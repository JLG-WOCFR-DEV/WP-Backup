<?php

declare(strict_types=1);

namespace BJLG {
    if (!function_exists(__NAMESPACE__ . '\\date_i18n')) {
        function date_i18n($format, $timestamp = null) {
            $timestamp = $timestamp ?? time();

            return date($format, $timestamp);
        }
    }
}

namespace BJLG\Tests {

use BJLG\BJLG_Admin_Advanced;
use BJLG\BJLG_Remote_Storage_Metrics;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/../backup-jlg.php';
require_once __DIR__ . '/../includes/class-bjlg-admin-advanced.php';
require_once __DIR__ . '/../includes/class-bjlg-remote-storage-metrics.php';

final class BJLG_AdminAdvancedStorageMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        bjlg_update_option(BJLG_Remote_Storage_Metrics::OPTION_KEY, []);
        bjlg_update_option(BJLG_Remote_Storage_Metrics::WARNING_DIGEST_OPTION, []);
        $GLOBALS['bjlg_test_hooks']['actions']['bjlg_storage_warning'] = [];
        add_filter('bjlg_remote_metrics_refresh_interval', static fn() => 3600);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['bjlg_test_hooks']['actions']['bjlg_storage_warning']);
        unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_remote_metrics_refresh_interval']);
        bjlg_update_option(BJLG_Remote_Storage_Metrics::OPTION_KEY, []);
        bjlg_update_option(BJLG_Remote_Storage_Metrics::WARNING_DIGEST_OPTION, []);
        parent::tearDown();
    }

    public function test_collect_remote_storage_metrics_triggers_warning_when_ratio_exceeds_threshold(): void
    {
        $generated_at = time();
        bjlg_update_option(BJLG_Remote_Storage_Metrics::OPTION_KEY, [
            'generated_at' => $generated_at,
            'destinations' => [
                [
                    'id' => 'aws-s3',
                    'name' => 'Primary S3',
                    'connected' => true,
                    'used_bytes' => 900,
                    'quota_bytes' => 1000,
                    'free_bytes' => 100,
                    'errors' => [],
                    'refreshed_at' => $generated_at,
                ],
            ],
        ]);

        $warnings = [];
        add_action('bjlg_storage_warning', static function ($payload) use (&$warnings) {
            $warnings[] = $payload;
        }, 10, 1);

        $admin = new BJLG_Admin_Advanced();
        $method = new ReflectionMethod(BJLG_Admin_Advanced::class, 'collect_remote_storage_metrics');
        $method->setAccessible(true);
        $result = $method->invoke($admin);

        $this->assertSame($generated_at, $result['generated_at']);
        $this->assertNotEmpty($warnings);
        $this->assertCount(1, $warnings);
        $warning = $warnings[0];
        $this->assertSame('awss3', $warning['destination_id']);
        $this->assertSame(0.9, round((float) $warning['ratio'], 2));
        $this->assertSame(85.0, (float) $warning['threshold_percent']);
        $this->assertSame(900, $warning['used_bytes']);
        $this->assertSame(1000, $warning['quota_bytes']);

        $digest = bjlg_get_option(BJLG_Remote_Storage_Metrics::WARNING_DIGEST_OPTION, []);
        $this->assertArrayHasKey('awss3', $digest);
        $this->assertSame($generated_at, $digest['awss3']);
    }
}

}

