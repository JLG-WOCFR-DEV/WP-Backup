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
}
