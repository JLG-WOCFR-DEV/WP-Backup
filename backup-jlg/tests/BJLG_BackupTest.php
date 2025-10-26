<?php
declare(strict_types=1);

use BJLG\BJLG_Destination_Interface;
use BJLG\BJLG_Encryption;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-backup.php';
require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-sftp.php';
require_once __DIR__ . '/../includes/class-bjlg-encryption.php';

if (!defined('BJLG_ENCRYPTION_KEY')) {
    $raw_key = str_repeat('A', BJLG\BJLG_Encryption::KEY_LENGTH);
    define('BJLG_ENCRYPTION_KEY', 'base64:' . base64_encode($raw_key));
}

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

    public function test_reserve_task_slot_reclaims_expired_option_lock(): void
    {
        $task_id = 'bjlg_backup_' . md5('expired-lock');
        $stale_payload = [
            'owner' => 'bjlg_backup_' . md5('stale'),
            'acquired_at' => time() - 3600,
            'initialized' => true,
            'expires_at' => time() - 30,
        ];

        $option_name = '_transient_bjlg_backup_task_lock';
        $timeout_name = '_transient_timeout_bjlg_backup_task_lock';

        $GLOBALS['bjlg_test_options'][$option_name] = $stale_payload;
        $GLOBALS['bjlg_test_options'][$timeout_name] = $stale_payload['expires_at'];
        $GLOBALS['bjlg_test_transients']['bjlg_backup_task_lock'] = $stale_payload;

        $this->assertTrue(
            BJLG\BJLG_Backup::reserve_task_slot($task_id),
            'The first task after expiration should reclaim the lock.'
        );

        $stored_payload = $GLOBALS['bjlg_test_options'][$option_name] ?? null;

        $this->assertIsArray($stored_payload, 'The lock payload should be recreated in options.');
        $this->assertSame(
            $task_id,
            $stored_payload['owner'],
            'The lock owner should be updated to the requesting task.'
        );
        $this->assertGreaterThan(time(), $stored_payload['expires_at'], 'The lock expiration should be extended.');
        $this->assertGreaterThan(time(), $GLOBALS['bjlg_test_options'][$timeout_name]);

        BJLG\BJLG_Backup::release_task_slot($task_id);
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

        $previous = bjlg_get_option('bjlg_backup_include_patterns');
        bjlg_update_option('bjlg_backup_include_patterns', ['wp-content/uploads/images', 'uploads/media/*']);

        $method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'resolve_include_patterns');
        $method->setAccessible(true);

        try {
            $patterns = $method->invoke($backup, []);
        } finally {
            bjlg_update_option('bjlg_backup_include_patterns', $previous);
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
        $previous_settings = bjlg_get_option('bjlg_encryption_settings', null);
        bjlg_update_option('bjlg_encryption_settings', ['enabled' => true]);

        $encryption = new BJLG\BJLG_Encryption();
        $backup = new BJLG\BJLG_Backup(null, $encryption);

        $plainArchive = BJLG_Test_BackupFixtures::createBackupArchive([
            'manifest' => ['type' => 'full', 'contains' => ['db']],
            'database' => "SELECT 1;\n",
            'files' => ['file.txt' => 'example'],
        ]);

        $method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'perform_post_backup_checks');
        $method->setAccessible(true);

        $results = $method->invoke($backup, $plainArchive['path'], ['checksum' => true, 'dry_run' => true], false);
        $this->assertSame(hash_file('sha256', $plainArchive['path']), $results['checksum']);
        $this->assertSame('sha256', $results['checksum_algorithm']);
        $this->assertSame('passed', $results['dry_run']);
        $this->assertSame('passed', $results['overall_status']);
        $this->assertArrayHasKey('files', $results);
        $this->assertArrayHasKey('backup-manifest.json', $results['files']);
        $this->assertArrayHasKey('database.sql', $results['files']);
        $this->assertSame('passed', $results['files']['backup-manifest.json']['status']);
        $this->assertSame('passed', $results['files']['database.sql']['status']);

        $password = 'secret-pass';
        $encryptedArchive = BJLG_Test_BackupFixtures::createBackupArchive([
            'manifest' => ['type' => 'full', 'contains' => ['db']],
            'database' => "SELECT 2;\n",
            'files' => ['file.txt' => 'encrypted'],
            'encrypt' => true,
            'password' => $password,
        ]);

        $encryptedResults = $method->invoke(
            new BJLG\BJLG_Backup(null, new BJLG_Encryption()),
            $encryptedArchive['path'],
            [
                'checksum' => true,
                'dry_run' => true,
                'encryption' => ['password' => $password],
            ],
            true
        );

        $this->assertSame('passed', $encryptedResults['dry_run']);
        $this->assertSame('passed', $encryptedResults['overall_status']);
        $this->assertSame('passed', $encryptedResults['files']['backup-manifest.json']['status']);
        $this->assertSame('passed', $encryptedResults['files']['database.sql']['status']);

        @unlink($plainArchive['path']);
        @unlink($encryptedArchive['path']);
    }

    public function test_perform_post_backup_checks_detects_invalid_hmac_on_encrypted_archive(): void
    {
        $password = 'integrity-check';

        $plainArchive = BJLG_Test_BackupFixtures::createBackupArchive([
            'manifest' => ['type' => 'full', 'contains' => ['db']],
            'database' => "SELECT 3;\n",
            'files' => ['file.txt' => 'hmac'],
        ]);

        $zip_path = $plainArchive['path'];

        $encryptedArchive = BJLG_Test_BackupFixtures::createBackupArchive([
            'manifest' => ['type' => 'full', 'contains' => ['db']],
            'database' => "SELECT 3;\n",
            'files' => ['file.txt' => 'hmac'],
            'encrypt' => true,
            'password' => $password,
        ]);

        BJLG_Test_BackupFixtures::corruptEncryptedArchiveHmac($encryptedArchive['path']);

        $method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'perform_post_backup_checks');
        $method->setAccessible(true);

        $encryption = new BJLG\BJLG_Encryption();
        $backup = new BJLG\BJLG_Backup(null, $encryption);
        $previous_settings = bjlg_get_option('bjlg_encryption_settings', null);

        $encrypted_path = null;
        $corrupted_zip = null;
        $corrupted_encrypted = null;
        $password_encrypted = null;
        $password_zip_source = null;
        $password_results = null;

        try {
            $results = $method->invoke($backup, $zip_path, ['checksum' => true, 'dry_run' => true], false);
            $this->assertSame(hash_file('sha256', $zip_path), $results['checksum']);
            $this->assertSame('sha256', $results['checksum_algorithm']);
            $this->assertSame('passed', $results['dry_run']);
            $this->assertSame('passed', $results['overall_status']);
            $this->assertSame('passed', $results['files']['backup-manifest.json']['status']);
            $this->assertSame('passed', $results['files']['database.sql']['status']);

            $copy_source = $zip_path . '.enc-source';
            $this->assertTrue(copy($zip_path, $copy_source));
            $encrypted_path = $encryption->encrypt_backup_file($copy_source);

            $encrypted_results = $method->invoke($backup, $encrypted_path, ['checksum' => true, 'dry_run' => true], true);
            $this->assertSame(hash_file('sha256', $encrypted_path), $encrypted_results['checksum']);
            $this->assertSame('sha256', $encrypted_results['checksum_algorithm']);
            $this->assertSame('passed', $encrypted_results['dry_run']);
            $this->assertSame('passed', $encrypted_results['overall_status']);
            $this->assertSame('passed', $encrypted_results['files']['backup-manifest.json']['status']);
            $this->assertSame('passed', $encrypted_results['files']['database.sql']['status']);

            $corrupted_zip = tempnam(sys_get_temp_dir(), 'bjlg-corrupt');
            $this->assertIsString($corrupted_zip);
            $corrupted_zip .= '.zip';

            $corrupt_archive = new ZipArchive();
            $this->assertTrue($corrupt_archive->open($corrupted_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $corrupt_archive->addFromString('backup-manifest.json', json_encode(['contains' => ['db'], 'type' => 'full']));
            $corrupt_archive->close();

            $corrupted_source = $corrupted_zip . '.enc-source';
            $this->assertTrue(copy($corrupted_zip, $corrupted_source));
            $corrupted_encrypted = $encryption->encrypt_backup_file($corrupted_source);

            /** @var array<string,mixed> $failed_results */
            $failed_results = $method->invoke($backup, $corrupted_encrypted, ['checksum' => false, 'dry_run' => true], true);
            $this->assertSame('passed', $failed_results['dry_run']);
            $this->assertSame('failed', $failed_results['overall_status']);
            $this->assertSame('failed', $failed_results['files']['database.sql']['status']);

            bjlg_update_option('bjlg_encryption_settings', ['enabled' => true, 'password_protect' => true]);
            $password_encryption = new BJLG\BJLG_Encryption();
            $password_backup = new BJLG\BJLG_Backup(null, $password_encryption);

            $password_zip_source = $zip_path . '.password-source';
            $this->assertTrue(copy($zip_path, $password_zip_source));
            $password = 'super-secret';
            $password_encrypted = $password_encryption->encrypt_backup_file($password_zip_source, $password);

            add_filter('bjlg_post_backup_checks_password', static function ($provided) use ($password) {
                return $password;
            }, 10, 4);

            try {
                $password_results = $method->invoke($password_backup, $password_encrypted, ['checksum' => false, 'dry_run' => true], true);
            } finally {
                unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_post_backup_checks_password']);
            }

            $this->assertIsArray($password_results);
            $this->assertSame('passed', $password_results['dry_run']);
            $this->assertSame('passed', $password_results['overall_status']);
            $this->assertSame('passed', $password_results['files']['backup-manifest.json']['status']);
            $this->assertSame('passed', $password_results['files']['database.sql']['status']);
        } finally {
            @unlink($zip_path);
            if ($encrypted_path !== null) {
                @unlink($encrypted_path);
            }
            if ($corrupted_zip !== null) {
                @unlink($corrupted_zip);
            }
            if ($corrupted_encrypted !== null) {
                @unlink($corrupted_encrypted);
            }
            if ($password_encrypted !== null) {
                @unlink($password_encrypted);
            }
            if ($password_zip_source !== null) {
                @unlink($password_zip_source);
            }

            @unlink($encryptedArchive['path']);

            if ($previous_settings === null) {
                unset($GLOBALS['bjlg_test_options']['bjlg_encryption_settings']);
            } else {
                bjlg_update_option('bjlg_encryption_settings', $previous_settings);
            }
        }
    }

    public function test_perform_post_backup_checks_detects_truncated_archive(): void
    {
        $zip_path = tempnam(sys_get_temp_dir(), 'bjlg-truncated');
        $this->assertIsString($zip_path);
        $zip_path .= '.zip';

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $manifest = [
            'contains' => ['db'],
        ];

        $zip->addFromString('backup-manifest.json', json_encode($manifest));
        $sqlContent = "CREATE TABLE `wp_test` (id INT);\nINSERT INTO `wp_test` VALUES (1);\n";
        $zip->addFromString('database.sql', $sqlContent);
        $zip->close();

        $truncatedZip = new class extends ZipArchive {
            /** @var array<int, string> */
            public $truncate = [];

            #[\ReturnTypeWillChange]
            public function getFromName($name, $length = 0, $flags = 0)
            {
                $data = parent::getFromName($name, $length, $flags);

                if ($data !== false && in_array($name, $this->truncate, true)) {
                    $cut = max(0, strlen($data) - 5);

                    return substr($data, 0, $cut);
                }

                return $data;
            }
        };
        $truncatedZip->truncate = ['database.sql'];

        $backup = new class($truncatedZip) extends BJLG\BJLG_Backup {
            private ZipArchive $zip;

            public function __construct(ZipArchive $zip)
            {
                $this->zip = $zip;
            }

            protected function create_zip_archive()
            {
                return $this->zip;
            }
        };

        $method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'perform_post_backup_checks');
        $method->setAccessible(true);

        try {
            /** @var array<string, mixed> $results */
            $results = $method->invoke($backup, $zip_path, ['checksum' => false, 'dry_run' => true], false);

            $this->assertSame('passed', $results['dry_run']);
            $this->assertSame('failed', $results['overall_status']);
            $this->assertArrayHasKey('database.sql', $results['files']);
            $this->assertSame('failed', $results['files']['database.sql']['status']);
            $this->assertSame('passed', $results['files']['backup-manifest.json']['status']);
            $this->assertSame(strlen($sqlContent), $results['files']['database.sql']['expected_size']);
            $this->assertLessThan($results['files']['database.sql']['expected_size'], $results['files']['database.sql']['read_size']);
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

            public function delete_remote_backup_by_name($filename)
            {
                return ['success' => false, 'message' => ''];
            }

            public function get_storage_usage()
            {
                return ['used_bytes' => null, 'quota_bytes' => null, 'free_bytes' => null];
            }

            public function get_remote_quota_snapshot()
            {
                return [
                    'status' => 'ok',
                    'used_bytes' => null,
                    'quota_bytes' => null,
                    'free_bytes' => null,
                    'latency_ms' => null,
                    'source' => 'mock',
                    'fetched_at' => time(),
                ];
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

            public function delete_remote_backup_by_name($filename)
            {
                return ['success' => true, 'message' => ''];
            }

            public function get_storage_usage()
            {
                return ['used_bytes' => 0, 'quota_bytes' => null, 'free_bytes' => null];
            }

            public function get_remote_quota_snapshot()
            {
                return [
                    'status' => 'ok',
                    'used_bytes' => 0,
                    'quota_bytes' => null,
                    'free_bytes' => null,
                    'latency_ms' => null,
                    'source' => 'mock',
                    'fetched_at' => time(),
                ];
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

        $previous_settings = bjlg_get_option('bjlg_sftp_settings');

        bjlg_update_option('bjlg_sftp_settings', [
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
            bjlg_update_option('bjlg_sftp_settings', $previous_settings);
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

        $previous = bjlg_get_option('bjlg_backup_secondary_destinations');
        bjlg_update_option('bjlg_backup_secondary_destinations', ['google_drive', 'aws_s3']);

        $method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'resolve_destination_queue');
        $method->setAccessible(true);

        try {
            $queue = $method->invoke($backup, []);
        } finally {
            bjlg_update_option('bjlg_backup_secondary_destinations', $previous);
        }

        $this->assertSame(['google_drive', 'aws_s3'], $queue);
    }
}
