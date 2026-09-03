<?php
declare(strict_types=1);

namespace BJLG {
    if (!class_exists(__NAMESPACE__ . '\\BJLG_Debug')) {
        class BJLG_Debug
        {
            public static function log($message, $level = 'info') {}

            public static function error($message) {}

            public static function warning($message) {}

            public static function info($message) {}

            public static function debug($message) {}
        }
    }
}

namespace {

use BJLG\BJLG_Encryption;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-encryption.php';

final class BJLG_EncryptionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('BJLG_ENCRYPTION_KEY')) {
            $raw_key = str_repeat('0', BJLG_Encryption::KEY_LENGTH);
            define('BJLG_ENCRYPTION_KEY', 'base64:' . base64_encode($raw_key));
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['bjlg_test_options'] = [];
        bjlg_update_option('bjlg_encryption_settings', ['enabled' => false]);
    }

    public function test_get_encryption_key_supports_base64_prefix(): void
    {
        $encryption = new BJLG_Encryption();

        $reflection = new \ReflectionClass($encryption);
        $property = $reflection->getProperty('encryption_key');
        $property->setAccessible(true);
        $key = $property->getValue($encryption);

        $this->assertIsString($key);
        $this->assertSame(BJLG_Encryption::KEY_LENGTH, strlen($key));
    }

    public function test_constructor_does_not_write_encryption_key_option(): void
    {
        unset($GLOBALS['bjlg_test_options']['bjlg_encryption_key']);

        new BJLG_Encryption();

        $this->assertArrayNotHasKey('bjlg_encryption_key', $GLOBALS['bjlg_test_options'] ?? []);
    }

    public function test_encrypted_file_requires_password_reads_header_flag(): void
    {
        $plain = sys_get_temp_dir() . '/bjlg-enc-plain-' . uniqid('', true) . '.zip';
        file_put_contents($plain, 'plain-backup');

        bjlg_update_option('bjlg_encryption_settings', ['enabled' => true]);
        $encryption = new BJLG_Encryption();

        $withPassword = $encryption->encrypt_backup_file($plain, 'super-secret');
        $this->assertTrue($encryption->encrypted_file_requires_password($withPassword));
        @unlink($withPassword);

        file_put_contents($plain, 'plain-backup');
        $siteKeyOnly = $encryption->encrypt_backup_file($plain, null);
        $this->assertFalse($encryption->encrypted_file_requires_password($siteKeyOnly));
        @unlink($siteKeyOnly);
        @unlink($plain);
    }
}

}
