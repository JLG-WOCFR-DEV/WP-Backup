<?php
declare(strict_types=1);

namespace BJLG {
    if (!class_exists(__NAMESPACE__ . '\\BJLG_History')) {
        class BJLG_History
        {
            public static function get_stats($period = 'week')
            {
                return [
                    'total_actions' => 0,
                    'successful' => 0,
                    'failed' => 0,
                    'info' => 0,
                    'by_action' => [],
                    'by_user' => [],
                    'most_active_hour' => null,
                ];
            }
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../includes/class-bjlg-rest-api.php';

    if (!defined('AUTH_KEY')) {
        define('AUTH_KEY', 'test-auth-key');
    }

    final class BJLG_REST_APITest extends TestCase
{
    public function test_verify_jwt_token_returns_false_for_invalid_signature(): void
    {
        $api = new BJLG\BJLG_REST_API();

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = [
            'user_id' => 1,
            'username' => 'test',
            'exp' => time() + 3600,
            'iat' => time(),
        ];

        $base64Header = $this->base64UrlEncode((string) json_encode($header));
        $base64Payload = $this->base64UrlEncode((string) json_encode($payload));

        $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, AUTH_KEY, true);
        $validSignature = $this->base64UrlEncode($signature);

        $invalidSignature = $this->base64UrlEncode(
            hash_hmac('sha256', $base64Header . '.' . $base64Payload, AUTH_KEY . 'invalid', true)
        );

        if (hash_equals($validSignature, $invalidSignature)) {
            $invalidSignature = $this->base64UrlEncode(
                hash_hmac('sha256', $base64Header . '.' . $base64Payload, AUTH_KEY . 'fallback', true)
            );
        }

        $token = $base64Header . '.' . $base64Payload . '.' . $invalidSignature;

        $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
        $method = $reflection->getMethod('verify_jwt_token');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($api, $token));
    }

    public function test_verify_api_key_updates_usage_statistics(): void
    {
        $GLOBALS['bjlg_test_options'] = [];

        $api = new BJLG\BJLG_REST_API();

        $api_key = 'test-api-key';
        $hashed_key = wp_hash_password($api_key);
        $initial_usage_count = 2;
        $initial_last_used = time() - 3600;

        update_option('bjlg_api_keys', [[
            'key' => $hashed_key,
            'usage_count' => $initial_usage_count,
            'last_used' => $initial_last_used,
        ]]);

        $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
        $method = $reflection->getMethod('verify_api_key');
        $method->setAccessible(true);

        $before_verification = time();
        $result = $method->invoke($api, $api_key);

        $updated_keys = get_option('bjlg_api_keys');

        $this->assertTrue($result);
        $this->assertNotEmpty($updated_keys);
        $this->assertSame($initial_usage_count + 1, $updated_keys[0]['usage_count']);
        $this->assertArrayHasKey('last_used', $updated_keys[0]);
        $this->assertGreaterThanOrEqual($before_verification, $updated_keys[0]['last_used']);
    }

    public function test_verify_api_key_migrates_plain_keys(): void
    {
        $GLOBALS['bjlg_test_options'] = [];

        $api = new BJLG\BJLG_REST_API();

        $api_key = 'legacy-api-key';

        update_option('bjlg_api_keys', [[
            'key' => $api_key,
            'usage_count' => 0,
        ]]);

        $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
        $method = $reflection->getMethod('verify_api_key');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($api, $api_key));

        $updated_keys = get_option('bjlg_api_keys');

        $this->assertNotSame($api_key, $updated_keys[0]['key']);
        $this->assertTrue(wp_check_password($api_key, $updated_keys[0]['key']));
    }

    public function test_filter_api_keys_before_save_hashes_plain_keys(): void
    {
        $api = new BJLG\BJLG_REST_API();

        $plain_key = 'plain-key';

        $filtered = $api->filter_api_keys_before_save([
            [
                'key' => $plain_key,
                'label' => 'My key',
            ],
        ]);

        $this->assertNotSame($plain_key, $filtered[0]['key']);
        $this->assertTrue(wp_check_password($plain_key, $filtered[0]['key']));
        $this->assertArrayNotHasKey('plain_key', $filtered[0]);
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

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

}
