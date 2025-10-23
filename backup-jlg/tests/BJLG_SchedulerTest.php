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
        bjlg_update_option('bjlg_schedule_settings', [
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
            'schedules' => [
                [
                    'id' => '',
                    'label' => 'Sauvegarde quotidienne',
                    'recurrence' => 'daily',
                    'day' => 'tuesday',
                    'day_of_month' => '27',
                    'time' => '10:15',
                    'components' => ['db', 'plugins'],
                    'encrypt' => 'true',
                    'incremental' => 'true',
                    'include_patterns' => "wp-content/uploads/*\ncustom/*",
                    'exclude_patterns' => "*/cache/*\n*.tmp",
                    'post_checks' => ['checksum'],
                    'secondary_destinations' => ['google_drive', 'aws_s3'],
                ],
            ],
        ];

        $scheduler = BJLG\BJLG_Scheduler::instance();

        try {
            $scheduler->handle_save_schedule();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('message', $response->data);
            $this->assertArrayHasKey('schedules', $response->data);
            $this->assertIsArray($response->data['schedules']);
            $this->assertCount(1, $response->data['schedules']);
            $saved_schedule = $response->data['schedules'][0];
            $this->assertSame('daily', $saved_schedule['recurrence']);
            $this->assertSame('tuesday', $saved_schedule['day']);
            $this->assertSame(27, $saved_schedule['day_of_month']);
            $this->assertSame('10:15', $saved_schedule['time']);
            $this->assertNotEmpty($saved_schedule['id']);
            $this->assertArrayHasKey('next_runs', $response->data);
            $this->assertIsArray($response->data['next_runs']);
            $this->assertArrayHasKey($saved_schedule['id'], $response->data['next_runs']);
            $next_run_entry = $response->data['next_runs'][$saved_schedule['id']];
        $this->assertArrayHasKey('next_run', $next_run_entry);
    }

    public function test_generate_cron_impact_summary_uses_recent_durations_filter(): void
    {
        $scheduler = BJLG\BJLG_Scheduler::instance();

        $callback = static function () {
            return [120, 150, 180];
        };

        add_filter('bjlg_scheduler_recent_durations', $callback, 10, 3);

        try {
            $impact = $scheduler->generate_cron_impact_summary('*/5 * * * *');
        } finally {
            remove_filter('bjlg_scheduler_recent_durations', $callback, 10);
        }

        $this->assertIsArray($impact);
        $this->assertSame(288.0, $impact['runs_per_day']);
        $this->assertSame(150.0, $impact['average_duration']);
        $this->assertSame(43200.0, $impact['estimated_load']);
        $this->assertSame('high', $impact['risk']['level']);
        $this->assertGreaterThan(0, $impact['history_samples']);
        $this->assertNotEmpty($impact['risk']['reasons']);
    }

        $collection = bjlg_get_option('bjlg_schedule_settings');

        $this->assertIsArray($collection);
        $this->assertSame(2, $collection['version']);
        $this->assertIsArray($collection['schedules']);
        $this->assertCount(1, $collection['schedules']);

        $stored_schedule = $collection['schedules'][0];
        $this->assertSame('daily', $stored_schedule['recurrence']);
        $this->assertSame('tuesday', $stored_schedule['day']);
        $this->assertSame(27, $stored_schedule['day_of_month']);
        $this->assertSame('10:15', $stored_schedule['time']);
        $this->assertSame(['db', 'plugins'], $stored_schedule['components']);
        $this->assertTrue($stored_schedule['encrypt']);
        $this->assertTrue($stored_schedule['incremental']);
        $this->assertSame(['wp-content/uploads/*', 'custom/*'], $stored_schedule['include_patterns']);
        $this->assertSame(['*/cache/*', '*.tmp'], $stored_schedule['exclude_patterns']);
        $this->assertSame(['google_drive', 'aws_s3'], $stored_schedule['secondary_destinations']);
        $this->assertSame([
            ['google_drive', 'aws_s3'],
        ], $stored_schedule['secondary_destination_batches']);
        $this->assertSame(
            ['checksum' => true, 'dry_run' => false],
            $stored_schedule['post_checks']
        );

        $this->assertSame(['wp-content/uploads/*', 'custom/*'], bjlg_get_option('bjlg_backup_include_patterns'));
        $this->assertSame(['*/cache/*', '*.tmp'], bjlg_get_option('bjlg_backup_exclude_patterns'));
        $this->assertSame(['google_drive', 'aws_s3'], bjlg_get_option('bjlg_backup_secondary_destinations'));
        $this->assertSame(
            ['checksum' => true, 'dry_run' => false],
            bjlg_get_option('bjlg_backup_post_checks')
        );

        $this->assertArrayHasKey(
            BJLG\BJLG_Scheduler::SCHEDULE_HOOK,
            $GLOBALS['bjlg_test_scheduled_events']['recurring']
        );

        $events = $GLOBALS['bjlg_test_scheduled_events']['recurring'][BJLG\BJLG_Scheduler::SCHEDULE_HOOK];
        $this->assertNotEmpty($events);
        $event = reset($events);
        $this->assertSame('daily', $event['recurrence']);
        $this->assertSame([$stored_schedule['id']], $event['args']);
        $this->assertIsInt($event['timestamp']);
        $this->assertGreaterThan(0, $event['timestamp']);
    }

    public function test_handle_save_schedule_accepts_fifteen_minute_recurrence(): void
    {
        $_POST = [
            'nonce' => 'test-nonce',
            'schedules' => [
                [
                    'id' => '',
                    'label' => 'Sauvegarde rapide',
                    'recurrence' => 'every_fifteen_minutes',
                    'day' => 'monday',
                    'day_of_month' => '10',
                    'time' => '00:05',
                    'components' => ['db'],
                    'encrypt' => 'false',
                    'incremental' => 'false',
                    'include_patterns' => '',
                    'exclude_patterns' => '',
                    'post_checks' => [],
                    'secondary_destinations' => [],
                ],
            ],
        ];

        $scheduler = BJLG\BJLG_Scheduler::instance();
        $capturedSchedule = null;

        try {
            $scheduler->handle_save_schedule();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertArrayHasKey('schedules', $response->data);
            $this->assertCount(1, $response->data['schedules']);

            $capturedSchedule = $response->data['schedules'][0];
            $this->assertSame('every_fifteen_minutes', $capturedSchedule['recurrence']);
            $this->assertSame('Sauvegarde rapide', $capturedSchedule['label']);

            $this->assertArrayHasKey('next_runs', $response->data);
            $this->assertArrayHasKey($capturedSchedule['id'], $response->data['next_runs']);
            $this->assertNotEmpty($response->data['next_runs'][$capturedSchedule['id']]['next_run']);
        }

        $this->assertIsArray($capturedSchedule);

        $events = $GLOBALS['bjlg_test_scheduled_events']['recurring'][BJLG\BJLG_Scheduler::SCHEDULE_HOOK] ?? [];
        $this->assertNotEmpty($events, 'Expected at least one cron event to be scheduled.');

        $matching = array_values(array_filter(
            $events,
            static function (array $event) use ($capturedSchedule): bool {
                return in_array($capturedSchedule['id'], $event['args'], true);
            }
        ));

        $this->assertNotEmpty($matching, 'Expected cron event arguments to include the schedule identifier.');
        $this->assertSame('every_fifteen_minutes', $matching[0]['recurrence']);
    }

    public function test_calculate_first_run_respects_day_of_month(): void
    {
        $scheduler = BJLG\BJLG_Scheduler::instance();
        $method = new \ReflectionMethod(BJLG\BJLG_Scheduler::class, 'calculate_first_run');
        $method->setAccessible(true);

        update_option('timezone_string', 'UTC');

        $schedule = [
            'recurrence' => 'monthly',
            'time' => '06:45',
            'day_of_month' => 19,
        ];

        $timestamp = $method->invoke($scheduler, $schedule);
        $this->assertIsInt($timestamp);

        $timezone = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $timezone);
        $expected = $this->computeExpectedMonthlyTimestamp($now, 19, 6, 45);

        $this->assertSame($expected, $timestamp);
    }

    public function test_calculate_first_run_clamps_day_of_month_when_needed(): void
    {
        $scheduler = BJLG\BJLG_Scheduler::instance();
        $method = new \ReflectionMethod(BJLG\BJLG_Scheduler::class, 'calculate_first_run');
        $method->setAccessible(true);

        update_option('timezone_string', 'UTC');

        $schedule = [
            'recurrence' => 'monthly',
            'time' => '02:30',
            'day_of_month' => 31,
        ];

        $timestamp = $method->invoke($scheduler, $schedule);
        $this->assertIsInt($timestamp);

        $timezone = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $timezone);
        $expected = $this->computeExpectedMonthlyTimestamp($now, 31, 2, 30);

        $this->assertSame($expected, $timestamp);

        $runDate = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
        $this->assertSame(
            min(31, (int) $runDate->format('t')),
            (int) $runDate->format('j')
        );
        $this->assertSame('02:30', $runDate->format('H:i'));
    }

    private function computeExpectedMonthlyTimestamp(\DateTimeImmutable $now, int $dayOfMonth, int $hour, int $minute): int
    {
        $targetDay = min($dayOfMonth, (int) $now->format('t'));
        $candidate = $now
            ->setDate((int) $now->format('Y'), (int) $now->format('n'), $targetDay)
            ->setTime($hour, $minute, 0);

        if ($now >= $candidate) {
            $nextMonth = $now->modify('first day of next month');
            $nextTarget = min($dayOfMonth, (int) $nextMonth->format('t'));
            $candidate = $nextMonth
                ->setDate((int) $nextMonth->format('Y'), (int) $nextMonth->format('n'), $nextTarget)
                ->setTime($hour, $minute, 0);
        }

        return $candidate->getTimestamp();
    }
}
