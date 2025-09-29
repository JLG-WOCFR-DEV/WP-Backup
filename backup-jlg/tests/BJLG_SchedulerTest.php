<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!class_exists('BJLG\\BJLG_Debug') && !class_exists('BJLG_Debug')) {
    class BJLG_Debug
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

    class_alias('BJLG_Debug', 'BJLG\\BJLG_Debug');
}

require_once __DIR__ . '/../includes/class-bjlg-backup.php';
require_once __DIR__ . '/../includes/class-bjlg-scheduler.php';

final class BJLG_SchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['bjlg_test_current_user_can'] = true;
        $GLOBALS['bjlg_test_hooks'] = [
            'actions' => [],
            'filters' => [],
        ];
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_scheduled_events'] = [
            'recurring' => [],
            'single' => [],
        ];
        $GLOBALS['bjlg_test_options'] = [];
        $_POST = [];
        $_REQUEST = [];
    }

    public function test_handle_run_scheduled_now_sets_start_time(): void
    {
        update_option('bjlg_schedule_settings', [
            'components' => ['db', 'themes'],
            'encrypt' => true,
            'incremental' => true,
        ]);

        $_POST['nonce'] = 'test-nonce';

        $scheduler = BJLG\BJLG_Scheduler::instance();

        try {
            $scheduler->handle_run_scheduled_now();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('task_id', $response->data);
            $task_id = $response->data['task_id'];
        }

        $this->assertArrayHasKey($task_id, $GLOBALS['bjlg_test_transients']);
        $task_data = $GLOBALS['bjlg_test_transients'][$task_id];

        $this->assertArrayHasKey('start_time', $task_data);
        $this->assertIsInt($task_data['start_time']);
        $this->assertGreaterThan(0, $task_data['start_time']);
    }

    public function test_handle_run_scheduled_now_returns_error_on_wp_error(): void
    {
        update_option('bjlg_schedule_settings', []);

        $_POST['nonce'] = 'test-nonce';

        add_filter('pre_schedule_event', static function ($pre, array $event) {
            if ($event['hook'] === 'bjlg_run_backup_task') {
                return new WP_Error('cron_failure', 'Cron failure for tests');
            }

            return $pre;
        }, 10, 2);

        $scheduler = BJLG\BJLG_Scheduler::instance();

        try {
            $scheduler->handle_run_scheduled_now();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertSame(500, $response->status_code);
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('message', $response->data);
            $this->assertArrayHasKey('details', $response->data);
            $this->assertSame('Cron failure for tests', $response->data['details']);
        }

        unset($GLOBALS['bjlg_test_hooks']['filters']['pre_schedule_event']);

        $this->assertEmpty($GLOBALS['bjlg_test_transients']);
        $this->assertEmpty($GLOBALS['bjlg_test_scheduled_events']['single']);
    }
}
