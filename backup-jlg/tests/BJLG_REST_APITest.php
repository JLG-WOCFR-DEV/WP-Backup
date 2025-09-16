<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-rest-api.php';

if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', 'test-auth-key');
}

final class BJLG_REST_APITest extends TestCase
{
    public function test_verify_jwt_token_returns_false_for_invalid_signature(): void
    {
        $api = new BJLG_REST_API();

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

        $reflection = new ReflectionClass(BJLG_REST_API::class);
        $method = $reflection->getMethod('verify_jwt_token');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($api, $token));
    }

    public function test_verify_api_key_updates_usage_statistics(): void
    {
        $GLOBALS['bjlg_test_options'] = [];

        $api = new BJLG_REST_API();

        $api_key = 'test-api-key';
        $initial_usage_count = 2;
        $initial_last_used = time() - 3600;

        update_option('bjlg_api_keys', [[
            'key' => $api_key,
            'usage_count' => $initial_usage_count,
            'last_used' => $initial_last_used,
        ]]);

        $reflection = new ReflectionClass(BJLG_REST_API::class);
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

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
