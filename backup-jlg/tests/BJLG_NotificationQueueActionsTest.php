<?php

declare(strict_types=1);

use BJLG\BJLG_Notification_Queue;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-notification-queue.php';
require_once __DIR__ . '/../includes/class-bjlg-notification-receipts.php';

final class BJLG_NotificationQueueActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        bjlg_update_option('bjlg_notification_queue', []);
        $GLOBALS['bjlg_test_scheduled_events']['single'] = [];
        BJLG\BJLG_Notification_Receipts::delete_all();
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

    public function test_enqueue_initializes_resolution_and_reminder(): void
    {
        $now = time();
        $entry = [
            'id' => 'entry-resolution',
            'event' => 'backup_failed',
            'title' => 'Backup failed',
            'subject' => 'Failure',
            'lines' => ['Failure'],
            'body' => 'Body',
            'context' => [],
            'channels' => [
                'email' => [
                    'enabled' => true,
                    'status' => 'pending',
                    'recipients' => ['admin@example.com'],
                ],
            ],
            'created_at' => $now,
            'resolution' => [
                'steps' => [
                    [
                        'timestamp' => $now,
                        'actor' => 'Système',
                        'summary' => 'Initialisé',
                        'type' => 'created',
                    ],
                ],
            ],
        ];

        $queued = BJLG_Notification_Queue::enqueue($entry);
        BJLG\BJLG_Notification_Receipts::record_creation($queued);

        $this->assertIsArray($queued);
        $this->assertArrayHasKey('resolution', $queued);
        $this->assertArrayHasKey('reminders', $queued);
        $this->assertSame('entry-resolution', $queued['id']);

        $receipts = BJLG\BJLG_Notification_Receipts::get('entry-resolution');
        $this->assertIsArray($receipts);
        $this->assertSame('entry-resolution', $receipts['id']);
        $this->assertNull($receipts['acknowledged_at']);

        $scheduled = $GLOBALS['bjlg_test_scheduled_events']['single'] ?? [];
        $hooks = array_column($scheduled, 'hook');
        $this->assertContains('bjlg_notification_queue_reminder', $hooks);
    }

    public function test_acknowledge_updates_queue_resolution(): void
    {
        $now = time();
        $entry = [
            'id' => 'entry-ack',
            'event' => 'backup_failed',
            'title' => 'Failure',
            'subject' => 'Failure',
            'lines' => ['Failure'],
            'body' => 'Body',
            'context' => [],
            'channels' => [
                'email' => [
                    'enabled' => true,
                    'status' => 'pending',
                    'recipients' => ['admin@example.com'],
                ],
            ],
            'created_at' => $now,
            'resolution' => [
                'steps' => [
                    [
                        'timestamp' => $now,
                        'actor' => 'Système',
                        'summary' => 'Initialisé',
                        'type' => 'created',
                    ],
                ],
            ],
        ];

        $queued = BJLG_Notification_Queue::enqueue($entry);
        $this->assertIsArray($queued);

        BJLG\BJLG_Notification_Receipts::record_creation($queued);
        $record = BJLG\BJLG_Notification_Receipts::acknowledge('entry-ack', 'Tester', 'Note');
        $this->assertNotEmpty($record['acknowledged_at']);

        $queue = bjlg_get_option('bjlg_notification_queue');
        $this->assertNotEmpty($queue);
        $entry_state = $queue[0];
        $this->assertSame('entry-ack', $entry_state['id']);
        $this->assertNotEmpty($entry_state['resolution']['acknowledged_at']);
        $this->assertFalse($entry_state['reminders']['active']);
    }
}
