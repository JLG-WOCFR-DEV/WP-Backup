<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-health-check.php';

final class BJLG_HealthCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['bjlg_test_disk_total_space_mock'], $GLOBALS['bjlg_test_disk_free_space_mock']);
    }

    public function test_check_disk_space_warns_when_total_space_missing(): void
    {
        $GLOBALS['bjlg_test_disk_total_space_mock'] = static function (string $directory) {
            return false;
        };
        $GLOBALS['bjlg_test_disk_free_space_mock'] = static function (string $directory) {
            return 1024;
        };

        $health_check = new BJLG\BJLG_Health_Check();

        $reflection = new ReflectionClass(BJLG\BJLG_Health_Check::class);
        $method = $reflection->getMethod('check_disk_space');
        $method->setAccessible(true);

        /** @var array{status: string, message: string} $result */
        $result = $method->invoke($health_check);

        $this->assertSame('warning', $result['status']);
        $this->assertStringContainsString("Impossible de déterminer l'espace disque total", $result['message']);
        $this->assertStringContainsString("L'utilisation du disque n'a pas pu être calculée", $result['message']);
    }

    public function test_check_disk_space_warns_when_total_space_non_positive(): void
    {
        $GLOBALS['bjlg_test_disk_total_space_mock'] = static function (string $directory) {
            return 0;
        };
        $GLOBALS['bjlg_test_disk_free_space_mock'] = static function (string $directory) {
            return 512;
        };

        $health_check = new BJLG\BJLG_Health_Check();

        $reflection = new ReflectionClass(BJLG\BJLG_Health_Check::class);
        $method = $reflection->getMethod('check_disk_space');
        $method->setAccessible(true);

        /** @var array{status: string, message: string} $result */
        $result = $method->invoke($health_check);

        $this->assertSame('warning', $result['status']);
        $this->assertStringContainsString("Impossible de déterminer l'espace disque total", $result['message']);
    }

    public function test_check_cron_status_sees_named_backup_schedules(): void
    {
        require_once __DIR__ . '/../includes/class-bjlg-settings.php';
        require_once __DIR__ . '/../includes/class-bjlg-scheduler.php';
        require_once __DIR__ . '/../includes/class-bjlg-cleanup.php';

        $GLOBALS['bjlg_test_scheduled_events'] = [
            'recurring' => [],
            'single' => [],
        ];

        bjlg_update_option('bjlg_schedule_settings', [
            'id' => 'health-sched',
            'label' => 'Nocturne',
            'recurrence' => 'daily',
            'components' => ['db'],
        ]);

        $scheduler = BJLG\BJLG_Scheduler::instance();
        $schedule_id = $scheduler->get_schedule_settings()['schedules'][0]['id'];
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', BJLG\BJLG_Scheduler::SCHEDULE_HOOK, [$schedule_id]);

        $health_check = new BJLG\BJLG_Health_Check();
        $method = (new ReflectionClass(BJLG\BJLG_Health_Check::class))->getMethod('check_cron_status');
        $method->setAccessible(true);

        /** @var array{status: string, message: string} $result */
        $result = $method->invoke($health_check);

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('Nocturne', $result['message']);
        $this->assertStringNotContainsString('Aucune tâche planifiée active', $result['message']);
    }

    public function test_check_cron_status_errors_when_enabled_schedule_has_no_event(): void
    {
        require_once __DIR__ . '/../includes/class-bjlg-settings.php';
        require_once __DIR__ . '/../includes/class-bjlg-scheduler.php';
        require_once __DIR__ . '/../includes/class-bjlg-cleanup.php';

        $GLOBALS['bjlg_test_scheduled_events'] = [
            'recurring' => [],
            'single' => [],
        ];

        bjlg_update_option('bjlg_schedule_settings', [
            'id' => 'orphan-sched',
            'label' => 'Orpheline',
            'recurrence' => 'daily',
            'components' => ['db'],
        ]);

        $health_check = new BJLG\BJLG_Health_Check();
        $method = (new ReflectionClass(BJLG\BJLG_Health_Check::class))->getMethod('check_cron_status');
        $method->setAccessible(true);

        /** @var array{status: string, message: string} $result */
        $result = $method->invoke($health_check);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('aucune tâche cron active', strtolower($result['message']));
    }
}
