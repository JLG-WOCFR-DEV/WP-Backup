<?php
declare(strict_types=1);

use BJLG\BJLG_Restore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-restore.php';

final class BJLG_RestoreSandboxValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['bjlg_test_transients'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['bjlg_test_transients']);
        parent::tearDown();
    }

    public function test_run_sandbox_validation_returns_health_report(): void
    {
        $backup_dir = bjlg_get_backup_directory();
        $archive_path = tempnam($backup_dir, 'sandbox-validation-');
        $this->assertIsString($archive_path);
        @unlink($archive_path);
        $archive_path .= '.zip';

        $zip = new ZipArchive();
        $open = $zip->open($archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($open === true || $open === ZipArchive::ER_OK);

        $manifest = [
            'type' => 'test',
            'contains' => ['db'],
        ];

        $zip->addFromString('backup-manifest.json', json_encode($manifest));
        $zip->addFromString('database.sql', "SELECT 1;\n");
        $zip->close();

        $restore = new class extends BJLG_Restore {
            public function run_restore_task($task_id)
            {
                $task_data = get_transient($task_id);
                if (!is_array($task_data)) {
                    $task_data = [];
                }

                $task_data['status'] = 'complete';
                $task_data['progress'] = 100;
                $task_data['status_text'] = 'Sandbox ready';

                set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl());
            }
        };

        $report = $restore->run_sandbox_validation([
            'backup' => basename($archive_path),
        ]);

        $this->assertSame('success', $report['status']);
        $this->assertIsArray($report['health']);
        $this->assertArrayHasKey('status', $report['health']);
        $this->assertArrayHasKey('summary', $report['health']);
        $this->assertArrayHasKey('issues', $report);
        $this->assertIsArray($report['issues']);

        if (file_exists($archive_path)) {
            unlink($archive_path);
        }
    }
}
