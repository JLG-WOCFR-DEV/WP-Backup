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
use BJLG\BJLG_Notification_Queue;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-notification-queue.php';
require_once __DIR__ . '/../includes/class-bjlg-incremental.php';
require_once __DIR__ . '/../includes/class-bjlg-actions.php';

final class BJLG_ActionsTest extends TestCase
{
    private string $manifestPath;
    protected function setUp(): void
    {
        $GLOBALS['bjlg_test_current_user_can'] = true;
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_last_status_header'] = null;
        $GLOBALS['bjlg_test_realpath_mock'] = null;
        $GLOBALS['current_user'] = null;
        $GLOBALS['current_user_id'] = 0;
        \BJLG\BJLG_Debug::$logs = [];
        $_POST = [];
        $_REQUEST = [];
        $_GET = [];
        BJLG_Notification_Queue::create_tables();
        BJLG_Notification_Queue::seed_queue([]);
        $this->manifestPath = bjlg_get_backup_directory() . '.incremental-manifest.json';
        if (file_exists($this->manifestPath)) {
            @unlink($this->manifestPath);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->manifestPath) && file_exists($this->manifestPath)) {
            @unlink($this->manifestPath);
        }
    }

    private function seedRemotePurgeManifest(string $file): void
    {
        $incremental = new BJLG\BJLG_Incremental();
        $reflection = new \ReflectionClass(BJLG\BJLG_Incremental::class);
        $property = $reflection->getProperty('last_backup_data');
        $property->setAccessible(true);
        $data = $property->getValue($incremental);
        $data['remote_purge_queue'] = [[
            'file' => $file,
            'destinations' => ['s3'],
            'status' => 'failed',
            'registered_at' => time() - 300,
            'attempts' => 2,
            'last_attempt_at' => time() - 60,
            'next_attempt_at' => time() + 600,
            'last_error' => 'error',
            'errors' => ['error'],
            'failed_at' => time() - 30,
        ]];
        $property->setValue($incremental, $data);

        $save = $reflection->getMethod('save_manifest');
        $save->setAccessible(true);
        $this->assertTrue($save->invoke($incremental));
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

    public function test_prepare_download_returns_error_when_transient_persistence_fails(): void
    {
        $actions = new BJLG\BJLG_Actions();

        $filename = 'bjlg-test-backup-' . uniqid('', true) . '.zip';
        $filepath = bjlg_get_backup_directory() . $filename;

        file_put_contents($filepath, 'backup-data');

        $_POST['filename'] = $filename;
        $_POST['nonce'] = 'test-nonce';

        $GLOBALS['bjlg_test_set_transient_mock'] = static function (string $transient, $value = null, $expiration = null) {
            if (strpos($transient, 'bjlg_download_') === 0) {
                return false;
            }

            return null;
        };

        try {
            $actions->prepare_download();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $exception) {
            $this->assertSame(['message' => 'Impossible de créer un token de téléchargement.'], $exception->data);
            $this->assertSame(500, $exception->status_code);
            $this->assertNotEmpty(BJLG\BJLG_Debug::$logs);
        } finally {
            $GLOBALS['bjlg_test_set_transient_mock'] = null;

            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }

    public function test_prepare_download_generates_token_payload(): void
    {
        $actions = new BJLG\BJLG_Actions();

        $filename = 'bjlg-test-backup-' . uniqid('', true) . '.zip';
        $filepath = bjlg_get_backup_directory() . $filename;

        file_put_contents($filepath, 'prepared-download');

        $real_filepath = realpath($filepath);

        if ($real_filepath === false) {
            $this->fail('Failed to resolve the real path for the prepared download file.');
        }

        $_POST['filename'] = $filename;
        $_POST['nonce'] = 'test-nonce';

        $expected_ttl = 123;
        $captured_filter_args = null;
        $previous_download_filters = $GLOBALS['bjlg_test_hooks']['filters']['bjlg_download_token_ttl'] ?? null;
        $transient_key = null;

        add_filter(
            'bjlg_download_token_ttl',
            function ($ttl, $path) use (&$captured_filter_args, $expected_ttl) {
                $captured_filter_args = [$ttl, $path];

                return $expected_ttl;
            },
            10,
            2
        );

        try {
            try {
                $actions->prepare_download();
                $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
            } catch (BJLG_Test_JSON_Response $exception) {
                $this->assertIsArray($exception->data);
                $this->assertArrayHasKey('download_url', $exception->data);
                $this->assertArrayHasKey('token', $exception->data);
                $this->assertArrayHasKey('expires_in', $exception->data);
                $this->assertSame($expected_ttl, $exception->data['expires_in']);

                $token = $exception->data['token'];
                $this->assertNotEmpty($token);
                $this->assertIsString($token);

                $download_url = $exception->data['download_url'];
                $this->assertIsString($download_url);
                $this->assertStringContainsString($token, $download_url);

                $transient_key = 'bjlg_download_' . $token;
                $this->assertArrayHasKey($transient_key, $GLOBALS['bjlg_test_transients']);

                $payload = $GLOBALS['bjlg_test_transients'][$transient_key];
                $this->assertIsArray($payload);
                $this->assertArrayHasKey('file', $payload);
                $this->assertArrayHasKey('requires_cap', $payload);
                $this->assertArrayHasKey('issued_at', $payload);
                $this->assertArrayHasKey('issued_by', $payload);

                $this->assertSame($real_filepath, $payload['file']);
                $this->assertSame(bjlg_get_required_capability(), $payload['requires_cap']);
                $this->assertIsInt($payload['issued_at']);
                $this->assertGreaterThan(0, $payload['issued_at']);
                $this->assertSame(0, $payload['issued_by']);

                $this->assertIsArray($captured_filter_args);
                $this->assertSame(15 * (defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60), $captured_filter_args[0]);
                $this->assertSame($real_filepath, $captured_filter_args[1]);
            }
        } finally {
            if ($previous_download_filters === null) {
                unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_download_token_ttl']);
            } else {
                $GLOBALS['bjlg_test_hooks']['filters']['bjlg_download_token_ttl'] = $previous_download_filters;
            }

            if ($transient_key !== null) {
                unset($GLOBALS['bjlg_test_transients'][$transient_key]);
            }

            if (file_exists($filepath)) {
                unlink($filepath);
            }
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
            if ($path === bjlg_get_backup_directory()) {
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

    public function test_handle_notification_queue_retry_succeeds(): void
    {
        $actions = new BJLG\BJLG_Actions();

        BJLG_Notification_Queue::seed_queue([
            [
                'id' => 'ajax-entry',
                'event' => 'backup_failed',
                'title' => 'Failure',
                'subject' => 'Subject',
                'lines' => ['Failure'],
                'body' => 'Body',
                'context' => [],
                'next_attempt_at' => time() + 600,
                'channels' => [
                    'email' => [
                        'enabled' => true,
                        'status' => 'failed',
                        'attempts' => 2,
                        'recipients' => ['admin@example.com'],
                        'last_error' => 'error',
                        'next_attempt_at' => time() + 600,
                    ],
                ],
            ],
        ]);

        $_POST = [
            'nonce' => 'test-nonce',
            'entry_id' => 'ajax-entry',
        ];

        try {
            $actions->handle_notification_queue_retry();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
        } finally {
            $_POST = [];
        }

        $queue = BJLG_Notification_Queue::export_queue();
        $this->assertSame('pending', $queue[0]['channels']['email']['status']);
    }

    public function test_handle_notification_queue_delete_succeeds(): void
    {
        $actions = new BJLG\BJLG_Actions();
        BJLG_Notification_Queue::seed_queue([
            [
                'id' => 'ajax-delete',
                'event' => 'backup_complete',
                'title' => 'Complete',
                'subject' => 'Subject',
                'lines' => ['Complete'],
                'body' => 'Body',
                'context' => [],
                'channels' => [
                    'email' => [
                        'enabled' => true,
                        'status' => 'completed',
                        'attempts' => 1,
                        'recipients' => ['user@example.com'],
                    ],
                ],
            ],
        ]);

        $_POST = [
            'nonce' => 'test-nonce',
            'entry_id' => 'ajax-delete',
        ];

        try {
            $actions->handle_notification_queue_delete();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
        } finally {
            $_POST = [];
        }

        $queue = BJLG_Notification_Queue::export_queue();
        $this->assertSame([], $queue);
    }

    public function test_handle_remote_purge_retry_succeeds(): void
    {
        $this->seedRemotePurgeManifest('ajax-remote.zip');
        $actions = new BJLG\BJLG_Actions();

        $_POST = [
            'nonce' => 'test-nonce',
            'file' => 'ajax-remote.zip',
        ];

        try {
            $actions->handle_remote_purge_retry();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
        } finally {
            $_POST = [];
        }

        $queue = (new BJLG\BJLG_Incremental())->get_remote_purge_queue();
        $this->assertSame('pending', $queue[0]['status']);
    }

    public function test_handle_remote_purge_delete_succeeds(): void
    {
        $this->seedRemotePurgeManifest('ajax-delete.zip');
        $actions = new BJLG\BJLG_Actions();

        $_POST = [
            'nonce' => 'test-nonce',
            'file' => 'ajax-delete.zip',
        ];

        try {
            $actions->handle_remote_purge_delete();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
        } finally {
            $_POST = [];
        }

        $queue = (new BJLG\BJLG_Incremental())->get_remote_purge_queue();
        $this->assertSame([], $queue);
    }

    public function test_validate_download_token_requires_authenticated_user(): void
    {
        $actions = new BJLG\BJLG_Actions();

        $token = 'bjlg-test-token';
        $filepath = bjlg_get_backup_directory() . 'token-download-' . uniqid('', true) . '.zip';

        file_put_contents($filepath, 'data');

        $user_id = 123;
        $user = (object) [
            'ID' => $user_id,
            'caps' => [bjlg_get_required_capability() => true],
            'allcaps' => [bjlg_get_required_capability() => true],
        ];

        $GLOBALS['bjlg_test_users'] = [$user_id => $user];
        $previous_can = $GLOBALS['bjlg_test_current_user_can'] ?? null;
        $GLOBALS['bjlg_test_current_user_can'] = false;
        wp_set_current_user(0);

        set_transient('bjlg_download_' . $token, [
            'file' => $filepath,
            'requires_cap' => bjlg_get_required_capability(),
            'issued_at' => time(),
            'issued_by' => $user_id,
        ], defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);

        $method = new \ReflectionMethod(BJLG\BJLG_Actions::class, 'validate_download_token');
        $method->setAccessible(true);

        try {
            $result = $method->invoke($actions, $token);

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('bjlg_forbidden', $result->get_error_code());
            $this->assertSame(0, get_current_user_id());
            $this->assertArrayHasKey('bjlg_download_' . $token, $GLOBALS['bjlg_test_transients']);

            wp_set_current_user($user_id);
            $GLOBALS['bjlg_test_current_user_can'] = null;

            $result = $method->invoke($actions, $token);

            $this->assertIsArray($result);
            $this->assertSame($filepath, $result[0]);
            $this->assertSame('bjlg_download_' . $token, $result[1]);
        } finally {
            if ($previous_can !== null) {
                $GLOBALS['bjlg_test_current_user_can'] = $previous_can;
            } else {
                unset($GLOBALS['bjlg_test_current_user_can']);
            }

            wp_set_current_user(0);

            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }
}

}
