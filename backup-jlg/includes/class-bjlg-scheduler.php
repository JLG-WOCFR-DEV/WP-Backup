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
        add_action('wp_ajax_bjlg_toggle_schedule_state', [$this, 'handle_toggle_schedule_state']);
        add_action('wp_ajax_bjlg_duplicate_schedule', [$this, 'handle_duplicate_schedule']);

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
        $collection = $this->get_schedule_settings();
        $this->sync_schedules($collection['schedules']);
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

        $raw_schedules = $posted['schedules'] ?? [];
        if (is_string($raw_schedules)) {
            $decoded = json_decode($raw_schedules, true);
            if (is_array($decoded)) {
                $raw_schedules = $decoded;
            }
        }

        $collection = BJLG_Settings::sanitize_schedule_collection($raw_schedules);
        $schedules = $collection['schedules'];

        if (empty($schedules)) {
            wp_send_json_error([
                'message' => 'Impossible d\'enregistrer la planification.',
                'errors' => ['Aucune planification valide fournie.']
            ]);
        }

        update_option('bjlg_schedule_settings', $collection);

        $primary = $this->get_primary_schedule($schedules);

        BJLG_Settings::get_instance()->update_backup_filters(
            $primary['include_patterns'],
            $primary['exclude_patterns'],
            $primary['secondary_destinations'],
            $primary['post_checks']
        );

        BJLG_Debug::log('Réglages de planification enregistrés : ' . print_r($collection, true));

        $this->sync_schedules($schedules);

        $next_runs = $this->get_next_runs_summary($schedules);

        wp_send_json_success([
            'message' => 'Planifications enregistrées !',
            'schedules' => $schedules,
            'next_runs' => $next_runs,
        ]);
    }
    
    /**
     * Met à jour la planification WordPress Cron
     */
    private function sync_schedules(array $schedules) {
        wp_clear_scheduled_hook(self::SCHEDULE_HOOK);

        $scheduled_any = false;

        foreach ($schedules as $schedule) {
            if (!is_array($schedule) || empty($schedule['id'])) {
                continue;
            }

            if (($schedule['recurrence'] ?? 'disabled') === 'disabled') {
                continue;
            }

            $first_timestamp = $this->calculate_first_run($schedule);

            if (!$first_timestamp) {
                BJLG_Debug::log('ERREUR : Impossible de calculer le prochain déclenchement pour la planification ' . $schedule['id'] . '.');
                continue;
            }

            $result = wp_schedule_event($first_timestamp, $schedule['recurrence'], self::SCHEDULE_HOOK, [$schedule['id']]);

            if ($result) {
                $scheduled_any = true;
                BJLG_Debug::log(sprintf(
                    'Planification %s (%s) programmée pour %s.',
                    $schedule['id'],
                    $schedule['recurrence'],
                    get_date_from_gmt($this->format_gmt_datetime($first_timestamp), 'd/m/Y H:i:s')
                ));

                BJLG_History::log(
                    'schedule_updated',
                    'success',
                    sprintf(
                        'Planification "%s" (%s) : prochaine exécution le %s.',
                        $schedule['label'] ?? $schedule['id'],
                        $schedule['recurrence'],
                        get_date_from_gmt($this->format_gmt_datetime($first_timestamp), 'd/m/Y H:i:s')
                    )
                );
            }
        }

        if (!$scheduled_any) {
            BJLG_History::log('schedule_updated', 'info', 'Aucune planification active après synchronisation.');
        }
    }

    /**
     * Calcule le timestamp de la première exécution
     */
    private function calculate_first_run(array $schedule) {
        $time_str = $schedule['time'] ?? '23:59';
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

        $recurrence = $schedule['recurrence'] ?? 'disabled';

        switch ($recurrence) {
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
                $day_str = strtolower($schedule['day'] ?? 'sunday'); // ex: "sunday"
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

        $collection = $this->get_schedule_settings();
        $summary = $this->get_next_runs_summary($collection['schedules']);

        wp_send_json_success([
            'schedules' => $summary,
        ]);
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
        
        $posted = wp_unslash($_POST);
        $schedule_id = isset($posted['schedule_id']) ? sanitize_key($posted['schedule_id']) : '';

        $collection = $this->get_schedule_settings();
        $schedule = $this->find_schedule_by_id($collection['schedules'], $schedule_id);

        if (!$schedule) {
            wp_send_json_error(['message' => 'Planification introuvable.']);
        }

        $task_id = 'bjlg_backup_' . md5(uniqid('manual_scheduled', true));
        $task_data = [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => 'Initialisation (manuelle)...',
            'components' => $schedule['components'],
            'encrypt' => $schedule['encrypt'],
            'incremental' => $schedule['incremental'],
            'source' => 'manual_scheduled',
            'start_time' => time(),
            'include_patterns' => $schedule['include_patterns'],
            'exclude_patterns' => $schedule['exclude_patterns'],
            'post_checks' => $schedule['post_checks'],
            'secondary_destinations' => $schedule['secondary_destinations'],
            'schedule_id' => $schedule['id'],
        ];

        $transient_set = set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl());

        if (!$transient_set) {
            $error_message = "Impossible d'initialiser la sauvegarde planifiée.";
            BJLG_Debug::log("ERREUR : Impossible d'initialiser la tâche de sauvegarde planifiée $task_id.");
            BJLG_History::log('scheduled_backup', 'failure', $error_message);
            wp_send_json_error(['message' => $error_message]);
        }

        BJLG_Debug::log(sprintf('Exécution manuelle de la sauvegarde planifiée %s - Task ID: %s', $schedule['id'], $task_id));
        BJLG_History::log('scheduled_backup', 'info', sprintf('Exécution manuelle de la planification "%s".', $schedule['label'] ?? $schedule['id']));

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

        $next_runs = $this->get_next_runs_summary($collection['schedules']);

        wp_send_json_success([
            'message' => 'Sauvegarde planifiée lancée manuellement.',
            'task_id' => $task_id,
            'schedule_id' => $schedule['id'],
            'next_runs' => $next_runs,
        ]);
    }

    /**
     * Déclenche l'exécution automatique d'une sauvegarde planifiée.
     */
    public function run_scheduled_backup($schedule_id = null) {
        if (is_array($schedule_id)) {
            $schedule_id = array_shift($schedule_id);
        }

        if (!is_string($schedule_id)) {
            $schedule_id = '';
        }

        $collection = $this->get_schedule_settings();
        $schedule = $this->find_schedule_by_id($collection['schedules'], $schedule_id);

        if (!$schedule) {
            BJLG_Debug::log('ERREUR : Planification introuvable pour l\'exécution automatique (' . $schedule_id . ').');
            return;
        }

        $task_id = 'bjlg_backup_' . md5(uniqid('scheduled', true));

        $task_data = [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => 'Initialisation (planifiée)...',
            'components' => $schedule['components'],
            'encrypt' => $schedule['encrypt'],
            'incremental' => $schedule['incremental'],
            'source' => 'scheduled',
            'start_time' => time(),
            'include_patterns' => $schedule['include_patterns'],
            'exclude_patterns' => $schedule['exclude_patterns'],
            'post_checks' => $schedule['post_checks'],
            'secondary_destinations' => $schedule['secondary_destinations'],
            'schedule_id' => $schedule['id'],
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

        BJLG_Debug::log(sprintf('Sauvegarde planifiée déclenchée automatiquement (%s) - Task ID: %s', $schedule['id'], $task_id));
        BJLG_History::log('scheduled_backup', 'info', sprintf('Planification "%s" exécutée automatiquement.', $schedule['label'] ?? $schedule['id']));
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
        $collection = $this->get_schedule_settings();
        $now = current_time('timestamp');

        foreach ($collection['schedules'] as $schedule) {
            if (($schedule['recurrence'] ?? 'disabled') === 'disabled') {
                continue;
            }

            $next_run = wp_next_scheduled(self::SCHEDULE_HOOK, [$schedule['id']]);

            if (!$next_run) {
                return true;
            }

            if ($next_run < ($now - HOUR_IN_SECONDS)) {
                return true;
            }
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

    private function get_primary_schedule(array $schedules): array {
        if (empty($schedules)) {
            $default = BJLG_Settings::get_default_schedule_entry();
            $default['id'] = 'bjlg_schedule_default';
            return $default;
        }

        foreach ($schedules as $schedule) {
            if (($schedule['recurrence'] ?? 'disabled') !== 'disabled') {
                return $schedule;
            }
        }

        return $schedules[0];
    }

    public function get_next_runs_summary(array $schedules): array {
        $summary = [];
        $now = current_time('timestamp');

        foreach ($schedules as $schedule) {
            if (!is_array($schedule) || empty($schedule['id'])) {
                continue;
            }

            $id = $schedule['id'];
            $next_run = wp_next_scheduled(self::SCHEDULE_HOOK, [$id]);
            $formatted = $next_run ? get_date_from_gmt($this->format_gmt_datetime($next_run), 'd/m/Y H:i:s') : 'Non planifié';
            $relative = null;
            if ($next_run) {
                $relative = human_time_diff($next_run, $now);
            }

            $summary[$id] = [
                'id' => $id,
                'label' => $schedule['label'] ?? $id,
                'recurrence' => $schedule['recurrence'] ?? 'disabled',
                'enabled' => ($schedule['recurrence'] ?? 'disabled') !== 'disabled',
                'next_run' => $next_run ?: null,
                'next_run_formatted' => $formatted,
                'next_run_relative' => $relative,
            ];
        }

        return $summary;
    }

    private function find_schedule_by_id(array $schedules, string $schedule_id) {
        if ($schedule_id !== '') {
            foreach ($schedules as $schedule) {
                if (!is_array($schedule) || empty($schedule['id'])) {
                    continue;
                }
                if ($schedule['id'] === $schedule_id) {
                    return $schedule;
                }
            }
        }

        if (!empty($schedules)) {
            return $this->get_primary_schedule($schedules);
        }

        return null;
    }

    public function get_schedule_settings() {
        $stored = get_option('bjlg_schedule_settings', []);
        $collection = BJLG_Settings::sanitize_schedule_collection($stored);

        if ($stored !== $collection) {
            update_option('bjlg_schedule_settings', $collection);
        }

        return $collection;
    }

    private function normalize_schedule_settings($settings) {
        // Conservé pour compatibilité interne éventuelle. Renvoie désormais l'ensemble de la collection.
        return BJLG_Settings::sanitize_schedule_collection($settings);
    }

    public function handle_toggle_schedule_state() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $posted = wp_unslash($_POST);
        $schedule_id = isset($posted['schedule_id']) ? sanitize_key((string) $posted['schedule_id']) : '';
        $state = isset($posted['state']) ? sanitize_key((string) $posted['state']) : '';

        if ($schedule_id === '' || !in_array($state, ['pause', 'resume'], true)) {
            wp_send_json_error(['message' => 'Requête invalide.'], 400);
        }

        $collection = $this->get_schedule_settings();
        $schedules = $collection['schedules'];
        $updated = false;

        foreach ($schedules as &$schedule) {
            if (!is_array($schedule) || !isset($schedule['id']) || $schedule['id'] !== $schedule_id) {
                continue;
            }

            $current_recurrence = $schedule['recurrence'] ?? 'disabled';
            $previous_recurrence = $schedule['previous_recurrence'] ?? '';

            if ($state === 'pause') {
                if ($current_recurrence !== 'disabled') {
                    $schedule['previous_recurrence'] = $current_recurrence;
                    $schedule['recurrence'] = 'disabled';
                }
                $updated = true;
            } else {
                $target = $previous_recurrence;
                if ($target === '' || $target === 'disabled') {
                    $target = $current_recurrence !== 'disabled' ? $current_recurrence : 'daily';
                }
                $schedule['recurrence'] = $target;
                $schedule['previous_recurrence'] = '';
                $updated = true;
            }

            break;
        }
        unset($schedule);

        if (!$updated) {
            wp_send_json_error(['message' => 'Planification introuvable.'], 404);
        }

        $collection = BJLG_Settings::sanitize_schedule_collection(['schedules' => $schedules]);
        update_option('bjlg_schedule_settings', $collection);

        $this->sync_schedules($collection['schedules']);
        $next_runs = $this->get_next_runs_summary($collection['schedules']);

        $schedule_entry = $this->find_schedule_by_id($collection['schedules'], $schedule_id);

        $message = $state === 'pause' ? 'Planification mise en pause.' : 'Planification réactivée.';

        wp_send_json_success([
            'message' => $message,
            'schedule' => $schedule_entry,
            'schedules' => $collection['schedules'],
            'next_runs' => $next_runs,
        ]);
    }

    public function handle_duplicate_schedule() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $posted = wp_unslash($_POST);
        $schedule_id = isset($posted['schedule_id']) ? sanitize_key((string) $posted['schedule_id']) : '';

        if ($schedule_id === '') {
            wp_send_json_error(['message' => 'Identifiant de planification manquant.'], 400);
        }

        $collection = $this->get_schedule_settings();
        $schedules = $collection['schedules'];
        $existing_ids = array_map('strval', (array) \wp_list_pluck($schedules, 'id'));

        $original = $this->find_schedule_by_id($schedules, $schedule_id);
        if (!$original) {
            wp_send_json_error(['message' => 'Planification introuvable.'], 404);
        }

        $label = isset($original['label']) && $original['label'] !== ''
            ? $original['label'] . ' (copie)'
            : 'Planification dupliquée';

        $duplicate = $original;
        $duplicate['id'] = '';
        $duplicate['label'] = $label;
        $duplicate['previous_recurrence'] = '';

        $schedules[] = $duplicate;

        $collection = BJLG_Settings::sanitize_schedule_collection(['schedules' => $schedules]);
        update_option('bjlg_schedule_settings', $collection);

        $this->sync_schedules($collection['schedules']);
        $next_runs = $this->get_next_runs_summary($collection['schedules']);

        $new_schedule = null;
        foreach ($collection['schedules'] as $schedule) {
            if (!in_array($schedule['id'], $existing_ids, true)) {
                $new_schedule = $schedule;
                break;
            }
        }

        wp_send_json_success([
            'message' => 'Planification dupliquée.',
            'schedule' => $new_schedule,
            'schedules' => $collection['schedules'],
            'next_runs' => $next_runs,
        ]);
    }
}
