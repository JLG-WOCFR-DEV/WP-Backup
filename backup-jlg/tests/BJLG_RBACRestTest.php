<?php
declare(strict_types=1);

use BJLG\BJLG_REST_API;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-rest-api.php';
require_once __DIR__ . '/../includes/class-bjlg-admin.php';

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string,mixed> */
        private $params = [];

        public function __construct($method = 'GET', $route = '')
        {
            $this->params = [];
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }

        public function set_param($key, $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_json_params(): array
        {
            return $this->params;
        }

        public function get_params(): array
        {
            return $this->params;
        }

        public function get_header($name)
        {
            return '';
        }
    }
}

final class BJLG_RBACRestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['bjlg_test_options'] = [];
        $GLOBALS['bjlg_registered_routes'] = [];
        $GLOBALS['bjlg_test_current_user_can'] = true;
        $GLOBALS['bjlg_test_is_multisite'] = false;
        $GLOBALS['bjlg_test_is_network_admin'] = false;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['bjlg_test_is_multisite'], $GLOBALS['bjlg_test_is_network_admin']);
        parent::tearDown();
    }

    public function test_rbac_route_is_registered(): void
    {
        $api = new BJLG_REST_API();
        $api->register_routes();

        $namespace = BJLG_REST_API::API_NAMESPACE;
        $this->assertArrayHasKey($namespace, $GLOBALS['bjlg_registered_routes']);
        $this->assertArrayHasKey('/rbac', $GLOBALS['bjlg_registered_routes'][$namespace]);
    }

    public function test_check_rbac_permissions_requires_capability(): void
    {
        $api = new BJLG_REST_API();
        $GLOBALS['bjlg_test_current_user_can'] = false;
        $request = new WP_REST_Request('GET', '/backup-jlg/v1/rbac');

        $this->assertFalse($api->check_rbac_permissions($request));

        $GLOBALS['bjlg_test_current_user_can'] = true;
        $this->assertTrue($api->check_rbac_permissions($request));
    }

    public function test_check_rbac_permissions_requires_network_capability(): void
    {
        $api = new BJLG_REST_API();
        $GLOBALS['bjlg_test_is_multisite'] = true;
        $GLOBALS['bjlg_test_current_user_can'] = false;

        $request = new WP_REST_Request('GET', '/backup-jlg/v1/rbac');
        $request->set_param('scope', 'network');

        $this->assertFalse($api->check_rbac_permissions($request));

        $GLOBALS['bjlg_test_current_user_can'] = true;
        $this->assertTrue($api->check_rbac_permissions($request));
    }

    public function test_update_rbac_settings_sanitizes_map(): void
    {
        $api = new BJLG_REST_API();
        $request = new WP_REST_Request('POST', '/backup-jlg/v1/rbac');
        $request->set_param('map', [
            'manage_plugin' => 'manage_options',
            'manage_backups' => 'backup_manager',
            'unknown' => 'should_be_ignored',
        ]);

        $response = $api->update_rbac_settings($request);
        $this->assertIsArray($response);
        $this->assertSame('manage_options', $response['map']['manage_plugin']);
        $this->assertSame('backup_manager', $response['map']['manage_backups']);
        $this->assertArrayNotHasKey('unknown', bjlg_get_option('bjlg_capability_map', []));
    }
}
