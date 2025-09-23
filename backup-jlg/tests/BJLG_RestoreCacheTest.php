<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-restore.php';

final class BJLG_RestoreCacheTest extends TestCase
{
    /** @var mixed */
    private $previous_wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

        $GLOBALS['wpdb'] = new class {
            /** @var string */
            public $options = 'wp_options';

            /** @var array<int, string> */
            public $queries = [];

            /** @var array<int, array<int, string>> */
            public $get_col_results = [];

            /** @var string */
            public $last_error = '';

            public function query($query)
            {
                $this->queries[] = (string) $query;
                $this->last_error = '';

                return 1;
            }

            public function get_col($query)
            {
                $this->queries[] = (string) $query;

                if (empty($this->get_col_results)) {
                    return [];
                }

                return array_shift($this->get_col_results);
            }
        };

        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_site_transients'] = [];
    }

    protected function tearDown(): void
    {
        if ($this->previous_wpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
        }

        parent::tearDown();
    }

    public function test_clear_all_caches_preserves_third_party_transients(): void
    {
        $restore = new BJLG\BJLG_Restore();

        $GLOBALS['bjlg_test_transients'] = [
            'bjlg_rate_example' => 'foo',
            'woocommerce_session_123' => 'bar',
        ];

        $GLOBALS['bjlg_test_site_transients'] = [
            'bjlg_site_token' => 'baz',
            'woocommerce_session_global' => 'qux',
        ];

        $GLOBALS['wpdb']->get_col_results = [
            ['_transient_bjlg_rate_example'],
            ['_site_transient_bjlg_site_token'],
        ];

        $method = new ReflectionMethod(BJLG\BJLG_Restore::class, 'clear_all_caches');
        $method->setAccessible(true);
        $method->invoke($restore);

        $this->assertArrayNotHasKey('bjlg_rate_example', $GLOBALS['bjlg_test_transients']);
        $this->assertArrayHasKey('woocommerce_session_123', $GLOBALS['bjlg_test_transients']);

        $this->assertArrayNotHasKey('bjlg_site_token', $GLOBALS['bjlg_test_site_transients']);
        $this->assertArrayHasKey('woocommerce_session_global', $GLOBALS['bjlg_test_site_transients']);

        $this->assertContains("DELETE FROM wp_options WHERE option_name LIKE '_transient_bjlg_%'", $GLOBALS['wpdb']->queries);
        $this->assertContains("DELETE FROM wp_options WHERE option_name LIKE '_site_transient_bjlg_%'", $GLOBALS['wpdb']->queries);
        $this->assertNotContains("DELETE FROM wp_options WHERE option_name LIKE '_transient_%'", $GLOBALS['wpdb']->queries);
    }
}
