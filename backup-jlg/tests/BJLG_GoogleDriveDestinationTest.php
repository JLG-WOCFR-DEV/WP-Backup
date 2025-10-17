<?php
declare(strict_types=1);

use BJLG\BJLG_Google_Drive;
use Exception;
use Google\Service\Drive\DriveFile;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-google-drive.php';

final class BJLG_GoogleDriveDestinationTest extends TestCase
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

    public function test_upload_file_sends_backup_to_google_drive(): void
    {
        $client = new FakeGoogleClient();
        $drive_files = new FakeDriveFiles();
        $drive_service = new FakeDriveService($drive_files);
        $media_factory = new FakeMediaUploadFactory();

        $destination = $this->createDestination($client, $drive_service, $media_factory);

        update_option('bjlg_gdrive_settings', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'folder_id' => 'folder-42',
            'enabled' => true,
        ]);

        update_option('bjlg_gdrive_token', [
            'access_token' => 'token',
            'refresh_token' => 'refresh-token',
            'created' => time(),
            'expires_in' => 3600,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        file_put_contents($file, 'backup-content');

        $destination->upload_file($file, 'task-1');

        $this->assertSame('folder-42', $drive_files->lastMetadata->getParents()[0]);
        $this->assertSame(basename($file), $drive_files->lastMetadata->getName());
        $this->assertSame('resumable', $drive_files->lastParams['uploadType']);
        $this->assertSame('application/zip', $drive_files->lastParams['mimeType']);
        $this->assertSame('id,name,size', $drive_files->lastParams['fields']);
        $this->assertTrue($drive_files->lastParams['supportsAllDrives']);
        $this->assertTrue($drive_files->lastParams['supportsTeamDrives']);

        $this->assertCount(1, $media_factory->uploads);
        $upload = $media_factory->uploads[0];
        $this->assertSame(filesize($file), $upload->fileSize);
        $this->assertSame('application/zip', $upload->mimeType);
        $this->assertSame(filesize($file), array_sum($upload->chunks));
        $this->assertSame(filesize($file), $upload->uploadedBytes);
    }

    public function test_upload_file_refreshes_token_when_expired(): void
    {
        $client = new FakeGoogleClient();
        $client->expired = true;
        $client->refreshTokenResponse = [
            'access_token' => 'fresh-token',
            'expires_in' => 3600,
        ];

        $drive_service = new FakeDriveService(new FakeDriveFiles());
        $destination = $this->createDestination($client, $drive_service, new FakeMediaUploadFactory());

        update_option('bjlg_gdrive_settings', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'folder_id' => '',
            'enabled' => true,
        ]);

        update_option('bjlg_gdrive_token', [
            'access_token' => 'old-token',
            'refresh_token' => 'refresh-token',
            'created' => time() - 7200,
            'expires_in' => 3600,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        file_put_contents($file, 'content');

        $destination->upload_file($file, 'task-refresh');

        $stored = get_option('bjlg_gdrive_token');
        $this->assertSame('fresh-token', $stored['access_token']);
        $this->assertSame('refresh-token', $stored['refresh_token']);
        $this->assertSame(['https://www.googleapis.com/auth/drive.file'], $client->scopes);
        $this->assertSame('offline', $client->config['access_type']);
    }

    public function test_upload_file_throws_exception_when_api_fails(): void
    {
        $client = new FakeGoogleClient();
        $drive_files = new FakeDriveFiles();
        $drive_files->exception = new \Exception('Boom');

        $destination = $this->createDestination($client, new FakeDriveService($drive_files), new FakeMediaUploadFactory());

        update_option('bjlg_gdrive_settings', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'folder_id' => '',
            'enabled' => true,
        ]);

        update_option('bjlg_gdrive_token', [
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'created' => time(),
            'expires_in' => 3600,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        file_put_contents($file, 'content');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Boom');

        $destination->upload_file($file, 'task-error');
    }

    public function test_handle_oauth_callback_stores_token_on_success(): void
    {
        $client = new FakeGoogleClient();
        $client->authTokenResponse = [
            'access_token' => 'auth-token',
            'refresh_token' => 'auth-refresh',
            'expires_in' => 3600,
        ];

        $destination = $this->createDestination($client, new FakeDriveService(new FakeDriveFiles()), new FakeMediaUploadFactory());

        update_option('bjlg_gdrive_settings', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'folder_id' => '',
            'enabled' => true,
        ]);

        update_option('bjlg_gdrive_state', 'state-token');

        update_option('bjlg_gdrive_status', [
            'last_result' => 'error',
            'tested_at' => 123,
            'message' => 'Une erreur précédente',
        ]);

        $_GET['bjlg_gdrive_auth'] = '1';
        $_GET['code'] = 'auth-code';
        $_GET['state'] = 'state-token';

        $destination->handle_oauth_callback();

        $token = get_option('bjlg_gdrive_token');
        $this->assertSame('auth-token', $token['access_token']);
        $this->assertSame('auth-refresh', $token['refresh_token']);
        $this->assertSame('auth-code', $client->authCodeReceived);
        $this->assertSame('', get_option('bjlg_gdrive_state', ''));
        $this->assertSame('success', $_GET['bjlg_notice']);
        $this->assertSame('Compte Google Drive connecté.', $_GET['bjlg_notice_message']);

        $status = get_option('bjlg_gdrive_status');
        $this->assertSame([
            'last_result' => null,
            'tested_at' => 0,
            'message' => '',
        ], $status);
    }

    public function test_handle_oauth_callback_ignores_invalid_state(): void
    {
        $client = new FakeGoogleClient();
        $destination = $this->createDestination($client, new FakeDriveService(new FakeDriveFiles()), new FakeMediaUploadFactory());

        update_option('bjlg_gdrive_settings', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'folder_id' => '',
            'enabled' => true,
        ]);

        update_option('bjlg_gdrive_state', 'expected-state');

        $_GET['bjlg_gdrive_auth'] = '1';
        $_GET['code'] = 'auth-code';
        $_GET['state'] = 'invalid-state';

        $destination->handle_oauth_callback();

        $this->assertSame([], get_option('bjlg_gdrive_token', []));
        $this->assertNull($client->authCodeReceived);
    }

    public function test_upload_file_streams_large_archive_in_chunks(): void
    {
        $client = new FakeGoogleClient();
        $drive_files = new FakeDriveFiles();
        $media_factory = new FakeMediaUploadFactory();
        $destination = $this->createDestination($client, new FakeDriveService($drive_files), $media_factory);

        update_option('bjlg_gdrive_settings', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'folder_id' => '',
            'enabled' => true,
        ]);

        update_option('bjlg_gdrive_token', [
            'access_token' => 'token',
            'refresh_token' => 'refresh-token',
            'created' => time(),
            'expires_in' => 3600,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        $handle = fopen($file, 'wb');
        $this->assertIsResource($handle);
        $large_size = 120 * 1024 * 1024; // 120 Mo
        ftruncate($handle, $large_size);
        fclose($handle);

        add_filter('bjlg_google_drive_chunk_size', static function ($size) {
            return 5 * 1024 * 1024;
        }, 10, 1);

        try {
            $destination->upload_file($file, 'task-large');
        } finally {
            unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_google_drive_chunk_size']);
        }

        $this->assertSame('resumable', $drive_files->lastParams['uploadType']);
        $this->assertNotEmpty($media_factory->uploads);
        $upload = $media_factory->uploads[0];

        $this->assertSame(5 * 1024 * 1024, $upload->chunkSize);
        $this->assertSame($large_size, $upload->fileSize);
        $this->assertGreaterThan(1, count($upload->chunks));
        $this->assertLessThan($large_size, max($upload->chunks));
        $this->assertSame($large_size, array_sum($upload->chunks));
    }

    public function test_upload_file_accepts_array_of_filepaths(): void
    {
        $client = new FakeGoogleClient();
        $drive_files = new FakeDriveFiles();
        $media_factory = new FakeMediaUploadFactory();

        $destination = $this->createDestination($client, new FakeDriveService($drive_files), $media_factory);

        update_option('bjlg_gdrive_settings', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'folder_id' => 'folder',
            'enabled' => true,
        ]);

        update_option('bjlg_gdrive_token', [
            'access_token' => 'token',
            'refresh_token' => 'refresh-token',
            'created' => time(),
            'expires_in' => 3600,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        file_put_contents($file, 'archive');

        try {
            $destination->upload_file([$file], 'task-array');
        } finally {
            @unlink($file);
        }

        $this->assertCount(1, $drive_files->createdRequests);
    }

    public function test_upload_file_handles_shared_drive_folder_without_exception(): void
    {
        $client = new FakeGoogleClient();
        $drive_files = new FakeDriveFiles();
        $drive_files->requireDriveSupport = true;
        $media_factory = new FakeMediaUploadFactory();

        $destination = $this->createDestination($client, new FakeDriveService($drive_files), $media_factory);

        update_option('bjlg_gdrive_settings', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'folder_id' => 'shared-folder-id',
            'enabled' => true,
        ]);

        update_option('bjlg_gdrive_token', [
            'access_token' => 'token',
            'refresh_token' => 'refresh-token',
            'created' => time(),
            'expires_in' => 3600,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        file_put_contents($file, 'shared-drive-backup');

        try {
            $destination->upload_file($file, 'task-shared-drive');
        } finally {
            @unlink($file);
        }

        $this->assertCount(1, $drive_files->createdRequests, 'Un seul envoi devrait être initié.');
        $this->assertTrue($drive_files->lastParams['supportsAllDrives']);
        $this->assertTrue($drive_files->lastParams['supportsTeamDrives']);
    }

    public function test_upload_file_array_aggregates_errors(): void
    {
        $client = new FakeGoogleClient();
        $drive_files = new FakeDriveFiles();
        $media_factory = new FakeMediaUploadFactory();

        $destination = $this->createDestination($client, new FakeDriveService($drive_files), $media_factory);

        update_option('bjlg_gdrive_settings', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'folder_id' => '',
            'enabled' => true,
        ]);

        update_option('bjlg_gdrive_token', [
            'access_token' => 'token',
            'refresh_token' => 'refresh-token',
            'created' => time(),
            'expires_in' => 3600,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        file_put_contents($file, 'content');

        $missing = $file . '.missing';

        try {
            $destination->upload_file([$file, $missing], 'task-errors');
            $this->fail('Une exception aurait dû être levée pour les envois multiples.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('Erreurs Google Drive', $exception->getMessage());
            $this->assertStringContainsString('introuvable', $exception->getMessage());
        } finally {
            @unlink($file);
        }

        $this->assertCount(1, $drive_files->createdRequests, 'Le premier fichier aurait dû être envoyé avant l\'échec.');
    }

    public function test_list_remote_backups_handles_shared_drive_folder(): void
    {
        $client = new FakeGoogleClient();
        $drive_files = new FakeDriveFiles();
        $drive_service = new FakeDriveService($drive_files);
        $media_factory = new FakeMediaUploadFactory();
        $destination = $this->createDestination($client, $drive_service, $media_factory);

        update_option('bjlg_gdrive_settings', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'folder_id' => 'drive-123:folder-456',
            'enabled' => true,
        ]);

        update_option('bjlg_gdrive_token', [
            'access_token' => 'token',
            'refresh_token' => 'refresh-token',
            'created' => time(),
            'expires_in' => 3600,
        ]);

        $now = time();
        $drive_files->queueListResponse([
            [
                'id' => 'shared-1',
                'name' => 'backup-shared.zip',
                'createdTime' => gmdate('c', $now - 120),
                'modifiedTime' => gmdate('c', $now - 60),
                'size' => 2048,
            ],
            [
                'id' => 'skip-me',
                'name' => 'notes.txt',
                'createdTime' => gmdate('c', $now - 30),
                'modifiedTime' => gmdate('c', $now - 30),
                'size' => 100,
            ],
        ]);

        $backups = $destination->list_remote_backups();

        $this->assertCount(1, $backups);
        $this->assertSame('shared-1', $backups[0]['id']);
        $this->assertSame('backup-shared.zip', $backups[0]['name']);

        $params = $drive_files->lastListParams;
        $this->assertIsArray($params);
        $this->assertArrayHasKey('supportsAllDrives', $params);
        $this->assertTrue($params['supportsAllDrives']);
        $this->assertArrayHasKey('includeItemsFromAllDrives', $params);
        $this->assertTrue($params['includeItemsFromAllDrives']);
        $this->assertSame('drive', $params['corpora']);
        $this->assertSame('drive-123', $params['driveId']);
        $this->assertStringContainsString("'folder-456' in parents", $params['q']);
    }

    public function test_delete_remote_backup_passes_shared_drive_flags(): void
    {
        $client = new FakeGoogleClient();
        $drive_files = new FakeDriveFiles();
        $drive_service = new FakeDriveService($drive_files);
        $destination = $this->createDestination($client, $drive_service, new FakeMediaUploadFactory());

        update_option('bjlg_gdrive_settings', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'folder_id' => 'drive-789:folder-000',
            'enabled' => true,
        ]);

        update_option('bjlg_gdrive_token', [
            'access_token' => 'token',
            'refresh_token' => 'refresh-token',
            'created' => time(),
            'expires_in' => 3600,
        ]);

        $now = time();
        $drive_files->queueListResponse([
            [
                'id' => 'delete-me',
                'name' => 'backup-team-drive.zip',
                'createdTime' => gmdate('c', $now - 3600),
                'modifiedTime' => gmdate('c', $now - 3600),
                'size' => 512,
            ],
        ]);

        $result = $destination->delete_remote_backup_by_name('backup-team-drive.zip');

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($drive_files->deleteCalls);

        $delete_call = $drive_files->deleteCalls[0];
        $this->assertSame('delete-me', $delete_call['id']);
        $this->assertArrayHasKey('supportsAllDrives', $delete_call['params']);
        $this->assertTrue($delete_call['params']['supportsAllDrives']);
        $this->assertArrayHasKey('supportsTeamDrives', $delete_call['params']);
        $this->assertTrue($delete_call['params']['supportsTeamDrives']);
    }

    private function createDestination(FakeGoogleClient $client, FakeDriveService $drive_service, FakeMediaUploadFactory $media_factory): BJLG_Google_Drive
    {
        $test_case = $this;

        return new BJLG_Google_Drive(
            static function () use ($client) {
                return $client;
            },
            static function ($provided_client) use ($client, $drive_service, $test_case) {
                $test_case->assertSame($client, $provided_client);

                return $drive_service;
            },
            static function () {
                return 'state-token';
            },
            static function ($provided_client, $request, string $mime_type, int $chunk_size) use ($media_factory, $test_case, $client) {
                $test_case->assertSame($client, $provided_client);

                return $media_factory($provided_client, $request, $mime_type, $chunk_size);
            }
        );
    }
}

final class FakeGoogleClient
{
    /** @var array<string, mixed> */
    public $config = [];

    /** @var array<int, string> */
    public $scopes = [];

    /** @var array<string, mixed> */
    public $accessToken = [];

    /** @var bool */
    public $expired = false;

    /** @var array<string, mixed> */
    public $authTokenResponse = [];

    /** @var array<string, mixed> */
    public $refreshTokenResponse = [];

    /** @var string|null */
    public $authCodeReceived = null;

    /** @var string|null */
    public $state = null;

    /** @var string|null */
    private $refreshToken = null;

    /** @var bool */
    public $defer = false;

    public function __construct()
    {
        $this->authTokenResponse = [
            'access_token' => 'auth-token',
            'refresh_token' => 'auth-refresh',
            'expires_in' => 3600,
        ];

        $this->refreshTokenResponse = [
            'access_token' => 'refresh-token',
            'refresh_token' => 'auth-refresh',
            'expires_in' => 3600,
        ];
    }

    public function setClientId($id): void
    {
        $this->config['client_id'] = $id;
    }

    public function setClientSecret($secret): void
    {
        $this->config['client_secret'] = $secret;
    }

    public function setRedirectUri($uri): void
    {
        $this->config['redirect_uri'] = $uri;
    }

    public function setAccessType($type): void
    {
        $this->config['access_type'] = $type;
    }

    public function setPrompt($prompt): void
    {
        $this->config['prompt'] = $prompt;
    }

    public function setScopes(array $scopes): void
    {
        $this->scopes = $scopes;
    }

    public function addScope($scope): void
    {
        $this->scopes[] = $scope;
    }

    public function setAccessToken($token): void
    {
        $this->accessToken = $token;
        if (isset($token['refresh_token'])) {
            $this->refreshToken = (string) $token['refresh_token'];
        }
    }

    public function isAccessTokenExpired(): bool
    {
        return $this->expired;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function fetchAccessTokenWithRefreshToken($refreshToken): array
    {
        $this->refreshToken = (string) $refreshToken;
        $this->accessToken = $this->refreshTokenResponse;

        return $this->refreshTokenResponse;
    }

    public function fetchAccessTokenWithAuthCode($code): array
    {
        $this->authCodeReceived = (string) $code;
        $this->refreshToken = $this->authTokenResponse['refresh_token'] ?? $this->refreshToken;
        $this->accessToken = $this->authTokenResponse;

        return $this->authTokenResponse;
    }

    public function createAuthUrl(): string
    {
        return 'https://example.com/auth?state=' . rawurlencode((string) $this->state);
    }

    public function setState($state): void
    {
        $this->state = (string) $state;
    }

    public function setDefer($defer): void
    {
        $this->defer = (bool) $defer;
    }
}

final class FakeDriveService
{
    /** @var FakeDriveFiles */
    public $files;

    public function __construct(FakeDriveFiles $files)
    {
        $this->files = $files;
    }
}

final class FakeDriveFiles
{
    /** @var DriveFile|null */
    public $lastMetadata = null;

    /** @var array<string, mixed>|null */
    public $lastParams = null;

    /** @var array<string, mixed>|null */
    public $lastListParams = null;

    /** @var \Exception|null */
    public $exception = null;

    /** @var array<int, FakeGoogleHttpRequest> */
    public $createdRequests = [];

    /** @var array<int, FakeDriveFileList> */
    private $listResponses = [];

    /** @var array<int, array{id:string,params:array<string,mixed>}> */
    public $deleteCalls = [];

    public function create($metadata, $params)
    {
        if ($this->exception instanceof \Exception) {
            throw $this->exception;
        }

        if ($this->requireDriveSupport) {
            if (empty($params['supportsAllDrives']) || empty($params['supportsTeamDrives'])) {
                throw new \Exception('Shared Drive support flags are missing.');
            }
        }

        $this->lastMetadata = $metadata;
        $this->lastParams = $params;

        $request = new FakeGoogleHttpRequest($metadata, $params);
        $this->createdRequests[] = $request;

        return $request;
    }

    public function queueListResponse(array $files, ?string $nextPageToken = null): void
    {
        $this->listResponses[] = new FakeDriveFileList($files, $nextPageToken);
    }

    public function listFiles($params)
    {
        if ($this->exception instanceof \Exception) {
            throw $this->exception;
        }

        $this->lastListParams = $params;

        if (!empty($this->listResponses)) {
            return array_shift($this->listResponses);
        }

        return new FakeDriveFileList([]);
    }

    public function delete($id, array $optParams = [])
    {
        if ($this->exception instanceof \Exception) {
            throw $this->exception;
        }

        $this->deleteCalls[] = [
            'id' => (string) $id,
            'params' => $optParams,
        ];
    }
}

final class FakeDriveFileList
{
    /** @var array<int, array<string, mixed>> */
    private $files;

    /** @var string|null */
    private $nextPageToken;

    /**
     * @param array<int, array<string, mixed>> $files
     */
    public function __construct(array $files, ?string $nextPageToken = null)
    {
        $this->files = $files;
        $this->nextPageToken = $nextPageToken;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getNextPageToken(): ?string
    {
        return $this->nextPageToken;
    }
}

final class FakeGoogleHttpRequest
{
    /** @var DriveFile */
    public $metadata;

    /** @var array<string, mixed> */
    public $params;

    public function __construct(DriveFile $metadata, array $params)
    {
        $this->metadata = $metadata;
        $this->params = $params;
    }
}

final class FakeMediaUploadFactory
{
    /** @var array<int, FakeGoogleMediaFileUpload> */
    public $uploads = [];

    public function __invoke($client, $request, string $mimeType, int $chunkSize): FakeGoogleMediaFileUpload
    {
        $upload = new FakeGoogleMediaFileUpload($client, $request, $mimeType, $chunkSize);
        $this->uploads[] = $upload;

        return $upload;
    }
}

final class FakeGoogleMediaFileUpload
{
    /** @var FakeGoogleClient */
    private $client;

    /** @var FakeGoogleHttpRequest */
    public $request;

    /** @var string */
    public $mimeType;

    /** @var int */
    public $chunkSize;

    /** @var int */
    public $fileSize = 0;

    /** @var array<int, int> */
    public $chunks = [];

    /** @var int */
    public $uploadedBytes = 0;

    public function __construct($client, FakeGoogleHttpRequest $request, string $mimeType, int $chunkSize)
    {
        $this->client = $client;
        $this->request = $request;
        $this->mimeType = $mimeType;
        $this->chunkSize = $chunkSize;
    }

    public function setFileSize($size): void
    {
        $this->fileSize = (int) $size;
    }

    public function nextChunk($chunk)
    {
        $length = strlen($chunk);
        $this->chunks[] = $length;
        $this->uploadedBytes += $length;

        if ($this->uploadedBytes >= $this->fileSize) {
            $file = new DriveFile();
            $file->setId('file-123');
            $file->setName($this->request->metadata->getName());
            $file->setSize((string) $this->fileSize);

            return $file;
        }

        return null;
    }
}
