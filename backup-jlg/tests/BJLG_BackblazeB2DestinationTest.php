<?php
declare(strict_types=1);

use BJLG\BJLG_Backblaze_B2;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-backblaze-b2.php';

final class BJLG_BackblazeB2DestinationTest extends TestCase
{
    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requests = [];
        $GLOBALS['bjlg_test_options'] = [];
    }

    public function test_upload_file_uses_large_file_flow(): void
    {
        $sha1Parts = [];
        $handler = function (string $url, array $args) use (&$sha1Parts): array {
            $this->requests[] = ['url' => $url, 'args' => $args];

            if (strpos($url, 'b2_authorize_account') !== false) {
                return $this->jsonResponse([
                    'accountId' => '1234',
                    'authorizationToken' => 'authToken',
                    'apiUrl' => 'https://api001.backblazeb2.com',
                    'downloadUrl' => 'https://f001.backblazeb2.com',
                ]);
            }

            if (strpos($url, 'b2_start_large_file') !== false) {
                $this->assertSame('authToken', $args['headers']['Authorization']);
                return $this->jsonResponse([
                    'fileId' => '4_zFileId',
                ]);
            }

            if (strpos($url, 'b2_get_upload_part_url') !== false) {
                return $this->jsonResponse([
                    'uploadUrl' => 'https://upload.example.com/part',
                    'authorizationToken' => 'partToken',
                ]);
            }

            if (strpos($url, 'upload.example.com') !== false) {
                $this->assertSame('partToken', $args['headers']['Authorization']);
                $this->assertArrayHasKey('X-Bz-Part-Number', $args['headers']);
                $sha1Parts[] = $args['headers']['X-Bz-Content-Sha1'];

                return $this->jsonResponse([
                    'contentSha1' => $args['headers']['X-Bz-Content-Sha1'],
                ]);
            }

            if (strpos($url, 'b2_finish_large_file') !== false) {
                $body = json_decode((string) ($args['body'] ?? ''), true);
                $this->assertSame($sha1Parts, $body['partSha1Array']);
                return $this->jsonResponse([
                    'fileId' => '4_zFileId',
                ]);
            }

            return $this->jsonResponse([]);
        };

        $destination = $this->createDestination($handler);

        bjlg_update_option('bjlg_backblaze_b2_settings', [
            'key_id' => 'key123',
            'application_key' => 'secret456',
            'bucket_id' => 'bucket-1',
            'bucket_name' => 'backups-bucket',
            'object_prefix' => 'nightly',
            'chunk_size_mb' => 5,
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        self::assertIsString($file);
        $payload = random_bytes(6 * 1024 * 1024); // > 5MB
        file_put_contents($file, $payload);

        $destination->upload_file($file, 'task-b2');
        unlink($file);

        $upload_calls = array_values(array_filter($this->requests, static function (array $request): bool {
            return strpos($request['url'], 'upload.example.com') !== false;
        }));
        $this->assertCount(2, $upload_calls);

        foreach ($upload_calls as $index => $request) {
            $this->assertSame('POST', $request['args']['method']);
            $this->assertSame($index + 1, (int) $request['args']['headers']['X-Bz-Part-Number']);
        }
    }

    public function test_test_connection_stores_status(): void
    {
        $destination = $this->createDestination(function (string $url, array $args): array {
            $this->requests[] = ['url' => $url, 'args' => $args];

            return $this->jsonResponse([
                'accountId' => '1234',
                'authorizationToken' => 'authToken',
                'apiUrl' => 'https://api001.backblazeb2.com',
                'downloadUrl' => 'https://f001.backblazeb2.com',
            ]);
        });

        $destination->test_connection([
            'key_id' => 'key',
            'application_key' => 'secret',
            'bucket_id' => 'bucket',
            'bucket_name' => 'bucket-name',
            'enabled' => true,
        ]);

        $status = bjlg_get_option('bjlg_backblaze_b2_status');
        $this->assertSame('success', $status['last_result']);
        $this->assertStringContainsString('bucket-name', (string) $status['message']);
    }

    public function test_create_download_token_requests_authorization(): void
    {
        $handler = function (string $url, array $args): array {
            $this->requests[] = ['url' => $url, 'args' => $args];

            if (strpos($url, 'b2_authorize_account') !== false) {
                return $this->jsonResponse([
                    'accountId' => '1234',
                    'authorizationToken' => 'authToken',
                    'apiUrl' => 'https://api001.backblazeb2.com',
                    'downloadUrl' => 'https://f001.backblazeb2.com',
                ]);
            }

            if (strpos($url, 'b2_get_download_authorization') !== false) {
                return $this->jsonResponse([
                    'authorizationToken' => 'downloadToken',
                ]);
            }

            return $this->jsonResponse([]);
        };

        $destination = $this->createDestination($handler);

        bjlg_update_option('bjlg_backblaze_b2_settings', [
            'key_id' => 'key',
            'application_key' => 'secret',
            'bucket_id' => 'bucket',
            'bucket_name' => 'bucket-name',
            'object_prefix' => 'remote',
            'enabled' => true,
        ]);

        $token = $destination->create_download_token('backup.zip', 600);

        $this->assertSame('downloadToken', $token['authorization']);
        $this->assertStringContainsString('remote/backup.zip', $token['url']);
    }

    private function createDestination(?callable $handler = null): BJLG_Backblaze_B2
    {
        $time = static fn (): int => strtotime('2021-02-01T00:00:00Z');

        return new BJLG_Backblaze_B2($handler ?? $this->buildDefaultHandler(), $time);
    }

    private function buildDefaultHandler(): callable
    {
        return function (string $url, array $args): array {
            $this->requests[] = ['url' => $url, 'args' => $args];

            return $this->jsonResponse([
                'accountId' => '1234',
                'authorizationToken' => 'authToken',
                'apiUrl' => 'https://api001.backblazeb2.com',
                'downloadUrl' => 'https://f001.backblazeb2.com',
            ]);
        };
    }

    private function jsonResponse(array $data): array
    {
        return [
            'response' => [
                'code' => 200,
                'message' => 'OK',
            ],
            'body' => wp_json_encode($data),
        ];
    }
}
