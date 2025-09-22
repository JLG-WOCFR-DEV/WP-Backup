<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-health-check.php';

final class BJLG_HealthCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['bjlg_test_disk_total_space_mock'], $GLOBALS['bjlg_test_disk_free_space_mock']);
    }

    public function test_check_disk_space_warns_when_total_space_missing(): void
    {
        $GLOBALS['bjlg_test_disk_total_space_mock'] = static function (string $directory) {
            return false;
        };
        $GLOBALS['bjlg_test_disk_free_space_mock'] = static function (string $directory) {
            return 1024;
        };

        $health_check = new BJLG\BJLG_Health_Check();

        $reflection = new ReflectionClass(BJLG\BJLG_Health_Check::class);
        $method = $reflection->getMethod('check_disk_space');
        $method->setAccessible(true);

        /** @var array{status: string, message: string} $result */
        $result = $method->invoke($health_check);

        $this->assertSame('warning', $result['status']);
        $this->assertStringContainsString("Impossible de déterminer l'espace disque total", $result['message']);
        $this->assertStringContainsString("L'utilisation du disque n'a pas pu être calculée", $result['message']);
    }

    public function test_check_disk_space_warns_when_total_space_non_positive(): void
    {
        $GLOBALS['bjlg_test_disk_total_space_mock'] = static function (string $directory) {
            return 0;
        };
        $GLOBALS['bjlg_test_disk_free_space_mock'] = static function (string $directory) {
            return 512;
        };

        $health_check = new BJLG\BJLG_Health_Check();

        $reflection = new ReflectionClass(BJLG\BJLG_Health_Check::class);
        $method = $reflection->getMethod('check_disk_space');
        $method->setAccessible(true);

        /** @var array{status: string, message: string} $result */
        $result = $method->invoke($health_check);

        $this->assertSame('warning', $result['status']);
        $this->assertStringContainsString("Impossible de déterminer l'espace disque total", $result['message']);
    }
}
