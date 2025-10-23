<?php

use BJLG\BJLG_API_Keys;
use BJLG\BJLG_History;
use BJLG\BJLG_Plugin;
use BJLG\BJLG_REST_API;
use BJLG\BJLG_Settings;
use BJLG\BJLG_Site_Context;
use PHPUnit\Framework\TestCase;

final class BJLG_MultisiteTest extends TestCase {
    private $previous_options = [];
    private $previous_sites = [];
    private $previous_stack = [];
    private $previous_multisite = false;
    private $previous_network_admin = false;
    private $previous_site_options = [];
    private $previous_is_multisite_flag = false;
    private $previous_is_network_admin_flag = false;
    private $previous_dbdelta_calls = [];

    protected function setUp(): void {
        parent::setUp();
        $this->previous_options = $GLOBALS['bjlg_test_options'] ?? [];
        $this->previous_sites = $GLOBALS['bjlg_tests_sites'] ?? [];
        $this->previous_stack = $GLOBALS['bjlg_tests_blog_stack'] ?? [1];
        $this->previous_multisite = $GLOBALS['bjlg_tests_multisite'] ?? false;
        $this->previous_network_admin = $GLOBALS['bjlg_tests_network_admin'] ?? false;
        $this->previous_site_options = $GLOBALS['bjlg_test_site_options'] ?? [];
        $this->previous_is_multisite_flag = $GLOBALS['bjlg_test_is_multisite'] ?? false;
        $this->previous_is_network_admin_flag = $GLOBALS['bjlg_test_is_network_admin'] ?? false;
        $this->previous_dbdelta_calls = $GLOBALS['bjlg_test_dbdelta_calls'] ?? [];

        $GLOBALS['bjlg_test_options'] = [];
        $GLOBALS['bjlg_tests_multisite'] = false;
        $GLOBALS['bjlg_tests_network_admin'] = false;
        $GLOBALS['bjlg_tests_blog_stack'] = [1];
        $GLOBALS['bjlg_tests_sites'] = [
            1 => (object) [
                'blog_id' => 1,
                'domain' => 'example.test',
                'path' => '/',
            ],
            2 => (object) [
                'blog_id' => 2,
                'domain' => 'network.test',
                'path' => '/',
            ],
        ];
        $GLOBALS['bjlg_test_current_user_can'] = false;
    }

    protected function tearDown(): void {
        $GLOBALS['bjlg_test_options'] = $this->previous_options;
        $GLOBALS['bjlg_tests_sites'] = $this->previous_sites ?: [
            1 => (object) [
                'blog_id' => 1,
                'domain' => 'example.test',
                'path' => '/',
            ],
        ];
        $GLOBALS['bjlg_tests_blog_stack'] = $this->previous_stack ?: [1];
        $GLOBALS['bjlg_tests_multisite'] = $this->previous_multisite;
        $GLOBALS['bjlg_tests_network_admin'] = $this->previous_network_admin;
        $GLOBALS['bjlg_test_site_options'] = $this->previous_site_options;
        $GLOBALS['bjlg_test_is_multisite'] = $this->previous_is_multisite_flag;
        $GLOBALS['bjlg_test_is_network_admin'] = $this->previous_is_network_admin_flag;
        $GLOBALS['bjlg_test_dbdelta_calls'] = $this->previous_dbdelta_calls;
        $GLOBALS['bjlg_test_current_user_can'] = false;

        parent::tearDown();
    }

    public function test_site_context_scopes_options() {
        $GLOBALS['bjlg_tests_multisite'] = true;

        bjlg_update_option('bjlg_settings', ['mode' => 'network'], null, true);
        bjlg_update_option('bjlg_settings', ['mode' => 'site-1']);
        bjlg_update_option('bjlg_settings', ['mode' => 'site-2'], 2);

        $this->assertSame('network', bjlg_get_option('bjlg_settings', [], null, true)['mode']);
        $this->assertSame('site-1', bjlg_get_option('bjlg_settings', [], 1)['mode']);
        $this->assertSame('site-2', bjlg_get_option('bjlg_settings', [], 2)['mode']);
    }

    public function test_handle_save_settings_switches_blog_context() {
        $GLOBALS['bjlg_tests_multisite'] = true;
        $GLOBALS['bjlg_tests_network_admin'] = true;
        $GLOBALS['bjlg_test_current_user_can'] = true;

        $_POST = [
            'nonce' => wp_create_nonce('bjlg_nonce'),
            'site_id' => 2,
            'by_number' => 7,
        ];

        $settings = BJLG_Settings::get_instance();

        try {
            $settings->handle_save_settings();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $data = $response->data;
            $this->assertIsArray($data['saved']);
            $this->assertSame(7, bjlg_get_option('bjlg_cleanup_settings', [], 2)['by_number']);
            $this->assertSame(1, get_current_blog_id());
        }

        $_POST = [];
    }

    public function test_rest_request_switches_and_restores_site() {
        $GLOBALS['bjlg_tests_multisite'] = true;
        $GLOBALS['bjlg_tests_network_admin'] = true;
        $GLOBALS['bjlg_test_current_user_can'] = true;

        $api = new BJLG_REST_API();
        $request = new class implements ArrayAccess {
            private $params = [
                'site_id' => 2,
            ];

            public function offsetExists($offset): bool {
                return isset($this->params[$offset]);
            }

            public function offsetGet($offset) {
                return $this->params[$offset] ?? null;
            }

            public function offsetSet($offset, $value): void {
                $this->params[$offset] = $value;
            }

            public function offsetUnset($offset): void {
                unset($this->params[$offset]);
            }

            public function get_param($key) {
                return $this->params[$key] ?? null;
            }

            public function get_route() {
                return '/backup-jlg/v1/backups';
            }
        };

        $result = $api->maybe_switch_site_for_request(null, null, $request);
        $this->assertNotInstanceOf(WP_Error::class, $result);
        $this->assertSame(2, get_current_blog_id());

        $api->restore_site_after_request(null, null, $request);
        $this->assertSame(1, get_current_blog_id());
    }

    public function test_activation_creates_network_history_table_when_scope_network(): void {
        require_once __DIR__ . '/../backup-jlg.php';

        $GLOBALS['bjlg_test_dbdelta_calls'] = [];
        $GLOBALS['bjlg_test_is_multisite'] = true;
        $GLOBALS['bjlg_tests_multisite'] = true;
        $GLOBALS['bjlg_tests_network_admin'] = true;
        $GLOBALS['bjlg_test_is_network_admin'] = true;
        $GLOBALS['bjlg_tests_sites'] = [
            1 => (object) [
                'blog_id' => 1,
                'domain' => 'example.test',
                'path' => '/',
            ],
            2 => (object) [
                'blog_id' => 2,
                'domain' => 'network.test',
                'path' => '/',
            ],
        ];

        $GLOBALS['wpdb']->prefix = 'wp_1_';
        $GLOBALS['wpdb']->base_prefix = 'wp_';

        BJLG_Site_Context::set_history_scope(BJLG_Site_Context::HISTORY_SCOPE_NETWORK);

        $plugin = new BJLG_Plugin();
        $plugin->activate();

        $network_calls = array_filter($GLOBALS['bjlg_test_dbdelta_calls'], static function ($sql) {
            return strpos($sql, 'CREATE TABLE wp_bjlg_history') !== false;
        });

        $this->assertNotEmpty($network_calls, 'Expected a network history table to be created.');
    }

    public function test_api_keys_prefer_network_storage_when_history_scope_network(): void {
        $GLOBALS['bjlg_test_is_multisite'] = true;
        $GLOBALS['bjlg_tests_multisite'] = true;

        BJLG_Site_Context::set_history_scope(BJLG_Site_Context::HISTORY_SCOPE_NETWORK);

        $now = time();
        update_site_option(
            BJLG_API_Keys::OPTION_NAME,
            [
                [
                    'id' => 'network-key',
                    'label' => 'Network Key',
                    'display_secret' => 'net-secret',
                    'key' => wp_hash_password('net-secret'),
                    'created_at' => $now,
                    'last_rotated_at' => $now,
                ],
            ]
        );

        update_option(
            BJLG_API_Keys::OPTION_NAME,
            [
                [
                    'id' => 'site-key',
                    'label' => 'Site Key',
                    'display_secret' => 'site-secret',
                    'key' => wp_hash_password('site-secret'),
                    'created_at' => $now,
                    'last_rotated_at' => $now,
                ],
            ]
        );

        $keys = BJLG_API_Keys::get_keys();
        $identifiers = array_map(static function ($record) {
            return isset($record['id']) ? (string) $record['id'] : '';
        }, $keys);

        $this->assertContains('network-key', $identifiers);
        $this->assertNotContains('site-key', $identifiers);
    }
}
