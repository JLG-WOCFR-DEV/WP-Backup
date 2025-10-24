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
    private const METRICS_OPTION = 'bjlg_remote_purge_sla_metrics';
    private const SATURATION_WARNING_THRESHOLD = 5 * MINUTE_IN_SECONDS;

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
     */
    private function update_metrics(array $queue, array $results, int $now) {
        $pending_total = 0;
        $pending_sum_age = 0;
        $pending_oldest = 0;
        $pending_over_threshold = 0;
        $pending_by_destination = [];
        $pending_destination_oldest = [];

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

                    if (!isset($pending_destination_oldest[$key])) {
                        $pending_destination_oldest[$key] = 0;
                    }
                    $pending_destination_oldest[$key] = max($pending_destination_oldest[$key], $age);
                }
            }
        }

        $average_age = $pending_total > 0 ? $pending_sum_age / $pending_total : 0.0;

        $existing = $this->get_metrics_snapshot();

        $throughput = isset($existing['throughput']) && is_array($existing['throughput']) ? $existing['throughput'] : [];
        $completion_samples = isset($throughput['samples']) ? (int) $throughput['samples'] : 0;
        $avg_completion = isset($throughput['average_completion_seconds']) ? (float) $throughput['average_completion_seconds'] : 0.0;
        $avg_attempts = isset($throughput['average_attempts']) ? (float) $throughput['average_attempts'] : 0.0;
        $last_completed_at = isset($throughput['last_completed_at']) ? (int) $throughput['last_completed_at'] : 0;
        $last_completion_seconds = isset($throughput['last_completion_seconds']) ? (float) $throughput['last_completion_seconds'] : 0.0;
        $total_completed = isset($throughput['total_completed']) ? (int) $throughput['total_completed'] : 0;

        $failures = isset($existing['failures']) && is_array($existing['failures']) ? $existing['failures'] : [];
        $failures_total = isset($failures['total']) ? (int) $failures['total'] : 0;
        $last_failure_at = isset($failures['last_failure_at']) ? (int) $failures['last_failure_at'] : 0;
        $last_failure_message = isset($failures['last_message']) ? (string) $failures['last_message'] : '';

        $existing_forecast = isset($existing['forecast']) && is_array($existing['forecast']) ? $existing['forecast'] : [];
        $forecast_destinations = isset($existing_forecast['destinations']) && is_array($existing_forecast['destinations'])
            ? $existing_forecast['destinations']
            : [];

        $duration_stats = isset($existing['durations']) && is_array($existing['durations']) ? $existing['durations'] : [];
        $duration_samples = isset($duration_stats['samples']) ? (int) $duration_stats['samples'] : 0;
        $avg_duration = isset($duration_stats['average_seconds']) ? (float) $duration_stats['average_seconds'] : 0.0;
        $duration_peak = isset($duration_stats['peak_seconds']) ? (float) $duration_stats['peak_seconds'] : 0.0;
        $last_duration_seconds = isset($duration_stats['last_seconds']) ? (float) $duration_stats['last_seconds'] : 0.0;

        $quota_snapshot = isset($existing['quotas']) && is_array($existing['quotas']) ? $existing['quotas'] : [];
        $quota_destinations = isset($quota_snapshot['destinations']) && is_array($quota_snapshot['destinations'])
            ? $quota_snapshot['destinations']
            : [];

        foreach ($results as $result) {
            if (!is_array($result) || empty($result['processed'])) {
                continue;
            }

            $outcome = isset($result['outcome']) ? (string) $result['outcome'] : '';

            if ($outcome === 'completed') {
                $duration = isset($result['duration']) ? (int) $result['duration'] : 0;
                if ($duration <= 0 && isset($result['registered_at'])) {
                    $duration = max(0, $now - (int) $result['registered_at']);
                }

                $attempts = isset($result['attempts']) ? (int) $result['attempts'] : 1;
                $completion_samples++;
                if ($completion_samples > 0) {
                    $avg_completion = $avg_completion + (($duration - $avg_completion) / $completion_samples);
                    $avg_attempts = $avg_attempts + (($attempts - $avg_attempts) / $completion_samples);
                }

                $duration_samples++;
                if ($duration_samples > 0) {
                    $avg_duration = $avg_duration + (($duration - $avg_duration) / $duration_samples);
                }
                $duration_peak = max($duration_peak, $duration);
                $last_duration_seconds = $duration;

                $last_completed_at = isset($result['timestamp']) ? (int) $result['timestamp'] : $now;
                $last_completion_seconds = $duration;
                $total_completed++;

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
                        $total_destination_completed = isset($forecast_destinations[$key]['total_completed'])
                            ? (int) $forecast_destinations[$key]['total_completed']
                            : 0;

                        $timestamp = isset($result['timestamp']) ? (int) $result['timestamp'] : $now;
                        if (!empty($history)) {
                            $last_point = end($history);
                            $last_timestamp = isset($last_point['timestamp']) ? (int) $last_point['timestamp'] : 0;
                            if ($timestamp <= $last_timestamp) {
                                $timestamp = $last_timestamp + 1;
                            }
                        }

                        $total_destination_completed++;
                        $history[] = [
                            'timestamp' => $timestamp,
                            'total' => $total_destination_completed,
                        ];

                        if (count($history) > 20) {
                            $history = array_slice($history, -20);
                        }

                        $forecast_destinations[$key]['history'] = $history;
                        $forecast_destinations[$key]['total_completed'] = $total_destination_completed;
                        $forecast_destinations[$key]['updated_at'] = $now;
                    }
                }
            }

            if ($outcome === 'failed') {
                $failures_total++;
                $last_failure_at = isset($result['timestamp']) ? (int) $result['timestamp'] : $now;

                if (!empty($result['errors']) && is_array($result['errors'])) {
                    $messages = array_filter(array_map('trim', array_map('strval', array_values($result['errors']))));
                    if (!empty($messages)) {
                        $last_failure_message = implode(' | ', $messages);
                    }
                }
            }

            if (!empty($result['quota_samples']) && is_array($result['quota_samples'])) {
                foreach ($result['quota_samples'] as $destination_id => $sample) {
                    if (!is_scalar($destination_id) || !is_array($sample)) {
                        continue;
                    }

                    $key = (string) $destination_id;
                    if (!isset($quota_destinations[$key]) || !is_array($quota_destinations[$key])) {
                        $quota_destinations[$key] = [
                            'history' => [],
                        ];
                    }

                    $history = isset($quota_destinations[$key]['history']) && is_array($quota_destinations[$key]['history'])
                        ? $quota_destinations[$key]['history']
                        : [];
                    $history[] = $sample;
                    if (count($history) > 20) {
                        $history = array_slice($history, -20);
                    }

                    $quota_destinations[$key]['history'] = $history;
                    $quota_destinations[$key]['last_sample'] = $sample;
                    $quota_destinations[$key]['ratio'] = isset($sample['ratio']) ? (float) $sample['ratio'] : null;
                    $quota_destinations[$key]['updated_at'] = isset($sample['captured_at']) ? (int) $sample['captured_at'] : $now;
                }
            }
        }

        $forecast = $this->build_forecast_metrics(
            $now,
            $pending_total,
            $pending_by_destination,
            $forecast_destinations
        );

        $existing_backlog = isset($existing['backlog']) && is_array($existing['backlog']) ? $existing['backlog'] : [];
        $backlog_history = isset($existing_backlog['history']) && is_array($existing_backlog['history'])
            ? $existing_backlog['history']
            : [];

        $backlog_history[] = [
            'timestamp' => $now,
            'pending' => $pending_total,
        ];

        if (count($backlog_history) > 40) {
            $backlog_history = array_slice($backlog_history, -40);
        }

        $backlog_points = [];
        foreach ($backlog_history as $point) {
            if (!is_array($point)) {
                continue;
            }

            $timestamp = isset($point['timestamp']) ? (int) $point['timestamp'] : 0;
            $pending_value = isset($point['pending']) ? (int) $point['pending'] : null;

            if ($timestamp <= 0 || $pending_value === null) {
                continue;
            }

            $backlog_points[] = [
                'timestamp' => $timestamp,
                'total' => $pending_value,
            ];
        }

        [$backlog_slope, $backlog_intercept] = $this->compute_regression($backlog_points);

        $time_to_threshold = $pending_total > 0 ? max(0, self::DELAY_ALERT_THRESHOLD - $pending_oldest) : null;
        $projected_breach = $time_to_threshold !== null ? $now + $time_to_threshold : null;
        $breach_imminent = $time_to_threshold !== null && $time_to_threshold <= self::SATURATION_WARNING_THRESHOLD;

        $saturation_destinations = [];
        foreach ($pending_by_destination as $destination_id => $count) {
            $destination_oldest = isset($pending_destination_oldest[$destination_id])
                ? (int) $pending_destination_oldest[$destination_id]
                : 0;
            $destination_time_to_threshold = $count > 0
                ? max(0, self::DELAY_ALERT_THRESHOLD - $destination_oldest)
                : null;

            $destination_projection = $destination_time_to_threshold !== null
                ? $now + $destination_time_to_threshold
                : null;

            $destination_breach = $destination_time_to_threshold !== null
                && $destination_time_to_threshold <= self::SATURATION_WARNING_THRESHOLD;

            $saturation_destinations[$destination_id] = [
                'pending' => (int) $count,
                'oldest_seconds' => $destination_oldest,
                'time_to_threshold' => $destination_time_to_threshold,
                'projected_breach_at' => $destination_projection,
                'breach_imminent' => $destination_breach,
            ];

            if (!$breach_imminent && $destination_breach) {
                $breach_imminent = true;
            }
        }

        $processed_total = $total_completed + $failures_total;
        $failure_rate = $processed_total > 0 ? $failures_total / $processed_total : 0.0;
        $success_rate = $processed_total > 0 ? $total_completed / $processed_total : 0.0;

        $quota_snapshot['destinations'] = $quota_destinations;
        $quota_snapshot['updated_at'] = $now;

        $metrics = [
            'version' => 2,
            'updated_at' => $now,
            'pending' => [
                'total' => $pending_total,
                'average_seconds' => $average_age,
                'oldest_seconds' => $pending_oldest,
                'over_threshold' => $pending_over_threshold,
                'destinations' => $pending_by_destination,
                'destination_oldest' => $pending_destination_oldest,
            ],
            'throughput' => [
                'average_completion_seconds' => $completion_samples > 0 ? $avg_completion : 0.0,
                'average_attempts' => $completion_samples > 0 ? $avg_attempts : 0.0,
                'samples' => $completion_samples,
                'last_completed_at' => $last_completed_at,
                'last_completion_seconds' => $last_completion_seconds,
                'total_completed' => $total_completed,
                'failure_rate' => $failure_rate,
                'success_rate' => $success_rate,
            ],
            'failures' => [
                'total' => $failures_total,
                'last_failure_at' => $last_failure_at,
                'last_message' => $last_failure_message,
            ],
            'durations' => [
                'average_seconds' => $duration_samples > 0 ? $avg_duration : 0.0,
                'samples' => $duration_samples,
                'peak_seconds' => $duration_peak,
                'last_seconds' => $last_duration_seconds,
            ],
            'forecast' => $forecast,
            'saturation' => [
                'threshold_seconds' => self::DELAY_ALERT_THRESHOLD,
                'oldest_seconds' => $pending_oldest,
                'time_to_threshold' => $time_to_threshold,
                'projected_breach_at' => $projected_breach,
                'breach_imminent' => $breach_imminent,
                'destinations' => $saturation_destinations,
            ],
            'backlog' => [
                'history' => $backlog_history,
                'slope' => $backlog_slope,
                'intercept' => $backlog_intercept,
                'samples' => count($backlog_history),
            ],
            'quotas' => $quota_snapshot,
        ];

        $this->save_metrics_snapshot($metrics);

        \do_action('bjlg_remote_purge_metrics_updated', $metrics, $queue, $results);
    }

    private function get_metrics_snapshot(): array {
        if (function_exists('bjlg_get_option')) {
            $snapshot = \bjlg_get_option(self::METRICS_OPTION, []);
        } elseif (function_exists('get_option')) {
            $snapshot = get_option(self::METRICS_OPTION, []);
        } else {
            $snapshot = [];
        }

        return is_array($snapshot) ? $snapshot : [];
    }

    private function save_metrics_snapshot(array $metrics): void {
        if (function_exists('bjlg_update_option')) {
            \bjlg_update_option(self::METRICS_OPTION, $metrics);

            return;
        }

        if (function_exists('update_option')) {
            update_option(self::METRICS_OPTION, $metrics);
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

    private function extract_quota_sample($destination_id, $result, int $now): ?array {
        if (!is_array($result)) {
            return null;
        }

        $candidates = [];

        if (isset($result['quota']) && is_array($result['quota'])) {
            $candidates[] = $result['quota'];
        }
        if (isset($result['usage']) && is_array($result['usage'])) {
            $candidates[] = $result['usage'];
        }
        if (isset($result['storage']) && is_array($result['storage'])) {
            $candidates[] = $result['storage'];
        }
        if (isset($result['metrics']) && is_array($result['metrics'])) {
            $candidates[] = $result['metrics'];
        }

        $usage = null;
        foreach ($candidates as $candidate) {
            if (!empty($candidate)) {
                $usage = $candidate;
                break;
            }
        }

        if (!is_array($usage)) {
            return null;
        }

        $used = $this->normalize_bytes_field($usage, ['used_bytes', 'used', 'usage_bytes']);
        $quota = $this->normalize_bytes_field($usage, ['quota_bytes', 'limit', 'capacity_bytes']);
        $free = $this->normalize_bytes_field($usage, ['free_bytes', 'available']);

        if ($quota !== null && $used !== null && $free === null) {
            $free = max(0, $quota - $used);
        }

        if ($quota === null && $used !== null && $free !== null) {
            $quota = max(0, $used + $free);
        }

        $ratio = null;
        if ($quota !== null && $quota > 0 && $used !== null) {
            $ratio = max(0.0, min(1.0, $used / $quota));
        }

        return [
            'destination' => (string) $destination_id,
            'captured_at' => $now,
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'free_bytes' => $free,
            'ratio' => $ratio,
        ];
    }

    private function normalize_bytes_field(array $data, array $keys): ?int {
        foreach ($keys as $key) {
            if (!isset($data[$key])) {
                continue;
            }

            $value = $data[$key];

            if (is_numeric($value)) {
                return (int) $value;
            }

            if (is_string($value) && $value !== '') {
                $normalized = preg_replace('/[^0-9\\.\-]/', '', $value);
                if ($normalized !== '' && is_numeric($normalized)) {
                    return (int) round((float) $normalized);
                }
            }
        }

        return null;
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
