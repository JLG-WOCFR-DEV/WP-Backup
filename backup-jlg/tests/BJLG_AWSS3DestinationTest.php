<?php
declare(strict_types=1);

use BJLG\BJLG_AWS_S3;
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
        $this->assertSame('application/zip', $headers['Content-Type']);
        $this->assertSame((string) filesize($file), $headers['Content-Length']);
        $this->assertSame('archive-content', $request['args']['body']);

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
