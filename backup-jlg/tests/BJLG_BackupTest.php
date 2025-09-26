<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

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
    }

    protected function tearDown(): void
    {
        $GLOBALS['bjlg_test_set_transient_mock'] = null;
        $GLOBALS['bjlg_test_schedule_single_event_mock'] = null;
        $_POST = [];

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
}
