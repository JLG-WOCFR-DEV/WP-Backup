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
                'directory' => defined('BJLG_BACKUP_DIR') ? BJLG_BACKUP_DIR : '',
                'total_size_bytes' => 0,
                'total_size_human' => size_format(0),
                'backup_count' => 0,
                'latest_backup' => null,
                'remote_destinations' => [],
            ],
            'alerts' => [],
            'onboarding' => $this->get_onboarding_resources(),
            'summary' => [],
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

        $metrics['storage']['remote_destinations'] = $this->collect_remote_storage_metrics();

        $metrics['queues'] = $this->build_queue_metrics($now);

        $metrics['summary'] = $this->build_summary($metrics);
        $metrics['alerts'] = $this->build_alerts($metrics);

        return $metrics;
    }

    private function build_queue_metrics(int $now): array {
        $queues = [];

        if (class_exists(__NAMESPACE__ . '\\BJLG_Notification_Queue')) {
            $queues['notifications'] = $this->format_notification_queue_metrics($now);
        }

        $queues['remote_purge'] = $this->format_remote_purge_metrics($now);

        return $queues;
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
        ];

        if (!class_exists(__NAMESPACE__ . '\\BJLG_Incremental')) {
            return $metrics;
        }

        $incremental = new BJLG_Incremental();
        $queue = $incremental->get_remote_purge_queue();
        if (!is_array($queue) || empty($queue)) {
            return $metrics;
        }

        $metrics['total'] = count($queue);

        $next_attempt = null;
        $oldest = null;
        $entries = [];

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

        $metrics['entries'] = $this->format_remote_purge_entries(array_slice($entries, 0, 5), $now);
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
            ];
        }

        return $formatted;
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     */
    private function format_remote_purge_entries(array $entries, int $now): array {
        $formatted = [];

        foreach ($entries as $entry) {
            [$next_formatted, $next_relative] = $this->format_timestamp_pair($entry['next_attempt_at'] ?? null, $now);
            [$registered_formatted, $registered_relative] = $this->format_timestamp_pair($entry['registered_at'] ?? null, $now);

            $status = isset($entry['status']) ? (string) $entry['status'] : 'pending';
            $destinations_label = $this->format_destination_label($entry['destinations'] ?? []);

            $title = isset($entry['title']) && $entry['title'] !== ''
                ? $entry['title']
                : __('Archive inconnue', 'backup-jlg');

            $formatted[] = [
                'file' => sanitize_text_field($title),
                'title' => sanitize_text_field($title),
                'status' => $status,
                'status_label' => $this->get_queue_status_label($status),
                'status_intent' => $this->get_queue_status_intent($status),
                'attempts' => isset($entry['attempts']) ? (int) $entry['attempts'] : 0,
                'attempt_label' => $this->format_attempt_label(isset($entry['attempts']) ? (int) $entry['attempts'] : 0),
                'next_attempt_relative' => $next_relative,
                'next_attempt_formatted' => $next_formatted,
                'created_relative' => $registered_relative,
                'created_formatted' => $registered_formatted,
                'message' => isset($entry['last_error']) ? (string) $entry['last_error'] : '',
                'details' => [
                    'destinations' => $destinations_label,
                ],
            ];
        }

        return $formatted;
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
        ];

        if (!empty($metrics['history']['last_backup'])) {
            $summary['history_last_backup'] = $metrics['history']['last_backup']['formatted'];
            $summary['history_last_backup_relative'] = $metrics['history']['last_backup']['relative'];
        }

        if (!empty($metrics['scheduler']['next_runs'][0])) {
            $summary['scheduler_next_run'] = $metrics['scheduler']['next_runs'][0]['next_run_formatted'];
            $summary['scheduler_next_run_relative'] = $metrics['scheduler']['next_runs'][0]['next_run_relative'];
        }

        return $summary;
    }

    private function collect_remote_storage_metrics(): array {
        if (!class_exists(BJLG_Destination_Factory::class)) {
            return [];
        }

        $destinations = [];
        $known_ids = BJLG_Settings::get_known_destination_ids();

        foreach ($known_ids as $destination_id) {
            $destination = BJLG_Destination_Factory::create($destination_id);
            if (!$destination instanceof BJLG_Destination_Interface) {
                continue;
            }

            $connected = $destination->is_connected();
            $entry = [
                'id' => $destination_id,
                'name' => $destination->get_name(),
                'connected' => $connected,
                'used_bytes' => null,
                'quota_bytes' => null,
                'free_bytes' => null,
                'used_human' => '',
                'quota_human' => '',
                'free_human' => '',
                'backups_count' => 0,
                'errors' => [],
            ];

            $usage = [];
            if ($connected) {
                try {
                    $usage = $destination->get_storage_usage();
                } catch (\Throwable $exception) {
                    $entry['errors'][] = $exception->getMessage();
                }
            }

            if (is_array($usage)) {
                if (isset($usage['used_bytes'])) {
                    $entry['used_bytes'] = $this->sanitize_metric_value($usage['used_bytes']);
                }
                if (isset($usage['quota_bytes'])) {
                    $entry['quota_bytes'] = $this->sanitize_metric_value($usage['quota_bytes']);
                }
                if (isset($usage['free_bytes'])) {
                    $entry['free_bytes'] = $this->sanitize_metric_value($usage['free_bytes']);
                }
            }

            $backups = [];
            if ($connected) {
                try {
                    $backups = $destination->list_remote_backups();
                } catch (\Throwable $exception) {
                    $entry['errors'][] = $exception->getMessage();
                }
            }

            if (is_array($backups)) {
                $entry['backups_count'] = count($backups);

                if ($entry['used_bytes'] === null) {
                    $total = 0;
                    foreach ($backups as $backup) {
                        $total += isset($backup['size']) ? (int) $backup['size'] : 0;
                    }
                    $entry['used_bytes'] = $total;
                }
            }

            if ($entry['used_bytes'] !== null) {
                $entry['used_human'] = size_format((int) $entry['used_bytes']);
            }

            if ($entry['quota_bytes'] !== null) {
                $entry['quota_human'] = size_format((int) $entry['quota_bytes']);
            }

            if ($entry['free_bytes'] === null && $entry['quota_bytes'] !== null && $entry['used_bytes'] !== null) {
                $free = max(0, (int) $entry['quota_bytes'] - (int) $entry['used_bytes']);
                $entry['free_bytes'] = $free;
            }

            if ($entry['free_bytes'] !== null) {
                $entry['free_human'] = size_format((int) $entry['free_bytes']);
            }

            $destinations[] = $entry;
        }

        return $destinations;
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
                        ['page' => 'backup-jlg', 'tab' => 'backup_restore'],
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
                        ['page' => 'backup-jlg', 'tab' => 'logs'],
                        admin_url('admin.php')
                    ),
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
                        ['page' => 'backup-jlg', 'tab' => 'backup_restore'],
                        admin_url('admin.php')
                    ) . '#bjlg-schedule',
                ]
            );
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
                'url' => add_query_arg(['tab' => 'logs'], $dashboard_url) . '#bjlg-diagnostics-tests',
            ],
        ];

        return $resources;
    }
}
