<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the notification queue and retry logic for asynchronous delivery.
 */
class BJLG_Notification_Queue {

    private const OPTION = 'bjlg_notification_queue';
    private const HOOK = 'bjlg_process_notification_queue';
    private const REMINDER_HOOK = 'bjlg_notification_queue_reminder';
    private const LOCK_TRANSIENT = 'bjlg_notification_queue_lock';
    private const LOCK_DURATION = 45; // seconds
    private const MAX_ATTEMPTS = 5;
    private const MAX_ENTRIES_PER_RUN = 5;
    private const VALID_SEVERITIES = ['info', 'warning', 'critical'];
    private const DEFAULT_REMINDER_INTERVAL = 900; // 15 minutes
    private const MAX_REMINDER_INTERVAL = 86400; // 24 hours

    public function __construct() {
        add_filter('cron_schedules', [$this, 'register_cron_schedule']);
        add_action('init', [$this, 'ensure_schedule']);
        add_action(self::HOOK, [$this, 'process_queue']);
        add_action(self::REMINDER_HOOK, [$this, 'handle_reminder']);
    }

    /**
     * Garantit que l'intervalle personnalisé utilisé par la file est disponible.
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
     * Ensures a recurring schedule exists so that stuck notifications are retried.
     */
    public function ensure_schedule() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'every_five_minutes', self::HOOK);
        }
    }

    /**
     * Adds an entry to the notification queue.
     *
     * @param array<string,mixed> $entry
     */
    public static function enqueue(array $entry) {
        $normalized = self::normalize_entry($entry);
        if (empty($normalized)) {
            return null;
        }

        $queue = self::get_queue();
        $queue[] = $normalized;
        self::save_queue($queue);

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + 15, self::HOOK);
        }

        do_action('bjlg_notification_queued', $normalized);

        self::schedule_entry_reminder($normalized, isset($normalized['reminders']) ? (int) ($normalized['reminders']['attempts'] ?? 0) : 0);

        return $normalized;
    }

    /**
     * Retourne un instantané sécurisé de la file pour l'observabilité UI.
     *
     * @return array<string,mixed>
     */
    public static function get_queue_snapshot() {
        $queue = self::get_queue();

        $snapshot = [
            'total_entries' => count($queue),
            'status_counts' => [
                'pending' => 0,
                'retry' => 0,
                'failed' => 0,
                'completed' => 0,
            ],
            'next_attempt_at' => null,
            'oldest_entry_at' => null,
            'entries' => [],
        ];

        if (empty($queue)) {
            return $snapshot;
        }

        foreach ($queue as $entry) {
            if (!is_array($entry) || empty($entry['channels']) || !is_array($entry['channels'])) {
                continue;
            }

            $channels = [];
            $has_pending = false;
            $has_retry = false;
            $all_failed = !empty($entry['channels']);
            $all_completed = !empty($entry['channels']);
            $max_attempts = 0;
            $entry_next_attempt = isset($entry['next_attempt_at']) ? (int) $entry['next_attempt_at'] : 0;
            $last_error = isset($entry['last_error']) ? (string) $entry['last_error'] : '';

            $escalation_next = null;
            $escalation_pending = false;

            foreach ($entry['channels'] as $channel_key => $channel) {
                if (!is_string($channel_key)) {
                    continue;
                }

                $status = isset($channel['status']) ? (string) $channel['status'] : 'pending';
                $status = $status !== '' ? $status : 'pending';
                $attempts = isset($channel['attempts']) ? (int) $channel['attempts'] : 0;
                $channel_next_attempt = isset($channel['next_attempt_at']) ? (int) $channel['next_attempt_at'] : 0;
                $channel_error = isset($channel['last_error']) ? (string) $channel['last_error'] : '';
                $is_escalation = !empty($channel['escalation']);
                $channel_acknowledged_at = isset($channel['acknowledged_at']) ? (int) $channel['acknowledged_at'] : 0;
                $channel_acknowledged_by = isset($channel['acknowledged_by'])
                    ? self::sanitize_actor_label($channel['acknowledged_by'])
                    : '';
                $channel_resolved_at = isset($channel['resolved_at']) ? (int) $channel['resolved_at'] : 0;
                $channel_resolution_notes = self::sanitize_notes_value($channel['resolution_notes'] ?? '');

                if ($channel_next_attempt > 0) {
                    $entry_next_attempt = self::min_time_value($entry_next_attempt, $channel_next_attempt);
                }

                if ($is_escalation) {
                    $escalation_next = self::min_time_value($escalation_next, $channel_next_attempt);
                    if (in_array($status, ['pending', 'retry'], true)) {
                        $escalation_pending = true;
                    }
                }

                if ($channel_error !== '' && $last_error === '') {
                    $last_error = $channel_error;
                }

                $has_pending = $has_pending || $status === 'pending';
                $has_retry = $has_retry || $status === 'retry';
                $all_failed = $all_failed && $status === 'failed';
                $all_completed = $all_completed && $status === 'completed';

                $max_attempts = max($max_attempts, $attempts);

                $channels[] = [
                    'key' => sanitize_key($channel_key),
                    'status' => $status,
                    'attempts' => $attempts,
                    'last_error' => $channel_error,
                    'next_attempt_at' => $channel_next_attempt,
                    'escalation' => $is_escalation,
                    'acknowledged_at' => $channel_acknowledged_at,
                    'acknowledged_by' => $channel_acknowledged_by,
                    'acknowledged' => $channel_acknowledged_at > 0 || $channel_acknowledged_by !== '',
                    'resolved_at' => $channel_resolved_at,
                    'resolved' => $channel_resolved_at > 0,
                    'resolution_notes' => $channel_resolution_notes,
                ];
            }

            if (empty($channels)) {
                continue;
            }

            $status = 'pending';
            if ($has_pending) {
                $status = 'pending';
            } elseif ($has_retry) {
                $status = 'retry';
            } elseif ($all_failed) {
                $status = 'failed';
            } elseif ($all_completed) {
                $status = 'completed';
            } else {
                $status = 'retry';
            }

            if (!isset($snapshot['status_counts'][$status])) {
                $snapshot['status_counts'][$status] = 0;
            }
            $snapshot['status_counts'][$status]++;

            $created_at = isset($entry['created_at']) ? (int) $entry['created_at'] : 0;
            if ($created_at > 0) {
                $snapshot['oldest_entry_at'] = self::min_time_value($snapshot['oldest_entry_at'], $created_at);
            }

            if ($entry_next_attempt > 0) {
                $snapshot['next_attempt_at'] = self::min_time_value($snapshot['next_attempt_at'], $entry_next_attempt);
            }

            $acknowledged_at = isset($entry['acknowledged_at']) ? (int) $entry['acknowledged_at'] : 0;
            $acknowledged_by = isset($entry['acknowledged_by']) ? self::sanitize_actor_label($entry['acknowledged_by']) : '';
            $resolved_at = isset($entry['resolved_at']) ? (int) $entry['resolved_at'] : 0;
            $resolution_notes = self::sanitize_notes_value($entry['resolution_notes'] ?? '');

            $snapshot['entries'][] = [
                'id' => isset($entry['id']) ? sanitize_text_field((string) $entry['id']) : '',
                'event' => isset($entry['event']) ? sanitize_text_field((string) $entry['event']) : '',
                'title' => isset($entry['title']) ? sanitize_text_field((string) $entry['title']) : '',
                'status' => $status,
                'attempts' => $max_attempts,
                'created_at' => $created_at,
                'next_attempt_at' => $entry_next_attempt,
                'last_error' => $last_error,
                'channels' => $channels,
                'quiet_until' => isset($entry['quiet_until']) ? (int) $entry['quiet_until'] : 0,
                'escalation' => isset($entry['escalation']) && is_array($entry['escalation']) ? $entry['escalation'] : [],
                'has_escalation_pending' => $escalation_pending,
                'escalation_next_attempt' => $escalation_next,
                'severity' => isset($entry['severity']) ? sanitize_key((string) $entry['severity']) : 'info',
                'acknowledged_at' => $acknowledged_at,
                'acknowledged_by' => $acknowledged_by,
                'acknowledged' => $acknowledged_at > 0 || $acknowledged_by !== '',
                'resolved_at' => $resolved_at,
                'resolved' => $resolved_at > 0,
                'resolution_notes' => $resolution_notes,
                'resolution_status' => isset($entry['resolution_status'])
                    ? sanitize_key((string) $entry['resolution_status'])
                    : 'pending',
                'resolution_summary' => self::sanitize_notes_value($entry['resolution_summary'] ?? ''),
            ];
        }

        if (!empty($snapshot['entries'])) {
            usort($snapshot['entries'], static function ($a, $b) {
                $a_time = $a['next_attempt_at'] ?? 0;
                $b_time = $b['next_attempt_at'] ?? 0;

                if ($a_time === $b_time) {
                    return ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0);
                }

                return $a_time <=> $b_time;
            });
        }

        return $snapshot;
    }

    /**
     * Resets a queue entry so it can be retried immediately.
     *
     * @param string $entry_id
     */
    public static function retry_entry($entry_id) {
        $entry_id = is_string($entry_id) ? trim($entry_id) : '';
        if ($entry_id === '') {
            return false;
        }

        $queue = self::get_queue();
        $now = time();
        $updated = false;
        $retried_entry = null;

        foreach ($queue as &$entry) {
            if (!is_array($entry) || !isset($entry['id']) || (string) $entry['id'] !== $entry_id) {
                continue;
            }

            if (empty($entry['channels']) || !is_array($entry['channels'])) {
                break;
            }

            foreach ($entry['channels'] as $channel_key => &$channel) {
                if (!is_array($channel)) {
                    continue;
                }

                if (empty($channel['enabled'])) {
                    continue;
                }

                $channel['status'] = 'pending';
                $channel['attempts'] = 0;
                unset($channel['next_attempt_at'], $channel['last_error'], $channel['last_error_at'], $channel['failed_at'], $channel['completed_at']);
            }
            unset($channel);

            $entry['next_attempt_at'] = $now;
            $entry['last_attempt_at'] = 0;
            $entry['updated_at'] = $now;
            $entry['last_error'] = '';

            $retried_entry = $entry;
            $updated = true;
            break;
        }
        unset($entry);

        if (!$updated) {
            return false;
        }

        self::save_queue($queue);
        wp_schedule_single_event(time() + 5, self::HOOK);

        if ($retried_entry !== null) {
            /**
             * Fires after a notification queue entry has been reset for retry.
             *
             * @param array<string,mixed> $retried_entry
             */
            do_action('bjlg_notification_queue_entry_retried', $retried_entry);
        }

        return true;
    }

    /**
     * Removes an entry from the notification queue.
     *
     * @param string $entry_id
     */
    public static function delete_entry($entry_id) {
        $entry_id = is_string($entry_id) ? trim($entry_id) : '';
        if ($entry_id === '') {
            return false;
        }

        $queue = self::get_queue();
        $updated_queue = [];
        $removed_entry = null;

        foreach ($queue as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (isset($entry['id']) && (string) $entry['id'] === $entry_id) {
                $removed_entry = $entry;
                continue;
            }

            $updated_queue[] = $entry;
        }

        if ($removed_entry === null) {
            return false;
        }

        self::save_queue($updated_queue);

        /**
         * Fires after a notification queue entry has been deleted.
         *
         * @param array<string,mixed> $removed_entry
         */
        do_action('bjlg_notification_queue_entry_deleted', $removed_entry);

        return true;
    }

    /**
     * Marks a specific channel within an entry as acknowledged by an operator.
     *
     * @param string   $entry_id
     * @param string   $channel_key
     * @param int|null $user_id
     */
    public static function acknowledge_channel($entry_id, $channel_key, $user_id = null) {
        $entry_id = is_string($entry_id) ? trim($entry_id) : '';
        $channel_key = is_string($channel_key) ? sanitize_key($channel_key) : '';

        if ($entry_id === '' || $channel_key === '') {
            return false;
        }

        $queue = self::get_queue();
        $now = time();
        $actor_label = self::resolve_actor_name($user_id);
        $updated = false;

        foreach ($queue as &$entry) {
            if (!is_array($entry) || !isset($entry['id']) || (string) $entry['id'] !== $entry_id) {
                continue;
            }

            if (empty($entry['channels']) || !is_array($entry['channels'])) {
                break;
            }

            foreach ($entry['channels'] as $key => &$channel) {
                $normalized_key = sanitize_key((string) $key);
                if ($normalized_key !== $channel_key || !is_array($channel)) {
                    continue;
                }

                $channel['acknowledged_at'] = $now;
                if ($actor_label !== '') {
                    $channel['acknowledged_by'] = $actor_label;
                }

                if (empty($entry['acknowledged_at'])) {
                    $entry['acknowledged_at'] = $now;
                }

                if ($actor_label !== '') {
                    $entry['acknowledged_by'] = $actor_label;
                }

                if (!isset($entry['resolution']) || !is_array($entry['resolution'])) {
                    $entry['resolution'] = self::normalize_resolution([]);
                }
                if (empty($entry['resolution']['acknowledged_at'])) {
                    $entry['resolution']['acknowledged_at'] = $now;
                }

                $entry['updated_at'] = $now;
                self::refresh_resolution_metadata($entry);
                $updated = true;
                break 2;
            }
            unset($channel);

            break;
        }
        unset($entry);

        if (!$updated) {
            return false;
        }

        self::save_queue($queue);

        return true;
    }

    /**
     * Marks an entire entry as acknowledged.
     *
     * @param string   $entry_id
     * @param int|null $user_id
     */
    public static function acknowledge_entry($entry_id, $user_id = null) {
        $entry_id = is_string($entry_id) ? trim($entry_id) : '';
        if ($entry_id === '') {
            return false;
        }

        $queue = self::get_queue();
        $now = time();
        $actor_label = self::resolve_actor_name($user_id);
        $updated = false;

        foreach ($queue as &$entry) {
            if (!is_array($entry) || !isset($entry['id']) || (string) $entry['id'] !== $entry_id) {
                continue;
            }

            $entry['acknowledged_at'] = $now;
            if ($actor_label !== '') {
                $entry['acknowledged_by'] = $actor_label;
            }

            if (!empty($entry['channels']) && is_array($entry['channels'])) {
                foreach ($entry['channels'] as &$channel) {
                    if (!is_array($channel)) {
                        continue;
                    }

                    $channel['acknowledged_at'] = $now;
                    if ($actor_label !== '') {
                        $channel['acknowledged_by'] = $actor_label;
                    }
                }
                unset($channel);
            }

            if (!isset($entry['resolution']) || !is_array($entry['resolution'])) {
                $entry['resolution'] = self::normalize_resolution([]);
            }
            if (empty($entry['resolution']['acknowledged_at'])) {
                $entry['resolution']['acknowledged_at'] = $now;
            }

            $entry['updated_at'] = $now;
            self::refresh_resolution_metadata($entry);
            $updated = true;
            break;
        }
        unset($entry);

        if (!$updated) {
            return false;
        }

        self::save_queue($queue);

        return true;
    }

    /**
     * Marks a channel as resolved. When all channels are resolved the entry is closed and logged.
     *
     * @param string      $entry_id
     * @param string      $channel_key
     * @param int|null    $user_id
     * @param string|null $notes
     */
    public static function resolve_channel($entry_id, $channel_key, $user_id = null, $notes = '') {
        $entry_id = is_string($entry_id) ? trim($entry_id) : '';
        $channel_key = is_string($channel_key) ? sanitize_key($channel_key) : '';

        if ($entry_id === '' || $channel_key === '') {
            return false;
        }

        $queue = self::get_queue();
        $now = time();
        $actor_label = self::resolve_actor_name($user_id);
        $sanitized_notes = self::sanitize_notes_value($notes ?? '');
        $updated = false;
        $was_resolved = false;
        $entry_reference = null;

        foreach ($queue as &$entry) {
            if (!is_array($entry) || !isset($entry['id']) || (string) $entry['id'] !== $entry_id) {
                continue;
            }

            if (empty($entry['channels']) || !is_array($entry['channels'])) {
                break;
            }

            $was_resolved = self::is_entry_resolved($entry);

            foreach ($entry['channels'] as $key => &$channel) {
                $normalized_key = sanitize_key((string) $key);
                if ($normalized_key !== $channel_key || !is_array($channel)) {
                    continue;
                }

                $channel['resolved_at'] = $now;
                $channel['acknowledged_at'] = $channel['acknowledged_at'] ?? $now;

                if ($actor_label !== '') {
                    $channel['acknowledged_by'] = $actor_label;
                }

                if ($sanitized_notes !== '') {
                    $channel['resolution_notes'] = $sanitized_notes;
                }

                if (empty($entry['acknowledged_at'])) {
                    $entry['acknowledged_at'] = $now;
                }

                if ($actor_label !== '') {
                    $entry['acknowledged_by'] = $actor_label;
                }

                if ($sanitized_notes !== '') {
                    $entry['resolution_notes'] = self::append_resolution_note(
                        $entry['resolution_notes'] ?? '',
                        $normalized_key,
                        $sanitized_notes
                    );
                }

                if (!isset($entry['resolution']) || !is_array($entry['resolution'])) {
                    $entry['resolution'] = self::normalize_resolution([]);
                }
                if (empty($entry['resolution']['acknowledged_at'])) {
                    $entry['resolution']['acknowledged_at'] = $now;
                }
                $entry['resolution']['resolved_at'] = $now;

                $entry['updated_at'] = $now;
                self::refresh_resolution_metadata($entry);
                $updated = true;
                break;
            }
            unset($channel);

            if ($updated) {
                $all_resolved = self::are_all_channels_resolved($entry['channels']);
                if ($all_resolved) {
                    if (empty($entry['resolved_at'])) {
                        $entry['resolved_at'] = $now;
                    }
                }

                $entry_reference = $entry;
                break;
            }

            break;
        }
        unset($entry);

        if (!$updated) {
            return false;
        }

        self::save_queue($queue);

        if ($entry_reference !== null) {
            $is_resolved = self::is_entry_resolved($entry_reference);
            if (!$was_resolved && $is_resolved) {
                self::log_resolution_event($entry_reference, $actor_label, $entry_reference['resolution_notes'] ?? '');
            }
        }

        return true;
    }

    /**
     * Marks an entire entry as resolved.
     *
     * @param string      $entry_id
     * @param int|null    $user_id
     * @param string|null $notes
     */
    public static function resolve_entry($entry_id, $user_id = null, $notes = '') {
        $entry_id = is_string($entry_id) ? trim($entry_id) : '';
        if ($entry_id === '') {
            return false;
        }

        $queue = self::get_queue();
        $now = time();
        $actor_label = self::resolve_actor_name($user_id);
        $sanitized_notes = self::sanitize_notes_value($notes ?? '');
        $updated = false;
        $was_resolved = false;
        $entry_reference = null;

        foreach ($queue as &$entry) {
            if (!is_array($entry) || !isset($entry['id']) || (string) $entry['id'] !== $entry_id) {
                continue;
            }

            $was_resolved = self::is_entry_resolved($entry);

            $entry['acknowledged_at'] = $entry['acknowledged_at'] ?? $now;
            $entry['resolved_at'] = $now;
            if ($actor_label !== '') {
                $entry['acknowledged_by'] = $actor_label;
            }
            if ($sanitized_notes !== '') {
                $entry['resolution_notes'] = $sanitized_notes;
            }

            if (!empty($entry['channels']) && is_array($entry['channels'])) {
                foreach ($entry['channels'] as $key => &$channel) {
                    if (!is_array($channel)) {
                        continue;
                    }

                    $channel['acknowledged_at'] = $channel['acknowledged_at'] ?? $now;
                    $channel['resolved_at'] = $now;
                    if ($actor_label !== '') {
                        $channel['acknowledged_by'] = $actor_label;
                    }

                    if ($sanitized_notes !== '') {
                        $channel['resolution_notes'] = $sanitized_notes;
                    }
                }
                unset($channel);
            }

            if (!isset($entry['resolution']) || !is_array($entry['resolution'])) {
                $entry['resolution'] = self::normalize_resolution([]);
            }
            if (empty($entry['resolution']['acknowledged_at'])) {
                $entry['resolution']['acknowledged_at'] = $now;
            }
            $entry['resolution']['resolved_at'] = $now;

            $entry['updated_at'] = $now;
            self::refresh_resolution_metadata($entry);
            $entry_reference = $entry;
            $updated = true;
            break;
        }
        unset($entry);

        if (!$updated) {
            return false;
        }

        self::save_queue($queue);

        if ($entry_reference !== null) {
            $is_resolved = self::is_entry_resolved($entry_reference);
            if (!$was_resolved && $is_resolved) {
                self::log_resolution_event($entry_reference, $actor_label, $entry_reference['resolution_notes'] ?? '');
            }
        }

        return true;
    }

    private static function min_time_value($current, $candidate) {
        $candidate = (int) $candidate;
        if ($candidate <= 0) {
            return $current;
        }

        if ($current === null) {
            return $candidate;
        }

        $current = (int) $current;
        if ($current <= 0) {
            return $candidate;
        }

        return min($current, $candidate);
    }

    /**
     * Processes the queued notifications when triggered by WP-Cron.
     */
    public function process_queue() {
        if ($this->is_locked()) {
            return;
        }

        $this->lock();

        try {
            $queue = self::get_queue();
            if (empty($queue)) {
                return;
            }

            $now = time();
            $updated_queue = [];
            $processed = 0;

            foreach ($queue as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if ($processed >= self::MAX_ENTRIES_PER_RUN) {
                    $updated_queue[] = $entry;
                    continue;
                }

                $next_attempt_at = isset($entry['next_attempt_at']) ? (int) $entry['next_attempt_at'] : 0;
                if ($next_attempt_at > $now) {
                    $updated_queue[] = $entry;
                    continue;
                }

                $result = $this->handle_entry($entry, $now);

                if ($result['completed']) {
                    $this->log_entry_completion($result['entry']);
                    continue;
                }

                $updated_queue[] = $result['entry'];

                if ($result['attempted']) {
                    $processed++;
                }
            }

            self::save_queue($updated_queue);
        } finally {
            $this->unlock();
        }

        $remaining = self::get_queue();
        if (!empty($remaining)) {
            $delay = $this->get_next_delay($remaining);
            wp_schedule_single_event(time() + $delay, self::HOOK);
        }
    }

    /**
     * Handles the delivery of a single queue entry.
     *
     * @param array<string,mixed> $entry
     *
     * @return array{entry:array<string,mixed>,completed:bool,attempted:bool}
     */
    private function handle_entry(array $entry, $now) {
        $attempted = false;
        $channels = isset($entry['channels']) && is_array($entry['channels']) ? $entry['channels'] : [];
        $all_terminal = true;
        $next_attempt_at = null;

        foreach ($channels as $channel_key => &$channel) {
            $channel = $this->normalize_channel($channel);

            if (empty($channel['enabled'])) {
                $channel['status'] = 'disabled';
                continue;
            }

            $status = $channel['status'];
            if (in_array($status, ['completed', 'failed'], true)) {
                continue;
            }

            $ready_at = isset($channel['next_attempt_at']) ? (int) $channel['next_attempt_at'] : $now;
            if ($ready_at > $now) {
                $all_terminal = false;
                $next_attempt_at = $this->min_time($next_attempt_at, $ready_at);
                continue;
            }

            $attempted = true;
            $result = $this->send_channel($channel_key, $entry, $channel);

            if (!is_array($result)) {
                $result = ['success' => false, 'message' => __('Erreur inconnue lors de l\'envoi.', 'backup-jlg')];
            }

            if (!empty($result['success'])) {
                $channel['status'] = 'completed';
                $channel['completed_at'] = $now;
                $channel['last_error'] = '';
                $this->log_channel_success($channel_key, $entry);
            } else {
                $channel['attempts']++;
                $channel['last_error'] = isset($result['message']) ? trim((string) $result['message']) : '';
                $channel['last_error_at'] = $now;

                if ($channel['attempts'] >= self::MAX_ATTEMPTS) {
                    $channel['status'] = 'failed';
                    $channel['failed_at'] = $now;
                    $this->log_channel_failure($channel_key, $entry, $channel['last_error']);
                } else {
                    $channel['status'] = 'retry';
                    $channel['next_attempt_at'] = $now + $this->compute_backoff($channel['attempts']);
                    $all_terminal = false;
                    $next_attempt_at = $this->min_time($next_attempt_at, $channel['next_attempt_at']);
                    $this->log_channel_retry($channel_key, $entry, $channel['last_error'], $channel['attempts']);
                }
            }
        }
        unset($channel);

        $entry['channels'] = $channels;
        $entry['last_attempt_at'] = $attempted ? $now : ($entry['last_attempt_at'] ?? 0);

        if ($next_attempt_at !== null) {
            $entry['next_attempt_at'] = $next_attempt_at;
        } else {
            $entry['next_attempt_at'] = $all_terminal ? $now : ($now + $this->compute_backoff(1));
        }

        $entry['updated_at'] = $now;

        return [
            'entry' => $entry,
            'completed' => $this->all_channels_terminal($channels),
            'attempted' => $attempted,
        ];
    }

    /**
     * Sends the notification via a given channel.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $channel
     *
     * @return array{success:bool,message?:string}
     */
    private function send_channel($channel_key, array $entry, array $channel) {
        $title = isset($entry['title']) ? (string) $entry['title'] : '';
        $lines = isset($entry['lines']) && is_array($entry['lines']) ? $entry['lines'] : [];
        $subject = isset($entry['subject']) ? (string) $entry['subject'] : $title;
        $body = isset($entry['body']) ? (string) $entry['body'] : implode("\n", $lines);

        switch ($channel_key) {
            case 'email':
                $recipients = isset($channel['recipients']) && is_array($channel['recipients']) ? $channel['recipients'] : [];
                return BJLG_Notification_Transport::send_email($recipients, $subject, $body);
            case 'slack':
                $webhook = isset($channel['webhook_url']) ? (string) $channel['webhook_url'] : '';
                return BJLG_Notification_Transport::send_slack($webhook, $title, $lines);
            case 'discord':
                $webhook = isset($channel['webhook_url']) ? (string) $channel['webhook_url'] : '';
                return BJLG_Notification_Transport::send_discord($webhook, $title, $lines);
            case 'teams':
                $webhook = isset($channel['webhook_url']) ? (string) $channel['webhook_url'] : '';
                return BJLG_Notification_Transport::send_teams($webhook, $title, $lines);
            case 'sms':
                $webhook = isset($channel['webhook_url']) ? (string) $channel['webhook_url'] : '';
                return BJLG_Notification_Transport::send_sms($webhook, $title, $lines);
            case 'internal':
                return ['success' => true];
        }

        return [
            'success' => false,
            'message' => sprintf(__('Canal inconnu : %s', 'backup-jlg'), $channel_key),
        ];
    }

    /**
     * Computes the delay before retrying a failed delivery.
     */
    private function compute_backoff($attempts) {
        $attempts = max(1, (int) $attempts);
        $base = 60; // seconds
        $max = 15 * MINUTE_IN_SECONDS;
        $delay = $base * (2 ** ($attempts - 1));

        return (int) min($delay, $max);
    }

    /**
     * Determines the delay before the next scheduled run based on pending channels.
     *
     * @param array<int,array<string,mixed>> $queue
     */
    private function get_next_delay(array $queue) {
        $now = time();
        $next = null;

        foreach ($queue as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $candidate = isset($entry['next_attempt_at']) ? (int) $entry['next_attempt_at'] : 0;
            if ($candidate > $now) {
                $next = $this->min_time($next, $candidate);
            }
        }

        if ($next === null) {
            return MINUTE_IN_SECONDS;
        }

        return max(15, $next - $now);
    }

    /**
     * Returns the smallest positive timestamp between the provided value and the candidate.
     *
     * @param int|null $current
     * @param int      $candidate
     *
     * @return int|null
     */
    private function min_time($current, $candidate) {
        if ($candidate <= 0) {
            return $current;
        }

        if ($current === null) {
            return $candidate;
        }

        return min($current, $candidate);
    }

    /**
     * Checks whether all channels are in a terminal state.
     *
     * @param array<string,array<string,mixed>> $channels
     */
    private function all_channels_terminal(array $channels) {
        foreach ($channels as $channel) {
            if (empty($channel['enabled'])) {
                continue;
            }

            $status = isset($channel['status']) ? (string) $channel['status'] : 'pending';
            if (!in_array($status, ['completed', 'failed', 'disabled'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalizes the structure of a channel entry.
     *
     * @param array<string,mixed> $channel
     *
     * @return array<string,mixed>
     */
    private function normalize_channel($channel) {
        if (!is_array($channel)) {
            $channel = [];
        }

        $channel['enabled'] = !empty($channel['enabled']);
        $channel['status'] = isset($channel['status']) ? (string) $channel['status'] : 'pending';
        $channel['attempts'] = isset($channel['attempts']) ? (int) $channel['attempts'] : 0;

        return $channel;
    }

    /**
     * Logs a successful delivery in the plugin history.
     */
    private function log_channel_success($channel_key, array $entry) {
        if (!class_exists(BJLG_History::class)) {
            return;
        }

        $message = sprintf(
            __('Notification « %s » envoyée via %s.', 'backup-jlg'),
            isset($entry['event']) ? (string) $entry['event'] : 'event',
            $channel_key
        );

        BJLG_History::log('notification', 'success', $message);
    }

    /**
     * Logs a retry in the plugin history.
     */
    private function log_channel_retry($channel_key, array $entry, $error_message, $attempts) {
        if (!class_exists(BJLG_History::class)) {
            return;
        }

        $message = sprintf(
            __('Nouvelle tentative pour la notification « %s » via %s (#%d) : %s', 'backup-jlg'),
            isset($entry['event']) ? (string) $entry['event'] : 'event',
            $channel_key,
            $attempts,
            $error_message !== '' ? $error_message : __('erreur inconnue', 'backup-jlg')
        );

        BJLG_History::log('notification', 'warning', $message);
    }

    /**
     * Logs a definitive failure in the plugin history.
     */
    private function log_channel_failure($channel_key, array $entry, $error_message) {
        if (!class_exists(BJLG_History::class)) {
            return;
        }

        $message = sprintf(
            __('Notification « %s » abandonnée via %s : %s', 'backup-jlg'),
            isset($entry['event']) ? (string) $entry['event'] : 'event',
            $channel_key,
            $error_message !== '' ? $error_message : __('erreur inconnue', 'backup-jlg')
        );

        BJLG_History::log('notification', 'failure', $message);
    }

    /**
     * Logs the completion of a queue entry (all channels processed).
     *
     * @param array<string,mixed> $entry
     */
    private function log_entry_completion(array $entry) {
        if (!class_exists(BJLG_History::class)) {
            return;
        }

        $channels = isset($entry['channels']) && is_array($entry['channels']) ? $entry['channels'] : [];
        $states = [];
        foreach ($channels as $key => $channel) {
            if (empty($channel['enabled'])) {
                continue;
            }
            $status = isset($channel['status']) ? (string) $channel['status'] : 'pending';
            $states[] = sprintf('%s:%s', $key, $status);
        }

        if (empty($states)) {
            return;
        }

        $message = sprintf(
            __('Notification « %s » clôturée (%s).', 'backup-jlg'),
            isset($entry['event']) ? (string) $entry['event'] : 'event',
            implode(', ', $states)
        );

        BJLG_History::log('notification', 'info', $message);
    }

    /**
     * Retrieves the queue from the options table.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function get_queue() {
        $queue = bjlg_get_option(self::OPTION, []);

        if (!is_array($queue)) {
            return [];
        }

        $normalized = [];
        foreach ($queue as $entry) {
            if (is_array($entry)) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * Persists the queue in the options table.
     *
     * @param array<int,array<string,mixed>> $queue
     */
    private static function save_queue(array $queue) {
        bjlg_update_option(self::OPTION, array_values($queue), null, null, false);
    }

    /**
     * Normalizes the structure of an entry to avoid missing keys.
     *
     * @param array<string,mixed> $entry
     *
     * @return array<string,mixed>
     */
    private static function normalize_entry(array $entry) {
        if (empty($entry['channels']) || !is_array($entry['channels'])) {
            return [];
        }

        $now = time();
        $normalized = [
            'id' => isset($entry['id']) ? (string) $entry['id'] : uniqid('bjlg_notif_', true),
            'event' => isset($entry['event']) ? (string) $entry['event'] : 'event',
            'title' => isset($entry['title']) ? (string) $entry['title'] : '',
            'subject' => isset($entry['subject']) ? (string) $entry['subject'] : '',
            'lines' => isset($entry['lines']) && is_array($entry['lines']) ? array_values($entry['lines']) : [],
            'body' => isset($entry['body']) ? (string) $entry['body'] : '',
            'context' => isset($entry['context']) && is_array($entry['context']) ? $entry['context'] : [],
            'created_at' => isset($entry['created_at']) ? (int) $entry['created_at'] : $now,
            'next_attempt_at' => isset($entry['next_attempt_at']) ? (int) $entry['next_attempt_at'] : $now,
            'channels' => [],
            'severity' => self::normalize_severity_value($entry['severity'] ?? ''),
            'acknowledged_by' => self::sanitize_actor_label($entry['acknowledged_by'] ?? ''),
            'acknowledged_at' => isset($entry['acknowledged_at']) ? (int) $entry['acknowledged_at'] : 0,
            'resolved_at' => isset($entry['resolved_at']) ? (int) $entry['resolved_at'] : 0,
            'resolution_notes' => self::sanitize_notes_value($entry['resolution_notes'] ?? ''),
        ];

        $normalized['resolution'] = self::normalize_resolution(
            isset($entry['resolution']) && is_array($entry['resolution']) ? $entry['resolution'] : []
        );

        self::refresh_resolution_metadata($normalized);

        $normalized['reminders'] = self::normalize_reminders($entry, $normalized['severity'], $now);

        if (isset($entry['quiet_until'])) {
            $normalized['quiet_until'] = (int) $entry['quiet_until'];
        }

        if (isset($entry['quiet_hours']) && is_array($entry['quiet_hours'])) {
            $normalized['quiet_hours'] = $entry['quiet_hours'];
        }

        if (isset($entry['escalation']) && is_array($entry['escalation'])) {
            $channels = isset($entry['escalation']['channels']) && is_array($entry['escalation']['channels'])
                ? array_map('sanitize_key', $entry['escalation']['channels'])
                : [];

            $normalized['escalation'] = [
                'channels' => array_values(array_filter($channels)),
                'delay' => isset($entry['escalation']['delay']) ? (int) $entry['escalation']['delay'] : 0,
                'only_critical' => !empty($entry['escalation']['only_critical']),
            ];

            if (isset($entry['escalation']['strategy'])) {
                $strategy = sanitize_key((string) $entry['escalation']['strategy']);
                if ($strategy !== '') {
                    $normalized['escalation']['strategy'] = $strategy;
                }
            }

            if (!empty($entry['escalation']['steps']) && is_array($entry['escalation']['steps'])) {
                $steps = [];
                foreach ($entry['escalation']['steps'] as $step) {
                    if (!is_array($step)) {
                        continue;
                    }

                    $step_channels = [];
                    if (!empty($step['channels']) && is_array($step['channels'])) {
                        foreach ($step['channels'] as $channel_key) {
                            $channel_key = sanitize_key((string) $channel_key);
                            if ($channel_key !== '') {
                                $step_channels[] = $channel_key;
                            }
                        }
                    }

                    $steps[] = [
                        'label' => isset($step['label']) ? sanitize_text_field((string) $step['label']) : '',
                        'channels' => array_values(array_unique($step_channels)),
                        'delay' => isset($step['delay']) ? (int) $step['delay'] : 0,
                    ];
                }

                if (!empty($steps)) {
                    $normalized['escalation']['steps'] = $steps;
                }
            }
        }

        foreach ($entry['channels'] as $key => $channel) {
            if (!is_string($key)) {
                continue;
            }

            $normalized['channels'][$key] = [
                'enabled' => !empty($channel['enabled']),
                'status' => isset($channel['status']) ? (string) $channel['status'] : 'pending',
                'attempts' => isset($channel['attempts']) ? (int) $channel['attempts'] : 0,
                'acknowledged_by' => self::sanitize_actor_label($channel['acknowledged_by'] ?? ''),
                'acknowledged_at' => isset($channel['acknowledged_at']) ? (int) $channel['acknowledged_at'] : 0,
                'resolved_at' => isset($channel['resolved_at']) ? (int) $channel['resolved_at'] : 0,
                'resolution_notes' => self::sanitize_notes_value($channel['resolution_notes'] ?? ''),
            ];

            if (isset($channel['recipients']) && is_array($channel['recipients'])) {
                $normalized['channels'][$key]['recipients'] = array_values($channel['recipients']);
            }

            if (isset($channel['webhook_url'])) {
                $normalized['channels'][$key]['webhook_url'] = (string) $channel['webhook_url'];
            }

            if (isset($channel['next_attempt_at'])) {
                $normalized['channels'][$key]['next_attempt_at'] = (int) $channel['next_attempt_at'];
            }

            if (!empty($channel['escalation'])) {
                $normalized['channels'][$key]['escalation'] = true;
            }
        }

        if (empty($normalized['channels'])) {
            return [];
        }

        return $normalized;
    }

    private static function normalize_resolution(array $resolution, array $fallback = []) {
        $source = !empty($resolution) ? $resolution : $fallback;

        $normalized = [
            'acknowledged_at' => null,
            'resolved_at' => null,
            'steps' => [],
            'summary' => '',
        ];

        if (isset($source['acknowledged_at']) && (int) $source['acknowledged_at'] > 0) {
            $normalized['acknowledged_at'] = (int) $source['acknowledged_at'];
        }

        if (isset($source['resolved_at']) && (int) $source['resolved_at'] > 0) {
            $normalized['resolved_at'] = (int) $source['resolved_at'];
        }

        if (!empty($source['steps']) && is_array($source['steps'])) {
            foreach ($source['steps'] as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $normalized['steps'][] = self::sanitize_resolution_step($step);
            }
        }

        $normalized['summary'] = self::summarize_resolution_steps($normalized['steps']);

        return $normalized;
    }

    private static function sanitize_resolution_step(array $step) {
        $timestamp = isset($step['timestamp']) ? (int) $step['timestamp'] : time();
        $actor = isset($step['actor']) ? (string) $step['actor'] : '';
        $summary = isset($step['summary']) ? (string) $step['summary'] : '';
        $type = isset($step['type']) ? (string) $step['type'] : 'update';

        if ($actor === '') {
            $actor = __('Système', 'backup-jlg');
        }

        if ($summary === '') {
            $summary = __('Mise à jour enregistrée.', 'backup-jlg');
        }

        return [
            'timestamp' => $timestamp,
            'actor' => $actor,
            'summary' => $summary,
            'type' => $type !== '' ? $type : 'update',
        ];
    }

    /**
     * Builds a concise summary of resolution steps suitable for history/escalation payloads.
     *
     * @param array<int,array<string,mixed>> $steps
     */
    public static function summarize_resolution_steps(array $steps): string {
        if (empty($steps)) {
            return '';
        }

        $lines = [];

        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $timestamp = isset($step['timestamp']) ? (int) $step['timestamp'] : 0;
            $formatted_time = $timestamp > 0 ? self::format_resolution_timestamp($timestamp) : '';
            $actor = isset($step['actor']) ? self::sanitize_actor_label($step['actor']) : '';
            if ($actor === '') {
                $actor = __('Système', 'backup-jlg');
            }

            $summary = isset($step['summary']) ? trim((string) $step['summary']) : '';
            if ($summary === '') {
                continue;
            }

            if ($formatted_time !== '') {
                $lines[] = sprintf('%1$s — %2$s : %3$s', $formatted_time, $actor, $summary);
            } else {
                $lines[] = sprintf('%1$s : %2$s', $actor, $summary);
            }
        }

        return implode("\n", $lines);
    }

    private static function format_resolution_timestamp($timestamp) {
        $timestamp = (int) $timestamp;
        if ($timestamp <= 0) {
            return '';
        }

        $date_format = function_exists('get_option') ? get_option('date_format', 'Y-m-d') : 'Y-m-d';
        $time_format = function_exists('get_option') ? get_option('time_format', 'H:i') : 'H:i';
        $format = trim($date_format . ' ' . $time_format);

        if (function_exists('wp_date')) {
            return wp_date($format, $timestamp);
        }

        if (function_exists('date_i18n')) {
            return date_i18n($format, $timestamp);
        }

        return date($format, $timestamp);
    }

    private static function normalize_reminders(array $entry, $severity, $now) {
        $reminders = isset($entry['reminders']) && is_array($entry['reminders']) ? $entry['reminders'] : [];
        $base = isset($reminders['base_interval']) && (int) $reminders['base_interval'] > 0
            ? (int) $reminders['base_interval']
            : self::get_base_reminder_interval((string) $severity, $entry);

        $attempts = isset($reminders['attempts']) ? max(0, (int) $reminders['attempts']) : 0;
        $next = isset($reminders['next_at']) ? (int) $reminders['next_at'] : 0;
        if ($next <= $now) {
            $next = $now + max($base, 60);
        }

        return [
            'attempts' => $attempts,
            'base_interval' => $base,
            'next_at' => $next,
            'last_triggered_at' => isset($reminders['last_triggered_at']) ? (int) $reminders['last_triggered_at'] : 0,
            'active' => isset($reminders['active']) ? (bool) $reminders['active'] : true,
            'backoff_multiplier' => isset($reminders['backoff_multiplier']) && (float) $reminders['backoff_multiplier'] > 0
                ? (float) $reminders['backoff_multiplier']
                : 2.0,
            'max_interval' => isset($reminders['max_interval']) && (int) $reminders['max_interval'] > 0
                ? (int) $reminders['max_interval']
                : self::MAX_REMINDER_INTERVAL,
        ];
    }

    private static function get_base_reminder_interval($severity, array $entry) {
        $minute = 60;
        $severity = is_string($severity) ? strtolower($severity) : 'info';

        switch ($severity) {
            case 'critical':
                $base = 5 * $minute;
                break;
            case 'warning':
                $base = 10 * $minute;
                break;
            default:
                $base = self::DEFAULT_REMINDER_INTERVAL;
                break;
        }

        $filtered = apply_filters('bjlg_notification_reminder_base_interval', $base, $severity, $entry);

        if (!is_int($filtered)) {
            $filtered = (int) $filtered;
        }

        if ($filtered <= 0) {
            $filtered = $base;
        }

        return max(60, $filtered);
    }

    private static function compute_reminder_delay($attempt, array $entry) {
        $attempt = max(0, (int) $attempt);
        $reminders = isset($entry['reminders']) && is_array($entry['reminders']) ? $entry['reminders'] : [];
        $base = isset($reminders['base_interval']) && (int) $reminders['base_interval'] > 0
            ? (int) $reminders['base_interval']
            : self::get_base_reminder_interval($entry['severity'] ?? 'info', $entry);

        $reminder_config = isset($entry['reminders']) && is_array($entry['reminders']) ? $entry['reminders'] : [];
        $multiplier_value = isset($reminder_config['backoff_multiplier']) && (float) $reminder_config['backoff_multiplier'] > 0
            ? (float) $reminder_config['backoff_multiplier']
            : 2.0;
        $max_interval = isset($reminder_config['max_interval']) && (int) $reminder_config['max_interval'] > 0
            ? (int) $reminder_config['max_interval']
            : self::MAX_REMINDER_INTERVAL;

        $growth = max(1.0, $multiplier_value);
        $delay = (int) round($base * ($growth ** $attempt));
        $delay = min($max_interval, max($base, $delay));

        $delay = apply_filters('bjlg_notification_reminder_backoff', $delay, $attempt, $entry);
        if (!is_int($delay)) {
            $delay = (int) $delay;
        }

        if ($delay <= 0) {
            $delay = $base;
        }

        return max(60, $delay);
    }

    private static function refresh_resolution_metadata(array &$entry) {
        if (!isset($entry['resolution']) || !is_array($entry['resolution'])) {
            $entry['resolution'] = self::normalize_resolution([]);
        }

        if (!isset($entry['resolution']['summary']) || !is_string($entry['resolution']['summary'])) {
            $entry['resolution']['summary'] = self::summarize_resolution_steps($entry['resolution']['steps'] ?? []);
        }

        $entry['resolution_status'] = self::derive_resolution_status($entry['resolution']);
        $entry['resolution_summary'] = trim((string) ($entry['resolution']['summary'] ?? ''));
    }

    private static function derive_resolution_status(array $resolution) {
        $resolved_at = isset($resolution['resolved_at']) ? (int) $resolution['resolved_at'] : 0;
        if ($resolved_at > 0) {
            return 'resolved';
        }

        $acknowledged_at = isset($resolution['acknowledged_at']) ? (int) $resolution['acknowledged_at'] : 0;
        if ($acknowledged_at > 0) {
            return 'acknowledged';
        }

        return 'pending';
    }

    private static function schedule_entry_reminder(array $entry, $attempt) {
        if (!class_exists(__NAMESPACE__ . '\\BJLG_Notification_Receipts')) {
            return;
        }

        $entry_id = isset($entry['id']) ? (string) $entry['id'] : '';
        if ($entry_id === '') {
            return;
        }

        $reminders = isset($entry['reminders']) && is_array($entry['reminders']) ? $entry['reminders'] : [];
        if (isset($reminders['active']) && $reminders['active'] === false) {
            return;
        }

        if (BJLG_Notification_Receipts::is_acknowledged($entry_id) || BJLG_Notification_Receipts::is_resolved($entry_id)) {
            self::deactivate_reminders_for_entry($entry_id);
            return;
        }

        $next_at = isset($reminders['next_at']) ? (int) $reminders['next_at'] : 0;
        if ($next_at <= time()) {
            $next_at = time() + self::compute_reminder_delay($attempt, $entry);
        }

        wp_schedule_single_event($next_at, self::REMINDER_HOOK, [$entry_id]);
    }

    private static function deactivate_reminders_for_entry($entry_id) {
        $queue = self::get_queue();
        $updated = false;

        foreach ($queue as &$entry) {
            if (!is_array($entry) || (string) ($entry['id'] ?? '') !== $entry_id) {
                continue;
            }

            if (!isset($entry['reminders']) || !is_array($entry['reminders'])) {
                $entry['reminders'] = [];
            }

            if (empty($entry['reminders']['active'])) {
                break;
            }

            $entry['reminders']['active'] = false;
            $entry['reminders']['next_at'] = 0;
            $updated = true;
            break;
        }
        unset($entry);

        if ($updated) {
            self::save_queue($queue);
        }
    }

    public static function update_resolution($entry_id, array $resolution) {
        $entry_id = is_string($entry_id) ? trim($entry_id) : '';
        if ($entry_id === '') {
            return;
        }

        $queue = self::get_queue();
        $updated = false;

        foreach ($queue as &$entry) {
            if (!is_array($entry) || (string) ($entry['id'] ?? '') !== $entry_id) {
                continue;
            }

            $entry['resolution'] = self::normalize_resolution($resolution, isset($entry['resolution']) && is_array($entry['resolution']) ? $entry['resolution'] : []);
            $entry['updated_at'] = time();
            self::refresh_resolution_metadata($entry);
            if (!empty($entry['resolution']['acknowledged_at']) || !empty($entry['resolution']['resolved_at'])) {
                if (!isset($entry['reminders']) || !is_array($entry['reminders'])) {
                    $entry['reminders'] = [];
                }
                $entry['reminders']['active'] = false;
                $entry['reminders']['next_at'] = 0;
            }
            $updated = true;
            break;
        }
        unset($entry);

        if ($updated) {
            self::save_queue($queue);
        }
    }

    public function handle_reminder($entry_id) {
        $entry_id = is_string($entry_id) ? trim($entry_id) : '';
        if ($entry_id === '' || !class_exists(__NAMESPACE__ . '\\BJLG_Notification_Receipts')) {
            return;
        }

        if (BJLG_Notification_Receipts::is_acknowledged($entry_id) || BJLG_Notification_Receipts::is_resolved($entry_id)) {
            self::deactivate_reminders_for_entry($entry_id);
            return;
        }

        $queue = self::get_queue();
        $updated = false;

        foreach ($queue as &$entry) {
            if (!is_array($entry) || (string) ($entry['id'] ?? '') !== $entry_id) {
                continue;
            }

            $reminders = isset($entry['reminders']) && is_array($entry['reminders']) ? $entry['reminders'] : [];
            if (isset($reminders['active']) && $reminders['active'] === false) {
                break;
            }

            $attempt = isset($reminders['attempts']) ? (int) $reminders['attempts'] : 0;
            $attempt++;
            $now = time();
            $reminders['attempts'] = $attempt;
            $reminders['last_triggered_at'] = $now;
            $reminders['next_at'] = $now + self::compute_reminder_delay($attempt, $entry);
            $entry['reminders'] = $reminders;
            $entry['updated_at'] = $now;
            $updated = true;

            $this->log_channel_retry('reminder', $entry, __('Accusé de réception toujours en attente.', 'backup-jlg'), $attempt);

            self::schedule_entry_reminder($entry, $attempt);
            break;
        }
        unset($entry);

        if ($updated) {
            self::save_queue($queue);
        }
    }

    private static function normalize_severity_value($value) {
        if (is_string($value)) {
            $candidate = strtolower(trim($value));
            if (in_array($candidate, self::VALID_SEVERITIES, true)) {
                return $candidate;
            }
        }

        return 'info';
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

    private static function resolve_actor_name($user_id = null) {
        $user = null;

        if ($user_id !== null && is_numeric($user_id) && (int) $user_id > 0) {
            if (function_exists('get_user_by')) {
                $user = get_user_by('id', (int) $user_id);
            } elseif (function_exists('get_userdata')) {
                $user = get_userdata((int) $user_id);
            }
        }

        if ($user === null && function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
        }

        if (is_object($user)) {
            if (!empty($user->display_name)) {
                return self::sanitize_actor_label($user->display_name);
            }

            if (!empty($user->user_login)) {
                return self::sanitize_actor_label($user->user_login);
            }

            if (!empty($user->user_email)) {
                return self::sanitize_actor_label($user->user_email);
            }
        }

        if ($user_id !== null && is_numeric($user_id) && (int) $user_id > 0) {
            return self::sanitize_actor_label(sprintf(__('Utilisateur #%d', 'backup-jlg'), (int) $user_id));
        }

        return '';
    }

    private static function sanitize_actor_label($value) {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return sanitize_text_field($value);
    }

    private static function sanitize_notes_value($value) {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $note) {
                $sanitized = self::sanitize_notes_value($note);
                if ($sanitized !== '') {
                    $parts[] = $sanitized;
                }
            }

            return implode("\n", $parts);
        }

        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (function_exists('sanitize_textarea_field')) {
            return sanitize_textarea_field($value);
        }

        return sanitize_text_field($value);
    }

    private static function append_resolution_note($existing, $channel_key, $note) {
        $existing = self::sanitize_notes_value($existing);
        $note = self::sanitize_notes_value($note);

        if ($note === '') {
            return $existing;
        }

        $label = sanitize_key((string) $channel_key);
        if ($label !== '') {
            $note = sprintf('%s: %s', $label, $note);
        }

        if ($existing === '') {
            return $note;
        }

        return $existing . "\n" . $note;
    }

    private static function are_all_channels_resolved($channels) {
        if (!is_array($channels) || empty($channels)) {
            return false;
        }

        $has_enabled = false;

        foreach ($channels as $channel) {
            if (!is_array($channel)) {
                continue;
            }

            if (isset($channel['enabled']) && !$channel['enabled']) {
                continue;
            }

            $has_enabled = true;
            $resolved_at = isset($channel['resolved_at']) ? (int) $channel['resolved_at'] : 0;
            if ($resolved_at <= 0) {
                return false;
            }
        }

        return $has_enabled;
    }

    private static function is_entry_resolved(array $entry) {
        $resolved_at = isset($entry['resolved_at']) ? (int) $entry['resolved_at'] : 0;
        if ($resolved_at > 0) {
            return true;
        }

        $channels = isset($entry['channels']) && is_array($entry['channels']) ? $entry['channels'] : [];

        return self::are_all_channels_resolved($channels);
    }

    private static function log_resolution_event(array $entry, $actor_label, $notes) {
        $actor_label = self::sanitize_actor_label($actor_label);
        $notes = self::sanitize_notes_value($notes);

        $title = isset($entry['title']) ? (string) $entry['title'] : '';
        if ($title === '' && !empty($entry['event'])) {
            $title = (string) $entry['event'];
        }
        if ($title === '' && !empty($entry['id'])) {
            $title = (string) $entry['id'];
        }

        if ($actor_label === '') {
            $actor_label = __('un opérateur', 'backup-jlg');
        }

        $summary = '';

        if (class_exists(BJLG_Notification_Receipts::class) && !empty($entry['id'])) {
            $receipt = BJLG_Notification_Receipts::get($entry['id']);
            if (is_array($receipt) && !empty($receipt['steps'])) {
                $summary = self::summarize_resolution_steps($receipt['steps']);
            }
        }

        if ($summary === '' && !empty($entry['resolution_summary'])) {
            $summary = (string) $entry['resolution_summary'];
        }

        if ($summary === '' && $notes !== '') {
            $summary = $notes;
        }

        $summary = self::sanitize_notes_value($summary);

        if (class_exists(BJLG_History::class)) {
            $message = sprintf(
                __('Notification « %s » résolue par %s.', 'backup-jlg'),
                $title !== '' ? $title : __('notification', 'backup-jlg'),
                $actor_label
            );

            if ($summary !== '') {
                $message .= ' ' . sprintf(__('Résumé : %s', 'backup-jlg'), $summary);
            }

            BJLG_History::log('notification_resolved', 'info', $message);
        }

        /**
         * Fires when a notification queue entry has been fully resolved.
         *
         * @param array<string,mixed> $entry
         */
        do_action('bjlg_notification_resolved', $entry);
    }
}
