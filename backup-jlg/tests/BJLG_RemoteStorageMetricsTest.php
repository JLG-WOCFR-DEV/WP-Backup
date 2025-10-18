<?php
declare(strict_types=1);

namespace BJLG\Tests;

use BJLG\BJLG_Destination_Interface;
use BJLG\BJLG_Remote_Storage_Metrics;
use BJLG\BJLG_Settings;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

require_once __DIR__ . '/../backup-jlg.php';
require_once __DIR__ . '/../includes/class-bjlg-settings.php';
require_once __DIR__ . '/../includes/class-bjlg-remote-storage-metrics.php';
require_once __DIR__ . '/../includes/class-bjlg-destination-factory.php';
require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';

final class BJLG_RemoteStorageMetricsTest extends TestCase
{
    /** @var array<string, mixed> */
    private $previousHooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSettingsInstance();
        BJLG_Settings::get_instance();
        $this->previousHooks = $GLOBALS['bjlg_test_hooks'] ?? ['actions' => [], 'filters' => []];
        $GLOBALS['bjlg_test_hooks'] = ['actions' => [], 'filters' => []];
        $GLOBALS['bjlg_test_options'] = [];
        $GLOBALS['bjlg_test_site_options'] = [];
        $GLOBALS['bjlg_test_transients'] = [];
    }

    protected function tearDown(): void
    {
        $this->resetSettingsInstance();
        $GLOBALS['bjlg_test_hooks'] = $this->previousHooks;
        parent::tearDown();
    }

    private function resetSettingsInstance(): void
    {
        if (!class_exists(BJLG_Settings::class)) {
            return;
        }

        $reflection = new ReflectionProperty(BJLG_Settings::class, 'instance');
        $reflection->setAccessible(true);
        $reflection->setValue(null, null);
    }

    public function test_refresh_snapshot_collects_usage_and_calculates_free_space(): void
    {
        add_filter('bjlg_known_destination_ids', static fn() => ['stub']);

        $destination = $this->createDestination([
            'used_bytes' => 1500,
            'quota_bytes' => 5000,
            'free_bytes' => 3500,
        ], [
            ['size' => 200],
            ['size' => 400],
        ]);

        add_filter(
            'bjlg_destination_factory',
            static function ($provided, $destination_id) use ($destination) {
                if ($destination_id === 'stub') {
                    return $destination;
                }

                return $provided;
            },
            10,
            2
        );

        $snapshot = BJLG_Remote_Storage_Metrics::refresh_snapshot();

        $this->assertArrayHasKey('generated_at', $snapshot);
        $this->assertArrayHasKey('destinations', $snapshot);
        $this->assertCount(1, $snapshot['destinations']);

        $entry = $snapshot['destinations'][0];
        $this->assertSame('stub', $entry['id']);
        $this->assertSame(1500, $entry['used_bytes']);
        $this->assertSame(5000, $entry['quota_bytes']);
        $this->assertSame(3500, $entry['free_bytes']);
        $this->assertSame(2, $entry['backups_count']);
        $this->assertNotNull($entry['latency_ms']);

        $stored = bjlg_get_option(BJLG_Remote_Storage_Metrics::OPTION_KEY, []);
        $this->assertSame($snapshot, $stored);
    }

    public function test_get_snapshot_returns_cached_data_when_not_stale(): void
    {
        add_filter('bjlg_remote_metrics_refresh_interval', static fn() => 600);

        $current = time();
        $cached = [
            'generated_at' => $current,
            'destinations' => [
                [
                    'id' => 'cached',
                    'used_bytes' => 100,
                    'quota_bytes' => 200,
                    'free_bytes' => 100,
                    'refreshed_at' => $current,
                ],
            ],
        ];

        bjlg_update_option(BJLG_Remote_Storage_Metrics::OPTION_KEY, $cached);

        $factoryCalls = 0;
        add_filter(
            'bjlg_destination_factory',
            static function ($provided, $destination_id) use (&$factoryCalls) {
                $factoryCalls++;

                return $provided;
            },
            10,
            2
        );

        add_filter('bjlg_known_destination_ids', static fn() => ['cached']);

        $snapshot = BJLG_Remote_Storage_Metrics::get_snapshot();

        $this->assertFalse($snapshot['stale']);
        $this->assertSame($cached['generated_at'], $snapshot['generated_at']);
        $this->assertSame($cached['destinations'], $snapshot['destinations']);
        $this->assertSame(0, $factoryCalls);
    }

    public function test_get_snapshot_refreshes_when_cache_expired(): void
    {
        add_filter('bjlg_remote_metrics_refresh_interval', static fn() => 60);
        add_filter('bjlg_known_destination_ids', static fn() => ['stub']);

        $previous = [
            'generated_at' => time() - 360,
            'destinations' => [
                ['id' => 'stale'],
            ],
        ];

        bjlg_update_option(BJLG_Remote_Storage_Metrics::OPTION_KEY, $previous);

        $factoryCalls = 0;
        $destination = $this->createDestination([], [
            ['size' => 300],
            ['size' => 300],
        ]);

        add_filter(
            'bjlg_destination_factory',
            static function ($provided, $destination_id) use (&$factoryCalls, $destination) {
                if ($destination_id === 'stub') {
                    $factoryCalls++;

                    return $destination;
                }

                return $provided;
            },
            10,
            2
        );

        $snapshot = BJLG_Remote_Storage_Metrics::get_snapshot();

        $this->assertArrayHasKey('generated_at', $snapshot);
        $this->assertGreaterThan($previous['generated_at'], $snapshot['generated_at']);
        $this->assertFalse($snapshot['stale']);
        $this->assertSame(1, $factoryCalls);
        $this->assertCount(1, $snapshot['destinations']);
        $entry = $snapshot['destinations'][0];
        $this->assertSame(600, $entry['used_bytes']);
        $this->assertNull($entry['quota_bytes']);
        $this->assertNull($entry['free_bytes']);
        $this->assertSame(2, $entry['backups_count']);
    }

    /**
     * @param array<string, mixed> $usage
     * @param array<int, array<string, mixed>> $backups
     */
    private function createDestination(array $usage, array $backups, bool $connected = true): BJLG_Destination_Interface
    {
        return new class($usage, $backups, $connected) implements BJLG_Destination_Interface {
            /** @var array<string, mixed> */
            private $usage;

            /** @var array<int, array<string, mixed>> */
            private $backups;

            /** @var bool */
            private $connected;

            public function __construct(array $usage, array $backups, bool $connected)
            {
                $this->usage = $usage;
                $this->backups = $backups;
                $this->connected = $connected;
            }

            public function get_id()
            {
                return 'stub';
            }

            public function get_name()
            {
                return 'Stub';
            }

            public function is_connected()
            {
                return $this->connected;
            }

            public function disconnect()
            {
            }

            public function render_settings()
            {
            }

            public function upload_file($filepath, $task_id)
            {
            }

            public function list_remote_backups()
            {
                return $this->backups;
            }

            public function prune_remote_backups($retain_by_number, $retain_by_age_days)
            {
                return [];
            }

            public function delete_remote_backup_by_name($filename)
            {
                return ['success' => true, 'message' => ''];
            }

            public function get_storage_usage()
            {
                return $this->usage;
            }
        };
    }
}
