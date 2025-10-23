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
            return [
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'body' => '',
                'url' => $url,
                'args' => $args,
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
            return true;
        }
    }
}

namespace BJLG\Tests {

use BJLG\BJLG_Notifications;
use BJLG\BJLG_Remote_Storage_Metrics;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

require_once __DIR__ . '/../backup-jlg.php';
require_once __DIR__ . '/../includes/class-bjlg-notification-queue.php';
require_once __DIR__ . '/../includes/class-bjlg-notification-transport.php';
require_once __DIR__ . '/../includes/class-bjlg-notifications.php';
require_once __DIR__ . '/../includes/class-bjlg-remote-storage-metrics.php';

final class BJLG_NotificationsStorageWarningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        bjlg_update_option('bjlg_notification_queue', []);
        bjlg_update_option(BJLG_Remote_Storage_Metrics::WARNING_DIGEST_OPTION, []);
        bjlg_update_option('bjlg_notification_settings', [
            'enabled' => true,
            'email_recipients' => 'ops@example.com, alerts@example.com',
            'events' => [
                'storage_warning' => true,
            ],
            'channels' => [
                'email' => ['enabled' => true],
                'slack' => [
                    'enabled' => true,
                    'webhook_url' => 'https://example.com/webhooks/slack',
                ],
            ],
        ]);
        $this->resetNotificationsInstance();
    }

    protected function tearDown(): void
    {
        $this->resetNotificationsInstance();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function storageWarningDestinationsProvider(): iterable
    {
        yield 's3' => ['s3://backups/site'];
        yield 'azure' => ['azure://container/backups'];
        yield 'b2' => ['b2://bucket-name/backups'];
    }

    /**
     * @dataProvider storageWarningDestinationsProvider
     */
    public function test_handle_storage_warning_enqueues_notification_for_each_destination(string $path): void
    {
        $notifications = new BJLG_Notifications();

        $notifications->handle_storage_warning([
            'free_space' => '2048',
            'threshold' => '90',
            'path' => $path,
        ]);

        $queue = bjlg_get_option('bjlg_notification_queue', []);
        $this->assertCount(1, $queue);
        $entry = $queue[0];

        $this->assertSame('storage_warning', $entry['event']);
        $this->assertSame(2048, $entry['context']['free_space']);
        $this->assertSame(90, $entry['context']['threshold']);
        $this->assertSame($path, $entry['context']['path']);
        $this->assertSame('warning', $entry['severity']);
        $this->assertArrayHasKey('email', $entry['channels']);
        $this->assertArrayHasKey('slack', $entry['channels']);
    }

    public function test_handle_storage_warning_falls_back_to_local_channel_when_remote_invalid(): void
    {
        bjlg_update_option('bjlg_notification_queue', []);
        bjlg_update_option('bjlg_notification_settings', [
            'enabled' => true,
            'email_recipients' => 'ops@example.com',
            'events' => [
                'storage_warning' => true,
            ],
            'channels' => [
                'email' => ['enabled' => true],
                'slack' => [
                    'enabled' => true,
                    'webhook_url' => 'invalid-url',
                ],
            ],
        ]);
        $this->resetNotificationsInstance();
        $notifications = new BJLG_Notifications();

        $notifications->handle_storage_warning([
            'free_space' => 1024,
            'threshold' => 85,
            'path' => 's3://critical/backups',
        ]);

        $queue = bjlg_get_option('bjlg_notification_queue', []);
        $this->assertCount(1, $queue);
        $entry = $queue[0];

        $this->assertArrayHasKey('email', $entry['channels']);
        $this->assertArrayNotHasKey('slack', $entry['channels']);
        $this->assertSame(1024, $entry['context']['free_space']);
        $this->assertSame(85, $entry['context']['threshold']);
        $this->assertSame('s3://critical/backups', $entry['context']['path']);
    }

    private function resetNotificationsInstance(): void
    {
        $property = new ReflectionProperty(BJLG_Notifications::class, 'instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}

}

