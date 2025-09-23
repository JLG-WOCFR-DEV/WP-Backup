<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!class_exists('BJLG\\BJLG_Debug')) {
    require_once __DIR__ . '/../includes/class-bjlg-debug.php';
}
require_once __DIR__ . '/../includes/class-bjlg-restore.php';
require_once __DIR__ . '/../includes/class-bjlg-encryption.php';

final class BJLG_RestoreSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['bjlg_test_current_user_can'] = true;
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_scheduled_events'] = [];

        $_POST = [];
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
