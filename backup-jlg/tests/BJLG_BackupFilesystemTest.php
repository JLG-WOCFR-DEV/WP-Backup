<?php
declare(strict_types=1);

namespace BJLG {
    if (!function_exists(__NAMESPACE__ . '\\function_exists')) {
        function function_exists($function)
        {
            if ($function === 'fnmatch' || $function === '\\fnmatch') {
                return false;
            }

            return \function_exists($function);
        }
    }
}

namespace {

use PHPUnit\Framework\TestCase;

if (!class_exists('BJLG\\BJLG_Debug') && !class_exists('BJLG_Debug')) {
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

            public function addFile($filepath, $entryname = "", $start = 0, $length = ZipArchive::LENGTH_TO_END, $flags = ZipArchive::FL_OVERWRITE): bool
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

    public function test_add_folder_to_zip_throws_exception_when_zip_addfile_fails(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $zip = new class extends ZipArchive {
            /** @var array<int, array{0: string, 1: string}> */
            public $addAttempts = [];

            public function addFile($filepath, $entryname = "", $start = 0, $length = ZipArchive::LENGTH_TO_END, $flags = ZipArchive::FL_OVERWRITE): bool
            {
                $this->addAttempts[] = [$filepath, $entryname];

                return false;
            }
        };

        $root = sys_get_temp_dir() . '/bjlg-addfile-fail-' . uniqid('', true);
        $directory = $root . '/data';

        try {
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                $this->fail('Unable to create the directory for the addFile failure test.');
            }

            $file = $directory . '/file.txt';
            file_put_contents($file, 'data');

            try {
                $backup->add_folder_to_zip($zip, $directory, 'data/');
                $this->fail('An exception should have been thrown when ZipArchive::addFile fails.');
            } catch (Exception $exception) {
                $this->assertStringContainsString($file, $exception->getMessage());
            }

            $this->assertNotEmpty($zip->addAttempts);
            $this->assertNotEmpty(BJLG_Debug::$logs);
            $this->assertStringContainsString($file, implode("\n", BJLG_Debug::$logs));
        } finally {
            if (isset($file) && file_exists($file)) {
                @unlink($file);
            }

            if (is_dir($directory)) {
                @rmdir($directory);
            }

            if (is_dir($root)) {
                @rmdir($root);
            }
        }
    }

    public function test_backup_migration_plugin_is_not_excluded_by_default(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $zip = new class extends ZipArchive {
            /** @var array<int, array{0: string, 1: string}> */
            public $addedFiles = [];

            public function addFile($filepath, $entryname = "", $start = 0, $length = ZipArchive::LENGTH_TO_END, $flags = ZipArchive::FL_OVERWRITE): bool
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
            $scan = $incremental->get_modified_files($windows_dir);
            $modified_files = is_array($scan['modified'] ?? null) ? $scan['modified'] : [];

            $this->assertCount(1, $modified_files);
            $expected_path = str_replace('\\', '/', (string) realpath($file_path));
            $this->assertSame($expected_path, $modified_files[0]);

            $zip = new class extends ZipArchive {
                /** @var array<int, array{0: string, 1: string}> */
                public $addedFiles = [];

                public function addFile($filepath, $entryname = "", $start = 0, $length = ZipArchive::LENGTH_TO_END, $flags = ZipArchive::FL_OVERWRITE): bool
                {
                    $this->addedFiles[] = [$filepath, $entryname];
                    return true;
                }

                public function setCompressionName($name, $method, $compflags = 0): bool
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

    public function test_add_folder_to_zip_throws_exception_when_setcompressionname_fails(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $zip = new class extends ZipArchive {
            /** @var array<int, array{0: string, 1: string}> */
            public $addedFiles = [];

            public function addFile($filepath, $entryname = "", $start = 0, $length = ZipArchive::LENGTH_TO_END, $flags = ZipArchive::FL_OVERWRITE): bool
            {
                $this->addedFiles[] = [$filepath, $entryname];

                return true;
            }

            public function setCompressionName($name, $method, $compflags = 0): bool
            {
                return false;
            }
        };

        $root = sys_get_temp_dir() . '/bjlg-compression-fail-' . uniqid('', true);
        $directory = $root . '/large';

        try {
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                $this->fail('Unable to create the directory for the compression failure test.');
            }

            $large_file = $directory . '/large.bin';

            $handle = fopen($large_file, 'w');
            if ($handle === false) {
                $this->fail('Unable to create the large file for the compression failure test.');
            }

            try {
                $seek_result = fseek($handle, 50 * 1024 * 1024);
                if ($seek_result !== 0) {
                    $this->fail('Unable to create the large file of the expected size for the compression failure test.');
                }

                $write_result = fwrite($handle, '0');
                if ($write_result === false) {
                    $this->fail('Unable to finalize the large file for the compression failure test.');
                }
            } finally {
                fclose($handle);
            }

            try {
                $backup->add_folder_to_zip($zip, $directory, 'large/');
                $this->fail('An exception should have been thrown when ZipArchive::setCompressionName fails.');
            } catch (Exception $exception) {
                $this->assertStringContainsString('large/large.bin', $exception->getMessage());
            }

            $this->assertNotEmpty($zip->addedFiles);
            $this->assertNotEmpty(BJLG_Debug::$logs);
            $this->assertStringContainsString('large/large.bin', implode("\n", BJLG_Debug::$logs));
        } finally {
            if (isset($large_file) && file_exists($large_file)) {
                @unlink($large_file);
            }

            if (is_dir($directory)) {
                @rmdir($directory);
            }

            if (is_dir($root)) {
                @rmdir($root);
            }
        }
    }

    public function test_add_folder_to_zip_uses_preg_match_fallback_when_fnmatch_missing(): void
    {
        $root = sys_get_temp_dir() . '/bjlg-fallback-' . uniqid('', true);
        $logs_dir = $root . '/logs';

        if (!is_dir($logs_dir) && !mkdir($logs_dir, 0777, true) && !is_dir($logs_dir)) {
            $this->fail('Unable to create the logs directory for the test.');
        }

        $log_file = $logs_dir . '/error.log';
        $text_file = $logs_dir . '/readme.txt';

        file_put_contents($log_file, 'log');
        file_put_contents($text_file, 'text');

        $zip = new class extends ZipArchive {
            /** @var array<int, array{0: string, 1: string}> */
            public $addedFiles = [];

            public function addFile($filepath, $entryname = "", $start = 0, $length = ZipArchive::LENGTH_TO_END, $flags = ZipArchive::FL_OVERWRITE): bool
            {
                $this->addedFiles[] = [$filepath, $entryname];
                return true;
            }

            public function setCompressionName($name, $method, $compflags = 0): bool
            {
                return true;
            }
        };

        try {
            $backup = new BJLG\BJLG_Backup();
            $backup->add_folder_to_zip($zip, $logs_dir, 'logs/', ['*.log']);

            $added_files = array_map(static fn($entry) => $entry[0], $zip->addedFiles);

            $this->assertContains($text_file, $added_files, 'The text file should be included when using the fallback.');
            $this->assertNotContains($log_file, $added_files, 'The log file should be excluded by the fallback matching logic.');
        } finally {
            @unlink($log_file);
            @unlink($text_file);
            @rmdir($logs_dir);
            @rmdir($root);
        }
    }

    public function test_add_folder_to_zip_streams_large_files_without_compression(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $zip = new class extends ZipArchive {
            /** @var array<int, array{0: string, 1: string}> */
            public $addedFiles = [];

            /** @var array<int, array{0: string, 1: int, 2: int}> */
            public $compressionCalls = [];

            public function addFile($filepath, $entryname = "", $start = 0, $length = ZipArchive::LENGTH_TO_END, $flags = ZipArchive::FL_OVERWRITE): bool
            {
                $this->addedFiles[] = [$filepath, $entryname];
                return true;
            }

            public function setCompressionName($name, $method, $compflags = 0): bool
            {
                $this->compressionCalls[] = [$name, $method, $compflags];
                return true;
            }
        };

        $root = sys_get_temp_dir() . '/bjlg-large-' . uniqid('', true);
        $large_dir = $root . '/large';

        try {
            if (!is_dir($large_dir) && !mkdir($large_dir, 0777, true) && !is_dir($large_dir)) {
                $this->fail('Unable to create the directory for the large file test.');
            }

            $large_file = $large_dir . '/file.bin';

            $handle = fopen($large_file, 'w');
            if ($handle === false) {
                $this->fail('Unable to create the large file for the test.');
            }

            try {
                $seek_result = fseek($handle, 50 * 1024 * 1024);
                if ($seek_result !== 0) {
                    $this->fail('Unable to create the large file of the expected size.');
                }

                $write_result = fwrite($handle, '0');
                if ($write_result === false) {
                    $this->fail('Unable to finalize the large file for the test.');
                }
            } finally {
                fclose($handle);
            }

            $backup->add_folder_to_zip($zip, $large_dir, 'large/');

            $this->assertCount(1, $zip->addedFiles, 'The large file should be added to the archive.');
            $this->assertSame($large_file, $zip->addedFiles[0][0]);
            $this->assertSame('large/file.bin', $zip->addedFiles[0][1]);

            $this->assertNotEmpty($zip->compressionCalls, 'Compression settings should be adjusted for large files.');
            $this->assertSame(['large/file.bin', ZipArchive::CM_STORE, 0], $zip->compressionCalls[0]);
        } finally {
            if (isset($large_file) && file_exists($large_file)) {
                @unlink($large_file);
            }

            if (is_dir($large_dir)) {
                @rmdir($large_dir);
            }

            if (is_dir($root)) {
                @rmdir($root);
            }
        }
    }

    public function test_run_backup_task_marks_task_as_error_when_zip_addfile_fails(): void
    {
        $task_id = 'bjlg_backup_' . uniqid('zipfail', true);

        $components = ['db'];

        $previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            /** @var string */
            public $prefix = 'wp_';

            public function get_results($query, $output = OBJECT)
            {
                if (stripos($query, 'SHOW TABLES') === 0) {
                    return [
                        ['wp_posts'],
                    ];
                }

                if (stripos($query, 'SELECT * FROM') === 0) {
                    return [];
                }

                return [];
            }

            public function get_row($query, $output = OBJECT)
            {
                if (stripos($query, 'SHOW CREATE TABLE') === 0) {
                    return ['wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint(20));'];
                }

                return null;
            }

            public function get_var($query)
            {
                if (stripos($query, 'SELECT COUNT(*)') === 0) {
                    return 0;
                }

                return 0;
            }
        };

        try {
            set_transient($task_id, [
                'progress' => 5,
                'status' => 'pending',
                'status_text' => 'Initialisation',
                'components' => $components,
                'encrypt' => false,
                'incremental' => false,
                'source' => 'tests',
                'start_time' => time(),
            ], HOUR_IN_SECONDS);

            $zip = new class extends ZipArchive {
                /** @var string|null */
                public $openedPath = null;

                #[\ReturnTypeWillChange]
                public function open(string $filename, int $flags = 0)
                {
                    $directory = dirname($filename);
                    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                        throw new \RuntimeException('Unable to create backup directory for the test.');
                    }

                    file_put_contents($filename, '');

                    $this->openedPath = $filename;

                    return true;
                }

                #[\ReturnTypeWillChange]
                public function addFromString(string $name, string $content, int $flags = ZipArchive::FL_OVERWRITE): bool
                {
                    return true;
                }

                public function addFile(string $filepath, string $entryname = "", int $start = 0, int $length = ZipArchive::LENGTH_TO_END, int $flags = ZipArchive::FL_OVERWRITE): bool
                {
                    return false;
                }

                #[\ReturnTypeWillChange]
                public function close(): bool
                {
                    return true;
                }
            };

            $backup = new class($zip) extends BJLG\BJLG_Backup {
                private $zipMock;

                public function __construct(ZipArchive $zipMock)
                {
                    $this->zipMock = $zipMock;
                }

                protected function create_zip_archive(): ZipArchive
                {
                    return $this->zipMock;
                }
            };

            try {
                $backup->run_backup_task($task_id);
            } catch (\Throwable $throwable) {
                $this->fail('run_backup_task should not bubble up exceptions: ' . $throwable->getMessage());
            }

            $task_state = get_transient($task_id);
            $this->assertIsArray($task_state);
            $this->assertSame('error', $task_state['status']);
            $this->assertSame(100, $task_state['progress']);
            $this->assertStringContainsString('Impossible d\'ajouter l\'export SQL temporaire', $task_state['status_text']);

            $this->assertNotEmpty(BJLG_Debug::$logs);
            $this->assertStringContainsString('Impossible d\'ajouter l\'export SQL temporaire', implode("\n", BJLG_Debug::$logs));

            if ($zip->openedPath !== null) {
                $this->assertFileDoesNotExist($zip->openedPath, 'The partial backup file should be cleaned up after a failure.');
            }
        } finally {
            if ($previous_wpdb === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $previous_wpdb;
            }
        }
    }

    public function test_generate_backup_filename_produces_unique_names_within_same_second(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $reflection = new ReflectionMethod(BJLG\BJLG_Backup::class, 'generate_backup_filename');
        $reflection->setAccessible(true);

        $attempts = 0;
        $first = '';
        $second = '';
        $start_second = 0;
        $end_second = 0;

        do {
            $attempts++;
            $start_microtime = microtime(true);
            $first = $reflection->invoke($backup, 'full', ['db', 'plugins', 'themes', 'uploads']);
            $second = $reflection->invoke($backup, 'full', ['db', 'plugins', 'themes', 'uploads']);
            $end_microtime = microtime(true);

            $start_second = (int) $start_microtime;
            $end_second = (int) $end_microtime;

            if ($start_second !== $end_second && $attempts < 5) {
                usleep(200000); // attendre un court instant avant une nouvelle tentative
            }
        } while ($start_second !== $end_second && $attempts < 5);

        $this->assertSame(
            $start_second,
            $end_second,
            'Unable to compare filenames generated within the same second.'
        );

        $this->assertNotSame(
            $first,
            $second,
            'Backup filenames generated within the same second should be unique.'
        );

        $this->assertStringEndsWith('.zip', $first);
        $this->assertStringEndsWith('.zip', $second);
    }
}

}
