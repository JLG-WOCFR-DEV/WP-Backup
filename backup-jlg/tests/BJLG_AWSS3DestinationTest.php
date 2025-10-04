<?php
declare(strict_types=1);

use BJLG\BJLG_AWS_S3;
use Exception;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-aws-s3.php';

final class BJLG_AWSS3DestinationTest extends TestCase
{
    /** @var array<int, array{url: string, args: array}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requests = [];
        $GLOBALS['bjlg_test_options'] = [];
    }

    public function test_upload_file_sends_signed_put_request(): void
    {
        $destination = $this->createDestination();

        update_option('bjlg_s3_settings', [
            'access_key' => 'AKIDEXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
            'bucket' => 'my-backups',
            'server_side_encryption' => 'AES256',
            'object_prefix' => 'backups',
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        self::assertNotFalse($file);
        file_put_contents($file, 'archive-content');

        $destination->upload_file($file, 'task-42');

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];

        $this->assertSame('PUT', $request['args']['method']);
        $this->assertSame('https://my-backups.s3.amazonaws.com/backups/' . basename($file), $request['url']);

        $headers = $request['args']['headers'];
        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringContainsString('Credential=AKIDEXAMPLE/20210101/us-east-1/s3/aws4_request', $headers['Authorization']);
        $this->assertSame('AES256', $headers['x-amz-server-side-encryption']);
        $this->assertSame('task-42', $headers['x-amz-meta-bjlg-task']);
        $this->assertArrayNotHasKey('x-amz-server-side-encryption-aws-kms-key-id', $headers);
        $this->assertSame('application/zip', $headers['Content-Type']);
        $this->assertSame((string) filesize($file), $headers['Content-Length']);
        $body = $request['args']['body'] ?? null;
        $this->assertTrue(is_resource($body) || gettype($body) === 'resource (closed)');
        $this->assertSame('UNSIGNED-PAYLOAD', $headers['X-Amz-Content-Sha256']);

        unlink($file);
    }

    public function test_upload_file_throws_exception_on_error_response(): void
    {
        $handler = function () {
            return [
                'response' => [
                    'code' => 403,
                    'message' => 'Forbidden',
                ],
                'body' => '',
            ];
        };

        $destination = $this->createDestination($handler);

        update_option('bjlg_s3_settings', [
            'access_key' => 'AK',
            'secret_key' => 'SECRET',
            'region' => 'eu-west-3',
            'bucket' => 'demo-bucket',
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        self::assertNotFalse($file);
        file_put_contents($file, 'content');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('403');

        try {
            $destination->upload_file($file, 'task');
        } finally {
            if (is_string($file) && file_exists($file)) {
                unlink($file);
            }
        }
    }

    public function test_upload_file_throws_exception_on_wp_error(): void
    {
        $handler = function () {
            return new WP_Error('http_error', 'cURL error 28');
        };

        $destination = $this->createDestination($handler);

        update_option('bjlg_s3_settings', [
            'access_key' => 'AK',
            'secret_key' => 'SECRET',
            'region' => 'eu-west-1',
            'bucket' => 'demo',
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        self::assertNotFalse($file);
        file_put_contents($file, 'content');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('cURL error 28');

        try {
            $destination->upload_file($file, 'task');
        } finally {
            if (is_string($file) && file_exists($file)) {
                unlink($file);
            }
        }
    }

    public function test_upload_file_uses_multipart_for_large_files(): void
    {
        $captured = [];
        $responses = [
            [
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'body' => '<InitiateMultipartUploadResult><UploadId>upload-123</UploadId></InitiateMultipartUploadResult>',
            ],
            [
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'headers' => ['ETag' => '"etag-part-1"'],
                'body' => '',
            ],
            [
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'headers' => ['ETag' => '"etag-part-2"'],
                'body' => '',
            ],
            [
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'headers' => ['ETag' => '"etag-part-3"'],
                'body' => '',
            ],
            [
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'body' => '',
            ],
        ];

        $handler = function ($url, array $args) use (&$responses, &$captured) {
            $captured[] = ['url' => $url, 'args' => $args];

            $response = array_shift($responses);
            if ($response === null) {
                return [
                    'response' => [
                        'code' => 500,
                        'message' => 'Unexpected request',
                    ],
                    'body' => '',
                ];
            }

            return $response;
        };

        $destination = $this->createDestination($handler);

        add_filter('bjlg_s3_upload_chunk_size', static function () {
            return 1024 * 1024; // 1 MiB
        }, 10, 4);

        add_filter('bjlg_s3_multipart_threshold', static function () {
            return 2 * 1024 * 1024; // 2 MiB
        }, 10, 4);

        update_option('bjlg_s3_settings', [
            'access_key' => 'AK',
            'secret_key' => 'SECRET',
            'region' => 'eu-west-3',
            'bucket' => 'multipart-bucket',
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        self::assertNotFalse($file);

        $size = (2 * 1024 * 1024) + 128;
        $chunk = str_repeat('A', 1024 * 1024);
        $content = str_repeat($chunk, 2) . substr($chunk, 0, 128);
        file_put_contents($file, $content);

        try {
            $destination->upload_file($file, 'task-large');
        } finally {
            if (is_string($file) && file_exists($file)) {
                unlink($file);
            }

            unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_s3_upload_chunk_size']);
            unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_s3_multipart_threshold']);
        }

        $this->assertCount(5, $captured);

        $this->assertSame('POST', $captured[0]['args']['method']);
        $this->assertStringContainsString('uploads=', $captured[0]['url']);

        $first_part = $captured[1]['args'];
        $second_part = $captured[2]['args'];
        $third_part = $captured[3]['args'];

        $this->assertSame('PUT', $first_part['method']);
        $this->assertSame('PUT', $second_part['method']);
        $this->assertSame('PUT', $third_part['method']);

        $this->assertSame(1024 * 1024, strlen($first_part['body']));
        $this->assertSame(1024 * 1024, strlen($second_part['body']));
        $this->assertSame($size - (2 * 1024 * 1024), strlen($third_part['body']));

        $this->assertSame((string) (1024 * 1024), $first_part['headers']['Content-Length']);
        $this->assertSame((string) (1024 * 1024), $second_part['headers']['Content-Length']);
        $this->assertSame((string) ($size - (2 * 1024 * 1024)), $third_part['headers']['Content-Length']);

        $this->assertSame($first_part['headers']['X-Amz-Content-Sha256'], hash('sha256', $first_part['body']));
        $this->assertSame($second_part['headers']['X-Amz-Content-Sha256'], hash('sha256', $second_part['body']));
        $this->assertSame($third_part['headers']['X-Amz-Content-Sha256'], hash('sha256', $third_part['body']));

        $this->assertSame('POST', $captured[4]['args']['method']);
        $this->assertStringContainsString('uploadId=upload-123', $captured[4]['url']);
    }

    public function test_test_connection_uses_head_request(): void
    {
        $captured = [];
        $handler = function ($url, array $args) use (&$captured) {
            $captured[] = ['url' => $url, 'args' => $args];

            return [
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'body' => '',
            ];
        };

        $destination = $this->createDestination($handler);

        $destination->test_connection([
            'access_key' => 'ACCESS',
            'secret_key' => 'SECRET',
            'region' => 'eu-west-3',
            'bucket' => 'demo-bucket',
            'enabled' => true,
        ]);

        $this->assertCount(1, $captured);
        $this->assertSame('HEAD', $captured[0]['args']['method']);
        $this->assertSame('https://demo-bucket.s3.eu-west-3.amazonaws.com/', $captured[0]['url']);

        $status = get_option('bjlg_s3_status');
        $this->assertSame('success', $status['last_result']);
        $this->assertStringContainsString('demo-bucket', $status['message']);
    }

    public function test_delete_file_sends_delete_request(): void
    {
        $captured = [];
        $handler = function ($url, array $args) use (&$captured) {
            $captured[] = ['url' => $url, 'args' => $args];

            return [
                'response' => [
                    'code' => 204,
                    'message' => 'No Content',
                ],
                'body' => '',
            ];
        };

        $destination = $this->createDestination($handler);

        update_option('bjlg_s3_settings', [
            'access_key' => 'AK',
            'secret_key' => 'SECRET',
            'region' => 'eu-central-1',
            'bucket' => 'files-bucket',
            'object_prefix' => 'archives',
            'enabled' => true,
        ]);

        $destination->delete_file('old.zip');

        $this->assertCount(1, $captured);
        $this->assertSame('DELETE', $captured[0]['args']['method']);
        $this->assertSame('https://files-bucket.s3.eu-central-1.amazonaws.com/archives/old.zip', $captured[0]['url']);
    }

    public function test_upload_file_with_kms_key_sets_header(): void
    {
        $destination = $this->createDestination();

        update_option('bjlg_s3_settings', [
            'access_key' => 'AKIDKMS',
            'secret_key' => 'kms-secret',
            'region' => 'eu-west-3',
            'bucket' => 'kms-bucket',
            'server_side_encryption' => 'aws:kms',
            'kms_key_id' => 'arn:aws:kms:eu-west-3:123:key/abc',
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        self::assertNotFalse($file);
        file_put_contents($file, 'kms-content');

        $destination->upload_file($file, 'task-99');

        $this->assertCount(1, $this->requests);
        $headers = $this->requests[0]['args']['headers'];

        $this->assertSame('aws:kms', $headers['x-amz-server-side-encryption']);
        $this->assertSame('arn:aws:kms:eu-west-3:123:key/abc', $headers['x-amz-server-side-encryption-aws-kms-key-id']);

        unlink($file);
    }

    public function test_upload_file_accepts_array_of_paths(): void
    {
        $destination = $this->createDestination();

        update_option('bjlg_s3_settings', [
            'access_key' => 'AKID',
            'secret_key' => 'SECRET',
            'region' => 'us-east-1',
            'bucket' => 'array-bucket',
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        self::assertNotFalse($file);
        file_put_contents($file, 'archive');

        try {
            $destination->upload_file([$file], 'task-array');
        } finally {
            if (is_string($file) && file_exists($file)) {
                unlink($file);
            }
        }

        $this->assertCount(1, $this->requests);
    }

    public function test_upload_file_array_reports_all_errors(): void
    {
        $destination = $this->createDestination();

        update_option('bjlg_s3_settings', [
            'access_key' => 'AKID',
            'secret_key' => 'SECRET',
            'region' => 'us-east-1',
            'bucket' => 'array-bucket',
            'enabled' => true,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'bjlg');
        self::assertNotFalse($file);
        file_put_contents($file, 'archive');
        $missing = $file . '.missing';

        try {
            $destination->upload_file([$file, $missing], 'task-errors');
            $this->fail('Une exception aurait dû être levée pour les erreurs multiples.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('Erreurs Amazon S3', $exception->getMessage());
            $this->assertStringContainsString('introuvable', $exception->getMessage());
        } finally {
            if (is_string($file) && file_exists($file)) {
                unlink($file);
            }
        }

        $this->assertCount(1, $this->requests, 'Le premier fichier aurait dû être envoyé malgré les erreurs suivantes.');
    }

    private function createDestination(?callable $handler = null): BJLG_AWS_S3
    {
        $handler = $handler ?: function ($url, array $args) {
            $this->requests[] = ['url' => $url, 'args' => $args];

            return [
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'body' => '',
            ];
        };

        return new BJLG_AWS_S3($handler, static function () {
            return 1609459200; // 2021-01-01 00:00:00 UTC
        });
    }
}
