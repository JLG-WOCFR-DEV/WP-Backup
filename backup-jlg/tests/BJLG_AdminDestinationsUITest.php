<?php
declare(strict_types=1);

use BJLG\BJLG_Admin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/destinations/interface-bjlg-destination.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-aws-s3.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-google-drive.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-dropbox.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-onedrive.php';
require_once __DIR__ . '/../includes/destinations/abstract-class-bjlg-s3-compatible.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-wasabi.php';
require_once __DIR__ . '/../includes/destinations/class-bjlg-sftp.php';
require_once __DIR__ . '/../includes/class-bjlg-webhooks.php';
require_once __DIR__ . '/../includes/class-bjlg-admin.php';

final class BJLG_AdminDestinationsUITest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['bjlg_test_options'] = [];
    }

    public function test_settings_section_contains_aws_s3_destination(): void
    {
        $admin = new BJLG_Admin();

        ob_start();
        $reflection = new ReflectionClass(BJLG_Admin::class);
        $method = $reflection->getMethod('render_settings_section');
        $method->setAccessible(true);
        $method->invoke($admin);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Amazon S3', $html);
        $this->assertStringContainsString("name='s3_access_key'", $html);
        $this->assertStringContainsString('bjlg-s3-test-connection', $html);
        $this->assertStringContainsString("name='s3_kms_key_id'", $html);
    }
}
