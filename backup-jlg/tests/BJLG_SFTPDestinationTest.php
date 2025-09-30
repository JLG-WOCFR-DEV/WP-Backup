<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-sftp.php';

final class BJLG_SFTPDestinationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['bjlg_test_options'] = [];
    }

    public function test_is_connected_checks_credentials(): void
    {
        $destination = new BJLG\BJLG_SFTP();

        update_option('bjlg_sftp_settings', [
            'host' => '',
            'port' => 22,
            'username' => '',
            'password' => '',
            'private_key' => '',
            'remote_path' => '',
            'enabled' => false,
        ]);

        $this->assertFalse($destination->is_connected());

        update_option('bjlg_sftp_settings', [
            'host' => 'sftp.example.com',
            'port' => 22,
            'username' => 'deploy',
            'password' => 'secret',
            'private_key' => '',
            'remote_path' => '',
            'enabled' => true,
        ]);

        $this->assertTrue($destination->is_connected());
    }

    public function test_upload_file_uses_sftp_client(): void
    {
        $temp_file = tempnam(sys_get_temp_dir(), 'bjlg-sftp-');
        if (!is_string($temp_file)) {
            $this->fail('Unable to create temporary file for the test.');
        }

        file_put_contents($temp_file, 'content');

        update_option('bjlg_sftp_settings', [
            'host' => 'sftp.example.com',
            'port' => 2222,
            'username' => 'deploy',
            'password' => 'secret',
            'private_key' => '',
            'remote_path' => '/backups',
            'enabled' => true,
        ]);

        $fake_client = new class {
            public const SOURCE_LOCAL_FILE = 7;
            public $sourceLocalFile = 7;

            /** @var array<int, array<int, mixed>> */
            public $loginCalls = [];

            /** @var array<int, array<int, mixed>> */
            public $putCalls = [];

            /** @var bool */
            public $disconnected = false;

            public function login($username, $credential)
            {
                $this->loginCalls[] = [$username, $credential];

                return true;
            }

            public function put($remote_path, $local_path, $mode)
            {
                $this->putCalls[] = [$remote_path, $local_path, $mode];

                return true;
            }

            public function disconnect(): void
            {
                $this->disconnected = true;
            }
        };

        $destination = new BJLG\BJLG_SFTP(static function () use ($fake_client) {
            return $fake_client;
        });

        $destination->upload_file($temp_file, 'task-sftp');

        $this->assertCount(1, $fake_client->loginCalls);
        $this->assertSame(['deploy', 'secret'], $fake_client->loginCalls[0]);

        $this->assertCount(1, $fake_client->putCalls);
        $put = $fake_client->putCalls[0];
        $this->assertSame('/backups/' . basename($temp_file), $put[0]);
        $this->assertSame($temp_file, $put[1]);
        $this->assertSame(1, $put[2]);

        $this->assertTrue($fake_client->disconnected);

        @unlink($temp_file);
    }

    public function test_upload_file_uses_private_key_loader_when_available(): void
    {
        update_option('bjlg_sftp_settings', [
            'host' => 'sftp.example.com',
            'port' => 22,
            'username' => 'deploy',
            'password' => 'passphrase',
            'private_key' => "-----BEGIN KEY-----\nFAKE\n-----END KEY-----",
            'remote_path' => '',
            'enabled' => true,
        ]);

        $fake_client = new class {
            public const SOURCE_LOCAL_FILE = 1;

            /** @var array<int, array<int, mixed>> */
            public $loginCalls = [];

            public function login($username, $credential)
            {
                $this->loginCalls[] = [$username, $credential];

                return true;
            }

            public function put($remote_path, $local_path, $mode)
            {
                return true;
            }
        };

        $loaded_keys = [];
        $loader = static function ($key, $password) use (&$loaded_keys) {
            $loaded_keys[] = [$key, $password];

            return 'loaded-key';
        };

        $destination = new BJLG\BJLG_SFTP(static function () use ($fake_client) {
            return $fake_client;
        }, $loader);

        $temp_file = tempnam(sys_get_temp_dir(), 'bjlg-sftp-key-');
        if (!is_string($temp_file)) {
            $this->fail('Unable to create temporary file for the test.');
        }

        file_put_contents($temp_file, 'data');

        $destination->upload_file($temp_file, 'task-key');

        $this->assertSame('loaded-key', $fake_client->loginCalls[0][1]);
        $this->assertCount(1, $loaded_keys);
        $this->assertSame('passphrase', $loaded_keys[0][1]);

        @unlink($temp_file);
    }
}
