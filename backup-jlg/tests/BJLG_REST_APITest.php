<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-rest-api.php';

final class BJLG_REST_APITest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['bjlg_test_options'] = [];
    }

    public function test_verify_api_key_increments_usage_count(): void
    {
        update_option('bjlg_api_keys', [
            [
                'key' => 'test-key',
                'usage_count' => 2,
                'last_used' => 123,
            ],
        ]);

        $api = new BJLG_REST_API();
        $beforeTime = time();

        $method = new ReflectionMethod(BJLG_REST_API::class, 'verify_api_key');
        $method->setAccessible(true);

        $result = $method->invoke($api, 'test-key');

        $this->assertTrue($result);

        $storedKeys = get_option('bjlg_api_keys');

        $this->assertSame(3, $storedKeys[0]['usage_count']);
        $this->assertArrayHasKey('last_used', $storedKeys[0]);
        $this->assertGreaterThanOrEqual($beforeTime, $storedKeys[0]['last_used']);
    }
}
