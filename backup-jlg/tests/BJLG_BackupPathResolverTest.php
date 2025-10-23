<?php
declare(strict_types=1);

use BJLG\BJLG_Backup_Path_Resolver;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-backup-path-resolver.php';

final class BJLG_BackupPathResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        bjlg_tests_recursive_delete(bjlg_get_backup_directory());
        if (!is_dir(bjlg_get_backup_directory())) {
            mkdir(bjlg_get_backup_directory(), 0777, true);
        }
    }

    protected function tearDown(): void
    {
        bjlg_tests_recursive_delete(bjlg_get_backup_directory());
        mkdir(bjlg_get_backup_directory(), 0777, true);
        parent::tearDown();
    }

    public function test_resolve_returns_absolute_path_for_existing_archive(): void
    {
        $backupPath = bjlg_get_backup_directory() . 'sample-backup.zip';
        file_put_contents($backupPath, 'dummy');

        $resolved = BJLG_Backup_Path_Resolver::resolve('sample-backup.zip');

        $this->assertSame(realpath($backupPath), $resolved);
    }

    public function test_resolve_appends_zip_extension_when_missing(): void
    {
        $backupPath = bjlg_get_backup_directory() . 'nightly.zip';
        file_put_contents($backupPath, 'nightly');

        $resolved = BJLG_Backup_Path_Resolver::resolve('nightly');

        $this->assertSame(realpath($backupPath), $resolved);
    }

    public function test_resolve_rejects_symlink_pointing_outside_backup_dir(): void
    {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not supported in this environment.');
        }

        $outsidePath = sys_get_temp_dir() . '/bjlg-outside-' . uniqid('', true) . '.zip';
        file_put_contents($outsidePath, 'outside');

        $linkPath = bjlg_get_backup_directory() . 'linked.zip';
        if (@symlink($outsidePath, $linkPath) === false) {
            $this->markTestSkipped('Symlinks cannot be created in this environment.');
        }

        $result = BJLG_Backup_Path_Resolver::resolve('linked.zip');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_backup_id', $result->get_error_code());

        @unlink($outsidePath);
    }

    public function test_resolve_returns_error_when_backup_missing(): void
    {
        $result = BJLG_Backup_Path_Resolver::resolve('missing.zip');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('backup_not_found', $result->get_error_code());
    }
}
