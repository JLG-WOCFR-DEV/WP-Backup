<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!defined('BJLG_CAPABILITY')) {
    define('BJLG_CAPABILITY', 'manage_options');
}

if (!defined('BJLG_BACKUP_DIR')) {
    define('BJLG_BACKUP_DIR', sys_get_temp_dir() . '/');
}

$GLOBALS['bjlg_test_current_user_can'] = true;
$GLOBALS['bjlg_test_transients'] = [];
$GLOBALS['bjlg_test_scheduled_events'] = [];
$GLOBALS['bjlg_test_options'] = [];

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string, array<int, string>> */
        protected $errors = [];

        /** @var array<string, mixed> */
        protected $error_data = [];

        /**
         * @param string $code
         * @param string $message
         * @param mixed  $data
         */
        public function __construct($code = '', $message = '', $data = null)
        {
            if ($code !== '') {
                $this->add($code, $message, $data);
            }
        }

        /**
         * @param string $code
         * @param string $message
         * @param mixed  $data
         */
        public function add($code, $message, $data = null): void
        {
            if (!isset($this->errors[$code])) {
                $this->errors[$code] = [];
            }

            $this->errors[$code][] = (string) $message;

            if ($data !== null) {
                $this->error_data[$code] = $data;
            }
        }

        public function get_error_code()
        {
            $codes = array_keys($this->errors);

            return $codes[0] ?? '';
        }

        public function get_error_message($code = '')
        {
            if ($code === '') {
                $code = $this->get_error_code();
            }

            if ($code === '') {
                return '';
            }

            return $this->errors[$code][0] ?? '';
        }

        public function get_error_data($code = '')
        {
            if ($code === '') {
                $code = $this->get_error_code();
            }

            if ($code === '') {
                return null;
            }

            return $this->error_data[$code] ?? null;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('BJLG_Test_JSON_Response')) {
    class BJLG_Test_JSON_Response extends RuntimeException {
        /** @var mixed */
        public $data;

        /** @var int|null */
        public $status_code;

        /**
         * @param mixed     $data
         * @param int|null  $status_code
         */
        public function __construct($data = null, $status_code = null) {
            $this->data = $data;
            $this->status_code = $status_code;
            parent::__construct('JSON response');
        }
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback) {
        // No-op for tests.
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        // No-op for tests.
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        return $value;
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $die = true) {
        return true;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return $GLOBALS['bjlg_test_current_user_can'] ?? false;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $GLOBALS['bjlg_test_options'][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        $GLOBALS['bjlg_test_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('wp_hash_password')) {
    function wp_hash_password($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

if (!function_exists('wp_check_password')) {
    function wp_check_password($password, $hash) {
        return password_verify($password, $hash);
    }
}

if (!function_exists('wp_password_needs_rehash')) {
    function wp_password_needs_rehash($hash) {
        $info = password_get_info($hash);

        if (!empty($info['algo'])) {
            return password_needs_rehash($hash, PASSWORD_DEFAULT);
        }

        return false;
    }
}

if (!function_exists('esc_sql')) {
    /**
     * Simplified esc_sql implementation for tests.
     *
     * @param mixed $value
     * @return mixed
     */
    function esc_sql($value) {
        if (is_array($value)) {
            return array_map('esc_sql', $value);
        }

        return addslashes((string) $value);
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename) {
        return preg_replace('/[^A-Za-z0-9\\-_.]/', '', (string) $filename);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        $str = (string) $str;
        $str = strip_tags($str);
        $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
        return trim($str);
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') {
        return 'bjlg-test-salt-' . $scheme;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return $value;
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null) {
        throw new BJLG_Test_JSON_Response($data, $status_code);
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null) {
        throw new BJLG_Test_JSON_Response($data, $status_code);
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration) {
        $GLOBALS['bjlg_test_transients'][$transient] = $value;
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        return $GLOBALS['bjlg_test_transients'][$transient] ?? false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        unset($GLOBALS['bjlg_test_transients'][$transient]);
        return true;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) {
        $GLOBALS['bjlg_test_scheduled_events'][] = [
            'timestamp' => $timestamp,
            'hook' => $hook,
            'args' => $args,
        ];

        return true;
    }
}
