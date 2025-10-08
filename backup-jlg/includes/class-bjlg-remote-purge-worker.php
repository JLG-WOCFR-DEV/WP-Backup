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

    public function __construct() {
        add_action(self::HOOK, [$this, 'process_queue']);
        add_action('bjlg_incremental_remote_purge', [$this, 'schedule_async_processing'], 10, 2);
        add_action('init', [$this, 'ensure_schedule']);
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
                return;
            }

            $now = time();
            $processed = 0;
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
                if ($handled) {
                    $processed++;
                }
            }
        } finally {
            $this->unlock();
        }

        $remaining = (new BJLG_Incremental())->get_remote_purge_queue();
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
            return false;
        }

        $destinations = isset($entry['destinations']) && is_array($entry['destinations'])
            ? array_values($entry['destinations'])
            : [];

        if (empty($destinations)) {
            return false;
        }

        $attempts = isset($entry['attempts']) ? (int) $entry['attempts'] : 0;
        $current_attempt = $attempts + 1;
        $incremental->update_remote_purge_entry($file, [
            'status' => 'processing',
            'attempts' => $current_attempt,
            'last_attempt_at' => $now,
            'next_attempt_at' => 0,
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
            ]);

            do_action('bjlg_remote_purge_completed', $file, [
                'destinations' => $success,
                'attempts' => $current_attempt,
            ]);

            return true;
        }

        if (!empty($errors)) {
            $update = [
                'errors' => $errors,
                'last_error' => implode(' | ', array_values($errors)),
            ];

            $remaining_labels = [];
            foreach ($remaining as $destination_id) {
                $remaining_labels[] = $destination_names[$destination_id] ?? $destination_id;
            }

            if ($current_attempt >= self::MAX_ATTEMPTS) {
                $update['status'] = 'failed';
                $update['next_attempt_at'] = 0;
                $update['failed_at'] = $now;

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
            }
        }

        return true;
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
