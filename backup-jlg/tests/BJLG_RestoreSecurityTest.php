<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BJLG_VERSION')) {
    define('BJLG_VERSION', 'test-version');
}

if (!function_exists('current_time')) {
    function current_time($type = 'mysql') {
        if ($type === 'mysql') {
            return gmdate('Y-m-d H:i:s');
        }

        return time();
    }
}

if (!class_exists('BJLG\\BJLG_Debug')) {
    require_once __DIR__ . '/../includes/class-bjlg-debug.php';
}

if (!class_exists('BJLG_Test_Debug_Logger')) {
    class BJLG_Test_Debug_Logger
    {
        /** @var array<int, string> */
        public static $logs = [];

        public static function __callStatic($name, $arguments)
        {
            $target = '\\BJLG\\BJLG_Debug';

            if (class_exists($target) && method_exists($target, $name)) {
                return forward_static_call_array([$target, $name], $arguments);
            }

            throw new \BadMethodCallException(sprintf('Method %s::%s does not exist.', $target, $name));
        }
    }

    if (class_exists('BJLG\\BJLG_Debug')) {
        BJLG_Test_Debug_Logger::$logs =& \BJLG\BJLG_Debug::$logs;
    } else {
        class_alias('BJLG_Test_Debug_Logger', 'BJLG\\BJLG_Debug');
    }
}
require_once __DIR__ . '/../includes/class-bjlg-restore.php';
require_once __DIR__ . '/../includes/class-bjlg-encryption.php';

final class BJLG_RestoreSecurityTest extends TestCase
{
    /**
     * @var string
     */
    private $existingBackupPath;

    /**
     * @var array<int, string>
     */
    private $additionalBackupPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['bjlg_test_current_user_can'] = true;
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_scheduled_events'] = [
            'recurring' => [],
            'single' => [],
        ];
        $GLOBALS['bjlg_test_set_transient_mock'] = null;
        $GLOBALS['bjlg_test_schedule_single_event_mock'] = null;

        $_POST = [];
        $this->additionalBackupPaths = [];

        if (!is_dir(BJLG_BACKUP_DIR)) {
            mkdir(BJLG_BACKUP_DIR, 0777, true);
        }

        $this->existingBackupPath = BJLG_BACKUP_DIR . 'backup.zip';
        file_put_contents($this->existingBackupPath, 'dummy-backup');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->existingBackupPath)) {
            unlink($this->existingBackupPath);
        }

        foreach ($this->additionalBackupPaths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->additionalBackupPaths = [];

        $GLOBALS['bjlg_test_set_transient_mock'] = null;
        $GLOBALS['bjlg_test_schedule_single_event_mock'] = null;

        parent::tearDown();
    }

    public function test_password_is_preserved_and_encrypted_before_storage(): void
    {
        $_POST['nonce'] = 'nonce';
        $_POST['filename'] = 'backup.zip';
        $_POST['password'] = "  pa\nss\tword  ";

        $restore = new BJLG\BJLG_Restore();

        try {
            $restore->handle_run_restore();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertArrayHasKey('task_id', $response->data);
            $task_id = $response->data['task_id'];
        }

        $task_data = get_transient($task_id);
        $this->assertNotFalse($task_data);
        $this->assertArrayHasKey('password_encrypted', $task_data);

        $encrypted_password = $task_data['password_encrypted'];
        $this->assertNotEmpty($encrypted_password);

        $raw_password = wp_unslash($_POST['password']);
        $sanitized_password = sanitize_text_field($raw_password);
        $this->assertNotSame($sanitized_password, $raw_password);
        $this->assertNotSame($sanitized_password, $encrypted_password);
        $this->assertStringNotContainsString($sanitized_password, $encrypted_password);

        $reflection = new ReflectionClass(BJLG\BJLG_Restore::class);
        $method = $reflection->getMethod('decrypt_password_from_transient');
        $method->setAccessible(true);
        $decrypted_password = $method->invoke($restore, $encrypted_password);

        $this->assertSame($raw_password, $decrypted_password);
    }

    public function test_handle_run_restore_stores_create_restore_point_flag(): void
    {
        $_POST['nonce'] = 'nonce';
        $_POST['filename'] = 'backup.zip';
        $_POST['create_backup_before_restore'] = '1';

        $restore = new BJLG\BJLG_Restore();

        try {
            $restore->handle_run_restore();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertArrayHasKey('task_id', $response->data);
            $task_id = $response->data['task_id'];
        }

        $task_data = get_transient($task_id);
        $this->assertIsArray($task_data);
        $this->assertArrayHasKey('create_restore_point', $task_data);
        $this->assertTrue($task_data['create_restore_point']);
    }

    public function test_handle_run_restore_defaults_to_not_creating_restore_point(): void
    {
        $_POST['nonce'] = 'nonce';
        $_POST['filename'] = 'backup.zip';

        $restore = new BJLG\BJLG_Restore();

        try {
            $restore->handle_run_restore();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertArrayHasKey('task_id', $response->data);
            $task_id = $response->data['task_id'];
        }

        $task_data = get_transient($task_id);
        $this->assertIsArray($task_data);
        $this->assertArrayHasKey('create_restore_point', $task_data);
        $this->assertFalse($task_data['create_restore_point']);
    }

    public function test_handle_run_restore_allows_encrypted_backup_with_password(): void
    {
        $encryptedPath = BJLG_BACKUP_DIR . 'encrypted-backup.zip.enc';
        file_put_contents($encryptedPath, 'encrypted-dummy');
        $this->additionalBackupPaths[] = $encryptedPath;

        $_POST['nonce'] = 'nonce';
        $_POST['filename'] = basename($encryptedPath);
        $_POST['password'] = 'super-secret';

        $restore = new BJLG\BJLG_Restore();

        try {
            $restore->handle_run_restore();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertArrayHasKey('task_id', $response->data);
            $task_id = $response->data['task_id'];
        }

        $task_data = get_transient($task_id);
        $this->assertIsArray($task_data);
        $this->assertSame(basename($encryptedPath), $task_data['filename']);
        $this->assertArrayHasKey('password_encrypted', $task_data);
        $this->assertNotEmpty($task_data['password_encrypted']);
    }

    public function test_handle_run_restore_requires_password_for_encrypted_backup(): void
    {
        $encryptedPath = BJLG_BACKUP_DIR . 'encrypted-no-password.zip.enc';
        file_put_contents($encryptedPath, 'encrypted-dummy');
        $this->additionalBackupPaths[] = $encryptedPath;

        $_POST['nonce'] = 'nonce';
        $_POST['filename'] = basename($encryptedPath);

        $restore = new BJLG\BJLG_Restore();

        try {
            $restore->handle_run_restore();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('message', $response->data);
            $this->assertSame('Un mot de passe est requis pour restaurer une sauvegarde chiffrée.', $response->data['message']);
            $this->assertArrayHasKey('validation_errors', $response->data);
            $this->assertIsArray($response->data['validation_errors']);
            $this->assertArrayHasKey('password', $response->data['validation_errors']);
            $this->assertContains(
                'Un mot de passe est requis pour restaurer une sauvegarde chiffrée.',
                $response->data['validation_errors']['password']
            );
        }

        $this->assertSame([], $GLOBALS['bjlg_test_transients']);
    }

    public function test_handle_run_restore_requires_minimum_password_length(): void
    {
        $_POST['nonce'] = 'nonce';
        $_POST['filename'] = 'backup.zip';
        $_POST['password'] = '123';

        $restore = new BJLG\BJLG_Restore();

        try {
            $restore->handle_run_restore();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertTrue(
                $response->status_code === null || $response->status_code === 200,
                'Expected wp_send_json_error to use the default HTTP status code.'
            );
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('message', $response->data);
            $this->assertSame('Le mot de passe doit contenir au moins 4 caractères.', $response->data['message']);
            $this->assertArrayHasKey('validation_errors', $response->data);
            $this->assertIsArray($response->data['validation_errors']);
            $this->assertArrayHasKey('password', $response->data['validation_errors']);
            $this->assertContains(
                'Le mot de passe doit contenir au moins 4 caractères.',
                $response->data['validation_errors']['password']
            );
        }

        $this->assertSame([], $GLOBALS['bjlg_test_transients']);
    }

    public function test_perform_pre_restore_backup_generates_unique_filenames(): void
    {
        $fake_backup_manager = new class extends BJLG\BJLG_Backup {
            public function __construct()
            {
                // Bypass parent initialization for isolated testing.
            }

            public function dump_database($filepath)
            {
                file_put_contents($filepath, 'SQL-DUMP');
            }

            public function add_folder_to_zip(&$zip, $folder, $zip_path, $exclude = [], $incremental = false, $modified_files = [])
            {
                if ($zip instanceof \ZipArchive) {
                    $zip->addFromString(rtrim($zip_path, '/') . '/placeholder.txt', 'placeholder');
                }
            }
        };

        $restore = new BJLG\BJLG_Restore($fake_backup_manager);

        $reflection = new ReflectionClass(BJLG\BJLG_Restore::class);
        $method = $reflection->getMethod('perform_pre_restore_backup');
        $method->setAccessible(true);

        $first_backup = $method->invoke($restore);
        $this->additionalBackupPaths[] = $first_backup['filepath'];

        $second_backup = $method->invoke($restore);
        $this->additionalBackupPaths[] = $second_backup['filepath'];

        $this->assertFileExists($first_backup['filepath']);
        $this->assertFileExists($second_backup['filepath']);
        $this->assertNotSame($first_backup['filename'], $second_backup['filename']);
    }

    public function test_handle_run_restore_interprets_false_string_as_false(): void
    {
        $_POST['nonce'] = 'nonce';
        $_POST['filename'] = 'backup.zip';
        $_POST['create_backup_before_restore'] = 'false';

        $restore = new BJLG\BJLG_Restore();

        try {
            $restore->handle_run_restore();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertArrayHasKey('task_id', $response->data);
            $task_id = $response->data['task_id'];
        }

        $task_data = get_transient($task_id);
        $this->assertIsArray($task_data);
        $this->assertArrayHasKey('create_restore_point', $task_data);
        $this->assertFalse($task_data['create_restore_point']);
    }

    public function test_handle_run_restore_returns_error_when_transient_initialization_fails(): void
    {
        $_POST['nonce'] = 'nonce';
        $_POST['filename'] = 'backup.zip';

        $GLOBALS['bjlg_test_set_transient_mock'] = static function (string $transient, $value = null, $expiration = null) {
            if (strpos($transient, 'bjlg_restore_') === 0) {
                return false;
            }

            return null;
        };

        $restore = new BJLG\BJLG_Restore();

        try {
            $restore->handle_run_restore();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertSame(500, $response->status_code);
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('message', $response->data);
            $this->assertSame("Impossible d'initialiser la tâche de restauration.", $response->data['message']);
        }

        $this->assertEmpty($GLOBALS['bjlg_test_transients']);
        $this->assertArrayHasKey('single', $GLOBALS['bjlg_test_scheduled_events']);
        $this->assertEmpty($GLOBALS['bjlg_test_scheduled_events']['single']);
    }

    public function test_handle_run_restore_cleans_up_when_scheduling_fails(): void
    {
        $_POST['nonce'] = 'nonce';
        $_POST['filename'] = 'backup.zip';

        $captured_task_id = null;

        $GLOBALS['bjlg_test_set_transient_mock'] = static function (string $transient, $value = null, $expiration = null) use (&$captured_task_id) {
            if (strpos($transient, 'bjlg_restore_') === 0) {
                $captured_task_id = $transient;
            }

            return null;
        };

        $GLOBALS['bjlg_test_schedule_single_event_mock'] = static function ($timestamp, $hook, $args = []) {
            if ($hook === 'bjlg_run_restore_task') {
                return false;
            }

            return null;
        };

        $restore = new BJLG\BJLG_Restore();

        try {
            $restore->handle_run_restore();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertNotNull($captured_task_id, 'The restore task identifier should have been captured.');
            $this->assertSame(500, $response->status_code);
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('message', $response->data);
            $this->assertSame("Impossible de planifier la tâche de restauration en arrière-plan.", $response->data['message']);
        }

        $this->assertNotNull($captured_task_id);
        $this->assertArrayNotHasKey($captured_task_id, $GLOBALS['bjlg_test_transients']);
        $this->assertArrayHasKey('single', $GLOBALS['bjlg_test_scheduled_events']);
        $this->assertEmpty($GLOBALS['bjlg_test_scheduled_events']['single']);
    }

    public function test_handle_run_restore_cleans_up_when_scheduling_returns_wp_error(): void
    {
        $_POST['nonce'] = 'nonce';
        $_POST['filename'] = 'backup.zip';

        $captured_task_id = null;

        $GLOBALS['bjlg_test_set_transient_mock'] = static function (string $transient, $value = null, $expiration = null) use (&$captured_task_id) {
            if (strpos($transient, 'bjlg_restore_') === 0) {
                $captured_task_id = $transient;
            }

            return null;
        };

        $GLOBALS['bjlg_test_schedule_single_event_mock'] = static function ($timestamp, $hook, $args = []) {
            if ($hook === 'bjlg_run_restore_task') {
                return new WP_Error('cron_failure', 'Unexpected cron failure');
            }

            return null;
        };

        $restore = new BJLG\BJLG_Restore();

        try {
            $restore->handle_run_restore();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertNotNull($captured_task_id, 'The restore task identifier should have been captured.');
            $this->assertSame(500, $response->status_code);
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('message', $response->data);
            $this->assertSame("Impossible de planifier la tâche de restauration en arrière-plan.", $response->data['message']);
            $this->assertArrayHasKey('details', $response->data);
            $this->assertSame('Unexpected cron failure', $response->data['details']);
        }

        $this->assertNotNull($captured_task_id);
        $this->assertArrayNotHasKey($captured_task_id, $GLOBALS['bjlg_test_transients']);
        $this->assertArrayHasKey('single', $GLOBALS['bjlg_test_scheduled_events']);
        $this->assertEmpty($GLOBALS['bjlg_test_scheduled_events']['single']);
    }

    public function test_restore_rejects_directory_traversal_entries(): void
    {
        $malicious_target = rtrim(BJLG_BACKUP_DIR, '/\\') . '/malicious.php';

        if (file_exists($malicious_target)) {
            unlink($malicious_target);
        }

        $temporary_dir = sys_get_temp_dir() . '/bjlg-restore-test-' . uniqid();
        if (!is_dir($temporary_dir)) {
            mkdir($temporary_dir, 0755, true);
        }

        $zip_path = $temporary_dir . '/malicious.zip';
        $zip = new ZipArchive();
        $open_result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($open_result === true || $open_result === ZipArchive::ER_OK);

        $manifest = [
            'type' => 'test',
            'contains' => ['db'],
        ];

        $zip->addFromString('backup-manifest.json', json_encode($manifest));
        $zip->addFromString('database.sql', 'SELECT 1;');
        $zip->addFromString('../malicious.php', '<?php echo "hacked";');
        $zip->close();

        $destination = BJLG_BACKUP_DIR . 'malicious.zip';
        copy($zip_path, $destination);

        $restore = new BJLG\BJLG_Restore();

        $task_id = 'bjlg_restore_' . uniqid();
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
        $this->assertSame('error', $task_data['status']);
        $this->assertStringContainsString("Entrée d'archive invalide détectée", $task_data['status_text']);
        $this->assertFileDoesNotExist($malicious_target);

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

    public function test_restore_rejects_symlink_entries(): void
    {
        $temporary_dir = sys_get_temp_dir() . '/bjlg-restore-test-symlink-' . uniqid();
        if (!is_dir($temporary_dir)) {
            mkdir($temporary_dir, 0755, true);
        }

        $zip_path = $temporary_dir . '/symlink.zip';
        $zip = new ZipArchive();
        $open_result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($open_result === true || $open_result === ZipArchive::ER_OK);

        $manifest = [
            'type' => 'test',
            'contains' => ['plugins'],
        ];

        $zip->addFromString('backup-manifest.json', json_encode($manifest));
        $zip->addEmptyDir('wp-content/');
        $zip->addEmptyDir('wp-content/plugins/');
        $zip->addFromString('wp-content/plugins/readme.txt', 'safe');

        $symlink_entry = 'wp-content/plugins/evil-link';
        $zip->addFromString($symlink_entry, 'ignored');
        if (method_exists($zip, 'setExternalAttributesName')) {
            $zip->setExternalAttributesName($symlink_entry, ZipArchive::OPSYS_UNIX, (0120777) << 16);
        }

        $zip->close();

        $destination = BJLG_BACKUP_DIR . 'symlink.zip';
        copy($zip_path, $destination);

        $restore = new BJLG\BJLG_Restore();

        $task_id = 'bjlg_restore_' . uniqid();
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
        $this->assertSame('error', $task_data['status']);
        $this->assertStringContainsString('lien symbolique', strtolower($task_data['status_text']));

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

    public function test_restoring_encrypted_backup_removes_plaintext_archive(): void
    {
        update_option('bjlg_encryption_settings', ['enabled' => true]);

        $zip_path = BJLG_BACKUP_DIR . 'encrypted-restore-' . uniqid('', true) . '.zip';
        $zip = new ZipArchive();
        $open_result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($open_result === true || $open_result === ZipArchive::ER_OK);

        $manifest = [
            'type' => 'test',
            'contains' => [],
        ];

        $zip->addFromString('backup-manifest.json', json_encode($manifest));
        $zip->close();

        $encryption = new BJLG\BJLG_Encryption();
        $encrypted_path = $encryption->encrypt_backup_file($zip_path);
        $this->assertFileDoesNotExist($zip_path);
        $this->assertFileExists($encrypted_path);

        $decrypted_path = substr($encrypted_path, 0, -4);

        $restore = new BJLG\BJLG_Restore();
        $task_id = 'bjlg_restore_' . uniqid();

        set_transient($task_id, [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => '',
            'filename' => basename($encrypted_path),
            'filepath' => $encrypted_path,
            'password_encrypted' => null,
        ], defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);

        $restore->run_restore_task($task_id);

        $task_data = get_transient($task_id);
        $this->assertIsArray($task_data);
        $this->assertSame('complete', $task_data['status']);

        $this->assertFileExists($encrypted_path);
        $this->assertFileDoesNotExist($decrypted_path);

        if (file_exists($encrypted_path)) {
            unlink($encrypted_path);
        }
        if (file_exists($decrypted_path)) {
            unlink($decrypted_path);
        }

        update_option('bjlg_encryption_settings', ['enabled' => false]);
    }
}
