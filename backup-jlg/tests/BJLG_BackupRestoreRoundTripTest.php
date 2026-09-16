<?php
declare(strict_types=1);

use BJLG\BJLG_Backup;
use BJLG\BJLG_Backup_Integrity;
use BJLG\BJLG_Restore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-backup.php';
require_once __DIR__ . '/../includes/class-bjlg-restore.php';
require_once __DIR__ . '/../includes/class-bjlg-actions.php';
require_once __DIR__ . '/Helpers/BJLG_Test_BackupFixtures.php';

final class BJLG_BackupRestoreRoundTripTest extends TestCase
{
    /** @var mixed */
    private $previousWpdb;

    /** @var array<int, string> */
    private $createdPaths = [];

    /** @var string */
    private $pluginDir = '';

    /** @var string */
    private $pluginFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_scheduled_events'] = [
            'recurring' => [],
            'single' => [],
        ];
        $GLOBALS['bjlg_test_headers'] = [];
        unset(
            $GLOBALS['bjlg_test_options']['_transient_bjlg_backup_task_lock'],
            $GLOBALS['bjlg_test_options']['_transient_timeout_bjlg_backup_task_lock']
        );

        $lock_property = new ReflectionProperty(BJLG_Backup::class, 'in_memory_lock');
        $lock_property->setAccessible(true);
        $lock_property->setValue(null, null);

        $this->pluginDir = rtrim(WP_PLUGIN_DIR, '/\\') . '/bjlg-roundtrip-plugin';
        $this->pluginFile = $this->pluginDir . '/marker.txt';

        if (!is_dir($this->pluginDir) && !mkdir($this->pluginDir, 0777, true) && !is_dir($this->pluginDir)) {
            $this->fail('Impossible de créer le répertoire plugin de test.');
        }

        file_put_contents($this->pluginFile, "round-trip-original\n");

        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            /** @var string */
            public $prefix = 'wp_';

            /** @var array<int, string> */
            public $queries = [];

            /** @var string */
            public $last_error = '';

            public function get_results($query, $output = OBJECT)
            {
                if (stripos((string) $query, 'SHOW TABLES') === 0) {
                    return [['wp_posts']];
                }

                if (stripos((string) $query, 'SHOW INDEX FROM') === 0) {
                    return [[
                        'Key_name' => 'PRIMARY',
                        'Column_name' => 'ID',
                        'Seq_in_index' => 1,
                    ]];
                }

                if (stripos((string) $query, 'SELECT * FROM') === 0) {
                    return [[
                        'ID' => 1,
                        'post_title' => 'Hello round-trip',
                    ]];
                }

                return [];
            }

            public function get_row($query, $output = OBJECT, $y = 0)
            {
                if (stripos((string) $query, 'SHOW CREATE TABLE') === 0) {
                    return ['wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint(20), `post_title` varchar(200))'];
                }

                if (stripos((string) $query, 'SHOW COLUMNS FROM') === 0) {
                    return [
                        'Field' => 'ID',
                        'Type' => 'bigint(20)',
                        'Null' => 'NO',
                    ];
                }

                return null;
            }

            public function get_var($query)
            {
                if (stripos((string) $query, 'SELECT COUNT(*)') === 0) {
                    return 1;
                }

                return 0;
            }

            public function prepare($query, ...$args)
            {
                return $query;
            }

            public function query($query)
            {
                $this->queries[] = (string) $query;
                $this->last_error = '';

                return 1;
            }
        };
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }

        if (is_dir($this->pluginDir)) {
            bjlg_tests_recursive_delete($this->pluginDir);
        }

        if ($this->previousWpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->previousWpdb;
        }

        $lock_property = new ReflectionProperty(BJLG_Backup::class, 'in_memory_lock');
        $lock_property->setAccessible(true);
        $lock_property->setValue(null, null);

        parent::tearDown();
    }

    public function test_create_download_restore_round_trip_restores_files_and_sql(): void
    {
        $task_id = 'bjlg_backup_' . md5(uniqid('roundtrip', true));
        set_transient($task_id, [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => 'Initialisation',
            'components' => ['db', 'plugins'],
            'encrypt' => false,
            'incremental' => false,
            'source' => 'tests',
            'start_time' => time(),
            'post_checks' => ['checksum' => true, 'dry_run' => true],
        ], HOUR_IN_SECONDS);

        $backup = new BJLG_Backup();
        $backup->run_backup_task($task_id);

        $task = get_transient($task_id);
        $this->assertIsArray($task);
        $this->assertSame('complete', $task['status'], (string) ($task['status_text'] ?? ''));
        $this->assertSame(100, $task['progress']);
        $this->assertNotEmpty($task['checksum'] ?? '');
        $this->assertNotEmpty($task['filepath'] ?? '');

        $archive_path = (string) $task['filepath'];
        $this->createdPaths[] = $archive_path;
        $this->createdPaths[] = BJLG_Backup_Integrity::sidecar_path($archive_path);

        $this->assertFileExists($archive_path);
        $this->assertTrue(BJLG_Backup_Integrity::is_backup_archive($archive_path));

        $this->assertFileExists(BJLG_Backup_Integrity::sidecar_path($archive_path));
        $expected_hash = hash_file('sha256', $archive_path);
        $this->assertSame($expected_hash, $task['checksum']);
        $this->assertSame($expected_hash, BJLG_Backup_Integrity::read_sidecar($archive_path));

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($archive_path) === true);
        $sql = $zip->getFromName('database.sql');
        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE TABLE `wp_posts`', $sql);
        $this->assertStringContainsString('Hello round-trip', $sql);
        $plugin_entry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && substr($name, -strlen('bjlg-roundtrip-plugin/marker.txt')) === 'bjlg-roundtrip-plugin/marker.txt') {
                $plugin_entry = $name;
                break;
            }
        }
        $this->assertNotNull($plugin_entry);
        $this->assertSame("round-trip-original\n", $zip->getFromName($plugin_entry));
        $zip->close();

        $previous_filter = $GLOBALS['bjlg_test_hooks']['filters']['bjlg_pre_stream_backup'] ?? null;
        $GLOBALS['bjlg_test_hooks']['filters']['bjlg_pre_stream_backup'] = [];
        $downloaded = '';
        add_filter('bjlg_pre_stream_backup', static function ($short, $filepath) use (&$downloaded) {
            $downloaded = (string) file_get_contents((string) $filepath);

            return '';
        }, 10, 2);

        try {
            $streamed = \BJLG\BJLG_Actions::stream_backup_file($archive_path);
            $this->assertFalse($streamed);
        } finally {
            if ($previous_filter === null) {
                unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_pre_stream_backup']);
            } else {
                $GLOBALS['bjlg_test_hooks']['filters']['bjlg_pre_stream_backup'] = $previous_filter;
            }
        }

        $this->assertSame(file_get_contents($archive_path), $downloaded);

        file_put_contents($this->pluginFile, "round-trip-mutated\n");
        $this->assertSame("round-trip-mutated\n", file_get_contents($this->pluginFile));

        $restore_id = 'bjlg_restore_' . md5(uniqid('roundtrip', true));
        set_transient($restore_id, [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => '',
            'filename' => basename($archive_path),
            'filepath' => $archive_path,
            'password_encrypted' => null,
            'components' => ['db', 'plugins'],
            'start_time' => time(),
        ], HOUR_IN_SECONDS);

        $GLOBALS['wpdb']->queries = [];
        $restore = new BJLG_Restore();
        $restore->run_restore_task($restore_id);

        $restore_task = get_transient($restore_id);
        $this->assertIsArray($restore_task);
        $this->assertSame('complete', $restore_task['status'], (string) ($restore_task['status_text'] ?? ''));
        $this->assertSame("round-trip-original\n", file_get_contents($this->pluginFile));

        $sql_queries = array_values(array_filter(
            $GLOBALS['wpdb']->queries,
            static function ($query) {
                return stripos((string) $query, 'CREATE TABLE') !== false
                    || stripos((string) $query, 'INSERT INTO') !== false;
            }
        ));
        $this->assertNotEmpty($sql_queries, 'La restauration SQL doit exécuter CREATE/INSERT.');
        $this->assertSame($expected_hash, $restore_task['checksum'] ?? null);
    }

    public function test_restore_rejects_checksum_mismatch(): void
    {
        $archive = BJLG_Test_BackupFixtures::createBackupArchive([
            'manifest' => ['type' => 'full', 'contains' => ['db']],
            'database' => "CREATE TABLE `wp_ok` (id INT);\n",
        ]);
        $this->createdPaths[] = $archive['path'];

        BJLG_Backup_Integrity::write_sidecar($archive['path']);
        $this->createdPaths[] = BJLG_Backup_Integrity::sidecar_path($archive['path']);
        file_put_contents($archive['path'], file_get_contents($archive['path']) . 'tamper');

        $restore_id = 'bjlg_restore_' . md5(uniqid('checksum', true));
        set_transient($restore_id, [
            'progress' => 0,
            'status' => 'pending',
            'filename' => basename($archive['path']),
            'filepath' => $archive['path'],
            'components' => ['db'],
            'start_time' => time(),
        ], HOUR_IN_SECONDS);

        (new BJLG_Restore())->run_restore_task($restore_id);
        $task = get_transient($restore_id);
        $this->assertIsArray($task);
        $this->assertSame('error', $task['status']);
        $this->assertStringContainsString('Checksum SHA-256 invalide', (string) $task['status_text']);
    }
}
