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

        foreach ($destinations as $destination_id) {
            $destination = BJLG_Destination_Factory::create($destination_id);
            if (!$destination instanceof BJLG_Destination_Interface) {
                $errors[$destination_id] = sprintf(__('Destination inconnue : %s', 'backup-jlg'), $destination_id);
                continue;
            }

            $destination_names[$destination_id] = $destination->get_name();

            $result = $destination->delete_remote_backup_by_name($file);
            $was_successful = is_array($result) ? !empty($result['success']) : false;

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

        $existing = function_exists('get_option') ? bjlg_get_option('bjlg_remote_purge_sla_metrics', []) : [];
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
        ];

        if (function_exists('update_option')) {
            bjlg_update_option('bjlg_remote_purge_sla_metrics', $metrics, false);
        }
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
