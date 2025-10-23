<?php
declare(strict_types=1);

namespace BJLG;

if (!function_exists(__NAMESPACE__ . '\\date_i18n')) {
    function date_i18n($format, $timestamp = null)
    {
        $timestamp = $timestamp ?? time();

        return date($format, $timestamp);
    }
}

namespace BJLG\Tests;

use BJLG\BJLG_Destination_Interface;
use BJLG\BJLG_Remote_Storage_Metrics;
use BJLG\BJLG_Settings;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

require_once __DIR__ . '/../backup-jlg.php';
require_once __DIR__ . '/../includes/class-bjlg-settings.php';
require_once __DIR__ . '/../includes/class-bjlg-remote-storage-metrics.php';
require_once __DIR__ . '/../includes/class-bjlg-destination-factory.php';
require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/class-bjlg-admin-advanced.php';

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

    private function invokeCollectDestinationSnapshot(string $destination_id, BJLG_Destination_Interface $destination): array
    {
        $method = new ReflectionMethod(BJLG_Remote_Storage_Metrics::class, 'collect_destination_snapshot');
        $method->setAccessible(true);

        /** @var array<string, mixed> $entry */
        $entry = $method->invoke(null, $destination_id, $destination);

        return $entry;
    }

    private function createNamedDestination(string $id, string $name, array $usage, array $backups, bool $connected = true): BJLG_Destination_Interface
    {
        return new class($id, $name, $usage, $backups, $connected) implements BJLG_Destination_Interface {
            private string $id;
            private string $name;

            /** @var array<string, mixed> */
            private array $usage;

            /** @var array<int, array<string, mixed>> */
            private array $backups;

            private bool $connected;

            public function __construct(string $id, string $name, array $usage, array $backups, bool $connected)
            {
                $this->id = $id;
                $this->name = $name;
                $this->usage = $usage;
                $this->backups = $backups;
                $this->connected = $connected;
            }

            public function get_id()
            {
                return $this->id;
            }

            public function get_name()
            {
                return $this->name;
            }

            public function is_connected()
            {
                return $this->connected;
            }

            public function disconnect()
            {
                $this->connected = false;
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

    /**
     * @return array<string, array{0:string,1:string,2:array<string,mixed>,3:array<int,array<string,mixed>>}>
     */
    public function provideDestinationUsage(): array
    {
        return [
            'aws_s3' => [
                'aws_s3',
                'Amazon S3',
                [
                    'used_bytes' => '2048',
                    'quota_bytes' => '4096',
                ],
                [
                    ['size' => '512'],
                    ['size' => 256],
                ],
            ],
            'azure_blob' => [
                'azure_blob',
                'Azure Blob',
                [
                    'used_bytes' => '1024',
                    'quota_bytes' => '2048',
                    'free_bytes' => '1024',
                ],
                [
                    ['size' => '128'],
                ],
            ],
            'backblaze_b2' => [
                'backblaze_b2',
                'Backblaze B2',
                [],
                [
                    ['size' => 100],
                    ['size' => '200'],
                ],
            ],
        ];
    }

    /**
     * @dataProvider provideDestinationUsage
     */
    public function test_collect_destination_snapshot_normalizes_usage_for_known_destinations(
        string $destination_id,
        string $name,
        array $usage,
        array $backups
    ): void {
        $destination = $this->createNamedDestination($destination_id, $name, $usage, $backups);

        $entry = $this->invokeCollectDestinationSnapshot($destination_id, $destination);

        $this->assertSame($destination_id, $entry['id']);
        $this->assertSame($name, $entry['name']);
        $this->assertTrue($entry['connected']);
        $this->assertSame(count($backups), $entry['backups_count']);
        $this->assertIsInt($entry['latency_ms']);
        $this->assertGreaterThanOrEqual(0, $entry['latency_ms']);
        $this->assertSame([], $entry['errors']);

        if (isset($usage['used_bytes'])) {
            $this->assertSame((int) $usage['used_bytes'], $entry['used_bytes']);
        } else {
            $expected_total = 0;
            foreach ($backups as $backup) {
                $expected_total += isset($backup['size']) ? (int) $backup['size'] : 0;
            }
            $this->assertSame($expected_total, $entry['used_bytes']);
        }

        if (isset($usage['quota_bytes'])) {
            $this->assertSame((int) $usage['quota_bytes'], $entry['quota_bytes']);
        }

        if (isset($usage['free_bytes'])) {
            $this->assertSame((int) $usage['free_bytes'], $entry['free_bytes']);
        } elseif (isset($usage['quota_bytes'], $usage['used_bytes'])) {
            $this->assertSame(max(0, (int) $usage['quota_bytes'] - (int) $usage['used_bytes']), $entry['free_bytes']);
        } else {
            $this->assertNull($entry['free_bytes']);
        }
    }

    public function test_collect_destination_snapshot_returns_defaults_when_disconnected(): void
    {
        $destination = $this->createNamedDestination('aws_s3', 'Amazon S3', [], [], false);

        $entry = $this->invokeCollectDestinationSnapshot('aws_s3', $destination);

        $this->assertSame('aws_s3', $entry['id']);
        $this->assertSame('Amazon S3', $entry['name']);
        $this->assertFalse($entry['connected']);
        $this->assertNull($entry['latency_ms']);
        $this->assertSame([], $entry['errors']);
        $this->assertSame(0, $entry['backups_count']);
        $this->assertNull($entry['used_bytes']);
        $this->assertNull($entry['quota_bytes']);
        $this->assertNull($entry['free_bytes']);
    }

    public function test_collect_destination_snapshot_records_api_errors(): void
    {
        $destination = new class() implements BJLG_Destination_Interface {
            public function get_id()
            {
                return 'azure_blob';
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
                throw new \RuntimeException('List error');
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
                throw new \RuntimeException('Usage error');
            }
        };

        $entry = $this->invokeCollectDestinationSnapshot('azure_blob', $destination);

        $this->assertSame(['Usage error', 'List error'], $entry['errors']);
        $this->assertSame(0, $entry['backups_count']);
        $this->assertSame(0, $entry['used_bytes']);
        $this->assertNull($entry['quota_bytes']);
        $this->assertNull($entry['free_bytes']);
    }

    public function test_collect_remote_storage_metrics_triggers_warning_for_each_destination(): void
    {
        $now = current_time('timestamp');

        bjlg_update_option('bjlg_monitoring_settings', [
            'storage_quota_warning_threshold' => 70,
            'remote_metrics_ttl_minutes' => 15,
        ]);

        bjlg_update_option(BJLG_Remote_Storage_Metrics::OPTION_KEY, [
            'generated_at' => $now,
            'destinations' => [
                [
                    'id' => 'aws_s3',
                    'name' => 'Amazon S3',
                    'used_bytes' => 90,
                    'quota_bytes' => 100,
                    'free_bytes' => 10,
                    'connected' => true,
                ],
                [
                    'id' => 'azure_blob',
                    'name' => 'Azure Blob',
                    'used_bytes' => 700,
                    'quota_bytes' => 800,
                    'free_bytes' => 100,
                    'connected' => true,
                ],
                [
                    'id' => 'backblaze_b2',
                    'name' => 'Backblaze B2',
                    'used_bytes' => 450,
                    'quota_bytes' => 500,
                    'free_bytes' => 50,
                    'connected' => true,
                ],
            ],
        ]);

        bjlg_update_option(BJLG_Remote_Storage_Metrics::WARNING_DIGEST_OPTION, []);

        $warnings = [];
        add_action('bjlg_storage_warning', static function ($payload) use (&$warnings) {
            $warnings[] = $payload;
        }, 9, 1);

        $admin = new \BJLG\BJLG_Admin_Advanced();
        $method = new ReflectionMethod(\BJLG\BJLG_Admin_Advanced::class, 'collect_remote_storage_metrics');
        $method->setAccessible(true);
        $metrics = $method->invoke($admin);

        $this->assertCount(3, $warnings);
        $this->assertSame(['aws_s3', 'azure_blob', 'backblaze_b2'], array_map(static function ($payload) {
            return $payload['destination_id'] ?? '';
        }, $warnings));

        foreach ($warnings as $payload) {
            $this->assertGreaterThan(0, $payload['ratio']);
            $this->assertSame(70.0, $payload['threshold_percent']);
        }

        $digest = bjlg_get_option(BJLG_Remote_Storage_Metrics::WARNING_DIGEST_OPTION, []);
        $this->assertSame([
            'aws_s3' => $now,
            'azure_blob' => $now,
            'backblaze_b2' => $now,
        ], $digest);

        $this->assertIsArray($metrics);
        $this->assertSame(3, count($metrics['destinations']));
        foreach ($metrics['destinations'] as $entry) {
            $this->assertArrayHasKey('utilization_ratio', $entry);
            $this->assertGreaterThan(0, $entry['utilization_ratio']);
        }
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

    public function test_refresh_snapshot_includes_growth_projection(): void
    {
        bjlg_update_option('bjlg_monitoring_settings', [
            'storage_quota_warning_threshold' => 80,
            'remote_metrics_ttl_minutes' => 30,
        ]);

        $previous_time = time() - DAY_IN_SECONDS;
        bjlg_update_option(BJLG_Remote_Storage_Metrics::OPTION_KEY, [
            'generated_at' => $previous_time,
            'destinations' => [
                [
                    'id' => 'stub',
                    'used_bytes' => 1000,
                    'quota_bytes' => 5000,
                    'refreshed_at' => $previous_time,
                ],
            ],
            'threshold_percent' => 80.0,
        ]);

        add_filter('bjlg_known_destination_ids', static fn() => ['stub']);

        $destination = $this->createDestination([
            'used_bytes' => 2000,
            'quota_bytes' => 5000,
        ], [
            ['size' => 1000],
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
        $this->assertNotEmpty($snapshot['destinations']);
        $entry = $snapshot['destinations'][0];

        $this->assertArrayHasKey('daily_delta_bytes', $entry);
        $this->assertNotNull($entry['daily_delta_bytes']);
        $this->assertNotEmpty($entry['forecast_label']);
        $this->assertNotNull($entry['days_to_threshold']);
        $this->assertNotSame('', $entry['days_to_threshold_label']);
        $this->assertArrayHasKey('projection_intent', $entry);
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
