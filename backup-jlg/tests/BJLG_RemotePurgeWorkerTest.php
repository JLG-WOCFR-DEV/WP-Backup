<?php

declare(strict_types=1);

use BJLG\BJLG_Destination_Interface;
use BJLG\BJLG_Incremental;
use BJLG\BJLG_Remote_Purge_Worker;
use PHPUnit\Framework\TestCase;

final class BJLG_RemotePurgeWorkerTest extends TestCase
{
    /** @var array<int,string> */
    private array $createdPaths = [];
    /** @var mixed */
    private $previousWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        BJLG_Debug::$logs = [];
        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            public function get_results($query, $output = ARRAY_A)
            {
                return [];
            }

            public function get_row($query, $output = ARRAY_A)
            {
                return ['Checksum' => 0];
            }
        };
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->previousWpdb !== null) {
            $GLOBALS['wpdb'] = $this->previousWpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }
        unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_destination_factory']);
        unset($GLOBALS['bjlg_test_hooks']['actions']['bjlg_remote_purge_completed']);
        unset($GLOBALS['bjlg_test_hooks']['actions']['bjlg_remote_purge_permanent_failure']);
        foreach ($this->createdPaths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        $this->createdPaths = [];
    }

    public function test_process_queue_retries_and_then_clears_completed_entry(): void
    {
        $worker = new BJLG_Remote_Purge_Worker();
        $this->prepareManifestWithDestinations(['fake']);

        $completedPayloads = [];
        add_action('bjlg_remote_purge_completed', static function ($file, $context) use (&$completedPayloads) {
            $completedPayloads[] = [$file, $context];
        }, 10, 2);

        $destination = $this->registerStubDestination(1);

        $worker->process_queue();

        $queue = (new BJLG_Incremental())->get_remote_purge_queue();
        $this->assertCount(1, $queue);
        $this->assertSame('retry', $queue[0]['status']);
        $this->assertSame(1, $queue[0]['attempts']);
        $this->assertGreaterThan(time(), $queue[0]['next_attempt_at']);
        $this->assertSame('fail', $queue[0]['last_error']);

        (new BJLG_Incremental())->update_remote_purge_entry($queue[0]['file'], [
            'next_attempt_at' => time(),
        ]);

        $worker->process_queue();

        $remaining = (new BJLG_Incremental())->get_remote_purge_queue();
        $this->assertEmpty($remaining);

        $this->assertCount(2, $destination->deleteCalls);
        $this->assertCount(1, $completedPayloads);
        $this->assertSame($queue[0]['file'], $completedPayloads[0][0]);
        $this->assertSame(['destinations' => ['fake'], 'attempts' => 2], $completedPayloads[0][1]);
    }

    public function test_process_queue_marks_entry_as_failed_after_max_attempts(): void
    {
        $worker = new BJLG_Remote_Purge_Worker();
        $this->prepareManifestWithDestinations(['fake']);

        $maxAttempts = (new ReflectionClass(BJLG_Remote_Purge_Worker::class))->getConstant('MAX_ATTEMPTS');

        $failures = [];
        add_action('bjlg_remote_purge_permanent_failure', static function ($file, $entry, $errors) use (&$failures) {
            $failures[] = [$file, $entry, $errors];
        }, 10, 3);

        $this->registerStubDestination($maxAttempts + 1);

        for ($i = 0; $i < $maxAttempts; $i++) {
            $worker->process_queue();

            if ($i < $maxAttempts - 1) {
                $queue = (new BJLG_Incremental())->get_remote_purge_queue();
                $this->assertSame('retry', $queue[0]['status']);
                (new BJLG_Incremental())->update_remote_purge_entry($queue[0]['file'], [
                    'next_attempt_at' => time(),
                ]);
            }
        }

        $queue = (new BJLG_Incremental())->get_remote_purge_queue();
        $this->assertCount(1, $queue);
        $entry = $queue[0];
        $this->assertSame('failed', $entry['status']);
        $this->assertSame($maxAttempts, $entry['attempts']);
        $this->assertSame(0, $entry['next_attempt_at']);
        $this->assertGreaterThan(0, $entry['failed_at']);
        $this->assertNotEmpty($entry['errors']);

        $this->assertCount(1, $failures);
        $this->assertSame($entry['file'], $failures[0][0]);
        $this->assertSame($entry['attempts'], $failures[0][1]['attempts']);
        $this->assertSame($entry['errors'], $failures[0][2]);
    }

    public function test_update_metrics_computes_forecast_projections(): void
    {
        $worker = new BJLG_Remote_Purge_Worker();
        $queue = [
            [
                'status' => 'pending',
                'registered_at' => time() - 200,
                'destinations' => ['alpha'],
            ],
            [
                'status' => 'pending',
                'registered_at' => time() - 120,
                'destinations' => ['alpha'],
            ],
        ];

        $results = [
            [
                'processed' => true,
                'outcome' => 'completed',
                'timestamp' => time() - 60,
                'destinations' => ['alpha'],
                'duration' => 100,
                'registered_at' => time() - 160,
            ],
            [
                'processed' => true,
                'outcome' => 'completed',
                'timestamp' => time() - 10,
                'destinations' => ['alpha'],
                'duration' => 50,
                'registered_at' => time() - 90,
            ],
        ];

        bjlg_update_option('bjlg_remote_purge_sla_metrics', []);

        $method = (new ReflectionClass(BJLG_Remote_Purge_Worker::class))->getMethod('update_metrics');
        $method->setAccessible(true);
        $method->invoke($worker, $queue, $results, time());

        $metrics = bjlg_get_option('bjlg_remote_purge_sla_metrics', []);
        $this->assertArrayHasKey('forecast', $metrics);
        $this->assertNotEmpty($metrics['forecast']);

        $forecast = $metrics['forecast'];
        $this->assertArrayHasKey('overall', $forecast);
        $overall = $forecast['overall'];
        $this->assertGreaterThan(0, $overall['forecast_seconds']);
        $this->assertNotEmpty($overall['forecast_label']);

        $destinations = $forecast['destinations'];
        $this->assertArrayHasKey('alpha', $destinations);
        $alpha = $destinations['alpha'];
        $this->assertSame(2, $alpha['pending']);
        $this->assertNotNull($alpha['forecast_seconds']);
        $this->assertNotEmpty($alpha['forecast_label']);
        $this->assertNotEmpty($alpha['history']);
    }

    /**
     * @param array<int,string> $destinations
     */
    private function prepareManifestWithDestinations(array $destinations): void
    {
        $destinations = array_values(array_unique(array_map('sanitize_key', $destinations)));

        $handler = new BJLG_Incremental();
        $full = $this->createBackupFile('full');
        $incremental = $this->createBackupFile('inc');

        $queue_entry = [
            'file' => basename($incremental),
            'destinations' => $destinations,
            'registered_at' => time(),
            'status' => 'pending',
            'attempts' => 0,
            'last_attempt_at' => 0,
            'next_attempt_at' => time(),
            'last_error' => '',
            'errors' => [],
            'failed_at' => 0,
        ];

        $reflection = new ReflectionClass(BJLG_Incremental::class);
        $property = $reflection->getProperty('last_backup_data');
        $property->setAccessible(true);
        $data = $property->getValue($handler);

        $data['full_backup'] = [
            'file' => basename($full),
            'path' => $full,
            'timestamp' => time() - 60,
            'size' => filesize($full),
            'components' => ['db'],
            'destinations' => $destinations,
        ];
        $data['incremental_backups'] = [];
        $data['remote_purge_queue'] = [$queue_entry];

        $property->setValue($handler, $data);

        $save_method = $reflection->getMethod('save_manifest');
        $save_method->setAccessible(true);
        $this->assertTrue($save_method->invoke($handler));

        $this->assertNotEmpty((new BJLG_Incremental())->get_remote_purge_queue());
    }

    private function createBackupFile(string $prefix): string
    {
        $path = BJLG_BACKUP_DIR . $prefix . '-' . uniqid('', true) . '.zip';
        file_put_contents($path, 'test');
        $this->createdPaths[] = $path;

        return $path;
    }

    private function registerStubDestination(int $failuresBeforeSuccess, ?array $quotaPayload = null, string $destinationId = 'fake')
    {
        $destination = new class($failuresBeforeSuccess, $quotaPayload, $destinationId) implements BJLG_Destination_Interface {
            public int $remainingFailures;
            /** @var array<int,string> */
            public array $deleteCalls = [];
            /** @var array<string,mixed>|null */
            private $quotaPayload;
            private string $identifier;

            public function __construct(int $remainingFailures, ?array $quotaPayload, string $identifier)
            {
                $this->remainingFailures = $remainingFailures;
                $this->quotaPayload = $quotaPayload;
                $this->identifier = $identifier;
            }

            public function get_id()
            {
                return $this->identifier;
            }

            public function get_name()
            {
                return 'Fake Destination';
            }

            public function is_connected()
            {
                return true;
            }

            public function disconnect(): void {}

            public function render_settings(): void {}

            public function upload_file($filepath, $task_id): void {}

            public function list_remote_backups()
            {
                return [];
            }

            public function prune_remote_backups($retain_by_number, $retain_by_age_days)
            {
                return [];
            }

            public function delete_remote_backup_by_name($filename)
            {
                $this->deleteCalls[] = $filename;
                if ($this->remainingFailures > 0) {
                    $this->remainingFailures--;

                    return ['success' => false, 'message' => 'fail'];
                }

                $result = ['success' => true];
                if (is_array($this->quotaPayload)) {
                    $result['quota'] = $this->quotaPayload;
                }

                return $result;
            }

            public function get_storage_usage()
            {
                return [];
            }
        };

        $registeredId = $destination->get_id();
        add_filter('bjlg_destination_factory', static function ($provided, $destinationId) use ($destination, $registeredId) {
            if ($destinationId === $registeredId) {
                return $destination;
            }

            return $provided;
        }, 10, 2);

        return $destination;
    }
}
