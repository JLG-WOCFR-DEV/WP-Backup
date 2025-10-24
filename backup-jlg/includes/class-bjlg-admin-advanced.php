<?php
namespace BJLG;

/**
 * Advanced Admin Features for Backup JLG
 *
 * @package Backup_JLG
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advanced admin functionality (placeholder for future features)
 */
class BJLG_Admin_Advanced {

    /**
     * Constructor
     */
    public function __construct() {
        BJLG_Debug::log('Advanced admin features initialized.');
    }

    /**
     * Agrège les principales métriques nécessaires à l'aperçu du tableau de bord.
     */
    public function get_dashboard_metrics(): array {
        $now = current_time('timestamp');

        $metrics = [
            'history' => [
                'stats' => [
                    'total_actions' => 0,
                    'successful' => 0,
                    'failed' => 0,
                    'info' => 0,
                    'by_action' => [],
                    'by_user' => [],
                    'most_active_hour' => null,
                ],
                'last_backup' => null,
                'recent_failures' => [],
            ],
            'scheduler' => [
                'stats' => [
                    'total_scheduled' => 0,
                    'successful' => 0,
                    'failed' => 0,
                    'success_rate' => 0,
                    'last_run' => null,
                    'average_duration' => 0,
                ],
                'next_runs' => [],
                'active_count' => 0,
                'overdue' => false,
            ],
            'storage' => [
                'directory' => defined('BJLG_BACKUP_DIR') ? bjlg_get_backup_directory() : '',
                'total_size_bytes' => 0,
                'total_size_human' => size_format(0),
                'backup_count' => 0,
                'latest_backup' => null,
                'remote_destinations' => [],
            ],
            'alerts' => [],
            'onboarding' => $this->get_onboarding_resources(),
            'summary' => [],
            'restore_self_test' => [],
            'generated_at' => $this->format_datetime($now),
        ];

        if (class_exists(__NAMESPACE__ . '\\BJLG_History')) {
            $history_stats = BJLG_History::get_stats('month');
            if (is_array($history_stats)) {
                $metrics['history']['stats'] = array_merge($metrics['history']['stats'], $history_stats);
            }

            $last_backup_entries = BJLG_History::get_history(1, [
                'action_type' => 'backup_created',
                'status' => 'success',
            ]);

            if (!empty($last_backup_entries[0])) {
                $entry = $last_backup_entries[0];
                $timestamp = strtotime($entry['timestamp']);

                $metrics['history']['last_backup'] = [
                    'timestamp' => $entry['timestamp'],
                    'formatted' => $timestamp ? $this->format_datetime($timestamp) : $entry['timestamp'],
                    'relative' => $timestamp ? sprintf(__('il y a %s', 'backup-jlg'), human_time_diff($timestamp, $now)) : '',
                    'status' => $entry['status'],
                    'details' => $entry['details'],
                ];
            }

            $recent_failures = BJLG_History::get_history(3, ['status' => 'failure']);
            if (is_array($recent_failures) && !empty($recent_failures)) {
                foreach ($recent_failures as $failure) {
                    $failure_ts = strtotime($failure['timestamp']);
                    $metrics['history']['recent_failures'][] = [
                        'timestamp' => $failure['timestamp'],
                        'formatted' => $failure_ts ? $this->format_datetime($failure_ts) : $failure['timestamp'],
                        'relative' => $failure_ts ? sprintf(__('il y a %s', 'backup-jlg'), human_time_diff($failure_ts, $now)) : '',
                        'details' => $failure['details'],
                        'action' => $failure['action_type'],
                    ];
                }
            }
        }

        if (class_exists(__NAMESPACE__ . '\\BJLG_Scheduler')) {
            $scheduler = BJLG_Scheduler::instance();

            $schedule_stats = $scheduler->get_schedule_stats();
            if (is_array($schedule_stats)) {
                $metrics['scheduler']['stats'] = array_merge($metrics['scheduler']['stats'], $schedule_stats);
            }

            $collection = $scheduler->get_schedule_settings();
            $next_runs = [];
            $active_count = 0;

            if (!empty($collection['schedules']) && is_array($collection['schedules'])) {
                foreach ($collection['schedules'] as $schedule) {
                    if (!is_array($schedule) || empty($schedule['id'])) {
                        continue;
                    }

                    $enabled = ($schedule['recurrence'] ?? 'disabled') !== 'disabled';
                    if ($enabled) {
                        $active_count++;
                    }

                    $next_run_timestamp = wp_next_scheduled(BJLG_Scheduler::SCHEDULE_HOOK, [$schedule['id']]);
                    $next_runs[] = [
                        'id' => $schedule['id'],
                        'label' => $schedule['label'] ?? $schedule['id'],
                        'enabled' => $enabled,
                        'next_run_timestamp' => $next_run_timestamp ?: null,
                        'next_run_formatted' => $next_run_timestamp ? $this->format_datetime($next_run_timestamp) : __('Non planifié', 'backup-jlg'),
                        'next_run_relative' => $this->format_schedule_relative($next_run_timestamp, $now),
                    ];
                }
            }

            $metrics['scheduler']['next_runs'] = $next_runs;
            $metrics['scheduler']['active_count'] = $active_count;
            $metrics['scheduler']['overdue'] = $scheduler->is_schedule_overdue();
        }

        if (function_exists('bjlg_get_backup_size')) {
            $size = (int) bjlg_get_backup_size();
            $metrics['storage']['total_size_bytes'] = $size;
            $metrics['storage']['total_size_human'] = size_format($size);
        }

        $backup_dir = $metrics['storage']['directory'];
        if ($backup_dir && is_dir($backup_dir)) {
            $pattern = trailingslashit($backup_dir) . '*.zip*';
            $files = glob($pattern);

            if (is_array($files) && !empty($files)) {
                $metrics['storage']['backup_count'] = count($files);

                usort($files, function ($a, $b) {
                    return filemtime($b) <=> filemtime($a);
                });

                $latest = $files[0];
                $mtime = @filemtime($latest);
                if ($mtime) {
                    $metrics['storage']['latest_backup'] = [
                        'path' => $latest,
                        'filename' => basename($latest),
                        'timestamp' => $mtime,
                        'formatted' => $this->format_datetime($mtime),
                        'relative' => sprintf(__('il y a %s', 'backup-jlg'), human_time_diff($mtime, $now)),
                    ];
                }
            }
        }

        $remote_snapshot = $this->collect_remote_storage_metrics();
        $metrics['storage']['remote_destinations'] = $remote_snapshot['destinations'];
        $metrics['storage']['remote_last_refreshed'] = $remote_snapshot['generated_at'];
        $metrics['storage']['remote_last_refreshed_formatted'] = $remote_snapshot['generated_at_formatted'];
        $metrics['storage']['remote_last_refreshed_relative'] = $remote_snapshot['generated_at_relative'];
        $metrics['storage']['remote_refresh_stale'] = $remote_snapshot['stale'];
        $metrics['storage']['remote_warning_threshold'] = $remote_snapshot['threshold_percent'];
        $metrics['storage']['remote_warning_threshold_ratio'] = $remote_snapshot['threshold_ratio'];

        $metrics['resilience'] = $this->collect_resilience_metrics();

        $metrics['queues'] = $this->build_queue_metrics($now);

        $metrics['encryption'] = $this->get_encryption_metrics();

        $metrics['sla_validation'] = $this->build_sla_validation_panel();

        $metrics['summary'] = $this->build_summary($metrics);
        $metrics['alerts'] = $this->build_alerts($metrics);
        $metrics['restore_self_test'] = $this->get_restore_self_test_snapshot($now);
        $metrics['reliability'] = $this->build_reliability($metrics);

        return $metrics;
    }

    private function build_sla_validation_panel(): array {
        $entry = BJLG_History::get_last_event_metadata('sandbox_restore_validation');

        if (!is_array($entry)) {
            return [
                'available' => false,
                'status' => 'unknown',
                'message' => __('Aucune validation sandbox enregistrée.', 'backup-jlg'),
                'actions' => [
                    __('Planifiez une restauration sandbox automatisée pour valider votre SLA.', 'backup-jlg'),
                ],
            ];
        }

        $metadata = isset($entry['metadata']) && is_array($entry['metadata']) ? $entry['metadata'] : [];
        $report = isset($metadata['report']) && is_array($metadata['report']) ? $metadata['report'] : [];
        $objectives = isset($report['objectives']) && is_array($report['objectives']) ? $report['objectives'] : [];
        $timings = isset($report['timings']) && is_array($report['timings']) ? $report['timings'] : [];
        $sandbox = isset($report['sandbox']) && is_array($report['sandbox']) ? $report['sandbox'] : [];
        $steps = isset($report['steps']) && is_array($report['steps']) ? $report['steps'] : [];
        $logs = isset($report['logs']) && is_array($report['logs']) ? $report['logs'] : [];
        $rollback = isset($report['rollback']) && is_array($report['rollback']) ? $report['rollback'] : [];
        $status = isset($entry['status']) ? (string) $entry['status'] : 'info';
        $timestamp = isset($entry['timestamp']) ? strtotime($entry['timestamp']) : false;
        $relative = ($timestamp && function_exists('human_time_diff')) ? human_time_diff($timestamp, time()) : '';
        $backup_filename = isset($report['backup_file']) && is_string($report['backup_file']) ? basename($report['backup_file']) : '';
        $cleanup = isset($sandbox['cleanup']) && is_array($sandbox['cleanup']) ? $sandbox['cleanup'] : [];

        $actions = [];
        if ($status === 'success') {
            $actions[] = __('Consignez cette validation dans votre registre SLA.', 'backup-jlg');
            $actions[] = __('Planifiez un contrôle manuel après les mises à jour critiques.', 'backup-jlg');
        } else {
            $actions[] = __('Relancez une restauration sandbox depuis l’onglet Restauration pour diagnostiquer l’incident.', 'backup-jlg');
            $actions[] = __('Vérifiez l’espace disque local et la connectivité des destinations distantes.', 'backup-jlg');
            $actions[] = __('Inspectez l’historique détaillé et les notifications associées.', 'backup-jlg');
        }

        return [
            'available' => true,
            'status' => $status,
            'validated_at' => $entry['timestamp'],
            'validated_relative' => $relative,
            'message' => $entry['details'],
            'report' => $report,
            'actions' => $actions,
            'objectives' => [
                'rto_seconds' => isset($objectives['rto_seconds']) ? (float) $objectives['rto_seconds'] : null,
                'rto_human' => isset($objectives['rto_human']) ? (string) $objectives['rto_human'] : ($timings['duration_human'] ?? ''),
                'rpo_seconds' => isset($objectives['rpo_seconds']) ? (float) $objectives['rpo_seconds'] : null,
                'rpo_human' => isset($objectives['rpo_human']) ? (string) $objectives['rpo_human'] : '',
            ],
            'backup' => [
                'filename' => $backup_filename,
                'path' => isset($report['backup_file']) ? (string) $report['backup_file'] : '',
            ],
            'sandbox' => [
                'path' => isset($sandbox['base_path']) ? (string) $sandbox['base_path'] : '',
                'cleanup' => $cleanup,
            ],
            'steps' => $steps,
            'logs' => array_slice($logs, -10),
            'rollback' => [
                'performed' => !empty($rollback['performed']),
                'error' => isset($rollback['error']) ? (string) $rollback['error'] : '',
            ],
        ];
    }

    private function build_queue_metrics(int $now): array {
        $queues = [];

        if (class_exists(__NAMESPACE__ . '\\BJLG_Notification_Queue')) {
            $queues['notifications'] = $this->format_notification_queue_metrics($now);
        }

        $queues['remote_purge'] = $this->format_remote_purge_metrics($now);

        return $queues;
    }

    private function get_remote_purge_metrics_snapshot(): array {
        if (function_exists('bjlg_get_option')) {
            $raw = \bjlg_get_option('bjlg_remote_purge_sla_metrics', []);
        } elseif (function_exists('get_option')) {
            $raw = get_option('bjlg_remote_purge_sla_metrics', []);
        } else {
            $raw = [];
        }

        return is_array($raw) ? $raw : [];
    }

    private function format_notification_queue_metrics(int $now): array {
        $metrics = [
            'key' => 'notifications',
            'label' => __('Notifications', 'backup-jlg'),
            'total' => 0,
            'status_counts' => [
                'pending' => 0,
                'retry' => 0,
                'failed' => 0,
                'completed' => 0,
            ],
            'next_attempt_formatted' => '',
            'next_attempt_relative' => '',
            'oldest_entry_formatted' => '',
            'oldest_entry_relative' => '',
            'entries' => [],
        ];

        $snapshot = BJLG_Notification_Queue::get_queue_snapshot();
        if (!is_array($snapshot)) {
            return $metrics;
        }

        $metrics['total'] = isset($snapshot['total_entries']) ? (int) $snapshot['total_entries'] : 0;

        $status_counts = isset($snapshot['status_counts']) && is_array($snapshot['status_counts'])
            ? $snapshot['status_counts']
            : [];
        foreach ($status_counts as $status => $count) {
            $status = is_string($status) ? $status : (string) $status;
            if (!isset($metrics['status_counts'][$status])) {
                $metrics['status_counts'][$status] = 0;
            }
            $metrics['status_counts'][$status] += (int) $count;
        }

        [$metrics['next_attempt_formatted'], $metrics['next_attempt_relative']] = $this->format_timestamp_pair($snapshot['next_attempt_at'] ?? null, $now);
        [$metrics['oldest_entry_formatted'], $metrics['oldest_entry_relative']] = $this->format_timestamp_pair($snapshot['oldest_entry_at'] ?? null, $now);

        $entries = isset($snapshot['entries']) && is_array($snapshot['entries']) ? $snapshot['entries'] : [];
        $metrics['entries'] = $this->format_notification_entries(array_slice($entries, 0, 5), $now);

        return $metrics;
    }

    private function format_remote_purge_metrics(int $now): array {
        $metrics = [
            'key' => 'remote_purge',
            'label' => __('Purge distante', 'backup-jlg'),
            'total' => 0,
            'status_counts' => [
                'pending' => 0,
                'retry' => 0,
                'processing' => 0,
                'failed' => 0,
            ],
            'next_attempt_formatted' => '',
            'next_attempt_relative' => '',
            'oldest_entry_formatted' => '',
            'oldest_entry_relative' => '',
            'entries' => [],
            'delayed_count' => 0,
        ];

        if (!class_exists(__NAMESPACE__ . '\\BJLG_Incremental')) {
            return $metrics;
        }

        $incremental = new BJLG_Incremental();
        $queue = $incremental->get_remote_purge_queue();
        if (!is_array($queue) || empty($queue)) {
            $snapshot = $this->get_remote_purge_metrics_snapshot();
            $metrics['sla'] = $this->format_remote_purge_sla_metrics($now, $snapshot);

            return $metrics;
        }

        $metrics['total'] = count($queue);

        $next_attempt = null;
        $oldest = null;
        $entries = [];
        $snapshot = $this->get_remote_purge_metrics_snapshot();
        $saturation_context = [];
        if (isset($snapshot['saturation']['destinations']) && is_array($snapshot['saturation']['destinations'])) {
            $saturation_context = $snapshot['saturation']['destinations'];
        }

        foreach ($queue as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $status = isset($entry['status']) ? (string) $entry['status'] : 'pending';
            $status = $status !== '' ? $status : 'pending';
            if (!isset($metrics['status_counts'][$status])) {
                $metrics['status_counts'][$status] = 0;
            }
            $metrics['status_counts'][$status]++;

            $registered_at = isset($entry['registered_at']) ? (int) $entry['registered_at'] : 0;
            if ($registered_at > 0) {
                $oldest = $this->min_time($oldest, $registered_at);
            }

            $entry_next_attempt = isset($entry['next_attempt_at']) ? (int) $entry['next_attempt_at'] : 0;
            if ($entry_next_attempt > 0) {
                $next_attempt = $this->min_time($next_attempt, $entry_next_attempt);
            }

            $last_delay = isset($entry['last_delay']) ? max(0, (int) $entry['last_delay']) : 0;
            $max_delay = isset($entry['max_delay']) ? max(0, (int) $entry['max_delay']) : $last_delay;
            $delay_alerted = !empty($entry['delay_alerted']);
            $next_attempt_overdue = $entry_next_attempt > 0 && $entry_next_attempt <= $now;
            $is_delayed = $delay_alerted || $next_attempt_overdue;
            if ($is_delayed) {
                $metrics['delayed_count']++;
            }

            $destinations = [];
            if (!empty($entry['destinations']) && is_array($entry['destinations'])) {
                foreach ($entry['destinations'] as $destination) {
                    $destinations[] = sanitize_text_field((string) $destination);
                }
            }

            $entries[] = [
                'title' => isset($entry['file']) ? sanitize_text_field((string) $entry['file']) : '',
                'status' => $status,
                'attempts' => isset($entry['attempts']) ? (int) $entry['attempts'] : 0,
                'next_attempt_at' => $entry_next_attempt,
                'registered_at' => $registered_at,
                'last_error' => isset($entry['last_error']) ? sanitize_text_field((string) $entry['last_error']) : '',
                'destinations' => $destinations,
                'last_delay' => $last_delay,
                'max_delay' => $max_delay,
                'delay_alerted' => $delay_alerted,
                'is_delayed' => $is_delayed,
            ];
        }

        usort($entries, static function ($a, $b) {
            $a_time = $a['next_attempt_at'] ?? 0;
            $b_time = $b['next_attempt_at'] ?? 0;

            if ($a_time === $b_time) {
                return ($a['registered_at'] ?? 0) <=> ($b['registered_at'] ?? 0);
            }

            if ($a_time === 0) {
                return 1;
            }

            if ($b_time === 0) {
                return -1;
            }

            return $a_time <=> $b_time;
        });

        $metrics['sla'] = $this->format_remote_purge_sla_metrics($now, $snapshot);
        $metrics['entries'] = $this->format_remote_purge_entries(
            array_slice($entries, 0, 5),
            $now,
            $metrics['sla']['saturation_destinations_map'] ?? $saturation_context
        );
        [$metrics['next_attempt_formatted'], $metrics['next_attempt_relative']] = $this->format_timestamp_pair($next_attempt, $now);
        [$metrics['oldest_entry_formatted'], $metrics['oldest_entry_relative']] = $this->format_timestamp_pair($oldest, $now);

        return $metrics;
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     */
    private function format_notification_entries(array $entries, int $now): array {
        $formatted = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $title = isset($entry['title']) ? (string) $entry['title'] : '';
            if ($title === '' && !empty($entry['event'])) {
                $title = (string) $entry['event'];
            }
            if ($title === '' && !empty($entry['id'])) {
                $title = (string) $entry['id'];
            }

            [$next_formatted, $next_relative] = $this->format_timestamp_pair($entry['next_attempt_at'] ?? null, $now);
            [$created_formatted, $created_relative] = $this->format_timestamp_pair($entry['created_at'] ?? null, $now);

            $status = isset($entry['status']) ? (string) $entry['status'] : 'pending';

            $details = [];

            $resolution_payload = null;
            if (class_exists(__NAMESPACE__ . '\\BJLG_Notification_Receipts') && !empty($entry['id'])) {
                $receipt = BJLG_Notification_Receipts::get($entry['id']);
                if (is_array($receipt)) {
                    $resolution_payload = BJLG_Notification_Receipts::prepare_for_display($receipt);
                }
            }

            if (!empty($entry['quiet_until'])) {
                [$quiet_formatted, $quiet_relative] = $this->format_timestamp_pair($entry['quiet_until'], $now);
                if ($quiet_relative !== '') {
                    $details['quiet_until_relative'] = $quiet_relative;
                }
                if ($quiet_formatted !== '') {
                    $details['quiet_until_formatted'] = $quiet_formatted;
                }
            }

            if (!empty($entry['escalation']) && is_array($entry['escalation'])) {
                $escalation_channels = [];
                if (!empty($entry['escalation']['channels']) && is_array($entry['escalation']['channels'])) {
                    foreach ($entry['escalation']['channels'] as $channel_key) {
                        $label = $this->get_notification_channel_label($channel_key);
                        if ($label !== '') {
                            $escalation_channels[] = $label;
                        }
                    }
                }

                if (!empty($escalation_channels)) {
                    $details['escalation_channels'] = implode(', ', array_unique($escalation_channels));
                }

                $delay_seconds = isset($entry['escalation']['delay']) ? (int) $entry['escalation']['delay'] : 0;
                if ($delay_seconds > 0) {
                    $details['escalation_delay'] = $this->format_duration_label($delay_seconds);
                }

                $strategy = isset($entry['escalation']['strategy']) ? (string) $entry['escalation']['strategy'] : '';
                if ($strategy === 'staged' && !empty($entry['escalation']['steps']) && is_array($entry['escalation']['steps'])) {
                    $step_summaries = [];
                    foreach ($entry['escalation']['steps'] as $step) {
                        if (!is_array($step)) {
                            continue;
                        }

                        $channels = isset($step['channels']) && is_array($step['channels']) ? $step['channels'] : [];
                        $channel_labels = [];
                        foreach ($channels as $channel_key) {
                            $label = $this->get_notification_channel_label($channel_key);
                            if ($label !== '') {
                                $channel_labels[] = $label;
                            }
                        }

                        if (empty($channel_labels)) {
                            continue;
                        }

                        $delay = isset($step['delay']) ? (int) $step['delay'] : 0;
                        $delay_label = $delay > 0
                            ? $this->format_duration_label($delay)
                            : __('immédiat', 'backup-jlg');

                        $label = isset($step['label']) && is_string($step['label']) && $step['label'] !== ''
                            ? $step['label']
                            : implode(', ', $channel_labels);

                        $step_summaries[] = sprintf('%s — %s', $label, $delay_label);
                    }

                    if (!empty($step_summaries)) {
                        $details['escalation_scenario'] = implode(' → ', $step_summaries);
                        $details['escalation_strategy'] = 'staged';
                    }
                }
            }

            if (!empty($entry['has_escalation_pending']) && !empty($entry['escalation_next_attempt'])) {
                [$escalation_formatted, $escalation_relative] = $this->format_timestamp_pair(
                    $entry['escalation_next_attempt'],
                    $now
                );

                if ($escalation_relative !== '') {
                    $details['escalation_next_relative'] = $escalation_relative;
                }

                if ($escalation_formatted !== '') {
                    $details['escalation_next_formatted'] = $escalation_formatted;
                }
            }

            $acknowledged_at = isset($entry['acknowledged_at']) ? (int) $entry['acknowledged_at'] : 0;
            $acknowledged_by = isset($entry['acknowledged_by']) ? sanitize_text_field((string) $entry['acknowledged_by']) : '';
            $acknowledged = !empty($entry['acknowledged']) || $acknowledged_at > 0 || $acknowledged_by !== '';

            if ($acknowledged) {
                [$ack_formatted, $ack_relative] = $this->format_timestamp_pair($acknowledged_at, $now);
                if ($ack_relative !== '') {
                    $details['acknowledged_relative'] = $ack_relative;
                }
                if ($ack_formatted !== '') {
                    $details['acknowledged_formatted'] = $ack_formatted;
                }
                if ($acknowledged_by !== '') {
                    $details['acknowledged_by'] = $acknowledged_by;
                }

                $ack_label = $acknowledged_by !== ''
                    ? sprintf(__('Accusée par %s', 'backup-jlg'), $acknowledged_by)
                    : __('Accusée', 'backup-jlg');

                if (!empty($details['acknowledged_relative'])) {
                    $ack_label .= ' — ' . $details['acknowledged_relative'];
                }

                $details['acknowledged_label'] = $ack_label;
            }

            $resolved_at = isset($entry['resolved_at']) ? (int) $entry['resolved_at'] : 0;
            $resolved = !empty($entry['resolved']) || $resolved_at > 0;

            if ($resolved) {
                [$resolved_formatted, $resolved_relative] = $this->format_timestamp_pair($resolved_at, $now);
                if ($resolved_relative !== '') {
                    $details['resolved_relative'] = $resolved_relative;
                }
                if ($resolved_formatted !== '') {
                    $details['resolved_formatted'] = $resolved_formatted;
                }

                $resolved_label = __('Résolue', 'backup-jlg');
                if (!empty($details['resolved_relative'])) {
                    $resolved_label .= ' — ' . $details['resolved_relative'];
                }

                $details['resolved_label'] = $resolved_label;

                if (!empty($entry['resolution_notes'])) {
                    $notes = is_string($entry['resolution_notes'])
                        ? $entry['resolution_notes']
                        : (is_array($entry['resolution_notes']) ? implode("\n", $entry['resolution_notes']) : '');

                    if ($notes !== '') {
                        if (function_exists('sanitize_textarea_field')) {
                            $notes = sanitize_textarea_field($notes);
                        } else {
                            $notes = sanitize_text_field($notes);
                        }
                        $details['resolution_notes'] = $notes;
                    }
                }
            }

            $severity = isset($entry['severity']) ? (string) $entry['severity'] : 'info';
            $severity_label = $this->get_notification_severity_label($severity);
            $severity_intent = $this->get_notification_severity_intent($severity);

            if ($severity_label !== '') {
                $details['severity_label'] = $severity_label;
            }

            $resolution_status = isset($entry['resolution_status']) ? (string) $entry['resolution_status'] : 'pending';
            $details['resolution_status'] = $resolution_status;
            $details['resolution_status_label'] = $this->get_resolution_status_label($resolution_status);

            if (!empty($entry['resolution_summary'])) {
                $details['resolution_summary'] = function_exists('sanitize_textarea_field')
                    ? sanitize_textarea_field((string) $entry['resolution_summary'])
                    : sanitize_text_field((string) $entry['resolution_summary']);
            }

            if ($resolution_payload) {
                $details['acknowledged_relative'] = $resolution_payload['acknowledged_relative'] ?? '';
                $details['acknowledged_formatted'] = $resolution_payload['acknowledged_formatted'] ?? '';
                $details['resolved_relative'] = $resolution_payload['resolved_relative'] ?? '';
                $details['resolved_formatted'] = $resolution_payload['resolved_formatted'] ?? '';
                $details['resolution_steps'] = $resolution_payload['steps'] ?? [];
                if (empty($details['resolution_summary']) && !empty($resolution_payload['steps']) && is_array($resolution_payload['steps'])) {
                    $summary = BJLG_Notification_Queue::summarize_resolution_steps($resolution_payload['steps']);
                    if ($summary !== '') {
                        $details['resolution_summary'] = function_exists('sanitize_textarea_field')
                            ? sanitize_textarea_field($summary)
                            : sanitize_text_field($summary);
                    }
                }
            } elseif (!empty($entry['resolution']) && is_array($entry['resolution'])) {
                $acknowledged = isset($entry['resolution']['acknowledged_at']) ? (int) $entry['resolution']['acknowledged_at'] : 0;
                $resolved = isset($entry['resolution']['resolved_at']) ? (int) $entry['resolution']['resolved_at'] : 0;
                [$ack_formatted, $ack_relative] = $this->format_timestamp_pair($acknowledged ?: null, $now);
                [$res_formatted, $res_relative] = $this->format_timestamp_pair($resolved ?: null, $now);
                if ($ack_relative !== '') {
                    $details['acknowledged_relative'] = $ack_relative;
                }
                if ($ack_formatted !== '') {
                    $details['acknowledged_formatted'] = $ack_formatted;
                }
                if ($res_relative !== '') {
                    $details['resolved_relative'] = $res_relative;
                }
                if ($res_formatted !== '') {
                    $details['resolved_formatted'] = $res_formatted;
                }
                if (!empty($entry['resolution']['steps']) && is_array($entry['resolution']['steps'])) {
                    $details['resolution_steps'] = $entry['resolution']['steps'];
                }
            }

            $formatted[] = [
                'id' => isset($entry['id']) ? sanitize_text_field((string) $entry['id']) : '',
                'title' => sanitize_text_field($title),
                'status' => $status,
                'status_label' => $this->get_queue_status_label($status),
                'status_intent' => $this->get_queue_status_intent($status),
                'attempts' => isset($entry['attempts']) ? (int) $entry['attempts'] : 0,
                'attempt_label' => $this->format_attempt_label(isset($entry['attempts']) ? (int) $entry['attempts'] : 0),
                'created_relative' => $created_relative,
                'created_formatted' => $created_formatted,
                'next_attempt_relative' => $next_relative,
                'next_attempt_formatted' => $next_formatted,
                'message' => isset($entry['last_error']) ? (string) $entry['last_error'] : '',
                'severity' => $severity,
                'severity_label' => $severity_label,
                'severity_intent' => $severity_intent,
                'acknowledged' => $acknowledged,
                'resolved' => $resolved,
                'details' => $details,
            ];
        }

        return $formatted;
    }

    private function format_remote_purge_sla_metrics(int $now, ?array $raw = null): array {
        if ($raw === null) {
            $raw = $this->get_remote_purge_metrics_snapshot();
        }

        if (!is_array($raw) || empty($raw)) {
            return [];
        }

        [$updated_formatted, $updated_relative] = $this->format_timestamp_pair($raw['updated_at'] ?? null, $now);

        $pending = isset($raw['pending']) && is_array($raw['pending']) ? $raw['pending'] : [];
        $throughput = isset($raw['throughput']) && is_array($raw['throughput']) ? $raw['throughput'] : [];
        $failures = isset($raw['failures']) && is_array($raw['failures']) ? $raw['failures'] : [];
        $forecast = isset($raw['forecast']) && is_array($raw['forecast']) ? $raw['forecast'] : [];
        $durations = isset($raw['durations']) && is_array($raw['durations']) ? $raw['durations'] : [];
        $saturation = isset($raw['saturation']) && is_array($raw['saturation']) ? $raw['saturation'] : [];
        $backlog = isset($raw['backlog']) && is_array($raw['backlog']) ? $raw['backlog'] : [];

        $overall_forecast = isset($forecast['overall']) && is_array($forecast['overall']) ? $forecast['overall'] : [];
        $forecast_destinations = isset($forecast['destinations']) && is_array($forecast['destinations'])
            ? $forecast['destinations']
            : [];

        $formatted = [
            'updated_relative' => $updated_relative,
            'updated_formatted' => $updated_formatted,
            'pending_total' => isset($pending['total']) ? (int) $pending['total'] : 0,
            'pending_average' => '',
            'pending_oldest' => '',
            'pending_over_threshold' => isset($pending['over_threshold']) ? (int) $pending['over_threshold'] : 0,
            'pending_destinations' => '',
            'throughput_average' => '',
            'throughput_attempts_average' => '',
            'throughput_last_completion' => '',
            'throughput_last_completion_relative' => '',
            'failures_total' => isset($failures['total']) ? (int) $failures['total'] : 0,
            'last_failure_relative' => '',
            'last_failure_message' => isset($failures['last_message']) ? (string) $failures['last_message'] : '',
            'forecast_label' => isset($overall_forecast['forecast_label']) ? (string) $overall_forecast['forecast_label'] : '',
            'forecast_seconds' => isset($overall_forecast['forecast_seconds']) ? (int) $overall_forecast['forecast_seconds'] : null,
            'forecast_destinations' => $this->format_forecast_destinations($forecast_destinations, $now),
            'forecast_projected_relative' => '',
            'forecast_projected_formatted' => '',
            'duration_average' => '',
            'duration_peak' => '',
            'duration_last' => '',
            'duration_p95' => '',
            'duration_series' => [],
            'capacity_projections' => [],
            'capacity_thresholds' => [],
            'threshold_summary' => '',
        ];

        if (!empty($pending['average_seconds'])) {
            $formatted['pending_average'] = $this->format_duration_label((int) round($pending['average_seconds']));
        }

        if (!empty($pending['oldest_seconds'])) {
            $formatted['pending_oldest'] = $this->format_duration_label((int) $pending['oldest_seconds']);
        }

        if (!empty($pending['destinations']) && is_array($pending['destinations'])) {
            $formatted['pending_destinations'] = $this->format_destination_counts($pending['destinations']);
        }

        if (!empty($throughput['average_completion_seconds'])) {
            $formatted['throughput_average'] = $this->format_duration_label((int) round($throughput['average_completion_seconds']));
        }

        if (!empty($throughput['average_attempts'])) {
            $formatted['throughput_attempts_average'] = number_format_i18n((float) $throughput['average_attempts'], 2);
        }

        if (!empty($throughput['last_completion_seconds'])) {
            $formatted['throughput_last_completion'] = $this->format_duration_label((int) round($throughput['last_completion_seconds']));
        }

        if (!empty($throughput['last_completed_at'])) {
            [$last_completed_formatted, $last_completed_relative] = $this->format_timestamp_pair(
                $throughput['last_completed_at'],
                $now
            );
            $formatted['throughput_last_completion_relative'] = $last_completed_relative;
            $formatted['throughput_last_completion_formatted'] = $last_completed_formatted;
        }

        if (!empty($failures['last_failure_at'])) {
            [$failure_formatted, $failure_relative] = $this->format_timestamp_pair(
                $failures['last_failure_at'],
                $now
            );
            $formatted['last_failure_relative'] = $failure_relative;
            $formatted['last_failure_formatted'] = $failure_formatted;
        }

        $duration_data = $this->format_duration_series($durations, $now);
        $formatted['duration_series'] = $duration_data['destinations'];
        $overall_duration = $duration_data['overall'];
        if (!empty($overall_duration['average_label'])) {
            $formatted['duration_average'] = $overall_duration['average_label'];
        }
        if (!empty($overall_duration['peak_label'])) {
            $formatted['duration_peak'] = $overall_duration['peak_label'];
        }
        if (!empty($overall_duration['last_label'])) {
            $formatted['duration_last'] = $overall_duration['last_label'];
        }
        if (!empty($overall_duration['p95_label'])) {
            $formatted['duration_p95'] = $overall_duration['p95_label'];
        }

        $capacity_data = $this->format_capacity_projections($projections, $now);
        $formatted['capacity_projections'] = $capacity_data['destinations'];
        $formatted['capacity_thresholds'] = $capacity_data['thresholds'];
        if (!empty($capacity_data['summary'])) {
            $formatted['threshold_summary'] = $capacity_data['summary'];
        }

        if (!empty($overall_forecast['projected_clearance'])) {
            [$projected_formatted, $projected_relative] = $this->format_timestamp_pair(
                $overall_forecast['projected_clearance'],
                $now
            );
            $formatted['forecast_projected_relative'] = $projected_relative;
            $formatted['forecast_projected_formatted'] = $projected_formatted;
        }

        $failure_rate = isset($throughput['failure_rate']) ? (float) $throughput['failure_rate'] : null;
        if ($failure_rate === null) {
            $processed_total = (int) ($throughput['total_completed'] ?? 0) + (int) ($failures['total'] ?? 0);
            if ($processed_total > 0) {
                $failure_rate = ($failures['total'] ?? 0) / $processed_total;
            }
        }
        if ($failure_rate !== null) {
            $formatted['failure_rate_percent'] = number_format_i18n(max(0.0, $failure_rate) * 100, 1) . '%';
        }

        $success_rate = isset($throughput['success_rate']) ? (float) $throughput['success_rate'] : null;
        if ($success_rate === null && isset($failure_rate)) {
            $success_rate = max(0.0, 1 - $failure_rate);
        }
        if ($success_rate !== null) {
            $formatted['success_rate_percent'] = number_format_i18n(max(0.0, $success_rate) * 100, 1) . '%';
        }

        if (!empty($durations['peak_seconds'])) {
            $formatted['duration_peak'] = $this->format_duration_label((int) round($durations['peak_seconds']));
        }

        if (!empty($durations['last_seconds'])) {
            $formatted['duration_last'] = $this->format_duration_label((int) round($durations['last_seconds']));
        }

        if (!empty($saturation['threshold_seconds'])) {
            $formatted['threshold_label'] = $this->format_duration_label((int) $saturation['threshold_seconds']);
        }

        $countdown_seconds = isset($saturation['time_to_threshold']) && $saturation['time_to_threshold'] !== null
            ? (int) $saturation['time_to_threshold']
            : null;

        if ($countdown_seconds !== null) {
            $countdown_label = $this->format_duration_label(max(0, $countdown_seconds));
            $formatted['saturation_countdown'] = $countdown_label;

            if ($countdown_seconds <= 0) {
                $formatted['saturation_label'] = __('Seuil SLA dépassé', 'backup-jlg');
            } else {
                $formatted['saturation_label'] = sprintf(
                    __('Dépassement du SLA estimé dans %s', 'backup-jlg'),
                    $countdown_label
                );
            }
        }

        if (!empty($saturation['projected_breach_at'])) {
            [$breach_formatted, $breach_relative] = $this->format_timestamp_pair(
                $saturation['projected_breach_at'],
                $now
            );
            $formatted['saturation_projected_relative'] = $breach_relative;
            $formatted['saturation_projected_formatted'] = $breach_formatted;
        }

        $saturation_destinations_map = $this->format_saturation_destinations_map(
            isset($saturation['destinations']) && is_array($saturation['destinations'])
                ? $saturation['destinations']
                : [],
            $now
        );

        if (!empty($saturation_destinations_map)) {
            foreach ($saturation_destinations_map as $destination) {
                if (!empty($destination['breach_imminent'])) {
                    $formatted['saturation_warning'] = true;
                    break;
                }
            }
        }

        $formatted['saturation_destinations_map'] = $saturation_destinations_map;
        $formatted['saturation_destinations'] = array_values($saturation_destinations_map);

        $history = isset($backlog['history']) && is_array($backlog['history']) ? $backlog['history'] : [];
        $formatted['backlog_sparkline'] = $this->build_backlog_sparkline($history);
        $formatted['backlog_trend_direction'] = $this->resolve_trend_direction(isset($backlog['slope']) ? (float) $backlog['slope'] : null);

        return $formatted;
    }

    /**
     * @param array<string,array<string,mixed>> $forecasts
     */
    private function format_forecast_destinations(array $forecasts, int $now): array {
        if (empty($forecasts)) {
            return [];
        }

        $formatted = [];
        foreach ($forecasts as $destination_id => $data) {
            if (!is_array($data)) {
                continue;
            }

            $label = (string) $destination_id;
            if (class_exists(__NAMESPACE__ . '\\BJLG_Settings')) {
                $destination_label = BJLG_Settings::get_destination_label($destination_id);
                if (is_string($destination_label) && $destination_label !== '') {
                    $label = $destination_label;
                }
            }

            $forecast_label = isset($data['forecast_label']) ? (string) $data['forecast_label'] : '';
            $projected_relative = '';

            if (!empty($data['projected_clearance'])) {
                [, $projected_relative] = $this->format_timestamp_pair($data['projected_clearance'], $now);
            }

            $formatted[] = [
                'id' => sanitize_key((string) $destination_id),
                'label' => sanitize_text_field($label),
                'forecast_label' => sanitize_text_field($forecast_label),
                'projected_relative' => $projected_relative,
                'pending' => isset($data['pending']) ? (int) $data['pending'] : 0,
            ];
        }

        return $formatted;
    }

    private function format_duration_series(array $durations, int $now): array {
        $destinations = isset($durations['destinations']) && is_array($durations['destinations'])
            ? $durations['destinations']
            : [];
        $formatted = [];

        foreach ($destinations as $destination_id => $data) {
            if (!is_array($data)) {
                continue;
            }

            $label = (string) $destination_id;
            if (class_exists(__NAMESPACE__ . '\\BJLG_Settings')) {
                $candidate = BJLG_Settings::get_destination_label($destination_id);
                if (is_string($candidate) && $candidate !== '') {
                    $label = $candidate;
                }
            }

            $average_seconds = isset($data['average_seconds']) ? (float) $data['average_seconds'] : null;
            $p95_seconds = isset($data['p95_seconds']) ? (float) $data['p95_seconds'] : null;
            $max_seconds = isset($data['max_seconds']) ? (int) $data['max_seconds'] : null;
            $last_seconds = isset($data['last_seconds']) ? (int) $data['last_seconds'] : null;

            $formatted[] = [
                'id' => sanitize_key((string) $destination_id),
                'label' => sanitize_text_field($label),
                'average_seconds' => $average_seconds,
                'average_label' => $average_seconds !== null ? $this->format_duration_label((int) round($average_seconds)) : '',
                'p95_seconds' => $p95_seconds,
                'p95_label' => $p95_seconds !== null ? $this->format_duration_label((int) round($p95_seconds)) : '',
                'max_seconds' => $max_seconds,
                'max_label' => $max_seconds !== null ? $this->format_duration_label($max_seconds) : '',
                'last_seconds' => $last_seconds,
                'last_label' => $last_seconds !== null ? $this->format_duration_label($last_seconds) : '',
                'sample_count' => isset($data['sample_count']) ? (int) $data['sample_count'] : (isset($data['samples']) && is_array($data['samples']) ? count($data['samples']) : 0),
            ];
        }

        $overall = isset($durations['overall']) && is_array($durations['overall']) ? $durations['overall'] : [];
        $overall_average = isset($overall['average_seconds']) ? (float) $overall['average_seconds'] : null;
        $overall_p95 = isset($overall['p95_seconds']) ? (float) $overall['p95_seconds'] : null;
        $overall_peak = isset($overall['max_seconds']) ? (int) $overall['max_seconds'] : null;
        $overall_last = isset($overall['last_seconds']) ? (int) $overall['last_seconds'] : null;

        return [
            'destinations' => $formatted,
            'overall' => [
                'average_seconds' => $overall_average,
                'average_label' => $overall_average !== null ? $this->format_duration_label((int) round($overall_average)) : '',
                'p95_seconds' => $overall_p95,
                'p95_label' => $overall_p95 !== null ? $this->format_duration_label((int) round($overall_p95)) : '',
                'max_seconds' => $overall_peak,
                'peak_label' => $overall_peak !== null ? $this->format_duration_label($overall_peak) : '',
                'last_seconds' => $overall_last,
                'last_label' => $overall_last !== null ? $this->format_duration_label($overall_last) : '',
            ],
        ];
    }

    private function format_capacity_projections(array $projections, int $now): array {
        $destinations = isset($projections['destinations']) && is_array($projections['destinations'])
            ? $projections['destinations']
            : [];
        $formatted = [];

        foreach ($destinations as $destination_id => $data) {
            if (!is_array($data)) {
                continue;
            }

            $label = (string) $destination_id;
            if (class_exists(__NAMESPACE__ . '\\BJLG_Settings')) {
                $candidate = BJLG_Settings::get_destination_label($destination_id);
                if (is_string($candidate) && $candidate !== '') {
                    $label = $candidate;
                }
            }

            $hours_remaining = isset($data['hours_remaining']) ? (float) $data['hours_remaining'] : null;
            $seconds_remaining = isset($data['seconds_remaining']) ? (int) $data['seconds_remaining'] : null;
            [$projected_formatted, $projected_relative] = $this->format_timestamp_pair($data['projected_timestamp'] ?? null, $now);

            $trend_label = '';
            switch ($data['trend_direction'] ?? '') {
                case 'up':
                    $trend_label = __('Consommation en hausse', 'backup-jlg');
                    break;
                case 'down':
                    $trend_label = __('Consommation en baisse', 'backup-jlg');
                    break;
                default:
                    $trend_label = __('Consommation stable', 'backup-jlg');
                    break;
            }

            $risk_level = isset($data['risk_level']) ? (string) $data['risk_level'] : 'unknown';
            $risk_label = '';
            $risk_intent = 'info';
            switch ($risk_level) {
                case 'critical':
                    $risk_label = __('Critique', 'backup-jlg');
                    $risk_intent = 'error';
                    break;
                case 'warning':
                    $risk_label = __('Avertissement', 'backup-jlg');
                    $risk_intent = 'warning';
                    break;
                case 'watch':
                    $risk_label = __('Surveillance', 'backup-jlg');
                    $risk_intent = 'info';
                    break;
                case 'ok':
                    $risk_label = __('Nominal', 'backup-jlg');
                    $risk_intent = 'success';
                    break;
                default:
                    $risk_label = __('Inconnu', 'backup-jlg');
                    $risk_intent = 'info';
                    break;
            }

            $usage_ratio = isset($data['usage_ratio']) && is_numeric($data['usage_ratio']) ? (float) $data['usage_ratio'] : null;
            $usage_label = '';
            if ($usage_ratio !== null) {
                $percent = $usage_ratio * 100;
                $usage_label = function_exists('number_format_i18n')
                    ? number_format_i18n($percent, $percent >= 10 ? 0 : 1)
                    : number_format($percent, $percent >= 10 ? 0 : 1);
            }

            $formatted[] = [
                'id' => sanitize_key((string) $destination_id),
                'label' => sanitize_text_field($label),
                'risk_level' => $risk_level,
                'risk_label' => $risk_label,
                'risk_intent' => $risk_intent,
                'hours_remaining' => $hours_remaining,
                'hours_label' => $hours_remaining !== null ? $this->format_duration_label((int) round($hours_remaining * HOUR_IN_SECONDS)) : '',
                'seconds_remaining' => $seconds_remaining,
                'projection_label' => isset($data['projection_label']) ? sanitize_text_field((string) $data['projection_label']) : '',
                'projected_formatted' => $projected_formatted,
                'projected_relative' => $projected_relative,
                'trend_label' => $trend_label,
                'usage_percent_label' => $usage_label !== '' ? $usage_label . '%' : '',
            ];
        }

        $thresholds = isset($projections['thresholds']) && is_array($projections['thresholds'])
            ? $projections['thresholds']
            : [];
        $warning_hours = isset($thresholds['warning_hours']) ? (int) $thresholds['warning_hours'] : 72;
        $critical_hours = isset($thresholds['critical_hours']) ? (int) $thresholds['critical_hours'] : 24;
        $threshold_percent = isset($thresholds['threshold_percent'])
            ? (float) $thresholds['threshold_percent']
            : (isset($thresholds['threshold_ratio']) ? (float) $thresholds['threshold_ratio'] * 100 : 85.0);

        $summary_parts = [];
        if ($critical_hours > 0) {
            $summary_parts[] = sprintf(
                __('Critique < %s', 'backup-jlg'),
                $this->format_duration_label($critical_hours * HOUR_IN_SECONDS)
            );
        }
        if ($warning_hours > 0) {
            $summary_parts[] = sprintf(
                __('Avertissement < %s', 'backup-jlg'),
                $this->format_duration_label($warning_hours * HOUR_IN_SECONDS)
            );
        }
        $percent_label = function_exists('number_format_i18n')
            ? number_format_i18n((int) round($threshold_percent))
            : number_format((int) round($threshold_percent));
        $summary_parts[] = sprintf(__('Seuil de remplissage %s%%', 'backup-jlg'), $percent_label);

        return [
            'destinations' => $formatted,
            'thresholds' => [
                'warning_hours' => $warning_hours,
                'critical_hours' => $critical_hours,
                'threshold_percent' => $threshold_percent,
                'threshold_ratio' => isset($thresholds['threshold_ratio']) ? (float) $thresholds['threshold_ratio'] : ($threshold_percent / 100),
            ],
            'summary' => implode(' • ', $summary_parts),
        ];
    }

    private function format_destination_counts(array $counts): string {
        if (empty($counts)) {
            return '';
        }

        $formatted = [];
        foreach ($counts as $destination => $value) {
            if (!is_scalar($destination)) {
                continue;
            }

            $count = (int) $value;
            $label = (string) $destination;

            if (class_exists(__NAMESPACE__ . '\\BJLG_Settings')) {
                $label = BJLG_Settings::get_destination_label($destination);
            }

            $formatted[] = sprintf('%s (%s)', $label, number_format_i18n($count));
        }

        return implode(', ', $formatted);
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     */
    private function format_remote_purge_entries(array $entries, int $now, array $saturation_context = []): array {
        $formatted = [];

        foreach ($entries as $entry) {
            [$next_formatted, $next_relative] = $this->format_timestamp_pair($entry['next_attempt_at'] ?? null, $now);
            [$registered_formatted, $registered_relative] = $this->format_timestamp_pair($entry['registered_at'] ?? null, $now);

            $status = isset($entry['status']) ? (string) $entry['status'] : 'pending';
            $destinations_label = $this->format_destination_label($entry['destinations'] ?? []);

            $destination_alerts = [];
            if (!empty($entry['destinations']) && is_array($entry['destinations'])) {
                foreach ($entry['destinations'] as $destination_id) {
                    $key = sanitize_key((string) $destination_id);
                    if (isset($saturation_context[$key]) && is_array($saturation_context[$key])) {
                        $destination_alerts[] = $saturation_context[$key];
                    }
                }
            }

            $title = isset($entry['title']) && $entry['title'] !== ''
                ? $entry['title']
                : __('Archive inconnue', 'backup-jlg');

            $severity = 'info';
            if (!empty($entry['is_delayed']) || $status === 'failed') {
                $severity = 'critical';
            } elseif ($status === 'retry') {
                $severity = 'warning';
            }

            foreach ($destination_alerts as $alert_context) {
                if (!empty($alert_context['breach_imminent'])) {
                    $severity = 'critical';
                    break;
                }
            }

            $formatted[] = [
                'file' => sanitize_text_field($title),
                'title' => sanitize_text_field($title),
                'status' => $status,
                'status_label' => $this->get_queue_status_label($status),
                'status_intent' => $this->get_queue_status_intent($status),
                'attempts' => isset($entry['attempts']) ? (int) $entry['attempts'] : 0,
                'attempt_label' => $this->format_attempt_label(isset($entry['attempts']) ? (int) $entry['attempts'] : 0),
                'severity' => $severity,
                'severity_label' => $this->get_notification_severity_label($severity),
                'severity_intent' => $this->get_notification_severity_intent($severity),
                'next_attempt_relative' => $next_relative,
                'next_attempt_formatted' => $next_formatted,
                'created_relative' => $registered_relative,
                'created_formatted' => $registered_formatted,
                'message' => isset($entry['last_error']) ? (string) $entry['last_error'] : '',
                'details' => [
                    'destinations' => $destinations_label,
                    'delay' => $this->format_duration_label(isset($entry['max_delay']) ? (int) $entry['max_delay'] : 0),
                    'saturation' => $destination_alerts,
                ],
                'delayed' => !empty($entry['is_delayed']),
                'delay_label' => $this->format_duration_label(isset($entry['max_delay']) ? (int) $entry['max_delay'] : 0),
                'last_delay_label' => $this->format_duration_label(isset($entry['last_delay']) ? (int) $entry['last_delay'] : 0),
            ];
        }

        return $formatted;
    }

    private function format_duration_label(int $seconds): string {
        $seconds = max(0, $seconds);

        if ($seconds < MINUTE_IN_SECONDS) {
            if ($seconds <= 1) {
                return __('1 seconde', 'backup-jlg');
            }

            return sprintf(
                _n('%s seconde', '%s secondes', $seconds, 'backup-jlg'),
                number_format_i18n($seconds)
            );
        }

        if ($seconds < HOUR_IN_SECONDS) {
            $minutes = (int) floor($seconds / MINUTE_IN_SECONDS);
            if ($minutes <= 1) {
                return __('1 minute', 'backup-jlg');
            }

            return sprintf(
                _n('%s minute', '%s minutes', $minutes, 'backup-jlg'),
                number_format_i18n($minutes)
            );
        }

        $hours = (int) floor($seconds / HOUR_IN_SECONDS);
        $remaining_minutes = (int) floor(($seconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);

        $parts = [
            sprintf(
                _n('%s heure', '%s heures', $hours, 'backup-jlg'),
                number_format_i18n($hours)
            ),
        ];

        if ($remaining_minutes > 0) {
            $parts[] = sprintf(
                _n('%s minute', '%s minutes', $remaining_minutes, 'backup-jlg'),
                number_format_i18n($remaining_minutes)
            );
        }

        return implode(' ', $parts);
    }

    private function get_notification_channel_label($channel) {
        $channel = sanitize_key((string) $channel);

        switch ($channel) {
            case 'email':
                return __('E-mail', 'backup-jlg');
            case 'slack':
                return __('Slack', 'backup-jlg');
            case 'discord':
                return __('Discord', 'backup-jlg');
            case 'teams':
                return __('Microsoft Teams', 'backup-jlg');
            case 'sms':
                return __('SMS', 'backup-jlg');
            default:
                return $channel !== '' ? ucfirst(str_replace('_', ' ', $channel)) : '';
        }
    }

    private function get_notification_severity_label(string $severity): string {
        switch (strtolower($severity)) {
            case 'critical':
                return __('Critique', 'backup-jlg');
            case 'warning':
                return __('Avertissement', 'backup-jlg');
            case 'info':
            default:
                return __('Information', 'backup-jlg');
        }
    }

    private function get_notification_severity_intent(string $severity): string {
        switch (strtolower($severity)) {
            case 'critical':
                return 'error';
            case 'warning':
                return 'warning';
            case 'info':
            default:
                return 'info';
        }
    }

    private function get_resolution_status_label(string $status): string {
        switch ($status) {
            case 'resolved':
                return __('Résolu', 'backup-jlg');
            case 'acknowledged':
                return __('Accusé', 'backup-jlg');
            case 'pending':
            default:
                return __('En attente', 'backup-jlg');
        }
    }

    private function get_queue_status_label(string $status): string {
        switch ($status) {
            case 'completed':
                return __('Terminé', 'backup-jlg');
            case 'failed':
                return __('Échec', 'backup-jlg');
            case 'retry':
                return __('Nouvel essai planifié', 'backup-jlg');
            case 'processing':
                return __('En cours', 'backup-jlg');
            case 'pending':
            default:
                return __('En attente', 'backup-jlg');
        }
    }

    private function get_queue_status_intent(string $status): string {
        switch ($status) {
            case 'completed':
                return 'success';
            case 'failed':
                return 'error';
            case 'retry':
                return 'warning';
            case 'processing':
                return 'info';
            case 'pending':
            default:
                return 'info';
        }
    }

    private function format_timestamp_pair($timestamp, int $now): array {
        $timestamp = is_numeric($timestamp) ? (int) $timestamp : 0;
        if ($timestamp <= 0) {
            return ['', ''];
        }

        $formatted = $this->format_datetime($timestamp);

        if ($timestamp > $now) {
            return [$formatted, sprintf(__('dans %s', 'backup-jlg'), human_time_diff($now, $timestamp))];
        }

        return [$formatted, sprintf(__('il y a %s', 'backup-jlg'), human_time_diff($timestamp, $now))];
    }

    private function determine_refresh_state(int $timestamp, int $now): string {
        if ($timestamp <= 0) {
            return 'unknown';
        }

        $age = max(0, $now - $timestamp);

        if ($age <= 10 * MINUTE_IN_SECONDS) {
            return 'fresh';
        }

        if ($age <= HOUR_IN_SECONDS) {
            return 'stale';
        }

        return 'expired';
    }

    private function min_time($current, $candidate) {
        $candidate = is_numeric($candidate) ? (int) $candidate : 0;
        if ($candidate <= 0) {
            return $current === null ? null : (int) $current;
        }

        if ($current === null) {
            return $candidate;
        }

        $current = is_numeric($current) ? (int) $current : 0;
        if ($current <= 0) {
            return $candidate;
        }

        return min($current, $candidate);
    }

    private function format_attempt_label(int $attempts): string {
        if ($attempts <= 0) {
            return __('Aucune tentative', 'backup-jlg');
        }

        return sprintf(
            _n('%s tentative', '%s tentatives', $attempts, 'backup-jlg'),
            number_format_i18n($attempts)
        );
    }

    private function format_destination_label(array $destinations): string {
        $sanitized = [];
        foreach ($destinations as $destination) {
            $label = sanitize_text_field((string) $destination);
            if ($label !== '') {
                $sanitized[] = $label;
            }
        }

        if (empty($sanitized)) {
            return __('Aucune destination spécifiée', 'backup-jlg');
        }

        if (function_exists('wp_sprintf_l')) {
            return wp_sprintf_l('%l', $sanitized);
        }

        return implode(', ', $sanitized);
    }

    /**
     * Construit un ensemble de valeurs synthétiques prêtes à afficher.
     */
    private function build_summary(array $metrics): array {
        $history_stats = $metrics['history']['stats'] ?? [];
        $scheduler_stats = $metrics['scheduler']['stats'] ?? [];
        $storage = $metrics['storage'] ?? [];
        $resilience = isset($metrics['resilience']) && is_array($metrics['resilience']) ? $metrics['resilience'] : [];

        $success_rate = isset($scheduler_stats['success_rate']) ? (float) $scheduler_stats['success_rate'] : 0.0;
        $formatted_rate = sprintf('%s%%', number_format_i18n($success_rate, $success_rate >= 1 ? 0 : 2));

        $summary = [
            'history_total_actions' => intval($history_stats['total_actions'] ?? 0),
            'history_successful_backups' => intval($history_stats['by_action']['backup_created'] ?? 0),
            'history_last_backup' => __('Aucune sauvegarde effectuée', 'backup-jlg'),
            'history_last_backup_relative' => '',
            'scheduler_next_run' => __('Non planifié', 'backup-jlg'),
            'scheduler_next_run_relative' => '',
            'scheduler_active_count' => intval($metrics['scheduler']['active_count'] ?? 0),
            'scheduler_success_rate' => $formatted_rate,
            'storage_total_size_human' => $storage['total_size_human'] ?? size_format(0),
            'storage_backup_count' => intval($storage['backup_count'] ?? 0),
            'resilience_status' => $resilience['status'] ?? 'inactive',
            'resilience_summary' => $this->format_resilience_summary($resilience),
            'resilience_updated_relative' => $resilience['updated_relative'] ?? '',
        ];

        if (!empty($metrics['history']['last_backup'])) {
            $summary['history_last_backup'] = $metrics['history']['last_backup']['formatted'];
            $summary['history_last_backup_relative'] = $metrics['history']['last_backup']['relative'];
        }

        if (!empty($metrics['scheduler']['next_runs'][0])) {
            $summary['scheduler_next_run'] = $metrics['scheduler']['next_runs'][0]['next_run_formatted'];
            $summary['scheduler_next_run_relative'] = $metrics['scheduler']['next_runs'][0]['next_run_relative'];
        }

        if (!empty($metrics['sla_validation']['available'])) {
            $sla = $metrics['sla_validation'];
            $summary['sla_status'] = $sla['status'];
            $summary['sla_validated_at'] = $sla['validated_at'];
            $summary['sla_validated_relative'] = $sla['validated_relative'];
            $summary['sla_rto_human'] = $sla['objectives']['rto_human'] ?? '';
            $summary['sla_rpo_human'] = $sla['objectives']['rpo_human'] ?? '';
        }

        return $summary;
    }

    private function collect_resilience_metrics(): array {
        $now = current_time('timestamp');
        $settings = \bjlg_get_option('bjlg_managed_replication_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $enabled = !empty($settings['enabled']);
        $expected = isset($settings['expected_copies']) ? (int) $settings['expected_copies'] : 0;

        if (!class_exists(__NAMESPACE__ . '\\BJLG_Managed_Replication')) {
            return [
                'enabled' => $enabled,
                'status' => $enabled ? 'inactive' : 'disabled',
                'available_copies' => 0,
                'expected_copies' => $expected,
                'latency_ms' => null,
                'replicas' => [],
                'errors' => [],
                'updated_at' => 0,
                'updated_relative' => '',
            ];
        }

        $status = BJLG_Managed_Replication::get_status_snapshot();
        $available = isset($status['available_copies']) ? max(0, (int) $status['available_copies']) : 0;
        $expected_copies = isset($status['expected_copies']) ? max(0, (int) $status['expected_copies']) : $expected;
        $latency = isset($status['latency_ms']) && $status['latency_ms'] !== null ? (int) $status['latency_ms'] : null;
        $updated_at = isset($status['updated_at']) ? (int) $status['updated_at'] : 0;
        $updated_relative = $updated_at > 0 ? sprintf(__('il y a %s', 'backup-jlg'), human_time_diff($updated_at, $now)) : '';
        $status_code = isset($status['status']) ? (string) $status['status'] : ($enabled ? 'inactive' : 'disabled');
        $errors = isset($status['errors']) && is_array($status['errors']) ? array_map('strval', $status['errors']) : [];
        $replicas = isset($status['replicas']) && is_array($status['replicas']) ? $status['replicas'] : [];

        return [
            'enabled' => $enabled,
            'status' => $status_code,
            'available_copies' => $available,
            'expected_copies' => $expected_copies,
            'latency_ms' => $latency,
            'replicas' => $replicas,
            'errors' => $errors,
            'updated_at' => $updated_at,
            'updated_relative' => $updated_relative,
        ];
    }

    private function format_resilience_summary(array $resilience): string {
        $enabled = !empty($resilience['enabled']);
        $available = isset($resilience['available_copies']) ? max(0, (int) $resilience['available_copies']) : 0;
        $expected = isset($resilience['expected_copies']) ? max(0, (int) $resilience['expected_copies']) : ($enabled ? 1 : 0);
        $status = isset($resilience['status']) ? (string) $resilience['status'] : ($enabled ? 'inactive' : 'disabled');
        $latency = isset($resilience['latency_ms']) && $resilience['latency_ms'] !== null
            ? sprintf(__('Latence moyenne : %sms', 'backup-jlg'), number_format_i18n(max(0, (int) $resilience['latency_ms'])))
            : __('Latence non mesurée', 'backup-jlg');

        if (!$enabled && $expected === 0) {
            return __('Destination managée inactive.', 'backup-jlg');
        }

        $base = sprintf(
            __('Copies disponibles : %1$s / %2$s', 'backup-jlg'),
            number_format_i18n($available),
            number_format_i18n(max(1, $expected))
        );

        if ($status === 'healthy') {
            return $base . ' • ' . $latency;
        }

        if ($status === 'degraded') {
            return $base . ' • ' . $latency . ' — ' . __('Réplication partielle détectée.', 'backup-jlg');
        }

        if ($status === 'failed') {
            return $base . ' • ' . __('Aucune copie valide', 'backup-jlg');
        }

        return $base . ' • ' . $latency;
    }

    private function collect_remote_storage_metrics(): array {
        $threshold_percent = $this->get_storage_warning_threshold_percent();
        $threshold_ratio = max(0.01, min(1.0, $threshold_percent / 100));
        $now = current_time('timestamp');

        if (!class_exists(BJLG_Remote_Storage_Metrics::class)) {
            return [
                'destinations' => [],
                'generated_at' => 0,
                'generated_at_formatted' => '',
                'generated_at_relative' => '',
                'stale' => false,
                'threshold_percent' => $threshold_percent,
                'threshold_ratio' => $threshold_ratio,
            ];
        }

        $snapshot = BJLG_Remote_Storage_Metrics::get_snapshot();
        $generated_at = isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : 0;
        $stale = !empty($snapshot['stale']);
        $destinations = isset($snapshot['destinations']) && is_array($snapshot['destinations'])
            ? $snapshot['destinations']
            : [];

        $formatted = $generated_at > 0 ? $this->format_datetime($generated_at) : '';
        $relative = '';
        if ($generated_at > 0) {
            $relative = sprintf(__('il y a %s', 'backup-jlg'), human_time_diff($generated_at, $now));
        }

        $digest = get_option(BJLG_Remote_Storage_Metrics::WARNING_DIGEST_OPTION, []);
        if (!is_array($digest)) {
            $digest = [];
        }

        $digest_updated = false;
        $seen_destinations = [];

        foreach ($destinations as &$destination) {
            if (!is_array($destination)) {
                $destination = [];
            }

            $destination_id = isset($destination['id']) ? sanitize_key((string) $destination['id']) : '';
            $name = isset($destination['name']) ? (string) $destination['name'] : $destination_id;

            if ($destination_id !== '') {
                $seen_destinations[$destination_id] = true;
            }

            $used_bytes = isset($destination['used_bytes']) ? $this->sanitize_metric_value($destination['used_bytes']) : null;
            $quota_bytes = isset($destination['quota_bytes']) ? $this->sanitize_metric_value($destination['quota_bytes']) : null;
            $free_bytes = isset($destination['free_bytes']) ? $this->sanitize_metric_value($destination['free_bytes']) : null;

            $destination['used_bytes'] = $used_bytes;
            $destination['quota_bytes'] = $quota_bytes;
            $destination['free_bytes'] = $free_bytes;

            if ($used_bytes !== null) {
                $destination['used_human'] = size_format((int) $used_bytes);
            } else {
                $destination['used_human'] = isset($destination['used_human']) ? (string) $destination['used_human'] : '';
            }

            if ($quota_bytes !== null) {
                $destination['quota_human'] = size_format((int) $quota_bytes);
            } else {
                $destination['quota_human'] = isset($destination['quota_human']) ? (string) $destination['quota_human'] : '';
            }

            if ($free_bytes === null && $quota_bytes !== null && $used_bytes !== null) {
                $free_bytes = max(0, (int) $quota_bytes - (int) $used_bytes);
                $destination['free_bytes'] = $free_bytes;
            }

            if ($free_bytes !== null) {
                $destination['free_human'] = size_format((int) $free_bytes);
            } else {
                $destination['free_human'] = isset($destination['free_human']) ? (string) $destination['free_human'] : '';
            }

            $ratio = null;
            if ($quota_bytes !== null && $quota_bytes > 0 && $used_bytes !== null) {
                $ratio = max(0.0, min(1.0, $used_bytes / max(1, $quota_bytes)));
            }

            $destination['utilization_ratio'] = $ratio;
            $destination['threshold_percent'] = $threshold_percent;
            $destination['threshold_ratio'] = $threshold_ratio;
            $destination['last_refreshed_at'] = $generated_at;

            if ($ratio !== null && $ratio >= $threshold_ratio && !$stale && $destination_id !== '') {
                $last_notified = isset($digest[$destination_id]) ? (int) $digest[$destination_id] : 0;
                if ($generated_at > $last_notified) {
                    do_action('bjlg_storage_warning', [
                        'destination_id' => $destination_id,
                        'name' => $name,
                        'ratio' => $ratio,
                        'threshold_percent' => $threshold_percent,
                        'threshold' => (int) round($threshold_percent),
                        'threshold_ratio' => $threshold_ratio,
                        'used_bytes' => $used_bytes,
                        'quota_bytes' => $quota_bytes,
                        'free_bytes' => $destination['free_bytes'],
                        'generated_at' => $generated_at,
                    ]);
                    $digest[$destination_id] = $generated_at;
                    $digest_updated = true;
                }
            }

            if ($destination_id !== '') {
                if ($ratio !== null && $ratio >= $threshold_ratio) {
                    $destination['badge'] = 'critical';
                } elseif ($days_to_threshold !== null && $days_to_threshold <= 1) {
                    $destination['badge'] = 'critical';
                } elseif ($days_to_threshold !== null && $days_to_threshold <= 3) {
                    $destination['badge'] = 'warning';
                } elseif ($projection_intent === 'success') {
                    $destination['badge'] = 'success';
                }
            }
        }
        unset($destination);

        if ($digest_updated) {
            if (!empty($seen_destinations)) {
                $digest = array_intersect_key($digest, $seen_destinations);
            }

            update_option(BJLG_Remote_Storage_Metrics::WARNING_DIGEST_OPTION, $digest);
        }

        return [
            'destinations' => $destinations,
            'generated_at' => $generated_at,
            'generated_at_formatted' => $formatted,
            'generated_at_relative' => $relative,
            'stale' => $stale,
            'threshold_percent' => $threshold_percent,
            'threshold_ratio' => $threshold_ratio,
        ];
    }

    private function get_storage_warning_threshold_percent(): float {
        if (!class_exists(BJLG_Settings::class) || !method_exists(BJLG_Settings::class, 'get_storage_warning_threshold')) {
            return 85.0;
        }

        return (float) BJLG_Settings::get_storage_warning_threshold();
    }

    private function get_encryption_metrics(): array {
        if (!class_exists(BJLG_Encryption::class)) {
            return [];
        }

        try {
            $service = new BJLG_Encryption();
            $stats = $service->get_encryption_stats();

            if (!is_array($stats)) {
                return [];
            }

            $encrypted = isset($stats['encrypted_count']) ? (int) $stats['encrypted_count'] : 0;
            $unencrypted = isset($stats['unencrypted_count']) ? (int) $stats['unencrypted_count'] : 0;
            $total = max(0, $encrypted + $unencrypted);
            $ratio = $total > 0 ? max(0, min(1, $encrypted / $total)) : null;

            $stats['encrypted_count'] = $encrypted;
            $stats['unencrypted_count'] = $unencrypted;
            $stats['total_archives'] = $total;
            $stats['encrypted_ratio'] = $ratio;

            return $stats;
        } catch (\Throwable $exception) {
            return [
                'encrypted_count' => 0,
                'unencrypted_count' => 0,
                'total_archives' => 0,
                'encrypted_ratio' => null,
                'encryption_enabled' => false,
                'error' => true,
                'error_message' => $exception->getMessage(),
            ];
        }
    }

    private function get_restore_self_test_snapshot(int $now): array {
        $snapshot = get_option('bjlg_restore_self_test_report', []);
        if (!is_array($snapshot) || empty($snapshot)) {
            return [];
        }

        $status = isset($snapshot['status']) ? sanitize_key((string) $snapshot['status']) : '';
        $message = isset($snapshot['message']) ? (string) $snapshot['message'] : '';
        $archive = isset($snapshot['archive']) ? (string) $snapshot['archive'] : '';
        $generated_at = isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : 0;
        $metrics = isset($snapshot['metrics']) && is_array($snapshot['metrics']) ? $snapshot['metrics'] : [];

        $result = [
            'status' => $status,
            'message' => $message,
            'archive' => $archive,
            'generated_at' => $generated_at,
            'generated_at_formatted' => $generated_at > 0 ? $this->format_datetime($generated_at) : '',
            'generated_at_relative' => $generated_at > 0
                ? sprintf(__('il y a %s', 'backup-jlg'), human_time_diff($generated_at, $now))
                : '',
            'rto_seconds' => isset($metrics['rto_seconds']) ? (float) $metrics['rto_seconds'] : null,
            'rpo_seconds' => isset($metrics['rpo_seconds']) ? (int) $metrics['rpo_seconds'] : null,
            'rto_human' => isset($metrics['rto_human']) ? (string) $metrics['rto_human'] : '',
            'rpo_human' => isset($metrics['rpo_human']) ? (string) $metrics['rpo_human'] : '',
            'files' => isset($snapshot['files']) && is_array($snapshot['files'])
                ? $this->sanitize_report_files($snapshot['files'])
                : [],
            'summary_markdown' => isset($snapshot['summary_markdown']) ? (string) $snapshot['summary_markdown'] : '',
        ];

        return $result;
    }

    private function build_reliability(array $metrics): array {
        $score = 100.0;
        $pillars = [];
        $insights = [];
        $recommendations = [];

        $summary = $metrics['summary'] ?? [];
        $scheduler = $metrics['scheduler'] ?? [];
        $scheduler_stats = $scheduler['stats'] ?? [];
        $active_count = isset($scheduler['active_count']) ? (int) $scheduler['active_count'] : 0;
        $success_rate = isset($scheduler_stats['success_rate']) ? (float) $scheduler_stats['success_rate'] : 0.0;
        $next_run_relative = $summary['scheduler_next_run_relative'] ?? ($summary['scheduler_next_run'] ?? '');

        $scheduler_intent = 'success';
        $scheduler_message = '';

        if (!empty($scheduler['overdue'])) {
            $scheduler_intent = 'danger';
            $scheduler_message = __('Une sauvegarde planifiée est en retard.', 'backup-jlg');
            $score -= 22;
            $insights[] = __('Planification en retard', 'backup-jlg');
            $this->add_recommendation($recommendations, __('Vérifier la planification automatique', 'backup-jlg'), add_query_arg(['page' => 'backup-jlg', 'section' => 'backup'], admin_url('admin.php')), 'primary');
        } elseif ($active_count === 0) {
            $scheduler_intent = 'warning';
            $scheduler_message = __('Aucune planification active.', 'backup-jlg');
            $score -= 12;
            $insights[] = __('Ajouter une planification récurrente', 'backup-jlg');
            $this->add_recommendation($recommendations, __('Créer une planification automatique', 'backup-jlg'), add_query_arg(['page' => 'backup-jlg', 'section' => 'backup'], admin_url('admin.php')), 'primary');
        } else {
            $formatted_rate = sprintf('%s%%', number_format_i18n($success_rate, $success_rate >= 1 ? 0 : 1));
            $scheduler_message = sprintf(
                _n('%1$s planification active • succès %2$s', '%1$s planifications actives • succès %2$s', $active_count, 'backup-jlg'),
                number_format_i18n($active_count),
                $formatted_rate
            );

            if ($success_rate < 75) {
                $scheduler_intent = 'warning';
                $score -= 8;
                $insights[] = __('Améliorer la stabilité des planifications', 'backup-jlg');
                $scheduler_message .= ' — ' . __('Taux de réussite sous les standards pro.', 'backup-jlg');
                $this->add_recommendation($recommendations, __('Analyser les rapports de sauvegarde', 'backup-jlg'), add_query_arg(['page' => 'backup-jlg', 'section' => 'monitoring'], admin_url('admin.php')));
            }
        }

        if ($next_run_relative !== '') {
            $scheduler_message .= ' • ' . sprintf(__('Prochain passage %s', 'backup-jlg'), $next_run_relative);
        }

        $pillars[] = [
            'key' => 'scheduler',
            'label' => __('Planification', 'backup-jlg'),
            'message' => $scheduler_message,
            'intent' => $scheduler_intent,
            'icon' => 'dashicons-clock',
        ];

        $self_test = isset($metrics['restore_self_test']) && is_array($metrics['restore_self_test'])
            ? $metrics['restore_self_test']
            : [];
        $self_test_status = isset($self_test['status']) ? sanitize_key((string) $self_test['status']) : '';
        $self_test_intent = 'warning';
        $self_test_message = __('Aucun test de restauration enregistré.', 'backup-jlg');
        $history_url = add_query_arg(['page' => 'backup-jlg', 'section' => 'history'], admin_url('admin.php')) . '#bjlg-history';

        if (empty($self_test)) {
            $score -= 10;
            $insights[] = __('Planifier un test de restauration automatique', 'backup-jlg');
            $this->add_recommendation($recommendations, __('Lancer un test de restauration', 'backup-jlg'), $history_url, 'primary');
        } else {
            $message_parts = [];
            $rto_human = isset($self_test['rto_human']) ? (string) $self_test['rto_human'] : '';
            $rpo_human = isset($self_test['rpo_human']) ? (string) $self_test['rpo_human'] : '';
            $generated_relative = isset($self_test['generated_at_relative']) ? (string) $self_test['generated_at_relative'] : '';

            if ($rto_human !== '') {
                $message_parts[] = sprintf(__('RTO %s', 'backup-jlg'), $rto_human);
            }

            if ($rpo_human !== '') {
                $message_parts[] = sprintf(__('RPO %s', 'backup-jlg'), $rpo_human);
            }

            if ($generated_relative !== '') {
                $message_parts[] = sprintf(__('mis à jour %s', 'backup-jlg'), $generated_relative);
            }

            $self_test_message = !empty($message_parts)
                ? implode(' • ', $message_parts)
                : __('Tests de restauration en cours de suivi.', 'backup-jlg');

            if ($self_test_status === 'failure') {
                $self_test_intent = 'danger';
                $score -= 18;
                $self_test_message = __('Dernier test de restauration en échec.', 'backup-jlg');
                $insights[] = __('Corriger le test de restauration automatique', 'backup-jlg');
                $this->add_recommendation($recommendations, __('Consulter le rapport de test', 'backup-jlg'), $history_url, 'primary');
            } elseif ($self_test_status === 'success') {
                $self_test_intent = 'success';
                $rpo_seconds = isset($self_test['rpo_seconds']) ? (int) $self_test['rpo_seconds'] : null;
                if ($rpo_seconds !== null && $rpo_seconds > 2 * DAY_IN_SECONDS) {
                    $self_test_intent = 'warning';
                    $score -= 6;
                    $self_test_message .= ' — ' . __('Archive testée trop ancienne.', 'backup-jlg');
                    $insights[] = __('Réduire l’âge des archives testées', 'backup-jlg');
                    $this->add_recommendation(
                        $recommendations,
                        __('Planifier une sauvegarde complète', 'backup-jlg'),
                        add_query_arg(['page' => 'backup-jlg', 'section' => 'backup'], admin_url('admin.php')),
                        'primary'
                    );
                }
            } else {
                $self_test_intent = 'warning';
                $score -= 6;
                $insights[] = __('Exécuter un test de restauration', 'backup-jlg');
                $this->add_recommendation($recommendations, __('Lancer un test de restauration', 'backup-jlg'), $history_url, 'primary');
            }
        }

        $pillars[] = [
            'key' => 'restore_self_test',
            'label' => __('Tests de restauration', 'backup-jlg'),
            'message' => $self_test_message,
            'intent' => $self_test_intent,
            'icon' => 'dashicons-backup',
        ];

        $encryption = isset($metrics['encryption']) && is_array($metrics['encryption']) ? $metrics['encryption'] : [];
        $encryption_enabled = !empty($encryption['encryption_enabled']);
        $encrypted_count = isset($encryption['encrypted_count']) ? (int) $encryption['encrypted_count'] : 0;
        $unencrypted_count = isset($encryption['unencrypted_count']) ? (int) $encryption['unencrypted_count'] : 0;
        $total_archives = isset($encryption['total_archives']) ? (int) $encryption['total_archives'] : ($encrypted_count + $unencrypted_count);
        $encrypted_ratio = $total_archives > 0 ? max(0, min(1, $encrypted_count / max(1, $total_archives))) : ($encryption_enabled ? 1.0 : 0.0);
        $encryption_intent = $encryption_enabled ? 'success' : 'warning';
        $encryption_message = '';

        if (!empty($encryption['error'])) {
            $encryption_intent = 'warning';
            $encryption_message = __('Impossible de vérifier le chiffrement des archives.', 'backup-jlg');
            $score -= 8;
            $insights[] = __('Valider le module de chiffrement', 'backup-jlg');
        } elseif (!$encryption_enabled) {
            $encryption_intent = 'warning';
            $encryption_message = __('Le chiffrement AES-256 est désactivé.', 'backup-jlg');
            $score -= 18;
            $insights[] = __('Activer le chiffrement des sauvegardes', 'backup-jlg');
            $this->add_recommendation(
                $recommendations,
                __('Activer le chiffrement AES-256', 'backup-jlg'),
                add_query_arg(['page' => 'backup-jlg', 'section' => 'settings'], admin_url('admin.php')) . '#bjlg-encryption-settings'
            );
        } else {
            $percentage = (int) round($encrypted_ratio * 100);
            $encryption_message = sprintf(__('Chiffrement actif • %s%% des archives sécurisées', 'backup-jlg'), number_format_i18n($percentage));

            if ($total_archives > 0 && $encrypted_ratio < 0.6) {
                $encryption_intent = 'warning';
                $score -= 6;
                $insights[] = __('Sécuriser les archives restantes', 'backup-jlg');
                $encryption_message .= ' — ' . __('Certaines archives restent non chiffrées.', 'backup-jlg');
            }
        }

        if (!empty($encryption['total_encrypted_size'])) {
            $encryption_message .= ' • ' . sprintf(__('Volume protégé : %s', 'backup-jlg'), $encryption['total_encrypted_size']);
        }

        $pillars[] = [
            'key' => 'encryption',
            'label' => __('Chiffrement', 'backup-jlg'),
            'message' => $encryption_message,
            'intent' => $encryption_intent,
            'icon' => 'dashicons-lock',
        ];

        $storage = $metrics['storage'] ?? [];
        $remote_destinations = isset($storage['remote_destinations']) && is_array($storage['remote_destinations']) ? $storage['remote_destinations'] : [];
        $remote_total = count($remote_destinations);
        $remote_connected = 0;
        $remote_with_errors = 0;

        foreach ($remote_destinations as $destination) {
            if (!is_array($destination)) {
                continue;
            }

            if (!empty($destination['connected'])) {
                $remote_connected++;
            }

            if (!empty($destination['errors'])) {
                $remote_with_errors++;
            }
        }

        $storage_intent = 'success';
        $storage_message = '';

        if ($remote_total === 0) {
            $storage_intent = 'warning';
            $storage_message = __('Aucune destination distante configurée.', 'backup-jlg');
            $score -= 8;
            $insights[] = __('Ajouter une redondance hors-site', 'backup-jlg');
            $this->add_recommendation($recommendations, __('Ajouter un stockage distant', 'backup-jlg'), add_query_arg(['page' => 'backup-jlg', 'section' => 'settings'], admin_url('admin.php')));
        } else {
            $storage_message = sprintf(
                __('Destinations distantes actives : %1$s / %2$s', 'backup-jlg'),
                number_format_i18n($remote_connected),
                number_format_i18n($remote_total)
            );

            if ($remote_connected === 0 || $remote_with_errors > 0) {
                $storage_intent = 'warning';
                $score -= 12;
                if ($remote_connected === 0) {
                    $storage_message .= ' — ' . __('Aucune connexion valide détectée.', 'backup-jlg');
                    $insights[] = __('Réparer les connexions distantes', 'backup-jlg');
                } else {
                    $storage_message .= ' — ' . __('Certaines destinations signalent des erreurs.', 'backup-jlg');
                    $insights[] = __('Vérifier les destinations distantes', 'backup-jlg');
                }
                $this->add_recommendation($recommendations, __('Reconfigurer la destination distante', 'backup-jlg'), add_query_arg(['page' => 'backup-jlg', 'section' => 'settings'], admin_url('admin.php')));
            } elseif (!empty($storage['total_size_human'])) {
                $storage_message .= ' • ' . sprintf(__('Stockage local : %s', 'backup-jlg'), $storage['total_size_human']);
            }

            if (!empty($resilience) && !empty($resilience['enabled'])) {
                $replication_summary = $this->format_resilience_summary($resilience);
                if ($replication_summary !== '') {
                    $storage_message .= ' • ' . $replication_summary;
                }
            }
        }

        $pillars[] = [
            'key' => 'storage',
            'label' => __('Redondance', 'backup-jlg'),
            'message' => $storage_message,
            'intent' => $storage_intent,
            'icon' => 'dashicons-database',
        ];

        $recent_failures = isset($metrics['history']['recent_failures']) && is_array($metrics['history']['recent_failures']) ? $metrics['history']['recent_failures'] : [];
        $history_intent = 'success';
        $history_message = __('Aucun échec récent détecté.', 'backup-jlg');

        if (!empty($recent_failures)) {
            $history_intent = count($recent_failures) > 1 ? 'danger' : 'warning';
            $recent = $recent_failures[0];
            $relative = isset($recent['relative']) ? (string) $recent['relative'] : (isset($recent['formatted']) ? (string) $recent['formatted'] : '');
            $history_message = $relative !== ''
                ? sprintf(__('Dernier échec %s', 'backup-jlg'), $relative)
                : __('Des échecs récents nécessitent une attention.', 'backup-jlg');
            $score -= count($recent_failures) > 1 ? 12 : 8;
            $insights[] = __('Analyser les journaux d’erreur', 'backup-jlg');
            $this->add_recommendation($recommendations, __('Consulter les journaux détaillés', 'backup-jlg'), add_query_arg(['page' => 'backup-jlg', 'section' => 'monitoring'], admin_url('admin.php')));
        } elseif (!empty($summary['history_last_backup_relative'])) {
            $history_message = sprintf(__('Dernière sauvegarde réussie %s', 'backup-jlg'), $summary['history_last_backup_relative']);
        }

        $pillars[] = [
            'key' => 'history',
            'label' => __('Fiabilité récente', 'backup-jlg'),
            'message' => $history_message,
            'intent' => $history_intent,
            'icon' => 'dashicons-shield-alt',
        ];

        $score = max(0, min(100, (int) round($score)));

        if ($score >= 90) {
            $level = __('Niveau pro', 'backup-jlg');
            $intent = 'success';
            $base_description = __('Tous les garde-fous majeurs sont alignés avec les extensions professionnelles.', 'backup-jlg');
        } elseif ($score >= 75) {
            $level = __('Solide', 'backup-jlg');
            $intent = 'success';
            $base_description = __('La configuration se rapproche des standards premium, quelques optimisations restent possibles.', 'backup-jlg');
        } elseif ($score >= 60) {
            $level = __('À surveiller', 'backup-jlg');
            $intent = 'warning';
            $base_description = __('Renforcez les points signalés pour atteindre la fiabilité attendue d’une solution professionnelle.', 'backup-jlg');
        } else {
            $level = __('Critique', 'backup-jlg');
            $intent = 'danger';
            $base_description = __('Priorité : résoudre les alertes critiques pour sécuriser vos sauvegardes.', 'backup-jlg');
        }

        $description = $base_description;
        if (!empty($insights)) {
            $description .= ' ' . implode(' ', array_slice(array_unique($insights), 0, 2));
        }

        return [
            'score' => $score,
            'score_label' => sprintf(_x('%s / 100', 'Indice de fiabilité', 'backup-jlg'), number_format_i18n($score)),
            'level' => $level,
            'description' => $description,
            'caption' => __('Comparaison avec les standards professionnels : planification, chiffrement et redondance.', 'backup-jlg'),
            'intent' => $intent,
            'pillars' => $pillars,
            'recommendations' => $recommendations,
        ];
    }

    private function add_recommendation(array &$recommendations, string $label, string $url, string $intent = 'secondary'): void {
        foreach ($recommendations as $entry) {
            if (isset($entry['label']) && $entry['label'] === $label) {
                return;
            }
        }

        $recommendations[] = [
            'label' => $label,
            'url' => $url,
            'intent' => $intent,
        ];
    }

    private function sanitize_metric_value($value) {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $int_value = (int) $value;

            return $int_value >= 0 ? $int_value : null;
        }

        return null;
    }

    private function sanitize_report_files(array $files): array {
        $sanitized = [];
        foreach (['json', 'html', 'markdown'] as $type) {
            if (isset($files[$type]) && is_array($files[$type])) {
                $entry = $files[$type];
                $sanitized[$type] = [
                    'filename' => isset($entry['filename']) ? (string) $entry['filename'] : '',
                    'path' => isset($entry['path']) ? (string) $entry['path'] : '',
                    'url' => isset($entry['url']) ? (string) $entry['url'] : '',
                    'mime_type' => isset($entry['mime_type']) ? (string) $entry['mime_type'] : '',
                ];
            }
        }

        if (isset($files['base_path'])) {
            $sanitized['base_path'] = (string) $files['base_path'];
        }

        if (isset($files['base_url'])) {
            $sanitized['base_url'] = (string) $files['base_url'];
        }

        return $sanitized;
    }

    /**
     * Génère la liste d'alertes à partir des métriques agrégées.
     */
    private function build_alerts(array $metrics): array {
        $alerts = [];

        $backup_count = intval($metrics['storage']['backup_count'] ?? 0);
        if ($backup_count === 0) {
            $alerts[] = $this->make_alert(
                'info',
                __('Aucune sauvegarde détectée', 'backup-jlg'),
                __('Lancez votre première sauvegarde pour protéger votre site.', 'backup-jlg'),
                [
                    'label' => __('Créer une sauvegarde', 'backup-jlg'),
                    'url' => add_query_arg(
                        ['page' => 'backup-jlg', 'section' => 'backup'],
                        admin_url('admin.php')
                    ),
                ]
            );
        }

        $history_stats = $metrics['history']['stats'] ?? [];
        $failed = intval($history_stats['failed'] ?? 0);
        if ($failed > 0) {
            $alerts[] = $this->make_alert(
                'warning',
                __('Des actions ont échoué récemment', 'backup-jlg'),
                sprintf(_n('%s action a échoué sur les 30 derniers jours.', '%s actions ont échoué sur les 30 derniers jours.', $failed, 'backup-jlg'), number_format_i18n($failed)),
                [
                    'label' => __('Consulter les logs', 'backup-jlg'),
                    'url' => add_query_arg(
                        ['page' => 'backup-jlg', 'section' => 'monitoring'],
                        admin_url('admin.php')
                    ),
                ]
            );
        }

        $restore = isset($metrics['restore_self_test']) && is_array($metrics['restore_self_test'])
            ? $metrics['restore_self_test']
            : [];
        $history_url = add_query_arg(['page' => 'backup-jlg', 'section' => 'history'], admin_url('admin.php')) . '#bjlg-history';
        if (empty($restore)) {
            $alerts[] = $this->make_alert(
                'warning',
                __('Test de restauration automatique non exécuté', 'backup-jlg'),
                __('Lancez un test de restauration pour valider vos procédures de reprise.', 'backup-jlg'),
                [
                    'label' => __('Ouvrir l’historique', 'backup-jlg'),
                    'url' => $history_url,
                ]
            );
        } else {
            $status = isset($restore['status']) ? sanitize_key((string) $restore['status']) : '';
            if ($status === 'failure') {
                $alerts[] = $this->make_alert(
                    'error',
                    __('Dernier test de restauration en échec', 'backup-jlg'),
                    __('Consultez le rapport détaillé et corrigez l’incident avant la prochaine sauvegarde.', 'backup-jlg'),
                    [
                        'label' => __('Voir le rapport', 'backup-jlg'),
                        'url' => $history_url,
                    ]
                );
            } elseif (!empty($restore['rpo_seconds']) && (int) $restore['rpo_seconds'] > 2 * DAY_IN_SECONDS) {
                $rpo_label = isset($restore['rpo_human']) && $restore['rpo_human'] !== ''
                    ? (string) $restore['rpo_human']
                    : sprintf(__('il y a %s', 'backup-jlg'), human_time_diff(time() - (int) $restore['rpo_seconds'], time()));
                $alerts[] = $this->make_alert(
                    'warning',
                    __('RPO dégradé sur le test de restauration', 'backup-jlg'),
                    sprintf(__('L’archive testée datait de %s. Planifiez une nouvelle sauvegarde complète.', 'backup-jlg'), $rpo_label),
                    [
                        'label' => __('Planifier une sauvegarde', 'backup-jlg'),
                        'url' => add_query_arg(['page' => 'backup-jlg', 'section' => 'backup'], admin_url('admin.php')),
                    ]
                );
            }
        }

        $sla_panel = $metrics['sla_validation'] ?? [];
        if (!empty($sla_panel['available'])) {
            $sla_status = isset($sla_panel['status']) ? sanitize_key((string) $sla_panel['status']) : 'info';
            if ($sla_status === 'failure') {
                $alerts[] = $this->make_alert(
                    'error',
                    __('Validation SLA en échec', 'backup-jlg'),
                    __('La dernière restauration sandbox automatisée a échoué. Consultez le rapport pour appliquer les actions de remédiation.', 'backup-jlg'),
                    [
                        'label' => __('Ouvrir le rapport SLA', 'backup-jlg'),
                        'url' => add_query_arg(['page' => 'backup-jlg', 'section' => 'monitoring'], admin_url('admin.php')) . '#bjlg-sla-panel',
                    ]
                );
            }
        } else {
            $alerts[] = $this->make_alert(
                'warning',
                __('Validation SLA non réalisée', 'backup-jlg'),
                __('Aucune restauration sandbox planifiée n’a encore été exécutée. Programmez une validation pour mesurer votre RTO/RPO.', 'backup-jlg'),
                [
                    'label' => __('Programmer la sandbox', 'backup-jlg'),
                    'url' => add_query_arg(['page' => 'backup-jlg', 'section' => 'monitoring'], admin_url('admin.php')),
                ]
            );
        }

        if (!empty($metrics['scheduler']['overdue'])) {
            $alerts[] = $this->make_alert(
                'error',
                __('Planification en retard', 'backup-jlg'),
                __('Au moins une sauvegarde planifiée semble ne pas s’être exécutée à temps.', 'backup-jlg'),
                [
                    'label' => __('Vérifier la planification', 'backup-jlg'),
                    'url' => add_query_arg(
                        ['page' => 'backup-jlg', 'section' => 'backup'],
                        admin_url('admin.php')
                    ) . '#bjlg-schedule',
                ]
            );
        }

        $resilience = isset($metrics['resilience']) && is_array($metrics['resilience']) ? $metrics['resilience'] : [];
        $resilience_status = isset($resilience['status']) ? (string) $resilience['status'] : 'inactive';
        if (in_array($resilience_status, ['degraded', 'failed'], true)) {
            $available = isset($resilience['available_copies']) ? max(0, (int) $resilience['available_copies']) : 0;
            $expected = isset($resilience['expected_copies']) ? max(1, (int) $resilience['expected_copies']) : 1;
            $errors = isset($resilience['errors']) && is_array($resilience['errors']) ? $resilience['errors'] : [];
            $description = sprintf(
                __('Copies disponibles : %1$s / %2$s. Vérifiez les régions configurées et l’état des destinations secondaires.', 'backup-jlg'),
                number_format_i18n($available),
                number_format_i18n($expected)
            );

            if (!empty($errors)) {
                $description .= ' ' . implode(' | ', array_map('sanitize_text_field', $errors));
            }

            $alerts[] = $this->make_alert(
                $resilience_status === 'failed' ? 'error' : 'warning',
                $resilience_status === 'failed' ? __('Réplication managée indisponible', 'backup-jlg') : __('Réplication managée dégradée', 'backup-jlg'),
                $description,
                [
                    'label' => __('Ouvrir les destinations', 'backup-jlg'),
                    'url' => add_query_arg(['page' => 'backup-jlg', 'section' => 'settings'], admin_url('admin.php')) . '#bjlg-destinations',
                ]
            );
        }

        $remote_destinations = isset($metrics['storage']['remote_destinations']) && is_array($metrics['storage']['remote_destinations'])
            ? $metrics['storage']['remote_destinations']
            : [];
        if (!empty($remote_destinations)) {
            $remote_threshold_percent = isset($metrics['storage']['remote_warning_threshold'])
                ? max(1.0, min(100.0, (float) $metrics['storage']['remote_warning_threshold']))
                : 85.0;
            $remote_threshold_ratio = isset($metrics['storage']['remote_warning_threshold_ratio'])
                ? max(0.01, min(1.0, (float) $metrics['storage']['remote_warning_threshold_ratio']))
                : max(0.01, min(1.0, $remote_threshold_percent / 100));

            $over_threshold = [];
            $max_ratio = 0.0;

            foreach ($remote_destinations as $destination) {
                if (!is_array($destination)) {
                    continue;
                }

                $ratio = null;
                if (isset($destination['utilization_ratio']) && is_numeric($destination['utilization_ratio'])) {
                    $ratio = max(0.0, min(1.0, (float) $destination['utilization_ratio']));
                } elseif (isset($destination['ratio']) && is_numeric($destination['ratio'])) {
                    $ratio = max(0.0, min(1.0, (float) $destination['ratio']));
                }

                if ($ratio === null) {
                    continue;
                }

                $name = isset($destination['name']) && is_string($destination['name']) && $destination['name'] !== ''
                    ? sanitize_text_field($destination['name'])
                    : (isset($destination['id']) ? sanitize_key((string) $destination['id']) : '');

                if ($ratio >= $remote_threshold_ratio) {
                    if ($name !== '') {
                        $over_threshold[] = $name;
                    }
                    $max_ratio = max($max_ratio, $ratio);
                }
            }

            if (!empty($over_threshold)) {
                $names_label = function_exists('wp_sprintf_l')
                    ? wp_sprintf_l('%l', $over_threshold)
                    : implode(', ', $over_threshold);
                $max_ratio_percent = max(0, min(100, round($max_ratio * 100)));
                $threshold_percent_label = number_format_i18n($remote_threshold_percent, $remote_threshold_percent >= 10 ? 0 : 1);
                $message = sprintf(
                    _n(
                        'La destination %1$s dépasse le seuil de %2$s%% configuré pour le stockage distant (pic mesuré : %3$s%%).',
                        'Les destinations %1$s dépassent le seuil de %2$s%% configuré pour le stockage distant (pic mesuré : %3$s%%).',
                        count($over_threshold),
                        'backup-jlg'
                    ),
                    $names_label,
                    $threshold_percent_label,
                    number_format_i18n($max_ratio_percent)
                );

                $alerts[] = $this->make_alert(
                    $max_ratio_percent >= 95 ? 'error' : 'warning',
                    __('Capacité distante proche du seuil', 'backup-jlg'),
                    esc_html($message),
                    [
                        'label' => __('Ouvrir le monitoring', 'backup-jlg'),
                        'url' => add_query_arg(
                            ['page' => 'backup-jlg', 'section' => 'monitoring'],
                            admin_url('admin.php')
                        ),
                    ]
                );
            }
        }

        return $alerts;
    }

    /**
     * Formate un timestamp UNIX selon la configuration de WordPress.
     */
    private function format_datetime(int $timestamp): string {
        if (function_exists('wp_date')) {
            $format = sprintf('%s %s', get_option('date_format', 'd/m/Y'), get_option('time_format', 'H:i'));
            return wp_date($format, $timestamp);
        }

        return date_i18n('d/m/Y H:i', $timestamp);
    }

    /**
     * Retourne un libellé relatif pour une planification.
     */
    private function format_schedule_relative(?int $timestamp, int $now): string {
        if (empty($timestamp)) {
            return '';
        }

        if ($timestamp >= $now) {
            return sprintf(__('dans %s', 'backup-jlg'), human_time_diff($now, $timestamp));
        }

        return sprintf(__('en retard de %s', 'backup-jlg'), human_time_diff($timestamp, $now));
    }

    /**
     * Crée une structure d'alerte normalisée.
     */
    private function make_alert(string $type, string $title, string $message, array $action = []): array {
        return [
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'action' => $action,
        ];
    }

    /**
     * Ressources d'onboarding affichées dans l'encart d'accueil.
     */
    private function get_onboarding_resources(): array {
        $dashboard_url = admin_url('admin.php?page=backup-jlg');
        $documentation_url = trailingslashit(BJLG_PLUGIN_URL) . 'assets/docs/documentation.html';
        $tutorial_url = trailingslashit(BJLG_PLUGIN_URL) . 'assets/docs/tutoriel-planification.html';
        $resources = [
            [
                'title' => __('Guide de démarrage', 'backup-jlg'),
                'description' => __('Découvrez les étapes essentielles pour configurer vos premières sauvegardes.', 'backup-jlg'),
                'url' => $documentation_url,
                'action_label' => __('Lire la documentation', 'backup-jlg'),
            ],
            [
                'title' => __('Planification automatique', 'backup-jlg'),
                'description' => __('Configurez des sauvegardes récurrentes adaptées à votre rythme.', 'backup-jlg'),
                'url' => $tutorial_url,
                'action_label' => __('Voir le tutoriel', 'backup-jlg'),
            ],
            [
                'title' => __('Vérifier l’installation', 'backup-jlg'),
                'description' => __('Exécutez la suite de tests PHPUnit pour valider votre environnement.', 'backup-jlg'),
                'command' => 'composer test',
                'action_label' => __('Voir les tests', 'backup-jlg'),
                'url' => add_query_arg(['section' => 'monitoring'], $dashboard_url) . '#bjlg-diagnostics-tests',
            ],
        ];

        return $resources;
    }
}
