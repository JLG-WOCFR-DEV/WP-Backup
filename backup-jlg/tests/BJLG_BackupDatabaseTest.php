<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-backup.php';

final class BJLG_BackupDatabaseTest extends TestCase
{
    public function test_create_insert_statement_escapes_special_and_binary_values(): void
    {
        $backup = new BJLG_Backup();

        $method = new ReflectionMethod(BJLG_Backup::class, 'create_insert_statement');
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
}
