<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BJLG_NetworkEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['bjlg_test_is_multisite'] = true;
        $GLOBALS['bjlg_tests_sites'] = [
            1 => (object) [
                'blog_id' => 1,
                'blogname' => 'Site Alpha',
                'domain' => 'alpha.test',
                'path' => '/',
            ],
            2 => (object) [
                'blog_id' => 2,
                'blogname' => 'Site Beta',
                'domain' => 'beta.test',
                'path' => '/',
            ],
        ];

        update_site_option(\BJLG\BJLG_Site_Context::NETWORK_MODE_OPTION, \BJLG\BJLG_Site_Context::NETWORK_MODE_NETWORK);

        \BJLG\BJLG_History::$history_store = [
            0 => [
                [
                    'action_type' => 'backup_created',
                    'status' => 'success',
                    'details' => 'network',
                    'timestamp' => '2024-01-01 00:00:00',
                ],
            ],
            1 => [
                [
                    'action_type' => 'backup_created',
                    'status' => 'success',
                    'details' => 'site-1-success',
                    'timestamp' => '2024-01-02 00:00:00',
                ],
                [
                    'action_type' => 'backup_failed',
                    'status' => 'failure',
                    'details' => 'site-1-failure',
                    'timestamp' => '2024-01-03 00:00:00',
                ],
            ],
            2 => [
                [
                    'action_type' => 'backup_created',
                    'status' => 'success',
                    'details' => 'site-2-success',
                    'timestamp' => '2024-01-04 00:00:00',
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['bjlg_test_is_multisite'], $GLOBALS['bjlg_tests_sites']);
        \BJLG\BJLG_History::$history_store = [];
        update_site_option(\BJLG\BJLG_Site_Context::NETWORK_MODE_OPTION, \BJLG\BJLG_Site_Context::NETWORK_MODE_SITE);
    }

    public function test_network_history_endpoint_aggregates_entries(): void
    {
        $api = new \BJLG\BJLG_REST_API();
        $request = new BJLG_Test_Rest_Request(['limit' => 10]);
        $response = $api->get_network_history($request);

        $this->assertIsArray($response);
        $this->assertSame(1, $response['total']);
        $this->assertSame('network', $response['entries'][0]['details']);
    }

    public function test_network_sites_endpoint_lists_sites_with_counts(): void
    {
        $api = new \BJLG\BJLG_REST_API();
        $request = new BJLG_Test_Rest_Request();
        $response = $api->get_network_sites($request);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('sites', $response);
        $this->assertCount(2, $response['sites']);

        $sites = [];
        foreach ($response['sites'] as $site) {
            $sites[$site['id']] = $site;
        }

        $this->assertArrayHasKey(1, $sites);
        $this->assertSame(2, $sites[1]['history']['total_actions']);
        $this->assertArrayHasKey(2, $sites);
        $this->assertSame(1, $sites[2]['history']['total_actions']);
    }

    public function test_switch_to_blog_updates_backup_directory(): void
    {
        $dirSite1 = bjlg_get_backup_directory(1);
        $dirSite2 = bjlg_get_backup_directory(2);

        $this->assertNotSame($dirSite1, $dirSite2);

        switch_to_blog(2);
        $switchedDir = bjlg_get_backup_directory();
        restore_current_blog();

        $this->assertSame($dirSite2, $switchedDir);
    }

    public function test_network_history_endpoint_requires_network_mode(): void
    {
        update_site_option(\BJLG\BJLG_Site_Context::NETWORK_MODE_OPTION, \BJLG\BJLG_Site_Context::NETWORK_MODE_SITE);

        $api = new \BJLG\BJLG_REST_API();
        $request = new BJLG_Test_Rest_Request();
        $response = $api->get_network_history($request);

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('bjlg_network_mode_disabled', $response->get_error_code());
    }

    public function test_network_sites_endpoint_requires_network_mode(): void
    {
        update_site_option(\BJLG\BJLG_Site_Context::NETWORK_MODE_OPTION, \BJLG\BJLG_Site_Context::NETWORK_MODE_SITE);

        $api = new \BJLG\BJLG_REST_API();
        $request = new BJLG_Test_Rest_Request();
        $response = $api->get_network_sites($request);

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('bjlg_network_mode_disabled', $response->get_error_code());
    }
}

class BJLG_Test_Rest_Request
{
    /** @var array<string,mixed> */
    private $params;

    /**
     * @param array<string,mixed> $params
     */
    public function __construct(array $params = [])
    {
        $this->params = $params;
    }

    public function get_param($key)
    {
        return $this->params[$key] ?? null;
    }

    public function get_header($key)
    {
        return '';
    }
}
