<?php
declare(strict_types=1);

use BJLG\BJLG_Azure_Blob;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-azure-blob.php';

final class BJLG_AzureBlobDestinationTest extends TestCase
{
    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requests = [];
        $GLOBALS['bjlg_test_options'] = [];
    }

    public function test_upload_file_splits_into_blocks_and_commits_list(): void
    {
        $handler = function (string $url, array $args): array {
            $this->requests[] = ['url' => $url, 'args' => $args];

            $code = 201;
            if (strpos($url, 'comp=list') !== false) {
                $code = 200;
            }

            return [
                'response' => [
                    'code' => $code,
                    'message' => 'Created',
                ],
                'body' => '',
            ];
        };

        $destination = $this->createDestination($handler);

        update_option('bjlg_azure_blob_settings', [
            'account_name' => 'myaccount',
            'account_key' => base64_encode('example-key-123456'),
            'container' => 'backups',
            'object_prefix' => 'archives',
            'chunk_size_mb' => 1,
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        self::assertIsString($file);
        $payload = random_bytes(1024 * 1024 + 128);
        $payload .= random_bytes(1024 * 512);
        file_put_contents($file, $payload);

        $destination->upload_file($file, 'task-azure');

        unlink($file);

        $this->assertGreaterThanOrEqual(3, count($this->requests));
        $block_requests = array_values(array_filter($this->requests, static function (array $request): bool {
            $parts = parse_url($request['url']);
            if (!isset($parts['query'])) {
                return false;
            }

            parse_str($parts['query'], $query);

            return isset($query['comp']) && $query['comp'] === 'block';
        }));
        $this->assertCount(2, $block_requests);

        foreach ($block_requests as $index => $request) {
            $this->assertSame('PUT', $request['args']['method']);
            $this->assertArrayHasKey('Authorization', $request['args']['headers']);
            $this->assertStringContainsString('SharedKey myaccount:', $request['args']['headers']['Authorization']);
            $this->assertSame('application/octet-stream', $request['args']['headers']['Content-Type']);
            $this->assertSame('BlockBlob', $request['args']['headers']['x-ms-blob-type']);
            $this->assertSame('task-azure', $request['args']['headers']['x-ms-meta-bjlg-task']);
            $this->assertGreaterThan(0, strlen((string) $request['args']['body']));
        }

        $commit_requests = array_values(array_filter($this->requests, static function (array $request): bool {
            $parts = parse_url($request['url']);
            if (!isset($parts['query'])) {
                return false;
            }

            parse_str($parts['query'], $query);

            return isset($query['comp']) && $query['comp'] === 'blocklist';
        }));
        $this->assertCount(1, $commit_requests);
        $commit = array_shift($commit_requests);
        $this->assertSame('application/xml', $commit['args']['headers']['Content-Type']);
        $this->assertStringContainsString('<BlockList>', $commit['args']['body']);
    }

    public function test_test_connection_updates_status_option(): void
    {
        $handler = function (string $url, array $args): array {
            $this->requests[] = ['url' => $url, 'args' => $args];

            return [
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'body' => '<EnumerationResults><Blobs /></EnumerationResults>',
            ];
        };

        $destination = $this->createDestination($handler);

        $destination->test_connection([
            'account_name' => 'demoaccount',
            'account_key' => base64_encode('demo-key'),
            'container' => 'demo-container',
            'object_prefix' => '',
            'enabled' => true,
        ]);

        $status = get_option('bjlg_azure_blob_status');
        $this->assertSame('success', $status['last_result']);
        $this->assertStringContainsString('demo-container', (string) $status['message']);
    }

    public function test_create_download_token_returns_sas_url(): void
    {
        $destination = $this->createDestination();

        update_option('bjlg_azure_blob_settings', [
            'account_name' => 'myaccount',
            'account_key' => base64_encode('another-secret-key'),
            'container' => 'backups',
            'object_prefix' => 'daily',
            'enabled' => true,
        ]);

        $token = $destination->create_download_token('backup.zip', 600);

        $this->assertStringContainsString('sig=', $token['url']);
        $this->assertStringContainsString('se=', $token['url']);
        $this->assertStringContainsString('/backups/daily/backup.zip?', $token['url']);
        $this->assertSame(1609459800, $token['expires_at']);
    }

    public function test_get_storage_usage_prefers_provider_snapshot(): void
    {
        $handler = function (string $url, array $args): array {
            $this->requests[] = ['url' => $url, 'args' => $args];

            if (strpos($url, 'comp=usage') !== false) {
                return [
                    'response' => [
                        'code' => 200,
                        'message' => 'OK',
                    ],
                    'body' => json_encode([
                        'usage' => [
                            'usedBytes' => 1000,
                            'quotaBytes' => 2000,
                            'freeBytes' => 1000,
                        ],
                    ]),
                ];
            }

            return [
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'body' => '<Blobs></Blobs>',
            ];
        };

        $destination = $this->createDestination($handler);

        update_option('bjlg_azure_blob_settings', [
            'account_name' => 'myaccount',
            'account_key' => base64_encode('secret'),
            'container' => 'backups',
            'object_prefix' => '',
            'enabled' => true,
        ]);

        $usage = $destination->get_storage_usage();

        $this->assertSame(1000, $usage['used_bytes']);
        $this->assertSame(2000, $usage['quota_bytes']);
        $this->assertSame(1000, $usage['free_bytes']);
        $this->assertSame('provider', $usage['source']);
        $this->assertSame(strtotime('2021-01-01T00:00:00Z'), $usage['refreshed_at']);
    }

    public function test_get_storage_usage_falls_back_to_listing_when_snapshot_fails(): void
    {
        $handler = function (string $url, array $args): array {
            $this->requests[] = ['url' => $url, 'args' => $args];

            if (strpos($url, 'comp=usage') !== false) {
                return [
                    'response' => [
                        'code' => 500,
                        'message' => 'Error',
                    ],
                    'body' => 'error',
                ];
            }

            if (strpos($url, 'comp=list') !== false) {
                return [
                    'response' => [
                        'code' => 200,
                        'message' => 'OK',
                    ],
                    'body' => '<EnumerationResults><Blobs><Blob><Name>daily/backup-1.zip</Name><Properties><ContentLength>500</ContentLength><LastModified>Fri, 01 Jan 2021 00:00:00 GMT</LastModified></Properties></Blob><Blob><Name>daily/backup-2.zip</Name><Properties><ContentLength>700</ContentLength><LastModified>Fri, 01 Jan 2021 01:00:00 GMT</LastModified></Properties></Blob></Blobs></EnumerationResults>',
                ];
            }

            return [
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'body' => '',
            ];
        };

        $destination = $this->createDestination($handler);

        update_option('bjlg_azure_blob_settings', [
            'account_name' => 'myaccount',
            'account_key' => base64_encode('secret'),
            'container' => 'backups',
            'object_prefix' => 'daily',
            'enabled' => true,
        ]);

        $usage = $destination->get_storage_usage();

        $this->assertSame(1200, $usage['used_bytes']);
        $this->assertNull($usage['quota_bytes']);
        $this->assertNull($usage['free_bytes']);
        $this->assertSame('estimate', $usage['source']);
    }

    private function createDestination(?callable $handler = null): BJLG_Azure_Blob
    {
        $time = static fn (): int => strtotime('2021-01-01T00:00:00Z');

        return new BJLG_Azure_Blob($handler ?? $this->buildSuccessfulHandler(), $time);
    }

    private function buildSuccessfulHandler(): callable
    {
        return function (string $url, array $args): array {
            $this->requests[] = ['url' => $url, 'args' => $args];

            return [
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'body' => '',
            ];
        };
    }
}
