<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persists acknowledgement and resolution receipts for notification incidents.
 */
class BJLG_Notification_Receipts {

    private const OPTION = 'bjlg_notification_receipts';

    /**
     * Seeds a receipt entry when a notification is queued.
     *
     * @param array<string,mixed> $entry
     */
    public static function record_creation(array $entry) {
        $entry_id = isset($entry['id']) ? (string) $entry['id'] : '';
        if ($entry_id === '') {
            return;
        }

        $receipts = self::load();
        if (!isset($receipts[$entry_id])) {
            $timestamp = isset($entry['created_at']) ? (int) $entry['created_at'] : current_time('timestamp');
            $receipts[$entry_id] = [
                'id' => $entry_id,
                'event' => isset($entry['event']) ? (string) $entry['event'] : '',
                'title' => isset($entry['title']) ? (string) $entry['title'] : '',
                'severity' => isset($entry['severity']) ? (string) $entry['severity'] : 'info',
                'created_at' => $timestamp,
                'acknowledged_at' => null,
                'resolved_at' => null,
                'steps' => [self::sanitize_step([
                    'timestamp' => $timestamp,
                    'actor' => __('Système', 'backup-jlg'),
                    'summary' => __('Incident détecté et notification mise en file.', 'backup-jlg'),
                    'type' => 'created',
                ])],
                'last_updated_at' => $timestamp,
            ];
        } else {
            $receipts[$entry_id] = self::normalize_record($receipts[$entry_id]);
        }

        self::save($receipts);
        self::update_queue_resolution($entry_id, $receipts[$entry_id]);
    }

    /**
     * Adds a manual step to an existing receipt.
     *
     * @param string               $entry_id
     * @param array<string,string> $data
     */
    public static function add_step($entry_id, array $data) {
        $entry_id = is_string($entry_id) ? trim($entry_id) : '';
        if ($entry_id === '') {
            return;
        }

        $receipts = self::load();
        if (!isset($receipts[$entry_id])) {
            return;
        }

        $step = self::sanitize_step([
            'timestamp' => isset($data['timestamp']) ? (int) $data['timestamp'] : current_time('timestamp'),
            'actor' => $data['actor'] ?? '',
            'summary' => $data['summary'] ?? '',
            'type' => $data['type'] ?? 'update',
        ]);

        if ($step['summary'] === '') {
            return;
        }

        $receipts[$entry_id]['steps'][] = $step;
        $receipts[$entry_id]['last_updated_at'] = $step['timestamp'];

        self::save($receipts);
        self::update_queue_resolution($entry_id, $receipts[$entry_id]);
    }

    /**
     * Registers an acknowledgement for an incident.
     *
     * @return array<string,mixed>
     */
    public static function acknowledge($entry_id, $actor = null, $summary = '') {
        return self::mark_status($entry_id, 'acknowledged', $actor, $summary);
    }

    /**
     * Registers a resolution for an incident.
     *
     * @return array<string,mixed>
     */
    public static function resolve($entry_id, $actor = null, $summary = '') {
        return self::mark_status($entry_id, 'resolved', $actor, $summary);
    }

    /**
     * Retrieves a raw receipt entry.
     *
     * @return array<string,mixed>|null
     */
    public static function get($entry_id) {
        $entry_id = is_string($entry_id) ? trim($entry_id) : '';
        if ($entry_id === '') {
            return null;
        }

        $receipts = self::load();
        if (!isset($receipts[$entry_id])) {
            return null;
        }

        return self::normalize_record($receipts[$entry_id]);
    }

    /**
     * Returns all receipts indexed by notification id.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function get_all() {
        $receipts = self::load();
        foreach ($receipts as $id => $record) {
            $receipts[$id] = self::normalize_record($record);
        }

        return $receipts;
    }

    /**
     * Returns display-ready receipts ordered by most recent activity.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_recent_for_display($limit = 20) {
        $records = array_values(self::get_all());

        usort($records, static function ($a, $b) {
            $a_time = isset($a['last_updated_at']) ? (int) $a['last_updated_at'] : 0;
            $b_time = isset($b['last_updated_at']) ? (int) $b['last_updated_at'] : 0;

            if ($a_time === $b_time) {
                return strcmp($a['id'] ?? '', $b['id'] ?? '');
            }

            return $b_time <=> $a_time;
        });

        $records = array_slice($records, 0, max(1, (int) $limit));

        return array_map([self::class, 'prepare_for_display'], $records);
    }

    /**
     * Formats a receipt for UI consumption.
     *
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>
     */
    public static function prepare_for_display(array $record) {
        $record = self::normalize_record($record);
        $now = current_time('timestamp');

        $status = self::resolve_status($record);
        $formatted_steps = [];
        foreach ($record['steps'] as $step) {
            $timestamp = (int) $step['timestamp'];
            $formatted_steps[] = [
                'timestamp' => $timestamp,
                'formatted' => $timestamp > 0 ? self::format_datetime($timestamp) : '',
                'relative' => $timestamp > 0 ? self::format_relative($timestamp, $now) : '',
                'actor' => $step['actor'],
                'summary' => $step['summary'],
                'type' => $step['type'],
            ];
        }

        $ack_at = $record['acknowledged_at'] ? (int) $record['acknowledged_at'] : 0;
        $resolved_at = $record['resolved_at'] ? (int) $record['resolved_at'] : 0;

        return [
            'id' => $record['id'],
            'event' => $record['event'],
            'title' => $record['title'],
            'severity' => $record['severity'],
            'status' => $status,
            'status_label' => self::get_status_label($status),
            'created_at' => $record['created_at'],
            'created_formatted' => $record['created_at'] ? self::format_datetime((int) $record['created_at']) : '',
            'created_relative' => $record['created_at'] ? self::format_relative((int) $record['created_at'], $now) : '',
            'acknowledged_at' => $ack_at,
            'acknowledged_formatted' => $ack_at ? self::format_datetime($ack_at) : '',
            'acknowledged_relative' => $ack_at ? self::format_relative($ack_at, $now) : '',
            'resolved_at' => $resolved_at,
            'resolved_formatted' => $resolved_at ? self::format_datetime($resolved_at) : '',
            'resolved_relative' => $resolved_at ? self::format_relative($resolved_at, $now) : '',
            'steps' => $formatted_steps,
        ];
    }

    public static function is_acknowledged($entry_id) {
        $record = self::get($entry_id);
        return $record && !empty($record['acknowledged_at']);
    }

    public static function is_resolved($entry_id) {
        $record = self::get($entry_id);
        return $record && !empty($record['resolved_at']);
    }

    /**
     * Utility used in tests to reset the store.
     */
    public static function delete_all() {
        bjlg_delete_option(self::OPTION);
    }

    /**
     * @param string $entry_id
     * @param string $status
     *
     * @return array<string,mixed>
     */
    private static function mark_status($entry_id, $status, $actor, $summary) {
        $entry_id = is_string($entry_id) ? trim($entry_id) : '';
        if ($entry_id === '') {
            return [];
        }

        $receipts = self::load();
        if (!isset($receipts[$entry_id])) {
            $seeded = self::seed_from_queue($entry_id);
            if (empty($seeded)) {
                return [];
            }

            $receipts = self::load();
        }

        $record = self::normalize_record($receipts[$entry_id]);
        $timestamp = current_time('timestamp');
        $actor_label = self::build_actor_label($actor);
        $summary = is_string($summary) ? trim($summary) : '';

        if ($status === 'acknowledged' && empty($record['acknowledged_at'])) {
            $record['acknowledged_at'] = $timestamp;
            if ($summary === '') {
                $summary = __('Accusé de réception consigné.', 'backup-jlg');
            }
            $record['steps'][] = self::sanitize_step([
                'timestamp' => $timestamp,
                'actor' => $actor_label,
                'summary' => $summary,
                'type' => 'acknowledged',
            ]);
        } elseif ($status === 'resolved') {
            if ($summary === '') {
                $summary = __('Incident marqué comme résolu.', 'backup-jlg');
            }
            $record['resolved_at'] = $timestamp;
            if (empty($record['acknowledged_at'])) {
                $record['acknowledged_at'] = $timestamp;
            }
            $record['steps'][] = self::sanitize_step([
                'timestamp' => $timestamp,
                'actor' => $actor_label,
                'summary' => $summary,
                'type' => 'resolved',
            ]);
        } else {
            return $record;
        }

        $record['last_updated_at'] = $timestamp;
        $receipts[$entry_id] = $record;
        self::save($receipts);
        self::update_queue_resolution($entry_id, $record);

        return $record;
    }

    /**
     * Attempts to lazily create a receipt from the queue entry when missing.
     *
     * @param string $entry_id
     *
     * @return array<string,mixed>
     */
    private static function seed_from_queue($entry_id) {
        if (!class_exists(__NAMESPACE__ . '\\BJLG_Notification_Queue')) {
            return [];
        }

        $entry = BJLG_Notification_Queue::find_entry($entry_id);
        if (empty($entry) || !is_array($entry)) {
            return [];
        }

        self::record_creation($entry);

        $receipts = self::load();
        if (!isset($receipts[$entry_id])) {
            return [];
        }

        $record = self::normalize_record($receipts[$entry_id]);
        $resolution = isset($entry['resolution']) && is_array($entry['resolution']) ? $entry['resolution'] : [];
        $updated = false;

        if (!empty($resolution['acknowledged_at']) && empty($record['acknowledged_at'])) {
            $record['acknowledged_at'] = (int) $resolution['acknowledged_at'];
            $updated = true;
        }

        if (!empty($resolution['resolved_at']) && empty($record['resolved_at'])) {
            $record['resolved_at'] = (int) $resolution['resolved_at'];
            if (empty($record['acknowledged_at'])) {
                $record['acknowledged_at'] = (int) $resolution['resolved_at'];
            }
            $updated = true;
        }

        if (!empty($resolution['steps']) && is_array($resolution['steps'])) {
            foreach ($resolution['steps'] as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $record['steps'][] = self::sanitize_step($step);
                $updated = true;
            }
        }

        if ($updated) {
            $last_step = end($record['steps']);
            if (is_array($last_step) && !empty($last_step['timestamp'])) {
                $record['last_updated_at'] = (int) $last_step['timestamp'];
            }

            $receipts[$entry_id] = $record;
            self::save($receipts);
            self::update_queue_resolution($entry_id, $record);
        }

        return $record;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function load() {
        $option = bjlg_get_option(self::OPTION, []);
        if (!is_array($option)) {
            return [];
        }

        $normalized = [];
        foreach ($option as $id => $record) {
            if (!is_string($id)) {
                continue;
            }
            if (!is_array($record)) {
                continue;
            }
            $normalized[$id] = $record;
        }

        return $normalized;
    }

    private static function save(array $receipts) {
        bjlg_update_option(self::OPTION, $receipts, null, null, false);
    }

    /**
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>
     */
    private static function normalize_record(array $record) {
        $record['id'] = isset($record['id']) ? (string) $record['id'] : '';
        $record['event'] = isset($record['event']) ? (string) $record['event'] : '';
        $record['title'] = isset($record['title']) ? (string) $record['title'] : '';
        $record['severity'] = isset($record['severity']) ? (string) $record['severity'] : 'info';
        $record['created_at'] = isset($record['created_at']) ? (int) $record['created_at'] : 0;
        $record['acknowledged_at'] = isset($record['acknowledged_at']) && (int) $record['acknowledged_at'] > 0
            ? (int) $record['acknowledged_at']
            : null;
        $record['resolved_at'] = isset($record['resolved_at']) && (int) $record['resolved_at'] > 0
            ? (int) $record['resolved_at']
            : null;
        $record['last_updated_at'] = isset($record['last_updated_at']) ? (int) $record['last_updated_at'] : $record['created_at'];

        $steps = [];
        if (!empty($record['steps']) && is_array($record['steps'])) {
            foreach ($record['steps'] as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $steps[] = self::sanitize_step($step);
            }
        }
        $record['steps'] = $steps;

        return $record;
    }

    /**
     * @param array<string,mixed> $step
     *
     * @return array<string,mixed>
     */
    private static function sanitize_step(array $step) {
        $timestamp = isset($step['timestamp']) ? (int) $step['timestamp'] : current_time('timestamp');
        $actor = isset($step['actor']) ? (string) $step['actor'] : '';
        $summary = isset($step['summary']) ? (string) $step['summary'] : '';
        $type = isset($step['type']) ? (string) $step['type'] : 'update';

        if ($actor === '') {
            $actor = __('Système', 'backup-jlg');
        }

        return [
            'timestamp' => $timestamp,
            'actor' => $actor,
            'summary' => trim($summary),
            'type' => $type !== '' ? $type : 'update',
        ];
    }

    private static function build_actor_label($actor) {
        if (is_string($actor) && $actor !== '') {
            return $actor;
        }

        if (is_array($actor) && !empty($actor['label'])) {
            return (string) $actor['label'];
        }

        if (function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            if ($user && isset($user->display_name) && $user->display_name !== '') {
                return (string) $user->display_name;
            }
            if ($user && isset($user->user_login) && $user->user_login !== '') {
                return (string) $user->user_login;
            }
        }

        return __('Opérateur', 'backup-jlg');
    }

    /**
     * @param array<string,mixed> $record
     */
    private static function update_queue_resolution($entry_id, array $record) {
        if (!class_exists(__NAMESPACE__ . '\\BJLG_Notification_Queue')) {
            return;
        }

        $resolution = [
            'acknowledged_at' => $record['acknowledged_at'],
            'resolved_at' => $record['resolved_at'],
            'steps' => $record['steps'],
        ];

        BJLG_Notification_Queue::update_resolution($entry_id, $resolution);
    }

    private static function resolve_status(array $record) {
        if (!empty($record['resolved_at'])) {
            return 'resolved';
        }

        if (!empty($record['acknowledged_at'])) {
            return 'acknowledged';
        }

        return 'pending';
    }

    private static function get_status_label($status) {
        switch ($status) {
            case 'resolved':
                return __('Résolu', 'backup-jlg');
            case 'acknowledged':
                return __('Accusé', 'backup-jlg');
            default:
                return __('En attente', 'backup-jlg');
        }
    }

    private static function format_datetime($timestamp) {
        if (!is_int($timestamp) || $timestamp <= 0) {
            return '';
        }

        if (function_exists('wp_date')) {
            return wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $timestamp);
        }

        return gmdate('Y-m-d H:i', $timestamp);
    }

    private static function format_relative($timestamp, $now) {
        if (!is_int($timestamp) || $timestamp <= 0) {
            return '';
        }

        if (!function_exists('human_time_diff')) {
            return '';
        }

        return sprintf(__('il y a %s', 'backup-jlg'), human_time_diff($timestamp, $now));
    }
}
