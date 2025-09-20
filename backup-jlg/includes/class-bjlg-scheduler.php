<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gère la planification avancée des sauvegardes automatiques.
 */
class BJLG_Scheduler {

    const SCHEDULE_HOOK = 'bjlg_scheduled_backup_hook';

    public function __construct() {
        // Actions AJAX
        add_action('wp_ajax_bjlg_save_schedule_settings', [$this, 'handle_save_schedule']);
        add_action('wp_ajax_bjlg_get_next_scheduled', [$this, 'handle_get_next_scheduled']);
        add_action('wp_ajax_bjlg_run_scheduled_now', [$this, 'handle_run_scheduled_now']);

        // Hook Cron pour l'exécution automatique
        add_action(self::SCHEDULE_HOOK, [$this, 'run_scheduled_backup']);
        
        // Filtres pour les intervalles personnalisés
        add_filter('cron_schedules', [$this, 'add_custom_schedules']);
        
        // Vérifier et appliquer la planification au chargement
        add_action('init', [$this, 'check_schedule']);
    }
    
    /**
     * Ajoute des intervalles de planification personnalisés
     */
    public function add_custom_schedules($schedules) {
        $schedules['weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display' => __('Une fois par semaine', 'backup-jlg')
        ];
        
        $schedules['monthly'] = [
            'interval' => MONTH_IN_SECONDS,
            'display' => __('Une fois par mois', 'backup-jlg')
        ];
        
        $schedules['twice_daily'] = [
            'interval' => 12 * HOUR_IN_SECONDS,
            'display' => __('Deux fois par jour', 'backup-jlg')
        ];
        
        return $schedules;
    }
    
    /**
     * Vérifie et met à jour la planification si nécessaire
     */
    public function check_schedule() {
        $settings = get_option('bjlg_schedule_settings', [
            'recurrence' => 'disabled',
            'day' => 'sunday',
            'time' => '23:59'
        ]);
        
        $current_schedule = wp_get_schedule(self::SCHEDULE_HOOK);
        
        // Si la planification doit être désactivée
        if ($settings['recurrence'] === 'disabled') {
            if ($current_schedule) {
                wp_clear_scheduled_hook(self::SCHEDULE_HOOK);
                BJLG_Debug::log("Planification de sauvegarde désactivée.");
            }
            return;
        }
        
        // Si la planification doit être mise à jour
        if ($current_schedule !== $settings['recurrence']) {
            $this->update_schedule($settings);
        }
    }

    /**
     * Gère la requête AJAX pour enregistrer les paramètres de planification.
     */
    public function handle_save_schedule() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        $schedule_settings = [
            'recurrence' => isset($_POST['recurrence']) ? sanitize_key($_POST['recurrence']) : 'disabled',
            'day'        => isset($_POST['day']) ? sanitize_key($_POST['day']) : 'sunday',
            'time'       => isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '23:59',
            'components' => isset($_POST['components']) ? array_map('sanitize_text_field', $_POST['components']) : ['db', 'plugins', 'themes', 'uploads'],
            'encrypt'    => isset($_POST['encrypt']) && $_POST['encrypt'] === 'true',
            'incremental' => isset($_POST['incremental']) && $_POST['incremental'] === 'true'
        ];
        
        // Valider les paramètres
        $valid_recurrences = ['disabled', 'hourly', 'twice_daily', 'daily', 'weekly', 'monthly'];
        if (!in_array($schedule_settings['recurrence'], $valid_recurrences)) {
            wp_send_json_error(['message' => 'Fréquence invalide.']);
        }
        
        $valid_days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        if (!in_array($schedule_settings['day'], $valid_days)) {
            wp_send_json_error(['message' => 'Jour invalide.']);
        }
        
        // Valider l'heure (format HH:MM)
        if (!preg_match('/^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/', $schedule_settings['time'])) {
            wp_send_json_error(['message' => 'Format d\'heure invalide.']);
        }
        
        update_option('bjlg_schedule_settings', $schedule_settings);
        BJLG_Debug::log("Réglages de planification enregistrés : " . print_r($schedule_settings, true));
        
        // Mettre à jour la planification
        $this->update_schedule($schedule_settings);
        
        // Obtenir la prochaine exécution
        $next_run = wp_next_scheduled(self::SCHEDULE_HOOK);
        $next_run_formatted = $next_run ? get_date_from_gmt(date('Y-m-d H:i:s', $next_run), 'd/m/Y H:i:s') : 'Non planifié';
        
        wp_send_json_success([
            'message' => 'Planification enregistrée !',
            'next_run' => $next_run_formatted
        ]);
    }
    
    /**
     * Met à jour la planification WordPress Cron
     */
    private function update_schedule($settings) {
        // D'abord, supprimer l'ancienne planification
        wp_clear_scheduled_hook(self::SCHEDULE_HOOK);
        
        if ($settings['recurrence'] === 'disabled') {
            BJLG_History::log('schedule_updated', 'info', 'Planification des sauvegardes désactivée.');
            return;
        }
        
        // Calculer le timestamp de la première exécution
        $first_timestamp = $this->calculate_first_run($settings);
        
        if ($first_timestamp) {
            // Planifier l'événement
            wp_schedule_event($first_timestamp, $settings['recurrence'], self::SCHEDULE_HOOK);
            
            BJLG_Debug::log(sprintf(
                "Nouvelle sauvegarde planifiée (%s). Prochaine exécution : %s",
                $settings['recurrence'],
                get_date_from_gmt(date('Y-m-d H:i:s', $first_timestamp), 'd/m/Y H:i:s')
            ));
            
            BJLG_History::log('schedule_updated', 'success', 
                'Prochaine sauvegarde planifiée pour le ' . get_date_from_gmt(date('Y-m-d H:i:s', $first_timestamp), 'd/m/Y H:i:s')
            );
        } else {
            BJLG_Debug::log("ERREUR : Impossible de calculer le timestamp pour la planification.");
        }
    }
    
    /**
     * Calcule le timestamp de la première exécution
     */
    private function calculate_first_run($settings) {
        $time_str = $settings['time']; // ex: "23:59"
        list($hour, $minute) = explode(':', $time_str);
        
        $current_time = current_time('timestamp');
        $today = date('Y-m-d', $current_time);
        
        switch ($settings['recurrence']) {
            case 'hourly':
                // Prochaine heure pile
                $next_run = strtotime(date('Y-m-d H:00:00', $current_time + HOUR_IN_SECONDS));
                break;
                
            case 'twice_daily':
                // Deux fois par jour à l'heure spécifiée et 12h plus tard
                $morning_time = strtotime($today . ' ' . $time_str);
                $evening_time = $morning_time + (12 * HOUR_IN_SECONDS);
                
                if ($current_time < $morning_time) {
                    $next_run = $morning_time;
                } elseif ($current_time < $evening_time) {
                    $next_run = $evening_time;
                } else {
                    $next_run = strtotime('tomorrow ' . $time_str);
                }
                break;
                
            case 'daily':
                $scheduled_time = strtotime($today . ' ' . $time_str);
                if ($current_time < $scheduled_time) {
                    $next_run = $scheduled_time;
                } else {
                    $next_run = strtotime('tomorrow ' . $time_str);
                }
                break;
                
            case 'weekly':
                $day_str = $settings['day']; // ex: "sunday"
                $next_day = strtotime("next $day_str $time_str");
                
                // Si c'est aujourd'hui mais pas encore l'heure
                if (strtolower(date('l')) === $day_str) {
                    $today_time = strtotime($today . ' ' . $time_str);
                    if ($current_time < $today_time) {
                        $next_run = $today_time;
                    } else {
                        $next_run = $next_day;
                    }
                } else {
                    $next_run = $next_day;
                }
                break;
                
            case 'monthly':
                // Premier jour du mois à l'heure spécifiée
                $first_of_month = strtotime(date('Y-m-01') . ' ' . $time_str);
                
                if ($current_time < $first_of_month) {
                    $next_run = $first_of_month;
                } else {
                    // Premier jour du mois prochain
                    $next_run = strtotime(date('Y-m-01', strtotime('next month')) . ' ' . $time_str);
                }
                break;
                
            default:
                $next_run = false;
        }
        
        return $next_run;
    }
    
    /**
     * Obtient la prochaine exécution planifiée
     */
    public function handle_get_next_scheduled() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        
        $next_run = wp_next_scheduled(self::SCHEDULE_HOOK);
        $settings = get_option('bjlg_schedule_settings', []);
        
        $response = [
            'enabled' => $settings['recurrence'] !== 'disabled',
            'recurrence' => $settings['recurrence'],
            'next_run' => null,
            'next_run_formatted' => 'Non planifié',
            'next_run_relative' => null
        ];
        
        if ($next_run) {
            $response['next_run'] = $next_run;
            $response['next_run_formatted'] = get_date_from_gmt(
                date('Y-m-d H:i:s', $next_run),
                'd/m/Y H:i:s'
            );
            $response['next_run_relative'] = human_time_diff($next_run, current_time('timestamp'));
        }
        
        wp_send_json_success($response);
    }
    
    /**
     * Exécute immédiatement une sauvegarde planifiée
     */
    public function handle_run_scheduled_now() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        $settings = get_option('bjlg_schedule_settings', []);
        
        // Créer une tâche de sauvegarde avec les paramètres planifiés
        $task_id = 'bjlg_backup_' . md5(uniqid('manual_scheduled', true));
        $task_data = [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => 'Initialisation (manuelle)...',
            'components' => $settings['components'] ?? ['db', 'plugins', 'themes', 'uploads'],
            'encrypt' => $settings['encrypt'] ?? false,
            'incremental' => $settings['incremental'] ?? false,
            'source' => 'manual_scheduled'
        ];
        
        set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl());
        
        BJLG_Debug::log("Exécution manuelle de la sauvegarde planifiée - Task ID: $task_id");
        BJLG_History::log('scheduled_backup', 'info', 'Exécution manuelle de la sauvegarde planifiée');
        
        wp_schedule_single_event(time(), 'bjlg_run_backup_task', ['task_id' => $task_id]);
        
        wp_send_json_success([
            'message' => 'Sauvegarde planifiée lancée manuellement.',
            'task_id' => $task_id
        ]);
    }

    /**
     * Déclenche l'exécution automatique d'une sauvegarde planifiée.
     */
    public function run_scheduled_backup() {
        $settings = get_option('bjlg_schedule_settings', []);

        $components = $settings['components'] ?? ['db', 'plugins', 'themes', 'uploads'];
        if (empty($components) || !is_array($components)) {
            $components = ['db', 'plugins', 'themes', 'uploads'];
        }

        $task_id = 'bjlg_backup_' . md5(uniqid('scheduled', true));

        $task_data = [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => 'Initialisation (planifiée)...',
            'components' => $components,
            'encrypt' => $settings['encrypt'] ?? false,
            'incremental' => $settings['incremental'] ?? false,
            'source' => 'scheduled',
            'start_time' => time()
        ];

        $transient_set = set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl());

        if (!$transient_set) {
            BJLG_Debug::log("ERREUR : Impossible d'initialiser la tâche de sauvegarde planifiée $task_id.");
            BJLG_History::log('scheduled_backup', 'failure', "Échec de l'initialisation de la sauvegarde planifiée.");
            return;
        }

        $scheduled = wp_schedule_single_event(time(), 'bjlg_run_backup_task', ['task_id' => $task_id]);

        if (!$scheduled) {
            delete_transient($task_id);
            BJLG_Debug::log("ERREUR : Impossible de planifier l'événement de sauvegarde pour la tâche $task_id.");
            BJLG_History::log('scheduled_backup', 'failure', "Échec de la planification de la sauvegarde planifiée.");
            return;
        }

        BJLG_Debug::log("Sauvegarde planifiée déclenchée automatiquement - Task ID: $task_id");
        BJLG_History::log('scheduled_backup', 'info', 'Sauvegarde planifiée déclenchée automatiquement.');
    }
    
    /**
     * Obtient l'historique des sauvegardes planifiées
     */
    public function get_scheduled_history($limit = 10) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bjlg_history';
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name 
                 WHERE action_type = 'scheduled_backup' 
                 ORDER BY timestamp DESC 
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        
        return $results;
    }
    
    /**
     * Vérifie si une sauvegarde planifiée est en retard
     */
    public function is_schedule_overdue() {
        $settings = get_option('bjlg_schedule_settings', []);
        
        if ($settings['recurrence'] === 'disabled') {
            return false;
        }
        
        $next_run = wp_next_scheduled(self::SCHEDULE_HOOK);
        
        if (!$next_run) {
            return true; // Devrait être planifié mais ne l'est pas
        }
        
        // Vérifier si la prochaine exécution est en retard
        if ($next_run < (current_time('timestamp') - HOUR_IN_SECONDS)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Obtient des statistiques sur les sauvegardes planifiées
     */
    public function get_schedule_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bjlg_history';
        
        $stats = [
            'total_scheduled' => 0,
            'successful' => 0,
            'failed' => 0,
            'success_rate' => 0,
            'last_run' => null,
            'average_duration' => 0
        ];
        
        // Total des sauvegardes planifiées
        $stats['total_scheduled'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE action_type = 'scheduled_backup'"
        );
        
        // Succès
        $stats['successful'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name 
             WHERE action_type = 'backup_created' 
             AND status = 'success' 
             AND details LIKE '%planifiée%'"
        );
        
        // Échecs
        $stats['failed'] = $stats['total_scheduled'] - $stats['successful'];
        
        // Taux de succès
        if ($stats['total_scheduled'] > 0) {
            $stats['success_rate'] = round(($stats['successful'] / $stats['total_scheduled']) * 100, 2);
        }
        
        // Dernière exécution
        $last_run = $wpdb->get_row(
            "SELECT * FROM $table_name 
             WHERE action_type = 'scheduled_backup' 
             ORDER BY timestamp DESC 
             LIMIT 1",
            ARRAY_A
        );
        
        if ($last_run) {
            $stats['last_run'] = $last_run['timestamp'];
        }
        
        return $stats;
    }
}