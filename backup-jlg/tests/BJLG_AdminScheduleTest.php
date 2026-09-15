<?php
declare(strict_types=1);

use BJLG\BJLG_Admin;
use BJLG\BJLG_Scheduler;
use BJLG\BJLG_Settings;
use PHPUnit\Framework\TestCase;

final class BJLG_AdminScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['bjlg_test_options'] = [];
        $GLOBALS['bjlg_test_scheduled_events'] = [
            'recurring' => [],
            'single' => [],
        ];
        $_GET = [];
        $_POST = [];

        $injected = new \ReflectionProperty(BJLG_Admin::class, 'schedule_data_injected');
        $injected->setAccessible(true);
        $injected->setValue(null, false);
    }

    public function test_render_settings_section_outputs_day_of_month_field(): void
    {
        $collection = BJLG_Settings::sanitize_schedule_collection([
            'schedules' => [
                [
                    'id' => 'bjlg_schedule_example',
                    'label' => 'Mensuelle',
                    'recurrence' => 'monthly',
                    'day' => 'monday',
                    'day_of_month' => 12,
                    'time' => '08:15',
                    'components' => ['db'],
                    'encrypt' => false,
                    'incremental' => false,
                    'include_patterns' => [],
                    'exclude_patterns' => [],
                    'post_checks' => ['checksum' => true, 'dry_run' => false],
                    'secondary_destinations' => [],
                ],
            ],
        ]);

        $GLOBALS['bjlg_test_options']['bjlg_schedule_settings'] = $collection;

        $reflection = new \ReflectionClass(BJLG_Admin::class);
        $admin = $reflection->newInstanceWithoutConstructor();

        $destinationsProperty = $reflection->getProperty('destinations');
        $destinationsProperty->setAccessible(true);
        $destinationsProperty->setValue($admin, []);

        $advancedProperty = $reflection->getProperty('advanced_admin');
        $advancedProperty->setAccessible(true);
        $advancedProperty->setValue($admin, null);

        $method = $reflection->getMethod('render_settings_section');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($admin);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('data-field="day_of_month"', $html);
        $this->assertStringContainsString('name="schedules[bjlg_schedule_example][day_of_month]"', $html);
        $this->assertStringContainsString('value="12"', $html);
        $this->assertStringContainsString('&quot;day_of_month&quot;:12', $html);
        $this->assertStringContainsString('&quot;day_of_month&quot;:1', $html);
        $this->assertStringContainsString('data-cron-suggestions', $html);
        $this->assertStringContainsString('data-cron-risk', $html);
    }

    public function test_render_schedule_section_completes_without_fatal(): void
    {
        $admin = $this->createAdminWithoutConstructor();
        $reflection = new \ReflectionClass(BJLG_Admin::class);
        $method = $reflection->getMethod('render_schedule_section');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($admin);
        $html = (string) ob_get_clean();

        $this->assertNotSame('', $html);
        $this->assertStringContainsString('bjlg-schedule-section', $html);
        $this->assertStringContainsString('bjlg-sandbox-schedule', $html);
        $this->assertSame('bjlg_run_restore_check', BJLG_Scheduler::RESTORE_CHECK_HOOK);
    }

    public function test_sanitize_restore_check_settings_uses_public_settings_api(): void
    {
        $settings = BJLG_Scheduler::sanitize_restore_check_settings([
            'enabled' => '1',
            'recurrence' => 'daily',
            'time' => '03:15',
            'components' => ['database', 'uploads'],
        ]);

        $this->assertTrue($settings['enabled']);
        $this->assertSame('daily', $settings['recurrence']);
        $this->assertSame('03:15', $settings['time']);
        $this->assertContains('db', $settings['components']);
        $this->assertContains('uploads', $settings['components']);
        $this->assertTrue((new \ReflectionMethod(BJLG_Settings::class, 'sanitize_backup_components'))->isPublic());
        $this->assertTrue((new \ReflectionMethod(BJLG_Scheduler::class, 'save_sandbox_schedule_settings'))->isPublic());
    }

    public function test_scheduler_settings_admin_do_not_call_private_members_across_classes(): void
    {
        $classes = [
            'BJLG_Scheduler' => BJLG_Scheduler::class,
            'BJLG_Settings' => BJLG_Settings::class,
            'BJLG_Admin' => BJLG_Admin::class,
        ];

        $violations = [];

        foreach ($classes as $callerShort => $callerFqcn) {
            $callerFile = (new \ReflectionClass($callerFqcn))->getFileName();
            $code = (string) file_get_contents($callerFile);

            foreach ($classes as $targetShort => $targetFqcn) {
                if ($callerShort === $targetShort) {
                    continue;
                }

                $target = new \ReflectionClass($targetFqcn);
                $ctor = $target->getConstructor();
                if ($ctor && $ctor->isPrivate() && preg_match('/new\s+(?:\\\\?BJLG\\\\)?' . preg_quote($targetShort, '/') . '\s*\(/', $code)) {
                    $violations[] = $callerShort . ' instantiates private constructor of ' . $targetShort;
                }

                if (preg_match_all('/(?:\\\\?BJLG\\\\)?' . preg_quote($targetShort, '/') . '::([A-Za-z_][A-Za-z0-9_]*)/', $code, $matches)) {
                    foreach (array_unique($matches[1]) as $member) {
                        if ($member === 'class') {
                            continue;
                        }
                        if ($target->hasMethod($member)) {
                            $method = $target->getMethod($member);
                            if (!$method->isPublic()) {
                                $violations[] = $callerShort . ' calls non-public ' . $targetShort . '::' . $member . '()';
                            }
                            continue;
                        }

                        if ($target->hasConstant($member)) {
                            $constant = $target->getReflectionConstant($member);
                            if ($constant && !$constant->isPublic()) {
                                $violations[] = $callerShort . ' reads non-public ' . $targetShort . '::' . $member;
                            }
                            continue;
                        }

                        $violations[] = $callerShort . ' references missing ' . $targetShort . '::' . $member;
                    }
                }
            }
        }

        $adminCode = (string) file_get_contents((new \ReflectionClass(BJLG_Admin::class))->getFileName());
        $scheduler = new \ReflectionClass(BJLG_Scheduler::class);
        if (preg_match_all('/\$scheduler->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $adminCode, $instanceCalls)) {
            foreach (array_unique($instanceCalls[1]) as $methodName) {
                if (!$scheduler->hasMethod($methodName)) {
                    $guard = 'method_exists($scheduler, \'' . $methodName . '\')';
                    $guardAlt = 'method_exists($scheduler, "' . $methodName . '")';
                    if (strpos($adminCode, $guard) === false && strpos($adminCode, $guardAlt) === false) {
                        $violations[] = 'BJLG_Admin calls missing BJLG_Scheduler::' . $methodName . '()';
                    }
                    continue;
                }

                $method = $scheduler->getMethod($methodName);
                if (!$method->isPublic()) {
                    $violations[] = 'BJLG_Admin calls non-public BJLG_Scheduler::' . $methodName . '()';
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    private function createAdminWithoutConstructor(): BJLG_Admin
    {
        $reflection = new \ReflectionClass(BJLG_Admin::class);
        /** @var BJLG_Admin $admin */
        $admin = $reflection->newInstanceWithoutConstructor();

        $destinationsProperty = $reflection->getProperty('destinations');
        $destinationsProperty->setAccessible(true);
        $destinationsProperty->setValue($admin, []);

        $advancedProperty = $reflection->getProperty('advanced_admin');
        $advancedProperty->setAccessible(true);
        $advancedProperty->setValue($admin, null);

        return $admin;
    }
}
