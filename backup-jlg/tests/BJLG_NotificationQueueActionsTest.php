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

        $GLOBALS['current_user'] = (object) [
            'ID' => 42,
            'display_name' => 'Test Operator',
            'user_login' => 'test-operator',
        ];
        $GLOBALS['current_user_id'] = 42;
        $GLOBALS['bjlg_test_users'][42] = $GLOBALS['current_user'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['current_user'], $GLOBALS['current_user_id']);
        if (isset($GLOBALS['bjlg_test_users'][42])) {
            unset($GLOBALS['bjlg_test_users'][42]);
        }

        parent::tearDown();
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

    public function test_acknowledge_channel_marks_entry_and_channel(): void
    {
        bjlg_update_option('bjlg_notification_queue', [
            [
                'id' => 'ack-entry',
                'event' => 'backup_failed',
                'title' => 'Backup failed',
                'channels' => [
                    'email' => [
                        'enabled' => true,
                        'status' => 'pending',
                        'attempts' => 0,
                    ],
                    'slack' => [
                        'enabled' => true,
                        'status' => 'pending',
                        'attempts' => 0,
                    ],
                ],
            ],
        ]);

        $this->assertTrue(BJLG_Notification_Queue::acknowledge_channel('ack-entry', 'email', 42));

        $queue = bjlg_get_option('bjlg_notification_queue');
        $this->assertCount(1, $queue);
        $entry = $queue[0];
        $this->assertArrayHasKey('acknowledged_at', $entry);
        $this->assertGreaterThan(0, $entry['acknowledged_at']);
        $this->assertSame('Test Operator', $entry['acknowledged_by']);

        $email = $entry['channels']['email'];
        $this->assertSame('Test Operator', $email['acknowledged_by']);
        $this->assertGreaterThan(0, $email['acknowledged_at']);

        $slack = $entry['channels']['slack'];
        $this->assertTrue(!isset($slack['acknowledged_at']) || (int) $slack['acknowledged_at'] === 0);
    }

    public function test_acknowledge_entry_marks_all_channels(): void
    {
        bjlg_update_option('bjlg_notification_queue', [
            [
                'id' => 'ack-all',
                'event' => 'backup_failed',
                'title' => 'Backup failed',
                'channels' => [
                    'email' => [
                        'enabled' => true,
                        'status' => 'pending',
                        'attempts' => 0,
                    ],
                    'sms' => [
                        'enabled' => true,
                        'status' => 'pending',
                        'attempts' => 0,
                    ],
                ],
            ],
        ]);

        $this->assertTrue(BJLG_Notification_Queue::acknowledge_entry('ack-all', 42));

        $queue = bjlg_get_option('bjlg_notification_queue');
        $entry = $queue[0];
        $this->assertArrayHasKey('acknowledged_at', $entry);
        $this->assertSame('Test Operator', $entry['acknowledged_by']);

        foreach ($entry['channels'] as $channel) {
            $this->assertSame('Test Operator', $channel['acknowledged_by']);
            $this->assertGreaterThan(0, $channel['acknowledged_at']);
        }
    }

    public function test_resolve_channel_logs_when_all_channels_resolved(): void
    {
        $GLOBALS['bjlg_test_hooks']['actions']['bjlg_history_logged'] = [];
        $GLOBALS['bjlg_test_hooks']['actions']['bjlg_notification_resolved'] = [];

        $history = [];
        add_action('bjlg_history_logged', function ($action, $status, $details) use (&$history) {
            $history[] = compact('action', 'status', 'details');
        }, 10, 3);

        $resolvedEntries = [];
        add_action('bjlg_notification_resolved', function ($entry) use (&$resolvedEntries) {
            $resolvedEntries[] = $entry;
        }, 10, 1);

        bjlg_update_option('bjlg_notification_queue', [
            [
                'id' => 'resolve-me',
                'event' => 'backup_failed',
                'title' => 'Backup failed',
                'channels' => [
                    'email' => [
                        'enabled' => true,
                        'status' => 'pending',
                        'attempts' => 1,
                    ],
                    'slack' => [
                        'enabled' => true,
                        'status' => 'pending',
                        'attempts' => 2,
                    ],
                ],
            ],
        ]);

        $this->assertTrue(BJLG_Notification_Queue::resolve_channel('resolve-me', 'email', 42, 'Email ok'));

        $queue = bjlg_get_option('bjlg_notification_queue');
        $entry = $queue[0];
        $this->assertArrayHasKey('resolution_notes', $entry);
        $this->assertStringContainsString('email', $entry['resolution_notes']);
        $this->assertSame([], $history);
        $this->assertSame([], $resolvedEntries);

        $this->assertTrue(BJLG_Notification_Queue::resolve_channel('resolve-me', 'slack', 42, 'Slack ok'));

        $queue = bjlg_get_option('bjlg_notification_queue');
        $entry = $queue[0];
        $this->assertGreaterThan(0, $entry['resolved_at']);
        $this->assertStringContainsString('slack', $entry['resolution_notes']);

        $this->assertNotEmpty($history);
        $this->assertSame('notification_resolved', $history[0]['action']);
        $this->assertSame('info', $history[0]['status']);
        $this->assertNotEmpty($resolvedEntries);
        $this->assertSame('resolve-me', $resolvedEntries[0]['id']);
    }

    public function test_trigger_manual_reminder_schedules_event(): void
    {
        bjlg_update_option('bjlg_notification_queue', [
            [
                'id' => 'manual-reminder',
                'event' => 'backup_failed',
                'title' => 'Backup failed',
                'channels' => [
                    'email' => [
                        'enabled' => true,
                        'status' => 'pending',
                        'attempts' => 0,
                    ],
                ],
                'reminders' => [
                    'active' => true,
                    'next_at' => time() + 3600,
                    'attempts' => 1,
                ],
            ],
        ]);

        $this->assertTrue(BJLG_Notification_Queue::trigger_manual_reminder('manual-reminder'));

        $queue = bjlg_get_option('bjlg_notification_queue');
        $this->assertCount(1, $queue);
        $entry = $queue[0];
        $this->assertArrayHasKey('reminders', $entry);
        $this->assertTrue(isset($entry['reminders']['active']) ? (bool) $entry['reminders']['active'] : true);
        $this->assertGreaterThan(time(), $entry['reminders']['next_at']);

        $scheduled = $GLOBALS['bjlg_test_scheduled_events']['single'] ?? [];
        $this->assertNotEmpty($scheduled);
        $hooks = array_column($scheduled, 'hook');
        $this->assertContains('bjlg_notification_queue_reminder', $hooks);
    }
}
