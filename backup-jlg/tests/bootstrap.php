<?php
declare(strict_types=1);

namespace {

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

require_once __DIR__ . '/../includes/class-bjlg-debug.php';

if (!defined('BJLG_CAPABILITY')) {
    define('BJLG_CAPABILITY', 'manage_options');
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_textarea')) {
    function esc_textarea($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = []) {
        if (is_object($args)) {
            $args = get_object_vars($args);
        }

        if (!is_array($args)) {
            $args = [];
        }

        if (!is_array($defaults)) {
            $defaults = [];
        }

        return array_merge($defaults, $args);
    }
}

if (!defined('WP_CONTENT_DIR')) {
    $wp_content_dir = sys_get_temp_dir() . '/bjlg-wp-content';
    if (!is_dir($wp_content_dir)) {
        mkdir($wp_content_dir, 0777, true);
    }

    define('WP_CONTENT_DIR', $wp_content_dir);
}

if (!defined('WP_PLUGIN_DIR')) {
    $wp_plugin_dir = WP_CONTENT_DIR . '/plugins';
    if (!is_dir($wp_plugin_dir)) {
        mkdir($wp_plugin_dir, 0777, true);
    }

    define('WP_PLUGIN_DIR', $wp_plugin_dir);
}

if (!defined('BJLG_BACKUP_DIR')) {
    $test_backup_dir = sys_get_temp_dir() . '/bjlg-tests/';
    if (!is_dir($test_backup_dir)) {
        mkdir($test_backup_dir, 0777, true);
    }

    define('BJLG_BACKUP_DIR', $test_backup_dir);
}

if (!defined('DB_NAME')) {
    define('DB_NAME', 'wordpress_test');
}

if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}

if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

if (!defined('DB_COLLATE')) {
    define('DB_COLLATE', '');
}

if (!defined('WP_MEMORY_LIMIT')) {
    define('WP_MEMORY_LIMIT', '256M');
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

if (!function_exists('wp_convert_hr_to_bytes')) {
    function wp_convert_hr_to_bytes($value) {
        $value = trim((string) $value);

        if ($value === '') {
            return 0;
        }

        $number = (float) $value;
        $last_char = strtolower(substr($value, -1));

        switch ($last_char) {
            case 'g':
                return (int) round($number * 1024 * 1024 * 1024);
            case 'm':
                return (int) round($number * 1024 * 1024);
            case 'k':
                return (int) round($number * 1024);
            default:
                return (int) $number;
        }
    }
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (!isset($GLOBALS['bjlg_test_hooks'])) {
    $GLOBALS['bjlg_test_hooks'] = [
        'actions' => [],
        'filters' => []
    ];
}

$GLOBALS['bjlg_test_current_user_can'] = true;
$GLOBALS['bjlg_test_transients'] = [];
$GLOBALS['bjlg_test_scheduled_events'] = [
    'recurring' => [],
    'single' => [],
];
$GLOBALS['bjlg_test_last_json_success'] = null;
$GLOBALS['bjlg_test_last_json_error'] = null;
$GLOBALS['bjlg_test_set_transient_mock'] = null;
$GLOBALS['bjlg_test_schedule_single_event_mock'] = null;
$GLOBALS['bjlg_test_wp_handle_upload_mock'] = null;
$GLOBALS['bjlg_test_wp_check_filetype_and_ext_mock'] = null;
$GLOBALS['bjlg_test_options'] = [];
$GLOBALS['bjlg_registered_routes'] = [];
$GLOBALS['bjlg_history_entries'] = [];
$GLOBALS['current_user'] = null;
$GLOBALS['current_user_id'] = 0;

if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        /** @var string */
        public $options = 'wp_options';

        /** @var string */
        public $prefix = 'wp_';

        public function query($query)
        {
            return true;
        }

        public function get_row($query)
        {
            return (object) [
                'size' => 0,
                'tables' => 0,
            ];
        }

        public function db_version()
        {
            return '10.4.0-test';
        }
    };
}

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

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!class_exists('BJLG_Debug') && class_exists('BJLG\\BJLG_Debug')) {
    class_alias('BJLG\\BJLG_Debug', 'BJLG_Debug');
}

if (!class_exists('BJLG_History')) {
    class BJLG_History
    {
        public static function get_stats($period = 'week')
        {
            return [
                'total_actions' => 0,
                'successful' => 0,
                'failed' => 0,
                'info' => 0,
                'by_action' => [],
                'by_user' => [],
                'most_active_hour' => null,
            ];
        }

        public static function log($action, $status, $message, $user_id = null)
        {
            if ($user_id === null && function_exists('get_current_user_id')) {
                $user_id = get_current_user_id();
            }

            if (function_exists('do_action')) {
                do_action('bjlg_history_logged', $action, $status, $message, $user_id);
            }
        }

        public static function get_history($limit = 100)
        {
            return [];
        }
    }
}

if (!class_exists('BJLG\\BJLG_History')) {
    class_alias('BJLG_History', 'BJLG\\BJLG_History');
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

if (!class_exists('BJLG_Test_WP_Die')) {
    class BJLG_Test_WP_Die extends RuntimeException {
        /** @var int|null */
        public $status_code;

        /**
         * @param string   $message
         * @param int|null $status_code
         */
        public function __construct($message = '', $status_code = null) {
            $this->status_code = $status_code;
            parent::__construct((string) $message);
        }
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['bjlg_test_hooks']['actions'][$hook][$priority][] = [
            'callback' => $callback,
            'accepted_args' => (int) $accepted_args,
        ];
    }
}

if (!function_exists('has_action')) {
    function has_action($hook, $callback = false) {
        if (empty($GLOBALS['bjlg_test_hooks']['actions'][$hook])) {
            return false;
        }

        if ($callback === false) {
            $count = 0;

            foreach ($GLOBALS['bjlg_test_hooks']['actions'][$hook] as $callbacks) {
                $count += count($callbacks);
            }

            return $count;
        }

        foreach ($GLOBALS['bjlg_test_hooks']['actions'][$hook] as $priority => $callbacks) {
            foreach ($callbacks as $definition) {
                if ($definition['callback'] === $callback) {
                    return $priority;
                }
            }
        }

        return false;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['bjlg_test_hooks']['filters'][$hook][$priority][] = [
            'callback' => $callback,
            'accepted_args' => (int) $accepted_args,
        ];
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        if (empty($GLOBALS['bjlg_test_hooks']['actions'][$hook])) {
            return;
        }

        ksort($GLOBALS['bjlg_test_hooks']['actions'][$hook]);

        foreach ($GLOBALS['bjlg_test_hooks']['actions'][$hook] as $callbacks) {
            foreach ($callbacks as $definition) {
                $accepted = $definition['accepted_args'] > 0 ? $definition['accepted_args'] : count($args);
                $callback_args = array_slice($args, 0, $accepted);
                call_user_func_array($definition['callback'], $callback_args);
            }
        }
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        if (empty($GLOBALS['bjlg_test_hooks']['filters'][$hook])) {
            return $value;
        }

        ksort($GLOBALS['bjlg_test_hooks']['filters'][$hook]);

        $all_args = array_merge([$value], $args);

        foreach ($GLOBALS['bjlg_test_hooks']['filters'][$hook] as $callbacks) {
            foreach ($callbacks as $definition) {
                $accepted = $definition['accepted_args'] > 0 ? $definition['accepted_args'] : count($all_args);
                $callback_args = array_slice($all_args, 0, $accepted);
                $all_args[0] = call_user_func_array($definition['callback'], $callback_args);
            }
        }

        return $all_args[0];
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $die = true) {
        return true;
    }
}

if (!function_exists('wp_set_current_user')) {
    function wp_set_current_user($user_id) {
        $user_id = (int) $user_id;
        $GLOBALS['current_user_id'] = $user_id;
        $users = $GLOBALS['bjlg_test_users'] ?? [];
        $GLOBALS['current_user'] = $users[$user_id] ?? null;

        return $GLOBALS['current_user'];
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() {
        return $GLOBALS['current_user'] ?? null;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return (int) ($GLOBALS['current_user_id'] ?? 0);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        $current_user = $GLOBALS['current_user'] ?? null;

        if (is_object($current_user)) {
            if (isset($current_user->allcaps) && is_array($current_user->allcaps) && array_key_exists($capability, $current_user->allcaps)) {
                return (bool) $current_user->allcaps[$capability];
            }

            if (isset($current_user->caps) && is_array($current_user->caps) && array_key_exists($capability, $current_user->caps)) {
                return (bool) $current_user->caps[$capability];
            }
        }

        return $GLOBALS['bjlg_test_current_user_can'] ?? false;
    }
}

if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        $users = $GLOBALS['bjlg_test_users'] ?? [];

        if ($field === 'id' || $field === 'ID') {
            $id = (int) $value;

            if (isset($users[$id])) {
                return $users[$id];
            }

            return false;
        }

        if ($field === 'login' || $field === 'user_login') {
            foreach ($users as $user) {
                if (isset($user->user_login) && $user->user_login === $value) {
                    return $user;
                }
            }
        }

        if ($field === 'email' || $field === 'user_email') {
            foreach ($users as $user) {
                if (isset($user->user_email) && $user->user_email === $value) {
                    return $user;
                }
            }
        }

        return false;
    }
}

if (!function_exists('user_can')) {
    function user_can($user, $capability) {
        if (is_object($user)) {
            if (isset($user->allcaps) && is_array($user->allcaps)) {
                return !empty($user->allcaps[$capability]);
            }

            if (isset($user->caps) && is_array($user->caps)) {
                return !empty($user->caps[$capability]);
            }
        }

        return false;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $GLOBALS['bjlg_test_options'][$option] ?? $default;
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw') {
        if ($show === 'version') {
            return '6.4.0-test';
        }

        return 'Backup JLG Test';
    }
}

if (!function_exists('get_site_url')) {
    function get_site_url($blog_id = null, $path = '', $scheme = null) {
        return 'https://example.test' . $path;
    }
}

if (!function_exists('get_home_url')) {
    function get_home_url($blog_id = null, $path = '', $scheme = null) {
        return 'https://example.test' . $path;
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '', $scheme = null) {
        return get_home_url(null, $path, $scheme);
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite() {
        return false;
    }
}

if (!function_exists('get_locale')) {
    function get_locale() {
        return 'fr_FR';
    }
}

if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) {
        if ($type === 'timestamp') {
            return time();
        }

        if ($type === 'mysql') {
            return date('Y-m-d H:i:s');
        }

        return date((string) $type);
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

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9_]/', '', $key);
    }
}

if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true) {
        $result = ($checked == $current) ? ' checked="checked"' : '';

        if ($echo) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true) {
        $result = ($selected == $current) ? ' selected="selected"' : '';

        if ($echo) {
            echo $result;
        }

        return $result;
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

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        $url = trim((string) $url);

        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $sanitized = filter_var($url, FILTER_SANITIZE_URL);

        if ($sanitized === false) {
            return '';
        }

        return str_replace(
            ['<', '>', '"', "'", ' '],
            ['%3C', '%3E', '%22', '%27', '%20'],
            $sanitized
        );
    }
}

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush() {
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') {
        return true;
    }
}

if (!function_exists('get_theme_root')) {
    function get_theme_root() {
        $root = WP_CONTENT_DIR . '/themes';
        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }

        return $root;
    }
}

if (!function_exists('wp_get_upload_dir')) {
    function wp_get_upload_dir() {
        $basedir = WP_CONTENT_DIR . '/uploads';
        if (!is_dir($basedir)) {
            mkdir($basedir, 0777, true);
        }

        return [
            'basedir' => $basedir,
        ];
    }
}

if (!function_exists('get_plugins')) {
    function get_plugins() {
        return [
            'backup-jlg/backup-jlg.php' => [
                'Name' => 'Backup JLG',
                'Version' => '2.0.3',
            ],
        ];
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) {
        return [
            'response' => [
                'code' => 200,
                'message' => 'OK',
            ],
            'body' => '',
            'url' => $url,
            'args' => $args,
        ];
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

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($target) {
        if (is_dir($target)) {
            return true;
        }

        if ($target === '' || $target === null) {
            return false;
        }

        return mkdir($target, 0777, true);
    }
}

if (!function_exists('wp_check_filetype_and_ext')) {
    function wp_check_filetype_and_ext($file, $filename, $mimes = null) {
        $mock = $GLOBALS['bjlg_test_wp_check_filetype_and_ext_mock'] ?? null;

        if (is_callable($mock)) {
            return $mock($file, $filename, $mimes);
        }

        $extension = strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION));

        if (is_array($mimes) && $mimes !== []) {
            if ($extension === '' || !array_key_exists($extension, $mimes)) {
                return [
                    'ext' => false,
                    'type' => false,
                    'proper_filename' => false,
                ];
            }

            return [
                'ext' => $extension,
                'type' => $mimes[$extension],
                'proper_filename' => $filename,
            ];
        }

        return [
            'ext' => $extension ?: false,
            'type' => $extension ? 'application/octet-stream' : false,
            'proper_filename' => $filename,
        ];
    }
}

if (!function_exists('wp_handle_upload')) {
    function wp_handle_upload($file, $overrides = false, $time = null, $action = '') {
        $mock = $GLOBALS['bjlg_test_wp_handle_upload_mock'] ?? null;

        if (is_callable($mock)) {
            return $mock($file, $overrides, $time, $action);
        }

        $upload_dir = sys_get_temp_dir() . '/bjlg-uploaded-files';

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $original_name = isset($file['name']) ? basename((string) $file['name']) : 'upload.tmp';
        $destination = $upload_dir . '/' . uniqid('upload_', true) . '_' . $original_name;

        if (!empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
            copy($file['tmp_name'], $destination);
        } else {
            file_put_contents($destination, '');
        }

        return [
            'file' => $destination,
            'url' => 'http://example.com/' . basename($destination),
            'type' => $file['type'] ?? '',
        ];
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null) {
        $GLOBALS['bjlg_test_last_json_error'] = [
            'data' => $data,
            'status_code' => $status_code,
        ];

        throw new BJLG_Test_JSON_Response($data, $status_code);
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null) {
        $GLOBALS['bjlg_test_last_json_success'] = [
            'data' => $data,
            'status_code' => $status_code,
        ];

        throw new BJLG_Test_JSON_Response($data, $status_code);
    }
}

if (!function_exists('status_header')) {
    function status_header($code) {
        $GLOBALS['bjlg_test_last_status_header'] = $code;
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = []) {
        $response_code = null;

        if (is_array($args) && isset($args['response'])) {
            $response_code = $args['response'];
        } elseif (!is_array($args) && $args !== null) {
            $response_code = (int) $args;
        }

        throw new BJLG_Test_WP_Die($message, $response_code);
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        if ($special_chars) {
            $chars .= '!@#$%^&*()';
        }

        if ($extra_special_chars) {
            $chars .= '-_[]{}<>~`+=,.;:/?|';
        }

        $password = '';
        $max_index = strlen($chars) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max_index)];
        }

        return $password;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration) {
        $mock = $GLOBALS['bjlg_test_set_transient_mock'] ?? null;

        if (is_callable($mock)) {
            $mock_result = $mock($transient, $value, $expiration);

            if ($mock_result === false) {
                return false;
            }

            if ($mock_result === true) {
                $GLOBALS['bjlg_test_transients'][$transient] = $value;

                return true;
            }
        }

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
        $mock = $GLOBALS['bjlg_test_schedule_single_event_mock'] ?? null;

        if (is_callable($mock)) {
            $mock_result = $mock($timestamp, $hook, $args);

            if ($mock_result !== null) {
                if ($mock_result === false || $mock_result instanceof WP_Error) {
                    return $mock_result;
                }

                $GLOBALS['bjlg_test_scheduled_events']['single'][] = [
                    'timestamp' => $timestamp,
                    'hook' => $hook,
                    'args' => $args,
                ];

                return true;
            }
        }

        $GLOBALS['bjlg_test_scheduled_events']['single'][] = [
            'timestamp' => $timestamp,
            'hook' => $hook,
            'args' => $args,
        ];

        return true;
    }
}

if (!function_exists('wp_unschedule_event')) {
    function wp_unschedule_event($timestamp, $hook, $args = []) {
        foreach ($GLOBALS['bjlg_test_scheduled_events']['single'] as $index => $event) {
            if ((int) $event['timestamp'] === (int) $timestamp
                && $event['hook'] === $hook
                && $event['args'] == $args
            ) {
                unset($GLOBALS['bjlg_test_scheduled_events']['single'][$index]);

                return true;
            }
        }

        return false;
    }
}

if (!function_exists('bjlg_build_cron_event_key')) {
    function bjlg_build_cron_event_key($hook, $args) {
        if (!is_array($args)) {
            $args = (array) $args;
        }

        if (empty($args)) {
            return $hook . '::default';
        }

        return $hook . '::' . md5(serialize(array_values($args)));
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) {
        $key = bjlg_build_cron_event_key($hook, $args);

        if (!isset($GLOBALS['bjlg_test_scheduled_events']['recurring'][$hook])) {
            $GLOBALS['bjlg_test_scheduled_events']['recurring'][$hook] = [];
        }

        $GLOBALS['bjlg_test_scheduled_events']['recurring'][$hook][$key] = [
            'timestamp' => $timestamp,
            'recurrence' => $recurrence,
            'args' => (array) $args,
        ];

        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) {
        $has_args = func_num_args() > 1;

        if (empty($GLOBALS['bjlg_test_scheduled_events']['recurring'][$hook])) {
            return false;
        }

        if ($has_args) {
            $key = bjlg_build_cron_event_key($hook, $args);
            if (isset($GLOBALS['bjlg_test_scheduled_events']['recurring'][$hook][$key])) {
                return $GLOBALS['bjlg_test_scheduled_events']['recurring'][$hook][$key]['timestamp'];
            }

            return false;
        }

        $timestamps = array_map(
            static function ($event) {
                return $event['timestamp'];
            },
            $GLOBALS['bjlg_test_scheduled_events']['recurring'][$hook]
        );

        if (empty($timestamps)) {
            return false;
        }

        return min($timestamps);
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook, $args = []) {
        $has_args = func_num_args() > 1;

        if ($has_args) {
            $key = bjlg_build_cron_event_key($hook, $args);
            if (isset($GLOBALS['bjlg_test_scheduled_events']['recurring'][$hook][$key])) {
                unset($GLOBALS['bjlg_test_scheduled_events']['recurring'][$hook][$key]);
                if (empty($GLOBALS['bjlg_test_scheduled_events']['recurring'][$hook])) {
                    unset($GLOBALS['bjlg_test_scheduled_events']['recurring'][$hook]);
                }
            }
        } else {
            unset($GLOBALS['bjlg_test_scheduled_events']['recurring'][$hook]);
        }

        foreach ($GLOBALS['bjlg_test_scheduled_events']['single'] as $index => $event) {
            if ($event['hook'] !== $hook) {
                continue;
            }

            if ($has_args && $event['args'] !== (array) $args) {
                continue;
            }

            unset($GLOBALS['bjlg_test_scheduled_events']['single'][$index]);
        }

        return true;
    }
}

if (!function_exists('human_time_diff')) {
    function human_time_diff($from, $to = 0) {
        $from = (int) $from;
        $to = $to ? (int) $to : time();
        $diff = abs($to - $from);

        if ($diff <= 0) {
            return '0 seconds';
        }

        $units = [
            DAY_IN_SECONDS => 'day',
            HOUR_IN_SECONDS => 'hour',
            MINUTE_IN_SECONDS => 'minute',
        ];

        foreach ($units as $seconds => $label) {
            if ($diff >= $seconds) {
                $value = (int) floor($diff / $seconds);
                return $value . ' ' . $label . ($value > 1 ? 's' : '');
            }
        }

        return $diff . ' seconds';
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '') {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value = false, $url = false) {
        if (is_array($key)) {
            $params = $key;
            $url = (string) $value;
        } else {
            $params = [$key => $value];
            $url = (string) $url;
        }

        if ($url === '' || $url === false) {
            $url = 'https://example.com/';
        }

        $parsed_url = parse_url($url);
        $query = [];

        if (!empty($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query);
        }

        foreach ($params as $param_key => $param_value) {
            if ($param_value === false) {
                unset($query[$param_key]);
                continue;
            }

            $query[$param_key] = $param_value;
        }

        $parsed_url['query'] = http_build_query($query);

        $scheme   = $parsed_url['scheme'] ?? 'https';
        $host     = $parsed_url['host'] ?? 'example.com';
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $path     = $parsed_url['path'] ?? '';
        $queryStr = $parsed_url['query'] ? '?' . $parsed_url['query'] : '';

        return $scheme . '://' . $host . $port . $path . $queryStr;
    }
}

if (!function_exists('size_format')) {
    function size_format($bytes, $decimals = 2) {
        $bytes = (float) $bytes;

        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return number_format_i18n($value, $decimals) . ' ' . $units[$power];
    }
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, $decimals = 0) {
        return number_format((float) $number, (int) $decimals, '.', ',');
    }
}

if (!function_exists('rest_url')) {
    function rest_url($path = '') {
        return 'https://example.com/wp-json/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_get_update_data')) {
    function wp_get_update_data() {
        return [
            'counts' => [
                'total' => 0,
            ],
        ];
    }
}

if (!function_exists('rest_ensure_response')) {
    function rest_ensure_response($response) {
        return $response;
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = [], $override = false) {
        $namespace = trim((string) $namespace, '/');

        if (!isset($GLOBALS['bjlg_registered_routes'][$namespace])) {
            $GLOBALS['bjlg_registered_routes'][$namespace] = [];
        }

        $GLOBALS['bjlg_registered_routes'][$namespace][$route] = $args;

        return true;
    }
}

}

namespace BJLG {
    if (!function_exists(__NAMESPACE__ . '\\disk_free_space')) {
        function disk_free_space($directory)
        {
            if (isset($GLOBALS['bjlg_test_disk_free_space_mock']) && is_callable($GLOBALS['bjlg_test_disk_free_space_mock'])) {
                return call_user_func($GLOBALS['bjlg_test_disk_free_space_mock'], $directory);
            }

            return \disk_free_space($directory);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\disk_total_space')) {
        function disk_total_space($directory)
        {
            if (isset($GLOBALS['bjlg_test_disk_total_space_mock']) && is_callable($GLOBALS['bjlg_test_disk_total_space_mock'])) {
                return call_user_func($GLOBALS['bjlg_test_disk_total_space_mock'], $directory);
            }

            return \disk_total_space($directory);
        }
    }
}
