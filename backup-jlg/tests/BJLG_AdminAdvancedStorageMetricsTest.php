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
        $this->assertSame('critical', $result['destinations'][0]['badge'] ?? null);
    }

    public function test_collect_remote_storage_metrics_guards_missing_projection_fields(): void
    {
        $result = $this->collectRemoteStorageMetrics([
            [
                'id' => 'gdrive',
                'name' => 'Google Drive',
                'connected' => true,
                'used_bytes' => 100,
                'quota_bytes' => 1000,
                'free_bytes' => 900,
                'errors' => [],
                'refreshed_at' => time(),
            ],
        ]);

        $this->assertArrayHasKey('destinations', $result);
        $this->assertCount(1, $result['destinations']);
        $this->assertArrayNotHasKey('badge', $result['destinations'][0]);
        $this->assertSame(0.1, round((float) $result['destinations'][0]['utilization_ratio'], 2));
    }

    /**
     * @dataProvider provideProjectionBadgeCases
     * @param array<string, mixed> $destinationOverrides
     */
    public function test_collect_remote_storage_metrics_sets_badge_from_projection_fields(
        array $destinationOverrides,
        ?string $expectedBadge
    ): void {
        $destination = array_merge(
            [
                'id' => 'aws-s3',
                'name' => 'Primary S3',
                'connected' => true,
                'used_bytes' => 400,
                'quota_bytes' => 1000,
                'free_bytes' => 600,
                'errors' => [],
                'refreshed_at' => time(),
            ],
            $destinationOverrides
        );

        $result = $this->collectRemoteStorageMetrics([$destination]);
        $this->assertSame($expectedBadge, $result['destinations'][0]['badge'] ?? null);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string|null}>
     */
    public function provideProjectionBadgeCases(): array
    {
        return [
            'ratio above threshold wins over projection' => [
                [
                    'used_bytes' => 900,
                    'quota_bytes' => 1000,
                    'free_bytes' => 100,
                    'days_to_threshold' => 10,
                    'projection_intent' => 'success',
                ],
                'critical',
            ],
            'days to threshold within one day' => [
                [
                    'days_to_threshold' => 0.5,
                    'projection_intent' => 'critical',
                ],
                'critical',
            ],
            'days to threshold within three days' => [
                [
                    'days_to_threshold' => 2.5,
                    'projection_intent' => 'warning',
                ],
                'warning',
            ],
            'projection success without imminent threshold' => [
                [
                    'days_to_threshold' => 10,
                    'projection_intent' => 'SUCCESS',
                ],
                'success',
            ],
            'projection success with missing days to threshold' => [
                [
                    'projection_intent' => 'success',
                ],
                'success',
            ],
            'non numeric days to threshold is ignored' => [
                [
                    'days_to_threshold' => 'soon',
                    'projection_intent' => 'watch',
                ],
                null,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $destinations
     * @return array<string, mixed>
     */
    private function collectRemoteStorageMetrics(array $destinations, ?int $generated_at = null): array
    {
        $generated_at = $generated_at ?? time();
        bjlg_update_option(BJLG_Remote_Storage_Metrics::OPTION_KEY, [
            'generated_at' => $generated_at,
            'destinations' => $destinations,
        ]);

        $admin = new BJLG_Admin_Advanced();
        $method = new ReflectionMethod(BJLG_Admin_Advanced::class, 'collect_remote_storage_metrics');
        $method->setAccessible(true);

        return $method->invoke($admin);
    }
}

}

