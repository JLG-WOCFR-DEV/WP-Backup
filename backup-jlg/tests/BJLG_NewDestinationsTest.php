<?php
declare(strict_types=1);

use BJLG\BJLG_Dropbox;
use BJLG\BJLG_OneDrive;
use BJLG\BJLG_PCloud;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-dropbox.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-onedrive.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-pcloud.php';

final class BJLG_NewDestinationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['bjlg_test_options'] = [];
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['bjlg_test_options'] = [];
        $_GET = [];
        $_POST = [];
        parent::tearDown();
    }

    public function test_dropbox_upload_and_prune(): void
    {
        $calls = [];
        $handler = function (string $url, array $args) use (&$calls) {
            $calls[] = ['url' => $url, 'args' => $args];

            if (strpos($url, 'list_folder') !== false) {
                $body = json_encode([
                    'entries' => [
                        [
                            '.tag' => 'file',
                            'id' => 'id:newest',
                            'name' => 'backup-20240103.zip',
                            'path_display' => '/backup-20240103.zip',
                            'server_modified' => '2024-01-03T12:00:00Z',
                            'size' => 1024,
                        ],
                        [
                            '.tag' => 'file',
                            'id' => 'id:middle',
                            'name' => 'backup-20240101.zip',
                            'path_display' => '/backup-20240101.zip',
                            'server_modified' => '2024-01-01T12:00:00Z',
                            'size' => 2048,
                        ],
                        [
                            '.tag' => 'file',
                            'id' => 'id:oldest',
                            'name' => 'backup-20231231.zip',
                            'path_display' => '/backup-20231231.zip',
                            'server_modified' => '2023-12-31T12:00:00Z',
                            'size' => 4096,
                        ],
                    ],
                ]);

                return [
                    'response' => ['code' => 200],
                    'body' => $body,
                ];
            }

            if (strpos($url, 'delete_v2') !== false) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode(['metadata' => []]),
                ];
            }

            return [
                'response' => ['code' => 200],
                'body' => '{}',
            ];
        };

        $time = static function (): int {
            return 1_700_000_000;
        };

        $destination = new BJLG_Dropbox($handler, $time);

        update_option('bjlg_dropbox_settings', [
            'access_token' => 'token',
            'folder' => '/Backups',
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg-dropbox');
        file_put_contents($file, 'archive-content');

        $destination->upload_file($file, 'task-dropbox');

        $this->assertNotEmpty($calls);
        $uploadCall = $calls[0];
        $this->assertSame('https://content.dropboxapi.com/2/files/upload', $uploadCall['url']);
        $this->assertSame('POST', $uploadCall['args']['method']);
        $this->assertSame('Bearer token', $uploadCall['args']['headers']['Authorization']);
        $apiArg = json_decode($uploadCall['args']['headers']['Dropbox-API-Arg'], true);
        $this->assertIsArray($apiArg);
        $this->assertSame('/Backups/' . basename($file), $apiArg['path']);

        $status = get_option('bjlg_dropbox_status');
        $this->assertIsArray($status);
        $this->assertSame('success', $status['last_result']);

        $result = $destination->prune_remote_backups(1, 0);

        $this->assertSame(3, $result['inspected']);
        $this->assertSame(2, $result['deleted']);
        $this->assertEmpty($result['errors']);
        $this->assertEqualsCanonicalizing(
            ['backup-20240101.zip', 'backup-20231231.zip'],
            $result['deleted_items']
        );

        $deleteCalls = array_values(array_filter($calls, static function ($call) {
            return strpos($call['url'], 'delete_v2') !== false;
        }));
        $this->assertCount(2, $deleteCalls);

        unlink($file);
    }

    public function test_onedrive_upload_and_prune(): void
    {
        $calls = [];
        $handler = function (string $url, array $args) use (&$calls) {
            $calls[] = ['url' => $url, 'args' => $args];

            if (strpos($url, '/children?') !== false) {
                $body = json_encode([
                    'value' => [
                        [
                            'id' => 'drive-new',
                            'name' => 'backup-20240103.zip',
                            'size' => 1200,
                            'file' => ['mimeType' => 'application/zip'],
                            'lastModifiedDateTime' => '2024-01-03T10:00:00Z',
                        ],
                        [
                            'id' => 'drive-old',
                            'name' => 'backup-20231230.zip',
                            'size' => 900,
                            'file' => ['mimeType' => 'application/zip'],
                            'lastModifiedDateTime' => '2023-12-30T10:00:00Z',
                        ],
                    ],
                ]);

                return [
                    'response' => ['code' => 200],
                    'body' => $body,
                ];
            }

            if (strpos($url, '/items/') !== false && strtoupper($args['method']) === 'DELETE') {
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

        $time = static function (): int {
            return 1_700_000_000;
        };

        $destination = new BJLG_OneDrive($handler, $time);

        update_option('bjlg_onedrive_settings', [
            'access_token' => 'token',
            'folder' => '/Sauvegardes',
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg-onedrive');
        file_put_contents($file, 'onedrive-content');

        $destination->upload_file($file, 'task-onedrive');

        $this->assertNotEmpty($calls);
        $uploadCall = $calls[0];
        $this->assertSame('https://graph.microsoft.com/v1.0/me/drive/root:/Sauvegardes/' . rawurlencode(basename($file)) . ':/content', $uploadCall['url']);
        $this->assertSame('PUT', strtoupper($uploadCall['args']['method']));
        $this->assertSame('Bearer token', $uploadCall['args']['headers']['Authorization']);

        $status = get_option('bjlg_onedrive_status');
        $this->assertIsArray($status);
        $this->assertSame('success', $status['last_result']);

        $result = $destination->prune_remote_backups(1, 0);

        $this->assertSame(2, $result['inspected']);
        $this->assertSame(1, $result['deleted']);
        $this->assertEmpty($result['errors']);
        $this->assertSame(['backup-20231230.zip'], $result['deleted_items']);

        $deleteCalls = array_values(array_filter($calls, static function ($call) {
            return strpos($call['url'], '/items/') !== false && isset($call['args']['method']) && strtoupper($call['args']['method']) === 'DELETE';
        }));
        $this->assertCount(1, $deleteCalls);

        unlink($file);
    }

    public function test_pcloud_upload_and_prune(): void
    {
        $calls = [];
        $handler = function (string $url, array $args) use (&$calls) {
            $calls[] = ['url' => $url, 'args' => $args];

            if (strpos($url, 'listfolder') !== false) {
                $body = json_encode([
                    'error' => 0,
                    'metadata' => [
                        'contents' => [
                            [
                                'fileid' => '1000',
                                'name' => 'backup-20240104.zip',
                                'path' => '/Backups/backup-20240104.zip',
                                'modified' => '2024-01-04T08:00:00Z',
                                'size' => 5000,
                            ],
                            [
                                'fileid' => '1001',
                                'name' => 'backup-20240102.zip',
                                'path' => '/Backups/backup-20240102.zip',
                                'modified' => '2024-01-02T08:00:00Z',
                                'size' => 4500,
                            ],
                            [
                                'fileid' => '1002',
                                'name' => 'backup-20231229.zip',
                                'path' => '/Backups/backup-20231229.zip',
                                'modified' => '2023-12-29T08:00:00Z',
                                'size' => 4300,
                            ],
                        ],
                    ],
                ]);

                return [
                    'response' => ['code' => 200],
                    'body' => $body,
                ];
            }

            if (strpos($url, 'deletefile') !== false) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode(['error' => 0]),
                ];
            }

            return [
                'response' => ['code' => 200],
                'body' => json_encode(['error' => 0]),
            ];
        };

        $time = static function (): int {
            return 1_700_000_000;
        };

        $destination = new BJLG_PCloud($handler, $time);

        update_option('bjlg_pcloud_settings', [
            'access_token' => 'token',
            'folder' => '/Backups',
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg-pcloud');
        file_put_contents($file, 'pcloud-content');

        $destination->upload_file($file, 'task-pcloud');

        $this->assertNotEmpty($calls);
        $uploadCall = $calls[0];
        $this->assertSame('https://api.pcloud.com/uploadfile', $uploadCall['url']);
        $this->assertSame('POST', strtoupper($uploadCall['args']['method']));
        $this->assertSame('Bearer token', $uploadCall['args']['headers']['Authorization']);
        $this->assertSame('/Backups/' . basename($file), $uploadCall['args']['headers']['X-PCloud-Path']);

        $status = get_option('bjlg_pcloud_status');
        $this->assertIsArray($status);
        $this->assertSame('success', $status['last_result']);

        $result = $destination->prune_remote_backups(1, 0);

        $this->assertSame(3, $result['inspected']);
        $this->assertSame(2, $result['deleted']);
        $this->assertEmpty($result['errors']);
        $this->assertEqualsCanonicalizing(
            ['backup-20240102.zip', 'backup-20231229.zip'],
            $result['deleted_items']
        );

        $deleteCalls = array_values(array_filter($calls, static function ($call) {
            return strpos($call['url'], 'deletefile') !== false;
        }));
        $this->assertCount(2, $deleteCalls);

        unlink($file);
    }
}
