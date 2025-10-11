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
    private const LOCK_TRANSIENT = 'bjlg_notification_queue_lock';
    private const LOCK_DURATION = 45; // seconds
    private const MAX_ATTEMPTS = 5;
    private const MAX_ENTRIES_PER_RUN = 5;

    public function __construct() {
        add_action('init', [$this, 'ensure_schedule']);
        add_action(self::HOOK, [$this, 'process_queue']);
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
            return;
        }

        $queue = self::get_queue();
        $queue[] = $normalized;
        self::save_queue($queue);

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + 15, self::HOOK);
        }

        do_action('bjlg_notification_queued', $normalized);
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
        $queue = get_option(self::OPTION, []);

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
        update_option(self::OPTION, array_values($queue), false);
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
        ];

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
        }

        foreach ($entry['channels'] as $key => $channel) {
            if (!is_string($key)) {
                continue;
            }

            $normalized['channels'][$key] = [
                'enabled' => !empty($channel['enabled']),
                'status' => isset($channel['status']) ? (string) $channel['status'] : 'pending',
                'attempts' => isset($channel['attempts']) ? (int) $channel['attempts'] : 0,
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

    private function is_locked() {
        return (bool) get_transient(self::LOCK_TRANSIENT);
    }

    private function lock() {
        set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_DURATION);
    }

    private function unlock() {
        delete_transient(self::LOCK_TRANSIENT);
    }
}
