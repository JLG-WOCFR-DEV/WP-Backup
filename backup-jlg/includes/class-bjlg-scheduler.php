<?php
namespace BJLG;

use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-bjlg-history.php';
require_once __DIR__ . '/class-bjlg-restore.php';

/**
 * Gère la planification avancée des sauvegardes automatiques.
 */
class BJLG_Scheduler {

    const SCHEDULE_HOOK = 'bjlg_scheduled_backup_hook';
    const SANDBOX_VALIDATION_HOOK = 'bjlg_sandbox_validation_hook';
    const MIN_CUSTOM_CRON_INTERVAL = 5 * MINUTE_IN_SECONDS;
    const EVENT_CRON_HOOK = 'bjlg_process_event_triggers';

    private const EVENT_SETTINGS_OPTION = 'bjlg_event_trigger_settings';
    private const EVENT_STATE_OPTION = 'bjlg_event_trigger_state';
    private const MAX_EVENT_SAMPLES = 10;

    /**
     * Instance unique du planificateur.
     *
     * @var BJLG_Scheduler|null
     */
    private static $instance = null;

    /**
     * Indique si les hooks supplémentaires ont déjà été initialisés.
     *
     * @var bool
     */
    private static $hooks_initialized = false;

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

    /**
     * Initialise les hooks complémentaires gérés par le planificateur.
     */
    public static function init_hooks(): void {
        if (self::$hooks_initialized) {
            return;
        }

        self::$hooks_initialized = true;

        add_action('init', [self::class, 'register_background_jobs'], 20);
        add_action(
            BJLG_Remote_Storage_Metrics::CRON_HOOK,
            [BJLG_Remote_Storage_Metrics::class, 'refresh_snapshot']
        );
        add_action(self::SANDBOX_VALIDATION_HOOK, [self::class, 'run_sandbox_validation_job']);
    }

    /**
     * Programme les tâches complémentaires (métriques de stockage, etc.).
     */
    public static function register_background_jobs(): void {
        $hook = BJLG_Remote_Storage_Metrics::CRON_HOOK;

        if (!wp_next_scheduled($hook)) {
            $start = time() + (int) apply_filters('bjlg_remote_storage_metrics_delay', MINUTE_IN_SECONDS);
            $recurrence = apply_filters('bjlg_remote_storage_metrics_recurrence', 'hourly');
            wp_schedule_event($start, $recurrence, $hook);
        }

        $sandbox_hook = self::SANDBOX_VALIDATION_HOOK;
        if (!wp_next_scheduled($sandbox_hook)) {
            $start = time() + (int) apply_filters('bjlg_sandbox_validation_delay', DAY_IN_SECONDS);
            $recurrence = apply_filters('bjlg_sandbox_validation_recurrence', 'daily');
            if (!is_string($recurrence) || $recurrence === '') {
                $recurrence = 'daily';
            }

            wp_schedule_event($start, $recurrence, $sandbox_hook);
        }
    }

    /**
     * Callback CRON pour la validation sandbox.
     */
    public static function run_sandbox_validation_job(): void {
        self::instance()->execute_sandbox_validation_job();
    }

    /**
     * Exécute réellement la validation sandbox et enregistre le rapport.
     */
    private function execute_sandbox_validation_job(): void {
        if (!class_exists(BJLG_Restore::class)) {
            return;
        }

        try {
            $restore = new BJLG_Restore();

            $args = [];
            $components_override = apply_filters('bjlg_sandbox_validation_components', null);
            if (is_array($components_override) || is_string($components_override)) {
                $args['components'] = $components_override;
            }

            $sandbox_path = apply_filters('bjlg_sandbox_validation_path', '');
            if (is_string($sandbox_path) && $sandbox_path !== '') {
                $args['sandbox_path'] = $sandbox_path;
            }

            $password_override = apply_filters('bjlg_sandbox_validation_password', null);
            if (is_string($password_override) && $password_override !== '') {
                $args['password'] = $password_override;
            }

            $report = $restore->run_sandbox_validation($args);
            $metadata = [
                'report' => $report,
                'triggered_at' => time(),
                'source' => 'scheduler',
            ];

            $status = isset($report['status']) ? (string) $report['status'] : 'failure';
            $summary = $this->summarize_sandbox_report($report);
            $base_message = $report['message'] ?? '';

            if ($status === 'success') {
                $message = $summary !== '' ? $summary : ($base_message !== '' ? $base_message : __('Validation sandbox réussie.', 'backup-jlg'));
                BJLG_History::log('sandbox_restore_validation', 'success', $message, null, null, $metadata);
                do_action('bjlg_sandbox_restore_validation_passed', $report);
            } else {
                $failure_message = $base_message !== '' ? $base_message : __('Validation sandbox échouée.', 'backup-jlg');
                $message = $summary !== '' ? $summary . ' | ' . $failure_message : $failure_message;
                BJLG_History::log('sandbox_restore_validation', 'failure', $message, null, null, $metadata);
                do_action('bjlg_sandbox_restore_validation_failed', $report);
            }
        } catch (Throwable $throwable) {
            $message = 'Validation sandbox échouée : ' . $throwable->getMessage();
            BJLG_History::log('sandbox_restore_validation', 'failure', $message, null, null, [
                'error' => $throwable->getMessage(),
                'triggered_at' => time(),
                'source' => 'scheduler',
            ]);

            if (class_exists(BJLG_Debug::class)) {
                BJLG_Debug::log('[Sandbox validation job] ' . $throwable->getMessage(), 'error');
            }

            do_action('bjlg_sandbox_restore_validation_failed', [
                'status' => 'failure',
                'message' => $message,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Construit un résumé lisible pour un rapport de validation sandbox.
     *
     * @param array<string,mixed> $report
     * @return string
     */
    private function summarize_sandbox_report(array $report): string {
        $parts = [];

        if (!empty($report['backup_file']) && is_string($report['backup_file'])) {
            $parts[] = sprintf(__('Archive : %s', 'backup-jlg'), basename($report['backup_file']));
        }

        $rto = '';
        if (!empty($report['objectives']['rto_human']) && is_string($report['objectives']['rto_human'])) {
            $rto = $report['objectives']['rto_human'];
        } elseif (!empty($report['timings']['duration_human']) && is_string($report['timings']['duration_human'])) {
            $rto = $report['timings']['duration_human'];
        }

        if ($rto !== '') {
            $parts[] = sprintf(__('RTO ≈ %s', 'backup-jlg'), $rto);
        }

        if (!empty($report['objectives']['rpo_human']) && is_string($report['objectives']['rpo_human'])) {
            $parts[] = sprintf(__('RPO ≈ %s', 'backup-jlg'), $report['objectives']['rpo_human']);
        }

        return implode(' | ', $parts);
    }

    private function __construct() {
        // Actions AJAX
        add_action('wp_ajax_bjlg_save_schedule_settings', [$this, 'handle_save_schedule']);
        add_action('wp_ajax_bjlg_get_next_scheduled', [$this, 'handle_get_next_scheduled']);
        add_action('wp_ajax_bjlg_run_scheduled_now', [$this, 'handle_run_scheduled_now']);
        add_action('wp_ajax_bjlg_toggle_schedule_state', [$this, 'handle_toggle_schedule_state']);
        add_action('wp_ajax_bjlg_duplicate_schedule', [$this, 'handle_duplicate_schedule']);
        add_action('wp_ajax_bjlg_preview_cron_expression', [$this, 'handle_preview_cron_expression']);
        add_action('wp_ajax_bjlg_scheduler_recommendations', [$this, 'handle_scheduler_recommendations']);

        // Hook Cron pour l'exécution automatique
        add_action(self::SCHEDULE_HOOK, [$this, 'run_scheduled_backup']);
        add_action(self::EVENT_CRON_HOOK, [$this, 'process_event_trigger_queue'], 10, 1);

        // Filtres pour les intervalles personnalisés
        add_filter('cron_schedules', [$this, 'add_custom_schedules']);

        // Vérifier et appliquer la planification au chargement
        add_action('init', [$this, 'check_schedule']);
        add_action('init', [$this, 'resume_event_trigger_queue'], 15);
    }

    private function __clone() {}

    public function __wakeup() {
        throw new \Exception('Cannot unserialize singleton');
    }
    
    /**
     * Ajoute des intervalles de planification personnalisés
     */
    public function add_custom_schedules($schedules) {
        $schedules['every_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => $this->get_schedule_label('Toutes les 5 minutes')
        ];

        $schedules['every_fifteen_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => $this->get_schedule_label('Toutes les 15 minutes')
        ];

        $schedules['weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display' => $this->get_schedule_label('Une fois par semaine')
        ];

        $schedules['monthly'] = [
            'interval' => MONTH_IN_SECONDS,
            'display' => $this->get_schedule_label('Une fois par mois')
        ];

        $schedules['twice_daily'] = [
            'interval' => 12 * HOUR_IN_SECONDS,
            'display' => $this->get_schedule_label('Deux fois par jour')
        ];

        return $schedules;
    }

    /**
     * Returns a schedule label, deferring translations until init has run.
     *
     * @param string $text
     * @return string
     */
    private function get_schedule_label($text) {
        if (did_action('init')) {
            return __($text, 'backup-jlg');
        }

        return $text;
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
        if (!\bjlg_can_manage_backups()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        $posted = wp_unslash($_POST);

        $raw_event_triggers = $posted['event_triggers'] ?? [];
        if (is_string($raw_event_triggers)) {
            $decoded_event_triggers = json_decode($raw_event_triggers, true);
            if (is_array($decoded_event_triggers)) {
                $raw_event_triggers = $decoded_event_triggers;
            }
        }

        $event_settings = self::sanitize_event_trigger_settings($raw_event_triggers);

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

        foreach ($schedules as $schedule_entry) {
            if (($schedule_entry['recurrence'] ?? '') === 'custom' && empty($schedule_entry['custom_cron'])) {
                wp_send_json_error([
                    'message' => 'Impossible d\'enregistrer la planification.',
                    'errors' => [__('L’expression Cron personnalisée est invalide.', 'backup-jlg')]
                ]);
            }
        }

        $cron_errors = [];
        $cron_analysis_cache = [];
        foreach ($schedules as $schedule_entry) {
            if (($schedule_entry['recurrence'] ?? '') !== 'custom') {
                continue;
            }

            $expression = isset($schedule_entry['custom_cron']) ? (string) $schedule_entry['custom_cron'] : '';
            if ($expression === '') {
                continue;
            }

            if (!isset($cron_analysis_cache[$expression])) {
                $cron_analysis_cache[$expression] = $this->analyze_custom_cron_expression_internal($expression);
            }

            $analysis = $cron_analysis_cache[$expression];
            if (is_wp_error($analysis)) {
                $details = $analysis->get_error_data();
                $errors = isset($details['details']) ? (array) $details['details'] : [];
                if (empty($errors)) {
                    $errors[] = $analysis->get_error_message();
                }
                $cron_errors = array_merge($cron_errors, $errors);
                continue;
            }

            if (!empty($analysis['errors'])) {
                $cron_errors = array_merge($cron_errors, $analysis['errors']);
            }
        }

        if (!empty($cron_errors)) {
            wp_send_json_error([
                'message' => __('Impossible d\'enregistrer la planification.', 'backup-jlg'),
                'errors' => array_values(array_unique(array_map('strval', $cron_errors))),
            ]);
        }

        $batch_size = $this->get_destination_batch_size();
        $all_secondary = [];
        foreach ($schedules as &$schedule) {
            $batches = $this->normalize_destination_batches(
                $schedule['secondary_destination_batches'] ?? [],
                $schedule['secondary_destinations'] ?? [],
                $batch_size
            );
            $schedule['secondary_destination_batches'] = $batches;
            $schedule['secondary_destinations'] = BJLG_Settings::flatten_destination_batches($batches);
            $all_secondary = array_merge($all_secondary, $schedule['secondary_destinations']);
        }
        unset($schedule);

        $collection['schedules'] = $schedules;

        \bjlg_update_option('bjlg_schedule_settings', $collection);
        $this->save_event_trigger_settings($event_settings);

        $primary = $this->get_primary_schedule($schedules);
        $aggregated_secondary = array_values(array_unique($all_secondary));

        BJLG_Settings::get_instance()->update_backup_filters(
            $primary['include_patterns'],
            $primary['exclude_patterns'],
            $aggregated_secondary,
            $primary['post_checks']
        );

        BJLG_Debug::log('Réglages de planification enregistrés : ' . print_r($collection, true));
        BJLG_History::log(
            'event_trigger_settings',
            'info',
            __('Réglages des déclencheurs événementiels mis à jour.', 'backup-jlg')
        );

        $this->sync_schedules($schedules);

        $next_runs = $this->get_next_runs_summary($schedules);

        wp_send_json_success([
            'message' => 'Planifications enregistrées !',
            'schedules' => $schedules,
            'next_runs' => $next_runs,
            'event_triggers' => $event_settings['triggers'],
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

            if (($schedule['recurrence'] ?? '') === 'custom') {
                $first_timestamp = $this->calculate_first_run($schedule);

                if (!$first_timestamp) {
                    BJLG_Debug::log('ERREUR : Impossible de calculer le prochain déclenchement pour la planification ' . $schedule['id'] . '.');
                    continue;
                }

                $result = wp_schedule_single_event($first_timestamp, self::SCHEDULE_HOOK, [$schedule['id']]);
            } else {
                $first_timestamp = $this->calculate_first_run($schedule);

                if (!$first_timestamp) {
                    BJLG_Debug::log('ERREUR : Impossible de calculer le prochain déclenchement pour la planification ' . $schedule['id'] . '.');
                    continue;
                }

                $result = wp_schedule_event($first_timestamp, $schedule['recurrence'], self::SCHEDULE_HOOK, [$schedule['id']]);
            }

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
    private function calculate_first_run(array $schedule, ?int $from_timestamp = null) {
        $time_str = $schedule['time'] ?? '23:59';
        list($hour, $minute) = array_map('intval', explode(':', $time_str));

        $timezone = $this->get_wordpress_timezone();

        $now = $from_timestamp !== null
            ? (new \DateTimeImmutable('@' . $from_timestamp))->setTimezone($timezone)
            : new \DateTimeImmutable('now', $timezone);

        $recurrence = $schedule['recurrence'] ?? 'disabled';

        switch ($recurrence) {
            case 'every_five_minutes':
            case 'every_fifteen_minutes':
                $interval_minutes = $recurrence === 'every_five_minutes' ? 5 : 15;
                $interval_seconds = $interval_minutes * MINUTE_IN_SECONDS;
                $base_time = $now->setTime($hour, $minute, 0);
                $base_timestamp = $base_time->getTimestamp();
                $current_timestamp = $now->getTimestamp();

                if ($current_timestamp >= $base_timestamp) {
                    $steps = (int) floor(($current_timestamp - $base_timestamp) / $interval_seconds) + 1;
                    $base_timestamp += $steps * $interval_seconds;
                }

                $next_run_time = (new \DateTimeImmutable('@' . $base_timestamp))->setTimezone($timezone);
                break;

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
                $day_of_month = isset($schedule['day_of_month'])
                    ? (int) $schedule['day_of_month']
                    : 1;
                $day_of_month = max(1, min(31, $day_of_month));

                $current_year = (int) $now->format('Y');
                $current_month = (int) $now->format('n');
                $days_in_month = (int) $now->format('t');
                $target_day = min($day_of_month, $days_in_month);

                $candidate = $now
                    ->setDate($current_year, $current_month, $target_day)
                    ->setTime($hour, $minute, 0);

                if ($now < $candidate) {
                    $next_run_time = $candidate;
                } else {
                    $next_month = $now->modify('first day of next month');
                    $next_year = (int) $next_month->format('Y');
                    $next_month_number = (int) $next_month->format('n');
                    $days_in_next_month = (int) $next_month->format('t');
                    $next_target_day = min($day_of_month, $days_in_next_month);

                    $next_run_time = $next_month
                        ->setDate($next_year, $next_month_number, $next_target_day)
                        ->setTime($hour, $minute, 0);
                }
                break;

            case 'custom':
                $expression = isset($schedule['custom_cron']) ? (string) $schedule['custom_cron'] : '';
                $next_run_time = $this->calculate_custom_cron_next_run($expression, $now);
                if (!$next_run_time instanceof \DateTimeImmutable) {
                    return false;
                }
                break;

            default:
                return false;
        }

        return $next_run_time->getTimestamp();
    }

    private function get_wordpress_timezone(): \DateTimeZone {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        $timezone_string = function_exists('wp_timezone_string') ? wp_timezone_string() : get_option('timezone_string', 'UTC');
        if (empty($timezone_string)) {
            $timezone_string = 'UTC';
        }

        return new \DateTimeZone($timezone_string);
    }

    /**
     * Obtient la prochaine exécution planifiée
     */
    public function handle_get_next_scheduled() {
        if (!\bjlg_can_manage_backups()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }

        $collection = $this->get_schedule_settings();
        $summary = $this->get_next_runs_summary($collection['schedules']);

        wp_send_json_success([
            'schedules' => $summary,
        ]);
    }

    /**
     * Fournit des recommandations de capacité pour les planifications.
     */
    public function handle_scheduler_recommendations() {
        if (!\bjlg_can_manage_backups()) {
            wp_send_json_error(['message' => 'Permission refusée.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $posted = wp_unslash($_POST);
        $override_schedule = null;

        if (isset($posted['current_schedule'])) {
            $raw_schedule = $posted['current_schedule'];
            if (is_string($raw_schedule)) {
                $decoded = json_decode($raw_schedule, true);
                if (is_array($decoded)) {
                    $raw_schedule = $decoded;
                }
            }

            if (is_array($raw_schedule)) {
                $override_schedule = BJLG_Settings::sanitize_schedule_entry($raw_schedule, 0);

                if (isset($raw_schedule['id']) && is_scalar($raw_schedule['id'])) {
                    $override_id = sanitize_key((string) $raw_schedule['id']);
                    $override_schedule['id'] = $override_id;
                }

                if (isset($raw_schedule['recurrence'])) {
                    $override_schedule['recurrence'] = sanitize_key((string) $raw_schedule['recurrence']);
                }

                if (isset($raw_schedule['custom_cron'])) {
                    $override_schedule['custom_cron'] = BJLG_Settings::sanitize_cron_expression($raw_schedule['custom_cron']);
                }
            }
        }

        $forecast = $this->get_capacity_forecast($override_schedule);

        wp_send_json_success([
            'forecast' => $forecast,
        ]);
    }

    /**
     * Retourne la date/heure GMT formatée attendue par get_date_from_gmt().
     */
    private function format_gmt_datetime($timestamp) {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function calculate_custom_cron_next_run($expression, \DateTimeImmutable $now) {
        $field_sets = $this->extract_cron_field_sets($expression);
        if ($field_sets === null) {
            return null;
        }

        return $this->calculate_next_run_from_sets($field_sets, $now);
    }

    public static function analyze_custom_cron_expression($expression) {
        $instance = self::instance();

        return $instance->analyze_custom_cron_expression_internal($expression);
    }

    public function handle_preview_cron_expression() {
        if (!\bjlg_can_manage_backups()) {
            wp_send_json_error(['message' => __('Permission refusée.', 'backup-jlg')]);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $posted = wp_unslash($_POST);
        $raw_expression = isset($posted['expression']) ? (string) $posted['expression'] : '';
        $sanitized = BJLG_Settings::sanitize_cron_expression($raw_expression);

        if ($sanitized === '') {
            wp_send_json_error([
                'message' => __('L’expression Cron doit contenir cinq champs valides.', 'backup-jlg'),
            ]);
        }

        $analysis = $this->analyze_custom_cron_expression_internal($sanitized);

        if (is_wp_error($analysis)) {
            $details = $analysis->get_error_data();
            wp_send_json_error([
                'message' => $analysis->get_error_message(),
                'errors' => isset($details['details']) ? (array) $details['details'] : [],
            ]);
        }

        $impact = $this->generate_cron_impact_summary($sanitized, [], $analysis);

        $runs = isset($analysis['runs']) ? $analysis['runs'] : [];
        $current_time = $this->get_current_time();
        $current_timestamp = $current_time->getTimestamp();
        $timezone = wp_timezone_string();

        $formatted_runs = [];
        foreach ($runs as $run) {
            if (!$run instanceof \DateTimeImmutable) {
                continue;
            }
            $timestamp = $run->getTimestamp();
            $formatted_runs[] = [
                'timestamp' => $timestamp,
                'formatted' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp),
                'relative' => sprintf(__('dans %s', 'backup-jlg'), human_time_diff($current_timestamp, $timestamp)),
                'iso' => wp_date('c', $timestamp),
            ];
        }

        $min_interval = $analysis['min_interval'] ?? null;

        $severity = 'success';
        if (!empty($analysis['errors'])) {
            $severity = 'error';
        } elseif (!empty($analysis['warnings'])) {
            $severity = 'warning';
        }

        $message = '';
        if (!empty($analysis['errors'])) {
            $message = (string) $analysis['errors'][0];
        } elseif (!empty($analysis['warnings'])) {
            $message = (string) $analysis['warnings'][0];
        } elseif ($min_interval) {
            $message = sprintf(
                __('Intervalle minimum détecté : %s.', 'backup-jlg'),
                $this->format_interval_label($min_interval)
            );
        } elseif (!empty($formatted_runs)) {
            $message = sprintf(
                __('Prochaine exécution planifiée dans %s.', 'backup-jlg'),
                human_time_diff($current_timestamp, $formatted_runs[0]['timestamp'])
            );
        }

        wp_send_json_success([
            'expression' => $sanitized,
            'next_runs' => $formatted_runs,
            'warnings' => $analysis['warnings'],
            'errors' => $analysis['errors'],
            'severity' => $severity,
            'message' => $message,
            'interval' => [
                'min' => $min_interval,
                'min_human' => $min_interval ? $this->format_interval_label($min_interval) : '',
            ],
            'timezone' => $timezone,
            'impact' => $impact,
        ]);
    }

    public static function get_cron_risk_thresholds() {
        $defaults = [
            'warning_frequency' => 12.0,
            'danger_frequency' => 48.0,
            'warning_load' => 3600.0,
            'danger_load' => 14400.0,
        ];

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('bjlg_cron_risk_thresholds', $defaults);
            if (is_array($filtered)) {
                $thresholds = $defaults;
                foreach ($filtered as $key => $value) {
                    if (!array_key_exists($key, $defaults)) {
                        continue;
                    }
                    $numeric = is_numeric($value) ? (float) $value : null;
                    if ($numeric !== null && $numeric >= 0) {
                        $thresholds[$key] = $numeric;
                    }
                }
                return $thresholds;
            }
        }

        return $defaults;
    }

    public function generate_cron_impact_summary($expression, array $components = [], $analysis = null) {
        if (!is_array($analysis)) {
            $analysis = $this->analyze_custom_cron_expression_internal($expression);
        }

        if (is_wp_error($analysis)) {
            $details = $analysis->get_error_data();
            return [
                'expression' => '',
                'runs_per_day' => 0.0,
                'average_duration' => null,
                'estimated_load' => null,
                'history_samples' => 0,
                'risk' => [
                    'level' => 'unknown',
                    'reasons' => [],
                    'thresholds' => self::get_cron_risk_thresholds(),
                    'details' => isset($details['details']) ? (array) $details['details'] : [],
                ],
                'errors' => [$analysis->get_error_message()],
            ];
        }

        $runs = isset($analysis['runs']) && is_array($analysis['runs']) ? $analysis['runs'] : [];
        $min_interval = isset($analysis['min_interval']) ? (int) $analysis['min_interval'] : null;

        $interval_seconds = $min_interval && $min_interval > 0 ? $min_interval : null;
        if (!$interval_seconds && count($runs) >= 2) {
            $first = $runs[0];
            $second = $runs[1];
            if ($first instanceof \DateTimeImmutable && $second instanceof \DateTimeImmutable) {
                $diff = $second->getTimestamp() - $first->getTimestamp();
                if ($diff > 0) {
                    $interval_seconds = (int) $diff;
                }
            }
        }

        if (!$interval_seconds || $interval_seconds <= 0) {
            $interval_seconds = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
        }

        $day_seconds = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
        $runs_per_day = $interval_seconds > 0 ? $day_seconds / $interval_seconds : 0.0;
        $runs_per_day = round($runs_per_day, 2);

        $durations = null;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('bjlg_scheduler_recent_durations', null, $analysis['expression'], $components);
            if (is_array($filtered)) {
                $durations = $filtered;
            }
        }

        if ($durations === null) {
            $durations = $this->fetch_recent_backup_durations();
        }

        $normalized_durations = [];
        foreach ($durations as $value) {
            if (is_numeric($value)) {
                $float_value = (float) $value;
                if ($float_value >= 0) {
                    $normalized_durations[] = $float_value;
                }
            }
        }

        $average_duration = null;
        if (!empty($normalized_durations)) {
            $average_duration = array_sum($normalized_durations) / count($normalized_durations);
            $average_duration = round($average_duration, 2);
        }

        $estimated_load = null;
        if ($average_duration !== null) {
            $estimated_load = round($average_duration * $runs_per_day, 2);
        }

        $thresholds = self::get_cron_risk_thresholds();
        $level_score = 0;
        $reasons = [];

        if ($runs_per_day >= $thresholds['danger_frequency']) {
            $level_score = 2;
            $reasons[] = sprintf(
                /* translators: %s: number of executions per day. */
                __('Fréquence très élevée : %s exécutions estimées sur 24h.', 'backup-jlg'),
                $runs_per_day
            );
        } elseif ($runs_per_day >= $thresholds['warning_frequency']) {
            $level_score = max($level_score, 1);
            $reasons[] = sprintf(
                /* translators: %s: number of executions per day. */
                __('Fréquence soutenue : %s exécutions estimées sur 24h.', 'backup-jlg'),
                $runs_per_day
            );
        }

        if ($estimated_load !== null) {
            if ($estimated_load >= $thresholds['danger_load']) {
                $level_score = 2;
                $reasons[] = sprintf(
                    /* translators: %s: estimated processing time label. */
                    __('Charge quotidienne estimée : %s de traitement.', 'backup-jlg'),
                    $this->format_interval_label($estimated_load)
                );
            } elseif ($estimated_load >= $thresholds['warning_load']) {
                $level_score = max($level_score, 1);
                $reasons[] = sprintf(
                    /* translators: %s: estimated processing time label. */
                    __('Charge notable : %s de traitement par jour.', 'backup-jlg'),
                    $this->format_interval_label($estimated_load)
                );
            }
        }

        $levels = ['low', 'medium', 'high'];
        $risk_level = $levels[min($level_score, count($levels) - 1)];

        $summary = [
            'expression' => $analysis['expression'],
            'runs_per_day' => $runs_per_day,
            'average_duration' => $average_duration,
            'estimated_load' => $estimated_load,
            'history_samples' => count($normalized_durations),
            'risk' => [
                'level' => $risk_level,
                'reasons' => $reasons,
                'thresholds' => $thresholds,
            ],
        ];

        if (!empty($analysis['warnings'])) {
            $summary['warnings'] = $analysis['warnings'];
        }

        return $summary;
    }

    private function fetch_recent_backup_durations($limit = 20) {
        if (!class_exists(BJLG_History::class)) {
            return [];
        }

        $entries = BJLG_History::get_history(
            max(1, (int) $limit),
            [
                'action_type' => 'backup_created',
                'status' => 'success',
            ]
        );

        if (!is_array($entries)) {
            return [];
        }

        $durations = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $value = $this->extract_duration_from_history_details($entry['details'] ?? '');
            if ($value !== null) {
                $durations[] = $value;
            }
        }

        return $durations;
    }

    private function extract_duration_from_history_details($details) {
        if (!is_string($details) || $details === '') {
            return null;
        }

        $patterns = [
            '/Dur(?:é|e)e?\s*:\s*(\d+(?:[.,]\d+)?)/iu',
            '/Duration\s*:\s*(\d+(?:[.,]\d+)?)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $details, $matches) === 1) {
                $raw = str_replace(',', '.', $matches[1]);
                $value = (float) $raw;
                if ($value >= 0) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function analyze_custom_cron_expression_internal($expression) {
        $sanitized = BJLG_Settings::sanitize_cron_expression($expression);
        if ($sanitized === '') {
            return new \WP_Error(
                'invalid_schedule_cron',
                __('L’expression Cron personnalisée est invalide.', 'backup-jlg'),
                ['details' => [__('L’expression Cron doit contenir cinq champs valides.', 'backup-jlg')]]
            );
        }

        $now = $this->get_current_time();
        $diagnostics = [];
        $runs = $this->compute_next_custom_runs($sanitized, $now, 5, $diagnostics);

        if (empty($runs)) {
            $details = isset($diagnostics['errors']) && is_array($diagnostics['errors']) ? $diagnostics['errors'] : [];

            return new \WP_Error(
                'invalid_schedule_cron',
                __('Impossible de déterminer la prochaine exécution pour cette expression Cron.', 'backup-jlg'),
                ['details' => $details]
            );
        }

        $intervals = [];
        $previous = null;
        foreach ($runs as $run) {
            if (!$run instanceof \DateTimeImmutable) {
                continue;
            }
            if ($previous instanceof \DateTimeImmutable) {
                $intervals[] = $run->getTimestamp() - $previous->getTimestamp();
            }
            $previous = $run;
        }

        $min_interval = !empty($intervals) ? min($intervals) : null;
        $max_interval = !empty($intervals) ? max($intervals) : null;

        $warnings = $diagnostics['warnings'] ?? [];
        $errors = $diagnostics['errors'] ?? [];

        if ($min_interval !== null && $min_interval < self::MIN_CUSTOM_CRON_INTERVAL) {
            $suggestion = $this->suggest_cron_interval($min_interval);
            $errors[] = sprintf(
                __('L’expression Cron lance une sauvegarde toutes les %1$s. Choisissez un intervalle d’au moins %2$s (ex. “%3$s”).', 'backup-jlg'),
                $this->format_interval_label($min_interval),
                $this->format_interval_label(self::MIN_CUSTOM_CRON_INTERVAL),
                $suggestion
            );
        }

        return [
            'expression' => $sanitized,
            'runs' => $runs,
            'min_interval' => $min_interval,
            'max_interval' => $max_interval,
            'warnings' => array_values(array_unique($warnings)),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function compute_next_custom_runs($expression, \DateTimeImmutable $now, int $limit = 5, array &$diagnostics = null) {
        $runs = [];
        $search_from = $now;
        $previous_timestamp = null;

        $warnings = [];
        $errors = [];
        $field_sets = $this->extract_cron_field_sets($expression, $warnings, $errors);

        if (is_array($diagnostics)) {
            $diagnostics['warnings'] = $warnings;
            $diagnostics['errors'] = $errors;
        }

        if ($field_sets === null) {
            return [];
        }

        for ($i = 0; $i < $limit; $i++) {
            $next = $this->calculate_next_run_from_sets($field_sets, $search_from);
            if (!$next instanceof \DateTimeImmutable) {
                break;
            }

            $timestamp = $next->getTimestamp();
            if ($previous_timestamp !== null && $timestamp === $previous_timestamp) {
                break;
            }

            $runs[] = $next;
            $previous_timestamp = $timestamp;
            $search_from = $next->modify('+1 minute');
        }

        return $runs;
    }

    private function calculate_next_run_from_sets(array $sets, \DateTimeImmutable $now) {
        $minutes = isset($sets['minutes']) ? (array) $sets['minutes'] : [];
        $hours = isset($sets['hours']) ? (array) $sets['hours'] : [];
        $months = isset($sets['months']) ? (array) $sets['months'] : [];
        $dom_values = isset($sets['dom_values']) ? (array) $sets['dom_values'] : [];
        $dow_values = isset($sets['dow_values']) ? (array) $sets['dow_values'] : [];
        $dom_any = !empty($sets['dom_any']);
        $dow_any = !empty($sets['dow_any']);

        if (empty($minutes) || empty($hours) || empty($months)) {
            return null;
        }

        if (!$dom_any && empty($dom_values)) {
            return null;
        }

        if (!$dow_any && empty($dow_values)) {
            return null;
        }

        $candidate = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0)->modify('+1 minute');

        for ($i = 0; $i < 525600; $i++) {
            $minute = (int) $candidate->format('i');
            if (!in_array($minute, $minutes, true)) {
                $candidate = $candidate->modify('+1 minute');
                continue;
            }

            $hour = (int) $candidate->format('H');
            if (!in_array($hour, $hours, true)) {
                $candidate = $candidate->modify('+1 minute');
                continue;
            }

            $month = (int) $candidate->format('n');
            if (!in_array($month, $months, true)) {
                $candidate = $candidate->modify('+1 minute');
                continue;
            }

            if ($this->cron_day_matches($candidate, $dom_values, $dow_values, $dom_any, $dow_any)) {
                return $candidate;
            }

            $candidate = $candidate->modify('+1 minute');
        }

        return null;
    }

    private function extract_cron_field_sets($expression, array &$warnings = null, array &$errors = null) {
        $expression = trim((string) $expression);
        if ($expression === '') {
            $this->add_diagnostic_message($errors, __('L’expression Cron doit contenir cinq segments.', 'backup-jlg'));
            return null;
        }

        $parts = preg_split('/\s+/', $expression);
        if (!is_array($parts) || count($parts) !== 5) {
            $this->add_diagnostic_message($errors, __('L’expression Cron doit comporter exactement cinq segments (minute heure jour-du-mois mois jour-de-semaine).', 'backup-jlg'));
            return null;
        }

        list($minute_field, $hour_field, $dom_field, $month_field, $dow_field) = $parts;

        $labels = [
            'minute' => __('minutes', 'backup-jlg'),
            'hour' => __('heures', 'backup-jlg'),
            'dom' => __('jour du mois', 'backup-jlg'),
            'month' => __('mois', 'backup-jlg'),
            'dow' => __('jour de semaine', 'backup-jlg'),
        ];

        $month_names = [
            'jan' => 1,
            'feb' => 2,
            'mar' => 3,
            'apr' => 4,
            'may' => 5,
            'jun' => 6,
            'jul' => 7,
            'aug' => 8,
            'sep' => 9,
            'oct' => 10,
            'nov' => 11,
            'dec' => 12,
        ];

        $dow_names = [
            'sun' => 0,
            'mon' => 1,
            'tue' => 2,
            'wed' => 3,
            'thu' => 4,
            'fri' => 5,
            'sat' => 6,
        ];

        $minutes = $this->parse_cron_field($minute_field, 0, 59, [], $labels['minute'], $warnings, $errors);
        $hours = $this->parse_cron_field($hour_field, 0, 23, [], $labels['hour'], $warnings, $errors);
        $months = $this->parse_cron_field($month_field, 1, 12, $month_names, $labels['month'], $warnings, $errors);

        $dom_any = $this->is_cron_field_wildcard($dom_field);
        $dow_any = $this->is_cron_field_wildcard($dow_field);

        $dom_values = $dom_any ? [] : $this->parse_cron_field($dom_field, 1, 31, [], $labels['dom'], $warnings, $errors);
        $dow_values = $dow_any ? [] : $this->parse_cron_field($dow_field, 0, 6, $dow_names, $labels['dow'], $warnings, $errors);

        if (empty($minutes)) {
            $this->add_diagnostic_message($errors, sprintf(__('Aucune minute valide trouvée. Utilisez des valeurs entre %1$d et %2$d.', 'backup-jlg'), 0, 59));
        }

        if (empty($hours)) {
            $this->add_diagnostic_message($errors, sprintf(__('Aucune heure valide trouvée. Utilisez des valeurs entre %1$d et %2$d.', 'backup-jlg'), 0, 23));
        }

        if (empty($months)) {
            $this->add_diagnostic_message($errors, __('Aucun mois valide trouvé. Utilisez 1–12 ou les abréviations jan–dec.', 'backup-jlg'));
        }

        if (!$dom_any && empty($dom_values)) {
            $this->add_diagnostic_message($errors, __('Le champ « jour du mois » ne contient aucune valeur exploitable.', 'backup-jlg'));
        }

        if (!$dow_any && empty($dow_values)) {
            $this->add_diagnostic_message($errors, __('Le champ « jour de semaine » ne contient aucune valeur exploitable.', 'backup-jlg'));
        }

        if (!$dom_any && !$dow_any) {
            $this->add_diagnostic_message($warnings, __('Jour du mois et jour de semaine sont définis : Cron applique un OU logique entre les deux segments.', 'backup-jlg'));
        }

        if (!empty($errors)) {
            return null;
        }

        return [
            'minutes' => array_values(array_unique($minutes)),
            'hours' => array_values(array_unique($hours)),
            'months' => array_values(array_unique($months)),
            'dom_values' => array_values(array_unique($dom_values)),
            'dow_values' => array_values(array_unique($dow_values)),
            'dom_any' => $dom_any,
            'dow_any' => $dow_any,
        ];
    }

    private function suggest_cron_interval($interval_seconds) {
        if ($interval_seconds <= 300) {
            return '*/5 * * * *';
        }

        if ($interval_seconds <= 900) {
            return '*/15 * * * *';
        }

        if ($interval_seconds <= 1800) {
            return '*/30 * * * *';
        }

        if ($interval_seconds <= 3600) {
            return '0 * * * *';
        }

        return '0 0 * * *';
    }

    private function add_diagnostic_message(?array &$bucket, $message): void
    {
        if (!is_array($bucket)) {
            $bucket = [];
        }

        $normalized = trim((string) $message);
        if ($normalized === '') {
            return;
        }

        if (!in_array($normalized, $bucket, true)) {
            $bucket[] = $normalized;
        }
    }

    /**
     * Fournit un résumé lisible pour une macro de planification.
     */
    public static function describe_schedule_macro(array $macro): array
    {
        $instance = self::instance();
        $expression = isset($macro['expression']) ? (string) $macro['expression'] : '';
        $analysis = $instance->analyze_custom_cron_expression_internal($expression);

        if (is_wp_error($analysis)) {
            $details = $analysis->get_error_data();

            return [
                'expression' => $expression,
                'errors' => [$analysis->get_error_message()],
                'details' => isset($details['details']) ? (array) $details['details'] : [],
                'warnings' => [],
                'next_run' => null,
                'next_run_formatted' => '',
                'next_run_relative' => '',
                'frequency_label' => '',
                'interval_seconds' => null,
                'runs_per_day' => null,
                'timezone' => wp_timezone_string(),
            ];
        }

        $runs = isset($analysis['runs']) && is_array($analysis['runs']) ? $analysis['runs'] : [];
        $next_run = null;
        if (!empty($runs)) {
            foreach ($runs as $run) {
                if ($run instanceof \DateTimeImmutable) {
                    $next_run = $run;
                    break;
                }
            }
        }

        $current = $instance->get_current_time();
        $timezone = wp_timezone_string();
        $next_run_timestamp = $next_run instanceof \DateTimeImmutable ? $next_run->getTimestamp() : null;
        $formatted = '';
        $relative = '';

        if ($next_run_timestamp) {
            $formatted = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run_timestamp);
            $relative = sprintf(__('dans %s', 'backup-jlg'), human_time_diff($current->getTimestamp(), $next_run_timestamp));
        }

        $impact = $instance->generate_cron_impact_summary($expression, [], $analysis);
        $min_interval = isset($analysis['min_interval']) ? (int) $analysis['min_interval'] : null;
        $frequency_label = '';
        if ($min_interval && $min_interval > 0) {
            $frequency_label = sprintf(__('Toutes les %s', 'backup-jlg'), $instance->format_interval_label($min_interval));
        }

        return [
            'expression' => $expression,
            'next_run' => $next_run_timestamp,
            'next_run_formatted' => $formatted,
            'next_run_relative' => $relative,
            'frequency_label' => $frequency_label,
            'interval_seconds' => $min_interval,
            'runs_per_day' => isset($impact['runs_per_day']) ? $impact['runs_per_day'] : null,
            'warnings' => $analysis['warnings'],
            'errors' => $analysis['errors'],
            'timezone' => $timezone,
            'impact' => $impact,
        ];
    }

    private function format_interval_label($seconds) {
        $seconds = (int) $seconds;
        if ($seconds <= 0) {
            return __('immédiatement', 'backup-jlg');
        }

        if ($seconds < MINUTE_IN_SECONDS) {
            return __('moins d’une minute', 'backup-jlg');
        }

        return human_time_diff(time(), time() + $seconds);
    }

    private function get_current_time() {
        return new \DateTimeImmutable('now', wp_timezone());
    }

    private function parse_cron_field($field, $min, $max, array $names = [], $field_label = '', array &$warnings = null, array &$errors = null) {
        $field = strtolower(trim((string) $field));
        if ($field === '' || $field === '*' || $field === '?') {
            return range($min, $max);
        }

        $values = [];
        $segments = explode(',', $field);

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $step = 1;
            if (strpos($segment, '/') !== false) {
                list($segment, $step_part) = explode('/', $segment, 2);
                $step_part = trim((string) $step_part);
                if ($step_part === '' || !is_numeric($step_part)) {
                    $this->add_diagnostic_message($errors, sprintf(__('Le pas « /%1$s » n’est pas valide pour le champ %2$s. Utilisez un entier positif (ex. /5).', 'backup-jlg'), $step_part === '' ? '0' : $step_part, $field_label));
                    continue;
                }

                $step = (int) $step_part;
                if ($step <= 0) {
                    $this->add_diagnostic_message($errors, sprintf(__('Le pas « /%1$s » n’est pas valide pour le champ %2$s. Utilisez un entier positif (ex. /5).', 'backup-jlg'), $step_part, $field_label));
                    continue;
                }
            }

            if ($segment === '' || $segment === '*' || $segment === '?') {
                $start = $min;
                $end = $max;
            } elseif (strpos($segment, '-') !== false) {
                list($start_token, $end_token) = explode('-', $segment, 2);
                $start = $this->cron_value_from_token($start_token, $names, $min, $max, $field_label, $errors);
                $end = $this->cron_value_from_token($end_token, $names, $min, $max, $field_label, $errors);
                if ($start === null || $end === null) {
                    continue;
                }
                if ($end < $start) {
                    $tmp = $start;
                    $start = $end;
                    $end = $tmp;
                }
            } else {
                $start = $this->cron_value_from_token($segment, $names, $min, $max, $field_label, $errors);
                if ($start === null) {
                    continue;
                }
                $end = $start;
            }

            for ($value = $start; $value <= $end; $value++) {
                if (($value - $start) % $step === 0) {
                    $values[$value] = $value;
                }
            }
        }

        ksort($values);

        return array_values($values);
    }

    private function cron_value_from_token($token, array $names, $min, $max, $field_label = '', array &$errors = null) {
        $token = strtolower(trim((string) $token));
        if ($token === '') {
            return null;
        }

        if (isset($names[$token])) {
            $value = (int) $names[$token];
        } elseif (preg_match('/^-?\d+$/', $token)) {
            $value = (int) $token;
        } else {
            if ($field_label !== '') {
                $this->add_diagnostic_message($errors, sprintf(__('Le segment « %1$s » n’est pas reconnu pour le champ %2$s.', 'backup-jlg'), $token, $field_label));
            }
            return null;
        }

        if ($max === 6 && $value === 7) {
            $value = 0;
        }

        if ($value < $min || $value > $max) {
            if ($field_label !== '') {
                $this->add_diagnostic_message($errors, sprintf(__('La valeur %1$s dépasse la plage autorisée pour le champ %2$s (%3$d–%4$d).', 'backup-jlg'), $token, $field_label, $min, $max));
            }
            return null;
        }

        return $value;
    }

    private function cron_day_matches(\DateTimeImmutable $candidate, array $dom_values, array $dow_values, $dom_any, $dow_any) {
        $day = (int) $candidate->format('j');
        $dow = (int) $candidate->format('w');

        if ($dom_any && $dow_any) {
            return true;
        }

        $dom_match = $dom_any ? false : in_array($day, $dom_values, true);
        $dow_match = $dow_any ? false : in_array($dow, $dow_values, true);

        if ($dom_any) {
            return $dow_match;
        }

        if ($dow_any) {
            return $dom_match;
        }

        return $dom_match || $dow_match;
    }

    private function is_cron_field_wildcard($field) {
        $field = strtolower(trim((string) $field));

        return $field === '' || $field === '*' || $field === '?';
    }

    private function schedule_custom_follow_up(array $schedule) {
        $next_timestamp = $this->calculate_first_run($schedule, time());

        if ($next_timestamp) {
            wp_schedule_single_event($next_timestamp, self::SCHEDULE_HOOK, [$schedule['id']]);
        }
    }

    /**
     * Exécute immédiatement une sauvegarde planifiée
     */
    public function handle_run_scheduled_now() {
        if (!\bjlg_can_manage_backups()) {
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
            'secondary_destination_batches' => $schedule['secondary_destination_batches'] ?? [],
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

        if (($schedule['recurrence'] ?? '') === 'custom') {
            $this->schedule_custom_follow_up($schedule);
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
            'secondary_destination_batches' => $schedule['secondary_destination_batches'] ?? [],
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

        if (($schedule['recurrence'] ?? '') === 'custom') {
            $this->schedule_custom_follow_up($schedule);
        }
    }
    
    /**
     * Obtient l'historique des sauvegardes planifiées
     */
    public function get_scheduled_history($limit = 10) {
        global $wpdb;
        $table_name = BJLG_History::get_table_name();
        
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
        $stats = [
            'total_scheduled' => 0,
            'successful' => 0,
            'failed' => 0,
            'success_rate' => 0,
            'last_run' => null,
            'average_duration' => 0
        ];

        if (!is_object($wpdb) || !isset($wpdb->prefix) || !method_exists($wpdb, 'get_var')) {
            return $stats;
        }

        $table_name = BJLG_History::get_table_name();

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
        $last_run = null;

        if (method_exists($wpdb, 'get_row')) {
            $last_run = $wpdb->get_row(
                "SELECT * FROM $table_name
                 WHERE action_type = 'scheduled_backup'
                 ORDER BY timestamp DESC
                 LIMIT 1",
                ARRAY_A
            );
        }

        if ($last_run) {
            $stats['last_run'] = $last_run['timestamp'];
        }

        return $stats;
    }

    /**
     * Calcule un pronostic de capacité basé sur l'historique et les créneaux.
     */
    public function get_capacity_forecast(array $override_schedule = null): array {
        $collection = $this->get_schedule_settings();
        $schedules = isset($collection['schedules']) && is_array($collection['schedules'])
            ? $collection['schedules']
            : [];

        if (is_array($override_schedule)) {
            $merged = false;
            if (!empty($override_schedule['id'])) {
                foreach ($schedules as &$existing) {
                    if (!is_array($existing) || empty($existing['id'])) {
                        continue;
                    }

                    if ($existing['id'] === $override_schedule['id']) {
                        $existing = array_merge($existing, $override_schedule);
                        $merged = true;
                        break;
                    }
                }
                unset($existing);
            }

            if (!$merged) {
                $schedules[] = $override_schedule;
            }
        }

        $next_runs = $this->get_next_runs_summary($schedules);
        $baseline_duration = $this->compute_average_backup_duration_seconds();
        $baseline_duration = max(300, (int) round($baseline_duration));
        $now = current_time('timestamp');

        $windows = [];
        foreach ($schedules as $schedule) {
            if (!is_array($schedule) || empty($schedule['id'])) {
                continue;
            }

            $recurrence = $schedule['recurrence'] ?? 'disabled';
            if ($recurrence === 'disabled') {
                continue;
            }

            $id = (string) $schedule['id'];
            $next_run_summary = isset($next_runs[$id]) ? $next_runs[$id] : null;
            $next_run = $next_run_summary['next_run'] ?? null;
            if (!$next_run) {
                continue;
            }

            $duration = $this->estimate_schedule_duration($schedule, $baseline_duration);
            $windows[] = $this->build_time_window_entry($schedule, $next_run_summary, (int) round($duration), $now);
        }

        usort($windows, static function ($left, $right) {
            if ($left['start'] === $right['start']) {
                return $left['end'] <=> $right['end'];
            }

            return $left['start'] <=> $right['start'];
        });

        $total_duration = 0;
        $span_start = null;
        $span_end = null;

        foreach ($windows as $window) {
            $total_duration += $window['duration'];
            $span_start = $span_start === null ? $window['start'] : min($span_start, $window['start']);
            $span_end = $span_end === null ? $window['end'] : max($span_end, $window['end']);
        }

        $conflicts = [];
        $window_count = count($windows);
        for ($i = 0; $i < $window_count; $i++) {
            for ($j = $i + 1; $j < $window_count; $j++) {
                if ($windows[$j]['start'] >= $windows[$i]['end']) {
                    break;
                }

                $overlap = min($windows[$i]['end'], $windows[$j]['end']) - max($windows[$i]['start'], $windows[$j]['start']);
                if ($overlap <= 0) {
                    continue;
                }

                $conflicts[] = [
                    'primary' => $windows[$i]['id'],
                    'secondary' => $windows[$j]['id'],
                    'participants' => [$windows[$i]['label'], $windows[$j]['label']],
                    'overlap_seconds' => (int) $overlap,
                    'overlap_label' => $this->format_duration_label((int) $overlap),
                    'label' => sprintf(
                        /* translators: 1: schedule label, 2: schedule label */
                        __('Chevauchement entre %1$s et %2$s', 'backup-jlg'),
                        $windows[$i]['label'],
                        $windows[$j]['label']
                    ),
                ];
            }
        }

        $ideal_windows = [];
        if (empty($windows)) {
            $gap_start = $now + HOUR_IN_SECONDS;
            $gap_end = $gap_start + (6 * HOUR_IN_SECONDS);
            $ideal_windows[] = $this->format_gap_window($gap_start, $gap_end, $now);
        } else {
            $cursor = $windows[0]['start'];
            foreach ($windows as $window) {
                if ($window['start'] > $cursor) {
                    $ideal_windows[] = $this->format_gap_window($cursor, $window['start'], $now);
                }
                $cursor = max($cursor, $window['end']);
            }

            $ideal_windows[] = $this->format_gap_window($cursor, $cursor + max($baseline_duration, 900), $now);
        }

        $ideal_windows = array_values(array_filter($ideal_windows, static function ($entry) use ($baseline_duration) {
            return isset($entry['duration']) && $entry['duration'] >= max(600, (int) round($baseline_duration / 2));
        }));

        $events = [];
        foreach ($windows as $window) {
            $events[] = [$window['start'], 1];
            $events[] = [$window['end'], -1];
        }
        usort($events, static function ($left, $right) {
            if ($left[0] === $right[0]) {
                return $left[1] <=> $right[1];
            }

            return $left[0] <=> $right[0];
        });

        $active = 0;
        $peak = 0;
        foreach ($events as $event) {
            $active += (int) $event[1];
            if ($active > $peak) {
                $peak = $active;
            }
        }

        $total_seconds = (int) round($total_duration);
        $total_hours = $total_seconds > 0 ? round($total_seconds / HOUR_IN_SECONDS, 2) : 0.0;
        $span = ($span_end !== null && $span_start !== null) ? max(0, $span_end - $span_start) : 0;
        $density_percent = $span > 0
            ? min(100, round(($total_duration / $span) * 100, 2))
            : 0.0;

        $load_level = 'low';
        if ($peak > 2 || $total_seconds >= 10800 || $density_percent >= 80) {
            $load_level = 'high';
        } elseif ($peak > 1 || $total_seconds >= 5400 || $density_percent >= 55) {
            $load_level = 'medium';
        }

        $advice = [];
        if ($peak > 1) {
            $advice[] = [
                'severity' => 'warning',
                'message' => __('Plusieurs sauvegardes sont prévues en parallèle. Répartissez-les pour éviter la saturation.', 'backup-jlg'),
            ];
        }

        if ($density_percent > 70) {
            $advice[] = [
                'severity' => 'warning',
                'message' => __('La fenêtre planifiée est très dense. Envisagez des sauvegardes incrémentales ou des horaires alternatifs.', 'backup-jlg'),
            ];
        }

        if (empty($conflicts) && empty($advice)) {
            $advice[] = [
                'severity' => 'success',
                'message' => __('Aucun conflit détecté : la répartition actuelle est équilibrée.', 'backup-jlg'),
            ];
        }

        if (!empty($ideal_windows)) {
            $first_gap = $ideal_windows[0];
            $advice[] = [
                'severity' => 'info',
                'message' => sprintf(
                    /* translators: %s: formatted duration */
                    __('Fenêtre disponible d’environ %s pour ajouter une sauvegarde.', 'backup-jlg'),
                    $first_gap['duration_label'] ?? ''
                ),
            ];
        }

        $estimated_load = [
            'baseline_duration' => $baseline_duration,
            'total_seconds' => $total_seconds,
            'total_hours' => $total_hours,
            'peak_concurrent' => $peak,
            'density_percent' => $density_percent,
            'window_count' => $window_count,
            'load_level' => $load_level,
        ];

        return [
            'generated_at' => $now,
            'average_duration' => $baseline_duration,
            'estimated_load' => $estimated_load,
            'windows' => $windows,
            'conflicts' => $conflicts,
            'ideal_windows' => array_slice($ideal_windows, 0, 5),
            'advice' => $advice,
            'suggested_adjustments' => $this->build_suggested_adjustments($load_level),
            'draft_schedule' => $override_schedule,
        ];
    }

    private function compute_average_backup_duration_seconds(): float {
        $stats = \bjlg_get_option('bjlg_performance_stats', []);
        $durations = [];

        if (is_array($stats)) {
            foreach ($stats as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (!isset($entry['duration']) || !is_numeric($entry['duration'])) {
                    continue;
                }
                $durations[] = (float) $entry['duration'];
            }
        }

        if (empty($durations) && class_exists(BJLG_History::class)) {
            $history_entries = BJLG_History::get_history(20, ['action_type' => 'backup_created']);
            if (is_array($history_entries)) {
                foreach ($history_entries as $entry) {
                    if (!is_array($entry) || empty($entry['details'])) {
                        continue;
                    }
                    if (preg_match('/Durée\s*:\s*(\d+(?:\.\d+)?)/', (string) $entry['details'], $matches)) {
                        $durations[] = (float) $matches[1];
                    }
                }
            }
        }

        if (empty($durations)) {
            return 900.0;
        }

        $average = array_sum($durations) / count($durations);

        return max(300.0, (float) $average);
    }

    private function estimate_schedule_duration(array $schedule, float $baseline): float {
        $components = isset($schedule['components']) && is_array($schedule['components'])
            ? array_values($schedule['components'])
            : [];

        $component_factor = 1.0 + (max(0, count($components) - 1) * 0.2);
        if (in_array('uploads', $components, true)) {
            $component_factor += 0.15;
        }

        $duration = $baseline * $component_factor;

        if (!empty($schedule['incremental'])) {
            $duration *= 0.6;
        }

        return max(300.0, $duration);
    }

    private function build_time_window_entry(array $schedule, array $summary, int $duration, int $now): array {
        $start = isset($summary['next_run']) ? (int) $summary['next_run'] : $now;
        $end = $start + max(300, $duration);
        $label = isset($schedule['label']) && $schedule['label'] !== ''
            ? (string) $schedule['label']
            : (isset($schedule['id']) ? (string) $schedule['id'] : '');

        return [
            'id' => isset($schedule['id']) ? (string) $schedule['id'] : '',
            'label' => $label,
            'start' => $start,
            'end' => $end,
            'duration' => $end - $start,
            'duration_label' => $this->format_duration_label($end - $start),
            'start_formatted' => get_date_from_gmt($this->format_gmt_datetime($start), 'd/m/Y H:i'),
            'end_formatted' => get_date_from_gmt($this->format_gmt_datetime($end), 'd/m/Y H:i'),
            'start_relative' => $start >= $now ? human_time_diff($start, $now) : __('déjà passé', 'backup-jlg'),
        ];
    }

    private function format_gap_window(int $start, int $end, int $now): array {
        $gap_start = min($start, $end);
        $gap_end = max($start, $end);
        $duration = max(0, $gap_end - $gap_start);

        return [
            'start' => $gap_start,
            'end' => $gap_end,
            'duration' => $duration,
            'duration_label' => $this->format_duration_label($duration),
            'label' => sprintf(
                /* translators: %s: formatted duration */
                __('Fenêtre libre (~%s)', 'backup-jlg'),
                $this->format_duration_label($duration)
            ),
            'start_formatted' => get_date_from_gmt($this->format_gmt_datetime($gap_start), 'd/m/Y H:i'),
            'end_formatted' => get_date_from_gmt($this->format_gmt_datetime($gap_end), 'd/m/Y H:i'),
            'start_relative' => $gap_start >= $now ? human_time_diff($gap_start, $now) : __('immédiat', 'backup-jlg'),
        ];
    }

    private function build_suggested_adjustments(string $load_level): array {
        switch ($load_level) {
            case 'high':
                return [
                    'scenario_id' => 'pre_deploy',
                    'label' => __('Snapshots rapides pré-déploiement', 'backup-jlg'),
                    'recurrence' => 'custom',
                    'custom_cron' => '*/10 * * * *',
                    'components' => ['db', 'plugins', 'themes'],
                    'incremental' => false,
                ];
            case 'medium':
                return [
                    'scenario_id' => 'weekly_media',
                    'label' => __('Synchronisation médias hebdomadaire', 'backup-jlg'),
                    'recurrence' => 'weekly',
                    'day' => 'sunday',
                    'time' => '04:00',
                    'components' => ['uploads'],
                    'incremental' => true,
                ];
        }

        return [
            'scenario_id' => 'nightly_full',
            'label' => __('Archive complète nocturne', 'backup-jlg'),
            'recurrence' => 'daily',
            'time' => '02:30',
            'components' => ['db', 'plugins', 'themes', 'uploads'],
            'incremental' => false,
        ];
    }

    private function format_duration_label(int $seconds): string {
        if ($seconds <= 0) {
            return __('instantané', 'backup-jlg');
        }

        if ($seconds < MINUTE_IN_SECONDS) {
            return sprintf(__('%ds', 'backup-jlg'), $seconds);
        }

        if ($seconds < HOUR_IN_SECONDS) {
            $minutes = max(1, (int) round($seconds / MINUTE_IN_SECONDS));

            return sprintf(__('%d min', 'backup-jlg'), $minutes);
        }

        if ($seconds < DAY_IN_SECONDS) {
            $hours = round($seconds / HOUR_IN_SECONDS, 1);

            return sprintf(__('%1$.1fh', 'backup-jlg'), $hours);
        }

        $days = round($seconds / DAY_IN_SECONDS, 1);

        return sprintf(__('%1$.1fj', 'backup-jlg'), $days);
    }

    private function get_destination_batch_size() {
        $size = (int) apply_filters('bjlg_scheduler_destination_batch_size', 2);

        return max(1, $size);
    }

    private function normalize_destination_batches($batches, array $destinations, $batch_size) {
        $sanitized_batches = BJLG_Settings::sanitize_destination_batches(
            $batches,
            BJLG_Settings::get_known_destination_ids()
        );

        if (!empty($sanitized_batches)) {
            return $this->rebalance_destination_batches($sanitized_batches, $batch_size);
        }

        $sanitized_destinations = BJLG_Settings::sanitize_destination_list(
            $destinations,
            BJLG_Settings::get_known_destination_ids()
        );

        if (empty($sanitized_destinations)) {
            return [];
        }

        return $this->rebalance_destination_batches([$sanitized_destinations], $batch_size);
    }

    private function rebalance_destination_batches(array $batches, $batch_size) {
        $flattened = BJLG_Settings::flatten_destination_batches($batches);

        if (empty($flattened)) {
            return [];
        }

        $batch_size = max(1, (int) $batch_size);

        return array_chunk($flattened, $batch_size);
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
        $stored = \bjlg_get_option('bjlg_schedule_settings', []);
        $collection = BJLG_Settings::sanitize_schedule_collection($stored);

        if ($stored !== $collection) {
            \bjlg_update_option('bjlg_schedule_settings', $collection);
        }

        return $collection;
    }

    private function normalize_schedule_settings($settings) {
        // Conservé pour compatibilité interne éventuelle. Renvoie désormais l'ensemble de la collection.
        return BJLG_Settings::sanitize_schedule_collection($settings);
    }

    public function handle_toggle_schedule_state() {
        if (!\bjlg_can_manage_backups()) {
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
        \bjlg_update_option('bjlg_schedule_settings', $collection);

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
        if (!\bjlg_can_manage_backups()) {
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
        \bjlg_update_option('bjlg_schedule_settings', $collection);

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

    public static function get_event_trigger_defaults(): array
    {
        return [
            'version' => 1,
            'triggers' => [
                'filesystem' => [
                    'enabled' => false,
                    'cooldown' => 600,
                    'batch_window' => 120,
                    'max_batch' => 10,
                ],
                'database' => [
                    'enabled' => false,
                    'cooldown' => 300,
                    'batch_window' => 60,
                    'max_batch' => 10,
                ],
            ],
        ];
    }

    public static function sanitize_event_trigger_settings($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        $defaults = self::get_event_trigger_defaults();
        $sanitized = ['version' => 1, 'triggers' => []];
        $raw_triggers = [];

        if (is_array($raw) && isset($raw['triggers']) && is_array($raw['triggers'])) {
            $raw_triggers = $raw['triggers'];
        } elseif (is_array($raw)) {
            $raw_triggers = $raw;
        }

        foreach ($defaults['triggers'] as $trigger_key => $default_settings) {
            $entry = isset($raw_triggers[$trigger_key]) && is_array($raw_triggers[$trigger_key])
                ? $raw_triggers[$trigger_key]
                : [];

            $enabled = !empty($entry['enabled']);
            $cooldown = isset($entry['cooldown']) ? (int) $entry['cooldown'] : $default_settings['cooldown'];
            $batch_window = isset($entry['batch_window']) ? (int) $entry['batch_window'] : $default_settings['batch_window'];
            $max_batch = isset($entry['max_batch']) ? (int) $entry['max_batch'] : $default_settings['max_batch'];

            $sanitized['triggers'][$trigger_key] = [
                'enabled' => $enabled,
                'cooldown' => max(0, $cooldown),
                'batch_window' => max(0, $batch_window),
                'max_batch' => max(1, $max_batch),
            ];
        }

        return $sanitized;
    }

    public function get_event_trigger_settings(): array
    {
        $stored = \bjlg_get_option(self::EVENT_SETTINGS_OPTION, []);
        $sanitized = self::sanitize_event_trigger_settings($stored);

        if (!is_array($stored) || $stored !== $sanitized) {
            $this->save_event_trigger_settings($sanitized);
        }

        return $sanitized;
    }

    private function save_event_trigger_settings(array $settings): void
    {
        \bjlg_update_option(self::EVENT_SETTINGS_OPTION, $settings, null, null, false);
    }

    public function handle_event_trigger(string $trigger_key, array $payload = []): void
    {
        $normalized_key = $this->normalize_trigger_key($trigger_key);
        $defaults = self::get_event_trigger_defaults();

        if (!isset($defaults['triggers'][$normalized_key])) {
            return;
        }

        $settings = $this->get_event_trigger_settings();
        $trigger_settings = $settings['triggers'][$normalized_key] ?? $defaults['triggers'][$normalized_key];

        if (empty($trigger_settings['enabled'])) {
            return;
        }

        $state = $this->get_event_state();
        $now = current_time('timestamp');

        $bucket = isset($state['pending'][$normalized_key]) && is_array($state['pending'][$normalized_key])
            ? $state['pending'][$normalized_key]
            : [
                'first_seen' => $now,
                'last_seen' => $now,
                'count' => 0,
                'payloads' => [],
            ];

        $bucket['first_seen'] = isset($bucket['first_seen']) ? (int) $bucket['first_seen'] : $now;
        $bucket['last_seen'] = $now;
        $bucket['count'] = (int) ($bucket['count'] ?? 0) + 1;

        $normalized_payload = $this->normalize_event_payload($payload);
        if (!empty($normalized_payload)) {
            $bucket = $this->append_event_payload($bucket, $normalized_payload);
        }

        $state['pending'][$normalized_key] = $bucket;

        if ($this->should_dispatch_event($normalized_key, $trigger_settings, $bucket, $now, $state)) {
            $dispatched = $this->dispatch_event_trigger($normalized_key, $trigger_settings, $bucket, $now);
            if ($dispatched) {
                unset($state['pending'][$normalized_key]);
                $state['last_dispatch'][$normalized_key] = $now;
                unset($state['next_run'][$normalized_key]);
            } else {
                $next_attempt = $this->calculate_next_event_run($normalized_key, $trigger_settings, $bucket, $now, $state);
                if ($next_attempt !== null) {
                    $state['next_run'][$normalized_key] = $next_attempt;
                    $this->schedule_event_trigger($normalized_key, $next_attempt);
                }
            }

            $this->save_event_state($state);

            return;
        }

        $next_run = $this->calculate_next_event_run($normalized_key, $trigger_settings, $bucket, $now, $state);
        if ($next_run !== null) {
            $state['next_run'][$normalized_key] = $next_run;
            $this->schedule_event_trigger($normalized_key, $next_run);
        }

        $this->save_event_state($state);
    }

    public function process_event_trigger_queue($trigger_key = null): void
    {
        $state = $this->get_event_state();
        $settings = $this->get_event_trigger_settings();
        $now = current_time('timestamp');
        $updated = false;

        $targets = [];
        if (is_string($trigger_key) && $trigger_key !== '') {
            $targets[] = $this->normalize_trigger_key($trigger_key);
        } else {
            $targets = array_keys($state['pending']);
        }

        foreach ($targets as $key) {
            if (!isset($state['pending'][$key])) {
                continue;
            }

            $bucket = $state['pending'][$key];
            $trigger_settings = $settings['triggers'][$key] ?? null;

            if (!$trigger_settings || empty($trigger_settings['enabled'])) {
                unset($state['pending'][$key], $state['next_run'][$key]);
                $updated = true;
                continue;
            }

            if ($this->should_dispatch_event($key, $trigger_settings, $bucket, $now, $state)) {
                $dispatched = $this->dispatch_event_trigger($key, $trigger_settings, $bucket, $now);
                if ($dispatched) {
                    unset($state['pending'][$key]);
                    $state['last_dispatch'][$key] = $now;
                    unset($state['next_run'][$key]);
                    $updated = true;
                    continue;
                }
            }

            $next_run = $this->calculate_next_event_run($key, $trigger_settings, $bucket, $now, $state);
            if ($next_run !== null) {
                $state['next_run'][$key] = $next_run;
                $this->schedule_event_trigger($key, $next_run);
            } else {
                unset($state['next_run'][$key]);
            }
            $updated = true;
        }

        if ($updated) {
            $this->save_event_state($state);
        }
    }

    public function resume_event_trigger_queue(): void
    {
        $state = $this->get_event_state();
        if (empty($state['pending'])) {
            return;
        }

        $settings = $this->get_event_trigger_settings();
        $now = current_time('timestamp');
        $updated = false;

        foreach ($state['pending'] as $key => $bucket) {
            $trigger_settings = $settings['triggers'][$key] ?? null;

            if (!$trigger_settings || empty($trigger_settings['enabled'])) {
                unset($state['pending'][$key], $state['next_run'][$key]);
                $updated = true;
                continue;
            }

            $next_run = isset($state['next_run'][$key]) ? (int) $state['next_run'][$key] : null;
            if ($next_run === null || $next_run < $now) {
                $next_run = $this->calculate_next_event_run($key, $trigger_settings, $bucket, $now, $state);
            }

            if ($next_run !== null) {
                $state['next_run'][$key] = $next_run;
                $this->schedule_event_trigger($key, $next_run);
                $updated = true;
            }
        }

        if ($updated) {
            $this->save_event_state($state);
        }
    }

    private function should_dispatch_event(string $trigger_key, array $settings, array $bucket, int $now, array $state): bool
    {
        if (empty($settings['enabled'])) {
            return false;
        }

        $last_dispatch = isset($state['last_dispatch'][$trigger_key]) ? (int) $state['last_dispatch'][$trigger_key] : 0;
        $cooldown = max(0, (int) ($settings['cooldown'] ?? 0));

        if ($cooldown > 0 && $last_dispatch > 0 && ($now - $last_dispatch) < $cooldown) {
            return false;
        }

        $count = isset($bucket['count']) ? (int) $bucket['count'] : 0;
        $max_batch = max(1, (int) ($settings['max_batch'] ?? 1));

        if ($count >= $max_batch) {
            return true;
        }

        $batch_window = max(0, (int) ($settings['batch_window'] ?? 0));
        if ($batch_window === 0) {
            return true;
        }

        $first_seen = isset($bucket['first_seen']) ? (int) $bucket['first_seen'] : $now;

        return ($now - $first_seen) >= $batch_window;
    }

    private function dispatch_event_trigger(string $trigger_key, array $settings, array $bucket, int $now): bool
    {
        $task_id = 'bjlg_backup_' . md5(uniqid('event', true));
        $default_schedule = BJLG_Settings::get_default_schedule_entry();
        $components = $this->resolve_event_components($trigger_key, $default_schedule);

        $samples = isset($bucket['payloads']) && is_array($bucket['payloads'])
            ? array_values($bucket['payloads'])
            : [];

        $task_data = [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => __('Initialisation (événement)...', 'backup-jlg'),
            'components' => $components,
            'encrypt' => (bool) ($default_schedule['encrypt'] ?? false),
            'incremental' => true,
            'source' => 'event',
            'start_time' => $now,
            'include_patterns' => $default_schedule['include_patterns'],
            'exclude_patterns' => $default_schedule['exclude_patterns'],
            'post_checks' => $default_schedule['post_checks'],
            'secondary_destinations' => $default_schedule['secondary_destinations'],
            'secondary_destination_batches' => $default_schedule['secondary_destination_batches'],
            'event_trigger' => [
                'key' => $trigger_key,
                'count' => (int) ($bucket['count'] ?? 1),
                'first_seen' => isset($bucket['first_seen']) ? (int) $bucket['first_seen'] : $now,
                'last_seen' => isset($bucket['last_seen']) ? (int) $bucket['last_seen'] : $now,
                'samples' => $samples,
            ],
        ];

        if (!set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl())) {
            BJLG_Debug::log("ERREUR : Impossible d'initialiser la sauvegarde événementielle $task_id.");
            BJLG_History::log(
                'event_trigger',
                'failure',
                __('Échec de l\'initialisation de la sauvegarde événementielle.', 'backup-jlg')
            );

            return false;
        }

        $scheduled = wp_schedule_single_event($now, 'bjlg_run_backup_task', ['task_id' => $task_id]);
        if (!$scheduled) {
            delete_transient($task_id);
            BJLG_Debug::log("ERREUR : Impossible de planifier la sauvegarde événementielle $task_id.");
            BJLG_History::log(
                'event_trigger',
                'failure',
                __('Échec de la planification de la sauvegarde événementielle.', 'backup-jlg')
            );

            return false;
        }

        BJLG_Debug::log(sprintf('Sauvegarde événementielle déclenchée (%s) - Task ID: %s', $trigger_key, $task_id));
        BJLG_History::log(
            'event_trigger',
            'info',
            sprintf(
                __('Déclencheur "%1$s" : %2$d évènement(s) regroupé(s).', 'backup-jlg'),
                $trigger_key,
                (int) ($bucket['count'] ?? 1)
            )
        );

        return true;
    }

    private function resolve_event_components(string $trigger_key, array $default_schedule): array
    {
        if ($trigger_key === 'filesystem') {
            return ['uploads', 'plugins', 'themes'];
        }

        if ($trigger_key === 'database') {
            return ['db'];
        }

        return isset($default_schedule['components']) && is_array($default_schedule['components'])
            ? $default_schedule['components']
            : ['db'];
    }

    private function calculate_next_event_run(string $trigger_key, array $settings, array $bucket, int $now, array $state): ?int
    {
        if (empty($settings['enabled'])) {
            return null;
        }

        $last_dispatch = isset($state['last_dispatch'][$trigger_key]) ? (int) $state['last_dispatch'][$trigger_key] : 0;
        $cooldown = max(0, (int) ($settings['cooldown'] ?? 0));
        $batch_window = max(0, (int) ($settings['batch_window'] ?? 0));
        $max_batch = max(1, (int) ($settings['max_batch'] ?? 1));
        $count = isset($bucket['count']) ? (int) $bucket['count'] : 0;
        $first_seen = isset($bucket['first_seen']) ? (int) $bucket['first_seen'] : $now;

        $cooldown_until = ($cooldown > 0 && $last_dispatch > 0) ? $last_dispatch + $cooldown : $now;

        if ($count >= $max_batch && $cooldown_until <= $now) {
            return $now;
        }

        $window_deadline = $batch_window > 0 ? $first_seen + $batch_window : $now;

        return max($now, $cooldown_until, $window_deadline);
    }

    private function schedule_event_trigger(string $trigger_key, int $timestamp): void
    {
        if (!function_exists('wp_schedule_single_event') || !function_exists('wp_next_scheduled')) {
            return;
        }

        $timestamp = max(current_time('timestamp'), $timestamp);
        $args = [$trigger_key];
        $existing = wp_next_scheduled(self::EVENT_CRON_HOOK, $args);

        if ($existing !== false && $existing <= $timestamp) {
            return;
        }

        if ($existing !== false && function_exists('wp_unschedule_event')) {
            wp_unschedule_event($existing, self::EVENT_CRON_HOOK, $args);
        }

        wp_schedule_single_event($timestamp, self::EVENT_CRON_HOOK, $args);
    }

    private function get_event_state(): array
    {
        $stored = \bjlg_get_option(self::EVENT_STATE_OPTION, []);
        $defaults = self::get_event_trigger_defaults();
        $valid_triggers = array_keys($defaults['triggers']);
        $state = [
            'version' => 1,
            'pending' => [],
            'last_dispatch' => [],
            'next_run' => [],
        ];

        if (is_array($stored)) {
            if (isset($stored['pending']) && is_array($stored['pending'])) {
                foreach ($stored['pending'] as $key => $bucket) {
                    if (!in_array($key, $valid_triggers, true) || !is_array($bucket)) {
                        continue;
                    }

                    $state['pending'][$key] = [
                        'first_seen' => isset($bucket['first_seen']) ? (int) $bucket['first_seen'] : current_time('timestamp'),
                        'last_seen' => isset($bucket['last_seen']) ? (int) $bucket['last_seen'] : current_time('timestamp'),
                        'count' => max(1, (int) ($bucket['count'] ?? 1)),
                        'payloads' => $this->sanitize_payload_samples($bucket['payloads'] ?? []),
                    ];
                }
            }

            if (isset($stored['last_dispatch']) && is_array($stored['last_dispatch'])) {
                foreach ($stored['last_dispatch'] as $key => $timestamp) {
                    if (in_array($key, $valid_triggers, true)) {
                        $state['last_dispatch'][$key] = (int) $timestamp;
                    }
                }
            }

            if (isset($stored['next_run']) && is_array($stored['next_run'])) {
                foreach ($stored['next_run'] as $key => $timestamp) {
                    if (in_array($key, $valid_triggers, true)) {
                        $state['next_run'][$key] = (int) $timestamp;
                    }
                }
            }
        }

        return $state;
    }

    private function save_event_state(array $state): void
    {
        \bjlg_update_option(self::EVENT_STATE_OPTION, $state, null, null, false);
    }

    private function sanitize_payload_samples($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $samples = [];
        foreach ($raw as $sample) {
            if (!is_array($sample)) {
                continue;
            }

            $normalized = [];
            foreach ($sample as $key => $value) {
                if (!is_scalar($value)) {
                    continue;
                }

                $normalized_key = $this->sanitize_payload_key($key);
                if ($normalized_key === '') {
                    continue;
                }

                $normalized[$normalized_key] = $this->sanitize_payload_string((string) $value);
            }

            if (!empty($normalized)) {
                $samples[] = $normalized;
            }

            if (count($samples) >= self::MAX_EVENT_SAMPLES) {
                break;
            }
        }

        return $samples;
    }

    private function normalize_event_payload(array $payload): array
    {
        $normalized = [];

        foreach ($payload as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $normalized_key = $this->sanitize_payload_key($key);
            if ($normalized_key === '') {
                continue;
            }

            $normalized[$normalized_key] = $this->sanitize_payload_string((string) $value);
        }

        return $normalized;
    }

    private function append_event_payload(array $bucket, array $payload): array
    {
        if (empty($payload)) {
            return $bucket;
        }

        $existing = isset($bucket['payloads']) && is_array($bucket['payloads']) ? $bucket['payloads'] : [];
        $serialized_payload = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);

        foreach ($existing as $entry) {
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($entry) : json_encode($entry);
            if ($encoded === $serialized_payload) {
                return $bucket;
            }
        }

        $existing[] = $payload;

        if (count($existing) > self::MAX_EVENT_SAMPLES) {
            $existing = array_slice($existing, -self::MAX_EVENT_SAMPLES);
        }

        $bucket['payloads'] = array_values($existing);

        return $bucket;
    }

    private function sanitize_payload_key($key): string
    {
        $key = is_string($key) ? $key : '';

        if (function_exists('sanitize_key')) {
            return sanitize_key($key);
        }

        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }

    private function sanitize_payload_string(string $value): string
    {
        if (function_exists('sanitize_text_field')) {
            $value = sanitize_text_field($value);
        } else {
            $value = trim(strip_tags($value));
        }

        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, 160);
        } else {
            $value = substr($value, 0, 160);
        }

        return $value;
    }

    private function normalize_trigger_key(string $trigger_key): string
    {
        if (function_exists('sanitize_key')) {
            return sanitize_key($trigger_key);
        }

        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($trigger_key));
    }
}
