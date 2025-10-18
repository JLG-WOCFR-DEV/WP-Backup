<?php

declare(strict_types=1);

use BJLG\BJLG_Notification_Queue;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-notification-queue.php';

final class BJLG_NotificationQueueActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        bjlg_update_option('bjlg_notification_queue', []);
        $GLOBALS['bjlg_test_scheduled_events']['single'] = [];
    }

    public function test_retry_entry_resets_channels_and_schedules_event(): void
    {
        $created = time() - 600;
        bjlg_update_option('bjlg_notification_queue', [
            [
                'id' => 'entry-1',
                'event' => 'backup_failed',
                'title' => 'Backup failed',
                'subject' => 'Failure',
                'lines' => ['Failure'],
                'body' => 'Body',
                'context' => [],
                'created_at' => $created,
                'next_attempt_at' => $created + 900,
                'last_error' => 'Previous error',
                'channels' => [
                    'email' => [
                        'enabled' => true,
                        'status' => 'failed',
                        'attempts' => 3,
                        'recipients' => ['admin@example.com'],
                        'last_error' => 'Transport error',
                        'next_attempt_at' => $created + 900,
                    ],
                ],
            ],
        ]);

        $this->assertTrue(BJLG_Notification_Queue::retry_entry('entry-1'));

        $queue = bjlg_get_option('bjlg_notification_queue');
        $this->assertCount(1, $queue);
        $entry = $queue[0];
        $this->assertSame('entry-1', $entry['id']);
        $this->assertSame('', $entry['last_error']);
        $this->assertSame(0, $entry['last_attempt_at']);
        $this->assertLessThanOrEqual(time(), $entry['next_attempt_at']);

        $this->assertArrayHasKey('email', $entry['channels']);
        $channel = $entry['channels']['email'];
        $this->assertSame('pending', $channel['status']);
        $this->assertSame(0, $channel['attempts']);
        $this->assertArrayNotHasKey('last_error', $channel);

        $scheduled = $GLOBALS['bjlg_test_scheduled_events']['single'] ?? [];
        $this->assertNotEmpty($scheduled);
        $hooks = array_column($scheduled, 'hook');
        $this->assertContains('bjlg_process_notification_queue', $hooks);
    }

    public function test_delete_entry_removes_entry(): void
    {
        bjlg_update_option('bjlg_notification_queue', [
            [
                'id' => 'delete-me',
                'event' => 'backup_complete',
                'title' => 'Complete',
                'subject' => 'Subject',
                'lines' => ['Complete'],
                'body' => 'Body',
                'context' => [],
                'channels' => [
                    'email' => [
                        'enabled' => true,
                        'status' => 'completed',
                        'attempts' => 1,
                        'recipients' => ['user@example.com'],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(BJLG_Notification_Queue::delete_entry('delete-me'));
        $queue = bjlg_get_option('bjlg_notification_queue');
        $this->assertSame([], $queue);
    }
}
