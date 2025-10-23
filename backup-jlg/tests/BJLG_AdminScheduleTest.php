<?php
declare(strict_types=1);

use BJLG\BJLG_Admin;
use BJLG\BJLG_Settings;
use PHPUnit\Framework\TestCase;

final class BJLG_AdminScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['bjlg_test_options'] = [];
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
}
