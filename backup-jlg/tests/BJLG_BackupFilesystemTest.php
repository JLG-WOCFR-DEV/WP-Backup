<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!class_exists('BJLG\\BJLG_Debug')) {
    class BJLG_Debug
    {
        /** @var array<int, string> */
        public static $logs = [];

        /**
         * @param mixed $message
         */
        public static function log($message): void
        {
            self::$logs[] = (string) $message;
        }
    }

    class_alias('BJLG_Debug', 'BJLG\\BJLG_Debug');
}

require_once __DIR__ . '/../includes/class-bjlg-backup.php';
require_once __DIR__ . '/../includes/class-bjlg-incremental.php';

final class BJLG_BackupFilesystemTest extends TestCase
{
    protected function setUp(): void
    {
        if (class_exists('BJLG\\BJLG_Debug')) {
            BJLG_Debug::$logs = [];
        }
    }

    public function test_add_folder_to_zip_throws_exception_when_directory_cannot_be_opened(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $zip = new class extends ZipArchive {
            /** @var array<int, array{0: string, 1: string}> */
            public $addedFiles = [];

            public function addFile(string $filepath, string $entryname = "", int $start = 0, int $length = ZipArchive::LENGTH_TO_END, int $flags = ZipArchive::FL_OVERWRITE): bool
            {
                $this->addedFiles[] = [$filepath, $entryname];
                return true;
            }
        };

        $nonexistentFolder = sys_get_temp_dir() . '/bjlg-missing-' . uniqid('', true);

        try {
            $backup->add_folder_to_zip($zip, $nonexistentFolder, 'wp-content/plugins/');
            $this->fail('Une exception aurait dû être levée lorsque le répertoire est introuvable.');
        } catch (Exception $exception) {
            $this->assertStringContainsString($nonexistentFolder, $exception->getMessage());
        }

        $this->assertSame([], $zip->addedFiles);
        $this->assertNotEmpty(BJLG_Debug::$logs);
        $this->assertStringContainsString($nonexistentFolder, BJLG_Debug::$logs[0]);
    }

    public function test_backup_migration_plugin_is_not_excluded_by_default(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $zip = new class extends ZipArchive {
            /** @var array<int, array{0: string, 1: string}> */
            public $addedFiles = [];

            public function addFile(string $filepath, string $entryname = "", int $start = 0, int $length = ZipArchive::LENGTH_TO_END, int $flags = ZipArchive::FL_OVERWRITE): bool
            {
                $this->addedFiles[] = [$filepath, $entryname];
                return true;
            }
        };

        $root = sys_get_temp_dir() . '/bjlg-plugins-' . uniqid('', true);
        $plugins_dir = $root . '/wp-content/plugins';
        $backup_migration_dir = $plugins_dir . '/backup-migration';

        if (!is_dir($backup_migration_dir) && !mkdir($backup_migration_dir, 0777, true) && !is_dir($backup_migration_dir)) {
            $this->fail('Unable to create the backup-migration plugin directory for the test.');
        }

        $plugin_file = $backup_migration_dir . '/plugin.php';
        file_put_contents($plugin_file, "<?php\n// backup-migration test plugin\n");

        $reflection = new ReflectionMethod(BJLG\BJLG_Backup::class, 'get_exclude_patterns');
        $reflection->setAccessible(true);
        /** @var array<int, string> $exclude_patterns */
        $exclude_patterns = $reflection->invoke($backup, $plugins_dir, 'wp-content/plugins/');

        $backup->add_folder_to_zip($zip, $plugins_dir, 'wp-content/plugins/', $exclude_patterns);

        $added_files = array_map(static fn($entry) => $entry[0], $zip->addedFiles);
        $this->assertContains($plugin_file, $added_files, 'The backup-migration plugin should be included in the archive by default.');
    }

    public function test_incremental_backup_includes_windows_style_modified_file(): void
    {
        $root = sys_get_temp_dir() . '/bjlg-win-' . uniqid('', true);
        $windows_dir = $root . '/C:\\temp';

        if (!is_dir($windows_dir) && !mkdir($windows_dir, 0777, true) && !is_dir($windows_dir)) {
            $this->fail('Unable to create the Windows-style directory for the test.');
        }

        $file_path = $windows_dir . '/example.txt';
        file_put_contents($file_path, 'example');

        try {
            $incremental = new BJLG\BJLG_Incremental();
            $modified_files = $incremental->get_modified_files($windows_dir);

            $this->assertCount(1, $modified_files);
            $expected_path = str_replace('\\', '/', (string) realpath($file_path));
            $this->assertSame($expected_path, $modified_files[0]);

            $zip = new class extends ZipArchive {
                /** @var array<int, array{0: string, 1: string}> */
                public $addedFiles = [];

                public function addFile(string $filepath, string $entryname = "", int $start = 0, int $length = ZipArchive::LENGTH_TO_END, int $flags = ZipArchive::FL_OVERWRITE): bool
                {
                    $this->addedFiles[] = [$filepath, $entryname];
                    return true;
                }

                public function setCompressionName(string $name, int $method, int $compflags = 0): bool
                {
                    return true;
                }
            };

            $backup = new BJLG\BJLG_Backup();
            $backup->add_folder_to_zip($zip, $windows_dir, 'windows/', [], true, $modified_files);

            $this->assertCount(1, $zip->addedFiles);
            $this->assertSame($file_path, $zip->addedFiles[0][0]);
            $this->assertSame('windows/example.txt', $zip->addedFiles[0][1]);
        } finally {
            @unlink($file_path);
            @rmdir($windows_dir);
            @rmdir($root);
        }
    }
}
