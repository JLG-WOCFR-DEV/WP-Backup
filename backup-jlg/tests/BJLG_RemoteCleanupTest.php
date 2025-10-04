<?php

namespace phpseclib3\Exception {
    if (!class_exists(UnableToConnectException::class)) {
        class UnableToConnectException extends \Exception {}
    }
}

namespace phpseclib3\Crypt {
    if (!class_exists(PublicKeyLoader::class)) {
        class PublicKeyLoader {
            public static function load($key, $password = false)
            {
                return $key;
            }
        }
    }
}

namespace phpseclib3\Net {
    if (!class_exists(SFTP::class)) {
        class SFTP {
            public const SOURCE_LOCAL_FILE = 1;
            public const TYPE_REGULAR = 1;

            public array $files = [];
            public array $deleted = [];

            public function __construct($host, $port = 22)
            {
            }

            public function login($username, $credential)
            {
                return true;
            }

            public function rawlist($path)
            {
                return $this->files;
            }

            public function delete($path)
            {
                $this->deleted[] = $path;

                return true;
            }

            public function put($remote_file, $local_file, $mode)
            {
                return true;
            }

            public function is_dir($path)
            {
                return false;
            }

            public function mkdir($path)
            {
                return true;
            }

            public function getServerPublicHostKey()
            {
                return null;
            }

            public function pwd()
            {
                return '/';
            }
        }
    }
}

namespace BJLG\Tests {

use BJLG\BJLG_AWS_S3;
use BJLG\BJLG_Debug;
use BJLG\BJLG_Google_Drive;
use BJLG\BJLG_SFTP;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class BJLG_RemoteCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BJLG_Debug::$logs = [];
        $GLOBALS['bjlg_test_options'] = [];
    }

    public function test_google_drive_prune_remote_backups_deletes_expected_files(): void
    {
        $now = time();

        $GLOBALS['bjlg_test_options']['bjlg_gdrive_settings'] = [
            'client_id' => 'client',
            'client_secret' => 'secret',
            'folder_id' => 'folder',
            'enabled' => true,
        ];
        $GLOBALS['bjlg_test_options']['bjlg_gdrive_token'] = [
            'refresh_token' => 'refresh',
            'access_token' => 'token',
        ];

        $client = new class {
            public function setClientId($id) {}
            public function setClientSecret($secret) {}
            public function setRedirectUri($uri) {}
            public function setAccessType($type) {}
            public function setPrompt($prompt) {}
            public function setScopes($scopes) {}
            public function setAccessToken($token) {}
            public function isAccessTokenExpired()
            {
                return false;
            }
        };

        $files_resource = new class {
            public array $deleted = [];
            private array $pages;

            public function __construct()
            {
                $this->pages = [];
            }

            public function set_pages(array $pages): void
            {
                $this->pages = $pages;
            }

            public function listFiles($params)
            {
                if (empty($this->pages)) {
                    return new class {
                        public function getFiles()
                        {
                            return [];
                        }

                        public function getNextPageToken()
                        {
                            return null;
                        }
                    };
                }

                return array_shift($this->pages);
            }

            public function delete($id): void
            {
                $this->deleted[] = $id;
            }
        };

        $files_resource->set_pages([
            new class ($now) {
                private array $files;

                public function __construct($now)
                {
                    $this->files = [
                        [
                            'id' => 'recent-1',
                            'name' => 'backup-recent-1.zip',
                            'createdTime' => gmdate('c', $now - DAY_IN_SECONDS),
                            'modifiedTime' => gmdate('c', $now - DAY_IN_SECONDS),
                            'size' => 100,
                        ],
                        [
                            'id' => 'recent-2',
                            'name' => 'backup-recent-2.zip',
                            'createdTime' => gmdate('c', $now - 3 * DAY_IN_SECONDS),
                            'modifiedTime' => gmdate('c', $now - 3 * DAY_IN_SECONDS),
                            'size' => 100,
                        ],
                        [
                            'id' => 'recent-3',
                            'name' => 'backup-recent-3.zip',
                            'createdTime' => gmdate('c', $now - 8 * DAY_IN_SECONDS),
                            'modifiedTime' => gmdate('c', $now - 8 * DAY_IN_SECONDS),
                            'size' => 100,
                        ],
                        [
                            'id' => 'old',
                            'name' => 'backup-old.zip',
                            'createdTime' => gmdate('c', $now - 20 * DAY_IN_SECONDS),
                            'modifiedTime' => gmdate('c', $now - 20 * DAY_IN_SECONDS),
                            'size' => 100,
                        ],
                    ];
                }

                public function getFiles()
                {
                    return $this->files;
                }

                public function getNextPageToken()
                {
                    return null;
                }
            },
        ]);

        $drive_service_factory = function () use ($files_resource) {
            return new class ($files_resource) {
                public $files;

                public function __construct($resource)
                {
                    $this->files = $resource;
                }
            };
        };

        $connector = new BJLG_Google_Drive(
            static function () use ($client) {
                return $client;
            },
            $drive_service_factory,
            null,
            null,
            static function () use ($now) {
                return $now;
            }
        );

        $sdk_property = new ReflectionProperty(BJLG_Google_Drive::class, 'sdk_available');
        $sdk_property->setAccessible(true);
        $sdk_property->setValue($connector, true);

        $result = $connector->prune_remote_backups(2, 10);

        $this->assertSame(2, $result['deleted']);
        $this->assertEmpty($result['errors']);
        $this->assertEqualsCanonicalizing(['backup-recent-3.zip', 'backup-old.zip'], $result['deleted_items']);
        $this->assertEqualsCanonicalizing(['recent-3', 'old'], $files_resource->deleted);
    }

    public function test_aws_s3_prune_remote_backups_deletes_expected_objects(): void
    {
        $now = time();

        $GLOBALS['bjlg_test_options']['bjlg_s3_settings'] = [
            'access_key' => 'key',
            'secret_key' => 'secret',
            'region' => 'eu-west-3',
            'bucket' => 'bucket',
            'object_prefix' => 'backups',
            'enabled' => true,
        ];

        $responses = [
            [
                'response' => ['code' => 200, 'message' => 'OK'],
                'body' => $this->buildS3ListingResponse($now),
            ],
        ];

        $requests = [];

        $request_handler = static function ($url, array $args) use (&$responses, &$requests) {
            $requests[] = ['url' => $url, 'args' => $args];

            if ($args['method'] === 'GET') {
                $response = array_shift($responses);
                $response = $response ?? ['response' => ['code' => 200, 'message' => 'OK'], 'body' => ''];

                return $response;
            }

            return ['response' => ['code' => 204, 'message' => 'No Content'], 'body' => ''];
        };

        $connector = new BJLG_AWS_S3($request_handler, static function () use ($now) {
            return $now;
        });

        $result = $connector->prune_remote_backups(2, 10);

        $this->assertSame(2, $result['deleted']);
        $this->assertEmpty($result['errors']);
        $this->assertEqualsCanonicalizing(['backup-recent-3.zip', 'backup-old.zip'], $result['deleted_items']);

        $delete_requests = array_filter($requests, static function ($request) {
            return $request['args']['method'] === 'DELETE';
        });

        $deleted_keys = array_map(static function ($request) {
            return $request['url'];
        }, $delete_requests);

        $this->assertCount(2, $deleted_keys);
        $this->assertEqualsCanonicalizing(
            [
                'https://bucket.s3.eu-west-3.amazonaws.com/backups/backup-recent-3.zip',
                'https://bucket.s3.eu-west-3.amazonaws.com/backups/backup-old.zip',
            ],
            $deleted_keys
        );
    }

    public function test_sftp_prune_remote_backups_deletes_expected_files(): void
    {
        $now = time();

        $GLOBALS['bjlg_test_options']['bjlg_sftp_settings'] = [
            'host' => 'example.test',
            'port' => 22,
            'username' => 'user',
            'password' => 'pass',
            'private_key' => '',
            'remote_path' => 'remote',
            'enabled' => true,
        ];

        $connection = new class ($now) extends \phpseclib3\Net\SFTP {
            private int $baseTime;
            public array $files = [];
            public array $deleted = [];

            public function __construct($now)
            {
                $this->baseTime = $now;
                $this->files = [
                    'backup-recent-1.zip' => ['mtime' => $this->baseTime - DAY_IN_SECONDS, 'size' => 100],
                    'backup-recent-2.zip' => ['mtime' => $this->baseTime - 3 * DAY_IN_SECONDS, 'size' => 100],
                    'backup-recent-3.zip' => ['mtime' => $this->baseTime - 8 * DAY_IN_SECONDS, 'size' => 100],
                    'backup-old.zip' => ['mtime' => $this->baseTime - 20 * DAY_IN_SECONDS, 'size' => 100],
                ];
            }

            public function login($username, ...$args)
            {
                return true;
            }

            public function rawlist($dir = '.', $recursive = false)
            {
                return $this->files;
            }

            public function delete($path, $recursive = true)
            {
                $this->deleted[] = $path;

                return true;
            }

            public function put($remote_file, $data, $mode = self::SOURCE_STRING, $start = -1, $local_start = -1, $progressCallback = null)
            {
                return true;
            }

            public function is_dir($path)
            {
                return false;
            }

            public function mkdir($dir, $mode = -1, $recursive = false)
            {
                return true;
            }

            public function getServerPublicHostKey()
            {
                return null;
            }
        };

        $connection_factory = static function () use ($connection) {
            return $connection;
        };

        $connector = new BJLG_SFTP($connection_factory, static function () use ($now) {
            return $now;
        });

        $result = $connector->prune_remote_backups(2, 10);

        $this->assertSame(2, $result['deleted']);
        $this->assertEmpty($result['errors']);
        $this->assertEqualsCanonicalizing(['backup-recent-3.zip', 'backup-old.zip'], $result['deleted_items']);

        $this->assertEqualsCanonicalizing([
            'remote/backup-recent-3.zip',
            'remote/backup-old.zip',
        ], $connection->deleted);
    }

    private function buildS3ListingResponse(int $now): string
    {
        $entries = [
            ['backups/backup-recent-1.zip', $now - DAY_IN_SECONDS],
            ['backups/backup-recent-2.zip', $now - 3 * DAY_IN_SECONDS],
            ['backups/backup-recent-3.zip', $now - 8 * DAY_IN_SECONDS],
            ['backups/backup-old.zip', $now - 20 * DAY_IN_SECONDS],
        ];

        $xml = "<ListBucketResult>";
        foreach ($entries as [$key, $timestamp]) {
            $xml .= sprintf(
                '<Contents><Key>%s</Key><LastModified>%s</LastModified><Size>100</Size></Contents>',
                $key,
                gmdate('Y-m-d\TH:i:s.000\Z', $timestamp)
            );
        }
        $xml .= '<IsTruncated>false</IsTruncated>';
        $xml .= '</ListBucketResult>';

        return $xml;
    }
}
}
