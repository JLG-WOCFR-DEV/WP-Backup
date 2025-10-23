<?php
declare(strict_types=1);

namespace BJLG\Tests;

use BJLG\BJLG_Notifications;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

require_once __DIR__ . '/../backup-jlg.php';
require_once __DIR__ . '/../includes/class-bjlg-notification-queue.php';
require_once __DIR__ . '/../includes/class-bjlg-notification-transport.php';
require_once __DIR__ . '/../includes/class-bjlg-notifications.php';

final class BJLG_NotificationsStorageWarningTest extends TestCase
{
    /** @var array<string, array<int, callable>> */
    private $previousHooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousHooks = $GLOBALS['bjlg_test_hooks'] ?? ['actions' => [], 'filters' => []];
        $GLOBALS['bjlg_test_hooks'] = ['actions' => [], 'filters' => []];
        $GLOBALS['bjlg_test_options'] = [];
        $GLOBALS['bjlg_test_site_options'] = [];
        $GLOBALS['bjlg_test_transients'] = [];
        $this->resetNotificationsSingleton();
        bjlg_update_option('bjlg_notification_queue', []);
    }

    protected function tearDown(): void
    {
        $this->resetNotificationsSingleton();
        $GLOBALS['bjlg_test_hooks'] = $this->previousHooks;
        parent::tearDown();
    }

    private function resetNotificationsSingleton(): void
    {
        if (!class_exists(BJLG_Notifications::class)) {
            return;
        }

        $reflection = new ReflectionProperty(BJLG_Notifications::class, 'instance');
        $reflection->setAccessible(true);
        $reflection->setValue(null, null);
    }

    private function bootstrapNotifications(): BJLG_Notifications
    {
        $this->resetNotificationsSingleton();

        bjlg_update_option('bjlg_notification_settings', [
            'enabled' => true,
            'email_recipients' => 'alerts@example.com',
            'channels' => [
                'email' => [
                    'enabled' => true,
                ],
            ],
            'events' => [
                'storage_warning' => true,
            ],
        ]);

        return new BJLG_Notifications();
    }

    public function test_handle_storage_warning_enqueues_local_notification(): void
    {
        $notifications = $this->bootstrapNotifications();

        $captured = [];
        add_filter('bjlg_notification_payload', static function ($payload, $event, $context) use (&$captured) {
            $captured = [
                'payload' => $payload,
                'event' => $event,
                'context' => $context,
            ];

            return $payload;
        }, 10, 3);

        $queued = [];
        add_action('bjlg_notification_queued', static function ($entry) use (&$queued) {
            $queued[] = $entry;
        });

        $notifications->handle_storage_warning([
            'free_space' => 512 * 1024 * 1024,
            'threshold' => 1024 * 1024 * 1024,
            'path' => '/var/backups',
        ]);

        $this->assertNotEmpty($captured);
        $this->assertSame('storage_warning', $captured['event']);
        $context = $captured['context'];
        $this->assertSame(512 * 1024 * 1024, $context['free_space']);
        $this->assertSame(1024 * 1024 * 1024, $context['threshold']);
        $this->assertSame('/var/backups', $context['path']);
        $this->assertArrayNotHasKey('destination_id', $context);

        $lines = $captured['payload']['lines'];
        $this->assertContains("L'espace disque disponible devient critique.", $lines);
        $this->assertContains('Chemin surveillé : /var/backups', $lines);

        $this->assertCount(1, $queued);
        $this->assertSame('storage_warning', $queued[0]['event']);
        $this->assertSame('/var/backups', $queued[0]['context']['path']);
    }

    /**
     * @return array<int, array{string, string, float}>
     */
    public function remoteWarningProvider(): array
    {
        return [
            ['aws_s3', 'Amazon S3', 0.92],
            ['azure_blob', 'Azure Blob', 0.81],
            ['backblaze_b2', 'Backblaze B2', 0.87],
        ];
    }

    /**
     * @dataProvider remoteWarningProvider
     */
    public function test_handle_storage_warning_formats_remote_notifications(string $destinationId, string $name, float $ratio): void
    {
        $notifications = $this->bootstrapNotifications();

        $captured = [];
        add_filter('bjlg_notification_payload', static function ($payload, $event, $context) use (&$captured) {
            $captured = [
                'payload' => $payload,
                'event' => $event,
                'context' => $context,
            ];

            return $payload;
        }, 10, 3);

        $queued = [];
        add_action('bjlg_notification_queued', static function ($entry) use (&$queued) {
            $queued[] = $entry;
        });

        $notifications->handle_storage_warning([
            'destination_id' => $destinationId,
            'name' => $name,
            'ratio' => $ratio,
            'threshold_percent' => 85,
            'used_bytes' => 920000000,
            'quota_bytes' => 1000000000,
        ]);

        $this->assertNotEmpty($captured);
        $this->assertSame('storage_warning', $captured['event']);
        $context = $captured['context'];
        $this->assertSame($destinationId, $context['destination_id']);
        $this->assertSame($name, $context['destination_name']);
        $this->assertSame(sprintf('%s (%s)', $name, $destinationId), $context['path']);
        $this->assertSame(1000000000 - 920000000, $context['free_space']);
        $this->assertSame(85.0, $context['threshold_percent']);
        $this->assertSame($ratio, $context['ratio']);
        $this->assertSame(920000000, $context['used_bytes']);
        $this->assertSame(1000000000, $context['quota_bytes']);
        $this->assertSame(1000000000 - 920000000, $context['free_bytes']);

        $lines = $captured['payload']['lines'];
        $combined = implode("\n", $lines);
        $this->assertStringContainsString($name, $combined);
        $this->assertStringContainsString('Utilisation actuelle', $combined);
        $this->assertStringContainsString('Seuil configuré', $combined);
        $this->assertStringContainsString('Quota total', $combined);
        $this->assertStringContainsString('Espace utilisé', $combined);
        $this->assertStringContainsString('Espace libre estimé', $combined);

        $this->assertCount(1, $queued);
        $this->assertSame('storage_warning', $queued[0]['event']);
        $this->assertSame($destinationId, $queued[0]['context']['destination_id']);
    }
}
