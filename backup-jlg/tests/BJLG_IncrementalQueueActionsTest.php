<?php

declare(strict_types=1);

use BJLG\BJLG_Incremental;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-incremental.php';

final class BJLG_IncrementalQueueActionsTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifestPath = bjlg_get_backup_directory() . '.incremental-manifest.json';
        if (file_exists($this->manifestPath)) {
            @unlink($this->manifestPath);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->manifestPath)) {
            @unlink($this->manifestPath);
        }
    }

    private function seedQueueEntry(): BJLG_Incremental
    {
        $incremental = new BJLG_Incremental();
        $reflection = new ReflectionClass(BJLG_Incremental::class);
        $property = $reflection->getProperty('last_backup_data');
        $property->setAccessible(true);
        $data = $property->getValue($incremental);
        $data['remote_purge_queue'] = [[
            'file' => 'archive.zip',
            'destinations' => ['s3'],
            'status' => 'failed',
            'registered_at' => time() - 3600,
            'attempts' => 4,
            'last_attempt_at' => time() - 120,
            'next_attempt_at' => time() + 3600,
            'last_error' => 'Previous failure',
            'errors' => ['failure'],
            'failed_at' => time() - 60,
        ]];
        $property->setValue($incremental, $data);

        $save = $reflection->getMethod('save_manifest');
        $save->setAccessible(true);
        $this->assertTrue($save->invoke($incremental));

        return $incremental;
    }

    public function test_retry_remote_purge_entry_resets_state(): void
    {
        $incremental = $this->seedQueueEntry();

        $this->assertTrue($incremental->retry_remote_purge_entry('archive.zip'));

        $queue = $incremental->get_remote_purge_queue();
        $this->assertCount(1, $queue);
        $entry = $queue[0];
        $this->assertSame('pending', $entry['status']);
        $this->assertSame(0, $entry['attempts']);
        $this->assertSame('', $entry['last_error']);
        $this->assertLessThanOrEqual(time(), $entry['next_attempt_at']);
    }

    public function test_delete_remote_purge_entry_removes_entry(): void
    {
        $incremental = $this->seedQueueEntry();

        $this->assertTrue($incremental->delete_remote_purge_entry('archive.zip'));

        $queue = $incremental->get_remote_purge_queue();
        $this->assertSame([], $queue);
    }
}
