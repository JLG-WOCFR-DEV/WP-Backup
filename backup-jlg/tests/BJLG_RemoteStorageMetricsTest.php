<?php
declare(strict_types=1);

namespace BJLG\Tests;

use BJLG\BJLG_Destination_Interface;
use BJLG\BJLG_Remote_Storage_Metrics;
use BJLG\BJLG_Settings;
use BJLG\Tests\Stubs\BJLG_Fake_Remote_Destination;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

require_once __DIR__ . '/../backup-jlg.php';
require_once __DIR__ . '/../includes/class-bjlg-settings.php';
require_once __DIR__ . '/../includes/class-bjlg-remote-storage-metrics.php';
require_once __DIR__ . '/../includes/class-bjlg-destination-factory.php';
require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/stubs/class-bjlg-fake-remote-destination.php';

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

    /**
     * @return iterable<string, array{string, array<string, int>, array<int, array<string, int>>, array<string, mixed>}>
     */
    public function provideDestinationSnapshots(): iterable
    {
        yield 'aws s3 with complete usage' => [
            'aws-s3',
            [
                'used_bytes' => 1073741824,
                'quota_bytes' => 2147483648,
                'free_bytes' => 1073741824,
            ],
            [
                ['size' => 524288000],
                ['size' => 262144000],
            ],
            [
                'used_bytes' => 1073741824,
                'quota_bytes' => 2147483648,
                'free_bytes' => 1073741824,
                'backups_count' => 2,
            ],
        ];

        yield 'azure blob computes missing free space' => [
            'azure-blob',
            [
                'used_bytes' => 536870912,
                'quota_bytes' => 1073741824,
            ],
            [],
            [
                'used_bytes' => 536870912,
                'quota_bytes' => 1073741824,
                'free_bytes' => 536870912,
                'backups_count' => 0,
            ],
        ];

        yield 'backblaze b2 infers usage from backups' => [
            'backblaze-b2',
            [],
            [
                ['size' => 157286400],
                ['size' => 262144000],
            ],
            [
                'used_bytes' => 419430400,
                'quota_bytes' => null,
                'free_bytes' => null,
                'backups_count' => 2,
            ],
        ];
    }

    /**
     * @dataProvider provideDestinationSnapshots
     *
     * @param array<string, int>                   $usage
     * @param array<int, array<string, int>>       $backups
     * @param array<string, int|null>              $expected
     */
    public function test_collect_destination_snapshot_handles_various_destinations(
        string $destination_id,
        array $usage,
        array $backups,
        array $expected
    ): void {
        $destination = new BJLG_Fake_Remote_Destination($destination_id, [
            'usage' => $usage,
            'backups' => $backups,
        ]);

        $start = time();
        $snapshot = $this->invokeCollectDestinationSnapshot($destination_id, $destination);
        $end = time();

        $this->assertSame($destination_id, $snapshot['id']);
        $this->assertTrue($snapshot['connected']);
        $this->assertSame($expected['backups_count'], $snapshot['backups_count']);
        $this->assertGreaterThanOrEqual($start, $snapshot['refreshed_at']);
        $this->assertLessThanOrEqual($end, $snapshot['refreshed_at']);
        $this->assertIsInt($snapshot['latency_ms']);
        $this->assertGreaterThanOrEqual(0, $snapshot['latency_ms']);
        $this->assertSame($expected['used_bytes'], $snapshot['used_bytes']);
        $this->assertSame($expected['quota_bytes'], $snapshot['quota_bytes']);
        $this->assertSame($expected['free_bytes'], $snapshot['free_bytes']);
        if ($snapshot['used_bytes'] !== null) {
            $this->assertSame(size_format((int) $snapshot['used_bytes']), $snapshot['used_human']);
        } else {
            $this->assertSame('', $snapshot['used_human']);
        }

        if ($snapshot['quota_bytes'] !== null) {
            $this->assertSame(size_format((int) $snapshot['quota_bytes']), $snapshot['quota_human']);
        } else {
            $this->assertSame('', $snapshot['quota_human']);
        }

        if ($snapshot['free_bytes'] !== null) {
            $this->assertSame(size_format((int) $snapshot['free_bytes']), $snapshot['free_human']);
        } else {
            $this->assertSame('', $snapshot['free_human']);
        }

        $this->assertSame([], $snapshot['errors']);
    }

    public function test_collect_destination_snapshot_records_api_errors(): void
    {
        $destination = new class() implements BJLG_Destination_Interface {
            public function get_id()
            {
                return 'azure-blob';
            }

            public function get_name()
            {
                return 'Azure Blob';
            }

            public function is_connected()
            {
                return true;
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
                throw new RuntimeException('Backups API unavailable');
            }

            public function prune_remote_backups($retain_by_number, $retain_by_age_days)
            {
                return [];
            }

            public function delete_remote_backup_by_name($filename)
            {
                return ['success' => false];
            }

            public function get_storage_usage()
            {
                throw new RuntimeException('Usage API unavailable');
            }
        };

        $snapshot = $this->invokeCollectDestinationSnapshot('azure-blob', $destination);

        $this->assertSame('azure-blob', $snapshot['id']);
        $this->assertSame(0, $snapshot['used_bytes']);
        $this->assertNull($snapshot['quota_bytes']);
        $this->assertNull($snapshot['free_bytes']);
        $this->assertSame('0 B', $snapshot['used_human']);
        $this->assertSame(0, $snapshot['backups_count']);
        $this->assertNotEmpty($snapshot['errors']);
        $this->assertSame([
            'Usage API unavailable',
            'Backups API unavailable',
        ], $snapshot['errors']);
    }

    private function invokeCollectDestinationSnapshot(string $destination_id, BJLG_Destination_Interface $destination): array
    {
        $method = new ReflectionMethod(BJLG_Remote_Storage_Metrics::class, 'collect_destination_snapshot');
        $method->setAccessible(true);

        /** @var array<string, mixed> $snapshot */
        $snapshot = $method->invoke(null, $destination_id, $destination);

        return $snapshot;
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
