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

    public function test_validate_download_token_restores_user_context(): void
    {
        $actions = new BJLG\BJLG_Actions();

        $token = 'bjlg-test-token';
        $filepath = BJLG_BACKUP_DIR . 'token-download-' . uniqid('', true) . '.zip';

        file_put_contents($filepath, 'data');

        $user_id = 123;
        $user = (object) [
            'ID' => $user_id,
            'caps' => [BJLG_CAPABILITY => true],
            'allcaps' => [BJLG_CAPABILITY => true],
        ];

        $GLOBALS['bjlg_test_users'] = [$user_id => $user];
        $GLOBALS['bjlg_test_current_user_can'] = false;
        $GLOBALS['current_user'] = null;
        $GLOBALS['current_user_id'] = 0;

        set_transient('bjlg_download_' . $token, [
            'file' => $filepath,
            'requires_cap' => BJLG_CAPABILITY,
            'issued_at' => time(),
            'issued_by' => $user_id,
        ], defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);

        $method = new \ReflectionMethod(BJLG\BJLG_Actions::class, 'validate_download_token');
        $method->setAccessible(true);

        try {
            $result = $method->invoke($actions, $token);

            $this->assertIsArray($result);
            $this->assertSame($filepath, $result[0]);
            $this->assertSame('bjlg_download_' . $token, $result[1]);
            $this->assertSame($user_id, get_current_user_id());
        } finally {
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }
}

}
