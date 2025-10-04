<?php
declare(strict_types=1);

use BJLG\BJLG_Destination_Interface;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use ZipArchive;

require_once __DIR__ . '/../includes/class-bjlg-backup.php';
require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-sftp.php';

class BJLG_Test_Phpseclib_SFTP_Stub
{
    public const SOURCE_LOCAL_FILE = 1;

    public static array $uploaded = [];

    private array $directories = [];

    public function __construct($host, $port)
    {
        $this->directories['/'] = true;
    }

    public function login($username, $credential)
    {
        return true;
    }

    public function is_dir($path)
    {
        return isset($this->directories[$this->normalize($path)]);
    }

    public function mkdir($path)
    {
        $this->directories[$this->normalize($path)] = true;

        return true;
    }

    public function put($remote_file, $filepath, $mode)
    {
        self::$uploaded[] = [
            'path' => $this->normalize($remote_file, false),
            'local' => $filepath,
            'mode' => $mode,
        ];

        return true;
    }

    public function pwd()
    {
        return '/';
    }

    private function normalize($path, bool $for_directory = true)
    {
        $path = str_replace('\\', '/', (string) $path);
        if ($path === '') {
            return $for_directory ? '/' : '';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        if ($for_directory) {
            return rtrim($path, '/') !== '' ? rtrim($path, '/') : '/';
        }

        return ltrim($path, '/');
    }
}

if (!class_exists('phpseclib3\\Net\\SFTP')) {
    class_alias(BJLG_Test_Phpseclib_SFTP_Stub::class, 'phpseclib3\\Net\\SFTP');
}

if (!class_exists('phpseclib3\\Exception\\UnableToConnectException')) {
    class BJLG_Test_Phpseclib_UnableToConnectException extends Exception
    {
    }

    class_alias(BJLG_Test_Phpseclib_UnableToConnectException::class, 'phpseclib3\\Exception\\UnableToConnectException');
}

final class BJLG_BackupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_scheduled_events'] = [
            'recurring' => [],
            'single' => [],
        ];
        $GLOBALS['bjlg_test_set_transient_mock'] = null;
        $GLOBALS['bjlg_test_schedule_single_event_mock'] = null;

        $_POST = [];

        $lock_property = new ReflectionProperty(BJLG\BJLG_Backup::class, 'in_memory_lock');
        $lock_property->setAccessible(true);
        $lock_property->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $GLOBALS['bjlg_test_set_transient_mock'] = null;
        $GLOBALS['bjlg_test_schedule_single_event_mock'] = null;
        $_POST = [];

        $lock_property = new ReflectionProperty(BJLG\BJLG_Backup::class, 'in_memory_lock');
        $lock_property->setAccessible(true);
        $lock_property->setValue(null, null);

        parent::tearDown();
    }

    public function test_handle_start_backup_task_releases_lock_when_state_save_fails(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $_POST['components'] = ['database'];
        $_POST['nonce'] = 'test-nonce';

        $captured_task_id = null;

        $GLOBALS['bjlg_test_set_transient_mock'] = static function (string $transient, $value = null, $expiration = null) use (&$captured_task_id) {
            if ($transient === 'bjlg_backup_task_lock') {
                return null;
            }

            if (strpos($transient, 'bjlg_backup_') === 0) {
                $captured_task_id = $transient;

                return false;
            }

            return null;
        };

        try {
            $backup->handle_start_backup_task();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertNotNull($captured_task_id, 'The task identifier should have been captured.');
            $this->assertSame(500, $response->status_code);
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('message', $response->data);
            $this->assertSame("Impossible d'initialiser la tâche de sauvegarde.", $response->data['message']);

            $this->assertFalse(BJLG\BJLG_Backup::is_task_locked());
            $this->assertEmpty($GLOBALS['bjlg_test_scheduled_events']['single']);

            $this->assertArrayNotHasKey($captured_task_id, $GLOBALS['bjlg_test_transients']);
            $this->assertArrayNotHasKey('bjlg_backup_task_lock', $GLOBALS['bjlg_test_transients']);
        }
    }

    public function test_second_task_cannot_reserve_lock_before_initialization(): void
    {
        $task_one = 'bjlg_backup_' . md5('first');
        $task_two = 'bjlg_backup_' . md5('second');

        $this->assertTrue(BJLG\BJLG_Backup::reserve_task_slot($task_one));
        $this->assertTrue(BJLG\BJLG_Backup::is_task_locked());
        $this->assertFalse(BJLG\BJLG_Backup::reserve_task_slot($task_two));

        $this->assertTrue(
            BJLG\BJLG_Backup::reserve_task_slot($task_one),
            'The original task should be able to refresh its reservation.'
        );

        BJLG\BJLG_Backup::release_task_slot($task_one);
    }

    public function test_progress_updates_refresh_lock_and_keep_owner(): void
    {
        $task_id = 'bjlg_backup_' . md5('lock-refresh');
        $backup = new BJLG\BJLG_Backup();

        $this->assertTrue(BJLG\BJLG_Backup::reserve_task_slot($task_id));

        $initial_state = [
            'progress' => 0,
            'status' => 'running',
            'status_text' => 'Initialisation',
        ];

        $this->assertTrue(BJLG\BJLG_Backup::save_task_state($task_id, $initial_state));

        $lock_payload = $GLOBALS['bjlg_test_transients']['bjlg_backup_task_lock'] ?? null;
        $this->assertIsArray($lock_payload);
        $this->assertSame($task_id, $lock_payload['owner']);
        $initial_expiration = $lock_payload['expires_at'];

        $progress_method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'update_task_progress');
        $progress_method->setAccessible(true);

        sleep(1);
        $progress_method->invoke($backup, $task_id, 25, 'running', 'Quarter way through');

        $lock_after_first_update = $GLOBALS['bjlg_test_transients']['bjlg_backup_task_lock'] ?? null;
        $this->assertIsArray($lock_after_first_update);
        $this->assertSame($task_id, $lock_after_first_update['owner']);
        $this->assertGreaterThan($initial_expiration, $lock_after_first_update['expires_at']);
        $this->assertSame(25, $GLOBALS['bjlg_test_transients'][$task_id]['progress']);

        $first_extension = $lock_after_first_update['expires_at'];

        sleep(1);
        $progress_method->invoke($backup, $task_id, 50.5, 'running', 'Halfway done');

        $lock_after_second_update = $GLOBALS['bjlg_test_transients']['bjlg_backup_task_lock'] ?? null;
        $this->assertIsArray($lock_after_second_update);
        $this->assertSame($task_id, $lock_after_second_update['owner']);
        $this->assertGreaterThan($first_extension, $lock_after_second_update['expires_at']);
        $this->assertSame(50.5, $GLOBALS['bjlg_test_transients'][$task_id]['progress']);

        BJLG\BJLG_Backup::release_task_slot($task_id);

        $this->assertArrayNotHasKey('bjlg_backup_task_lock', $GLOBALS['bjlg_test_transients']);
        unset($GLOBALS['bjlg_test_transients'][$task_id]);
    }

    public function test_resolve_include_patterns_normalizes_plain_paths(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $previous = get_option('bjlg_backup_include_patterns');
        update_option('bjlg_backup_include_patterns', ['wp-content/uploads/images', 'uploads/media/*']);

        $method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'resolve_include_patterns');
        $method->setAccessible(true);

        try {
            $patterns = $method->invoke($backup, []);
        } finally {
            update_option('bjlg_backup_include_patterns', $previous);
        }

        $this->assertSame(['*wp-content/uploads/images*', 'uploads/media/*'], $patterns);
    }

    public function test_should_include_file_supports_relative_patterns(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'should_include_file');
        $method->setAccessible(true);

        $directory = WP_CONTENT_DIR . '/uploads/custom';
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            $this->fail('Impossible de créer le répertoire de test.');
        }

        $file = $directory . '/file.txt';
        file_put_contents($file, 'content');

        try {
            $this->assertTrue($method->invoke($backup, $file, ['wp-content/uploads/*']));
            $this->assertTrue($method->invoke($backup, $file, ['uploads/custom/*']));
            $this->assertFalse($method->invoke($backup, $file, ['wp-content/themes/*']));
        } finally {
            @unlink($file);
            @rmdir($directory);
            @rmdir(dirname($directory));
        }
    }

    public function test_perform_post_backup_checks_returns_checksum_and_dry_run_status(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $zip_path = tempnam(sys_get_temp_dir(), 'bjlg-checks');
        $this->assertIsString($zip_path);
        $zip_path .= '.zip';

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('file.txt', 'example');
        $zip->close();

        $method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'perform_post_backup_checks');
        $method->setAccessible(true);

        try {
            $results = $method->invoke($backup, $zip_path, ['checksum' => true, 'dry_run' => true], false);
            $this->assertSame(hash_file('sha256', $zip_path), $results['checksum']);
            $this->assertSame('sha256', $results['checksum_algorithm']);
            $this->assertSame('passed', $results['dry_run']);

            $encryptedResults = $method->invoke($backup, $zip_path, ['checksum' => true, 'dry_run' => true], true);
            $this->assertSame('skipped', $encryptedResults['dry_run']);
        } finally {
            @unlink($zip_path);
        }
    }

    public function test_dispatch_to_destinations_records_failures_and_successes(): void
    {
        $backup = new BJLG\BJLG_Backup();
        $method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'dispatch_to_destinations');
        $method->setAccessible(true);

        $file = tempnam(sys_get_temp_dir(), 'bjlg-dispatch');
        $this->assertIsString($file);
        file_put_contents($file, 'payload');

        $primary = new class implements BJLG_Destination_Interface {
            public int $uploads = 0;

            public function get_id()
            {
                return 'primary';
            }

            public function get_name()
            {
                return 'Primaire';
            }

            public function is_connected()
            {
                return true;
            }

            public function disconnect(): void
            {
            }

            public function render_settings(): void
            {
            }

            public function upload_file($filepath, $task_id)
            {
                $this->uploads++;
                throw new Exception('API indisponible');
            }

            public function list_remote_backups()
            {
                return [];
            }

            public function prune_remote_backups($retain_by_number, $retain_by_age_days)
            {
                return ['deleted' => 0, 'errors' => [], 'inspected' => 0, 'deleted_items' => []];
            }
        };

        $secondary = new class implements BJLG_Destination_Interface {
            public array $uploads = [];

            public function get_id()
            {
                return 'secondary';
            }

            public function get_name()
            {
                return 'Secours';
            }

            public function is_connected()
            {
                return true;
            }

            public function disconnect(): void
            {
            }

            public function render_settings(): void
            {
            }

            public function upload_file($filepath, $task_id)
            {
                $this->uploads[] = [$filepath, $task_id];
            }

            public function list_remote_backups()
            {
                return [];
            }

            public function prune_remote_backups($retain_by_number, $retain_by_age_days)
            {
                return ['deleted' => 0, 'errors' => [], 'inspected' => 0, 'deleted_items' => []];
            }
        };

        add_filter('bjlg_backup_instantiate_destination', static function ($provided, $destination_id) use ($primary, $secondary) {
            if ($destination_id === 'primary') {
                return $primary;
            }

            if ($destination_id === 'secondary') {
                return $secondary;
            }

            return $provided;
        }, 10, 2);

        try {
            $results = $method->invoke($backup, $file, ['primary', 'secondary'], 'task-99');
        } finally {
            unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_backup_instantiate_destination']);
            @unlink($file);
        }

        $this->assertSame(['secondary'], $results['success']);
        $this->assertArrayHasKey('primary', $results['failures']);
        $this->assertSame('API indisponible', $results['failures']['primary']);
        $this->assertSame(1, $primary->uploads);
        $this->assertCount(1, $secondary->uploads);
        $this->assertSame('task-99', $secondary->uploads[0][1]);
    }

    public function test_dispatch_to_destinations_supports_sftp_destination(): void
    {
        if (class_exists('phpseclib3\\Net\\SFTP') && !is_a('phpseclib3\\Net\\SFTP', BJLG_Test_Phpseclib_SFTP_Stub::class, true)) {
            $this->markTestSkipped('Real phpseclib SFTP implementation is available; this test relies on the stub.');
        }

        \phpseclib3\Net\SFTP::$uploaded = [];

        update_option('bjlg_sftp_settings', [
            'enabled' => true,
            'host' => 'sftp.example.org',
            'port' => 22,
            'username' => 'backup-user',
            'password' => 'secret',
            'private_key' => '',
            'passphrase' => '',
            'remote_path' => 'wordpress/backups',
            'fingerprint' => '',
        ]);

        $backup = new BJLG\BJLG_Backup();
        $method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'dispatch_to_destinations');
        $method->setAccessible(true);

        $file = tempnam(sys_get_temp_dir(), 'bjlg-sftp');
        $this->assertIsString($file);
        file_put_contents($file, 'content');

        try {
            $results = $method->invoke($backup, $file, ['sftp'], 'task-sftp-1');
        } finally {
            @unlink($file);
            update_option('bjlg_sftp_settings', []);
        }

        $this->assertSame(['sftp'], $results['success']);
        $this->assertSame([], $results['failures']);

        $this->assertCount(1, \phpseclib3\Net\SFTP::$uploaded);
        $upload = \phpseclib3\Net\SFTP::$uploaded[0];
        $this->assertSame('wordpress/backups/' . basename($file), $upload['path']);
        $this->assertSame($file, $upload['local']);
        $this->assertSame(\phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE, $upload['mode']);
    }

    public function test_resolve_destination_queue_uses_saved_options(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $previous = get_option('bjlg_backup_secondary_destinations');
        update_option('bjlg_backup_secondary_destinations', ['google_drive', 'aws_s3']);

        $method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'resolve_destination_queue');
        $method->setAccessible(true);

        try {
            $queue = $method->invoke($backup, []);
        } finally {
            update_option('bjlg_backup_secondary_destinations', $previous);
        }

        $this->assertSame(['google_drive', 'aws_s3'], $queue);
    }
}
