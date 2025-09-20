<?php
declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../includes/class-bjlg-cleanup.php';
    require_once __DIR__ . '/../includes/class-bjlg-rest-api.php';

    if (!defined('AUTH_KEY')) {
        define('AUTH_KEY', 'test-auth-key');
    }

    final class BJLG_REST_APITest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['bjlg_test_options'] = [];
            $GLOBALS['bjlg_test_hooks'] = [
                'actions' => [],
                'filters' => [],
            ];
            $GLOBALS['bjlg_registered_routes'] = [];
            $GLOBALS['bjlg_test_users'] = [];
        }

        private function makeUser(int $id, string $login, ?array $caps = null, ?array $roles = null): object
        {
            $caps = $caps ?? [BJLG_CAPABILITY => true];
            $roles = $roles ?? ['administrator'];

            return (object) [
                'ID' => $id,
                'user_login' => $login,
                'user_email' => $login . '@example.com',
                'allcaps' => $caps,
                'roles' => $roles,
            ];
        }

        public function test_backups_route_per_page_validation_rejects_zero(): void
        {
            $api = new BJLG\BJLG_REST_API();
            $api->register_routes();

            $namespace = BJLG\BJLG_REST_API::API_NAMESPACE;
            $this->assertArrayHasKey($namespace, $GLOBALS['bjlg_registered_routes']);
            $this->assertArrayHasKey('/backups', $GLOBALS['bjlg_registered_routes'][$namespace]);

            $route = $GLOBALS['bjlg_registered_routes'][$namespace]['/backups'];
            $this->assertIsArray($route);

            $collection_endpoint = $route[0];
            $this->assertArrayHasKey('args', $collection_endpoint);
            $this->assertArrayHasKey('per_page', $collection_endpoint['args']);

            $validator = $collection_endpoint['args']['per_page']['validate_callback'];
            $this->assertIsCallable($validator);

            $result = $validator(0, null, null);
            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('rest_invalid_param', $result->get_error_code());

            $error_data = $result->get_error_data('rest_invalid_param');
            $this->assertIsArray($error_data);
            $this->assertSame(400, $error_data['status']);

            $this->assertTrue($validator(5, null, null));
        }

        public function test_get_backups_enforces_minimum_per_page(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $files = [];

            for ($i = 0; $i < 2; $i++) {
                $filename = BJLG_BACKUP_DIR . 'bjlg-test-' . uniqid('', true) . '.zip';
                file_put_contents($filename, 'backup');
                touch($filename, time() - $i);
                $files[] = $filename;
            }

            $request = new class {
                /** @var array<string, mixed> */
                private $params;

                public function __construct()
                {
                    $this->params = [
                        'page' => 1,
                        'per_page' => 0,
                        'type' => 'all',
                        'sort' => 'date_desc',
                    ];
                }

                public function get_param($key)
                {
                    return $this->params[$key] ?? null;
                }
            };

            $response = $api->get_backups($request);

            $this->assertIsArray($response);
            $this->assertArrayHasKey('pagination', $response);
            $this->assertSame(1, $response['pagination']['per_page']);

            foreach ($files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }

        public function test_verify_jwt_token_returns_error_for_invalid_signature(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $validToken = $this->generateJwtToken();
            $parts = explode('.', $validToken);

            $invalidSignature = $this->base64UrlEncode(
                hash_hmac('sha256', $parts[0] . '.' . $parts[1], AUTH_KEY . 'invalid', true)
            );

            if (hash_equals($parts[2], $invalidSignature)) {
                $invalidSignature = $this->base64UrlEncode(
                    hash_hmac('sha256', $parts[0] . '.' . $parts[1], AUTH_KEY . 'fallback', true)
                );
            }

            $parts[2] = $invalidSignature;
            $token = implode('.', $parts);

            $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
            $method = $reflection->getMethod('verify_jwt_token');
            $method->setAccessible(true);

            $result = $method->invoke($api, $token);

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('jwt_invalid_signature', $result->get_error_code());
        }

        public function test_verify_jwt_token_returns_error_when_user_missing(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $token = $this->generateJwtToken([
                'user_id' => 999,
                'username' => 'ghost-user',
            ]);

            $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
            $method = $reflection->getMethod('verify_jwt_token');
            $method->setAccessible(true);

            $result = $method->invoke($api, $token);

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('jwt_user_not_found', $result->get_error_code());
        }

        public function test_check_permissions_rejects_token_when_capability_revoked(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $user = (object) [
                'ID' => 42,
                'user_login' => 'revoked-user',
                'allcaps' => [
                    'read' => true,
                ],
            ];

            $GLOBALS['bjlg_test_users'] = [
                $user->ID => $user,
            ];

            $token = $this->generateJwtToken([
                'user_id' => $user->ID,
                'username' => $user->user_login,
            ]);

            $request = new class($token) {
                /** @var array<string, string> */
                private $headers;

                public function __construct(string $token)
                {
                    $this->headers = [
                        'Authorization' => 'Bearer ' . $token,
                    ];
                }

                public function get_header($key)
                {
                    return $this->headers[$key] ?? null;
                }
            };

            $result = $api->check_permissions($request);

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('jwt_insufficient_permissions', $result->get_error_code());
        }

        public function test_check_permissions_rejects_token_when_user_deleted(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $token = $this->generateJwtToken([
                'user_id' => 314,
                'username' => 'deleted-user',
            ]);

            $request = new class($token) {
                /** @var array<string, string> */
                private $headers;

                public function __construct(string $token)
                {
                    $this->headers = [
                        'Authorization' => 'Bearer ' . $token,
                    ];
                }

                public function get_header($key)
                {
                    return $this->headers[$key] ?? null;
                }
            };

            $result = $api->check_permissions($request);

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('jwt_user_not_found', $result->get_error_code());
        }

    public function test_verify_api_key_updates_usage_statistics(): void
    {
        $GLOBALS['bjlg_test_options'] = [];

        $api = new BJLG\BJLG_REST_API();

        $api_key = 'test-api-key';
        $hashed_key = wp_hash_password($api_key);
        $initial_usage_count = 2;
        $initial_last_used = time() - 3600;

        $user = $this->makeUser(101, 'api-user');
        $GLOBALS['bjlg_test_users'] = [
            $user->ID => $user,
        ];

        update_option('bjlg_api_keys', [[
            'key' => $hashed_key,
            'usage_count' => $initial_usage_count,
            'last_used' => $initial_last_used,
            'user_id' => $user->ID,
            'roles' => ['administrator'],
        ]]);

        $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
        $method = $reflection->getMethod('verify_api_key');
        $method->setAccessible(true);

        $before_verification = time();
        $result = $method->invoke($api, $api_key);

        $updated_keys = get_option('bjlg_api_keys');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertSame($user->ID, $result['user_id']);
        $this->assertArrayHasKey('roles', $result);
        $this->assertContains('administrator', $result['roles']);
        $this->assertNotEmpty($updated_keys);
        $this->assertSame($initial_usage_count + 1, $updated_keys[0]['usage_count']);
        $this->assertArrayHasKey('last_used', $updated_keys[0]);
        $this->assertGreaterThanOrEqual($before_verification, $updated_keys[0]['last_used']);
        $this->assertSame($user->ID, $updated_keys[0]['user_id']);
        $this->assertContains('administrator', $updated_keys[0]['roles']);
    }

    public function test_verify_api_key_migrates_plain_keys(): void
    {
        $GLOBALS['bjlg_test_options'] = [];

        $api = new BJLG\BJLG_REST_API();

        $api_key = 'legacy-api-key';

        $user = $this->makeUser(202, 'legacy-user');
        $GLOBALS['bjlg_test_users'] = [
            $user->ID => $user,
        ];

        update_option('bjlg_api_keys', [[
            'key' => $api_key,
            'usage_count' => 0,
            'user_login' => $user->user_login,
        ]]);

        $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
        $method = $reflection->getMethod('verify_api_key');
        $method->setAccessible(true);

        $result = $method->invoke($api, $api_key);

        $this->assertIsArray($result);
        $this->assertSame($user->ID, $result['user_id']);
        $this->assertContains('administrator', $result['roles']);

        $updated_keys = get_option('bjlg_api_keys');

        $this->assertNotSame($api_key, $updated_keys[0]['key']);
        $this->assertTrue(wp_check_password($api_key, $updated_keys[0]['key']));
        $this->assertSame($user->ID, $updated_keys[0]['user_id']);
        $this->assertContains('administrator', $updated_keys[0]['roles']);
    }

    public function test_filter_api_keys_before_save_hashes_plain_keys(): void
    {
        $api = new BJLG\BJLG_REST_API();

        $plain_key = 'plain-key';

        $user = $this->makeUser(303, 'api-form-user');
        $GLOBALS['bjlg_test_users'] = [
            $user->ID => $user,
        ];

        $filtered = $api->filter_api_keys_before_save([
            [
                'key' => $plain_key,
                'label' => 'My key',
                'user' => $user->user_login,
            ],
        ]);

        $this->assertNotSame($plain_key, $filtered[0]['key']);
        $this->assertTrue(wp_check_password($plain_key, $filtered[0]['key']));
        $this->assertArrayNotHasKey('plain_key', $filtered[0]);
        $this->assertSame($user->ID, $filtered[0]['user_id']);
        $this->assertContains('administrator', $filtered[0]['roles']);
    }

    public function test_authenticate_rejects_api_key_for_different_user(): void
    {
        $api = new BJLG\BJLG_REST_API();

        $owner = $this->makeUser(404, 'api-owner');
        $other = $this->makeUser(405, 'other-user');
        $GLOBALS['bjlg_test_users'] = [
            $owner->ID => $owner,
            $other->ID => $other,
        ];

        $api_key = 'strict-key';

        update_option('bjlg_api_keys', [[
            'key' => wp_hash_password($api_key),
            'user_id' => $owner->ID,
            'roles' => $owner->roles,
        ]]);

        $request = new class($api_key, $other->user_login) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct(string $key, string $username)
            {
                $this->params = [
                    'api_key' => $key,
                    'username' => $username,
                    'password' => 'unused',
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        $result = $api->authenticate($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('api_key_user_mismatch', $result->get_error_code());
    }

    public function test_backup_endpoints_reject_symlink_outside_backup_directory(): void
    {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not supported in this environment.');
        }

        $api = new BJLG\BJLG_REST_API();

        $target = __DIR__ . '/../bjlg-outside-' . uniqid('', true) . '.zip';
        $symlinkName = 'bjlg-test-symlink-' . uniqid('', true) . '.zip';
        $symlinkPath = BJLG_BACKUP_DIR . $symlinkName;

        file_put_contents($target, 'outside');

        if (file_exists($symlinkPath) || is_link($symlinkPath)) {
            unlink($symlinkPath);
        }

        if (@symlink($target, $symlinkPath) === false) {
            unlink($target);
            $this->markTestSkipped('Unable to create symlink in backup directory.');
        }

        $request = new class($symlinkName) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct($id)
            {
                $this->params = [
                    'id' => $id,
                    'components' => ['all'],
                    'create_restore_point' => true,
                    'token' => null,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        try {
            foreach (['get_backup', 'delete_backup', 'download_backup', 'restore_backup'] as $method) {
                $result = $api->{$method}($request);
                $this->assertInstanceOf(WP_Error::class, $result, sprintf('Expected %s to return WP_Error.', $method));
                $this->assertSame('invalid_backup_id', $result->get_error_code(), sprintf('Expected %s to reject traversal attempts.', $method));
            }
        } finally {
            if (file_exists($symlinkPath) || is_link($symlinkPath)) {
                unlink($symlinkPath);
            }

            if (file_exists($target)) {
                unlink($target);
            }
        }
    }

    public function test_download_backup_accepts_ids_with_file_extensions(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $filename = 'bjlg-test-backup-' . uniqid('', true) . '.zip';
        $filepath = BJLG_BACKUP_DIR . $filename;

        file_put_contents($filepath, 'backup-data');

        $request = new class($filename) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct($id)
            {
                $this->params = [
                    'id' => $id,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        try {
            $response = $api->download_backup($request);

            $this->assertIsArray($response);
            $this->assertArrayHasKey('download_url', $response);
            $this->assertSame($filename, $response['filename']);
            $this->assertArrayHasKey('size', $response);
            $this->assertGreaterThan(0, $response['size']);
        } finally {
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }

        public function test_update_settings_rejects_empty_payload(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $original_cleanup = ['by_number' => 3, 'by_age' => 1];
            $GLOBALS['bjlg_test_options']['bjlg_cleanup_settings'] = $original_cleanup;

            $request = new class {
                public function get_json_params()
                {
                    return [];
                }
            };

            $result = $api->update_settings($request);

            $this->assertInstanceOf(WP_Error::class, $result);
            $this->assertSame('invalid_payload', $result->get_error_code());
            $this->assertSame($original_cleanup, get_option('bjlg_cleanup_settings'));
        }

        public function test_update_settings_rejects_invalid_cleanup_structure(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $original_cleanup = ['by_number' => 5, 'by_age' => 2];
            $GLOBALS['bjlg_test_options']['bjlg_cleanup_settings'] = $original_cleanup;

            $request = new class {
                public function get_json_params()
                {
                    return [
                        'cleanup' => ['by_number' => 'not-an-int'],
                    ];
                }
            };

            $result = $api->update_settings($request);

            $this->assertInstanceOf(WP_Error::class, $result);
            $this->assertSame('invalid_cleanup_settings', $result->get_error_code());
            $this->assertSame($original_cleanup, get_option('bjlg_cleanup_settings'));
        }

        public function test_create_schedule_rejects_incomplete_payload(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $original_schedule = [
                'recurrence' => 'weekly',
                'day' => 'monday',
                'time' => '01:00',
                'components' => ['db', 'plugins'],
                'encrypt' => false,
                'incremental' => false,
            ];
            $GLOBALS['bjlg_test_options']['bjlg_schedule_settings'] = $original_schedule;

            $request = new class {
                public function get_json_params()
                {
                    return [
                        'recurrence' => 'weekly',
                        'time' => '01:00',
                    ];
                }
            };

            $result = $api->create_schedule($request);

            $this->assertInstanceOf(WP_Error::class, $result);
            $this->assertSame('invalid_schedule_settings', $result->get_error_code());
            $this->assertSame($original_schedule, get_option('bjlg_schedule_settings'));
        }

    public function test_create_backup_stores_incremental_flag_from_rest_request(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_scheduled_events'] = [];

        $api = new BJLG\BJLG_REST_API();

        $request = new class {
            /** @var array<string, mixed> */
            private $params;

            public function __construct()
            {
                $this->params = [
                    'components' => ['db', 'plugins'],
                    'type' => 'incremental',
                    'encrypt' => 'false',
                    'description' => 'Incremental backup via REST',
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        $response = $api->create_backup($request);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('task_id', $response);

        $task_id = $response['task_id'];

        $this->assertArrayHasKey($task_id, $GLOBALS['bjlg_test_transients']);
        $task_data = $GLOBALS['bjlg_test_transients'][$task_id];

        $this->assertArrayHasKey('incremental', $task_data);
        $this->assertTrue($task_data['incremental']);
        $this->assertArrayHasKey('encrypt', $task_data);
        $this->assertFalse($task_data['encrypt']);
    }

    public function test_get_stats_handles_disk_space_failure(): void
    {
        $GLOBALS['bjlg_test_disk_total_space_mock'] = static function (string $directory) {
            return false;
        };

        $GLOBALS['bjlg_test_disk_free_space_mock'] = static function (string $directory) {
            return 1024;
        };

        $api = new BJLG\BJLG_REST_API();

        $request = new class {
            public function get_param($key)
            {
                if ($key === 'period') {
                    return 'week';
                }

                return null;
            }
        };

        $response = $api->get_stats($request);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('disk', $response);
        $this->assertArrayHasKey('usage_percent', $response['disk']);
        $this->assertNull($response['disk']['usage_percent']);
        $this->assertArrayHasKey('calculation_error', $response['disk']);
        $this->assertTrue($response['disk']['calculation_error']);

        unset($GLOBALS['bjlg_test_disk_total_space_mock'], $GLOBALS['bjlg_test_disk_free_space_mock']);
    }

    public function test_get_stats_does_not_register_cleanup_hooks_multiple_times(): void
    {
        $cleanup = BJLG\BJLG_Cleanup::instance();
        $initial_hooks = has_action(BJLG\BJLG_Cleanup::CRON_HOOK);

        $api = new BJLG\BJLG_REST_API();

        $request = new class {
            public function get_param($key)
            {
                if ($key === 'period') {
                    return 'week';
                }

                return null;
            }
        };

        $api->get_stats($request);
        $after_first_call = has_action(BJLG\BJLG_Cleanup::CRON_HOOK);

        $api->get_stats($request);
        $after_second_call = has_action(BJLG\BJLG_Cleanup::CRON_HOOK);

        $this->assertSame($initial_hooks, $after_first_call);
        $this->assertSame($after_first_call, $after_second_call);

        $registered_priority = has_action(BJLG\BJLG_Cleanup::CRON_HOOK, [$cleanup, 'run_cleanup']);
        $this->assertNotFalse($registered_priority);
    }

    public function test_format_backup_data_generates_download_token(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $tempFile = tempnam(BJLG_BACKUP_DIR, 'bjlg-test-backup-');
        file_put_contents($tempFile, 'backup-content');

        $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
        $method = $reflection->getMethod('format_backup_data');
        $method->setAccessible(true);

        $data = $method->invoke($api, $tempFile);

        $this->assertArrayHasKey('download_url', $data);
        $this->assertArrayHasKey('download_token', $data);
        $this->assertArrayHasKey('download_rest_url', $data);

        $parsed_url = parse_url($data['download_url']);
        $query_args = [];

        if (!empty($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query_args);
        }

        $this->assertArrayHasKey('token', $query_args);
        $this->assertSame($data['download_token'], $query_args['token']);
        $this->assertSame($tempFile, get_transient('bjlg_download_' . $data['download_token']));

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    private function generateJwtToken(array $overrides = []): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = array_merge([
            'user_id' => 1,
            'username' => 'test-user',
            'exp' => time() + 3600,
            'iat' => time(),
        ], $overrides);

        $base64Header = $this->base64UrlEncode((string) json_encode($header));
        $base64Payload = $this->base64UrlEncode((string) json_encode($payload));
        $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, AUTH_KEY, true);

        return $base64Header . '.' . $base64Payload . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

}
