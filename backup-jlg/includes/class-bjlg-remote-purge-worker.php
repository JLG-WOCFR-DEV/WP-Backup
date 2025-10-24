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
    private const METRICS_OPTION = 'bjlg_remote_purge_sla_metrics';
    private const DESTINATION_METRICS_OPTION = 'bjlg_remote_purge_destination_metrics';
    private const DESTINATION_METRICS_VERSION = 1;

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
        $storage_destinations_index = [];

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
        $total_completed = isset($throughput['total_completed']) ? (int) $throughput['total_completed'] : 0;

        $failures = isset($existing['failures']) && is_array($existing['failures']) ? $existing['failures'] : [];
        $failures_total = isset($failures['total']) ? (int) $failures['total'] : 0;
        $last_failure_at = isset($failures['last_failure_at']) ? (int) $failures['last_failure_at'] : 0;
        $last_failure_message = isset($failures['last_message']) ? (string) $failures['last_message'] : '';

        $existing_forecast = isset($existing['forecast']) && is_array($existing['forecast']) ? $existing['forecast'] : [];
        $forecast_destinations = isset($existing_forecast['destinations']) && is_array($existing_forecast['destinations'])
            ? $existing_forecast['destinations']
            : [];
        $existing_durations = isset($existing['durations']) && is_array($existing['durations']) ? $existing['durations'] : [];
        $duration_destinations = isset($existing_durations['destinations']) && is_array($existing_durations['destinations'])
            ? $existing_durations['destinations']
            : [];
        $overall_duration_store = isset($existing_durations['overall']) && is_array($existing_durations['overall'])
            ? $existing_durations['overall']
            : [];
        $overall_duration_samples = isset($overall_duration_store['samples']) && is_array($overall_duration_store['samples'])
            ? $overall_duration_store['samples']
            : [];
        $existing_quotas = isset($existing['quotas']) && is_array($existing['quotas']) ? $existing['quotas'] : [];
        $quota_destinations = isset($existing_quotas['destinations']) && is_array($existing_quotas['destinations'])
            ? $existing_quotas['destinations']
            : [];
        $existing_projections = isset($existing['projections']) && is_array($existing['projections']) ? $existing['projections'] : [];
        $projection_destinations = isset($existing_projections['destinations']) && is_array($existing_projections['destinations'])
            ? $existing_projections['destinations']
            : [];
        $alerts_to_dispatch = [];

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

                        $history_timestamp = $timestamp;
                        if (!empty($history)) {
                            $last_point = end($history);
                            $last_timestamp = isset($last_point['timestamp']) ? (int) $last_point['timestamp'] : 0;
                            if ($history_timestamp <= $last_timestamp) {
                                $history_timestamp = $last_timestamp + 1;
                            }
                        }

                        $total_destination_completed++;
                        $history[] = [
                            'timestamp' => $history_timestamp,
                            'total' => $total_completed,
                        ];

                        if (count($history) > 20) {
                            $history = array_slice($history, -20);
                        }

                        $forecast_destinations[$key]['history'] = $history;
                        $forecast_destinations[$key]['total_completed'] = $total_destination_completed;
                        $forecast_destinations[$key]['updated_at'] = $now;

                        $duration_destinations[$key] = $this->append_duration_sample(
                            isset($duration_destinations[$key]) && is_array($duration_destinations[$key])
                                ? $duration_destinations[$key]
                                : [],
                            $duration,
                            $now
                        );
                    }
                }

                if ($duration > 0) {
                    $overall_duration_samples[] = (int) $duration;
                }

                if (!empty($result['quota_samples']) && is_array($result['quota_samples'])) {
                    foreach ($result['quota_samples'] as $destination_id => $sample) {
                        if (!is_scalar($destination_id)) {
                            continue;
                        }

                        $normalized = $this->normalize_quota_sample($sample, $now);
                        if ($normalized === null) {
                            continue;
                        }

                        $key = (string) $destination_id;
                        $quota_destinations[$key] = $this->append_quota_sample(
                            isset($quota_destinations[$key]) && is_array($quota_destinations[$key])
                                ? $quota_destinations[$key]
                                : [],
                            $normalized
                        );
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

        if (class_exists(__NAMESPACE__ . '\\BJLG_Remote_Storage_Metrics')
            && method_exists(BJLG_Remote_Storage_Metrics::class, 'get_snapshot')
        ) {
            try {
                $storage_snapshot = BJLG_Remote_Storage_Metrics::get_snapshot();
            } catch (\Throwable $exception) {
                $storage_snapshot = [];
            }

            if (is_array($storage_snapshot) && !empty($storage_snapshot['destinations']) && is_array($storage_snapshot['destinations'])) {
                foreach ($storage_snapshot['destinations'] as $entry) {
                    if (!is_array($entry) || empty($entry['id'])) {
                        continue;
                    }

                    $key = (string) $entry['id'];
                    $timestamp = isset($entry['refreshed_at']) ? (int) $entry['refreshed_at'] : ($storage_snapshot['generated_at'] ?? $now);

                    if (!isset($storage_destinations_index[$key])) {
                        $storage_destinations_index[$key] = [
                            'id' => $key,
                            'name' => isset($entry['name']) ? (string) $entry['name'] : $key,
                            'connected' => isset($entry['connected']) ? (bool) $entry['connected'] : null,
                            'latency_ms' => isset($entry['latency_ms']) && is_numeric($entry['latency_ms'])
                                ? (float) $entry['latency_ms']
                                : null,
                            'refreshed_at' => $timestamp,
                            'stale' => !empty($storage_snapshot['stale']),
                            'ratio' => isset($entry['ratio']) && is_numeric($entry['ratio']) ? (float) $entry['ratio'] : null,
                            'used_bytes' => $this->sanitize_bytes($entry['used_bytes'] ?? null),
                            'quota_bytes' => $this->sanitize_bytes($entry['quota_bytes'] ?? null),
                            'free_bytes' => $this->sanitize_bytes($entry['free_bytes'] ?? null),
                        ];
                    } else {
                        $storage_destinations_index[$key]['refreshed_at'] = $timestamp;
                        if (isset($entry['name'])) {
                            $storage_destinations_index[$key]['name'] = (string) $entry['name'];
                        }
                        if (isset($entry['connected'])) {
                            $storage_destinations_index[$key]['connected'] = (bool) $entry['connected'];
                        }
                        if (isset($entry['latency_ms']) && is_numeric($entry['latency_ms'])) {
                            $storage_destinations_index[$key]['latency_ms'] = (float) $entry['latency_ms'];
                        }
                        if (isset($entry['ratio']) && is_numeric($entry['ratio'])) {
                            $storage_destinations_index[$key]['ratio'] = (float) $entry['ratio'];
                        }
                        $usedCandidate = $this->sanitize_bytes($entry['used_bytes'] ?? null);
                        if ($usedCandidate !== null) {
                            $storage_destinations_index[$key]['used_bytes'] = $usedCandidate;
                        }
                        $quotaCandidate = $this->sanitize_bytes($entry['quota_bytes'] ?? null);
                        if ($quotaCandidate !== null) {
                            $storage_destinations_index[$key]['quota_bytes'] = $quotaCandidate;
                        }
                        $freeCandidate = $this->sanitize_bytes($entry['free_bytes'] ?? null);
                        if ($freeCandidate !== null) {
                            $storage_destinations_index[$key]['free_bytes'] = $freeCandidate;
                        }
                        $storage_destinations_index[$key]['stale'] = !empty($storage_snapshot['stale']);
                    }

                    $sample = $this->normalize_quota_sample(
                        [
                            'timestamp' => $timestamp,
                            'used_bytes' => $entry['used_bytes'] ?? null,
                            'quota_bytes' => $entry['quota_bytes'] ?? null,
                            'free_bytes' => $entry['free_bytes'] ?? null,
                        ],
                        $now
                    );

                    if ($sample === null) {
                        continue;
                    }

                    $quota_destinations[$key] = $this->append_quota_sample(
                        isset($quota_destinations[$key]) && is_array($quota_destinations[$key])
                            ? $quota_destinations[$key]
                            : [],
                        $sample
                    );

                    $storage_destinations_index[$key]['ratio'] = $sample['ratio'];
                    $storage_destinations_index[$key]['used_bytes'] = $sample['used_bytes'];
                    $storage_destinations_index[$key]['quota_bytes'] = $sample['quota_bytes'];
                    $storage_destinations_index[$key]['free_bytes'] = $sample['free_bytes'];
                }
            }
        }

        $forecast = $this->build_forecast_metrics(
            $now,
            $pending_total,
            $pending_by_destination,
            $forecast_destinations
        );

        $overall_duration = [
            'average_seconds' => $duration_samples > 0 ? $avg_duration : ($overall_duration_store['average_seconds'] ?? 0.0),
            'samples' => $duration_samples,
            'max_seconds' => max(
                $duration_peak,
                isset($overall_duration_store['max_seconds']) ? (float) $overall_duration_store['max_seconds'] : 0.0
            ),
            'last_seconds' => $last_duration_seconds,
            'p95_seconds' => $overall_duration_store['p95_seconds'] ?? null,
        ];

        $durations_metrics = [
            'updated_at' => $now,
            'destinations' => $destination_stats,
            'overall' => $overall_duration,
        ];

        $quota_metrics = $this->build_quota_projections(
            $now,
            $existing_quota_metrics,
            $quota_samples
        );

        $destination_overview_entries = $this->build_destination_overview(
            $now,
            $pending_by_destination,
            $pending_destination_oldest,
            $destination_stats,
            isset($forecast['destinations']) && is_array($forecast['destinations']) ? $forecast['destinations'] : [],
            isset($quota_metrics['destinations']) && is_array($quota_metrics['destinations']) ? $quota_metrics['destinations'] : [],
            $storage_destinations_index
        );

        $destination_overview = [
            'updated_at' => $now,
            'destinations' => $destination_overview_entries,
        ];

        $quota_metrics = $this->maybe_trigger_proactive_alerts(
            $quota_metrics,
            $now,
            $destination_overview_entries
        );

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
            'durations' => $durations_metrics,
            'quotas' => $quota_metrics,
            'destinations_overview' => $destination_overview,
        ];

        $this->persist_metrics_audit($metrics);

        if (function_exists('update_option')) {
            update_option(self::METRICS_OPTION, $metrics);
            update_option(
                self::DESTINATION_METRICS_OPTION,
                [
                    'version' => self::DESTINATION_METRICS_VERSION,
                    'updated_at' => $now,
                    'destinations' => $destination_overview_entries,
                ]
            );
        }

        foreach ($alerts_to_dispatch as $alert) {
            if (!is_array($alert)) {
                continue;
            }

            $this->handle_capacity_alert($alert);
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

    private function append_duration_sample(array $store, int $duration, int $timestamp): array
    {
        $samples = [];
        if (isset($store['samples']) && is_array($store['samples'])) {
            foreach ($store['samples'] as $sample) {
                if (!is_numeric($sample)) {
                    continue;
                }

                $value = (int) $sample;
                if ($value < 0) {
                    continue;
                }

                $samples[] = $value;
            }
        }

        if ($duration >= 0) {
            $samples[] = (int) $duration;
        }

        if (count($samples) > self::MAX_DURATION_SAMPLES) {
            $samples = array_slice($samples, -self::MAX_DURATION_SAMPLES);
        }

        $store['samples'] = $samples;
        $store['last_seconds'] = !empty($samples) ? (int) end($samples) : ($store['last_seconds'] ?? null);
        $store['updated_at'] = $timestamp;
        reset($samples);

        return $store;
    }

    private function normalize_quota_sample($sample, int $fallbackTimestamp): ?array
    {
        if (!is_array($sample)) {
            return null;
        }

        $timestamp = isset($sample['timestamp']) ? (int) $sample['timestamp'] : $fallbackTimestamp;
        if ($timestamp <= 0) {
            $timestamp = $fallbackTimestamp;
        }

        $used = $this->sanitize_bytes($sample['used_bytes'] ?? $sample['used'] ?? $sample['usage'] ?? ($sample['usage_bytes'] ?? null));
        $quota = $this->sanitize_bytes($sample['quota_bytes'] ?? $sample['quota'] ?? $sample['limit'] ?? ($sample['total'] ?? null));
        $free = $this->sanitize_bytes($sample['free_bytes'] ?? $sample['free'] ?? $sample['remaining'] ?? null);

        if ($quota !== null && $used !== null && $free === null) {
            $free = max(0, $quota - $used);
        } elseif ($quota === null && $used !== null && $free !== null) {
            $quota = max(0, $used + $free);
        }

        if ($used === null && $quota === null && $free === null) {
            return null;
        }

        return [
            'timestamp' => $timestamp,
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'free_bytes' => $free,
        ];
    }

    private function append_quota_sample(array $store, array $sample): array
    {
        $samples = [];
        if (isset($store['samples']) && is_array($store['samples'])) {
            foreach ($store['samples'] as $existing) {
                if (!is_array($existing)) {
                    continue;
                }

                if (isset($existing['timestamp']) && isset($sample['timestamp']) && (int) $existing['timestamp'] === (int) $sample['timestamp']) {
                    $samples[] = $sample;
                } else {
                    $samples[] = $existing;
                }
            }
        }

        if (empty($samples) || (isset($sample['timestamp']) && !in_array($sample, $samples, true))) {
            $samples[] = $sample;
        }

        usort($samples, static function ($a, $b) {
            return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
        });

        if (count($samples) > self::MAX_QUOTA_SAMPLES) {
            $samples = array_slice($samples, -self::MAX_QUOTA_SAMPLES);
        }

        $samples = array_values($samples);
        $store['samples'] = $samples;
        $last = end($samples);
        if (is_array($last)) {
            $store['last_sample'] = $last;
            $store['updated_at'] = isset($last['timestamp']) ? (int) $last['timestamp'] : ($store['updated_at'] ?? $sample['timestamp']);
        } else {
            $store['last_sample'] = null;
            $store['updated_at'] = $store['updated_at'] ?? $sample['timestamp'];
        }
        reset($samples);

        return $store;
    }

    private function compute_duration_statistics(array $store, int $now): array
    {
        $samples = [];
        if (isset($store['samples']) && is_array($store['samples'])) {
            foreach ($store['samples'] as $sample) {
                if (!is_numeric($sample)) {
                    continue;
                }

                $value = (int) $sample;
                if ($value < 0) {
                    continue;
                }

                $samples[] = $value;
            }
        }

        if (count($samples) > self::MAX_DURATION_SAMPLES) {
            $samples = array_slice($samples, -self::MAX_DURATION_SAMPLES);
        }

        $sample_count = count($samples);
        $average = $sample_count > 0 ? array_sum($samples) / $sample_count : null;
        $p95 = $sample_count > 1 ? $this->compute_percentile($samples, 0.95) : ($sample_count === 1 ? (float) $samples[0] : null);
        $max = $sample_count > 0 ? max($samples) : null;
        $last_seconds = isset($store['last_seconds']) ? (int) $store['last_seconds'] : ($sample_count > 0 ? (int) end($samples) : null);
        $updated_at = isset($store['updated_at']) ? (int) $store['updated_at'] : $now;
        reset($samples);

        return [
            'samples' => $samples,
            'sample_count' => $sample_count,
            'average_seconds' => $average !== null ? (float) $average : null,
            'p95_seconds' => $p95,
            'max_seconds' => $max,
            'last_seconds' => $last_seconds,
            'updated_at' => $updated_at,
        ];
    }

    private function compute_percentile(array $values, float $percentile): ?float
    {
        if (empty($values)) {
            return null;
        }

        sort($values);
        $count = count($values);
        if ($count === 1) {
            return (float) $values[0];
        }

        $index = $percentile * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return (float) $values[$lower];
        }

        $weight = $index - $lower;

        return $values[$lower] + (($values[$upper] - $values[$lower]) * $weight);
    }

    private function compute_capacity_projections(int $now, array $quota_destinations, array $previous_projections): array
    {
        $threshold_ratio = 0.85;
        $warning_hours = 72;
        $critical_hours = 24;

        if (class_exists(__NAMESPACE__ . '\\BJLG_Settings') && method_exists(BJLG_Settings::class, 'get_monitoring_settings')) {
            try {
                $monitoring_settings = BJLG_Settings::get_monitoring_settings();
            } catch (\Throwable $exception) {
                $monitoring_settings = [];
            }

            if (is_array($monitoring_settings)) {
                if (isset($monitoring_settings['storage_quota_warning_threshold'])) {
                    $candidate = (float) $monitoring_settings['storage_quota_warning_threshold'];
                    if ($candidate > 0) {
                        $threshold_ratio = max(0.01, min(1.0, $candidate / 100));
                    }
                }

                if (isset($monitoring_settings['remote_capacity_warning_hours'])) {
                    $warning_candidate = (int) $monitoring_settings['remote_capacity_warning_hours'];
                    if ($warning_candidate > 0) {
                        $warning_hours = max(1, min(24 * 7, $warning_candidate));
                    }
                }

                if (isset($monitoring_settings['remote_capacity_critical_hours'])) {
                    $critical_candidate = (int) $monitoring_settings['remote_capacity_critical_hours'];
                    if ($critical_candidate > 0) {
                        $critical_hours = max(1, min($warning_hours, $critical_candidate));
                    }
                }
            }
        }

        $warning_seconds = $warning_hours * HOUR_IN_SECONDS;
        $critical_seconds = $critical_hours * HOUR_IN_SECONDS;

        $normalized_quotas = [];
        $projections = [];
        $alerts = [];

        foreach ($quota_destinations as $destination_id => $data) {
            if (!is_array($data)) {
                continue;
            }

            $samples = [];
            if (!empty($data['samples']) && is_array($data['samples'])) {
                foreach ($data['samples'] as $sample) {
                    if (!is_array($sample)) {
                        continue;
                    }

                    $normalized = $this->normalize_quota_sample($sample, $now);
                    if ($normalized === null) {
                        continue;
                    }

                    $samples[] = $normalized;
                }
            }

            if (empty($samples) && isset($data['last_sample']) && is_array($data['last_sample'])) {
                $normalized = $this->normalize_quota_sample($data['last_sample'], $now);
                if ($normalized !== null) {
                    $samples[] = $normalized;
                }
            }

            usort($samples, static function ($a, $b) {
                return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
            });

            if (count($samples) > self::MAX_QUOTA_SAMPLES) {
                $samples = array_slice($samples, -self::MAX_QUOTA_SAMPLES);
            }

            $normalized_quotas[$destination_id] = [
                'samples' => $samples,
                'last_sample' => !empty($samples) ? end($samples) : null,
                'updated_at' => !empty($samples) ? (int) end($samples)['timestamp'] : ($data['updated_at'] ?? $now),
            ];
            if (!empty($samples)) {
                $last_sample = end($samples);
                reset($samples);
            } else {
                $last_sample = null;
            }

            $points = [];
            foreach ($samples as $sample) {
                if (!isset($sample['timestamp'], $sample['used_bytes'])) {
                    continue;
                }

                $points[] = [
                    'timestamp' => (int) $sample['timestamp'],
                    'total' => (float) $sample['used_bytes'],
                ];
            }

            [$slope, $intercept] = $this->compute_regression($points);
            $trend = $slope !== null ? (float) $slope : 0.0;
            $trend_direction = 'flat';
            if ($trend > 0) {
                $trend_direction = 'up';
            } elseif ($trend < 0) {
                $trend_direction = 'down';
            }

            $quota_bytes = isset($last_sample['quota_bytes']) && $last_sample['quota_bytes'] !== null
                ? (int) $last_sample['quota_bytes']
                : null;
            $used_bytes = isset($last_sample['used_bytes']) && $last_sample['used_bytes'] !== null
                ? (int) $last_sample['used_bytes']
                : null;

            $projected_timestamp = null;
            $seconds_remaining = null;
            $risk_level = 'unknown';
            $projection_label = '';

            if ($quota_bytes !== null && $quota_bytes > 0 && $used_bytes !== null) {
                $target_bytes = (int) floor($quota_bytes * $threshold_ratio);
                if ($used_bytes >= $target_bytes) {
                    $seconds_remaining = 0;
                    $projected_timestamp = $now;
                    $risk_level = 'critical';
                } elseif ($trend > 0) {
                    $seconds_remaining = ($target_bytes - $used_bytes) / $trend;
                    if ($seconds_remaining < 0) {
                        $seconds_remaining = 0.0;
                    }
                    $anchor = isset($last_sample['timestamp']) ? (int) $last_sample['timestamp'] : $now;
                    $projected_timestamp = $anchor + (int) round($seconds_remaining);

                    if ($seconds_remaining <= $critical_seconds) {
                        $risk_level = 'critical';
                    } elseif ($seconds_remaining <= $warning_seconds) {
                        $risk_level = 'warning';
                    } else {
                        $risk_level = 'watch';
                    }
                } elseif ($trend < 0) {
                    $risk_level = 'watch';
                } else {
                    $risk_level = 'watch';
                }

                if ($seconds_remaining !== null) {
                    $projection_label = sprintf(
                        __('Saturation estimée dans %s', 'backup-jlg'),
                        $this->format_delay_label((int) round($seconds_remaining))
                    );
                } elseif ($trend <= 0) {
                    $projection_label = __('Consommation stable ou en baisse', 'backup-jlg');
                } else {
                    $projection_label = __('Projection indisponible', 'backup-jlg');
                }
            } else {
                $projection_label = __('Quota non communiqué', 'backup-jlg');
            }

            $previous = isset($previous_projections[$destination_id]) && is_array($previous_projections[$destination_id])
                ? $previous_projections[$destination_id]
                : [];
            $previous_level = isset($previous['risk_level']) ? (string) $previous['risk_level'] : 'ok';
            $last_alerted_at = isset($previous['last_alerted_at']) ? (int) $previous['last_alerted_at'] : 0;

            if (in_array($risk_level, ['warning', 'critical'], true)) {
                $should_alert = false;
                $interval = $risk_level === 'critical' ? self::CRITICAL_RENOTIFY_INTERVAL : self::WARNING_RENOTIFY_INTERVAL;
                if ($risk_level !== $previous_level) {
                    $should_alert = true;
                } elseif (($now - $last_alerted_at) >= $interval) {
                    $should_alert = true;
                }

                if ($should_alert) {
                    $seconds_value = $seconds_remaining !== null ? (int) round($seconds_remaining) : null;
                    $threshold_percent = $threshold_ratio * 100;
                    $percent_label = function_exists('number_format_i18n')
                        ? number_format_i18n((int) round($threshold_percent))
                        : number_format((int) round($threshold_percent));
                    $time_label = $seconds_value !== null ? $this->format_delay_label($seconds_value) : __('indéterminé', 'backup-jlg');

                    $context = [
                        'destination_id' => $destination_id,
                        'destination_label' => $this->get_destination_label((string) $destination_id),
                        'risk_level' => $risk_level,
                        'threshold_ratio' => $threshold_ratio,
                        'threshold_percent' => $threshold_percent,
                        'warning_hours' => $warning_hours,
                        'critical_hours' => $critical_hours,
                        'projected_timestamp' => $projected_timestamp,
                        'seconds_remaining' => $seconds_value,
                        'trend_bytes_per_second' => $trend,
                        'trend_direction' => $trend_direction,
                        'last_used_bytes' => $used_bytes,
                        'quota_bytes' => $quota_bytes,
                        'projection_label' => $projection_label,
                        'generated_at' => $now,
                    ];

                    $alerts[] = [
                        'risk_level' => $risk_level,
                        'context' => $context,
                        'message' => sprintf(
                            __('%1$s : saturation estimée dans %2$s (seuil %3$s%%).', 'backup-jlg'),
                            $context['destination_label'],
                            $time_label,
                            $percent_label
                        ),
                    ];

                    $last_alerted_at = $now;
                }
            }

            $projections[$destination_id] = [
                'samples' => $samples,
                'risk_level' => $risk_level,
                'trend_direction' => $trend_direction,
                'trend_bytes_per_second' => $trend,
                'seconds_remaining' => $seconds_remaining !== null ? (int) round($seconds_remaining) : null,
                'hours_remaining' => $seconds_remaining !== null ? ($seconds_remaining / HOUR_IN_SECONDS) : null,
                'projected_timestamp' => $projected_timestamp,
                'projection_label' => $projection_label,
                'last_alerted_at' => $last_alerted_at,
                'last_sample' => $last_sample,
                'threshold_ratio' => $threshold_ratio,
                'usage_ratio' => ($quota_bytes && $used_bytes !== null) ? ($used_bytes / max(1, $quota_bytes)) : null,
            ];
        }

        return [
            'quotas' => $normalized_quotas,
            'projections' => $projections,
            'thresholds' => [
                'warning_hours' => $warning_hours,
                'critical_hours' => $critical_hours,
                'threshold_ratio' => $threshold_ratio,
                'threshold_percent' => $threshold_ratio * 100,
            ],
            'alerts' => $alerts,
        ];
    }

    private function handle_capacity_alert(array $alert): void
    {
        $context = isset($alert['context']) && is_array($alert['context']) ? $alert['context'] : [];
        $message = isset($alert['message']) ? (string) $alert['message'] : '';
        $risk_level = isset($alert['risk_level']) ? (string) $alert['risk_level'] : 'warning';
        $status = $risk_level === 'critical' ? 'failure' : 'warning';

        if ($message !== '' && class_exists(BJLG_History::class)) {
            BJLG_History::log('remote_purge_capacity_forecast', $status, $message, null, null, $context);
        }

        if (function_exists('do_action')) {
            do_action('bjlg_remote_storage_capacity_forecast', $context);
        }
    }

    private function get_destination_label(string $destination_id): string
    {
        $label = $destination_id;
        if (class_exists(__NAMESPACE__ . '\\BJLG_Settings') && method_exists(BJLG_Settings::class, 'get_destination_label')) {
            try {
                $candidate = BJLG_Settings::get_destination_label($destination_id);
            } catch (\Throwable $exception) {
                $candidate = null;
            }

            if (is_string($candidate) && $candidate !== '') {
                $label = $candidate;
            }
        }

        return $label;
    }

    private function sanitize_bytes($value): ?int
    {
        if (is_numeric($value)) {
            $numeric = (float) $value;
            if (!is_finite($numeric)) {
                return null;
            }

            return (int) max(0, $numeric);
        }

        return null;
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

    /**
     * @param array<string,int> $pending_by_destination
     * @param array<string,int> $pending_oldest
     * @param array<string,array<string,mixed>> $duration_stats
     * @param array<string,array<string,mixed>> $forecast_destinations
     * @param array<string,array<string,mixed>> $quota_destinations
     * @param array<string,array<string,mixed>> $storage_destinations
     *
     * @return array<string,array<string,mixed>>
     */
    private function build_destination_overview(
        int $now,
        array $pending_by_destination,
        array $pending_oldest,
        array $duration_stats,
        array $forecast_destinations,
        array $quota_destinations,
        array $storage_destinations
    ): array {
        $destination_ids = array_unique(array_merge(
            array_keys($pending_by_destination),
            array_keys($pending_oldest),
            array_keys($duration_stats),
            array_keys($forecast_destinations),
            array_keys($quota_destinations),
            array_keys($storage_destinations)
        ));

        sort($destination_ids);

        $overview = [];
        foreach ($destination_ids as $destination_id) {
            $key = (string) $destination_id;
            $label = $this->get_destination_label($key);

            $pending = isset($pending_by_destination[$key]) ? (int) $pending_by_destination[$key] : 0;
            $pending_oldest_seconds = isset($pending_oldest[$key]) ? (int) $pending_oldest[$key] : 0;

            $duration_entry = isset($duration_stats[$key]) && is_array($duration_stats[$key]) ? $duration_stats[$key] : [];
            $avg_duration = isset($duration_entry['average_duration_seconds']) ? (float) $duration_entry['average_duration_seconds'] : null;
            $avg_attempts = isset($duration_entry['average_attempts']) ? (float) $duration_entry['average_attempts'] : null;
            $last_duration = isset($duration_entry['last_duration_seconds']) ? (float) $duration_entry['last_duration_seconds'] : null;
            $last_outcome = isset($duration_entry['last_outcome']) ? (string) $duration_entry['last_outcome'] : '';
            $duration_samples = isset($duration_entry['samples']) ? (int) $duration_entry['samples'] : 0;
            $completed_count = isset($duration_entry['completed']) ? (int) $duration_entry['completed'] : 0;
            $duration_updated_at = isset($duration_entry['last_updated']) ? (int) $duration_entry['last_updated'] : 0;

            $forecast_entry = isset($forecast_destinations[$key]) && is_array($forecast_destinations[$key]) ? $forecast_destinations[$key] : [];
            $forecast_seconds = isset($forecast_entry['forecast_seconds']) ? (int) $forecast_entry['forecast_seconds'] : null;
            $forecast_label = isset($forecast_entry['forecast_label']) ? (string) $forecast_entry['forecast_label'] : '';
            $projected_clearance = isset($forecast_entry['projected_clearance']) ? (int) $forecast_entry['projected_clearance'] : null;
            $seconds_per_item = isset($forecast_entry['seconds_per_item']) ? (float) $forecast_entry['seconds_per_item'] : null;
            $trend_direction = isset($forecast_entry['trend_direction']) ? (string) $forecast_entry['trend_direction'] : 'flat';
            $forecast_samples = isset($forecast_entry['samples']) ? (int) $forecast_entry['samples'] : 0;

            $quota_entry = isset($quota_destinations[$key]) && is_array($quota_destinations[$key]) ? $quota_destinations[$key] : [];
            $quota_ratio = isset($quota_entry['current_ratio']) ? (float) $quota_entry['current_ratio'] : null;
            $quota_projected = isset($quota_entry['projected_saturation']) ? (int) $quota_entry['projected_saturation'] : null;
            $quota_lead = isset($quota_entry['lead_time_seconds']) ? (int) $quota_entry['lead_time_seconds'] : null;
            $quota_risk = isset($quota_entry['risk_level']) ? (string) $quota_entry['risk_level'] : 'unknown';
            $quota_label = isset($quota_entry['projected_label']) ? (string) $quota_entry['projected_label'] : '';
            $quota_threshold = isset($quota_entry['threshold_percent']) ? (float) $quota_entry['threshold_percent'] : null;
            $quota_history = isset($quota_entry['history']) && is_array($quota_entry['history']) ? $quota_entry['history'] : [];
            $quota_last_sample = isset($quota_entry['last_sample']) && is_array($quota_entry['last_sample']) ? $quota_entry['last_sample'] : null;

            $storage_entry = isset($storage_destinations[$key]) && is_array($storage_destinations[$key]) ? $storage_destinations[$key] : [];
            $storage_connected = isset($storage_entry['connected']) ? (bool) $storage_entry['connected'] : null;
            $storage_latency = isset($storage_entry['latency_ms']) ? (float) $storage_entry['latency_ms'] : null;
            $storage_refreshed = isset($storage_entry['refreshed_at']) ? (int) $storage_entry['refreshed_at'] : null;
            $storage_ratio = isset($storage_entry['ratio']) ? (float) $storage_entry['ratio'] : null;
            $storage_used = $storage_entry['used_bytes'] ?? ($quota_last_sample['used_bytes'] ?? null);
            $storage_quota = $storage_entry['quota_bytes'] ?? ($quota_last_sample['quota_bytes'] ?? null);
            $storage_free = $storage_entry['free_bytes'] ?? ($quota_last_sample['free_bytes'] ?? null);

            $overview[$key] = [
                'id' => $key,
                'label' => isset($storage_entry['name']) && is_string($storage_entry['name']) && $storage_entry['name'] !== ''
                    ? (string) $storage_entry['name']
                    : $label,
                'pending' => $pending,
                'pending_oldest_seconds' => $pending_oldest_seconds,
                'average_duration_seconds' => $avg_duration,
                'average_attempts' => $avg_attempts,
                'last_duration_seconds' => $last_duration,
                'last_outcome' => $last_outcome,
                'duration_samples' => $duration_samples,
                'duration_completed' => $completed_count,
                'duration_updated_at' => $duration_updated_at,
                'forecast_seconds' => $forecast_seconds,
                'forecast_label' => $forecast_label,
                'projected_clearance' => $projected_clearance,
                'seconds_per_item' => $seconds_per_item,
                'trend_direction' => $trend_direction,
                'forecast_samples' => $forecast_samples,
                'quota' => [
                    'current_ratio' => $quota_ratio,
                    'projected_saturation' => $quota_projected,
                    'lead_time_seconds' => $quota_lead,
                    'risk_level' => $quota_risk,
                    'projected_label' => $quota_label,
                    'threshold_percent' => $quota_threshold,
                    'history' => $quota_history,
                    'last_sample' => $quota_last_sample,
                ],
                'storage' => [
                    'connected' => $storage_connected,
                    'latency_ms' => $storage_latency,
                    'refreshed_at' => $storage_refreshed,
                    'ratio' => $storage_ratio,
                    'used_bytes' => $storage_used,
                    'quota_bytes' => $storage_quota,
                    'free_bytes' => $storage_free,
                ],
                'updated_at' => $now,
            ];
        }

        return $overview;
    }

    private function maybe_trigger_proactive_alerts(array $quotas, int $now, array $destination_overview = []): array
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

            if (isset($destination_overview[$destination_id]) && is_array($destination_overview[$destination_id])) {
                $overview_entry = $destination_overview[$destination_id];
                $context['pending'] = $overview_entry['pending'] ?? null;
                $context['pending_oldest_seconds'] = $overview_entry['pending_oldest_seconds'] ?? null;
                $context['average_duration_seconds'] = $overview_entry['average_duration_seconds'] ?? null;
                $context['forecast_seconds'] = $overview_entry['forecast_seconds'] ?? null;
                $context['forecast_label_overview'] = $overview_entry['forecast_label'] ?? '';
                $context['seconds_per_item'] = $overview_entry['seconds_per_item'] ?? null;
            }

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
