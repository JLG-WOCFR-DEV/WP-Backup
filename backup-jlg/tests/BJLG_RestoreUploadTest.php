<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BJLG_VERSION')) {
    define('BJLG_VERSION', 'test-version');
}

require_once __DIR__ . '/../includes/class-bjlg-restore.php';

final class BJLG_RestoreUploadTest extends TestCase
{
    /** @var array<int, string> */
    private $temporaryPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['bjlg_test_current_user_can'] = true;
        $GLOBALS['bjlg_test_last_json_error'] = null;
        $GLOBALS['bjlg_test_last_json_success'] = null;
        $GLOBALS['bjlg_test_wp_handle_upload_mock'] = null;
        $GLOBALS['bjlg_test_wp_check_filetype_and_ext_mock'] = null;

        if (!isset($GLOBALS['bjlg_test_hooks'])) {
            $GLOBALS['bjlg_test_hooks'] = [
                'actions' => [],
                'filters' => [],
            ];
        }

        $GLOBALS['bjlg_test_hooks']['filters'] = [];
        $GLOBALS['bjlg_test_hooks']['actions'] = [];

        $_POST = [];
        $_FILES = [];

        if (!is_dir(bjlg_get_backup_directory())) {
            mkdir(bjlg_get_backup_directory(), 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }

        $this->temporaryPaths = [];
        $_FILES = [];
        $GLOBALS['bjlg_test_wp_handle_upload_mock'] = null;
        $GLOBALS['bjlg_test_wp_check_filetype_and_ext_mock'] = null;

        parent::tearDown();
    }

    public function test_handle_upload_restore_file_returns_ini_size_error_message(): void
    {
        $_POST['nonce'] = 'nonce';

        $tempFile = $this->createTemporaryFile('ini-size-error');

        $_FILES['restore_file'] = [
            'name' => 'oversized.zip',
            'type' => 'application/zip',
            'tmp_name' => $tempFile,
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => filesize($tempFile),
        ];

        $restore = new BJLG\BJLG_Restore();

        try {
            $restore->handle_upload_restore_file();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('message', $response->data);
            $this->assertSame(
                'Le fichier dépasse la taille maximale autorisée par la configuration PHP.',
                $response->data['message']
            );
            $this->assertArrayHasKey('details', $response->data);
            $this->assertIsArray($response->data['details']);
            $this->assertArrayHasKey('upload_error_code', $response->data['details']);
            $this->assertSame(UPLOAD_ERR_INI_SIZE, $response->data['details']['upload_error_code']);
            $this->assertArrayHasKey('upload_error_key', $response->data['details']);
            $this->assertSame('ini_size_limit_exceeded', $response->data['details']['upload_error_key']);
        }
    }

    public function test_handle_upload_restore_file_rejects_disallowed_extension(): void
    {
        $_POST['nonce'] = 'nonce';

        $tempFile = $this->createTemporaryFile('not-zip');

        $_FILES['restore_file'] = [
            'name' => 'payload.txt',
            'type' => 'text/plain',
            'tmp_name' => $tempFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempFile),
        ];

        add_filter('bjlg_restore_validate_is_uploaded_file', static function () {
            return true;
        }, 10, 2);

        $restore = new BJLG\BJLG_Restore();

        try {
            $restore->handle_upload_restore_file();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('message', $response->data);
            $this->assertSame('Type ou extension de fichier non autorisé.', $response->data['message']);
            $this->assertArrayHasKey('details', $response->data);
            $this->assertIsArray($response->data['details']);
            $this->assertSame(['zip', 'enc'], $response->data['details']['allowed_extensions']);
        }
    }

    public function test_handle_upload_restore_file_moves_file_to_backup_directory(): void
    {
        $_POST['nonce'] = 'nonce';

        $tempFile = $this->createTemporaryFile('valid-zip');

        $_FILES['restore_file'] = [
            'name' => 'archive.zip',
            'type' => 'application/zip',
            'tmp_name' => $tempFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tempFile),
        ];

        add_filter('bjlg_restore_validate_is_uploaded_file', static function () {
            return true;
        }, 10, 2);

        $handledPath = sys_get_temp_dir() . '/bjlg-handled-' . uniqid('', true) . '.zip';
        $this->temporaryPaths[] = $handledPath;

        $GLOBALS['bjlg_test_wp_handle_upload_mock'] = static function ($file) use ($handledPath) {
            copy($file['tmp_name'], $handledPath);

            return [
                'file' => $handledPath,
                'url' => 'http://example.com/' . basename($handledPath),
                'type' => $file['type'] ?? '',
            ];
        };

        $restore = new BJLG\BJLG_Restore();

        try {
            $restore->handle_upload_restore_file();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('filepath', $response->data);
            $this->assertArrayHasKey('filename', $response->data);
            $this->assertArrayHasKey('message', $response->data);
            $this->assertSame('Fichier téléversé avec succès.', $response->data['message']);

            $destination = $response->data['filepath'];
            $this->assertStringContainsString('restore_', basename($destination));
            $this->assertFileExists($destination);
            $this->assertStringEndsWith('.zip', $response->data['filename']);
            $this->assertArrayHasKey('details', $response->data);
            $this->assertSame('archive.zip', $response->data['details']['original_filename']);
            $this->assertSame('archive.zip', $response->data['details']['sanitized_filename']);

            $this->temporaryPaths[] = $destination;
            $this->assertFileDoesNotExist($handledPath);
        }
    }

    private function createTemporaryFile(string $contents): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'bjlg');
        if ($tempFile === false) {
            $this->fail('Unable to create temporary file for test.');
        }

        file_put_contents($tempFile, $contents);
        $this->temporaryPaths[] = $tempFile;

        return $tempFile;
    }
}
