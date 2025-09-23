<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-restore.php';

if (!class_exists('BJLG\\BJLG_Debug')) {
    class BJLG_Test_Debug_Logger
    {
        /** @var array<int, string> */
        public static $logs = [];

        /**
         * @param mixed $message
         */
        public static function log($message): void
        {
            self::$logs[] = (string) $message;
        }
    }

    class_alias('BJLG_Test_Debug_Logger', 'BJLG\\BJLG_Debug');
}

final class BJLG_RestoreTaskTest extends TestCase
{
    /** @var mixed */
    private $previous_wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

        $GLOBALS['wpdb'] = new class {
            /** @var array<int, string> */
            public $queries = [];

            /** @var string */
            public $last_error = '';

            /** @var string */
            public $options = 'wp_options';

            /** @var array{transients: array<int, string>, site_transients: array<int, string>, network_site_transients: array<int, string>} */
            public $col_results = [
                'transients' => [],
                'site_transients' => [],
                'network_site_transients' => [],
            ];

            /**
             * @param string $query
             * @return int
             */
            public function query($query)
            {
                $this->queries[] = (string) $query;
                $this->last_error = '';

                return 1;
            }

            /**
             * @param string $query
             * @return array<int, string>
             */
            public function get_col($query)
            {
                $this->queries[] = (string) $query;
                $this->last_error = '';

                $query = (string) $query;

                if (strpos($query, '_site_transient_') !== false) {
                    if (strpos($query, 'sitemeta') !== false) {
                        return $this->col_results['network_site_transients'];
                    }

                    return $this->col_results['site_transients'];
                }

                return $this->col_results['transients'];
            }
        };

        $GLOBALS['bjlg_test_transients'] = [];
    }

    protected function tearDown(): void
    {
        if ($this->previous_wpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
        }

        parent::tearDown();
    }

    public function test_run_restore_task_completes_for_database_only_backup(): void
    {
        $temporary_dir = sys_get_temp_dir() . '/bjlg-restore-db-only-' . uniqid('', true);
        if (!is_dir($temporary_dir)) {
            mkdir($temporary_dir, 0755, true);
        }

        $zip_path = $temporary_dir . '/db-only.zip';

        $zip = new ZipArchive();
        $open_result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($open_result === true || $open_result === ZipArchive::ER_OK);

        $manifest = [
            'type' => 'test',
            'contains' => ['db'],
        ];

        $zip->addFromString('backup-manifest.json', json_encode($manifest));
        $zip->addFromString('database.sql', "CREATE TABLE `wp_test` (id INT);\nINSERT INTO `wp_test` VALUES (1);\n");
        $zip->close();

        $destination = BJLG_BACKUP_DIR . 'db-only-' . uniqid('', true) . '.zip';
        copy($zip_path, $destination);

        $restore = new BJLG\BJLG_Restore();

        $task_id = 'bjlg_restore_' . uniqid('', true);
        set_transient($task_id, [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => '',
            'filename' => basename($destination),
            'filepath' => $destination,
            'password_encrypted' => null,
        ], defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);

        $restore->run_restore_task($task_id);

        $task_data = get_transient($task_id);
        $this->assertIsArray($task_data);
        $this->assertSame('complete', $task_data['status']);
        $this->assertSame(100, $task_data['progress']);
        $this->assertSame('Restauration terminée avec succès !', $task_data['status_text']);

        if (file_exists($destination)) {
            unlink($destination);
        }

        if (file_exists($zip_path)) {
            unlink($zip_path);
        }

        if (is_dir($temporary_dir)) {
            rmdir($temporary_dir);
        }
    }

    public function test_run_restore_task_creates_pre_restore_backup_when_requested(): void
    {
        $temporary_dir = sys_get_temp_dir() . '/bjlg-restore-pre-backup-' . uniqid('', true);
        if (!is_dir($temporary_dir)) {
            mkdir($temporary_dir, 0755, true);
        }

        $zip_path = $temporary_dir . '/pre-backup.zip';

        $zip = new ZipArchive();
        $open_result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($open_result === true || $open_result === ZipArchive::ER_OK);

        $manifest = [
            'type' => 'test',
            'contains' => ['db'],
        ];

        $zip->addFromString('backup-manifest.json', json_encode($manifest));
        $zip->addFromString('database.sql', "SELECT 1;\n");
        $zip->close();

        $destination = BJLG_BACKUP_DIR . 'pre-backup-' . uniqid('', true) . '.zip';
        copy($zip_path, $destination);

        $restore = new class extends BJLG\BJLG_Restore {
            /** @var int */
            public $pre_restore_backup_calls = 0;

            protected function perform_pre_restore_backup(): array
            {
                $this->pre_restore_backup_calls++;

                return [
                    'filename' => 'dummy-pre-restore.zip',
                    'filepath' => BJLG_BACKUP_DIR . 'dummy-pre-restore.zip',
                ];
            }
        };

        $task_id = 'bjlg_restore_' . uniqid('', true);
        set_transient($task_id, [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => '',
            'filename' => basename($destination),
            'filepath' => $destination,
            'password_encrypted' => null,
            'create_restore_point' => true,
        ], defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);

        $restore->run_restore_task($task_id);

        $this->assertSame(1, $restore->pre_restore_backup_calls);

        if (file_exists($destination)) {
            unlink($destination);
        }

        if (file_exists($zip_path)) {
            unlink($zip_path);
        }

        if (is_dir($temporary_dir)) {
            rmdir($temporary_dir);
        }
    }

    public function test_clear_all_caches_preserves_third_party_transients(): void
    {
        $restore = new BJLG\BJLG_Restore();

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->col_results['transients'] = ['_transient_bjlg_restore_state'];

        set_transient('bjlg_restore_state', 'plugin', HOUR_IN_SECONDS);
        set_transient('woocommerce_session_abcd', 'session', HOUR_IN_SECONDS);

        $reflection = new ReflectionClass(BJLG\BJLG_Restore::class);
        $method = $reflection->getMethod('clear_all_caches');
        $method->setAccessible(true);
        $method->invoke($restore);

        $this->assertFalse(get_transient('bjlg_restore_state'));
        $this->assertSame('session', get_transient('woocommerce_session_abcd'));

        $this->assertNotEmpty($wpdb->queries);
        $this->assertStringContainsString("\\_transient\\_bjlg\\_%", $wpdb->queries[0]);
    }
}
