<?php
declare(strict_types=1);

use BJLG\BJLG_REST_API;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-rest-api.php';
require_once __DIR__ . '/../includes/class-bjlg-remote-storage-metrics.php';
require_once __DIR__ . '/../includes/class-bjlg-settings.php';
require_once __DIR__ . '/../includes/class-bjlg-restore.php';
require_once __DIR__ . '/../includes/class-bjlg-webhooks.php';

if (!function_exists('bjlg_with_site')) {
    function bjlg_with_site($site_id, callable $callback)
    {
        switch_to_blog((int) $site_id);

        try {
            return $callback();
        } finally {
            restore_current_blog();
        }
    }
}

if (!function_exists('bjlg_with_network')) {
    function bjlg_with_network(callable $callback)
    {
        return $callback();
    }
}

final class BJLG_RestMonitoringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['bjlg_test_options'] = [];
        $GLOBALS['bjlg_test_site_options'] = [];
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_registered_routes'] = [];
        $GLOBALS['bjlg_test_current_user_can'] = true;
        $GLOBALS['bjlg_test_is_multisite'] = false;
        $GLOBALS['bjlg_test_blog_switch_log'] = [];
        $GLOBALS['bjlg_test_blog_stack'] = [];
        $GLOBALS['bjlg_test_current_blog_id'] = 1;

        if (class_exists(BJLG\BJLG_Settings::class)) {
            $settings = new ReflectionProperty(BJLG\BJLG_Settings::class, 'instance');
            $settings->setAccessible(true);
            $settings->setValue(null, null);
        }
    }

    public function test_routes_are_registered(): void
    {
        $api = new BJLG_REST_API();
        $api->register_routes();

        $namespace = BJLG_REST_API::API_NAMESPACE;
        $this->assertArrayHasKey($namespace, $GLOBALS['bjlg_registered_routes']);
        $this->assertArrayHasKey('/monitoring/storage', $GLOBALS['bjlg_registered_routes'][$namespace]);
        $this->assertArrayHasKey('/monitoring/sla', $GLOBALS['bjlg_registered_routes'][$namespace]);
    }

    public function test_check_permissions_requires_capability(): void
    {
        $api = new BJLG_REST_API();
        $request = new BJLG_Test_Monitoring_Request();

        $GLOBALS['bjlg_test_current_user_can'] = false;
        $this->assertFalse($api->check_permissions($request));

        $GLOBALS['bjlg_test_current_user_can'] = true;
        $this->assertTrue($api->check_permissions($request));
    }

    public function test_monitoring_storage_returns_snapshot(): void
    {
        $api = new BJLG_REST_API();
        $snapshot = [
            'generated_at' => time(),
            'threshold_percent' => 85,
            'destinations' => [
                ['id' => 's3', 'connected' => true],
            ],
            'stale' => false,
        ];
        bjlg_update_option(BJLG\BJLG_Remote_Storage_Metrics::OPTION_KEY, $snapshot);

        $request = new BJLG_Test_Monitoring_Request();
        $response = $api->get_monitoring_storage($request);

        $this->assertIsArray($response);
        $this->assertSame($snapshot['destinations'][0]['id'], $response['snapshot']['destinations'][0]['id']);
        $this->assertSame(1, $response['site_id']);
    }

    public function test_monitoring_storage_accepts_multisite_site_id(): void
    {
        $api = new BJLG_REST_API();
        $GLOBALS['bjlg_test_is_multisite'] = true;
        $snapshot = [
            'generated_at' => time(),
            'destinations' => [
                ['id' => 'remote', 'connected' => true],
            ],
            'stale' => false,
        ];
        bjlg_update_option(BJLG\BJLG_Remote_Storage_Metrics::OPTION_KEY, $snapshot);

        $request = new BJLG_Test_Monitoring_Request(['site_id' => 7]);
        $response = $api->get_monitoring_storage($request);

        $this->assertIsArray($response);
        $this->assertSame(7, $response['site_id']);
        $this->assertSame('remote', $response['snapshot']['destinations'][0]['id']);
        $this->assertContains(7, $GLOBALS['bjlg_test_blog_switch_log']);
    }

    public function test_monitoring_storage_rejects_site_id_when_not_multisite(): void
    {
        $api = new BJLG_REST_API();
        $request = new BJLG_Test_Monitoring_Request(['site_id' => 3]);

        $result = $api->get_monitoring_storage($request);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('bjlg_multisite_required', $result->get_error_code());
    }

    public function test_monitoring_sla_returns_latest_report(): void
    {
        $api = new BJLG_REST_API();
        $registry = [
            'reports' => [
                [
                    'id' => 'rep-1',
                    'status' => 'failure',
                    'created_at' => time() - 120,
                    'message' => 'Older report',
                ],
                [
                    'id' => 'rep-2',
                    'status' => 'success',
                    'created_at' => time(),
                    'message' => 'Most recent',
                ],
            ],
        ];
        bjlg_update_option('bjlg_sandbox_validation_reports', $registry);

        $response = $api->get_monitoring_sla(new BJLG_Test_Monitoring_Request());

        $this->assertIsArray($response);
        $this->assertTrue($response['available']);
        $this->assertSame('rep-2', $response['report']['id']);
    }
}

final class BJLG_Test_Monitoring_Request
{
    /** @var array<string,mixed> */
    private $params;

    /** @var array<string,string> */
    private $headers;

    /**
     * @param array<string,mixed> $params
     * @param array<string,string> $headers
     */
    public function __construct(array $params = [], array $headers = [])
    {
        $this->params = $params;
        $this->headers = [];

        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
    }

    public function get_param($key)
    {
        return $this->params[$key] ?? null;
    }

    public function set_param($key, $value): void
    {
        $this->params[$key] = $value;
    }

    public function get_params(): array
    {
        return $this->params;
    }

    public function get_json_params(): array
    {
        return $this->params;
    }

    public function get_header($name)
    {
        $key = strtolower((string) $name);

        return $this->headers[$key] ?? '';
    }
}
