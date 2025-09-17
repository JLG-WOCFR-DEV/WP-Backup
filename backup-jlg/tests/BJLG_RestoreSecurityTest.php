<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-restore.php';

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

    public function test_password_is_sanitized_and_encrypted_before_storage(): void
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

        $expected_sanitized = sanitize_text_field("  pa\nss\tword  ");
        $this->assertNotSame($expected_sanitized, $encrypted_password);
        $this->assertStringNotContainsString($expected_sanitized, $encrypted_password);

        $reflection = new ReflectionClass(BJLG\BJLG_Restore::class);
        $method = $reflection->getMethod('decrypt_password_from_transient');
        $method->setAccessible(true);
        $decrypted_password = $method->invoke($restore, $encrypted_password);

        $this->assertSame($expected_sanitized, $decrypted_password);
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
}
