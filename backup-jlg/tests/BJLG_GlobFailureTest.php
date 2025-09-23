<?php
declare(strict_types=1);

namespace BJLG {
    if (!function_exists(__NAMESPACE__ . '\\glob')) {
        function glob($pattern, $flags = 0)
        {
            if (isset($GLOBALS['bjlg_test_glob_override']) && is_callable($GLOBALS['bjlg_test_glob_override'])) {
                return $GLOBALS['bjlg_test_glob_override']($pattern, $flags);
            }

            return \glob($pattern, $flags);
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\BJLG_Debug')) {
        class BJLG_Debug
        {
            public static function log($message, $level = 'info') {}
            public static function error($message) {}
            public static function warning($message) {}
            public static function info($message) {}
            public static function debug($message) {}
        }
    }
}

namespace {

use BJLG\BJLG_Cleanup;
use BJLG\BJLG_Encryption;
use BJLG\BJLG_REST_API;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-rest-api.php';
require_once __DIR__ . '/../includes/class-bjlg-encryption.php';
require_once __DIR__ . '/../includes/class-bjlg-cleanup.php';

if (!defined('BJLG_VERSION')) {
    define('BJLG_VERSION', 'test-version');
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '')
    {
        if ($show === 'version') {
            return '6.3.1';
        }

        return '6.3.1';
    }
}

final class BJLG_GlobFailureTest extends TestCase
{
    /** @var mixed */
    private $previousHooks;

    /** @var mixed */
    private $previousOptions;

    /** @var mixed */
    private $previousWpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousHooks = $GLOBALS['bjlg_test_hooks'] ?? null;
        $this->previousOptions = $GLOBALS['bjlg_test_options'] ?? null;
        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;

        $GLOBALS['bjlg_test_hooks'] = [
            'actions' => [],
            'filters' => [],
        ];

        $GLOBALS['bjlg_test_options'] = [];

        $GLOBALS['wpdb'] = new class {
            /** @var string */
            public $options = 'wp_options';

            public function get_var($query)
            {
                return 0;
            }

            public function get_row($query)
            {
                return (object) [
                    'size' => 0,
                    'tables' => 0,
                ];
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['bjlg_test_glob_override']);

        if (class_exists(BJLG_Cleanup::class)) {
            $cleanupReflection = new \ReflectionClass(BJLG_Cleanup::class);

            if ($cleanupReflection->hasProperty('instance')) {
                $property = $cleanupReflection->getProperty('instance');
                $property->setAccessible(true);
                $property->setValue(null, null);
            }
        }

        if ($this->previousHooks !== null) {
            $GLOBALS['bjlg_test_hooks'] = $this->previousHooks;
        } else {
            unset($GLOBALS['bjlg_test_hooks']);
        }

        if ($this->previousOptions !== null) {
            $GLOBALS['bjlg_test_options'] = $this->previousOptions;
        } else {
            unset($GLOBALS['bjlg_test_options']);
        }

        if ($this->previousWpdb !== null) {
            $GLOBALS['wpdb'] = $this->previousWpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }

        parent::tearDown();
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function runWithGlobFailure(callable $callback)
    {
        $GLOBALS['bjlg_test_glob_override'] = static function ($pattern, $flags) {
            return false;
        };

        $errors = [];
        set_error_handler(static function ($errno, $errstr) use (&$errors) {
            $errors[] = $errstr;
            return true;
        });

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
            unset($GLOBALS['bjlg_test_glob_override']);
        }

        \PHPUnit\Framework\Assert::assertSame([], $errors, 'No warnings should be triggered when glob() returns false.');

        return $result;
    }

    public function test_rest_api_handles_glob_failure(): void
    {
        $api = new BJLG_REST_API();

        $response = $this->runWithGlobFailure(static function () use ($api) {
            return $api->get_status(new class {
            });
        });

        $this->assertIsArray($response);
        $this->assertArrayHasKey('total_backups', $response);
        $this->assertSame(0, $response['total_backups']);
        $this->assertArrayHasKey('total_size', $response);
        $this->assertSame(0, $response['total_size']);
    }

    public function test_encryption_stats_handle_glob_failure(): void
    {
        $encryption = new BJLG_Encryption();

        $stats = $this->runWithGlobFailure(static function () use ($encryption) {
            return $encryption->get_encryption_stats();
        });

        $this->assertIsArray($stats);
        $this->assertSame(0, $stats['encrypted_count']);
        $this->assertSame(0, $stats['unencrypted_count']);
        $this->assertSame('0 B', $stats['total_encrypted_size']);
    }

    public function test_cleanup_temp_files_handles_glob_failure(): void
    {
        $cleanup = new BJLG_Cleanup();

        $deleted = $this->runWithGlobFailure(static function () use ($cleanup) {
            $reflection = new \ReflectionClass($cleanup);
            $method = $reflection->getMethod('cleanup_temp_files');
            $method->setAccessible(true);

            return $method->invoke($cleanup);
        });

        $this->assertSame(0, $deleted);
    }
}

}
