<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-cleanup.php';
require_once __DIR__ . '/../includes/class-bjlg-incremental.php';

final class BJLG_CleanupRetentionTest extends TestCase
{
    /** @var array<int, string> */
    private $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('bjlg_tests_cleanup_backup_dir')) {
            bjlg_tests_cleanup_backup_dir();
        }
        $GLOBALS['bjlg_test_options']['bjlg_cleanup_settings'] = [
            'by_number' => 0,
            'by_age' => 1,
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $manifest = bjlg_get_backup_directory() . '.incremental-manifest.json';
        if (file_exists($manifest)) {
            @unlink($manifest);
        }

        parent::tearDown();
    }

    public function test_age_cleanup_keeps_newest_backup(): void
    {
        $old = $this->touchBackup('old-backup.zip', time() - (10 * DAY_IN_SECONDS));
        $newest = $this->touchBackup('new-backup.zip', time() - 60);

        $deleted = $this->purge();

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($newest);
    }

    public function test_age_cleanup_keeps_incremental_chain(): void
    {
        $full = $this->touchBackup('full-backup.zip', time() - (20 * DAY_IN_SECONDS));
        $incremental = $this->touchBackup('incremental-backup.zip', time() - (15 * DAY_IN_SECONDS));
        $newest = $this->touchBackup('new-backup.zip', time() - 30);

        $handler = new BJLG\BJLG_Incremental();
        $handler->update_manifest($full, [
            'path' => $full,
            'file' => basename($full),
            'incremental' => false,
            'timestamp' => time() - (20 * DAY_IN_SECONDS),
        ]);
        $handler->update_manifest($incremental, [
            'path' => $incremental,
            'file' => basename($incremental),
            'incremental' => true,
            'timestamp' => time() - (15 * DAY_IN_SECONDS),
        ]);

        $deleted = $this->purge();

        $this->assertSame(0, $deleted);
        $this->assertFileExists($full);
        $this->assertFileExists($incremental);
        $this->assertFileExists($newest);
    }

    private function touchBackup(string $filename, int $mtime): string
    {
        $path = bjlg_get_backup_directory() . $filename;
        file_put_contents($path, 'zip');
        touch($path, $mtime);
        $this->created[] = $path;

        return $path;
    }

    private function purge(): int
    {
        $cleanup = new class extends BJLG\BJLG_Cleanup {
            public function __construct()
            {
            }

            public function purge(): int
            {
                return $this->cleanup_backups();
            }
        };

        return $cleanup->purge();
    }
}
