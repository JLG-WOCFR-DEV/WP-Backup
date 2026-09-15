<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BJLG_AdminBackupListActionsTest extends TestCase
{
    public function test_admin_backup_js_binds_list_download_delete_and_restore_handlers(): void
    {
        $path = dirname(__DIR__) . '/assets/js/admin-backup.js';
        $this->assertFileExists($path);

        $js = (string) file_get_contents($path);

        $this->assertStringContainsString("class: 'button bjlg-download-button'", $js);
        $this->assertMatchesRegularExpression(
            '/\$section\.on\(\s*[\'"]click[\'"]\s*,\s*[\'"]\.bjlg-download-button[\'"]/s',
            $js,
            'The download button must have a delegated click handler.'
        );
        $this->assertStringContainsString("action: 'bjlg_prepare_download'", $js);

        $this->assertStringContainsString("class: 'button button-link-delete bjlg-delete-button'", $js);
        $this->assertMatchesRegularExpression(
            '/\$section\.on\(\s*[\'"]click[\'"]\s*,\s*[\'"]\.bjlg-delete-button[\'"]/s',
            $js,
            'The delete button must have a delegated click handler.'
        );
        $this->assertStringContainsString("action: 'bjlg_delete_backup'", $js);

        $this->assertStringContainsString("class: 'button button-primary bjlg-restore-button'", $js);
        $this->assertMatchesRegularExpression(
            '/\$section\.on\(\s*[\'"]click[\'"]\s*,\s*[\'"]\.bjlg-restore-button[\'"]/s',
            $js,
            'The restore-from-list button must have a delegated click handler.'
        );
        $this->assertStringContainsString("action: 'bjlg_run_restore'", $js);
    }
}
