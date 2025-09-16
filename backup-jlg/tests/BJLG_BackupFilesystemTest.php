<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!class_exists('BJLG_Debug')) {
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
}

require_once __DIR__ . '/../includes/class-bjlg-backup.php';

final class BJLG_BackupFilesystemTest extends TestCase
{
    protected function setUp(): void
    {
        if (class_exists('BJLG_Debug')) {
            BJLG_Debug::$logs = [];
        }
    }

    public function test_add_folder_to_zip_logs_and_returns_when_directory_cannot_be_opened(): void
    {
        $backup = new BJLG_Backup();

        $zip = new class extends ZipArchive {
            /** @var array<int, array{0: string, 1: string}> */
            public $addedFiles = [];

            public function addFile($filepath, $entryname, $start = 0, $length = 0, $flags = 0): bool
            {
                $this->addedFiles[] = [$filepath, $entryname];
                return true;
            }
        };

        $nonexistentFolder = sys_get_temp_dir() . '/bjlg-missing-' . uniqid('', true);

        $backup->add_folder_to_zip($zip, $nonexistentFolder, 'wp-content/plugins/');

        $this->assertSame([], $zip->addedFiles);
        $this->assertNotEmpty(BJLG_Debug::$logs);
        $this->assertStringContainsString($nonexistentFolder, BJLG_Debug::$logs[0]);
    }
}
