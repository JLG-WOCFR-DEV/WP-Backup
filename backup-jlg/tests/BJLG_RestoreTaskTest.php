<?php

declare(strict_types=1);

use BJLG\BJLG_Encryption;
use BJLG\BJLG_Restore;
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

if (!class_exists('BJLG_Test_Debug_Logger')) {
    class BJLG_Test_Debug_Logger extends BJLG\BJLG_Debug
    {
    }
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

    public function test_run_restore_task_uses_sandbox_environment_without_touching_production(): void
    {
        $temporary_dir = sys_get_temp_dir() . '/bjlg-restore-sandbox-' . uniqid('', true);
        if (!is_dir($temporary_dir)) {
            mkdir($temporary_dir, 0755, true);
        }

        $zip_path = $temporary_dir . '/sandbox.zip';
        $zip = new ZipArchive();
        $open_result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($open_result === true || $open_result === ZipArchive::ER_OK);

        $manifest = [
            'type' => 'test',
            'contains' => ['plugins', 'uploads'],
        ];

        $zip->addFromString('backup-manifest.json', json_encode($manifest));
        $zip->addFromString('wp-content/plugins/sample/plugin.php', '<?php echo "sandbox";');
        $zip->addFromString('wp-content/uploads/example.txt', 'sandbox file');
        $zip->close();

        $destination = BJLG_BACKUP_DIR . 'sandbox-' . uniqid('', true) . '.zip';
        copy($zip_path, $destination);

        $sandbox_target = BJLG_BACKUP_DIR . 'sandbox-target-' . uniqid('', true);
        $environment_config = BJLG\BJLG_Restore::prepare_environment(
            BJLG\BJLG_Restore::ENV_SANDBOX,
            ['sandbox_path' => $sandbox_target]
        );

        $restore = new BJLG\BJLG_Restore();

        $task_id = 'bjlg_restore_' . uniqid('', true);
        set_transient($task_id, [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => '',
            'filename' => basename($destination),
            'filepath' => $destination,
            'password_encrypted' => null,
            'components' => ['plugins', 'uploads'],
            'environment' => $environment_config['environment'],
            'routing_table' => $environment_config['routing_table'],
            'sandbox' => $environment_config['sandbox'],
        ], defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);

        $production_plugin_path = WP_PLUGIN_DIR . '/sample/plugin.php';
        if (file_exists($production_plugin_path)) {
            unlink($production_plugin_path);
        }

        $production_upload_path = wp_get_upload_dir()['basedir'] . '/example.txt';
        if (file_exists($production_upload_path)) {
            unlink($production_upload_path);
        }

        $restore->run_restore_task($task_id);

        $task_data = get_transient($task_id);
        $this->assertIsArray($task_data);
        $this->assertSame('complete', $task_data['status']);
        $this->assertSame(BJLG\BJLG_Restore::ENV_SANDBOX, $task_data['environment']);

        $sandbox_routes = $environment_config['routing_table'];
        $sandbox_plugin = $sandbox_routes['plugins'] . '/sample/plugin.php';
        $sandbox_upload = $sandbox_routes['uploads'] . '/example.txt';

        $this->assertFileExists($sandbox_plugin);
        $this->assertFileExists($sandbox_upload);
        $this->assertFalse(file_exists($production_plugin_path));
        $this->assertFalse(file_exists($production_upload_path));

        if (file_exists($destination)) {
            unlink($destination);
        }

        if (file_exists($zip_path)) {
            unlink($zip_path);
        }

        if (is_dir($temporary_dir)) {
            bjlg_tests_recursive_delete($temporary_dir);
        }

        if (isset($environment_config['sandbox']['base_path'])) {
            bjlg_tests_recursive_delete($environment_config['sandbox']['base_path']);
        }
    }

    public function test_handle_run_restore_rejects_when_another_restore_is_running(): void
    {
        $restore = new BJLG\BJLG_Restore();

        $backup_path = BJLG_BACKUP_DIR . 'restore-lock-' . uniqid('', true) . '.zip';
        file_put_contents($backup_path, 'dummy');

        $existing_task = 'bjlg_restore_' . md5(uniqid('existing', true));
        $lock_acquired = BJLG\BJLG_Backup::reserve_task_slot($existing_task);
        $this->assertTrue($lock_acquired, 'Le verrou initial aurait dû être acquis pour le test.');

        $_POST = [
            'nonce' => 'test-nonce',
            'filename' => basename($backup_path),
            'components' => ['db'],
        ];

        try {
            $restore->handle_run_restore();
            $this->fail('Un refus de restauration simultanée était attendu.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertSame(409, $response->status_code);
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('message', $response->data);
        } finally {
            if ($lock_acquired) {
                BJLG\BJLG_Backup::release_task_slot($existing_task);
            }

            if (file_exists($backup_path)) {
                unlink($backup_path);
            }

            $_POST = [];
        }
    }

    public function test_ajax_restore_task_respects_requested_components(): void
    {
        BJLG_Test_Debug_Logger::$logs = [];

        $plugin_slug = 'component-plugin-' . uniqid('', true);
        $theme_slug = 'component-theme-' . uniqid('', true);
        $upload_slug = 'component-upload-' . uniqid('', true);

        $zip_filename = 'components-' . uniqid('', true) . '.zip';
        $zip_path = BJLG_BACKUP_DIR . $zip_filename;

        $zip = new ZipArchive();
        $open_result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($open_result === true || $open_result === ZipArchive::ER_OK);

        $manifest = [
            'type' => 'test',
            'contains' => ['db', 'plugins', 'themes', 'uploads'],
        ];

        $zip->addFromString('backup-manifest.json', json_encode($manifest));
        $zip->addFromString('database.sql', "SELECT 1;\n");
        $zip->addFromString('wp-content/plugins/' . $plugin_slug . '/plugin.txt', 'plugin');
        $zip->addFromString('wp-content/themes/' . $theme_slug . '/style.css', 'theme');
        $zip->addFromString('wp-content/uploads/' . $upload_slug . '/file.txt', 'upload');
        $zip->close();

        $plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;
        $plugin_file = $plugin_dir . '/plugin.txt';
        $theme_dir = get_theme_root() . '/' . $theme_slug;
        $theme_file = $theme_dir . '/style.css';
        $upload_dir = wp_get_upload_dir()['basedir'] . '/' . $upload_slug;
        $upload_file = $upload_dir . '/file.txt';

        $this->removePath($plugin_dir);
        $this->removePath($theme_dir);
        $this->removePath($upload_dir);

        $_POST = [
            'nonce' => 'test',
            'filename' => $zip_filename,
            'components' => ['plugins', 'uploads'],
        ];

        $restore = new BJLG\BJLG_Restore();

        $task_id = null;

        try {
            $restore->handle_run_restore();
            $this->fail('Expected JSON response not thrown');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('task_id', $response->data);
            $task_id = $response->data['task_id'];
        }

        $this->assertIsString($task_id);

        $task_data = get_transient($task_id);
        $this->assertIsArray($task_data);
        $this->assertSame(['plugins', 'uploads'], $task_data['components']);

        $GLOBALS['wpdb']->queries = [];

        $restore->run_restore_task($task_id);

        $this->assertFileExists($plugin_file);
        $this->assertFileExists($upload_file);
        $this->assertFileDoesNotExist($theme_file);

        $database_queries = array_filter(
            $GLOBALS['wpdb']->queries,
            static function ($query) {
                return stripos((string) $query, 'foreign_key_checks') !== false;
            }
        );
        $this->assertSame([], array_values($database_queries));

        $this->assertNotContains('Import de la base de données...', BJLG_Test_Debug_Logger::$logs);
        $this->assertContains('Restauration de plugins terminée.', BJLG_Test_Debug_Logger::$logs);
        $this->assertContains('Restauration de uploads terminée.', BJLG_Test_Debug_Logger::$logs);
        $this->assertNotContains('Restauration de themes terminée.', BJLG_Test_Debug_Logger::$logs);

        $this->removePath($plugin_dir);
        $this->removePath($upload_dir);
        $this->removePath($theme_dir);

        if (file_exists($zip_path)) {
            unlink($zip_path);
        }

        $_POST = [];
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

    public function test_publish_sandbox_removes_obsolete_production_files(): void
    {
        $sandbox_base = sys_get_temp_dir() . '/bjlg-sandbox-publish-' . uniqid('', true);
        $sandbox_plugins_dir = $sandbox_base . '/wp-content/plugins';
        $sandbox_plugin_dir = $sandbox_plugins_dir . '/sample-plugin';
        $sandbox_plugin_file = $sandbox_plugin_dir . '/plugin.php';

        if (!is_dir($sandbox_plugin_dir)) {
            mkdir($sandbox_plugin_dir, 0777, true);
        }

        file_put_contents($sandbox_plugin_file, "<?php // sandbox version\n");

        $production_plugin_dir = WP_PLUGIN_DIR . '/sample-plugin';
        $production_plugin_file = $production_plugin_dir . '/plugin.php';
        $obsolete_file = $production_plugin_dir . '/obsolete.txt';

        bjlg_tests_recursive_delete($production_plugin_dir);

        mkdir($production_plugin_dir, 0777, true);
        file_put_contents($production_plugin_file, "<?php // production version\n");
        file_put_contents($obsolete_file, 'to be removed');

        $task_id = 'bjlg_publish_' . uniqid('', true);
        $task_data = [
            'environment' => BJLG_Restore::ENV_SANDBOX,
            'routing_table' => [
                'plugins' => $sandbox_plugins_dir,
            ],
            'sandbox' => [
                'base_path' => $sandbox_base,
                'routing_table' => [
                    'plugins' => $sandbox_plugins_dir,
                ],
            ],
            'components' => ['plugins'],
        ];

        set_transient($task_id, $task_data, 3600);

        $_POST['task_id'] = $task_id;
        $_POST['nonce'] = 'publish';

        $restore = new class(false) extends BJLG_Restore {
            public function __construct($backup_manager = null)
            {
                parent::__construct($backup_manager);
            }

            protected function perform_pre_restore_backup(): array
            {
                return [
                    'filename' => 'dummy.zip',
                    'filepath' => BJLG_BACKUP_DIR . 'dummy.zip',
                ];
            }
        };

        try {
            $restore->handle_publish_sandbox();
            $this->fail('Expected JSON response not thrown');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
            $this->assertSame('Sandbox promue en production.', $response->data['message']);
        }

        $this->assertFileExists($production_plugin_file);
        $this->assertSame(
            file_get_contents($sandbox_plugin_file),
            file_get_contents($production_plugin_file)
        );
        $this->assertFalse(file_exists($obsolete_file));

        bjlg_tests_recursive_delete($sandbox_base);
        bjlg_tests_recursive_delete($production_plugin_dir);
        delete_transient($task_id);
        unset($_POST['task_id'], $_POST['nonce']);
    }

    public function test_encrypted_full_and_incremental_restore_flow_promotes_sandbox_changes(): void
    {
        $password = 'sandbox-secret';

        $fullArchive = BJLG_Test_BackupFixtures::createBackupArchive([
            'filename' => 'full-backup-' . uniqid('', true) . '.zip',
            'manifest' => ['type' => 'full', 'contains' => ['db', 'plugins', 'uploads']],
            'database' => "CREATE TABLE `wp_posts` (id INT);\n",
            'files' => [
                'wp-content/plugins/sample-plugin/plugin.php' => "<?php // full backup\n", 
                'wp-content/uploads/keep.txt' => 'full-keep',
            ],
            'encrypt' => true,
            'password' => $password,
        ]);

        $incrementalArchive = BJLG_Test_BackupFixtures::createBackupArchive([
            'filename' => 'incremental-backup-' . uniqid('', true) . '.zip',
            'manifest' => ['type' => 'incremental', 'contains' => ['plugins', 'uploads']],
            'files' => [
                'wp-content/plugins/sample-plugin/plugin.php' => "<?php // incremental\n",
                'wp-content/uploads/new.txt' => 'new-upload',
            ],
            'encrypt' => true,
            'password' => $password,
        ]);

        $pluginDir = WP_PLUGIN_DIR . '/sample-plugin';
        bjlg_tests_recursive_delete($pluginDir);
        if (!is_dir($pluginDir)) {
            mkdir($pluginDir, 0777, true);
        }

        $productionPluginFile = $pluginDir . '/plugin.php';
        $obsoleteProductionFile = $pluginDir . '/obsolete.php';
        file_put_contents($productionPluginFile, "<?php // production\n");
        file_put_contents($obsoleteProductionFile, 'to-remove');

        $uploadsDir = wp_get_upload_dir()['basedir'];
        $productionKeep = $uploadsDir . '/keep.txt';
        $productionOld = $uploadsDir . '/old.txt';
        file_put_contents($productionKeep, 'production-keep');
        file_put_contents($productionOld, 'production-old');

        $environmentConfig = BJLG\BJLG_Restore::prepare_environment(BJLG\BJLG_Restore::ENV_SANDBOX);

        $restore = new class(new BJLG\BJLG_Backup(), new BJLG_Encryption()) extends BJLG\BJLG_Restore {
            public function __construct($backup_manager = null, $encryption_handler = null)
            {
                parent::__construct($backup_manager, $encryption_handler);
            }

            protected function perform_pre_restore_backup(): array
            {
                return [
                    'filename' => 'pre-restore.zip',
                    'filepath' => BJLG_BACKUP_DIR . 'pre-restore.zip',
                ];
            }
        };

        $fullTaskId = 'bjlg_restore_' . uniqid('full', true);
        set_transient($fullTaskId, [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => '',
            'filename' => basename($fullArchive['path']),
            'filepath' => $fullArchive['path'],
            'password_encrypted' => BJLG\BJLG_Restore::encrypt_password_for_transient($password),
            'components' => ['plugins', 'uploads', 'db'],
            'environment' => $environmentConfig['environment'],
            'routing_table' => $environmentConfig['routing_table'],
            'sandbox' => $environmentConfig['sandbox'],
        ], defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);

        $restore->run_restore_task($fullTaskId);

        $fullTaskState = get_transient($fullTaskId);
        $this->assertIsArray($fullTaskState);
        $this->assertSame('complete', $fullTaskState['status']);

        $sandboxPlugin = $environmentConfig['routing_table']['plugins'] . '/sample-plugin/plugin.php';
        $sandboxUploadsDir = $environmentConfig['routing_table']['uploads'];
        $this->assertFileExists($sandboxPlugin);
        $this->assertSame("<?php // full backup\n", file_get_contents($sandboxPlugin));
        $this->assertFileExists($sandboxUploadsDir . '/keep.txt');

        $incrementalTaskId = 'bjlg_restore_' . uniqid('incremental', true);
        set_transient($incrementalTaskId, [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => '',
            'filename' => basename($incrementalArchive['path']),
            'filepath' => $incrementalArchive['path'],
            'password_encrypted' => BJLG\BJLG_Restore::encrypt_password_for_transient($password),
            'components' => ['plugins', 'uploads'],
            'environment' => $environmentConfig['environment'],
            'routing_table' => $environmentConfig['routing_table'],
            'sandbox' => $environmentConfig['sandbox'],
        ], defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);

        $restore->run_restore_task($incrementalTaskId);

        $incrementalTaskState = get_transient($incrementalTaskId);
        $this->assertIsArray($incrementalTaskState);
        $this->assertSame('complete', $incrementalTaskState['status']);
        $this->assertSame("<?php // incremental\n", file_get_contents($sandboxPlugin));
        $this->assertFileExists($sandboxUploadsDir . '/new.txt');

        $_POST['task_id'] = $incrementalTaskId;
        $_POST['nonce'] = 'publish';

        try {
            $restore->handle_publish_sandbox();
            $this->fail('Expected JSON response');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertSame('Sandbox promue en production.', $response->data['message']);
        }

        $this->assertSame("<?php // incremental\n", file_get_contents($productionPluginFile));
        $this->assertFalse(file_exists($obsoleteProductionFile));
        $this->assertSame('full-keep', file_get_contents($productionKeep));
        $this->assertFalse(file_exists($productionOld));
        $this->assertSame('new-upload', file_get_contents($uploadsDir . '/new.txt'));

        bjlg_tests_recursive_delete($environmentConfig['sandbox']['base_path'] ?? '');
        bjlg_tests_recursive_delete($pluginDir);
        @unlink($productionKeep);
        @unlink($productionOld);
        @unlink($uploadsDir . '/new.txt');
        @unlink(BJLG_BACKUP_DIR . 'pre-restore.zip');
        delete_transient($fullTaskId);
        delete_transient($incrementalTaskId);
        @unlink($fullArchive['path']);
        @unlink($incrementalArchive['path']);
        unset($_POST['task_id'], $_POST['nonce']);
    }

    private function removePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->removePath($path . DIRECTORY_SEPARATOR . $item);
        }

        @rmdir($path);
    }
}
