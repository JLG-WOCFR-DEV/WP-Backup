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

        $wasabi = bjlg_get_option('bjlg_wasabi_settings');
        $this->assertIsArray($wasabi);
        $this->assertArrayHasKey('bucket', $wasabi);
        $this->assertSame('', $wasabi['bucket']);
    }

    public function test_init_default_settings_preserves_existing_values(): void
    {
        bjlg_update_option('bjlg_notification_settings', [
            'enabled' => true,
            'channels' => [
                'email' => ['enabled' => true],
            ],
        ]);

        $settings = new BJLG_Settings();
        $settings->init_default_settings();

        $stored = bjlg_get_option('bjlg_notification_settings');
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

    public function test_init_default_settings_adds_update_guard_defaults(): void
    {
        $settings = new BJLG_Settings();
        $settings->init_default_settings();

        $stored = bjlg_get_option('bjlg_update_guard_settings');
        $this->assertIsArray($stored);
        $this->assertArrayHasKey('enabled', $stored);
        $this->assertTrue($stored['enabled']);
        $this->assertArrayHasKey('mode', $stored);
        $this->assertSame('full', $stored['mode']);
        $this->assertArrayHasKey('components', $stored);
        $this->assertSame(['db', 'plugins', 'themes', 'uploads'], $stored['components']);
        $this->assertArrayHasKey('targets', $stored);
        $this->assertSame([
            'core' => true,
            'plugin' => true,
            'theme' => true,
        ], $stored['targets']);
        $this->assertArrayHasKey('reminder', $stored);
        $this->assertFalse($stored['reminder']['enabled']);
        $this->assertNotSame('', $stored['reminder']['message']);
        $this->assertSame(0, $stored['reminder']['delay_minutes']);
        $this->assertArrayHasKey('channels', $stored['reminder']);
        $this->assertSame([
            'notification' => ['enabled' => false],
            'email' => ['enabled' => false, 'recipients' => ''],
        ], $stored['reminder']['channels']);
    }

    public function test_register_settings_declares_plugin_options_without_cloud_credentials(): void
    {
        $GLOBALS['bjlg_test_registered_settings'] = [];
        $GLOBALS['bjlg_test_settings_sections'] = [];

        $settings = new BJLG_Settings();
        $settings->register_settings();

        $registered = $GLOBALS['bjlg_test_registered_settings'][BJLG_Settings::SETTINGS_GROUP] ?? [];
        $option_names = BJLG_Settings::get_settings_api_options();

        $this->assertNotEmpty($option_names);
        foreach ($option_names as $option_name) {
            $this->assertArrayHasKey($option_name, $registered);
            $this->assertFalse($registered[$option_name]['show_in_rest']);
            $this->assertSame('array', $registered[$option_name]['type']);
            $this->assertIsCallable($registered[$option_name]['sanitize_callback']);
        }

        $this->assertContains('bjlg_cleanup_settings', $option_names);
        $this->assertContains('bjlg_sandbox_automation_settings', $option_names);
        $this->assertNotContains('bjlg_s3_settings', $option_names);
        $this->assertNotContains('bjlg_gdrive_settings', $option_names);
        $this->assertNotContains('bjlg_schedule_settings', $option_names);

        $sections = $GLOBALS['bjlg_test_settings_sections'][BJLG_Settings::SETTINGS_GROUP] ?? [];
        $this->assertArrayHasKey('bjlg_plugin_settings_main', $sections);
    }

    public function test_settings_fields_helper_outputs_settings_api_markers(): void
    {
        ob_start();
        BJLG_Settings::render_settings_fields();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('name="option_page"', $html);
        $this->assertStringContainsString('bjlg_plugin_settings', $html);
        $this->assertStringContainsString('name="action"', $html);
        $this->assertStringContainsString('value="update"', $html);
    }

    public function test_sanitize_registered_option_keeps_cleanup_bounds(): void
    {
        $settings = new BJLG_Settings();
        $sanitized = $settings->sanitize_registered_option('bjlg_cleanup_settings', [
            'by_number' => -4,
            'by_age' => 12,
        ]);

        $this->assertSame(0, $sanitized['by_number']);
        $this->assertSame(12, $sanitized['by_age']);
    }

    public function test_sanitize_registered_option_normalizes_sandbox_automation(): void
    {
        $settings = new BJLG_Settings();
        $sanitized = $settings->sanitize_registered_option('bjlg_sandbox_automation_settings', [
            'enabled' => '1',
            'recurrence' => 'daily',
            'sandbox_path' => '',
        ]);

        $this->assertTrue($sanitized['enabled']);
        $this->assertSame('daily', $sanitized['recurrence']);
    }

    public function test_performance_save_does_not_disable_encryption_silently(): void
    {
        bjlg_update_option('bjlg_encryption_settings', [
            'enabled' => true,
            'auto_encrypt' => true,
            'password_protect' => false,
            'compression_level' => 4,
        ]);

        $_POST = [
            'nonce' => 'test-nonce',
            'multi_threading' => '1',
            'max_workers' => '3',
            'chunk_size' => '40',
            'compression_level' => '8',
        ];

        $settings = new BJLG_Settings();

        try {
            $settings->handle_save_settings();
            $this->fail('Expected JSON response.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
        } finally {
            $_POST = [];
        }

        $encryption = bjlg_get_option('bjlg_encryption_settings');
        $this->assertTrue($encryption['enabled']);
        $this->assertTrue($encryption['auto_encrypt']);
        $this->assertSame(4, (int) $encryption['compression_level']);

        $performance = bjlg_get_option('bjlg_performance_settings');
        $this->assertSame(8, (int) $performance['compression_level']);
        $this->assertSame(3, (int) $performance['max_workers']);
    }

    public function test_encryption_form_save_uses_dedicated_compression_field(): void
    {
        $_POST = [
            'nonce' => 'test-nonce',
            'encryption_settings_submitted' => '1',
            'encryption_enabled' => '1',
            'auto_encrypt' => '1',
            'encryption_compression_level' => '7',
        ];

        $settings = new BJLG_Settings();

        try {
            $settings->handle_save_settings();
            $this->fail('Expected JSON response.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
        } finally {
            $_POST = [];
        }

        $encryption = bjlg_get_option('bjlg_encryption_settings');
        $this->assertTrue($encryption['enabled']);
        $this->assertTrue($encryption['auto_encrypt']);
        $this->assertSame(7, (int) $encryption['compression_level']);
    }

    public function test_native_settings_post_without_ajax_surfaces_visible_error(): void
    {
        $GLOBALS['bjlg_test_settings_errors'] = [];
        $GLOBALS['bjlg_test_doing_ajax'] = false;
        $GLOBALS['pagenow'] = 'admin.php';
        $_POST = [
            'option_page' => BJLG_Settings::SETTINGS_GROUP,
            'action' => 'update',
        ];

        $settings = new BJLG_Settings();
        $settings->flag_settings_posted_without_ajax();

        $codes = array_column($GLOBALS['bjlg_test_settings_errors'] ?? [], 'code');
        $this->assertContains('bjlg_settings_js_required', $codes);

        ob_start();
        BJLG_Settings::render_settings_notices();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('JavaScript', $html);

        $_POST = [];
        $GLOBALS['bjlg_test_settings_errors'] = [];
    }

    public function test_native_settings_post_is_ignored_during_ajax(): void
    {
        $GLOBALS['bjlg_test_settings_errors'] = [];
        $GLOBALS['bjlg_test_doing_ajax'] = true;
        $_POST = [
            'option_page' => BJLG_Settings::SETTINGS_GROUP,
            'action' => 'bjlg_save_settings',
        ];

        $settings = new BJLG_Settings();
        $settings->flag_settings_posted_without_ajax();

        $this->assertSame([], $GLOBALS['bjlg_test_settings_errors'] ?? []);

        $_POST = [];
        $GLOBALS['bjlg_test_doing_ajax'] = false;
    }
}
