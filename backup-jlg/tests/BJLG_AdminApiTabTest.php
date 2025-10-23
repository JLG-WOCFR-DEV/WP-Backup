<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-api-keys.php';
require_once __DIR__ . '/../includes/class-bjlg-admin.php';
require_once __DIR__ . '/../includes/class-bjlg-notification-receipts.php';

if (!defined('BJLG_VERSION')) {
    define('BJLG_VERSION', 'test-version');
}

if (!function_exists('get_admin_page_title')) {
    function get_admin_page_title() {
        return 'Backup - JLG';
    }
}

final class BJLG_AdminApiTabTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['bjlg_test_options'] = [];
    }

    public function test_render_api_section_outputs_expected_markup(): void
    {
        $timestamp = time();

        $GLOBALS['bjlg_test_options'][BJLG\BJLG_API_Keys::OPTION_NAME] = [
            'demo' => [
                'id' => 'demo',
                'label' => 'Mon intégration',
                'key' => wp_hash_password('SECRETXYZ'),
                'created_at' => $timestamp,
                'last_rotated_at' => $timestamp,
                'user_id' => 42,
                'user_login' => 'editor',
                'user_email' => 'editor@example.com',
            ],
        ];

        $admin = new BJLG\BJLG_Admin();

        $reflection = new ReflectionClass(BJLG\BJLG_Admin::class);
        $method = $reflection->getMethod('render_api_section');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($admin);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('API &amp; Intégrations', $output);
        $this->assertStringContainsString('bjlg-api-keys-table', $output);
        $this->assertStringContainsString('Mon intégration', $output);
        $this->assertStringContainsString('bjlg-api-key-value--hidden', $output);
        $this->assertStringContainsString('Clé masquée', $output);
        $this->assertStringContainsString('Secret masqué. Régénérez la clé pour obtenir un nouveau secret.', $output);
    }

    public function test_render_settings_section_displays_receipts_block(): void
    {
        BJLG\BJLG_Notification_Receipts::delete_all();
        $now = time();
        BJLG\BJLG_Notification_Receipts::record_creation([
            'id' => 'test-entry',
            'event' => 'backup_failed',
            'title' => 'Incident critique',
            'severity' => 'critical',
            'created_at' => $now,
            'channels' => [
                'email' => ['enabled' => true],
            ],
        ]);

        $admin = new BJLG\BJLG_Admin();
        $reflection = new ReflectionClass(BJLG\BJLG_Admin::class);
        $method = $reflection->getMethod('render_settings_section');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($admin);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('bjlg-notification-receipts', $output);
        $this->assertStringContainsString('Accusés de réception récents', $output);
        $this->assertStringContainsString('Incident critique', $output);
    }
}
