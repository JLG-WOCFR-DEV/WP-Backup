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
        update_option('bjlg_encryption_settings', ['enabled' => false]);
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
}

}
