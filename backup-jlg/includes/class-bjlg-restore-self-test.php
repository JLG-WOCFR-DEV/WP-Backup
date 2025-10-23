<?php
namespace BJLG;

use RuntimeException;
use ZipArchive;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Programme et exécute des tests de restauration réguliers dans une sandbox.
 */
class BJLG_Restore_Self_Test {
    private const HOOK = 'bjlg_run_restore_self_test';
    private const OPTION = 'bjlg_restore_self_test_state';
    private const REPORT_OPTION = 'bjlg_restore_self_test_report';
    private const HISTORY_LIMIT = 5;

    public function __construct() {
        add_action('init', [$this, 'maybe_schedule']);
        add_action(self::HOOK, [$this, 'run']);
    }

    public function maybe_schedule(): void {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }

        if (wp_next_scheduled(self::HOOK)) {
            return;
        }

        $timestamp = time() + HOUR_IN_SECONDS;
        wp_schedule_event($timestamp, 'weekly', self::HOOK);
    }

    public function run(): void {
        $archive = $this->find_latest_backup();
        $started_at = time();
        $archive_mtime = ($archive !== null && file_exists($archive)) ? @filemtime($archive) : null;

        if ($archive === null) {
            $message = __('Test de restauration ignoré : aucune archive disponible.', 'backup-jlg');
            $report = [
                'status' => 'skipped',
                'message' => $message,
                'archive' => '',
                'archive_path' => '',
                'sandbox_path' => '',
                'duration' => 0.0,
                'started_at' => $started_at,
                'completed_at' => time(),
            ];
            $report = $this->finalize_report('skipped', $report, $started_at, null, null);
            $this->record_state('skipped', $message, $report);
            $this->log_history('info', $message);

            return;
        }

        $timer = microtime(true);

        try {
            $report = $this->perform_restore_simulation($archive);
            $report['status'] = 'success';
            $report['message'] = sprintf(__('Restauration sandbox validée pour %s.', 'backup-jlg'), basename($archive));
            $report['duration'] = microtime(true) - $timer;
            $report['started_at'] = $started_at;
            $report['completed_at'] = time();

            $report = $this->finalize_report('success', $report, $started_at, $archive, $archive_mtime);
            $this->record_state('success', $report['message'], $report);
            $this->log_history('success', $report['message']);
            do_action('bjlg_restore_self_test_passed', $report);
        } catch (\Throwable $exception) {
            $error_message = sprintf(
                __('Test de restauration en échec pour %1$s : %2$s', 'backup-jlg'),
                basename($archive),
                $exception->getMessage()
            );

            $report = [
                'status' => 'failure',
                'message' => $error_message,
                'archive' => basename($archive),
                'archive_path' => $archive,
                'sandbox_path' => '',
                'duration' => microtime(true) - $timer,
                'started_at' => $started_at,
                'completed_at' => time(),
                'exception' => $exception->getMessage(),
            ];

            if ($exception instanceof RuntimeException && method_exists($exception, 'getCode') && $exception->getCode()) {
                $report['error_code'] = $exception->getCode();
            }

            $report = $this->finalize_report('failure', $report, $started_at, $archive, $archive_mtime);
            $this->record_state('failure', $error_message, $report);
            $this->log_history('failure', $error_message);
            do_action('bjlg_restore_self_test_failed', $report, $exception);
        }
    }

    private function perform_restore_simulation(string $archive_path): array {
        if (!file_exists($archive_path)) {
            throw new RuntimeException(__('Archive introuvable pour le test de restauration.', 'backup-jlg'));
        }

        $working_archive = $archive_path;
        $temporary_archive = null;
        $sandbox_base = '';

        try {
            if (substr($archive_path, -4) === '.enc') {
                $encryption = new BJLG_Encryption();
                $temporary_archive = $encryption->decrypt_backup_file($archive_path);

                if (!is_string($temporary_archive) || !file_exists($temporary_archive)) {
                    throw new RuntimeException(__('Impossible de déchiffrer l\'archive chiffrée.', 'backup-jlg'));
                }

                $working_archive = $temporary_archive;
            }

            $environment = BJLG_Restore::prepare_environment(BJLG_Restore::ENV_SANDBOX, [
                'sandbox_path' => 'self-tests',
            ]);

            $sandbox = isset($environment['sandbox']) && is_array($environment['sandbox']) ? $environment['sandbox'] : [];
            if (empty($sandbox['base_path'])) {
                throw new RuntimeException(__('Impossible de préparer la sandbox de test.', 'backup-jlg'));
            }

            $sandbox_base = (string) $sandbox['base_path'];
            $test_directory = rtrim($sandbox_base, '/\\') . '/self-test-' . uniqid('', true);
            $this->ensure_directory($test_directory);

            $zip = new ZipArchive();
            if ($zip->open($working_archive) !== true) {
                throw new RuntimeException(__('Lecture de l\'archive impossible pour le test de restauration.', 'backup-jlg'));
            }

            if (!$zip->extractTo($test_directory)) {
                $zip->close();
                throw new RuntimeException(__('Extraction sandbox impossible pour le test de restauration.', 'backup-jlg'));
            }
            $zip->close();

            $extracted_files = glob($test_directory . '/**', GLOB_NOSORT);
            if (!is_array($extracted_files) || empty($extracted_files)) {
                throw new RuntimeException(__('Aucun fichier extrait dans la sandbox de test.', 'backup-jlg'));
            }

            $has_database = !empty(glob($test_directory . '/*.sql'));
            $has_content = is_dir($test_directory . '/wp-content');

            if (!$has_database && !$has_content) {
                throw new RuntimeException(__('Les composants critiques sont absents de l\'archive testée.', 'backup-jlg'));
            }

            $result = [
                'archive' => basename($archive_path),
                'archive_path' => $archive_path,
                'sandbox_path' => $sandbox_base,
                'test_directory' => $test_directory,
                'components' => [
                    'database' => $has_database,
                    'wp_content' => $has_content,
                ],
            ];

            $this->cleanup_directory($sandbox_base);

            return $result;
        } catch (\Throwable $throwable) {
            if ($sandbox_base !== '') {
                $this->cleanup_directory($sandbox_base);
            }

            throw $throwable;
        } finally {
            if ($temporary_archive !== null && file_exists($temporary_archive)) {
                @unlink($temporary_archive);
            }
        }
    }

    private function find_latest_backup(): ?string {
        if (!defined('BJLG_BACKUP_DIR')) {
            return null;
        }

        $pattern = trailingslashit(bjlg_get_backup_directory()) . '*.{zip,enc}';
        $files = glob($pattern, GLOB_BRACE);
        if (!is_array($files) || empty($files)) {
            return null;
        }

        usort($files, static function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return $files[0] ?? null;
    }

    private function record_state(string $status, string $message, array $report): void {
        $state = $this->get_state();
        $history = isset($state['history']) && is_array($state['history']) ? $state['history'] : [];

        array_unshift($history, [
            'status' => $status,
            'message' => $message,
            'timestamp' => time(),
            'report' => $report,
        ]);

        $history = array_slice($history, 0, self::HISTORY_LIMIT);
        $now = time();

        $new_state = [
            'status' => $status,
            'message' => $message,
            'last_run_at' => $now,
            'last_success_at' => $status === 'success' ? $now : ($state['last_success_at'] ?? 0),
            'last_failure_at' => $status === 'failure' ? $now : ($state['last_failure_at'] ?? 0),
            'consecutive_failures' => $status === 'failure'
                ? ($state['consecutive_failures'] ?? 0) + 1
                : 0,
            'history' => $history,
            'last_report' => $report,
        ];

        update_option(self::OPTION, $new_state, false);
    }

    private function get_state(): array {
        $state = get_option(self::OPTION, []);

        return is_array($state) ? $state : [];
    }

    private function log_history(string $status, string $message): void {
        if (class_exists(BJLG_History::class)) {
            BJLG_History::log('restore_self_test', $status, $message);
        }
    }

    private function ensure_directory(string $path): void {
        if ($path === '') {
            return;
        }

        if (!is_dir($path)) {
            wp_mkdir_p($path);
        }
    }

    private function cleanup_directory(string $path): void {
        if ($path === '' || !file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        $items = glob(rtrim($path, '/\\') . '/*', GLOB_NOSORT | GLOB_BRACE);
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_dir($item)) {
                    $this->cleanup_directory($item);
                    continue;
                }

                @unlink($item);
            }
        }

        @rmdir($path);
    }

    protected function finalize_report(string $status, array $report, int $started_at, ?string $archive_path, ?int $archive_mtime): array {
        $metrics = $this->compute_metrics($report, $started_at, $archive_mtime);
        $report['metrics'] = $metrics;

        $markdown = $this->build_markdown_summary($status, $report);
        $files = $this->persist_report_files($status, $report, $markdown);

        if (!empty($markdown)) {
            $report['attachments']['summary_markdown'] = $files['markdown'] ?? [
                'filename' => $this->build_report_filename('restore-self-test', $report, 'md'),
                'content' => $markdown,
            ];
        }

        if (!empty($files['json'])) {
            $report['report_files']['json'] = $files['json'];
        }

        if (!empty($files['html'])) {
            $report['report_files']['html'] = $files['html'];
        }

        if (!empty($files['markdown'])) {
            $report['report_files']['markdown'] = $files['markdown'];
        }

        $this->persist_report_option($status, $report, $metrics, $files, $markdown);

        return $report;
    }

    private function compute_metrics(array $report, int $started_at, ?int $archive_mtime): array {
        $duration = isset($report['duration']) ? (float) $report['duration'] : null;
        $rto_seconds = $duration !== null ? max(0, $duration) : null;
        $rpo_seconds = $archive_mtime !== null ? max(0, $started_at - $archive_mtime) : null;

        return [
            'rto_seconds' => $rto_seconds,
            'rpo_seconds' => $rpo_seconds,
            'rto_human' => $this->format_duration($rto_seconds),
            'rpo_human' => $this->format_rpo($rpo_seconds, $started_at),
        ];
    }

    private function format_duration(?float $seconds): string {
        if ($seconds === null) {
            return '';
        }

        $seconds = max(0, (float) $seconds);

        if ($seconds < 1) {
            return __('< 1 seconde', 'backup-jlg');
        }

        if ($seconds < MINUTE_IN_SECONDS) {
            $rounded = max(1, (int) round($seconds));

            return sprintf(_n('%s seconde', '%s secondes', $rounded, 'backup-jlg'), number_format_i18n($rounded));
        }

        $minutes = (int) floor($seconds / MINUTE_IN_SECONDS);
        if ($minutes < HOUR_IN_SECONDS / MINUTE_IN_SECONDS) {
            return sprintf(_n('%s minute', '%s minutes', $minutes, 'backup-jlg'), number_format_i18n($minutes));
        }

        $hours = (int) floor($seconds / HOUR_IN_SECONDS);
        if ($hours < DAY_IN_SECONDS / HOUR_IN_SECONDS) {
            return sprintf(_n('%s heure', '%s heures', $hours, 'backup-jlg'), number_format_i18n($hours));
        }

        $days = (int) floor($seconds / DAY_IN_SECONDS);

        return sprintf(_n('%s jour', '%s jours', $days, 'backup-jlg'), number_format_i18n($days));
    }

    private function format_rpo(?int $seconds, int $reference): string {
        if ($seconds === null) {
            return '';
        }

        $seconds = max(0, $seconds);
        if ($seconds === 0) {
            return __('Archive générée à l’instant', 'backup-jlg');
        }

        $from = $reference - $seconds;

        return sprintf(__('il y a %s', 'backup-jlg'), human_time_diff($from, $reference));
    }

    private function build_markdown_summary(string $status, array $report): string {
        $lines = [];
        $status_label = [
            'success' => __('Réussi', 'backup-jlg'),
            'failure' => __('Échec', 'backup-jlg'),
            'skipped' => __('Ignoré', 'backup-jlg'),
        ][strtolower($status)] ?? ucfirst($status);

        $lines[] = '# ' . __('Rapport du test de restauration automatique', 'backup-jlg');
        $lines[] = '';
        $lines[] = sprintf('* **%s** : %s', __('Statut', 'backup-jlg'), $status_label);

        if (!empty($report['archive'])) {
            $lines[] = sprintf('* **%s** : `%s`', __('Archive testée', 'backup-jlg'), $report['archive']);
        }

        if (!empty($report['started_at'])) {
            $lines[] = sprintf('* **%s** : %s', __('Démarré', 'backup-jlg'), $this->format_timestamp((int) $report['started_at']));
        }

        if (!empty($report['completed_at'])) {
            $lines[] = sprintf('* **%s** : %s', __('Terminé', 'backup-jlg'), $this->format_timestamp((int) $report['completed_at']));
        }

        $metrics = isset($report['metrics']) && is_array($report['metrics']) ? $report['metrics'] : [];
        if (!empty($metrics['rto_human'])) {
            $lines[] = sprintf('* **%s** : %s', __('RTO mesuré', 'backup-jlg'), $metrics['rto_human']);
        }
        if (!empty($metrics['rpo_human'])) {
            $lines[] = sprintf('* **%s** : %s', __('RPO estimé', 'backup-jlg'), $metrics['rpo_human']);
        }

        if (!empty($report['message'])) {
            $lines[] = '';
            $lines[] = '---';
            $lines[] = '';
            $lines[] = sprintf('> %s', trim((string) $report['message']));
        }

        $components = isset($report['components']) && is_array($report['components']) ? $report['components'] : [];
        if (!empty($components)) {
            $lines[] = '';
            $lines[] = '## ' . __('Composants vérifiés', 'backup-jlg');
            foreach ($components as $component => $present) {
                $label = is_string($component) ? $component : __('Composant', 'backup-jlg');
                $lines[] = sprintf('- %s : %s', $label, $present ? __('présent', 'backup-jlg') : __('absent', 'backup-jlg'));
            }
        }

        return implode("\n", $lines);
    }

    private function persist_report_files(string $status, array $report, string $markdown): array {
        $location = $this->get_report_storage_location();
        $base_dir = $location['path'];
        $base_url = $location['url'];

        if ($base_dir === '') {
            return [];
        }

        if (!is_dir($base_dir)) {
            $this->ensure_directory($base_dir);
        }

        $writable = function_exists('wp_is_writable') ? wp_is_writable($base_dir) : is_writable($base_dir);
        if (!is_dir($base_dir) || !$writable) {
            return [];
        }

        $timestamp = isset($report['completed_at']) ? (int) $report['completed_at'] : time();
        $slug = $this->build_report_filename('restore-self-test', $report, '');
        $files = [];

        $json_path = trailingslashit($base_dir) . $slug . '.json';
        $json_payload = $this->build_export_payload($status, $report);
        $json_written = file_put_contents($json_path, wp_json_encode($json_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($json_written !== false) {
            $files['json'] = [
                'filename' => basename($json_path),
                'path' => $json_path,
                'url' => $base_url !== '' ? $base_url . basename($json_path) : '',
                'mime_type' => 'application/json',
                'generated_at' => $timestamp,
            ];
        }

        $html_path = trailingslashit($base_dir) . $slug . '.html';
        $html_written = file_put_contents($html_path, $this->build_html_report($status, $report, $markdown));
        if ($html_written !== false) {
            $files['html'] = [
                'filename' => basename($html_path),
                'path' => $html_path,
                'url' => $base_url !== '' ? $base_url . basename($html_path) : '',
                'mime_type' => 'text/html',
                'generated_at' => $timestamp,
            ];
        }

        if ($markdown !== '') {
            $markdown_path = trailingslashit($base_dir) . $slug . '.md';
            $markdown_written = file_put_contents($markdown_path, $markdown);
            if ($markdown_written !== false) {
                $files['markdown'] = [
                    'filename' => basename($markdown_path),
                    'path' => $markdown_path,
                    'url' => $base_url !== '' ? $base_url . basename($markdown_path) : '',
                    'mime_type' => 'text/markdown',
                    'generated_at' => $timestamp,
                ];
            }
        }

        $files['base_path'] = $base_dir;
        $files['base_url'] = $base_url;

        return $files;
    }

    private function build_export_payload(string $status, array $report): array {
        $metrics = isset($report['metrics']) && is_array($report['metrics']) ? $report['metrics'] : [];

        return [
            'status' => $status,
            'message' => isset($report['message']) ? (string) $report['message'] : '',
            'archive' => isset($report['archive']) ? (string) $report['archive'] : '',
            'archive_path' => isset($report['archive_path']) ? (string) $report['archive_path'] : '',
            'started_at' => isset($report['started_at']) ? (int) $report['started_at'] : null,
            'completed_at' => isset($report['completed_at']) ? (int) $report['completed_at'] : null,
            'duration' => isset($report['duration']) ? (float) $report['duration'] : null,
            'metrics' => $metrics,
            'components' => isset($report['components']) && is_array($report['components']) ? $report['components'] : [],
        ];
    }

    private function build_html_report(string $status, array $report, string $markdown): string {
        $title = __('Rapport du test de restauration', 'backup-jlg');
        $status_label = [
            'success' => __('Réussi', 'backup-jlg'),
            'failure' => __('Échec', 'backup-jlg'),
            'skipped' => __('Ignoré', 'backup-jlg'),
        ][strtolower($status)] ?? ucfirst($status);

        $metrics = isset($report['metrics']) && is_array($report['metrics']) ? $report['metrics'] : [];
        $rows = [];
        $rows[] = $this->build_html_row(__('Statut', 'backup-jlg'), $status_label);
        if (!empty($report['archive'])) {
            $rows[] = $this->build_html_row(__('Archive testée', 'backup-jlg'), $report['archive']);
        }
        if (!empty($report['started_at'])) {
            $rows[] = $this->build_html_row(__('Démarré', 'backup-jlg'), $this->format_timestamp((int) $report['started_at']));
        }
        if (!empty($report['completed_at'])) {
            $rows[] = $this->build_html_row(__('Terminé', 'backup-jlg'), $this->format_timestamp((int) $report['completed_at']));
        }
        if (!empty($metrics['rto_human'])) {
            $rows[] = $this->build_html_row(__('RTO mesuré', 'backup-jlg'), $metrics['rto_human']);
        }
        if (!empty($metrics['rpo_human'])) {
            $rows[] = $this->build_html_row(__('RPO estimé', 'backup-jlg'), $metrics['rpo_human']);
        }

        $message_html = '';
        if (!empty($report['message'])) {
            $message_html = '<blockquote>' . esc_html((string) $report['message']) . '</blockquote>';
        }

        $components_html = '';
        $components = isset($report['components']) && is_array($report['components']) ? $report['components'] : [];
        if (!empty($components)) {
            $components_html .= '<h2>' . esc_html(__('Composants vérifiés', 'backup-jlg')) . '</h2><ul>';
            foreach ($components as $component => $present) {
                $label = is_string($component) ? $component : __('Composant', 'backup-jlg');
                $components_html .= '<li>' . esc_html($label) . ' : ' . esc_html($present ? __('présent', 'backup-jlg') : __('absent', 'backup-jlg')) . '</li>';
            }
            $components_html .= '</ul>';
        }

        $table = '<table>' . implode('', $rows) . '</table>';

        return '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>' . esc_html($title) . '</title><style>'
            . 'body{font-family:Arial,Helvetica,sans-serif;background:#f8f9fb;color:#1d2327;padding:24px;}'
            . 'table{width:100%;border-collapse:collapse;margin-bottom:24px;}'
            . 'th,td{text-align:left;padding:8px 12px;border-bottom:1px solid #dcdfe4;}'
            . 'blockquote{margin:0 0 24px;padding:12px 16px;background:#fff;border-left:4px solid #2271b1;}'
            . 'h1{margin-top:0;}'
            . '</style></head><body><h1>' . esc_html($title) . '</h1>' . $table . $message_html
            . $components_html . '<h2>' . esc_html(__('Résumé Markdown', 'backup-jlg')) . '</h2><pre>'
            . esc_html($markdown) . '</pre></body></html>';
    }

    private function build_html_row(string $label, string $value): string {
        return '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private function get_report_storage_location(): array {
        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            if (is_array($uploads) && empty($uploads['error'])) {
                $basedir = trailingslashit($uploads['basedir']) . 'bjlg-self-tests/';
                $baseurl = isset($uploads['baseurl']) ? trailingslashit($uploads['baseurl']) . 'bjlg-self-tests/' : '';

                return [
                    'path' => $basedir,
                    'url' => $baseurl,
                ];
            }
        }

        if (defined('BJLG_BACKUP_DIR')) {
            return [
                'path' => trailingslashit(bjlg_get_backup_directory()) . 'self-tests/',
                'url' => '',
            ];
        }

        return [
            'path' => trailingslashit(sys_get_temp_dir()) . 'bjlg-self-tests/',
            'url' => '',
        ];
    }

    private function build_report_filename(string $prefix, array $report, string $extension): string {
        $timestamp = isset($report['completed_at']) ? (int) $report['completed_at'] : time();
        $hash_source = isset($report['archive']) ? (string) $report['archive'] : uniqid('', true);
        $hash = substr(md5($hash_source . $timestamp), 0, 8);
        $base = sprintf('%s-%s-%s', $prefix, gmdate('Ymd-His', $timestamp), $hash);

        if ($extension === '') {
            return sanitize_file_name($base);
        }

        return sanitize_file_name($base . '.' . ltrim($extension, '.'));
    }

    private function persist_report_option(string $status, array $report, array $metrics, array $files, string $markdown): void {
        $completed_at = isset($report['completed_at']) ? (int) $report['completed_at'] : time();
        $option_payload = [
            'status' => $status,
            'message' => isset($report['message']) ? (string) $report['message'] : '',
            'archive' => isset($report['archive']) ? (string) $report['archive'] : '',
            'generated_at' => $completed_at,
            'metrics' => $metrics,
            'files' => $files,
            'summary_markdown' => $markdown,
            'storage_directory' => isset($files['base_path']) ? (string) $files['base_path'] : '',
        ];

        update_option(self::REPORT_OPTION, $option_payload, false);
    }

    private function format_timestamp(int $timestamp): string {
        if (function_exists('wp_date')) {
            $format = sprintf('%s %s', get_option('date_format', 'd/m/Y'), get_option('time_format', 'H:i'));

            return wp_date($format, $timestamp);
        }

        return date_i18n('d/m/Y H:i', $timestamp);
    }
}
