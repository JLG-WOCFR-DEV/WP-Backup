<?php
declare(strict_types=1);

use BJLG\BJLG_Settings;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-client-ip-helper.php';
require_once __DIR__ . '/../includes/class-bjlg-settings.php';

final class BJLG_SettingsDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['bjlg_test_options'] = [];
        $instance = new ReflectionProperty(BJLG\BJLG_Settings::class, 'instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $GLOBALS['bjlg_test_options'] = [];
        $instance = new ReflectionProperty(BJLG\BJLG_Settings::class, 'instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
        parent::tearDown();
    }

    public function test_merge_settings_with_defaults_adds_missing_nested_values(): void
    {
        $existing = [
            'enabled' => true,
            'channels' => [
                'email' => ['enabled' => true],
            ],
        ];

        $defaults = [
            'enabled' => false,
            'email_recipients' => '',
            'events' => [
                'backup_complete' => true,
            ],
            'channels' => [
                'email' => ['enabled' => false],
                'slack' => ['enabled' => false, 'webhook_url' => ''],
                'discord' => ['enabled' => false, 'webhook_url' => ''],
                'teams' => ['enabled' => false, 'webhook_url' => ''],
                'sms' => ['enabled' => false, 'webhook_url' => ''],
            ],
        ];

        $merged = BJLG_Settings::merge_settings_with_defaults($existing, $defaults);

        $this->assertTrue($merged['enabled']);
        $this->assertSame('', $merged['email_recipients']);
        $this->assertSame(['backup_complete' => true], $merged['events']);
        $this->assertArrayHasKey('slack', $merged['channels']);
        $this->assertSame(
            ['enabled' => false, 'webhook_url' => ''],
            $merged['channels']['slack']
        );
        $this->assertArrayHasKey('teams', $merged['channels']);
        $this->assertSame(
            ['enabled' => false, 'webhook_url' => ''],
            $merged['channels']['teams']
        );
        $this->assertArrayHasKey('sms', $merged['channels']);
        $this->assertSame(
            ['enabled' => false, 'webhook_url' => ''],
            $merged['channels']['sms']
        );
    }

    public function test_init_default_settings_populates_wasabi_defaults(): void
    {
        $settings = new BJLG_Settings();
        $settings->init_default_settings();

        $wasabi = get_option('bjlg_wasabi_settings');
        $this->assertIsArray($wasabi);
        $this->assertArrayHasKey('bucket', $wasabi);
        $this->assertSame('', $wasabi['bucket']);
    }

    public function test_init_default_settings_preserves_existing_values(): void
    {
        update_option('bjlg_notification_settings', [
            'enabled' => true,
            'channels' => [
                'email' => ['enabled' => true],
            ],
        ]);

        $settings = new BJLG_Settings();
        $settings->init_default_settings();

        $stored = get_option('bjlg_notification_settings');
        $this->assertTrue($stored['enabled']);
        $this->assertSame(
            ['enabled' => true],
            $stored['channels']['email']
        );
        $this->assertArrayHasKey('slack', $stored['channels']);
        $this->assertSame(
            ['enabled' => false, 'webhook_url' => ''],
            $stored['channels']['slack']
        );
        $this->assertArrayHasKey('teams', $stored['channels']);
        $this->assertSame(
            ['enabled' => false, 'webhook_url' => ''],
            $stored['channels']['teams']
        );
        $this->assertArrayHasKey('sms', $stored['channels']);
        $this->assertSame(
            ['enabled' => false, 'webhook_url' => ''],
            $stored['channels']['sms']
        );
    }
}
