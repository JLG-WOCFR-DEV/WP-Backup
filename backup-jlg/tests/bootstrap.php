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

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename) {
        return preg_replace('/[^A-Za-z0-9\\-_.]/', '', (string) $filename);
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
