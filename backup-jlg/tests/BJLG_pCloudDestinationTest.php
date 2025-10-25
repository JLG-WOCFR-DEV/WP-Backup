<?php
declare(strict_types=1);

use BJLG\BJLG_pCloud;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-pcloud.php';

final class BJLG_pCloudDestinationTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests = [];
        $GLOBALS['bjlg_test_options'] = [];
    }

    public function test_upload_file_streams_archive_to_pcloud(): void
    {
        $handler = function (string $url, array $args) {
            TestCase::assertArrayHasKey('body', $args);
            TestCase::assertTrue(is_resource($args['body']), 'pCloud upload should provide a stream body.');
            TestCase::assertSame('stream', get_resource_type($args['body']));
            $contents = stream_get_contents($args['body']);
            TestCase::assertIsString($contents);
            TestCase::assertSame('pcloud-content', $contents);
            rewind($args['body']);

            $record_args = $args;
            $record_args['__body_contents'] = $contents;
            $this->requests[] = ['url' => $url, 'args' => $record_args];

            return [
                'response' => ['code' => 200],
                'body' => json_encode(['result' => 0]),
            ];
        };

        $destination = new BJLG_pCloud($handler, static function (): int {
            return 1_700_000_000;
        });

        bjlg_update_option('bjlg_pcloud_settings', [
            'access_token' => 'pc-token',
            'folder' => '/Archives',
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        self::assertIsString($file);
        file_put_contents($file, 'pcloud-content');

        $destination->upload_file($file, 'task-789');

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];

        $this->assertSame('https://api.pcloud.com/uploadfile', $request['url']);
        $this->assertSame('POST', strtoupper($request['args']['method']));
        $this->assertSame('pcloud-content', $request['args']['__body_contents']);

        $headers = $request['args']['headers'];
        $this->assertSame('Bearer pc-token', $headers['Authorization']);
        $this->assertSame('/Archives/' . basename($file), $headers['X-PCloud-Path']);
        $this->assertSame('1', $headers['X-PCloud-Overwrite']);
        $this->assertArrayHasKey('Content-Length', $headers);
        $this->assertSame((string) filesize($file), $headers['Content-Length']);

        if (is_file($file)) {
            unlink($file);
        }
    }

    public function test_prune_remote_backups_removes_expired_files(): void
    {
        $now = 1_700_000_000;

        $handler = function (string $url, array $args) use ($now) {
            $this->requests[] = ['url' => $url, 'args' => $args];

            if (strpos($url, 'listfolder') !== false) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode([
                        'metadata' => [
                            'contents' => [
                                [
                                    'fileid' => '1001',
                                    'name' => 'backup-old.zip',
                                    'path' => '/Archives/backup-old.zip',
                                    'modified' => gmdate('c', $now - 12 * DAY_IN_SECONDS),
                                    'size' => 100,
                                ],
                                [
                                    'fileid' => '1002',
                                    'name' => 'backup-new.zip',
                                    'path' => '/Archives/backup-new.zip',
                                    'modified' => gmdate('c', $now - 2 * DAY_IN_SECONDS),
                                    'size' => 200,
                                ],
                            ],
                        ],
                    ]),
                ];
            }

            if (strpos($url, 'deletefile') !== false) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode(['result' => 0]),
                ];
            }

            return [
                'response' => ['code' => 200],
                'body' => '{}',
            ];
        };

        $destination = new BJLG_pCloud($handler, static function () use ($now): int {
            return $now;
        });

        bjlg_update_option('bjlg_pcloud_settings', [
            'access_token' => 'pc-token',
            'folder' => '/Archives',
            'enabled' => true,
        ]);

        $result = $destination->prune_remote_backups(1, 5);

        $this->assertSame(1, $result['deleted']);
        $this->assertContains('backup-old.zip', $result['deleted_items']);
        $this->assertSame(2, $result['inspected']);

        $this->assertGreaterThanOrEqual(2, count($this->requests));

        $deleteRequest = $this->requests[1];
        $payload = json_decode((string) $deleteRequest['args']['body'], true);
        $this->assertSame('1001', $payload['fileid']);
    }
}
