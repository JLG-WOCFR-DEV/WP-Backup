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

    /**
     * Instance unique du planificateur.
     *
     * @var BJLG_Scheduler|null
     */
    private static $instance = null;

    /**
     * Retourne l'instance unique du planificateur.
     *
     * @return BJLG_Scheduler
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
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

    private function __clone() {}

    public function __wakeup() {
        throw new \Exception('Cannot unserialize singleton');
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
        $settings = $this->get_schedule_settings();

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

        $posted = wp_unslash($_POST);

        $recurrence = isset($posted['recurrence']) ? sanitize_key($posted['recurrence']) : 'disabled';
        if (!in_array($recurrence, $this->get_valid_recurrences(), true)) {
            wp_send_json_error([
                'message' => 'Impossible d\'enregistrer la planification.',
                'errors' => ['Fréquence invalide.']
            ]);
        }

        $day = isset($posted['day']) ? sanitize_key($posted['day']) : 'sunday';
        if (!in_array($day, $this->get_valid_days(), true)) {
            wp_send_json_error([
                'message' => 'Impossible d\'enregistrer la planification.',
                'errors' => ['Jour invalide.']
            ]);
        }

        $time = isset($posted['time']) ? sanitize_text_field($posted['time']) : '23:59';
        if (!preg_match('/^([0-1]?\d|2[0-3]):([0-5]\d)$/', $time)) {
            wp_send_json_error([
                'message' => 'Impossible d\'enregistrer la planification.',
                'errors' => ['Format d\'heure invalide.']
            ]);
        }

        $components_value = isset($posted['components']) ? $posted['components'] : [];
        $components = $this->sanitize_components($components_value);
        if (is_wp_error($components)) {
            wp_send_json_error([
                'message' => 'Impossible d\'enregistrer la planification.',
                'errors' => [$components->get_error_message()]
            ]);
        }

        if (empty($components)) {
            wp_send_json_error([
                'message' => 'Impossible d\'enregistrer la planification.',
                'errors' => ['Veuillez sélectionner au moins un composant valide.']
            ]);
        }

        $encrypt = $this->sanitize_boolean($posted['encrypt'] ?? false);
        $incremental = $this->sanitize_boolean($posted['incremental'] ?? false);
        $include_patterns = BJLG_Settings::sanitize_pattern_list($posted['include_patterns'] ?? []);
        $exclude_patterns = BJLG_Settings::sanitize_pattern_list($posted['exclude_patterns'] ?? []);
        $post_checks = BJLG_Settings::sanitize_post_checks(
            $posted['post_checks'] ?? [],
            BJLG_Settings::get_default_backup_post_checks()
        );
        $secondary_destinations = BJLG_Settings::sanitize_destination_list(
            $posted['secondary_destinations'] ?? [],
            BJLG_Settings::get_known_destination_ids()
        );

        $schedule_settings = [
            'recurrence' => $recurrence,
            'day' => $day,
            'time' => $time,
            'components' => $components,
            'encrypt' => $encrypt,
            'incremental' => $incremental,
            'include_patterns' => $include_patterns,
            'exclude_patterns' => $exclude_patterns,
            'post_checks' => $post_checks,
            'secondary_destinations' => $secondary_destinations,
        ];

        update_option('bjlg_schedule_settings', $schedule_settings);
        BJLG_Settings::get_instance()->update_backup_filters(
            $include_patterns,
            $exclude_patterns,
            $secondary_destinations,
            $post_checks
        );
        BJLG_Debug::log("Réglages de planification enregistrés : " . print_r($schedule_settings, true));

        // Mettre à jour la planification
        $this->update_schedule($schedule_settings);
        
        // Obtenir la prochaine exécution
        $next_run = wp_next_scheduled(self::SCHEDULE_HOOK);
        $next_run_formatted = $next_run ? get_date_from_gmt($this->format_gmt_datetime($next_run), 'd/m/Y H:i:s') : 'Non planifié';
        
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
                get_date_from_gmt($this->format_gmt_datetime($first_timestamp), 'd/m/Y H:i:s')
            ));

            BJLG_History::log('schedule_updated', 'success',
                'Prochaine sauvegarde planifiée pour le ' . get_date_from_gmt($this->format_gmt_datetime($first_timestamp), 'd/m/Y H:i:s')
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
        list($hour, $minute) = array_map('intval', explode(':', $time_str));

        if (function_exists('wp_timezone')) {
            $timezone = wp_timezone();
        } else {
            $timezone_string = function_exists('wp_timezone_string') ? wp_timezone_string() : get_option('timezone_string', 'UTC');
            if (empty($timezone_string)) {
                $timezone_string = 'UTC';
            }
            $timezone = new \DateTimeZone($timezone_string);
        }

        $now = new \DateTimeImmutable('now', $timezone);

        switch ($settings['recurrence']) {
            case 'hourly':
                // Prochaine heure pile dans le fuseau WordPress
                $next_run_time = $now->setTime((int) $now->format('H'), 0, 0)->modify('+1 hour');
                break;

            case 'twice_daily':
                // Deux fois par jour à l'heure spécifiée et 12h plus tard
                $first_run = $now->setTime($hour, $minute, 0);
                if ($now < $first_run) {
                    $next_run_time = $first_run;
                    break;
                }

                $second_run = $first_run->modify('+12 hours');
                if ($now < $second_run) {
                    $next_run_time = $second_run;
                } else {
                    $next_run_time = $first_run->modify('+1 day');
                }
                break;

            case 'daily':
                $scheduled_time = $now->setTime($hour, $minute, 0);
                if ($now < $scheduled_time) {
                    $next_run_time = $scheduled_time;
                } else {
                    $next_run_time = $scheduled_time->modify('+1 day');
                }
                break;

            case 'weekly':
                $day_str = strtolower($settings['day']); // ex: "sunday"
                $days_map = [
                    'sunday' => 0,
                    'monday' => 1,
                    'tuesday' => 2,
                    'wednesday' => 3,
                    'thursday' => 4,
                    'friday' => 5,
                    'saturday' => 6,
                ];

                if (!isset($days_map[$day_str])) {
                    return false;
                }

                $current_weekday = (int) $now->format('w');
                $days_ahead = ($days_map[$day_str] - $current_weekday + 7) % 7;
                $candidate = $now->modify('+' . $days_ahead . ' days')->setTime($hour, $minute, 0);

                if ($days_ahead === 0 && $now >= $candidate) {
                    $candidate = $candidate->modify('+7 days');
                }

                $next_run_time = $candidate;
                break;

            case 'monthly':
                // Premier jour du mois à l'heure spécifiée
                $first_of_month = $now->modify('first day of this month')->setTime($hour, $minute, 0);

                if ($now < $first_of_month) {
                    $next_run_time = $first_of_month;
                } else {
                    // Premier jour du mois prochain
                    $next_run_time = $first_of_month->modify('first day of next month')->setTime($hour, $minute, 0);
                }
                break;

            default:
                return false;
        }

        return $next_run_time->getTimestamp();
    }
    
    /**
     * Obtient la prochaine exécution planifiée
     */
    public function handle_get_next_scheduled() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        
        $next_run = wp_next_scheduled(self::SCHEDULE_HOOK);
        $settings = $this->get_schedule_settings();

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
                $this->format_gmt_datetime($next_run),
                'd/m/Y H:i:s'
            );
            $response['next_run_relative'] = human_time_diff($next_run, current_time('timestamp'));
        }
        
        wp_send_json_success($response);
    }

    /**
     * Retourne la date/heure GMT formatée attendue par get_date_from_gmt().
     */
    private function format_gmt_datetime($timestamp) {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Exécute immédiatement une sauvegarde planifiée
     */
    public function handle_run_scheduled_now() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        $settings = $this->get_schedule_settings();

        // Créer une tâche de sauvegarde avec les paramètres planifiés
        $task_id = 'bjlg_backup_' . md5(uniqid('manual_scheduled', true));
        $task_data = [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => 'Initialisation (manuelle)...',
            'components' => $settings['components'],
            'encrypt' => $settings['encrypt'],
            'incremental' => $settings['incremental'],
            'source' => 'manual_scheduled',
            'start_time' => time(),
            'include_patterns' => $settings['include_patterns'],
            'exclude_patterns' => $settings['exclude_patterns'],
            'post_checks' => $settings['post_checks'],
            'secondary_destinations' => $settings['secondary_destinations'],
        ];

        $transient_set = set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl());

        if (!$transient_set) {
            $error_message = "Impossible d'initialiser la sauvegarde planifiée.";
            BJLG_Debug::log("ERREUR : Impossible d'initialiser la tâche de sauvegarde planifiée $task_id.");
            BJLG_History::log('scheduled_backup', 'failure', $error_message);
            wp_send_json_error(['message' => $error_message]);
        }

        BJLG_Debug::log("Exécution manuelle de la sauvegarde planifiée - Task ID: $task_id");
        BJLG_History::log('scheduled_backup', 'info', 'Exécution manuelle de la sauvegarde planifiée');

        $scheduled = wp_schedule_single_event(time(), 'bjlg_run_backup_task', ['task_id' => $task_id]);

        if (is_wp_error($scheduled) || !$scheduled) {
            delete_transient($task_id);
            $error_message = "Impossible de planifier l'exécution de la sauvegarde planifiée.";

            if (is_wp_error($scheduled)) {
                $error_detail = $scheduled->get_error_message();
                if (!empty($error_detail)) {
                    $error_message .= ' Raison : ' . $error_detail;
                }
            }

            BJLG_Debug::log("ERREUR : $error_message Task ID: $task_id.");
            BJLG_History::log('scheduled_backup', 'failure', $error_message);
            wp_send_json_error(['message' => $error_message]);
        }

        wp_send_json_success([
            'message' => 'Sauvegarde planifiée lancée manuellement.',
            'task_id' => $task_id
        ]);
    }

    /**
     * Déclenche l'exécution automatique d'une sauvegarde planifiée.
     */
    public function run_scheduled_backup() {
        $settings = $this->get_schedule_settings();
        $components = $settings['components'];

        $task_id = 'bjlg_backup_' . md5(uniqid('scheduled', true));

        $task_data = [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => 'Initialisation (planifiée)...',
            'components' => $components,
            'encrypt' => $settings['encrypt'],
            'incremental' => $settings['incremental'],
            'source' => 'scheduled',
            'start_time' => time(),
            'include_patterns' => $settings['include_patterns'],
            'exclude_patterns' => $settings['exclude_patterns'],
            'post_checks' => $settings['post_checks'],
            'secondary_destinations' => $settings['secondary_destinations'],
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
        $settings = $this->get_schedule_settings();

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

    public function get_schedule_settings() {
        $stored = get_option('bjlg_schedule_settings', []);

        return $this->normalize_schedule_settings($stored);
    }

    private function normalize_schedule_settings($settings) {
        $defaults = $this->get_default_schedule_settings();

        if (!is_array($settings)) {
            $settings = [];
        }

        $settings = wp_parse_args($settings, $defaults);

        $recurrence = sanitize_key($settings['recurrence']);
        if (!in_array($recurrence, $this->get_valid_recurrences(), true)) {
            $recurrence = $defaults['recurrence'];
        }

        $day = sanitize_key($settings['day']);
        if (!in_array($day, $this->get_valid_days(), true)) {
            $day = $defaults['day'];
        }

        $time = sanitize_text_field($settings['time']);
        if (!preg_match('/^([0-1]?\d|2[0-3]):([0-5]\d)$/', $time)) {
            $time = $defaults['time'];
        }

        $components = $this->sanitize_components($settings['components']);
        if (is_wp_error($components) || empty($components)) {
            $components = $defaults['components'];
        }

        $include_patterns = BJLG_Settings::sanitize_pattern_list($settings['include_patterns'] ?? []);
        $exclude_patterns = BJLG_Settings::sanitize_pattern_list($settings['exclude_patterns'] ?? []);
        $post_checks = BJLG_Settings::sanitize_post_checks(
            $settings['post_checks'] ?? [],
            BJLG_Settings::get_default_backup_post_checks()
        );
        $secondary_destinations = BJLG_Settings::sanitize_destination_list(
            $settings['secondary_destinations'] ?? [],
            BJLG_Settings::get_known_destination_ids()
        );

        return [
            'recurrence' => $recurrence,
            'day' => $day,
            'time' => $time,
            'components' => $components,
            'encrypt' => $this->sanitize_boolean($settings['encrypt']),
            'incremental' => $this->sanitize_boolean($settings['incremental']),
            'include_patterns' => $include_patterns,
            'exclude_patterns' => $exclude_patterns,
            'post_checks' => $post_checks,
            'secondary_destinations' => $secondary_destinations,
        ];
    }

    private function get_default_schedule_settings() {
        return [
            'recurrence' => 'disabled',
            'day' => 'sunday',
            'time' => '23:59',
            'components' => ['db', 'plugins', 'themes', 'uploads'],
            'encrypt' => false,
            'incremental' => false,
            'include_patterns' => [],
            'exclude_patterns' => [],
            'post_checks' => BJLG_Settings::get_default_backup_post_checks(),
            'secondary_destinations' => [],
        ];
    }

    private function get_valid_recurrences() {
        return ['disabled', 'hourly', 'twice_daily', 'daily', 'weekly', 'monthly'];
    }

    private function get_valid_days() {
        return ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    }

    private function sanitize_components($components) {
        $allowed_components = ['db', 'plugins', 'themes', 'uploads'];
        $group_aliases = [
            'files' => ['plugins', 'themes', 'uploads'],
            'content' => ['plugins', 'themes', 'uploads'],
            'all_files' => ['plugins', 'themes', 'uploads'],
        ];
        $single_aliases = [
            'database' => 'db',
            'db_only' => 'db',
            'sql' => 'db',
            'plugins_dir' => 'plugins',
            'themes_dir' => 'themes',
            'uploads_dir' => 'uploads',
            'media' => 'uploads',
        ];

        if (is_string($components)) {
            $decoded = json_decode($components, true);
            if (is_array($decoded)) {
                $components = $decoded;
            } else {
                $components = preg_split('/[\s,;|]+/', $components, -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        if (!is_array($components)) {
            $components = (array) $components;
        }

        $sanitized = [];

        foreach ($components as $component) {
            if (!is_scalar($component)) {
                continue;
            }

            $component = (string) $component;

            if (preg_match('#[\\/]#', $component)) {
                return new \WP_Error('invalid_component_format', 'Format de composant invalide.');
            }

            $component = sanitize_key($component);

            if ($component === '') {
                continue;
            }

            if (in_array($component, ['all', 'full', 'everything'], true)) {
                return $allowed_components;
            }

            if (isset($group_aliases[$component])) {
                foreach ($group_aliases[$component] as $alias) {
                    if (!in_array($alias, $sanitized, true)) {
                        $sanitized[] = $alias;
                    }
                }
                continue;
            }

            if (isset($single_aliases[$component])) {
                $component = $single_aliases[$component];
            }

            if (in_array($component, $allowed_components, true) && !in_array($component, $sanitized, true)) {
                $sanitized[] = $component;
            }
        }

        return array_values($sanitized);
    }

    private function sanitize_boolean($value) {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return false;
    }
}
