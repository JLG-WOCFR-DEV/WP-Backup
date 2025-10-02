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

        $metrics['summary'] = $this->build_summary($metrics);
        $metrics['alerts'] = $this->build_alerts($metrics);

        return $metrics;
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
        $resources = [
            [
                'title' => __('Guide de démarrage', 'backup-jlg'),
                'description' => __('Découvrez les étapes essentielles pour configurer vos premières sauvegardes.', 'backup-jlg'),
                'url' => 'https://jlg.dev/docs/backup-jlg/demarrage',
                'action_label' => __('Lire la documentation', 'backup-jlg'),
            ],
            [
                'title' => __('Planification automatique', 'backup-jlg'),
                'description' => __('Configurez des sauvegardes récurrentes adaptées à votre rythme.', 'backup-jlg'),
                'url' => 'https://jlg.dev/docs/backup-jlg/planification',
                'action_label' => __('Voir le tutoriel', 'backup-jlg'),
            ],
            [
                'title' => __('Vérifier l’installation', 'backup-jlg'),
                'description' => __('Exécutez la suite de tests PHPUnit pour valider votre environnement.', 'backup-jlg'),
                'command' => 'composer test',
                'action_label' => __('Voir les tests', 'backup-jlg'),
                'url' => 'https://jlg.dev/docs/backup-jlg/tests',
            ],
        ];

        return $resources;
    }
}
