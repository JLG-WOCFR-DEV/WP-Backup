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

        $pattern = trailingslashit(BJLG_BACKUP_DIR) . '*.{zip,enc}';
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
}
