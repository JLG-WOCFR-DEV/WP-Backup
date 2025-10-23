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
        $destinations = [];

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
                    if (!isset($destinations[$key])) {
                        $destinations[$key] = 0;
                    }
                    $destinations[$key]++;
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

                $last_completed_at = isset($result['timestamp']) ? (int) $result['timestamp'] : $now;
                $last_completion_seconds = $duration;
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
        }

        $duration_metrics = isset($existing['durations']) && is_array($existing['durations']) ? $existing['durations'] : [];
        $duration_samples = isset($duration_metrics['samples']) ? (int) $duration_metrics['samples'] : 0;
        $avg_duration = isset($duration_metrics['average_seconds']) ? (float) $duration_metrics['average_seconds'] : 0.0;
        $max_duration = isset($duration_metrics['max_seconds']) ? (int) $duration_metrics['max_seconds'] : 0;
        $min_duration = isset($duration_metrics['min_seconds']) ? (int) $duration_metrics['min_seconds'] : 0;
        $last_duration = isset($duration_metrics['last_seconds']) ? (int) $duration_metrics['last_seconds'] : 0;

        $backlog_metrics = isset($existing['backlog']) && is_array($existing['backlog']) ? $existing['backlog'] : [];
        $backlog_samples = isset($backlog_metrics['samples']) ? (int) $backlog_metrics['samples'] : 0;
        $avg_queue_size = isset($backlog_metrics['average_queue_size']) ? (float) $backlog_metrics['average_queue_size'] : 0.0;
        $avg_oldest = isset($backlog_metrics['average_oldest_seconds']) ? (float) $backlog_metrics['average_oldest_seconds'] : 0.0;
        $max_queue_size = isset($backlog_metrics['max_queue_size']) ? (int) $backlog_metrics['max_queue_size'] : 0;
        $max_oldest_seconds = isset($backlog_metrics['max_oldest_seconds']) ? (int) $backlog_metrics['max_oldest_seconds'] : 0;
        $last_snapshot = isset($backlog_metrics['last_snapshot']) && is_array($backlog_metrics['last_snapshot'])
            ? $backlog_metrics['last_snapshot']
            : null;
        $trend_per_minute = isset($backlog_metrics['trend_per_minute']) ? (float) $backlog_metrics['trend_per_minute'] : 0.0;
        $trend_samples = isset($backlog_metrics['trend_samples']) ? (int) $backlog_metrics['trend_samples'] : 0;

        $quota_metrics = isset($existing['quotas']) && is_array($existing['quotas']) ? $existing['quotas'] : [];
        $quota_samples = isset($quota_metrics['samples']) ? (int) $quota_metrics['samples'] : 0;
        $quota_ratio_samples = isset($quota_metrics['ratio_samples']) ? (int) $quota_metrics['ratio_samples'] : 0;
        $quota_average_ratio = isset($quota_metrics['average_ratio']) ? (float) $quota_metrics['average_ratio'] : 0.0;
        $quota_last_sample = isset($quota_metrics['last_sample_at']) ? (int) $quota_metrics['last_sample_at'] : 0;
        $quota_destinations = isset($quota_metrics['destinations']) && is_array($quota_metrics['destinations'])
            ? $quota_metrics['destinations']
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

                if ($duration > 0) {
                    $duration_samples++;
                    if ($duration_samples > 0) {
                        $avg_duration = $avg_duration + (($duration - $avg_duration) / $duration_samples);
                    }
                    $max_duration = max($max_duration, $duration);
                    $min_duration = $min_duration === 0 ? $duration : min($min_duration, $duration);
                    $last_duration = $duration;
                }
            }

            if (!empty($result['quota_samples']) && is_array($result['quota_samples'])) {
                foreach ($result['quota_samples'] as $destination_id => $sample) {
                    if (!is_array($sample)) {
                        continue;
                    }

                    $destination_key = (string) $destination_id;
                    $sample_timestamp = isset($sample['timestamp']) ? (int) $sample['timestamp'] : $now;
                    $used_bytes = isset($sample['used_bytes']) ? $this->sanitize_positive_int($sample['used_bytes']) : null;
                    $quota_bytes = isset($sample['quota_bytes']) ? $this->sanitize_positive_int($sample['quota_bytes']) : null;
                    $free_bytes = isset($sample['free_bytes']) ? $this->sanitize_positive_int($sample['free_bytes']) : null;

                    if ($used_bytes === null && $quota_bytes === null && $free_bytes === null) {
                        continue;
                    }

                    $quota_samples++;
                    $quota_last_sample = max($quota_last_sample, $sample_timestamp);

                    if (!isset($quota_destinations[$destination_key]) || !is_array($quota_destinations[$destination_key])) {
                        $quota_destinations[$destination_key] = [
                            'samples' => 0,
                            'last_seen_at' => 0,
                            'used_bytes' => null,
                            'quota_bytes' => null,
                            'free_bytes' => null,
                            'usage_ratio' => null,
                            'average_usage_ratio' => null,
                        ];
                    }

                    $quota_destinations[$destination_key]['samples']++;
                    $quota_destinations[$destination_key]['last_seen_at'] = $sample_timestamp;
                    if ($used_bytes !== null) {
                        $quota_destinations[$destination_key]['used_bytes'] = $used_bytes;
                    }
                    if ($quota_bytes !== null) {
                        $quota_destinations[$destination_key]['quota_bytes'] = $quota_bytes;
                    }
                    if ($free_bytes !== null) {
                        $quota_destinations[$destination_key]['free_bytes'] = $free_bytes;
                    }

                    $ratio = null;
                    if ($quota_bytes !== null && $quota_bytes > 0 && $used_bytes !== null) {
                        $ratio = max(0.0, min(1.0, $used_bytes / max(1, $quota_bytes)));
                        $quota_destinations[$destination_key]['usage_ratio'] = $ratio;

                        $samples_for_destination = $quota_destinations[$destination_key]['samples'];
                        $prev_avg = isset($quota_destinations[$destination_key]['average_usage_ratio'])
                            ? (float) $quota_destinations[$destination_key]['average_usage_ratio']
                            : 0.0;
                        $new_avg = $prev_avg + (($ratio - $prev_avg) / max(1, $samples_for_destination));
                        $quota_destinations[$destination_key]['average_usage_ratio'] = $new_avg;

                        $quota_ratio_samples++;
                        $quota_average_ratio = $quota_average_ratio + (($ratio - $quota_average_ratio) / max(1, $quota_ratio_samples));
                    }
                }
            }
        }

        $current_snapshot = [
            'timestamp' => $now,
            'queue_size' => $pending_total,
            'average_seconds' => $average_age,
            'oldest_seconds' => $pending_oldest,
        ];

        $backlog_samples++;
        if ($backlog_samples > 0) {
            $avg_queue_size = $avg_queue_size + (($pending_total - $avg_queue_size) / $backlog_samples);
            $avg_oldest = $avg_oldest + (($pending_oldest - $avg_oldest) / $backlog_samples);
        }
        $max_queue_size = max($max_queue_size, $pending_total);
        $max_oldest_seconds = max($max_oldest_seconds, $pending_oldest);

        if (is_array($last_snapshot) && isset($last_snapshot['timestamp'])) {
            $delta_seconds = max(60, $now - (int) $last_snapshot['timestamp']);
            $delta_minutes = $delta_seconds / 60;
            if ($delta_minutes > 0) {
                $delta_queue = $pending_total - (int) ($last_snapshot['queue_size'] ?? 0);
                $current_trend = $delta_queue / $delta_minutes;
                $trend_samples++;
                $trend_per_minute = $trend_per_minute + (($current_trend - $trend_per_minute) / max(1, $trend_samples));
            }
        }

        $processing_capacity_per_hour = self::MAX_ENTRIES_PER_RUN * (int) max(1, (int) (HOUR_IN_SECONDS / max(1, 5 * MINUTE_IN_SECONDS)));
        $projected_queue_15 = max(0, (int) round($pending_total + ($trend_per_minute * 15)));
        $projected_queue_60 = max(0, (int) round($pending_total + ($trend_per_minute * 60)));
        $projected_oldest_15 = max(0, (int) round($pending_oldest + max(0, $trend_per_minute) * 15 * ($avg_completion > 0 ? $avg_completion : 60)));
        $projected_oldest_60 = max(0, (int) round($pending_oldest + max(0, $trend_per_minute) * 60 * ($avg_completion > 0 ? $avg_completion : 60)));
        $estimated_clearance = $avg_completion > 0 ? (int) round($pending_total * $avg_completion) : 0;

        $saturation_risk = $projected_queue_60 > $processing_capacity_per_hour || $projected_oldest_60 > (self::DELAY_ALERT_THRESHOLD * 2);

        $metrics = [
            'updated_at' => $now,
            'pending' => [
                'total' => $pending_total,
                'average_seconds' => $average_age,
                'oldest_seconds' => $pending_oldest,
                'over_threshold' => $pending_over_threshold,
                'destinations' => $destinations,
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
            'durations' => [
                'samples' => $duration_samples,
                'average_seconds' => $duration_samples > 0 ? $avg_duration : 0,
                'max_seconds' => $max_duration,
                'min_seconds' => $min_duration,
                'last_seconds' => $last_duration,
            ],
            'backlog' => [
                'samples' => $backlog_samples,
                'average_queue_size' => $avg_queue_size,
                'average_oldest_seconds' => $avg_oldest,
                'max_queue_size' => $max_queue_size,
                'max_oldest_seconds' => $max_oldest_seconds,
                'trend_per_minute' => $trend_per_minute,
                'trend_samples' => $trend_samples,
                'last_snapshot' => $current_snapshot,
            ],
            'projections' => [
                'queue_in_15m' => $projected_queue_15,
                'queue_in_60m' => $projected_queue_60,
                'oldest_in_15m' => $projected_oldest_15,
                'oldest_in_60m' => $projected_oldest_60,
                'clearance_seconds' => $estimated_clearance,
                'saturation_risk' => $saturation_risk,
                'trend_per_minute' => $trend_per_minute,
                'processing_capacity_per_hour' => $processing_capacity_per_hour,
            ],
            'quotas' => [
                'samples' => $quota_samples,
                'ratio_samples' => $quota_ratio_samples,
                'average_ratio' => $quota_ratio_samples > 0 ? $quota_average_ratio : null,
                'last_sample_at' => $quota_last_sample,
                'destinations' => $quota_destinations,
            ],
        ];

        if (function_exists('update_option')) {
            \bjlg_update_option('bjlg_remote_purge_sla_metrics', $metrics);
        }
    }

    private function extract_quota_sample($destination_id, array $result, int $timestamp): ?array {
        $payload = null;

        if (isset($result['quota']) && is_array($result['quota'])) {
            $payload = $result['quota'];
        } elseif (isset($result['usage']) && is_array($result['usage'])) {
            $payload = $result['usage'];
        } elseif (isset($result['metrics']) && is_array($result['metrics']) && isset($result['metrics']['quota']) && is_array($result['metrics']['quota'])) {
            $payload = $result['metrics']['quota'];
        }

        if (!is_array($payload)) {
            return null;
        }

        $used = $this->extract_numeric_from_payload($payload, ['used_bytes', 'used', 'usage', 'usedBytes']);
        $quota = $this->extract_numeric_from_payload($payload, ['quota_bytes', 'quota', 'limit', 'quotaBytes']);
        $free = $this->extract_numeric_from_payload($payload, ['free_bytes', 'free', 'remaining']);

        $used = $this->sanitize_positive_int($used);
        $quota = $this->sanitize_positive_int($quota);
        $free = $this->sanitize_positive_int($free);

        if ($used === null && $quota === null && $free === null) {
            return null;
        }

        if ($free === null && $quota !== null && $used !== null) {
            $free = max(0, $quota - $used);
        }

        if ($quota === null && $used !== null && $free !== null) {
            $quota = max(0, $used + $free);
        }

        return [
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'free_bytes' => $free,
            'timestamp' => $timestamp,
            'destination' => (string) $destination_id,
        ];
    }

    private function extract_numeric_from_payload(array $payload, array $keys) {
        foreach ($keys as $key) {
            if (!isset($payload[$key])) {
                continue;
            }

            $value = $payload[$key];
            if (is_numeric($value)) {
                return $value;
            }

            if (is_string($value) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function sanitize_positive_int($value): ?int {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value)) {
            if (!is_finite($value) || $value < 0) {
                return null;
            }

            return (int) round($value);
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }

            if (!is_numeric($value)) {
                return null;
            }

            return $this->sanitize_positive_int((float) $value);
        }

        return null;
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
