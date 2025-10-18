<?php
declare(strict_types=1);

use BJLG\BJLG_Dropbox;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-dropbox.php';

final class BJLG_DropboxDestinationTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests = [];
        $GLOBALS['bjlg_test_options'] = [];
    }

    public function test_upload_file_sends_archive_to_dropbox(): void
    {
        $handler = function (string $url, array $args) {
            $this->requests[] = ['url' => $url, 'args' => $args];

            return [
                'response' => ['code' => 200],
                'body' => json_encode(['name' => 'uploaded.zip']),
            ];
        };

        $destination = new BJLG_Dropbox($handler, static function (): int {
            return 1_700_000_000;
        });

        bjlg_update_option('bjlg_dropbox_settings', [
            'access_token' => 'token-123',
            'folder' => '/Backups',
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        self::assertIsString($file);
        file_put_contents($file, 'dropbox-archive');

        $destination->upload_file($file, 'task-123');

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];

        $this->assertSame('https://content.dropboxapi.com/2/files/upload', $request['url']);
        $this->assertSame('POST', $request['args']['method']);
        $this->assertSame('dropbox-archive', $request['args']['body']);

        $headers = $request['args']['headers'];
        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertSame('Bearer token-123', $headers['Authorization']);
        $this->assertArrayHasKey('Dropbox-API-Arg', $headers);

        $apiArgs = json_decode((string) $headers['Dropbox-API-Arg'], true);
        $this->assertSame('/Backups/' . basename($file), $apiArgs['path']);
        $this->assertSame('application/octet-stream', $headers['Content-Type']);

        if (is_file($file)) {
            unlink($file);
        }
    }

    public function test_prune_remote_backups_deletes_old_archives(): void
    {
        $now = 1_700_000_000;

        $handler = function (string $url, array $args) use ($now) {
            $this->requests[] = ['url' => $url, 'args' => $args];

            if (strpos($url, 'list_folder') !== false) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode([
                        'entries' => [
                            [
                                '.tag' => 'file',
                                'id' => 'id-old',
                                'path_display' => '/Backups/backup-old.zip',
                                'server_modified' => gmdate('c', $now - 10 * DAY_IN_SECONDS),
                                'size' => 123,
                            ],
                            [
                                '.tag' => 'file',
                                'id' => 'id-new',
                                'path_display' => '/Backups/backup-new.zip',
                                'server_modified' => gmdate('c', $now - DAY_IN_SECONDS),
                                'size' => 456,
                            ],
                        ],
                    ]),
                ];
            }

            if (strpos($url, 'delete_v2') !== false) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode(['metadata' => ['id' => 'deleted']]),
                ];
            }

            return [
                'response' => ['code' => 200],
                'body' => '{}',
            ];
        };

        $destination = new BJLG_Dropbox($handler, static function () use ($now): int {
            return $now;
        });

        bjlg_update_option('bjlg_dropbox_settings', [
            'access_token' => 'token-123',
            'folder' => '/Backups',
            'enabled' => true,
        ]);

        $result = $destination->prune_remote_backups(1, 5);

        $this->assertSame(1, $result['deleted']);
        $this->assertContains('backup-old.zip', $result['deleted_items']);
        $this->assertSame(2, $result['inspected']);

        $this->assertGreaterThanOrEqual(2, count($this->requests));

        $deleteRequest = $this->requests[1];
        $payload = json_decode((string) $deleteRequest['args']['body'], true);
        $this->assertSame('/Backups/backup-old.zip', $payload['path']);
    }
}
