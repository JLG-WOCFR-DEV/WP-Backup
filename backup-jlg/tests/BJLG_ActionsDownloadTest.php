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

    if (!class_exists(__NAMESPACE__ . '\\BJLG_Debug')) {
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

            /**
             * @param mixed $message
             */
            public static function error($message): void
            {
                self::log($message);
            }
        }
    }
}

namespace {

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-actions.php';

final class BJLG_ActionsDownloadTest extends TestCase
{
    /** @var mixed */
    private $previousHistoryHooks;

    /** @var array<string, mixed> */
    private $previousServer;

    /** @var mixed */
    private $previousCurrentUser;

    /** @var mixed */
    private $previousCurrentUserId;

    /** @var array<int, object> */
    private $previousTestUsers;

    /** @var array<string, mixed> */
    private $previousRequest;

    /** @var array<string, mixed> */
    private $previousGet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousHistoryHooks = $GLOBALS['bjlg_test_hooks']['actions']['bjlg_history_logged'] ?? null;
        $GLOBALS['bjlg_test_hooks']['actions']['bjlg_history_logged'] = [];

        $GLOBALS['bjlg_history_entries'] = [];

        add_action('bjlg_history_logged', static function ($action, $status, $message, $user_id) {
            $GLOBALS['bjlg_history_entries'][] = [
                'action' => (string) $action,
                'status' => (string) $status,
                'details' => (string) $message,
                'user_id' => $user_id,
            ];
        }, 10, 4);

        $this->previousServer = $_SERVER ?? [];
        $_SERVER = [];

        $this->previousCurrentUser = $GLOBALS['current_user'] ?? null;
        $this->previousCurrentUserId = $GLOBALS['current_user_id'] ?? 0;
        $this->previousTestUsers = $GLOBALS['bjlg_test_users'] ?? [];

        $this->previousRequest = $_REQUEST ?? [];
        $this->previousGet = $_GET ?? [];
        $_REQUEST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        if ($this->previousHistoryHooks === null) {
            unset($GLOBALS['bjlg_test_hooks']['actions']['bjlg_history_logged']);
        } else {
            $GLOBALS['bjlg_test_hooks']['actions']['bjlg_history_logged'] = $this->previousHistoryHooks;
        }

        $_SERVER = $this->previousServer;
        $GLOBALS['current_user'] = $this->previousCurrentUser;
        $GLOBALS['current_user_id'] = $this->previousCurrentUserId;
        $GLOBALS['bjlg_test_users'] = $this->previousTestUsers;
        $_REQUEST = $this->previousRequest;
        $_GET = $this->previousGet;

        parent::tearDown();
    }

    public function test_handle_download_request_logs_failure_with_context(): void
    {
        $actions = new \BJLG\BJLG_Actions();

        $_REQUEST['token'] = 'invalid-token';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.5';

        try {
            $actions->handle_download_request();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (\BJLG_Test_JSON_Response $exception) {
            $this->assertSame(403, $exception->status_code);
        }

        $this->assertNotEmpty($GLOBALS['bjlg_history_entries']);
        $last_entry = $GLOBALS['bjlg_history_entries'][count($GLOBALS['bjlg_history_entries']) - 1];

        $this->assertSame('backup_download_failure', $last_entry['action']);
        $this->assertSame('failure', $last_entry['status']);
        $this->assertStringContainsString('IP: 198.51.100.5', $last_entry['details']);
        $this->assertStringContainsString('Token: invalid-token', $last_entry['details']);
        $this->assertStringContainsString('Fichier: inconnu', $last_entry['details']);
        $this->assertStringContainsString('Erreur: Lien de téléchargement invalide ou expiré.', $last_entry['details']);
    }

    public function test_handle_download_request_logs_success_with_context(): void
    {
        $actions = new \BJLG\BJLG_Actions();

        $filename = 'bjlg-test-download-' . uniqid('', true) . '.zip';
        $filepath = BJLG_BACKUP_DIR . $filename;
        file_put_contents($filepath, 'backup');

        $realpath = realpath($filepath);
        $this->assertIsString($realpath);

        $token = 'valid-token';
        $user_id = 321;
        $GLOBALS['bjlg_test_users'] = [
            $user_id => (object) [
                'ID' => $user_id,
                'caps' => [bjlg_get_required_capability() => true],
                'allcaps' => [bjlg_get_required_capability() => true],
            ],
        ];
        $GLOBALS['current_user'] = null;
        $GLOBALS['current_user_id'] = 0;

        set_transient('bjlg_download_' . $token, [
            'file' => $realpath,
            'requires_cap' => bjlg_get_required_capability(),
            'issued_at' => time(),
            'issued_by' => $user_id,
        ], 3600);

        $_REQUEST['token'] = $token;
        $_SERVER['REMOTE_ADDR'] = '192.0.2.55';

        $previous_filter = $GLOBALS['bjlg_test_hooks']['filters']['bjlg_pre_stream_backup'] ?? null;
        $GLOBALS['bjlg_test_hooks']['filters']['bjlg_pre_stream_backup'] = [];

        add_filter('bjlg_pre_stream_backup', static function () {
            return '';
        }, 10, 2);

        try {
            $actions->handle_download_request();
        } finally {
            if ($previous_filter === null) {
                unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_pre_stream_backup']);
            } else {
                $GLOBALS['bjlg_test_hooks']['filters']['bjlg_pre_stream_backup'] = $previous_filter;
            }

            unset($GLOBALS['bjlg_test_transients']['bjlg_download_' . $token]);

            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }

        $this->assertNotEmpty($GLOBALS['bjlg_history_entries']);
        $last_entry = $GLOBALS['bjlg_history_entries'][count($GLOBALS['bjlg_history_entries']) - 1];

        $this->assertSame('backup_download_success', $last_entry['action']);
        $this->assertSame('success', $last_entry['status']);
        $this->assertStringContainsString('IP: 192.0.2.55', $last_entry['details']);
        $this->assertStringContainsString('Token: ' . $token, $last_entry['details']);
        $this->assertStringContainsString('Fichier: ' . $filename, $last_entry['details']);
        $this->assertSame($user_id, $last_entry['user_id']);
    }
}

}
