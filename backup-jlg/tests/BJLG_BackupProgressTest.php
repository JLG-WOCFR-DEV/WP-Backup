<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BJLG_BackupProgressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['bjlg_test_transients'] = [];
    }

    public function test_update_task_progress_stores_decimal_progress(): void
    {
        $task_id = 'bjlg_backup_' . uniqid('test', true);

        set_transient($task_id, [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => 'Initialisation',
        ], HOUR_IN_SECONDS);

        $backup = new BJLG\BJLG_Backup();

        $method = new ReflectionMethod($backup, 'update_task_progress');
        $method->setAccessible(true);
        $method->invoke($backup, $task_id, 42.65, 'running', 'Test progress');

        $task_data = get_transient($task_id);

        $this->assertIsArray($task_data);
        $this->assertArrayHasKey('progress', $task_data);
        $this->assertSame(42.7, $task_data['progress']);
        $this->assertSame('running', $task_data['status']);
        $this->assertSame('Test progress', $task_data['status_text']);
    }

    public function test_update_task_progress_preserves_integer_progress(): void
    {
        $task_id = 'bjlg_backup_' . uniqid('test', true);

        set_transient($task_id, [
            'progress' => 10,
            'status' => 'running',
            'status_text' => 'En cours',
        ], HOUR_IN_SECONDS);

        $backup = new BJLG\BJLG_Backup();

        $method = new ReflectionMethod($backup, 'update_task_progress');
        $method->setAccessible(true);
        $method->invoke($backup, $task_id, 50, 'running', 'Toujours en cours');

        $task_data = get_transient($task_id);

        $this->assertIsArray($task_data);
        $this->assertArrayHasKey('progress', $task_data);
        $this->assertSame(50, $task_data['progress']);
        $this->assertSame('running', $task_data['status']);
        $this->assertSame('Toujours en cours', $task_data['status_text']);
    }
}
