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

if (!function_exists('get_date_from_gmt')) {
    function get_date_from_gmt($string, $format = 'Y-m-d H:i:s')
    {
        $timestamp = strtotime($string . ' UTC');

        if ($timestamp === false) {
            return '';
        }

        return gmdate($format, $timestamp);
    }
}

require_once __DIR__ . '/../includes/class-bjlg-backup.php';
require_once __DIR__ . '/../includes/class-bjlg-scheduler.php';

final class BJLG_SchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['bjlg_test_current_user_can'] = true;
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_scheduled_events'] = [
            'recurring' => [],
            'single' => [],
        ];
        $GLOBALS['bjlg_test_options'] = [];
        $GLOBALS['bjlg_test_set_transient_mock'] = null;
        $GLOBALS['bjlg_test_schedule_single_event_mock'] = null;
        BJLG_Debug::$logs = [];
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['bjlg_test_set_transient_mock'] = null;
        $GLOBALS['bjlg_test_schedule_single_event_mock'] = null;
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

    public function test_handle_run_scheduled_now_returns_error_when_transient_fails(): void
    {
        $_POST['nonce'] = 'test-nonce';

        $GLOBALS['bjlg_test_set_transient_mock'] = static function () {
            return false;
        };

        $scheduler = BJLG\BJLG_Scheduler::instance();

        try {
            $scheduler->handle_run_scheduled_now();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertSame(['message' => "Impossible d'initialiser la sauvegarde planifiée."], $response->data);
        }

        $this->assertEmpty($GLOBALS['bjlg_test_transients']);
        $this->assertEmpty($GLOBALS['bjlg_test_scheduled_events']['single']);
        $this->assertNotEmpty(BJLG_Debug::$logs);
        $this->assertTrue(
            (bool) array_filter(
                BJLG_Debug::$logs,
                static fn(string $log): bool => strpos($log, 'ERREUR') !== false
            ),
            'Expected at least one log entry to contain "ERREUR".'
        );
    }

    public function test_handle_run_scheduled_now_returns_error_when_scheduling_fails(): void
    {
        $_POST['nonce'] = 'test-nonce';

        $GLOBALS['bjlg_test_schedule_single_event_mock'] = static function () {
            return new WP_Error('schedule_failed', 'Défaillance de planification.');
        };

        $scheduler = BJLG\BJLG_Scheduler::instance();

        try {
            $scheduler->handle_run_scheduled_now();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertSame(
                [
                    'message' => "Impossible de planifier l'exécution de la sauvegarde planifiée. Raison : Défaillance de planification.",
                ],
                $response->data
            );
        }

        $this->assertEmpty($GLOBALS['bjlg_test_transients']);
        $this->assertEmpty($GLOBALS['bjlg_test_scheduled_events']['single']);
        $this->assertNotEmpty(BJLG_Debug::$logs);
        $this->assertTrue(
            (bool) array_filter(
                BJLG_Debug::$logs,
                static fn(string $log): bool => strpos($log, 'ERREUR') !== false
            ),
            'Expected at least one log entry to contain "ERREUR".'
        );
    }

    public function test_handle_save_schedule_updates_settings_and_returns_next_run(): void
    {
        $_POST = [
            'nonce' => 'test-nonce',
            'recurrence' => 'daily',
            'day' => 'tuesday',
            'time' => '10:15',
            'components' => ['db', 'plugins'],
            'encrypt' => 'true',
            'incremental' => 'true',
        ];

        $scheduler = BJLG\BJLG_Scheduler::instance();

        try {
            $scheduler->handle_save_schedule();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('message', $response->data);
            $this->assertArrayHasKey('next_run', $response->data);
            $this->assertNotEmpty($response->data['next_run']);
        }

        $settings = get_option('bjlg_schedule_settings');

        $this->assertSame('daily', $settings['recurrence']);
        $this->assertSame('tuesday', $settings['day']);
        $this->assertSame('10:15', $settings['time']);
        $this->assertSame(['db', 'plugins'], $settings['components']);
        $this->assertTrue($settings['encrypt']);
        $this->assertTrue($settings['incremental']);

        $this->assertArrayHasKey(
            BJLG\BJLG_Scheduler::SCHEDULE_HOOK,
            $GLOBALS['bjlg_test_scheduled_events']['recurring']
        );

        $event = $GLOBALS['bjlg_test_scheduled_events']['recurring'][BJLG\BJLG_Scheduler::SCHEDULE_HOOK];

        $this->assertSame('daily', $event['recurrence']);
        $this->assertIsInt($event['timestamp']);
        $this->assertGreaterThan(0, $event['timestamp']);
    }
}
