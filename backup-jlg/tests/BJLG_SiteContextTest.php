<?php
declare(strict_types=1);

namespace BJLG\Tests;

use BJLG\BJLG_Site_Context;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

require_once __DIR__ . '/../backup-jlg.php';
require_once __DIR__ . '/../includes/class-bjlg-site-context.php';

final class BJLG_SiteContextTest extends TestCase
{
    /** @var array<string, mixed> */
    private $previousHooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSiteContext();
        $this->previousHooks = $GLOBALS['bjlg_test_hooks'] ?? ['actions' => [], 'filters' => []];
        $GLOBALS['bjlg_test_hooks'] = ['actions' => [], 'filters' => []];
        $GLOBALS['bjlg_test_options'] = [];
        $GLOBALS['bjlg_test_site_options'] = [];
        $GLOBALS['bjlg_test_is_multisite'] = false;
        $GLOBALS['bjlg_test_is_network_admin'] = false;
        $GLOBALS['bjlg_test_current_blog_id'] = 1;
        $GLOBALS['bjlg_test_blog_stack'] = [];
        $GLOBALS['bjlg_test_blog_switch_log'] = [];
    }

    protected function tearDown(): void
    {
        $this->resetSiteContext();
        $GLOBALS['bjlg_test_hooks'] = $this->previousHooks;
        parent::tearDown();
    }

    private function resetSiteContext(): void
    {
        if (!class_exists(BJLG_Site_Context::class)) {
            return;
        }

        $reflection = new ReflectionProperty(BJLG_Site_Context::class, 'network_stack');
        $reflection->setAccessible(true);
        $reflection->setValue(null, 0);
    }

    public function test_get_option_returns_site_value_when_not_multisite(): void
    {
        bjlg_update_option('bjlg_monitoring_settings', ['site' => true]);

        $value = bjlg_get_option('bjlg_monitoring_settings');

        $this->assertSame(['site' => true], $value);
        $this->assertArrayHasKey('bjlg_monitoring_settings', $GLOBALS['bjlg_test_options']);
        $this->assertArrayNotHasKey('bjlg_monitoring_settings', $GLOBALS['bjlg_test_site_options']);
    }

    public function test_with_network_uses_network_option_storage(): void
    {
        $GLOBALS['bjlg_test_is_multisite'] = true;
        BJLG_Site_Context::bootstrap();

        bjlg_update_option('bjlg_monitoring_settings', ['site' => 'value']);
        $siteValue = bjlg_get_option('bjlg_monitoring_settings');

        bjlg_with_network(function () {
            bjlg_update_option('bjlg_monitoring_settings', ['network' => 'value']);
        });

        $networkValue = bjlg_with_network(function () {
            return bjlg_get_option('bjlg_monitoring_settings');
        });

        $this->assertSame(['site' => 'value'], $siteValue);
        $this->assertSame(['network' => 'value'], $networkValue);
        $this->assertSame(['site' => 'value'], bjlg_get_option('bjlg_monitoring_settings'));
        $this->assertArrayHasKey('bjlg_monitoring_settings', $GLOBALS['bjlg_test_site_options']);
    }

    public function test_with_site_switches_blog_context_temporarily(): void
    {
        $GLOBALS['bjlg_test_is_multisite'] = true;
        $GLOBALS['bjlg_test_current_blog_id'] = 2;

        $result = bjlg_with_site(7, function () {
            return get_current_blog_id();
        });

        $this->assertSame(7, $result);
        $this->assertSame(2, get_current_blog_id());
        $this->assertSame([7], $GLOBALS['bjlg_test_blog_switch_log']);
    }
}
