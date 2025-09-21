<?php
declare(strict_types=1);

namespace BJLG {
    if (!function_exists(__NAMESPACE__ . '\\realpath')) {
        function realpath($path)
        {
            if (isset($GLOBALS['bjlg_test_realpath_mock']) && is_callable($GLOBALS['bjlg_test_realpath_mock'])) {
                return call_user_func($GLOBALS['bjlg_test_realpath_mock'], $path);
            }

            return \realpath($path);
        }
    }
}

namespace {

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-actions.php';

final class BJLG_ActionsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['bjlg_test_current_user_can'] = true;
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_last_status_header'] = null;
        $GLOBALS['bjlg_test_realpath_mock'] = null;
        $_POST = [];
        $_REQUEST = [];
        $_GET = [];
    }

    public function test_handle_delete_backup_denies_user_without_capability(): void
    {
        $GLOBALS['bjlg_test_current_user_can'] = false;

        $actions = new BJLG\BJLG_Actions();

        try {
            $actions->handle_delete_backup();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $exception) {
            $this->assertSame(['message' => 'Permission refusée.'], $exception->data);
            $this->assertSame(403, $exception->status_code);
        }
    }

    /**
     * @return array<string, array{0: ?string, 1: int}>
     */
    public function provide_invalid_tokens_for_download(): array
    {
        return [
            'missing token' => [null, 400],
            'empty token' => ['', 400],
            'expired token' => ['expired', 403],
        ];
    }

    /**
     * @dataProvider provide_invalid_tokens_for_download
     */
    public function test_handle_download_request_uses_status_from_validation(?string $token, int $expected_status): void
    {
        $actions = new BJLG\BJLG_Actions();

        if ($token !== null) {
            $_REQUEST['token'] = $token;
        }

        try {
            $actions->handle_download_request();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $exception) {
            $this->assertSame($expected_status, $exception->status_code);
        }
    }

    public function test_handle_download_request_requires_identifier_when_no_token(): void
    {
        $actions = new BJLG\BJLG_Actions();

        try {
            $actions->handle_download_request();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $exception) {
            $this->assertSame(['message' => 'Identifiant de sauvegarde manquant.'], $exception->data);
            $this->assertSame(400, $exception->status_code);
        }
    }

    public function test_handle_download_request_requires_capability_for_identifier_flow(): void
    {
        $GLOBALS['bjlg_test_current_user_can'] = false;

        $actions = new BJLG\BJLG_Actions();

        $_REQUEST['file'] = 'example.zip';

        try {
            $actions->handle_download_request();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $exception) {
            $this->assertSame(['message' => 'Permission refusée.'], $exception->data);
            $this->assertSame(403, $exception->status_code);
        }
    }

    public function test_handle_download_request_returns_not_found_when_identifier_unknown(): void
    {
        $actions = new BJLG\BJLG_Actions();

        $_REQUEST['file'] = 'missing-backup.zip';

        try {
            $actions->handle_download_request();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $exception) {
            $this->assertSame(404, $exception->status_code);
        }
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public function provide_public_download_tokens(): array
    {
        return [
            'missing token' => ['', 400],
            'expired token' => ['expired', 403],
        ];
    }

    /**
     * @dataProvider provide_public_download_tokens
     */
    public function test_maybe_handle_public_download_uses_status_from_validation(string $token, int $expected_status): void
    {
        $actions = new BJLG\BJLG_Actions();

        $_GET['bjlg_download'] = $token;

        try {
            $actions->maybe_handle_public_download();
            $this->fail('Expected BJLG_Test_WP_Die to be thrown.');
        } catch (BJLG_Test_WP_Die $exception) {
            $this->assertSame($expected_status, $exception->status_code);
            $this->assertSame($expected_status, $GLOBALS['bjlg_test_last_status_header']);
        }
    }

    public function test_handle_delete_backup_returns_error_when_backup_directory_is_missing(): void
    {
        $actions = new BJLG\BJLG_Actions();

        $_POST['filename'] = 'missing-backup.zip';

        $GLOBALS['bjlg_test_realpath_mock'] = static function (string $path) {
            if ($path === BJLG_BACKUP_DIR) {
                return false;
            }

            return \realpath($path);
        };

        try {
            $actions->handle_delete_backup();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $exception) {
            $this->assertSame(['message' => 'Répertoire de sauvegarde introuvable.'], $exception->data);
            $this->assertSame(500, $exception->status_code);
        }
    }
}

}
