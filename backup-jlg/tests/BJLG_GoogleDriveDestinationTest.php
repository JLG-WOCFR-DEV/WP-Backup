<?php
declare(strict_types=1);

use BJLG\BJLG_Google_Drive;
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

        $destination = $this->createDestination($client, $drive_service);

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
        $this->assertSame('multipart', $drive_files->lastParams['uploadType']);
        $this->assertSame('application/zip', $drive_files->lastParams['mimeType']);
        $this->assertSame(strlen('backup-content'), strlen($drive_files->lastParams['data']));
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
        $destination = $this->createDestination($client, $drive_service);

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

        $destination = $this->createDestination($client, new FakeDriveService($drive_files));

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

        $destination = $this->createDestination($client, new FakeDriveService(new FakeDriveFiles()));

        update_option('bjlg_gdrive_settings', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'folder_id' => '',
            'enabled' => true,
        ]);

        update_option('bjlg_gdrive_state', 'state-token');

        $_GET['bjlg_gdrive_auth'] = '1';
        $_GET['code'] = 'auth-code';
        $_GET['state'] = 'state-token';

        $destination->handle_oauth_callback();

        $token = get_option('bjlg_gdrive_token');
        $this->assertSame('auth-token', $token['access_token']);
        $this->assertSame('auth-refresh', $token['refresh_token']);
        $this->assertSame('auth-code', $client->authCodeReceived);
        $this->assertSame('', get_option('bjlg_gdrive_state', ''));
    }

    public function test_handle_oauth_callback_ignores_invalid_state(): void
    {
        $client = new FakeGoogleClient();
        $destination = $this->createDestination($client, new FakeDriveService(new FakeDriveFiles()));

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

    private function createDestination(FakeGoogleClient $client, FakeDriveService $drive_service): BJLG_Google_Drive
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

    /** @var \Exception|null */
    public $exception = null;

    public function create($metadata, $params)
    {
        if ($this->exception instanceof \Exception) {
            throw $this->exception;
        }

        $this->lastMetadata = $metadata;
        $this->lastParams = $params;

        $file = new DriveFile();
        $file->setId('file-123');
        $file->setName($metadata->getName());
        $file->setSize((string) strlen($params['data'] ?? ''));

        return $file;
    }
}
