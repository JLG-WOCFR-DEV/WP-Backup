<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-debug.php';
require_once __DIR__ . '/../includes/class-bjlg-settings.php';
require_once __DIR__ . '/../includes/class-bjlg-backup.php';
require_once __DIR__ . '/../includes/class-bjlg-notification-queue.php';
require_once __DIR__ . '/../includes/class-bjlg-update-guard.php';

if (!class_exists('BJLG_Test_BackupStub')) {
    class BJLG_Test_BackupStub extends BJLG\BJLG_Backup
    {
        /** @var array<int, string> */
        public $task_ids = [];

        public function __construct()
        {
            // Ne pas exécuter le constructeur parent pour éviter l'enregistrement de hooks.
        }

        public function run_backup_task($task_id)
        {
            $this->task_ids[] = (string) $task_id;
        }
    }
}

final class BJLG_UpdateGuardTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_options'] = [];
        $GLOBALS['bjlg_test_filters'] = [];
        $GLOBALS['bjlg_test_scheduled_events'] = [
            'single' => [],
            'recurring' => [],
        ];
        unset($GLOBALS['bjlg_test_set_transient_mock']);

        if (!isset($GLOBALS['bjlg_test_hooks'])) {
            $GLOBALS['bjlg_test_hooks'] = [
                'actions' => [],
                'filters' => [],
            ];
        } else {
            $GLOBALS['bjlg_test_hooks']['actions'] = [];
            $GLOBALS['bjlg_test_hooks']['filters'] = [];
        }
    }

    public function test_launches_backup_before_plugin_update(): void
    {
        $backup_stub = new BJLG_Test_BackupStub();
        $guard = new BJLG\BJLG_Update_Guard($backup_stub);

        $captured_history = [];
        add_action(
            'bjlg_history_logged',
            static function ($action, $status, $details) use (&$captured_history): void {
                $captured_history[] = [$action, $status, $details];
            },
            10,
            3
        );

        $hook_extra = [
            'type' => 'plugin',
            'action' => 'update',
            'plugins' => ['hello/hello.php'],
        ];

        $task_id = $guard->maybe_trigger_pre_update_backup($hook_extra);

        $this->assertNotNull($task_id);
        $this->assertSame([$task_id], $backup_stub->task_ids);
        $this->assertArrayHasKey($task_id, $GLOBALS['bjlg_test_transients']);

        $task_data = $GLOBALS['bjlg_test_transients'][$task_id];
        $this->assertSame('pre_update', $task_data['source']);
        $this->assertSame(['hello/hello.php'], $task_data['update_context']['items']);
        $this->assertSame('la mise à jour de l\'extension', $task_data['update_context']['label']);

        $this->assertNotEmpty($captured_history);
        $this->assertSame('pre_update_backup', $captured_history[0][0]);
        $this->assertSame('info', $captured_history[0][1]);
    }

    public function test_skips_duplicate_contexts(): void
    {
        $backup_stub = new BJLG_Test_BackupStub();
        $guard = new BJLG\BJLG_Update_Guard($backup_stub);

        $hook_extra = [
            'type' => 'plugin',
            'action' => 'update',
            'plugins' => ['classic-editor/classic-editor.php'],
        ];

        $first = $guard->maybe_trigger_pre_update_backup($hook_extra);
        $second = $guard->maybe_trigger_pre_update_backup($hook_extra);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertCount(1, $backup_stub->task_ids);
    }

    public function test_ignores_non_update_actions(): void
    {
        $backup_stub = new BJLG_Test_BackupStub();
        $guard = new BJLG\BJLG_Update_Guard($backup_stub);

        $hook_extra = [
            'type' => 'plugin',
            'action' => 'install',
            'plugin' => 'new-plugin/new-plugin.php',
        ];

        $task_id = $guard->maybe_trigger_pre_update_backup($hook_extra);

        $this->assertNull($task_id);
        $this->assertEmpty($backup_stub->task_ids);
        $this->assertEmpty($GLOBALS['bjlg_test_transients']);
    }

    public function test_handle_pre_install_is_backward_compatible_with_two_arguments(): void
    {
        $backup_stub = new BJLG_Test_BackupStub();
        new BJLG\BJLG_Update_Guard($backup_stub);

        $hook_extra = [
            'type' => 'plugin',
            'action' => 'update',
            'plugin' => 'akismet/akismet.php',
        ];

        $result = apply_filters('upgrader_pre_install', true, $hook_extra);

        $this->assertTrue($result);
        $this->assertCount(1, $backup_stub->task_ids);
    }

    public function test_disable_filter_prevents_backup_creation(): void
    {
        $backup_stub = new BJLG_Test_BackupStub();
        $guard = new BJLG\BJLG_Update_Guard($backup_stub);

        add_filter(
            'bjlg_pre_update_backup_enabled',
            static function ($enabled, array $context, $hook_extra) {
                return false;
            },
            10,
            3
        );

        $hook_extra = [
            'type' => 'plugin',
            'action' => 'update',
            'plugin' => 'woocommerce/woocommerce.php',
        ];

        $task_id = $guard->maybe_trigger_pre_update_backup($hook_extra);

        $this->assertNull($task_id);
        $this->assertEmpty($backup_stub->task_ids);
        $this->assertEmpty($GLOBALS['bjlg_test_transients']);

        unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_pre_update_backup_enabled']);
    }

    public function test_signature_is_not_blocked_after_blueprint_rejection(): void
    {
        $backup_stub = new BJLG_Test_BackupStub();
        $guard = new BJLG\BJLG_Update_Guard($backup_stub);

        add_filter(
            'bjlg_pre_update_backup_blueprint',
            static function ($blueprint) {
                $blueprint['components'] = [];

                return $blueprint;
            },
            10,
            3
        );

        $hook_extra = [
            'type' => 'plugin',
            'action' => 'update',
            'plugin' => 'retry-plugin/retry.php',
        ];

        $first_attempt = $guard->maybe_trigger_pre_update_backup($hook_extra);

        $this->assertNull($first_attempt);
        $this->assertEmpty($backup_stub->task_ids);

        unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_pre_update_backup_blueprint']);

        $second_attempt = $guard->maybe_trigger_pre_update_backup($hook_extra);

        $this->assertNotNull($second_attempt);
        $this->assertCount(1, $backup_stub->task_ids);
    }

    public function test_signature_is_not_blocked_when_transient_creation_fails(): void
    {
        $backup_stub = new BJLG_Test_BackupStub();
        $guard = new BJLG\BJLG_Update_Guard($backup_stub);

        $GLOBALS['bjlg_test_set_transient_mock'] = static function () {
            return false;
        };

        $hook_extra = [
            'type' => 'plugin',
            'action' => 'update',
            'plugin' => 'unstable-plugin/unstable.php',
        ];

        $first_attempt = $guard->maybe_trigger_pre_update_backup($hook_extra);

        $this->assertNull($first_attempt);
        $this->assertEmpty($backup_stub->task_ids);

        unset($GLOBALS['bjlg_test_set_transient_mock']);

        $second_attempt = $guard->maybe_trigger_pre_update_backup($hook_extra);

        $this->assertNotNull($second_attempt);
        $this->assertCount(1, $backup_stub->task_ids);
    }

    public function test_disabled_setting_triggers_reminder(): void
    {
        bjlg_update_option('bjlg_update_guard_settings', [
            'enabled' => false,
            'components' => ['db', 'plugins'],
            'reminder' => ['enabled' => true, 'message' => 'Pensez à sauvegarder avant la mise à jour.'],
        ]);

        $backup_stub = new BJLG_Test_BackupStub();
        $guard = new BJLG\BJLG_Update_Guard($backup_stub);

        $reminders = [];
        add_action(
            'bjlg_pre_update_backup_reminder',
            static function ($context, $reason, $reminder) use (&$reminders): void {
                $reminders[] = [$context, $reason, $reminder];
            },
            10,
            3
        );

        $hook_extra = [
            'type' => 'plugin',
            'action' => 'update',
            'plugin' => 'akismet/akismet.php',
        ];

        $task_id = $guard->maybe_trigger_pre_update_backup($hook_extra);

        $this->assertNull($task_id);
        $this->assertEmpty($backup_stub->task_ids);
        $this->assertCount(1, $reminders);
        $this->assertSame('disabled', $reminders[0][1]);
        $this->assertSame('Pensez à sauvegarder avant la mise à jour.', $reminders[0][2]['message']);
    }

    public function test_components_are_filtered_by_settings(): void
    {
        bjlg_update_option('bjlg_update_guard_settings', [
            'enabled' => true,
            'components' => ['db'],
            'reminder' => ['enabled' => false, 'message' => ''],
        ]);

        $backup_stub = new BJLG_Test_BackupStub();
        $guard = new BJLG\BJLG_Update_Guard($backup_stub);

        $hook_extra = [
            'type' => 'plugin',
            'action' => 'update',
            'plugin' => 'hello/hello.php',
        ];

        $task_id = $guard->maybe_trigger_pre_update_backup($hook_extra);

        $this->assertNotNull($task_id);
        $this->assertSame([$task_id], $backup_stub->task_ids);
        $this->assertArrayHasKey($task_id, $GLOBALS['bjlg_test_transients']);
        $this->assertSame(['db'], $GLOBALS['bjlg_test_transients'][$task_id]['components']);
    }

    public function test_targeted_mode_selects_components_by_context(): void
    {
        bjlg_update_option('bjlg_update_guard_settings', [
            'enabled' => true,
            'mode' => 'targeted',
            'components' => ['db', 'plugins', 'themes', 'uploads'],
            'reminder' => ['enabled' => false, 'message' => ''],
        ]);

        $backup_stub = new BJLG_Test_BackupStub();
        $guard = new BJLG\BJLG_Update_Guard($backup_stub);

        $hook_extra = [
            'type' => 'plugin',
            'action' => 'update',
            'plugin' => 'security/security.php',
        ];

        $task_id = $guard->maybe_trigger_pre_update_backup($hook_extra);

        $this->assertNotNull($task_id);
        $this->assertSame(['db', 'plugins'], $GLOBALS['bjlg_test_transients'][$task_id]['components']);
    }

    public function test_reminder_with_delay_is_scheduled(): void
    {
        bjlg_update_option('bjlg_update_guard_settings', [
            'enabled' => false,
            'mode' => 'full',
            'components' => ['db', 'plugins'],
            'reminder' => [
                'enabled' => true,
                'message' => 'Déclenchez un snapshot manuel.',
                'delay_minutes' => 10,
                'channels' => [
                    'notification' => ['enabled' => true],
                    'email' => ['enabled' => false, 'recipients' => ''],
                ],
            ],
        ]);

        $backup_stub = new BJLG_Test_BackupStub();
        $guard = new BJLG\BJLG_Update_Guard($backup_stub);

        $reminder_calls = 0;
        add_action('bjlg_pre_update_backup_reminder', static function () use (&$reminder_calls): void {
            $reminder_calls++;
        }, 10, 4);

        $hook_extra = [
            'type' => 'plugin',
            'action' => 'update',
            'plugin' => 'delayed-plugin/delayed.php',
        ];

        $task_id = $guard->maybe_trigger_pre_update_backup($hook_extra);

        $this->assertNull($task_id);
        $this->assertSame(0, $reminder_calls);
        $this->assertNotEmpty($GLOBALS['bjlg_test_scheduled_events']['single']);
        $event = $GLOBALS['bjlg_test_scheduled_events']['single'][0];
        $this->assertSame('bjlg_pre_update_snapshot_reminder_event', $event['hook']);
        $this->assertGreaterThan(time(), $event['timestamp']);

        $pending = bjlg_get_option('bjlg_update_guard_pending_reminders', []);
        $this->assertIsArray($pending);
        $this->assertNotEmpty($pending);
        $this->assertArrayHasKey($event['args'][0], $pending);
    }
}
