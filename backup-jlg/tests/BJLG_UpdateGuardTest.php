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
}
