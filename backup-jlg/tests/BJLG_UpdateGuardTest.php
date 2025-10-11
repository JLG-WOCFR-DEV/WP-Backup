<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-debug.php';
require_once __DIR__ . '/../includes/class-bjlg-settings.php';
require_once __DIR__ . '/../includes/class-bjlg-backup.php';
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
}
