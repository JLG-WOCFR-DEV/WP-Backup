<?php

namespace BJLG\Tests;

use BJLG\BJLG_Notifications;
use BJLG\BJLG_Restore_Self_Test;
use PHPUnit\Framework\TestCase;

final class BJLG_RestoreSelfTestReportTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        $report = get_option('bjlg_restore_self_test_report');
        if (is_array($report) && !empty($report['files'])) {
            $files = $report['files'];
            foreach (['json', 'html', 'markdown'] as $type) {
                if (!empty($files[$type]['path']) && file_exists($files[$type]['path'])) {
                    @unlink($files[$type]['path']);
                }
            }
            if (!empty($files['base_path']) && is_dir($files['base_path'])) {
                bjlg_tests_recursive_delete($files['base_path']);
            }
        }

        update_option('bjlg_restore_self_test_report', []);
    }

    public function test_finalize_report_computes_metrics_and_persists_option(): void
    {
        $startedAt = time();
        $archivePath = tempnam(bjlg_get_backup_directory(), 'self-test-');
        $this->assertNotFalse($archivePath);

        $archiveMtime = $startedAt - 7200;
        touch($archivePath, $archiveMtime);

        $report = [
            'archive' => basename($archivePath),
            'archive_path' => $archivePath,
            'message' => 'OK',
            'duration' => 12.75,
            'started_at' => $startedAt,
            'completed_at' => $startedAt + 15,
        ];

        $subject = new class extends BJLG_Restore_Self_Test {
            public function finalizeForTest(array $report, string $status, int $startedAt, ?string $archivePath, ?int $archiveMtime): array
            {
                return $this->finalize_report($status, $report, $startedAt, $archivePath, $archiveMtime);
            }
        };

        $enriched = $subject->finalizeForTest($report, 'success', $startedAt, $archivePath, $archiveMtime);
        @unlink($archivePath);

        $this->assertArrayHasKey('metrics', $enriched);
        $this->assertSame(12.75, $enriched['metrics']['rto_seconds']);
        $this->assertSame($startedAt - $archiveMtime, $enriched['metrics']['rpo_seconds']);
        $this->assertArrayHasKey('report_files', $enriched);
        $this->assertArrayHasKey('html', $enriched['report_files']);
        $this->assertFileExists($enriched['report_files']['html']['path']);

        $stored = get_option('bjlg_restore_self_test_report');
        $this->assertIsArray($stored);
        $this->assertSame('success', $stored['status']);
        $this->assertSame($enriched['metrics']['rto_seconds'], $stored['metrics']['rto_seconds']);
        $this->assertSame($enriched['metrics']['rpo_seconds'], $stored['metrics']['rpo_seconds']);
        $this->assertNotEmpty($stored['files']['html']['path']);
        $this->assertFileExists($stored['files']['html']['path']);
    }

    public function test_notifications_context_includes_rto_rpo(): void
    {
        $settings = [
            'enabled' => true,
            'email_recipients' => 'alerts@example.com',
            'events' => [
                'restore_self_test_passed' => true,
                'restore_self_test_failed' => true,
            ],
            'channels' => [
                'email' => ['enabled' => true],
            ],
        ];

        bjlg_update_option('bjlg_notification_settings', $settings);

        $notifications = BJLG_Notifications::instance();
        $notifications->handle_settings_saved(['notifications' => $settings]);

        $captured = [];
        $filter = static function ($payload, $event, $context) use (&$captured) {
            if ($event === 'restore_self_test_passed') {
                $captured['passed'] = $context;
            }
            if ($event === 'restore_self_test_failed') {
                $captured['failed'] = $context;
            }

            return [
                'title' => $payload['title'] ?? 'Test',
                'lines' => [],
                'context' => $context,
                'severity' => $payload['severity'] ?? 'info',
            ];
        };

        add_filter('bjlg_notification_payload', $filter, 10, 3);

        $report = [
            'archive' => 'backup.zip',
            'duration' => 15.5,
            'started_at' => time(),
            'completed_at' => time(),
            'metrics' => [
                'rto_seconds' => 15.5,
                'rpo_seconds' => 3600,
                'rto_human' => '15 secondes',
                'rpo_human' => 'il y a 1 heure',
            ],
            'attachments' => [
                'summary_markdown' => [
                    'filename' => 'rapport.md',
                    'path' => '/tmp/rapport.md',
                ],
            ],
            'report_files' => [
                'html' => ['url' => 'https://example.com/report.html'],
            ],
            'exception' => 'Erreur',
        ];

        $notifications->handle_restore_self_test_passed($report);
        $notifications->handle_restore_self_test_failed($report);
        remove_filter('bjlg_notification_payload', $filter, 10);

        $this->assertArrayHasKey('passed', $captured);
        $this->assertSame(15.5, $captured['passed']['rto_seconds']);
        $this->assertSame(3600, $captured['passed']['rpo_seconds']);
        $this->assertSame('15 secondes', $captured['passed']['rto_human']);
        $this->assertSame('il y a 1 heure', $captured['passed']['rpo_human']);
        $this->assertArrayHasKey('attachments', $captured['passed']);
        $this->assertArrayHasKey('report_files', $captured['passed']);

        $this->assertArrayHasKey('failed', $captured);
        $this->assertSame(15.5, $captured['failed']['rto_seconds']);
        $this->assertSame(3600, $captured['failed']['rpo_seconds']);
        $this->assertSame('Erreur', $captured['failed']['error']);
    }
}
