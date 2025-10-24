<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Traite la file de purge distante issue des sauvegardes incrémentales.
 */
class BJLG_Remote_Purge_Worker {

    private const HOOK = 'bjlg_process_remote_purge_queue';
    private const LOCK_TRANSIENT = 'bjlg_remote_purge_lock';
    private const LOCK_DURATION = 60; // secondes
    private const MAX_ENTRIES_PER_RUN = 3;
    private const MAX_ATTEMPTS = 5;
    private const BASE_BACKOFF = 60; // secondes
    private const MAX_BACKOFF = 15 * MINUTE_IN_SECONDS;
    private const DELAY_ALERT_THRESHOLD = 10 * MINUTE_IN_SECONDS;
    private const ALERT_ATTEMPT_THRESHOLD = 3;
    private const FORECAST_HISTORY_LIMIT = 20;
    private const DEFAULT_FORECAST_LEAD_TIME = 3 * DAY_IN_SECONDS;

    public function __construct() {
        add_filter('cron_schedules', [$this, 'register_cron_schedule']);
        add_action(self::HOOK, [$this, 'process_queue']);
        add_action('bjlg_incremental_remote_purge', [$this, 'schedule_async_processing'], 10, 2);
        add_action('init', [$this, 'ensure_schedule']);
    }

    /**
     * Garantit la disponibilité de l'intervalle personnalisé requis par la tâche.
     *
     * @param array<string, array<string, mixed>> $schedules
     *
     * @return array<string, array<string, mixed>>
     */
    public function register_cron_schedule($schedules) {
        if (!is_array($schedules)) {
            $schedules = [];
        }

        if (!isset($schedules['every_five_minutes'])) {
            $schedules['every_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => __('Toutes les 5 minutes', 'backup-jlg'),
            ];
        }

        return $schedules;
    }

    /**
     * Planifie un passage régulier pour éviter les purges oubliées.
     */
    public function ensure_schedule() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'every_five_minutes', self::HOOK);
        }
    }

    /**
     * Programme un traitement rapide après l'enregistrement d'une purge.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $manifest
     */
    public function schedule_async_processing($entry, $manifest) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        if (!wp_next_scheduled(self::HOOK, [])) {
            wp_schedule_single_event(time() + 15, self::HOOK);
        }
    }

    /**
     * Traite la file de purge distante.
     */
    public function process_queue() {
        if ($this->is_locked()) {
            return;
        }

        $this->lock();

        try {
            $incremental = new BJLG_Incremental();
            $queue = $incremental->get_remote_purge_queue();

            if (empty($queue)) {
                $this->update_metrics([], [], time());
                return;
            }

            $now = time();
            $processed = 0;
            $results = [];
            foreach ($queue as $entry) {
                if ($processed >= self::MAX_ENTRIES_PER_RUN) {
                    break;
                }

                if (!is_array($entry)) {
                    continue;
                }

                if (!$this->should_process_entry($entry, $now)) {
                    continue;
                }

                $handled = $this->handle_queue_entry($incremental, $entry, $now);
                if (is_array($handled)) {
                    if (!empty($handled['processed'])) {
                        $processed++;
                    }
                    $results[] = $handled;
                }
            }
        } finally {
            $this->unlock();
        }

        $remaining = (new BJLG_Incremental())->get_remote_purge_queue();
        $this->update_metrics($remaining, $results, isset($now) ? $now : time());
        $delay = $this->get_next_delay($remaining);
        if ($delay !== null) {
            wp_schedule_single_event(time() + $delay, self::HOOK);
        }
    }

    /**
     * Traite une entrée de la file.
     *
     * @param array<string,mixed> $entry
     */
    private function handle_queue_entry(BJLG_Incremental $incremental, array $entry, $now) {
        $file = isset($entry['file']) ? basename((string) $entry['file']) : '';
        if ($file === '') {
            return ['processed' => false];
        }

        $destinations = isset($entry['destinations']) && is_array($entry['destinations'])
            ? array_values($entry['destinations'])
            : [];

        if (empty($destinations)) {
            return ['processed' => false];
        }

        $attempts = isset($entry['attempts']) ? (int) $entry['attempts'] : 0;
        $current_attempt = $attempts + 1;
        $last_attempt_at = isset($entry['last_attempt_at']) ? (int) $entry['last_attempt_at'] : 0;
        $registered_at = isset($entry['registered_at']) ? (int) $entry['registered_at'] : $now;
        if ($registered_at <= 0) {
            $registered_at = $now;
        }

        $anchor = $last_attempt_at > 0 ? $last_attempt_at : $registered_at;
        $wait_time = max(0, $now - $anchor);
        $previous_max_delay = isset($entry['max_delay']) ? (int) $entry['max_delay'] : 0;
        $max_delay = max($previous_max_delay, $wait_time);
        $was_alerted = !empty($entry['delay_alerted']);

        if ($wait_time > 0) {
            $this->log_history(
                'info',
                sprintf(
                    __('Tentative #%1$s pour %2$s après %3$s d\'attente.', 'backup-jlg'),
                    number_format_i18n($current_attempt),
                    $file,
                    $this->format_delay_label($wait_time)
                )
            );
        }

        $incremental->update_remote_purge_entry($file, [
            'status' => 'processing',
            'attempts' => $current_attempt,
            'last_attempt_at' => $now,
            'next_attempt_at' => 0,
            'last_delay' => $wait_time,
            'max_delay' => $max_delay,
            'delay_alerted' => $was_alerted,
        ]);

        $success = [];
        $errors = [];

        $destination_names = [];
        $quota_samples = [];

        foreach ($destinations as $destination_id) {
            $destination = BJLG_Destination_Factory::create($destination_id);
            if (!$destination instanceof BJLG_Destination_Interface) {
                $errors[$destination_id] = sprintf(__('Destination inconnue : %s', 'backup-jlg'), $destination_id);
                continue;
            }

            $destination_names[$destination_id] = $destination->get_name();

            $result = $destination->delete_remote_backup_by_name($file);
            $was_successful = is_array($result) ? !empty($result['success']) : false;

            if (is_array($result)) {
                $quota_sample = $this->extract_quota_sample($destination_id, $result, $now);
                if ($quota_sample !== null) {
                    $quota_samples[$destination_id] = $quota_sample;
                }
            }

            if ($was_successful) {
                $success[] = $destination_id;
            } else {
                $message = '';
                if (is_array($result) && isset($result['message'])) {
                    $message = trim((string) $result['message']);
                }
                if ($message === '') {
                    $message = __('La suppression distante a échoué.', 'backup-jlg');
                }

                $errors[$destination_id] = $message;

                $destination_label = $destination->get_name();
                if (class_exists(BJLG_Debug::class)) {
                    BJLG_Debug::log(sprintf('Purge distante %s (%s) : %s', $destination_label, $destination_id, $message));
                }
            }
        }

        if (!empty($success)) {
            $incremental->mark_remote_purge_completed($file, $success);
            $success_labels = [];
            foreach ($success as $destination_id) {
                $label = $destination_names[$destination_id] ?? $destination_id;
                $success_labels[] = $label;
            }

            $message = sprintf(
                __('Purge distante réussie pour %1$s via %2$s.', 'backup-jlg'),
                $file,
                implode(', ', $success_labels)
            );
            $this->log_history('success', $message);
        }

        $remaining = array_values(array_diff($destinations, $success));

        if (empty($remaining)) {
            $incremental->update_remote_purge_entry($file, [
                'status' => 'completed',
                'errors' => [],
                'last_error' => '',
                'next_attempt_at' => 0,
                'failed_at' => 0,
                'last_delay' => $wait_time,
                'max_delay' => $max_delay,
                'delay_alerted' => false,
            ]);

            do_action('bjlg_remote_purge_completed', $file, [
                'destinations' => $success,
                'attempts' => $current_attempt,
            ]);

            return [
                'processed' => true,
                'outcome' => 'completed',
                'file' => $file,
                'registered_at' => $registered_at,
                'timestamp' => $now,
                'attempts' => $current_attempt,
                'destinations' => $success,
                'duration' => max(0, $now - $registered_at),
                'quota_samples' => $quota_samples,
            ];
        }

        if (!empty($errors)) {
            $update = [
                'errors' => $errors,
                'last_error' => implode(' | ', array_values($errors)),
                'last_delay' => $wait_time,
                'max_delay' => $max_delay,
            ];

            $remaining_labels = [];
            foreach ($remaining as $destination_id) {
                $remaining_labels[] = $destination_names[$destination_id] ?? $destination_id;
            }

            if ($current_attempt >= self::MAX_ATTEMPTS) {
                $update['status'] = 'failed';
                $update['next_attempt_at'] = 0;
                $update['failed_at'] = $now;
                $update['delay_alerted'] = true;

                $incremental->update_remote_purge_entry($file, $update);
                $current_entry = $this->find_queue_entry($incremental, $file);
                if ($current_entry === null) {
                    $current_entry = array_merge($entry, $update, [
                        'file' => $file,
                        'destinations' => $remaining,
                        'attempts' => $current_attempt,
                    ]);
                }

                $summary = sprintf(
                    __('Purge distante abandonnée pour %1$s (%2$s).', 'backup-jlg'),
                    $file,
                    implode(', ', $remaining_labels)
                );
                $this->log_history('failure', $summary);

                foreach ($errors as $destination_id => $error_message) {
                    $label = $destination_names[$destination_id] ?? $destination_id;
                    $history_message = sprintf(
                        __('Purge distante échouée pour %1$s via %2$s : %3$s', 'backup-jlg'),
                        $file,
                        $label,
                        $error_message
                    );
                    $this->log_history('failure', $history_message);
                }

                do_action('bjlg_remote_purge_permanent_failure', $file, $current_entry, $errors);

                return [
                    'processed' => true,
                    'outcome' => 'failed',
                    'file' => $file,
                    'registered_at' => $registered_at,
                    'timestamp' => $now,
                    'attempts' => $current_attempt,
                    'errors' => $errors,
                    'max_delay' => $max_delay,
                    'quota_samples' => $quota_samples,
                ];
            } else {
                $delay = $this->compute_backoff($current_attempt);
                $update['status'] = 'retry';
                $update['next_attempt_at'] = $now + $delay;
                $update['failed_at'] = 0;

                $incremental->update_remote_purge_entry($file, $update);

                $history_message = sprintf(
                    __('Purge distante replanifiée pour %1$s (%2$s) dans %3$s.', 'backup-jlg'),
                    $file,
                    implode(', ', $remaining_labels),
                    human_time_diff($now, $now + $delay)
                );
                $this->log_history('warning', $history_message);

                if (!$was_alerted && $this->should_raise_delay_alert($current_attempt, $max_delay)) {
                    $incremental->update_remote_purge_entry($file, ['delay_alerted' => true]);
                    $alert_message = sprintf(
                        __('Retard critique pour la purge distante %1$s (%2$s tentatives, attente max %3$s).', 'backup-jlg'),
                        $file,
                        number_format_i18n($current_attempt),
                        $this->format_delay_label($max_delay)
                    );
                    $this->log_history('warning', $alert_message);

                    $alert_context = array_merge(
                        $entry,
                        $update,
                        [
                            'file' => $file,
                            'destinations' => $remaining,
                            'attempts' => $current_attempt,
                            'last_delay' => $wait_time,
                            'max_delay' => $max_delay,
                            'was_alerted' => false,
                        ]
                    );

                    do_action('bjlg_remote_purge_delayed', $file, $alert_context);
                }
            }
        }

        return [
            'processed' => true,
            'outcome' => 'retry',
            'file' => $file,
            'registered_at' => $registered_at,
            'timestamp' => $now,
            'attempts' => $current_attempt,
            'remaining_destinations' => $remaining,
            'max_delay' => $max_delay,
            'quota_samples' => $quota_samples,
        ];
    }

    private function is_locked() {
        return (bool) get_transient(self::LOCK_TRANSIENT);
    }

    private function lock() {
        set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_DURATION);
    }

    private function unlock() {
        delete_transient(self::LOCK_TRANSIENT);
    }

    private function log_history($status, $message) {
        if (!is_string($message) || trim($message) === '') {
            return;
        }

        if (class_exists(BJLG_History::class)) {
            BJLG_History::log('remote_purge', $status, $message);
        }
    }

    private function should_raise_delay_alert(int $attempts, int $max_delay): bool {
        if ($attempts >= self::MAX_ATTEMPTS) {
            return true;
        }

        if ($attempts >= self::ALERT_ATTEMPT_THRESHOLD) {
            return true;
        }

        return $max_delay >= self::DELAY_ALERT_THRESHOLD;
    }

    /**
     * Agrège les métriques SLA exploitées par le tableau de bord d’observabilité.
     *
     * Les délais moyens, les destinations impactées et les dernières purges alimentent
     * les alertes et le module multi-canal. Prochaine étape : brancher les prédictions
     * de saturation et déclencher des actions correctives automatisées.
     */
    private function update_metrics(array $queue, array $results, int $now) {
        $pending_total = 0;
        $pending_sum_age = 0;
        $pending_oldest = 0;
        $pending_over_threshold = 0;
        $pending_by_destination = [];

        foreach ($queue as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $status = isset($entry['status']) ? (string) $entry['status'] : 'pending';
            if (!in_array($status, ['pending', 'retry', 'processing'], true)) {
                continue;
            }

            $registered = isset($entry['registered_at']) ? (int) $entry['registered_at'] : 0;
            if ($registered <= 0) {
                $registered = $now;
            }

            $age = max(0, $now - $registered);
            $pending_total++;
            $pending_sum_age += $age;
            $pending_oldest = max($pending_oldest, $age);

            if ($age >= self::DELAY_ALERT_THRESHOLD) {
                $pending_over_threshold++;
            }

            if (!empty($entry['destinations']) && is_array($entry['destinations'])) {
                foreach ($entry['destinations'] as $destination_id) {
                    if (!is_scalar($destination_id)) {
                        continue;
                    }

                    $key = (string) $destination_id;
                    if (!isset($pending_by_destination[$key])) {
                        $pending_by_destination[$key] = 0;
                    }
                    $pending_by_destination[$key]++;
                }
            }
        }

        $average_age = $pending_total > 0 ? $pending_sum_age / $pending_total : 0;

        if (function_exists('bjlg_get_option')) {
            $existing = \bjlg_get_option('bjlg_remote_purge_sla_metrics', []);
        } elseif (function_exists('get_option')) {
            $existing = get_option('bjlg_remote_purge_sla_metrics', []);
        } else {
            $existing = [];
        }
        $existing = is_array($existing) ? $existing : [];

        $durations_existing = isset($existing['durations']) && is_array($existing['durations']) ? $existing['durations'] : [];
        $destination_stats = isset($durations_existing['destinations']) && is_array($durations_existing['destinations'])
            ? $durations_existing['destinations']
            : [];

        $existing_quota_metrics = isset($existing['quotas']) && is_array($existing['quotas']) ? $existing['quotas'] : [];
        $quota_samples = [];

        $throughput = isset($existing['throughput']) && is_array($existing['throughput']) ? $existing['throughput'] : [];
        $completion_samples = isset($throughput['samples']) ? (int) $throughput['samples'] : 0;
        $avg_completion = isset($throughput['average_completion_seconds']) ? (float) $throughput['average_completion_seconds'] : 0.0;
        $avg_attempts = isset($throughput['average_attempts']) ? (float) $throughput['average_attempts'] : 0.0;
        $last_completed_at = isset($throughput['last_completed_at']) ? (int) $throughput['last_completed_at'] : 0;
        $last_completion_seconds = isset($throughput['last_completion_seconds']) ? (float) $throughput['last_completion_seconds'] : 0.0;

        $failures = isset($existing['failures']) && is_array($existing['failures']) ? $existing['failures'] : [];
        $failures_total = isset($failures['total']) ? (int) $failures['total'] : 0;
        $last_failure_at = isset($failures['last_failure_at']) ? (int) $failures['last_failure_at'] : 0;
        $last_failure_message = isset($failures['last_message']) ? (string) $failures['last_message'] : '';

        $existing_forecast = isset($existing['forecast']) && is_array($existing['forecast']) ? $existing['forecast'] : [];
        $forecast_destinations = isset($existing_forecast['destinations']) && is_array($existing_forecast['destinations'])
            ? $existing_forecast['destinations']
            : [];

        foreach ($results as $result) {
            if (!is_array($result) || empty($result['processed'])) {
                continue;
            }

            $outcome = isset($result['outcome']) ? (string) $result['outcome'] : '';
            $timestamp = isset($result['timestamp']) ? (int) $result['timestamp'] : $now;
            $attempts = isset($result['attempts']) ? (int) $result['attempts'] : 1;
            $duration = isset($result['duration']) ? (int) $result['duration'] : 0;

            if ($duration <= 0 && isset($result['registered_at'])) {
                $duration = max(0, $timestamp - (int) $result['registered_at']);
            }

            if (!empty($result['destinations']) && is_array($result['destinations'])) {
                foreach ($result['destinations'] as $destination_id) {
                    if (!is_scalar($destination_id)) {
                        continue;
                    }

                    $key = (string) $destination_id;
                    $current_stats = isset($destination_stats[$key]) && is_array($destination_stats[$key])
                        ? $destination_stats[$key]
                        : [];

                    $destination_stats[$key] = $this->update_destination_duration_stats(
                        $current_stats,
                        $duration,
                        $attempts,
                        $outcome,
                        $timestamp
                    );
                }
            }

            if (!empty($result['quota_samples']) && is_array($result['quota_samples'])) {
                foreach ($result['quota_samples'] as $destination_id => $sample) {
                    if (!is_scalar($destination_id) || !is_array($sample)) {
                        continue;
                    }

                    $key = (string) $destination_id;
                    if (!isset($quota_samples[$key])) {
                        $quota_samples[$key] = [];
                    }

                    if (!isset($sample['timestamp'])) {
                        $sample['timestamp'] = $timestamp;
                    }
                    $sample['destination_id'] = $key;
                    $quota_samples[$key][] = $sample;
                }
            }

            if ($outcome === 'completed') {
                $completion_samples++;
                if ($completion_samples > 0) {
                    $avg_completion = $avg_completion + (($duration - $avg_completion) / $completion_samples);
                    $avg_attempts = $avg_attempts + (($attempts - $avg_attempts) / $completion_samples);
                }

                $last_completed_at = $timestamp;
                $last_completion_seconds = $duration;

                if (!empty($result['destinations']) && is_array($result['destinations'])) {
                    foreach ($result['destinations'] as $destination_id) {
                        if (!is_scalar($destination_id)) {
                            continue;
                        }

                        $key = (string) $destination_id;
                        if (!isset($forecast_destinations[$key]) || !is_array($forecast_destinations[$key])) {
                            $forecast_destinations[$key] = [
                                'history' => [],
                                'total_completed' => 0,
                            ];
                        }

                        $history = isset($forecast_destinations[$key]['history']) && is_array($forecast_destinations[$key]['history'])
                            ? $forecast_destinations[$key]['history']
                            : [];
                        $total_completed = isset($forecast_destinations[$key]['total_completed'])
                            ? (int) $forecast_destinations[$key]['total_completed']
                            : 0;

                        $history_timestamp = $timestamp;
                        if (!empty($history)) {
                            $last_point = end($history);
                            $last_timestamp = isset($last_point['timestamp']) ? (int) $last_point['timestamp'] : 0;
                            if ($history_timestamp <= $last_timestamp) {
                                $history_timestamp = $last_timestamp + 1;
                            }
                        }

                        $total_completed++;
                        $history[] = [
                            'timestamp' => $history_timestamp,
                            'total' => $total_completed,
                        ];

                        if (count($history) > 20) {
                            $history = array_slice($history, -20);
                        }

                        $forecast_destinations[$key]['history'] = $history;
                        $forecast_destinations[$key]['total_completed'] = $total_completed;
                        $forecast_destinations[$key]['updated_at'] = $now;
                    }
                }
            }

            if ($outcome === 'failed') {
                $failures_total++;
                $last_failure_at = $timestamp;

                if (!empty($result['errors']) && is_array($result['errors'])) {
                    $messages = array_filter(array_map('trim', array_map('strval', array_values($result['errors']))));
                    if (!empty($messages)) {
                        $last_failure_message = implode(' | ', $messages);
                    }
                }
            }
        }

        $forecast = $this->build_forecast_metrics(
            $now,
            $pending_total,
            $pending_by_destination,
            $forecast_destinations
        );

        $durations_metrics = [
            'updated_at' => $now,
            'destinations' => $destination_stats,
        ];

        $quota_metrics = $this->build_quota_projections(
            $now,
            $existing_quota_metrics,
            $quota_samples
        );

        $quota_metrics = $this->maybe_trigger_proactive_alerts($quota_metrics, $now);

        $metrics = [
            'updated_at' => $now,
            'pending' => [
                'total' => $pending_total,
                'average_seconds' => $average_age,
                'oldest_seconds' => $pending_oldest,
                'over_threshold' => $pending_over_threshold,
                'destinations' => $pending_by_destination,
            ],
            'throughput' => [
                'average_completion_seconds' => $completion_samples > 0 ? $avg_completion : 0,
                'average_attempts' => $completion_samples > 0 ? $avg_attempts : 0,
                'samples' => $completion_samples,
                'last_completed_at' => $last_completed_at,
                'last_completion_seconds' => $last_completion_seconds,
            ],
            'failures' => [
                'total' => $failures_total,
                'last_failure_at' => $last_failure_at,
                'last_message' => $last_failure_message,
            ],
            'forecast' => $forecast,
            'durations' => $durations_metrics,
            'quotas' => $quota_metrics,
        ];

        $this->persist_metrics_audit($metrics);

        if (function_exists('update_option')) {
            \bjlg_update_option('bjlg_remote_purge_sla_metrics', $metrics);
        }
    }

    /**
     * @param array<string,int> $pending_by_destination
     * @param array<string,mixed> $forecast_destinations
     */
    private function build_forecast_metrics(int $now, int $pending_total, array $pending_by_destination, array $forecast_destinations): array {
        $destinations_output = [];
        $total_throughput = 0.0;

        foreach ($forecast_destinations as $destination_id => $data) {
            if (!is_array($data)) {
                continue;
            }

            $history = isset($data['history']) && is_array($data['history']) ? $data['history'] : [];
            $history = array_values(array_filter($history, static function ($point) {
                return isset($point['timestamp'], $point['total']);
            }));

            [$slope, $intercept] = $this->compute_regression($history);

            $pending = isset($pending_by_destination[$destination_id]) ? (int) $pending_by_destination[$destination_id] : 0;
            $seconds_per_item = null;
            $forecast_seconds = null;
            $projected_clearance = null;
            $forecast_label = '';
            $trend_direction = 'flat';

            if ($slope !== null) {
                if ($slope > 0) {
                    $seconds_per_item = 1 / $slope;
                    $total_throughput += $slope;

                    if ($pending > 0) {
                        $forecast_seconds = (int) ceil($pending / $slope);
                        $projected_clearance = $now + $forecast_seconds;
                        $forecast_label = sprintf(
                            __('File vidée dans %s', 'backup-jlg'),
                            $this->format_delay_label($forecast_seconds)
                        );
                    } else {
                        $forecast_label = __('File vidée', 'backup-jlg');
                    }

                    $trend_direction = 'positive';
                } elseif ($slope < 0) {
                    $trend_direction = 'negative';
                    $forecast_label = __('Débit négatif détecté', 'backup-jlg');
                }
            }

            if ($forecast_label === '' && $pending > 0) {
                $forecast_label = __('Projection indisponible (historique insuffisant)', 'backup-jlg');
            }

            $destinations_output[$destination_id] = [
                'history' => $history,
                'total_completed' => isset($data['total_completed']) ? (int) $data['total_completed'] : count($history),
                'slope' => $slope,
                'intercept' => $intercept,
                'pending' => $pending,
                'seconds_per_item' => $seconds_per_item,
                'forecast_seconds' => $forecast_seconds,
                'projected_clearance' => $projected_clearance,
                'forecast_label' => $forecast_label,
                'trend_direction' => $trend_direction,
                'samples' => count($history),
            ];
        }

        foreach ($pending_by_destination as $destination_id => $count) {
            if (isset($destinations_output[$destination_id])) {
                continue;
            }

            $destinations_output[$destination_id] = [
                'history' => [],
                'total_completed' => 0,
                'slope' => null,
                'intercept' => null,
                'pending' => (int) $count,
                'seconds_per_item' => null,
                'forecast_seconds' => null,
                'projected_clearance' => null,
                'forecast_label' => __('Projection indisponible (historique insuffisant)', 'backup-jlg'),
                'trend_direction' => 'flat',
                'samples' => 0,
            ];
        }

        $overall_forecast_seconds = null;
        $overall_projected = null;
        $overall_label = '';

        if ($pending_total > 0 && $total_throughput > 0) {
            $overall_forecast_seconds = (int) ceil($pending_total / $total_throughput);
            $overall_projected = $now + $overall_forecast_seconds;
            $overall_label = sprintf(
                __('Projection globale : file vidée dans %s', 'backup-jlg'),
                $this->format_delay_label($overall_forecast_seconds)
            );
        } elseif ($pending_total === 0) {
            $overall_label = __('Aucune entrée en attente.', 'backup-jlg');
        }

        return [
            'generated_at' => $now,
            'destinations' => $destinations_output,
            'overall' => [
                'pending' => $pending_total,
                'throughput_per_second' => $total_throughput,
                'forecast_seconds' => $overall_forecast_seconds,
                'projected_clearance' => $overall_projected,
                'forecast_label' => $overall_label,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $stats
     */
    private function update_destination_duration_stats(array $stats, int $duration, int $attempts, string $outcome, int $timestamp): array
    {
        $duration = max(0, $duration);
        $attempts = max(1, $attempts);

        $samples = isset($stats['samples']) ? (int) $stats['samples'] : 0;
        $samples++;

        $average_duration = isset($stats['average_duration_seconds']) ? (float) $stats['average_duration_seconds'] : 0.0;
        $average_duration = $average_duration + (($duration - $average_duration) / max(1, $samples));

        $average_attempts = isset($stats['average_attempts']) ? (float) $stats['average_attempts'] : 0.0;
        $average_attempts = $average_attempts + (($attempts - $average_attempts) / max(1, $samples));

        $stats['samples'] = $samples;
        $stats['average_duration_seconds'] = $average_duration;
        $stats['average_attempts'] = $average_attempts;
        $stats['last_duration_seconds'] = $duration;
        $stats['last_attempts'] = $attempts;
        $stats['last_outcome'] = $outcome;
        $stats['last_updated'] = $timestamp;
        $stats['completed'] = ($stats['completed'] ?? 0) + ($outcome === 'completed' ? 1 : 0);

        return $stats;
    }

    /**
     * @param string $destination_id
     * @param array<string,mixed> $result
     */
    private function extract_quota_sample(string $destination_id, array $result, int $timestamp): ?array
    {
        $used = $this->sanitize_bytes($result['used_bytes'] ?? null);
        $quota = $this->sanitize_bytes($result['quota_bytes'] ?? null);
        $free = $this->sanitize_bytes($result['free_bytes'] ?? null);

        $nested_keys = ['quota', 'usage', 'metrics', 'storage', 'quota_snapshot'];
        foreach ($nested_keys as $key) {
            if (!isset($result[$key]) || !is_array($result[$key])) {
                continue;
            }

            $payload = $result[$key];
            if ($used === null) {
                $used = $this->sanitize_bytes($payload['used_bytes'] ?? $payload['used'] ?? $payload['used_space'] ?? null);
            }
            if ($quota === null) {
                $quota = $this->sanitize_bytes($payload['quota_bytes'] ?? $payload['quota'] ?? $payload['total_bytes'] ?? $payload['capacity'] ?? null);
            }
            if ($free === null) {
                $free = $this->sanitize_bytes($payload['free_bytes'] ?? $payload['free'] ?? $payload['available_bytes'] ?? null);
            }
        }

        if ($quota !== null && $used !== null && $free === null) {
            $free = max(0, $quota - $used);
        } elseif ($quota !== null && $free !== null && $used === null) {
            $used = max(0, $quota - $free);
        }

        if ($used === null && $quota === null && $free === null) {
            return null;
        }

        $ratio = null;
        if ($quota !== null && $quota > 0 && $used !== null) {
            $ratio = max(0.0, min(1.0, $used / $quota));
        } elseif ($quota !== null && $quota > 0 && $free !== null) {
            $ratio = max(0.0, min(1.0, 1 - ($free / $quota)));
        }

        return [
            'destination_id' => $destination_id,
            'timestamp' => $timestamp,
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'free_bytes' => $free,
            'ratio' => $ratio,
        ];
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,array<int,array<string,mixed>>> $new_samples
     */
    private function build_quota_projections(int $now, array $existing, array $new_samples): array
    {
        $threshold_ratio = 0.85;
        if (class_exists(BJLG_Settings::class) && method_exists(BJLG_Settings::class, 'get_remote_storage_threshold')) {
            $threshold_ratio = (float) BJLG_Settings::get_remote_storage_threshold();
        }
        $threshold_ratio = max(0.1, min(1.0, $threshold_ratio));
        $threshold_percent = $threshold_ratio * 100;

        $lead_time = isset($existing['lead_time_seconds']) ? (int) $existing['lead_time_seconds'] : self::DEFAULT_FORECAST_LEAD_TIME;

        $destinations = isset($existing['destinations']) && is_array($existing['destinations'])
            ? $existing['destinations']
            : [];

        $destination_ids = array_unique(array_merge(array_keys($destinations), array_keys($new_samples)));

        foreach ($destination_ids as $destination_id) {
            $entry = isset($destinations[$destination_id]) && is_array($destinations[$destination_id])
                ? $destinations[$destination_id]
                : [];

            $history = isset($entry['history']) && is_array($entry['history']) ? $entry['history'] : [];

            if (!empty($new_samples[$destination_id])) {
                foreach ($new_samples[$destination_id] as $sample) {
                    if (!is_array($sample)) {
                        continue;
                    }

                    $sample_timestamp = isset($sample['timestamp']) ? (int) $sample['timestamp'] : $now;
                    $used = $this->sanitize_bytes($sample['used_bytes'] ?? null);
                    $quota = $this->sanitize_bytes($sample['quota_bytes'] ?? null);
                    $free = $this->sanitize_bytes($sample['free_bytes'] ?? null);
                    $ratio = null;

                    if (isset($sample['ratio']) && is_numeric($sample['ratio'])) {
                        $ratio = max(0.0, min(1.0, (float) $sample['ratio']));
                    }

                    if ($ratio === null && $quota !== null && $quota > 0 && $used !== null) {
                        $ratio = max(0.0, min(1.0, $used / $quota));
                    } elseif ($ratio === null && $quota !== null && $free !== null) {
                        $ratio = max(0.0, min(1.0, 1 - ($free / $quota)));
                        if ($used === null) {
                            $used = max(0, $quota - $free);
                        }
                    }

                    if ($quota !== null && $used !== null && $free === null) {
                        $free = max(0, $quota - $used);
                    }

                    $history[] = [
                        'timestamp' => $sample_timestamp,
                        'used_bytes' => $used,
                        'quota_bytes' => $quota,
                        'free_bytes' => $free,
                        'ratio' => $ratio,
                        'label' => $this->format_history_label($sample_timestamp),
                    ];
                }
            }

            $history = array_values(array_filter($history, static function ($point) {
                return is_array($point) && isset($point['timestamp']);
            }));

            usort($history, static function ($a, $b) {
                return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
            });

            if (count($history) > self::FORECAST_HISTORY_LIMIT) {
                $history = array_slice($history, -self::FORECAST_HISTORY_LIMIT);
            }

            $current = end($history);
            if ($current === false) {
                $current = null;
            }

            $current_ratio = isset($current['ratio']) && $current['ratio'] !== null ? (float) $current['ratio'] : null;
            $trend_direction = 'flat';
            $trend_slope = null;
            $projected_at = null;
            $projected_label = '';
            $lead_seconds = null;
            $risk_level = 'normal';
            $threshold_bytes = null;

            $ratio_points = [];
            foreach ($history as $point) {
                if (!is_array($point) || !isset($point['ratio']) || $point['ratio'] === null) {
                    continue;
                }

                $ratio_points[] = [
                    'timestamp' => isset($point['timestamp']) ? (float) $point['timestamp'] : 0.0,
                    'value' => (float) $point['ratio'],
                ];
            }

            if (count($ratio_points) >= 2) {
                [$trend_slope, $trend_intercept] = $this->compute_regression_generic($ratio_points);
                if ($trend_slope !== null) {
                    if ($trend_slope > 0) {
                        $trend_direction = 'up';
                    } elseif ($trend_slope < 0) {
                        $trend_direction = 'down';
                    }
                }
            }

            if ($current !== null && isset($current['quota_bytes'])) {
                $quota_bytes = $this->sanitize_bytes($current['quota_bytes']);
                if ($quota_bytes !== null) {
                    $threshold_bytes = (int) floor($quota_bytes * $threshold_ratio);
                }
            }

            if ($current_ratio !== null) {
                if ($current_ratio >= $threshold_ratio) {
                    $projected_at = $now;
                    $lead_seconds = 0;
                    $projected_label = __('Seuil dépassé', 'backup-jlg');
                    $risk_level = 'critical';
                } elseif ($trend_slope !== null && $trend_slope > 0) {
                    $seconds_to_threshold = ($threshold_ratio - $current_ratio) / $trend_slope;
                    if (is_finite($seconds_to_threshold) && $seconds_to_threshold >= 0) {
                        $lead_seconds = (int) round($seconds_to_threshold);
                        $projected_at = $now + $lead_seconds;
                        $projected_label = sprintf(
                            __('Seuil atteint estimé dans %s', 'backup-jlg'),
                            $this->format_delay_label($lead_seconds)
                        );

                        if ($lead_seconds <= DAY_IN_SECONDS) {
                            $risk_level = 'critical';
                        } elseif ($lead_seconds <= 3 * DAY_IN_SECONDS) {
                            $risk_level = 'warning';
                        } else {
                            $risk_level = 'watch';
                        }
                    }
                } elseif ($trend_slope !== null && $trend_slope < 0) {
                    $projected_label = __('Consommation en baisse', 'backup-jlg');
                    $risk_level = 'success';
                } else {
                    $projected_label = __('Consommation stable', 'backup-jlg');
                }
            }

            if ($projected_label === '') {
                $projected_label = __('Projection indisponible (historique insuffisant)', 'backup-jlg');
            }

            $destinations[$destination_id] = [
                'history' => $history,
                'current_ratio' => $current_ratio,
                'trend_slope' => $trend_slope,
                'trend_direction' => $trend_direction,
                'projected_saturation' => $projected_at,
                'projected_label' => $projected_label,
                'lead_time_seconds' => $lead_seconds,
                'risk_level' => $risk_level,
                'threshold_percent' => $threshold_percent,
                'threshold_ratio' => $threshold_ratio,
                'threshold_bytes' => $threshold_bytes,
                'last_sample' => $current,
                'samples' => count($history),
                'last_alerted_at' => isset($entry['last_alerted_at']) ? (int) $entry['last_alerted_at'] : 0,
            ];
        }

        return [
            'generated_at' => $now,
            'destinations' => $destinations,
            'threshold_percent' => $threshold_percent,
            'threshold_ratio' => $threshold_ratio,
            'lead_time_seconds' => $lead_time,
        ];
    }

    private function format_history_label(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        if (function_exists('wp_date')) {
            $time_format = function_exists('get_option') ? get_option('time_format', 'H:i') : 'H:i';
            return wp_date($time_format, $timestamp);
        }

        if (function_exists('date_i18n')) {
            return date_i18n('H:i', $timestamp);
        }

        return date('H:i', $timestamp);
    }

    private function sanitize_bytes($value): ?int
    {
        if (is_numeric($value)) {
            $numeric = (float) $value;
            if (is_finite($numeric) && $numeric >= 0) {
                return (int) round($numeric);
            }
        }

        return null;
    }

    private function persist_metrics_audit(array $metrics): void
    {
        if (!class_exists(BJLG_History::class)) {
            return;
        }

        $quotas = isset($metrics['quotas']) && is_array($metrics['quotas']) ? $metrics['quotas'] : [];
        $destinations = isset($quotas['destinations']) && is_array($quotas['destinations']) ? $quotas['destinations'] : [];

        if (empty($destinations)) {
            return;
        }

        $at_risk = [];
        foreach ($destinations as $destination_id => $data) {
            if (!is_array($data)) {
                continue;
            }

            $risk_level = isset($data['risk_level']) ? (string) $data['risk_level'] : 'normal';
            if (!in_array($risk_level, ['warning', 'critical'], true)) {
                continue;
            }

            $at_risk[] = [
                'destination' => $destination_id,
                'risk' => $risk_level,
                'current_ratio' => isset($data['current_ratio']) ? (float) $data['current_ratio'] : null,
                'lead_seconds' => isset($data['lead_time_seconds']) ? (int) $data['lead_time_seconds'] : null,
                'projected_at' => isset($data['projected_saturation']) ? (int) $data['projected_saturation'] : null,
            ];
        }

        if (empty($at_risk)) {
            return;
        }

        BJLG_History::log(
            'remote_storage_forecast',
            'info',
            __('Projection de saturation distante mise à jour.', 'backup-jlg'),
            null,
            null,
            [
                'overall_pending' => $metrics['pending']['total'] ?? 0,
                'overall_forecast_seconds' => $metrics['forecast']['overall']['forecast_seconds'] ?? null,
                'destinations' => $at_risk,
            ]
        );
    }

    private function maybe_trigger_proactive_alerts(array $quotas, int $now): array
    {
        if (empty($quotas['destinations']) || !is_array($quotas['destinations'])) {
            return $quotas;
        }

        $lead_time = (int) apply_filters('bjlg_remote_purge_forecast_lead_time', self::DEFAULT_FORECAST_LEAD_TIME, $quotas);
        if ($lead_time < 0) {
            $lead_time = self::DEFAULT_FORECAST_LEAD_TIME;
        }

        foreach ($quotas['destinations'] as $destination_id => &$data) {
            if (!is_array($data)) {
                continue;
            }

            $projected_at = isset($data['projected_saturation']) ? (int) $data['projected_saturation'] : null;
            $lead_seconds = isset($data['lead_time_seconds']) ? (int) $data['lead_time_seconds'] : null;
            $current_ratio = isset($data['current_ratio']) ? (float) $data['current_ratio'] : null;
            $threshold_ratio = isset($data['threshold_ratio']) ? (float) $data['threshold_ratio'] : null;

            if ($projected_at === null && $current_ratio !== null && $threshold_ratio !== null && $current_ratio >= $threshold_ratio) {
                $projected_at = $now;
                $lead_seconds = 0;
                $data['projected_saturation'] = $projected_at;
                $data['lead_time_seconds'] = 0;
            }

            if ($projected_at === null) {
                continue;
            }

            if ($lead_seconds === null) {
                $lead_seconds = max(0, $projected_at - $now);
                $data['lead_time_seconds'] = $lead_seconds;
            }

            if ($lead_seconds > $lead_time) {
                continue;
            }

            $last_alerted_at = isset($data['last_alerted_at']) ? (int) $data['last_alerted_at'] : 0;
            if ($last_alerted_at > 0 && ($now - $last_alerted_at) < HOUR_IN_SECONDS) {
                continue;
            }

            $history = isset($data['history']) && is_array($data['history']) ? array_slice($data['history'], -5) : [];

            $context = [
                'destination_id' => $destination_id,
                'projected_at' => $projected_at,
                'lead_seconds' => $lead_seconds,
                'risk_level' => isset($data['risk_level']) ? (string) $data['risk_level'] : 'warning',
                'current_ratio' => $current_ratio,
                'threshold_percent' => isset($data['threshold_percent']) ? (float) $data['threshold_percent'] : null,
                'history' => $history,
                'used_bytes' => $data['last_sample']['used_bytes'] ?? null,
                'quota_bytes' => $data['last_sample']['quota_bytes'] ?? null,
                'free_bytes' => $data['last_sample']['free_bytes'] ?? null,
                'projected_label' => $data['projected_label'] ?? '',
            ];

            do_action('bjlg_remote_storage_forecast_warning', $destination_id, $context);
            $data['last_alerted_at'] = $now;
        }
        unset($data);

        $quotas['lead_time_seconds'] = $lead_time;

        return $quotas;
    }

    /**
     * @param array<int,array<string,float>> $points
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function compute_regression_generic(array $points): array
    {
        $count = count($points);
        if ($count < 2) {
            return [null, null];
        }

        $sum_x = 0.0;
        $sum_y = 0.0;
        $sum_xy = 0.0;
        $sum_x2 = 0.0;

        foreach ($points as $point) {
            $x = isset($point['timestamp']) ? (float) $point['timestamp'] : 0.0;
            $y = isset($point['value']) ? (float) $point['value'] : 0.0;
            $sum_x += $x;
            $sum_y += $y;
            $sum_xy += ($x * $y);
            $sum_x2 += ($x * $x);
        }

        $denominator = ($count * $sum_x2) - ($sum_x ** 2);
        if (abs($denominator) < 0.000001) {
            return [null, null];
        }

        $slope = (($count * $sum_xy) - ($sum_x * $sum_y)) / $denominator;
        $intercept = ($sum_y - ($slope * $sum_x)) / $count;

        return [$slope, $intercept];
    }

    /**
     * @param array<int,array<string,int>> $points
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function compute_regression(array $points): array {
        $count = count($points);
        if ($count < 2) {
            return [null, null];
        }

        $sum_x = 0.0;
        $sum_y = 0.0;
        $sum_xy = 0.0;
        $sum_x2 = 0.0;

        foreach ($points as $point) {
            $x = isset($point['timestamp']) ? (float) $point['timestamp'] : 0.0;
            $y = isset($point['total']) ? (float) $point['total'] : 0.0;
            $sum_x += $x;
            $sum_y += $y;
            $sum_xy += ($x * $y);
            $sum_x2 += ($x * $x);
        }

        $denominator = ($count * $sum_x2) - ($sum_x ** 2);
        if (abs($denominator) < 0.000001) {
            return [null, null];
        }

        $slope = (($count * $sum_xy) - ($sum_x * $sum_y)) / $denominator;
        $intercept = ($sum_y - ($slope * $sum_x)) / $count;

        return [$slope, $intercept];
    }

    private function format_delay_label(int $seconds): string {
        $seconds = max(0, $seconds);

        if ($seconds < MINUTE_IN_SECONDS) {
            if ($seconds <= 1) {
                return __('1 seconde', 'backup-jlg');
            }

            return sprintf(
                __('%s secondes', 'backup-jlg'),
                number_format_i18n($seconds)
            );
        }

        $now = time();
        $reference = $now - $seconds;
        if ($reference < 0) {
            $reference = 0;
        }

        $relative = human_time_diff($reference, $now);
        if (!is_string($relative) || $relative === '') {
            $minutes = max(1, round($seconds / MINUTE_IN_SECONDS));

            if ($minutes <= 1) {
                return __('1 minute', 'backup-jlg');
            }

            return sprintf(
                __('%s minutes', 'backup-jlg'),
                number_format_i18n($minutes)
            );
        }

        return $relative;
    }

    /**
     * Returns true when the queue entry should be processed now.
     *
     * @param array<string,mixed> $entry
     */
    private function should_process_entry(array $entry, $now) {
        $status = isset($entry['status']) ? (string) $entry['status'] : 'pending';
        if (!in_array($status, ['pending', 'retry'], true)) {
            return false;
        }

        $next_attempt = isset($entry['next_attempt_at']) ? (int) $entry['next_attempt_at'] : 0;

        return $next_attempt <= $now;
    }

    private function compute_backoff($attempt) {
        $attempt = max(1, (int) $attempt);
        $delay = self::BASE_BACKOFF * (2 ** ($attempt - 1));

        return (int) min($delay, self::MAX_BACKOFF);
    }

    /**
     * @param array<int,array<string,mixed>> $queue
     */
    private function get_next_delay(array $queue) {
        if (empty($queue)) {
            return null;
        }

        $now = time();
        $next = null;

        foreach ($queue as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $status = isset($entry['status']) ? (string) $entry['status'] : 'pending';
            if (!in_array($status, ['pending', 'retry'], true)) {
                continue;
            }

            $candidate = isset($entry['next_attempt_at']) ? (int) $entry['next_attempt_at'] : 0;
            if ($candidate <= $now) {
                return 30;
            }

            $next = $this->min_time($next, $candidate);
        }

        if ($next === null) {
            return MINUTE_IN_SECONDS;
        }

        return max(30, $next - $now);
    }

    private function min_time($current, $candidate) {
        if ($candidate <= 0) {
            return $current;
        }

        if ($current === null) {
            return $candidate;
        }

        return min($current, $candidate);
    }

    private function find_queue_entry(BJLG_Incremental $incremental, $file) {
        $file = basename((string) $file);
        if ($file === '') {
            return null;
        }

        $queue = $incremental->get_remote_purge_queue();
        foreach ($queue as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (($entry['file'] ?? '') === $file) {
                return $entry;
            }
        }

        return null;
    }
}
