<?php
declare(strict_types=1);

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
require_once __DIR__ . '/../includes/class-bjlg-webhooks.php';

final class BJLG_WebhooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_scheduled_events'] = [
            'recurring' => [],
            'single' => [],
        ];
        $GLOBALS['bjlg_test_options'] = [];
        $GLOBALS['bjlg_test_set_transient_mock'] = null;
        $GLOBALS['bjlg_test_schedule_single_event_mock'] = null;
        BJLG_Debug::$logs = [];

        $_GET = [];
        $_POST = [];
        $_SERVER = [];

        $lock_property = new ReflectionProperty(BJLG\BJLG_Backup::class, 'in_memory_lock');
        $lock_property->setAccessible(true);
        $lock_property->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $lock_property = new ReflectionProperty(BJLG\BJLG_Backup::class, 'in_memory_lock');
        $lock_property->setAccessible(true);
        $lock_property->setValue(null, null);

        $_GET = [];
        $_POST = [];
        $_SERVER = [];

        parent::tearDown();
    }

    public function test_webhook_rejects_invalid_components_without_reserving_slot(): void
    {
        bjlg_update_option('bjlg_webhook_key', 'webhook-test-key');

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_BJLG_WEBHOOK_KEY'] = 'webhook-test-key';

        $_GET[BJLG\BJLG_Webhooks::WEBHOOK_QUERY_VAR] = BJLG\BJLG_Webhooks::WEBHOOK_SECURE_MARKER;
        $_GET['components'] = 'foo';

        $webhooks = new BJLG\BJLG_Webhooks();

        try {
            $webhooks->listen_for_webhook();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertSame(400, $response->status_code);
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('message', $response->data);
            $this->assertSame(
                'No valid components were requested. Allowed components are: db, plugins, themes, uploads.',
                $response->data['message']
            );
        }

        $this->assertArrayNotHasKey('bjlg_backup_task_lock', $GLOBALS['bjlg_test_transients']);
        $this->assertEmpty($GLOBALS['bjlg_test_scheduled_events']['single']);
    }
}
