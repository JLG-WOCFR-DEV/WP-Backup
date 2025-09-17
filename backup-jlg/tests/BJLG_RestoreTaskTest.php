<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-restore.php';

final class BJLG_RestoreTaskTest extends TestCase
{
    /** @var mixed */
    private $previous_wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

        $GLOBALS['wpdb'] = new class {
            /** @var array<int, string> */
            public $queries = [];

            /** @var string */
            public $last_error = '';

            /** @var string */
            public $options = 'wp_options';

            /**
             * @param string $query
             * @return int
             */
            public function query($query)
            {
                $this->queries[] = (string) $query;
                $this->last_error = '';

                return 1;
            }
        };

        $GLOBALS['bjlg_test_transients'] = [];
    }

    protected function tearDown(): void
    {
        if ($this->previous_wpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
        }

        parent::tearDown();
    }

    public function test_run_restore_task_completes_for_database_only_backup(): void
    {
        $temporary_dir = sys_get_temp_dir() . '/bjlg-restore-db-only-' . uniqid('', true);
        if (!is_dir($temporary_dir)) {
            mkdir($temporary_dir, 0755, true);
        }

        $zip_path = $temporary_dir . '/db-only.zip';

        $zip = new ZipArchive();
        $open_result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($open_result === true || $open_result === ZipArchive::ER_OK);

        $manifest = [
            'type' => 'test',
            'contains' => ['db'],
        ];

        $zip->addFromString('backup-manifest.json', json_encode($manifest));
        $zip->addFromString('database.sql', "CREATE TABLE `wp_test` (id INT);\nINSERT INTO `wp_test` VALUES (1);\n");
        $zip->close();

        $destination = BJLG_BACKUP_DIR . 'db-only-' . uniqid('', true) . '.zip';
        copy($zip_path, $destination);

        $restore = new BJLG\BJLG_Restore();

        $task_id = 'bjlg_restore_' . uniqid('', true);
        set_transient($task_id, [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => '',
            'filename' => basename($destination),
            'filepath' => $destination,
            'password_encrypted' => null,
        ], defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);

        $restore->run_restore_task($task_id);

        $task_data = get_transient($task_id);
        $this->assertIsArray($task_data);
        $this->assertSame('complete', $task_data['status']);
        $this->assertSame(100, $task_data['progress']);
        $this->assertSame('Restauration terminée avec succès !', $task_data['status_text']);

        if (file_exists($destination)) {
            unlink($destination);
        }

        if (file_exists($zip_path)) {
            unlink($zip_path);
        }

        if (is_dir($temporary_dir)) {
            rmdir($temporary_dir);
        }
    }
}
