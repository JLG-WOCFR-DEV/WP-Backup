<?php
declare(strict_types=1);

use BJLG\BJLG_OneDrive;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-onedrive.php';

final class BJLG_OneDriveDestinationTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests = [];
        $GLOBALS['bjlg_test_options'] = [];
    }

    public function test_upload_file_puts_archive_in_onedrive(): void
    {
        $handler = function (string $url, array $args) {
            $this->requests[] = ['url' => $url, 'args' => $args];

            return [
                'response' => ['code' => 201],
                'body' => json_encode(['id' => 'item-id']),
            ];
        };

        $destination = new BJLG_OneDrive($handler, static function (): int {
            return 1_700_000_000;
        });

        bjlg_update_option('bjlg_onedrive_settings', [
            'access_token' => 'access-token',
            'folder' => '/Archives',
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        self::assertIsString($file);
        file_put_contents($file, 'onedrive-data');

        $destination->upload_file($file, 'task-456');

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];

        $this->assertSame('PUT', $request['args']['method']);
        $this->assertStringContainsString('/me/drive/root:/Archives/' . basename($file) . ':/content', $request['url']);
        $this->assertSame('onedrive-data', $request['args']['body']);

        $headers = $request['args']['headers'];
        $this->assertSame('Bearer access-token', $headers['Authorization']);
        $this->assertSame('application/octet-stream', $headers['Content-Type']);

        if (is_file($file)) {
            unlink($file);
        }
    }

    public function test_prune_remote_backups_removes_extra_items(): void
    {
        $now = 1_700_000_000;

        $handler = function (string $url, array $args) use ($now) {
            $this->requests[] = ['url' => $url, 'args' => $args];

            if (strpos($url, '/children') !== false) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode([
                        'value' => [
                            [
                                'id' => 'id-old',
                                'name' => 'backup-old.zip',
                                'file' => ['mimeType' => 'application/zip'],
                                'lastModifiedDateTime' => gmdate('c', $now - 9 * DAY_IN_SECONDS),
                                'size' => 123,
                            ],
                            [
                                'id' => 'id-new',
                                'name' => 'backup-new.zip',
                                'file' => ['mimeType' => 'application/zip'],
                                'lastModifiedDateTime' => gmdate('c', $now - 2 * DAY_IN_SECONDS),
                                'size' => 456,
                            ],
                        ],
                    ]),
                ];
            }

            if (strpos($url, '/drive/items/') !== false && strtoupper($args['method']) === 'DELETE') {
                return [
                    'response' => ['code' => 204],
                    'body' => '',
                ];
            }

            return [
                'response' => ['code' => 200],
                'body' => '{}',
            ];
        };

        $destination = new BJLG_OneDrive($handler, static function () use ($now): int {
            return $now;
        });

        bjlg_update_option('bjlg_onedrive_settings', [
            'access_token' => 'access-token',
            'folder' => '/Archives',
            'enabled' => true,
        ]);

        $result = $destination->prune_remote_backups(1, 5);

        $this->assertSame(1, $result['deleted']);
        $this->assertContains('backup-old.zip', $result['deleted_items']);
        $this->assertSame(2, $result['inspected']);

        $this->assertGreaterThanOrEqual(2, count($this->requests));

        $deleteRequest = $this->requests[1];
        $this->assertSame('DELETE', strtoupper($deleteRequest['args']['method']));
        $this->assertStringContainsString('id-old', $deleteRequest['url']);
    }
}
