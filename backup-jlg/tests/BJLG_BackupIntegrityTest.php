<?php
declare(strict_types=1);

use BJLG\BJLG_Backup_Integrity;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-backup-integrity.php';

final class BJLG_BackupIntegrityTest extends TestCase
{
    public function test_filter_archive_paths_excludes_sidecars(): void
    {
        $paths = [
            '/tmp/backup-full.zip',
            '/tmp/backup-full.zip.sha256',
            '/tmp/backup-full.zip.enc',
            '/tmp/notes.txt',
        ];

        $this->assertSame(
            ['/tmp/backup-full.zip', '/tmp/backup-full.zip.enc'],
            BJLG_Backup_Integrity::filter_archive_paths($paths)
        );
    }

    public function test_write_and_verify_sidecar(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bjlg-int');
        $this->assertIsString($file);
        $path = $file . '.zip';
        rename($file, $path);
        file_put_contents($path, 'archive-bytes');

        try {
            $hash = BJLG_Backup_Integrity::write_sidecar($path);
            $this->assertSame(hash_file('sha256', $path), $hash);
            $this->assertFileExists(BJLG_Backup_Integrity::sidecar_path($path));

            $result = BJLG_Backup_Integrity::verify_file($path, true);
            $this->assertSame('passed', $result['status']);
            $this->assertSame($hash, $result['checksum']);
        } finally {
            BJLG_Backup_Integrity::delete_sidecar($path);
            @unlink($path);
        }
    }

    public function test_verify_file_skips_missing_sidecar(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bjlg-int');
        $this->assertIsString($file);
        file_put_contents($file, 'plain');

        try {
            $result = BJLG_Backup_Integrity::verify_file($file, false);
            $this->assertSame('skipped', $result['status']);
            $this->assertSame(hash_file('sha256', $file), $result['checksum']);
        } finally {
            @unlink($file);
        }
    }
}
