<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BJLG_VERSION')) {
    define('BJLG_VERSION', 'test-version');
}

if (!function_exists('get_site_url')) {
    function get_site_url() {
        return 'https://example.com';
    }
}

if (!class_exists('BJLG\\BJLG_Debug')) {
    class BJLG_Debug
    {
        /** @var array<int, string> */
        public static $logs = [];

        public static function log($message) {
            self::$logs[] = (string) $message;
        }
    }

    class_alias('BJLG_Debug', 'BJLG\\BJLG_Debug');
}

require_once __DIR__ . '/../includes/class-bjlg-backup.php';

final class BJLG_BackupDatabaseTest extends TestCase
{
    public function test_create_insert_statement_escapes_special_and_binary_values(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'create_insert_statement');
        $method->setAccessible(true);

        $rows = [
            [
                'id' => 1,
                'name' => "O'Reilly",
                'payload' => "Binary\0Data",
                'comment' => "'; DROP TABLE users; --",
                'injection' => "' OR '1'='1",
                'emoji' => "emoji 😃",
                'nullable' => null,
            ],
        ];

        $sql = $method->invoke($backup, 'wp_test', $rows);

        $this->assertStringContainsString("INSERT INTO `wp_test` (`id`, `name`, `payload`, `comment`, `injection`, `emoji`, `nullable`) VALUES", $sql);
        $this->assertStringContainsString("'O\\'Reilly'", $sql);
        $this->assertStringContainsString('0x42696e6172790044617461', $sql);
        $this->assertStringContainsString("'\\'; DROP TABLE users; --'", $sql);
        $this->assertStringContainsString("'\\' OR \\'1\\'=\\'1'", $sql);
        $this->assertStringContainsString("'emoji 😃'", $sql);
        $this->assertStringContainsString(', NULL)', $sql);
        $this->assertStringNotContainsString("' OR '1'='1", $sql);
    }

    public function test_backup_database_streams_content_into_zip_via_temp_file(): void
    {
        $backup = new BJLG\BJLG_Backup();

        $zip = new class extends ZipArchive {
            /** @var array<int, array{0: string, 1: string}> */
            public $addedFiles = [];

            /** @var array<string, string|false> */
            public $fileContents = [];

            public function addFile($filepath, $entryname, $start = 0, $length = 0, $flags = 0): bool
            {
                $this->addedFiles[] = [$filepath, $entryname];
                $this->fileContents[$entryname] = @file_get_contents($filepath);
                return true;
            }
        };

        $previous_wpdb = $GLOBALS['wpdb'] ?? null;

        $GLOBALS['wpdb'] = new class {
            /** @var string */
            public $prefix = 'wp_';

            public function get_results($query, $output = 'OBJECT')
            {
                if (stripos($query, 'SHOW TABLES') === 0) {
                    return [['wp_test']];
                }

                if (stripos($query, 'SELECT * FROM `wp_test`') === 0) {
                    return [[
                        'id' => 1,
                        'name' => "Streamed",
                    ]];
                }

                return [];
            }

            public function get_row($query, $output = 'OBJECT', $y = 0)
            {
                if (stripos($query, 'SHOW CREATE TABLE') === 0) {
                    return ['wp_test', 'CREATE TABLE `wp_test` (`id` int(11))'];
                }

                return null;
            }

            public function get_var($query)
            {
                if (stripos($query, 'SELECT COUNT(*) FROM `wp_test`') === 0) {
                    return 1;
                }

                return 0;
            }
        };

        $method = new ReflectionMethod(BJLG\BJLG_Backup::class, 'backup_database');
        $method->setAccessible(true);
        $method->invoke($backup, $zip, false);

        $this->assertNotEmpty($zip->addedFiles);

        $addedFile = $zip->addedFiles[0];
        $this->assertSame('database.sql', $addedFile[1]);

        $this->assertArrayHasKey('database.sql', $zip->fileContents);
        $dumpContent = $zip->fileContents['database.sql'];
        $this->assertIsString($dumpContent);
        $this->assertStringContainsString('-- Backup JLG Database Export', $dumpContent);
        $this->assertStringContainsString('-- Table: wp_test', $dumpContent);
        $this->assertStringContainsString('INSERT INTO `wp_test` (`id`, `name`)', $dumpContent);

        $tempPath = $addedFile[0];
        $this->assertFileDoesNotExist($tempPath);

        if ($previous_wpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $previous_wpdb;
        }
    }
}
