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

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
