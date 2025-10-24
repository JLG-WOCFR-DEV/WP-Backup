<?php

declare(strict_types=1);

namespace BJLG {
    if (!function_exists(__NAMESPACE__ . '\\wp_http_validate_url')) {
        function wp_http_validate_url($url)
        {
            $url = trim((string) $url);

            return filter_var($url, FILTER_VALIDATE_URL) ? $url : false;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_post')) {
        function wp_remote_post($url, $args = [])
        {
            $GLOBALS['bjlg_test_transports']['remote_post'][] = [
                'url' => $url,
                'args' => $args,
            ];

            return [
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
            ];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response)
        {
            if (is_array($response) && isset($response['response']['code'])) {
                return (int) $response['response']['code'];
            }

            return 0;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_mail')) {
        function wp_mail($recipients, $subject, $body, $headers = [])
        {
            $GLOBALS['bjlg_test_transports']['mail'][] = [
                'recipients' => (array) $recipients,
                'subject' => $subject,
                'body' => $body,
                'headers' => $headers,
            ];

            return true;
        }
    }
}

namespace {

use BJLG\BJLG_Notification_Queue;
use BJLG\BJLG_Notifications;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-notification-queue.php';
require_once __DIR__ . '/../includes/class-bjlg-notification-receipts.php';
require_once __DIR__ . '/../includes/class-bjlg-notifications.php';

final class BJLG_NotificationQueueActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetQueueStorage();
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

        $this->resetQueueStorage();

        $this->resetNotificationsInstance();
        parent::tearDown();
    }

    public function test_retry_entry_resets_channels_and_schedules_event(): void
    {
        $created = time() - 600;
        BJLG_Notification_Queue::seed_queue([
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

        $queue = $this->getQueueEntries();
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

        $row = $this->getTableRow('entry-1');
        $this->assertNotNull($row);
        $this->assertSame('entry-1', $row['id']);
        $this->assertGreaterThan(0, $row['created_at']);

        $scheduled = $GLOBALS['bjlg_test_scheduled_events']['single'] ?? [];
        $this->assertNotEmpty($scheduled);
        $hooks = array_column($scheduled, 'hook');
        $this->assertContains('bjlg_process_notification_queue', $hooks);
    }

    public function test_delete_entry_removes_entry(): void
    {
        BJLG_Notification_Queue::seed_queue([
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
        $queue = $this->getQueueEntries();
        $this->assertSame([], $queue);
        $this->assertNull($this->getTableRow('delete-me'));
    }

    public function test_acknowledge_channel_marks_entry_and_channel(): void
    {
        BJLG_Notification_Queue::seed_queue([
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

        $queue = $this->getQueueEntries();
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

        $row = $this->getTableRow('ack-entry');
        $this->assertNotNull($row);
        $this->assertGreaterThan(0, $row['acknowledged_at']);
        $this->assertSame('Test Operator', $row['acknowledged_by']);
    }

    public function test_acknowledge_entry_marks_all_channels(): void
    {
        BJLG_Notification_Queue::seed_queue([
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

        $queue = $this->getQueueEntries();
        $this->assertCount(1, $queue);
        $entry = $queue[0];
        $this->assertArrayHasKey('acknowledged_at', $entry);
        $this->assertSame('Test Operator', $entry['acknowledged_by']);

        foreach ($entry['channels'] as $channel) {
            $this->assertSame('Test Operator', $channel['acknowledged_by']);
            $this->assertGreaterThan(0, $channel['acknowledged_at']);
        }

        $row = $this->getTableRow('ack-all');
        $this->assertNotNull($row);
        $this->assertGreaterThan(0, $row['acknowledged_at']);
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

        BJLG_Notification_Queue::seed_queue([
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

        $queue = $this->getQueueEntries();
        $entry = $queue[0];
        $this->assertArrayHasKey('resolution_notes', $entry);
        $this->assertStringContainsString('email', $entry['resolution_notes']);
        $this->assertSame([], $history);
        $this->assertSame([], $resolvedEntries);

        $this->assertTrue(BJLG_Notification_Queue::resolve_channel('resolve-me', 'slack', 42, 'Slack ok'));

        $queue = $this->getQueueEntries();
        $entry = $queue[0];
        $this->assertGreaterThan(0, $entry['resolved_at']);
        $this->assertStringContainsString('slack', $entry['resolution_notes']);

        $this->assertNotEmpty($history);
        $this->assertSame('notification_resolved', $history[0]['action']);
        $this->assertSame('info', $history[0]['status']);
        $this->assertNotEmpty($resolvedEntries);
        $this->assertSame('resolve-me', $resolvedEntries[0]['id']);

        $row = $this->getTableRow('resolve-me');
        $this->assertNotNull($row);
        $this->assertGreaterThan(0, $row['resolved_at']);
        $this->assertStringContainsString('Slack ok', (string) $row['resolution_notes']);
    }

    public function test_trigger_manual_reminder_schedules_event(): void
    {
        BJLG_Notification_Queue::seed_queue([
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

        $queue = $this->getQueueEntries();
        $this->assertCount(1, $queue);
        $entry = $queue[0];
        $this->assertArrayHasKey('reminders', $entry);
        $this->assertTrue(isset($entry['reminders']['active']) ? (bool) $entry['reminders']['active'] : true);
        $this->assertGreaterThan(time(), $entry['reminders']['next_at']);

        $row = $this->getTableRow('manual-reminder');
        $this->assertNotNull($row);
        $this->assertGreaterThan(time(), $row['reminder_next_at']);

        $scheduled = $GLOBALS['bjlg_test_scheduled_events']['single'] ?? [];
        $this->assertNotEmpty($scheduled);
        $hooks = array_column($scheduled, 'hook');
        $this->assertContains('bjlg_notification_queue_reminder', $hooks);
    }

    public function test_resolution_broadcast_sends_summary_and_logs_history(): void
    {
        $this->resetNotificationsInstance();

        $previous_history_hooks = $GLOBALS['bjlg_test_hooks']['actions']['bjlg_history_logged'] ?? null;
        $GLOBALS['bjlg_test_hooks']['actions']['bjlg_history_logged'] = [];

        $history = [];
        add_action('bjlg_history_logged', function ($action, $status, $details) use (&$history) {
            $history[] = compact('action', 'status', 'details');
        }, 10, 3);

        $GLOBALS['bjlg_test_transports'] = ['mail' => [], 'remote_post' => []];

        bjlg_update_option('bjlg_notification_settings', [
            'enabled' => true,
            'email_recipients' => 'ops@example.com',
            'events' => [
                'backup_failed' => true,
            ],
            'channels' => [
                'email' => ['enabled' => true],
                'slack' => [
                    'enabled' => true,
                    'webhook_url' => 'https://example.com/webhooks/slack',
                ],
                'sms' => [
                    'enabled' => true,
                    'webhook_url' => 'https://example.com/webhooks/sms',
                ],
            ],
        ]);

        $resolvedSummary = 'Incident rétabli après redémarrage du service.';
        $now = time();

        BJLG_Notification_Queue::seed_queue([
            [
                'id' => 'resolve-broadcast',
                'event' => 'backup_failed',
                'title' => 'Sauvegarde critique échouée',
                'subject' => 'Incident sauvegarde',
                'lines' => ['Incident critique détecté.'],
                'body' => 'Incident critique détecté.',
                'context' => ['site_name' => 'Site de test'],
                'channels' => [
                    'email' => [
                        'enabled' => true,
                        'status' => 'completed',
                        'attempts' => 1,
                        'recipients' => ['ops@example.com'],
                    ],
                    'slack' => [
                        'enabled' => true,
                        'status' => 'completed',
                        'attempts' => 1,
                        'webhook_url' => 'https://example.com/webhooks/slack',
                    ],
                    'sms' => [
                        'enabled' => true,
                        'status' => 'completed',
                        'attempts' => 1,
                        'webhook_url' => 'https://example.com/webhooks/sms',
                    ],
                ],
                'severity' => 'critical',
                'resolution' => [
                    'acknowledged_at' => $now - 300,
                    'resolved_at' => null,
                    'summary' => $resolvedSummary,
                    'steps' => [
                        [
                            'timestamp' => $now - 600,
                            'actor' => 'Alice',
                            'summary' => 'Diagnostic initial.',
                            'type' => 'update',
                        ],
                        [
                            'timestamp' => $now - 120,
                            'actor' => 'Bob',
                            'summary' => 'Relance du service effectif.',
                            'type' => 'update',
                        ],
                    ],
                    'actions' => ['Redémarrage du service sauvegarde'],
                ],
            ],
        ]);

        new BJLG_Notifications();

        BJLG_Notification_Queue::resolve_entry('resolve-broadcast', 42, 'Consignation finale');

        $mailLogs = $GLOBALS['bjlg_test_transports']['mail'] ?? [];
        $remoteLogs = $GLOBALS['bjlg_test_transports']['remote_post'] ?? [];

        $this->assertNotEmpty($mailLogs, 'Le canal e-mail doit être sollicité.');

        $slackCalls = array_filter($remoteLogs, static function ($call) {
            return isset($call['url']) && $call['url'] === 'https://example.com/webhooks/slack';
        });
        $smsCalls = array_filter($remoteLogs, static function ($call) {
            return isset($call['url']) && $call['url'] === 'https://example.com/webhooks/sms';
        });

        $this->assertNotEmpty($slackCalls, 'Le canal Slack doit être sollicité.');
        $this->assertNotEmpty($smsCalls, 'Le canal SMS doit être sollicité.');

        $this->assertNotEmpty($history, 'Un log BJLG_History est attendu.');
        $historyActions = array_column($history, 'action');
        $this->assertContains('notification_resolution_broadcast', $historyActions);
        $this->assertStringContainsString('Résumé', $history[0]['details']);

        $snapshot = BJLG_Notification_Queue::get_queue_snapshot();
        $this->assertNotEmpty($snapshot['entries']);
        $snapshotEntry = $snapshot['entries'][0];
        $this->assertSame('resolve-broadcast', $snapshotEntry['id']);
        $this->assertSame('resolved', $snapshotEntry['resolution_status']);
        $this->assertSame($resolvedSummary, $snapshotEntry['resolution_summary']);
        $this->assertNotEmpty($snapshotEntry['resolution_steps']);

        $row = $this->getTableRow('resolve-broadcast');
        $this->assertNotNull($row);
        $this->assertSame($resolvedSummary, $row['resolution_summary']);
        $this->assertStringContainsString('Consignation finale', (string) $row['resolution_notes']);
        $rowSteps = json_decode((string) $row['resolution_steps'], true);
        $this->assertIsArray($rowSteps);
        $this->assertCount(2, $rowSteps);

        if ($previous_history_hooks === null) {
            unset($GLOBALS['bjlg_test_hooks']['actions']['bjlg_history_logged']);
        } else {
            $GLOBALS['bjlg_test_hooks']['actions']['bjlg_history_logged'] = $previous_history_hooks;
        }
    }

    private function resetQueueStorage(): void
    {
        $table = BJLG_Notification_Queue::get_table_name();
        $GLOBALS['wpdb']->tables[$table] = [];
        bjlg_update_option('bjlg_notification_queue', []);
        BJLG_Notification_Queue::create_tables();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getQueueEntries(): array
    {
        $table = BJLG_Notification_Queue::get_table_name();
        $rows = $GLOBALS['wpdb']->tables[$table] ?? [];
        $entries = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['queue_data'])) {
                continue;
            }

            $decoded = json_decode((string) $row['queue_data'], true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return array_values($entries);
    }

    private function getTableRow(string $entryId): ?array
    {
        $table = BJLG_Notification_Queue::get_table_name();
        $rows = $GLOBALS['wpdb']->tables[$table] ?? [];

        if (isset($rows[$entryId]) && is_array($rows[$entryId])) {
            return $rows[$entryId];
        }

        return null;
    }

    private function resetNotificationsInstance(): void
    {
        $property = new \ReflectionProperty(BJLG_Notifications::class, 'instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}

}
