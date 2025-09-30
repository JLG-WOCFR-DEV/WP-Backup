<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-s3.php';

final class BJLG_S3DestinationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['bjlg_test_options'] = [];
    }

    public function test_is_connected_requires_enabled_credentials(): void
    {
        $destination = new BJLG\BJLG_S3();

        update_option('bjlg_s3_settings', [
            'access_key' => '',
            'secret_key' => '',
            'region' => '',
            'bucket' => '',
            'prefix' => '',
            'enabled' => false,
        ]);

        $this->assertFalse($destination->is_connected());

        update_option('bjlg_s3_settings', [
            'access_key' => 'AKIA-123',
            'secret_key' => 'secret',
            'region' => 'us-east-1',
            'bucket' => 'my-bucket',
            'prefix' => 'backups',
            'enabled' => true,
        ]);

        $this->assertTrue($destination->is_connected());
    }

    public function test_upload_file_sends_archive_to_client(): void
    {
        $temp_file = tempnam(sys_get_temp_dir(), 'bjlg-s3-');
        if (!is_string($temp_file)) {
            $this->fail('Unable to create temporary file for the test.');
        }

        file_put_contents($temp_file, 'backup');

        update_option('bjlg_s3_settings', [
            'access_key' => 'AK',
            'secret_key' => 'SK',
            'region' => 'eu-west-3',
            'bucket' => 'bucket-name',
            'prefix' => 'wordpress',
            'enabled' => true,
        ]);

        $fake_client = new class {
            /** @var array<int, array<string, mixed>> */
            public $calls = [];

            public function putObject(array $args)
            {
                $this->calls[] = $args;

                return ['ObjectURL' => 'https://example.com/' . $args['Key']];
            }
        };

        $destination = new BJLG\BJLG_S3(static function () use ($fake_client) {
            return $fake_client;
        });

        $destination->upload_file($temp_file, 'task-test');

        $this->assertCount(1, $fake_client->calls);
        $call = $fake_client->calls[0];
        $this->assertSame('bucket-name', $call['Bucket']);
        $this->assertSame('wordpress/' . basename($temp_file), $call['Key']);
        $this->assertSame($temp_file, $call['SourceFile']);

        @unlink($temp_file);
    }
}
