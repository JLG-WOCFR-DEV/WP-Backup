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

    public function test_dispatch_backup_to_destinations_uses_registered_instances(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $fake_destination = new class implements BJLG\BJLG_Destination_Interface {
            /** @var array<int, array<int, string>> */
            public $uploads = [];

            public function get_id()
            {
                return 'fake';
            }

            public function get_name()
            {
                return 'Fake Destination';
            }

            public function is_connected()
            {
                return true;
            }

            public function disconnect()
            {
            }

            public function render_settings()
            {
            }

            public function upload_file($filepath, $task_id)
            {
                $this->uploads[] = [$filepath, $task_id];
            }
        };

        $backup->set_destination_factory(static function ($destination_id) use ($fake_destination) {
            return $destination_id === 'fake' ? $fake_destination : null;
        });

        add_filter('bjlg_available_destination_ids', static function ($available, $context) {
            if ($context === 'manual') {
                return ['fake'];
            }

            return $available;
        }, 10, 2);

        $method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'dispatch_backup_to_destinations');
        $method->setAccessible(true);

        $temp_file = tempnam(sys_get_temp_dir(), 'bjlg-dispatch-');
        if (!is_string($temp_file)) {
            $this->fail('Unable to create a temporary file for the dispatch test.');
        }

        file_put_contents($temp_file, 'archive');

        $task_data = [
            'source' => 'manual',
            'destinations' => ['fake'],
        ];

        $method->invoke($backup, $temp_file, basename($temp_file), 'task-dest', $task_data);

        $this->assertSame([[$temp_file, 'task-dest']], $fake_destination->uploads);

        unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_available_destination_ids']);
        @unlink($temp_file);
    }
}
