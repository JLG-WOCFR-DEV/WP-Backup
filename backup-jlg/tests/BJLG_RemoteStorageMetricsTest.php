<?php
declare(strict_types=1);

use BJLG\BJLG_Admin_Advanced;
use BJLG\BJLG_Remote_Storage_Metrics;
use BJLG\BJLG_Settings;
use BJLG\Tests\Stubs\BJLG_Fake_Remote_Destination;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/class-bjlg-settings.php';
require_once __DIR__ . '/../includes/class-bjlg-destination-factory.php';
require_once __DIR__ . '/../includes/class-bjlg-remote-storage-metrics.php';
require_once __DIR__ . '/../includes/class-bjlg-admin-advanced.php';
require_once __DIR__ . '/stubs/class-bjlg-fake-remote-destination.php';

final class BJLG_RemoteStorageMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['bjlg_test_filters'] = [];
        $GLOBALS['bjlg_test_options'] = [];
        $GLOBALS['bjlg_test_transients'] = [];
        BJLG_Remote_Storage_Metrics::invalidate_cache();
    }

    public function test_refresh_snapshot_collects_usage_and_caches(): void
    {
        add_filter('bjlg_known_destination_ids', static function () {
            return ['fake_remote'];
        });

        $destination = new BJLG_Fake_Remote_Destination('fake_remote', [
            'usage' => [
                'used_bytes' => 150,
                'quota_bytes' => 300,
                'free_bytes' => 150,
                'source' => 'provider',
            ],
            'backups' => [
                ['size' => 50],
                ['size' => 100],
            ],
        ]);

        add_filter('bjlg_destination_factory', static function ($provided, $id) use ($destination) {
            if ($id === 'fake_remote') {
                return $destination;
            }

            return $provided;
        }, 10, 2);

        $snapshot = BJLG_Remote_Storage_Metrics::refresh_snapshot(true);

        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('destinations', $snapshot);
        $this->assertArrayHasKey('fake_remote', $snapshot['destinations']);
        $this->assertSame(150, $snapshot['destinations']['fake_remote']['used_bytes']);

        $cached = get_transient(BJLG_Remote_Storage_Metrics::TRANSIENT_KEY);
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('destinations', $cached);
        $this->assertArrayHasKey('fake_remote', $cached['destinations']);
    }

    public function test_collect_remote_storage_metrics_triggers_warning(): void
    {
        add_filter('bjlg_known_destination_ids', static function () {
            return ['remote_warning'];
        });

        $usage_snapshot = [
            'generated_at' => time(),
            'destinations' => [
                'remote_warning' => [
                    'id' => 'remote_warning',
                    'name' => 'Remote Warning',
                    'connected' => true,
                    'used_bytes' => 900,
                    'quota_bytes' => 1000,
                    'free_bytes' => 100,
                    'refreshed_at' => time(),
                    'source' => 'provider',
                ],
            ],
        ];

        set_transient(BJLG_Remote_Storage_Metrics::TRANSIENT_KEY, $usage_snapshot, 60);
        update_option(BJLG_Remote_Storage_Metrics::OPTION_KEY, $usage_snapshot);

        $destination = new BJLG_Fake_Remote_Destination('remote_warning', [
            'usage' => $usage_snapshot['destinations']['remote_warning'],
            'backups' => [
                ['size' => 450],
                ['size' => 450],
            ],
        ]);

        add_filter('bjlg_destination_factory', static function ($provided, $id) use ($destination) {
            if ($id === 'remote_warning') {
                return $destination;
            }

            return $provided;
        }, 10, 2);

        update_option('bjlg_advanced_settings', [
            'remote_storage_threshold' => 0.7,
        ]);

        $captured = [];
        add_action('bjlg_storage_warning', static function ($payload) use (&$captured) {
            $captured[] = $payload;
        }, 10, 1);

        $admin = new BJLG_Admin_Advanced();
        $reflection = new \ReflectionClass($admin);
        $method = $reflection->getMethod('collect_remote_storage_metrics');
        $method->setAccessible(true);
        $metrics = $method->invoke($admin);

        $this->assertNotEmpty($captured, 'Expected bjlg_storage_warning to be triggered.');
        $this->assertSame('remote_warning', $captured[0]['destination_id']);
        $this->assertSame(900, $captured[0]['used_bytes']);
        $this->assertSame(1000, $captured[0]['quota_bytes']);

        $this->assertArrayHasKey('destinations', $metrics);
        $this->assertCount(1, $metrics['destinations']);
        $entry = $metrics['destinations'][0];
        $this->assertSame('remote_warning', $entry['id']);
        $this->assertSame('warning', $entry['badge']);
        $this->assertSame('provider', $entry['snapshot_source']);
    }
}

