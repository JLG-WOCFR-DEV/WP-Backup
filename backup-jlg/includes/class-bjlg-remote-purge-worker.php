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

            $processed = 0;
            foreach ($queue as $entry) {
                if ($processed >= self::MAX_ENTRIES_PER_RUN) {
                    break;
                }

                if (!is_array($entry)) {
                    continue;
                }

                $status = isset($entry['status']) ? (string) $entry['status'] : 'pending';
                if (!in_array($status, ['pending', 'failed'], true)) {
                    continue;
                }

                $handled = $this->handle_queue_entry($incremental, $entry);
                if ($handled) {
                    $processed++;
                }
            }
        } finally {
            $this->unlock();
        }

        $remaining = (new BJLG_Incremental())->get_remote_purge_queue();
        if (!empty($remaining)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK);
        }
    }

    /**
     * Traite une entrée de la file.
     *
     * @param array<string,mixed> $entry
     */
    private function handle_queue_entry(BJLG_Incremental $incremental, array $entry) {
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
        $incremental->update_remote_purge_entry($file, [
            'status' => 'processing',
            'attempts' => $attempts + 1,
            'last_attempt_at' => time(),
        ]);

        $success = [];
        $errors = [];

        foreach ($destinations as $destination_id) {
            $destination = BJLG_Destination_Factory::create($destination_id);
            if (!$destination instanceof BJLG_Destination_Interface) {
                $errors[$destination_id] = sprintf(__('Destination inconnue : %s', 'backup-jlg'), $destination_id);
                continue;
            }

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

                if (class_exists(BJLG_Debug::class)) {
                    BJLG_Debug::log(sprintf('Purge distante %s (%s) : %s', $destination->get_name(), $destination_id, $message));
                }
            }
        }

        if (!empty($success)) {
            $incremental->mark_remote_purge_completed($file, $success);
        }

        $remaining = array_values(array_diff($destinations, $success));

        if (!empty($errors) && !empty($remaining)) {
            $incremental->update_remote_purge_entry($file, [
                'status' => 'failed',
                'errors' => $errors,
                'last_error' => implode(' | ', array_values($errors)),
            ]);
        } elseif (empty($remaining)) {
            // Tout est supprimé, s'assurer que l'entrée ne laisse pas d'erreur résiduelle.
            $incremental->update_remote_purge_entry($file, [
                'status' => 'completed',
                'errors' => [],
                'last_error' => '',
            ]);
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
}
