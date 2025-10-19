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
    }

    private function __construct() {
        // Actions AJAX
        add_action('wp_ajax_bjlg_save_schedule_settings', [$this, 'handle_save_schedule']);
        add_action('wp_ajax_bjlg_get_next_scheduled', [$this, 'handle_get_next_scheduled']);
        add_action('wp_ajax_bjlg_run_scheduled_now', [$this, 'handle_run_scheduled_now']);
        add_action('wp_ajax_bjlg_toggle_schedule_state', [$this, 'handle_toggle_schedule_state']);
        add_action('wp_ajax_bjlg_duplicate_schedule', [$this, 'handle_duplicate_schedule']);
        add_action('wp_ajax_bjlg_preview_cron_expression', [$this, 'handle_preview_cron_expression']);

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

        $primary = $this->get_primary_schedule($schedules);
        $aggregated_secondary = array_values(array_unique($all_secondary));

        BJLG_Settings::get_instance()->update_backup_filters(
            $primary['include_patterns'],
            $primary['exclude_patterns'],
            $aggregated_secondary,
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

    public function handle_preview_cron_expression() {
        if (!\bjlg_can_manage_backups()) {
            wp_send_json_error(['message' => __('Permission refusée.', 'backup-jlg')], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $posted = wp_unslash($_POST);
        $raw_expression = isset($posted['expression']) ? $posted['expression'] : '';
        $expression = BJLG_Settings::sanitize_cron_expression($raw_expression);

        if ($expression === '') {
            wp_send_json_error([
                'message' => __('Expression Cron invalide.', 'backup-jlg'),
                'errors' => [__('Indiquez une expression Cron à cinq champs (minute, heure, jour du mois, mois, jour de semaine).', 'backup-jlg')],
            ], 400);
        }

        $timezone = $this->get_wordpress_timezone();
        $now = new \DateTimeImmutable('now', $timezone);
        $occurrences = $this->build_custom_cron_preview($expression, $now, 5);

        if (empty($occurrences)) {
            wp_send_json_error([
                'message' => __('Impossible de calculer les prochaines exécutions.', 'backup-jlg'),
                'errors' => [__('Aucune occurrence trouvée sur l’année à venir. Vérifiez les champs « jour » et « mois ».', 'backup-jlg')],
            ], 422);
        }

        $current_timestamp = current_time('timestamp');
        $response_occurrences = [];
        $previous_timestamp = null;
        $intervals = [];

        foreach ($occurrences as $occurrence) {
            $timestamp = $occurrence->getTimestamp();
            $response_occurrences[] = [
                'timestamp' => $timestamp,
                'formatted' => get_date_from_gmt($this->format_gmt_datetime($timestamp), 'd/m/Y H:i'),
                'relative' => human_time_diff($timestamp, $current_timestamp),
            ];

            if ($previous_timestamp !== null) {
                $intervals[] = max(0, $timestamp - $previous_timestamp);
            }

            $previous_timestamp = $timestamp;
        }

        $warnings = [];
        if (!empty($intervals)) {
            $min_interval = min($intervals);
            if ($min_interval < 5 * MINUTE_IN_SECONDS) {
                $warnings[] = __('Cette expression déclenche la sauvegarde très fréquemment (moins de 5 minutes entre deux passages). Assurez-vous que l’infrastructure peut encaisser cette cadence.', 'backup-jlg');
            } elseif ($min_interval < 15 * MINUTE_IN_SECONDS) {
                $warnings[] = __('Fréquence élevée détectée (moins de 15 minutes). Vérifiez qu’il ne s’agit pas d’une erreur de configuration.', 'backup-jlg');
            }
        }

        wp_send_json_success([
            'expression' => $expression,
            'occurrences' => $response_occurrences,
            'warnings' => $warnings,
        ]);
    }

    /**
     * Retourne la date/heure GMT formatée attendue par get_date_from_gmt().
     */
    private function format_gmt_datetime($timestamp) {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function calculate_custom_cron_next_run($expression, \DateTimeImmutable $now) {
        $expression = trim((string) $expression);
        if ($expression === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $expression);
        if (!is_array($parts) || count($parts) !== 5) {
            return null;
        }

        list($minute_field, $hour_field, $dom_field, $month_field, $dow_field) = $parts;

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

        $minutes = $this->parse_cron_field($minute_field, 0, 59);
        $hours = $this->parse_cron_field($hour_field, 0, 23);
        $months = $this->parse_cron_field($month_field, 1, 12, $month_names);
        $dom_any = $this->is_cron_field_wildcard($dom_field);
        $dow_any = $this->is_cron_field_wildcard($dow_field);
        $dom_values = $dom_any ? [] : $this->parse_cron_field($dom_field, 1, 31);
        $dow_values = $dow_any ? [] : $this->parse_cron_field($dow_field, 0, 6, $dow_names);

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

    private function build_custom_cron_preview(string $expression, \DateTimeImmutable $start, int $count = 5): array {
        $occurrences = [];
        $cursor = $start;

        for ($index = 0; $index < max(1, $count); $index++) {
            $next = $this->calculate_custom_cron_next_run($expression, $cursor);
            if (!$next instanceof \DateTimeImmutable) {
                break;
            }

            $occurrences[] = $next;
            $cursor = $next;
        }

        return $occurrences;
    }

    private function parse_cron_field($field, $min, $max, array $names = []) {
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
                $step = max(1, (int) $step_part);
            }

            if ($segment === '' || $segment === '*' || $segment === '?') {
                $start = $min;
                $end = $max;
            } elseif (strpos($segment, '-') !== false) {
                list($start_token, $end_token) = explode('-', $segment, 2);
                $start = $this->cron_value_from_token($start_token, $names, $min, $max);
                $end = $this->cron_value_from_token($end_token, $names, $min, $max);
                if ($start === null || $end === null) {
                    continue;
                }
                if ($end < $start) {
                    $tmp = $start;
                    $start = $end;
                    $end = $tmp;
                }
            } else {
                $start = $this->cron_value_from_token($segment, $names, $min, $max);
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

    private function cron_value_from_token($token, array $names, $min, $max) {
        $token = strtolower(trim((string) $token));
        if ($token === '') {
            return null;
        }

        if (isset($names[$token])) {
            $value = (int) $names[$token];
        } elseif (preg_match('/^-?\d+$/', $token)) {
            $value = (int) $token;
        } else {
            return null;
        }

        if ($max === 6 && $value === 7) {
            $value = 0;
        }

        if ($value < $min || $value > $max) {
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

        $table_name = $wpdb->prefix . 'bjlg_history';

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
}
