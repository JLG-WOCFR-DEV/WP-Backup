<?php
declare(strict_types=1);

namespace BJLG {
    if (!function_exists(__NAMESPACE__ . '\\filesize')) {
        function filesize($filename)
        {
            if (isset($GLOBALS['bjlg_test_filesize_callback']) && is_callable($GLOBALS['bjlg_test_filesize_callback'])) {
                return call_user_func($GLOBALS['bjlg_test_filesize_callback'], $filename);
            }

            return \filesize($filename);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\filemtime')) {
        function filemtime($filename)
        {
            if (isset($GLOBALS['bjlg_test_filemtime_callback']) && is_callable($GLOBALS['bjlg_test_filemtime_callback'])) {
                return call_user_func($GLOBALS['bjlg_test_filemtime_callback'], $filename);
            }

            return \filemtime($filename);
        }
    }
}

namespace {
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

            /**
             * @param mixed $message
             */
            public static function error($message): void
            {
                self::log($message);
            }
        }

        class_alias('BJLG_Debug', 'BJLG\\BJLG_Debug');
    }

    require_once __DIR__ . '/../includes/class-bjlg-client-ip-helper.php';
    require_once __DIR__ . '/../includes/class-bjlg-cleanup.php';
    require_once __DIR__ . '/../includes/class-bjlg-actions.php';
    require_once __DIR__ . '/../includes/class-bjlg-rest-api.php';
    require_once __DIR__ . '/../includes/class-bjlg-restore.php';
    require_once __DIR__ . '/../includes/class-bjlg-encryption.php';
    require_once __DIR__ . '/../includes/class-bjlg-webhooks.php';
    require_once __DIR__ . '/../includes/class-bjlg-backup.php';
    require_once __DIR__ . '/../includes/class-bjlg-rate-limiter.php';
    require_once __DIR__ . '/../includes/class-bjlg-settings.php';

    class BJLG_Test_Auth_Request
    {
        /** @var array<string, mixed> */
        private $params;

        /** @var array<string, string> */
        private $headers;

        /**
         * @param array<string, mixed>  $params
         * @param array<string, string> $headers
         */
        public function __construct(array $params = [], array $headers = [])
        {
            $this->params = $params;
            $this->headers = [];

            foreach ($headers as $name => $value) {
                $this->headers[strtolower((string) $name)] = (string) $value;
            }
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }

        public function get_header($name)
        {
            $normalized = strtolower((string) $name);

            return $this->headers[$normalized] ?? '';
        }
    }

    class BJLG_Test_Restore_For_Rest extends BJLG\BJLG_Restore
    {
        /** @var int */
        public $pre_backup_calls = 0;

        protected function perform_pre_restore_backup(): array
        {
            $this->pre_backup_calls++;

            return [
                'filename' => 'test-pre-restore-' . $this->pre_backup_calls . '.zip',
                'filepath' => BJLG_BACKUP_DIR . 'test-pre-restore-' . $this->pre_backup_calls . '.zip',
            ];
        }
    }

    final class BJLG_REST_APITest extends TestCase
    {
        /** @var bool */
        private static $ensure_auth_key = true;

        /** @var array<int, string> */
        private static $tests_without_auth_key = [
            'test_a_authenticate_returns_error_when_auth_key_missing',
            'test_client_ip_helper_ignores_untrusted_forwarded_for',
        ];

        public function runBare(): void
        {
            $original = self::$ensure_auth_key;

            $name = null;

            if (method_exists($this, 'getName')) {
                $name = $this->getName(false);
            }

            if ($name === null && method_exists($this, 'name')) {
                $name = $this->name();
            }

            if ($name !== null && in_array($name, self::$tests_without_auth_key, true)) {
                self::$ensure_auth_key = false;
            }

            try {
                parent::runBare();
            } finally {
                self::$ensure_auth_key = $original;
            }
        }

        protected function setUp(): void
        {
            parent::setUp();

            if (self::$ensure_auth_key && !defined('AUTH_KEY')) {
                define('AUTH_KEY', 'test-auth-key');
            }

            \BJLG\BJLG_Debug::$logs = [];
            $GLOBALS['bjlg_test_options'] = [];
            $GLOBALS['bjlg_test_hooks'] = [
                'actions' => [],
                'filters' => [],
            ];
            $GLOBALS['bjlg_registered_routes'] = [];
            $GLOBALS['bjlg_test_users'] = [];
            $GLOBALS['bjlg_history_entries'] = [];
            $GLOBALS['current_user'] = null;
            $GLOBALS['current_user_id'] = 0;
            $GLOBALS['bjlg_test_current_user_can'] = true;
            $GLOBALS['bjlg_test_realpath_mock'] = null;
            $GLOBALS['bjlg_test_transients'] = [];
            $GLOBALS['bjlg_test_scheduled_events'] = [
                'recurring' => [],
                'single' => [],
            ];
            $GLOBALS['bjlg_test_set_transient_mock'] = null;
            $GLOBALS['bjlg_test_schedule_single_event_mock'] = null;
            unset($GLOBALS['bjlg_test_filesize_callback'], $GLOBALS['bjlg_test_filemtime_callback']);

            $GLOBALS['bjlg_test_filesize_calls'] = [];
            $GLOBALS['bjlg_test_filemtime_calls'] = [];

            if (class_exists(BJLG\BJLG_Settings::class)) {
                $settings_instance = new \ReflectionProperty(BJLG\BJLG_Settings::class, 'instance');
                $settings_instance->setAccessible(true);
                $settings_instance->setValue(null, null);
            }

            $lock_property = new \ReflectionProperty(BJLG\BJLG_Backup::class, 'in_memory_lock');
            $lock_property->setAccessible(true);
            $lock_property->setValue(null, null);

            if (!is_dir(BJLG_BACKUP_DIR)) {
                mkdir(BJLG_BACKUP_DIR, 0777, true);
            }

            add_action('bjlg_history_logged', static function ($action, $status, $message, $user_id) {
                $GLOBALS['bjlg_history_entries'][] = [
                    'action' => (string) $action,
                    'status' => (string) $status,
                    'details' => (string) $message,
                    'user_id' => $user_id,
                ];
            }, 10, 4);
        }

        private function makeUser(int $id, string $login, ?array $caps = null, ?array $roles = null): object
        {
            $caps = $caps ?? [BJLG_CAPABILITY => true];
            $roles = $roles ?? ['administrator'];

            return (object) [
                'ID' => $id,
                'user_login' => $login,
                'user_email' => $login . '@example.com',
                'allcaps' => $caps,
                'roles' => $roles,
            ];
        }

        /**
         * @param array<int, string> $components
         */
        private function createBackupWithComponents(array $components): string
        {
            $filename = BJLG_BACKUP_DIR . 'bjlg-test-' . uniqid('', true) . '.zip';

            $zip = new \ZipArchive();
            $openResult = $zip->open($filename, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $this->assertTrue($openResult === true || $openResult === \ZipArchive::ER_OK);

            $manifest = [
                'type' => 'full',
                'contains' => $components,
            ];

            $zip->addFromString('backup-manifest.json', json_encode($manifest));
            $zip->addFromString('dummy.txt', 'content');
            $zip->close();

            return $filename;
        }

        private function deleteBackupIfExists(string $path): void
        {
            if (is_file($path)) {
                unlink($path);
            }
        }

        public function test_client_ip_helper_ignores_untrusted_forwarded_for(): void
        {
            $had_remote = array_key_exists('REMOTE_ADDR', $_SERVER);
            $previous_remote = $had_remote ? $_SERVER['REMOTE_ADDR'] : null;
            $had_forwarded = array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER);
            $previous_forwarded = $had_forwarded ? $_SERVER['HTTP_X_FORWARDED_FOR'] : null;

            $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.33';

            try {
                unset($GLOBALS['bjlg_test_options']['bjlg_trusted_proxy_headers']);

                $ip = \BJLG\BJLG_Client_IP_Helper::get_client_ip([
                    'bjlg_history_trusted_proxy_headers',
                    'bjlg_rate_limiter_trusted_proxy_headers',
                ]);

                $this->assertSame('203.0.113.10', $ip);
            } finally {
                if ($had_remote) {
                    $_SERVER['REMOTE_ADDR'] = $previous_remote;
                } else {
                    unset($_SERVER['REMOTE_ADDR']);
                }

                if ($had_forwarded) {
                    $_SERVER['HTTP_X_FORWARDED_FOR'] = $previous_forwarded;
                } else {
                    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
                }
            }
        }

        public function test_a_authenticate_returns_error_when_auth_key_missing(): void
        {
            $this->assertFalse(defined('AUTH_KEY'));

            $api = new BJLG\BJLG_REST_API();

            $user = $this->makeUser(101, 'missing-key-user');
            $GLOBALS['bjlg_test_users'] = [
                $user->ID => $user,
            ];

            $api_key = 'missing-key-auth';

            update_option('bjlg_api_keys', [[
                'key' => wp_hash_password($api_key),
                'user_id' => $user->ID,
                'roles' => $user->roles,
            ]]);

            $request = new class($api_key, $user->user_login) {
                /** @var array<string, mixed> */
                private $params;

                public function __construct(string $api_key, string $username)
                {
                    $this->params = [
                        'api_key' => $api_key,
                        'username' => $username,
                    ];
                }

                public function get_param($key)
                {
                    return $this->params[$key] ?? null;
                }
            };

            $response = $api->authenticate($request);

            $this->assertInstanceOf(\WP_Error::class, $response);
            $this->assertSame('jwt_missing_signing_key', $response->get_error_code());
            $this->assertSame(
                __('La clé AUTH_KEY est manquante; impossible de générer un token JWT.', 'backup-jlg'),
                $response->get_error_message('jwt_missing_signing_key')
            );

            $error_data = $response->get_error_data('jwt_missing_signing_key');
            $this->assertIsArray($error_data);
            $this->assertSame(500, $error_data['status'] ?? null);
        }

        public function test_backups_route_per_page_validation_rejects_zero(): void
        {
            $api = new BJLG\BJLG_REST_API();
            $api->register_routes();

            $namespace = BJLG\BJLG_REST_API::API_NAMESPACE;
            $this->assertArrayHasKey($namespace, $GLOBALS['bjlg_registered_routes']);
            $this->assertArrayHasKey('/backups', $GLOBALS['bjlg_registered_routes'][$namespace]);

            $route = $GLOBALS['bjlg_registered_routes'][$namespace]['/backups'];
            $this->assertIsArray($route);

            $collection_endpoint = $route[0];
            $this->assertArrayHasKey('args', $collection_endpoint);
            $this->assertArrayHasKey('per_page', $collection_endpoint['args']);

            $validator = $collection_endpoint['args']['per_page']['validate_callback'];
            $this->assertIsCallable($validator);

            $result = $validator(0, null, null);
            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('rest_invalid_param', $result->get_error_code());

            $error_data = $result->get_error_data('rest_invalid_param');
            $this->assertIsArray($error_data);
            $this->assertSame(400, $error_data['status']);

            $this->assertTrue($validator(5, null, null));
        }

        public function test_history_route_limit_validation_rejects_out_of_range(): void
        {
            $api = new BJLG\BJLG_REST_API();
            $api->register_routes();

            $namespace = BJLG\BJLG_REST_API::API_NAMESPACE;
            $this->assertArrayHasKey($namespace, $GLOBALS['bjlg_registered_routes']);
            $this->assertArrayHasKey('/history', $GLOBALS['bjlg_registered_routes'][$namespace]);

            $route = $GLOBALS['bjlg_registered_routes'][$namespace]['/history'];
            $this->assertIsArray($route);
            $this->assertArrayHasKey('args', $route);
            $this->assertArrayHasKey('limit', $route['args']);

            $validator = $route['args']['limit']['validate_callback'];
            $this->assertIsCallable($validator);

            $result = $validator(-1, null, null);
            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('rest_invalid_param', $result->get_error_code());

            $error_data = $result->get_error_data('rest_invalid_param');
            $this->assertIsArray($error_data);
            $this->assertSame(400, $error_data['status']);

            $this->assertTrue($validator(100, null, null));
        }

        public function test_auth_route_registers_custom_permission_callback(): void
        {
            $api = new BJLG\BJLG_REST_API();
            $api->register_routes();

            $namespace = BJLG\BJLG_REST_API::API_NAMESPACE;
            $this->assertArrayHasKey($namespace, $GLOBALS['bjlg_registered_routes']);
            $this->assertArrayHasKey('/auth', $GLOBALS['bjlg_registered_routes'][$namespace]);

            $route = $GLOBALS['bjlg_registered_routes'][$namespace]['/auth'];

            $this->assertIsArray($route);
            $this->assertArrayHasKey('permission_callback', $route);
            $this->assertSame([$api, 'check_auth_permissions'], $route['permission_callback']);
        }

        public function test_get_backups_enforces_minimum_per_page(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $files = [];

            for ($i = 0; $i < 2; $i++) {
                $filename = BJLG_BACKUP_DIR . 'bjlg-test-' . uniqid('', true) . '.zip';
                file_put_contents($filename, 'backup');
                touch($filename, time() - $i);
                $files[] = $filename;
            }

            $request = new class {
                /** @var array<string, mixed> */
                private $params;

                public function __construct()
                {
                    $this->params = [
                        'page' => 1,
                        'per_page' => 0,
                        'type' => 'all',
                        'sort' => 'date_desc',
                    ];
                }

                public function get_param($key)
                {
                    return $this->params[$key] ?? null;
                }
            };

            $response = $api->get_backups($request);

            $this->assertIsArray($response);
            $this->assertArrayHasKey('pagination', $response);
            $this->assertSame(1, $response['pagination']['per_page']);

            foreach ($files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }

        public function test_get_backups_filters_database_and_files_types(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $databaseBackup = $this->createBackupWithComponents(['db']);
            $uploadsBackup = $this->createBackupWithComponents(['uploads']);

            $makeRequest = static function (string $type) {
                return new class($type) {
                    /** @var array<string, mixed> */
                    private $params;

                    public function __construct(string $type)
                    {
                        $this->params = [
                            'page' => 1,
                            'per_page' => 10,
                            'type' => $type,
                            'sort' => 'date_desc',
                        ];
                    }

                    public function get_param($key)
                    {
                        return $this->params[$key] ?? null;
                    }
                };
            };

            $databaseResponse = $api->get_backups($makeRequest('database'));
            $this->assertIsArray($databaseResponse);
            $this->assertArrayHasKey('backups', $databaseResponse);

            $databaseFilenames = array_map(static function ($backup) {
                return $backup['filename'] ?? null;
            }, $databaseResponse['backups']);

            $this->assertContains(basename($databaseBackup), $databaseFilenames);
            $this->assertNotContains(basename($uploadsBackup), $databaseFilenames);

            $databaseEntry = null;
            foreach ($databaseResponse['backups'] as $backup) {
                if (($backup['filename'] ?? '') === basename($databaseBackup)) {
                    $databaseEntry = $backup;
                    break;
                }
            }

            $this->assertNotNull($databaseEntry);
            $this->assertContains('db', $databaseEntry['components'] ?? []);

            $filesResponse = $api->get_backups($makeRequest('files'));
            $this->assertIsArray($filesResponse);
            $this->assertArrayHasKey('backups', $filesResponse);

            $filesFilenames = array_map(static function ($backup) {
                return $backup['filename'] ?? null;
            }, $filesResponse['backups']);

            $this->assertContains(basename($uploadsBackup), $filesFilenames);
            $this->assertNotContains(basename($databaseBackup), $filesFilenames);

            $filesEntry = null;
            foreach ($filesResponse['backups'] as $backup) {
                if (($backup['filename'] ?? '') === basename($uploadsBackup)) {
                    $filesEntry = $backup;
                    break;
                }
            }

            $this->assertNotNull($filesEntry);
            $this->assertNotEmpty(array_intersect(['plugins', 'themes', 'uploads'], $filesEntry['components'] ?? []));

            $this->deleteBackupIfExists($databaseBackup);
            $this->deleteBackupIfExists($uploadsBackup);
        }

        public function test_get_backups_does_not_generate_token_by_default(): void
        {
            $GLOBALS['bjlg_test_transients'] = [];

            $api = new BJLG\BJLG_REST_API();

            $backup = $this->createBackupWithComponents(['db']);

            $request = new class {
                /** @var array<string, mixed> */
                private $params;

                public function __construct()
                {
                    $this->params = [
                        'page' => 1,
                        'per_page' => 10,
                        'type' => 'all',
                        'sort' => 'date_desc',
                    ];
                }

                public function get_param($key)
                {
                    return $this->params[$key] ?? null;
                }
            };

            try {
                $response = $api->get_backups($request);

                $this->assertIsArray($response);
                $this->assertArrayHasKey('backups', $response);
                $this->assertNotEmpty($response['backups']);

                $first = $response['backups'][0];
                $this->assertArrayHasKey('download_rest_url', $first);
                $this->assertArrayNotHasKey('download_token', $first);
                $this->assertArrayNotHasKey('download_url', $first);
                $this->assertSame([], $GLOBALS['bjlg_test_transients']);
            } finally {
                $this->deleteBackupIfExists($backup);
            }
        }

        public function test_get_backups_can_include_download_token_on_demand(): void
        {
            $GLOBALS['bjlg_test_transients'] = [];

            $api = new BJLG\BJLG_REST_API();

            $backup = $this->createBackupWithComponents(['db']);

            $request = new class {
                /** @var array<string, mixed> */
                private $params;

                public function __construct()
                {
                    $this->params = [
                        'page' => 1,
                        'per_page' => 10,
                        'type' => 'all',
                        'sort' => 'date_desc',
                        'with_token' => '1',
                    ];
                }

                public function get_param($key)
                {
                    return $this->params[$key] ?? null;
                }
            };

            try {
                $response = $api->get_backups($request);

                $this->assertIsArray($response);
                $this->assertArrayHasKey('backups', $response);
                $this->assertNotEmpty($response['backups']);

                $first = $response['backups'][0];
                $this->assertArrayHasKey('download_token', $first);
                $this->assertArrayHasKey('download_url', $first);
                $this->assertArrayHasKey('download_expires_in', $first);
                $this->assertNotEmpty($GLOBALS['bjlg_test_transients']);
            } finally {
                $this->deleteBackupIfExists($backup);
            }
        }

        public function test_get_backups_with_token_logs_error_when_transient_fails(): void
        {
            $GLOBALS['bjlg_test_transients'] = [];

            $api = new BJLG\BJLG_REST_API();

            $backup = $this->createBackupWithComponents(['db']);

            $GLOBALS['bjlg_test_set_transient_mock'] = static function (string $transient, $value = null, $expiration = null) {
                if (strpos($transient, 'bjlg_download_') === 0) {
                    return false;
                }

                return null;
            };

            $request = new class {
                /** @var array<string, mixed> */
                private $params;

                public function __construct()
                {
                    $this->params = [
                        'page' => 1,
                        'per_page' => 10,
                        'type' => 'all',
                        'sort' => 'date_desc',
                        'with_token' => true,
                    ];
                }

                public function get_param($key)
                {
                    return $this->params[$key] ?? null;
                }
            };

            try {
                $response = $api->get_backups($request);

                $this->assertIsArray($response);
                $this->assertArrayHasKey('backups', $response);
                $this->assertNotEmpty($response['backups']);

                $first = $response['backups'][0];
                $this->assertArrayNotHasKey('download_token', $first);
                $this->assertArrayNotHasKey('download_url', $first);
                $this->assertArrayNotHasKey('download_expires_in', $first);
                $this->assertEmpty($GLOBALS['bjlg_test_transients']);
                $this->assertNotEmpty(\BJLG\BJLG_Debug::$logs);
            } finally {
                $GLOBALS['bjlg_test_set_transient_mock'] = null;
                $this->deleteBackupIfExists($backup);
            }
        }

        public function test_get_backup_does_not_include_token_by_default(): void
        {
            $GLOBALS['bjlg_test_transients'] = [];

            $api = new BJLG\BJLG_REST_API();

            $backup = $this->createBackupWithComponents(['db']);
            $filename = basename($backup);

            $request = new class($filename) {
                /** @var array<string, mixed> */
                private $params;

                public function __construct(string $id)
                {
                    $this->params = [
                        'id' => $id,
                    ];
                }

                public function get_param($key)
                {
                    return $this->params[$key] ?? null;
                }
            };

            try {
                $response = $api->get_backup($request);

                $this->assertIsArray($response);
                $this->assertArrayHasKey('download_rest_url', $response);
                $this->assertArrayNotHasKey('download_token', $response);
                $this->assertArrayNotHasKey('download_url', $response);
                $this->assertSame([], $GLOBALS['bjlg_test_transients']);
            } finally {
                $this->deleteBackupIfExists($backup);
            }
        }

        public function test_get_backup_includes_token_when_requested(): void
        {
            $GLOBALS['bjlg_test_transients'] = [];

            $api = new BJLG\BJLG_REST_API();

            $backup = $this->createBackupWithComponents(['db']);
            $filename = basename($backup);

            $request = new class($filename) {
                /** @var array<string, mixed> */
                private $params;

                public function __construct(string $id)
                {
                    $this->params = [
                        'id' => $id,
                        'with_token' => true,
                    ];
                }

                public function get_param($key)
                {
                    return $this->params[$key] ?? null;
                }
            };

            try {
                $response = $api->get_backup($request);

                $this->assertIsArray($response);
                $this->assertArrayHasKey('download_token', $response);
                $this->assertArrayHasKey('download_url', $response);
                $this->assertArrayHasKey('download_expires_in', $response);
                $this->assertNotEmpty($GLOBALS['bjlg_test_transients']);
            } finally {
                $this->deleteBackupIfExists($backup);
            }
        }

        public function test_get_backup_with_token_logs_error_when_transient_fails(): void
        {
            $GLOBALS['bjlg_test_transients'] = [];

            $api = new BJLG\BJLG_REST_API();

            $backup = $this->createBackupWithComponents(['db']);
            $filename = basename($backup);

            $GLOBALS['bjlg_test_set_transient_mock'] = static function (string $transient, $value = null, $expiration = null) {
                if (strpos($transient, 'bjlg_download_') === 0) {
                    return false;
                }

                return null;
            };

            $request = new class($filename) {
                /** @var array<string, mixed> */
                private $params;

                public function __construct(string $id)
                {
                    $this->params = [
                        'id' => $id,
                        'with_token' => true,
                    ];
                }

                public function get_param($key)
                {
                    return $this->params[$key] ?? null;
                }
            };

            try {
                $response = $api->get_backup($request);

                $this->assertIsArray($response);
                $this->assertArrayHasKey('download_rest_url', $response);
                $this->assertArrayNotHasKey('download_token', $response);
                $this->assertArrayNotHasKey('download_url', $response);
                $this->assertArrayNotHasKey('download_expires_in', $response);
                $this->assertEmpty($GLOBALS['bjlg_test_transients']);
                $this->assertNotEmpty(\BJLG\BJLG_Debug::$logs);
            } finally {
                $GLOBALS['bjlg_test_set_transient_mock'] = null;
                $this->deleteBackupIfExists($backup);
            }
        }

        public function test_verify_jwt_token_returns_error_for_invalid_signature(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $validToken = $this->generateJwtToken();
            $parts = explode('.', $validToken);

            $invalidSignature = $this->base64UrlEncode(
                hash_hmac('sha256', $parts[0] . '.' . $parts[1], AUTH_KEY . 'invalid', true)
            );

            if (hash_equals($parts[2], $invalidSignature)) {
                $invalidSignature = $this->base64UrlEncode(
                    hash_hmac('sha256', $parts[0] . '.' . $parts[1], AUTH_KEY . 'fallback', true)
                );
            }

            $parts[2] = $invalidSignature;
            $token = implode('.', $parts);

            $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
            $method = $reflection->getMethod('verify_jwt_token');
            $method->setAccessible(true);

            $result = $method->invoke($api, $token);

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('jwt_invalid_signature', $result->get_error_code());
        }

        public function test_verify_jwt_token_returns_error_when_user_missing(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $token = $this->generateJwtToken([
                'user_id' => 999,
                'username' => 'ghost-user',
            ]);

            $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
            $method = $reflection->getMethod('verify_jwt_token');
            $method->setAccessible(true);

            $result = $method->invoke($api, $token);

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('jwt_user_not_found', $result->get_error_code());
        }

        public function test_check_permissions_rejects_token_when_capability_revoked(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $user = (object) [
                'ID' => 42,
                'user_login' => 'revoked-user',
                'allcaps' => [
                    'read' => true,
                ],
            ];

            $GLOBALS['bjlg_test_users'] = [
                $user->ID => $user,
            ];

            $token = $this->generateJwtToken([
                'user_id' => $user->ID,
                'username' => $user->user_login,
            ]);

            $request = new class($token) {
                /** @var array<string, string> */
                private $headers;

                public function __construct(string $token)
                {
                    $this->headers = [
                        'Authorization' => 'Bearer ' . $token,
                    ];
                }

                public function get_header($key)
                {
                    return $this->headers[$key] ?? null;
                }
            };

            $result = $api->check_permissions($request);

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('jwt_insufficient_permissions', $result->get_error_code());
        }

        public function test_check_permissions_rejects_token_when_user_deleted(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $token = $this->generateJwtToken([
                'user_id' => 314,
                'username' => 'deleted-user',
            ]);

            $request = new class($token) {
                /** @var array<string, string> */
                private $headers;

                public function __construct(string $token)
                {
                    $this->headers = [
                        'Authorization' => 'Bearer ' . $token,
                    ];
                }

                public function get_header($key)
                {
                    return $this->headers[$key] ?? null;
                }
            };

            $result = $api->check_permissions($request);

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('jwt_user_not_found', $result->get_error_code());
        }

    public function test_verify_api_key_updates_usage_statistics(): void
    {
        $GLOBALS['bjlg_test_options'] = [];

        $api = new BJLG\BJLG_REST_API();

        $api_key = 'test-api-key';
        $hashed_key = wp_hash_password($api_key);
        $initial_usage_count = 2;
        $initial_last_used = time() - 3600;

        $user = $this->makeUser(101, 'api-user');
        $GLOBALS['bjlg_test_users'] = [
            $user->ID => $user,
        ];

        update_option('bjlg_api_keys', [[
            'key' => $hashed_key,
            'usage_count' => $initial_usage_count,
            'last_used' => $initial_last_used,
            'user_id' => $user->ID,
            'roles' => ['administrator'],
        ]]);

        $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
        $method = $reflection->getMethod('verify_api_key');
        $method->setAccessible(true);

        $before_verification = time();
        $result = $method->invoke($api, $api_key);

        $updated_keys = get_option('bjlg_api_keys');
        $storage_key_method = $reflection->getMethod('get_api_key_stats_storage_key');
        $storage_key_method->setAccessible(true);
        $stats_storage_key = $storage_key_method->invoke($api, $hashed_key);
        $stored_stats = get_transient($stats_storage_key);

        $this->assertIsObject($result);
        $this->assertSame($user->ID, $result->ID);
        $this->assertNotEmpty($updated_keys);
        $this->assertArrayNotHasKey('usage_count', $updated_keys[0]);
        $this->assertArrayNotHasKey('last_used', $updated_keys[0]);
        $this->assertIsArray($stored_stats);
        $this->assertSame($initial_usage_count + 1, $stored_stats['usage_count']);
        $this->assertArrayHasKey('last_used', $stored_stats);
        $this->assertGreaterThanOrEqual($before_verification, $stored_stats['last_used']);
        $this->assertSame($user->ID, $updated_keys[0]['user_id']);
        $this->assertContains('administrator', $updated_keys[0]['roles']);
    }

    public function test_verify_api_key_migrates_plain_keys(): void
    {
        $GLOBALS['bjlg_test_options'] = [];

        $api = new BJLG\BJLG_REST_API();

        $api_key = 'legacy-api-key';

        $user = $this->makeUser(202, 'legacy-user');
        $GLOBALS['bjlg_test_users'] = [
            $user->ID => $user,
        ];

        update_option('bjlg_api_keys', [[
            'key' => $api_key,
            'usage_count' => 0,
            'user_login' => $user->user_login,
        ]]);

        $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
        $method = $reflection->getMethod('verify_api_key');
        $method->setAccessible(true);

        $result = $method->invoke($api, $api_key);

        $this->assertIsObject($result);
        $this->assertSame($user->ID, $result->ID);

        $updated_keys = get_option('bjlg_api_keys');
        $storage_key_method = $reflection->getMethod('get_api_key_stats_storage_key');
        $storage_key_method->setAccessible(true);
        $stats_storage_key = $storage_key_method->invoke($api, $updated_keys[0]['key']);
        $stored_stats = get_transient($stats_storage_key);

        $this->assertNotSame($api_key, $updated_keys[0]['key']);
        $this->assertTrue(wp_check_password($api_key, $updated_keys[0]['key']));
        $this->assertSame($user->ID, $updated_keys[0]['user_id']);
        $this->assertContains('administrator', $updated_keys[0]['roles']);
        $this->assertArrayNotHasKey('usage_count', $updated_keys[0]);
        $this->assertArrayNotHasKey('last_used', $updated_keys[0]);
        $this->assertIsArray($stored_stats);
        $this->assertSame(1, $stored_stats['usage_count']);
        $this->assertSame($user->ID, get_current_user_id());
    }

    public function test_filter_api_keys_before_save_hashes_plain_keys(): void
    {
        $api = new BJLG\BJLG_REST_API();

        $plain_key = 'plain-key';

        $user = $this->makeUser(303, 'api-form-user');
        $GLOBALS['bjlg_test_users'] = [
            $user->ID => $user,
        ];

        $filtered = $api->filter_api_keys_before_save([
            [
                'key' => $plain_key,
                'label' => 'My key',
                'user' => $user->user_login,
            ],
        ]);

        $this->assertNotSame($plain_key, $filtered[0]['key']);
        $this->assertTrue(wp_check_password($plain_key, $filtered[0]['key']));
        $this->assertArrayNotHasKey('plain_key', $filtered[0]);
        $this->assertSame($user->ID, $filtered[0]['user_id']);
        $this->assertContains('administrator', $filtered[0]['roles']);
    }

    public function test_authenticate_rejects_api_key_for_different_user(): void
    {
        $api = new BJLG\BJLG_REST_API();

        $owner = $this->makeUser(404, 'api-owner');
        $other = $this->makeUser(405, 'other-user');
        $GLOBALS['bjlg_test_users'] = [
            $owner->ID => $owner,
            $other->ID => $other,
        ];

        $api_key = 'strict-key';

        update_option('bjlg_api_keys', [[
            'key' => wp_hash_password($api_key),
            'user_id' => $owner->ID,
            'roles' => $owner->roles,
        ]]);

        $request = new class($api_key, $other->user_login) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct(string $key, string $username)
            {
                $this->params = [
                    'api_key' => $key,
                    'username' => $username,
                    'password' => 'unused',
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        $result = $api->authenticate($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('api_key_user_mismatch', $result->get_error_code());
    }

    /**
     * @dataProvider provide_authenticated_rest_methods
     */
    public function test_authenticated_admin_request_sets_current_user_and_logs_history(string $auth_method): void
    {
        $api = new BJLG\BJLG_REST_API();
        $api->register_routes();

        $namespace = BJLG\BJLG_REST_API::API_NAMESPACE;
        $this->assertArrayHasKey($namespace, $GLOBALS['bjlg_registered_routes']);
        $this->assertArrayHasKey('/settings', $GLOBALS['bjlg_registered_routes'][$namespace]);

        $put_endpoint = null;

        foreach ($GLOBALS['bjlg_registered_routes'][$namespace]['/settings'] as $endpoint) {
            if (is_array($endpoint) && isset($endpoint['methods']) && stripos((string) $endpoint['methods'], 'PUT') !== false) {
                $put_endpoint = $endpoint;
                break;
            }
        }

        $this->assertIsArray($put_endpoint);
        $this->assertArrayHasKey('permission_callback', $put_endpoint);
        $this->assertArrayHasKey('callback', $put_endpoint);

        $admin = $this->makeUser(777, 'rest-admin');
        $GLOBALS['bjlg_test_users'] = [
            $admin->ID => $admin,
        ];

        $api_key = 'integration-api-key';
        update_option('bjlg_api_keys', []);

        $headers = [];

        if ($auth_method === 'api_key') {
            update_option('bjlg_api_keys', [
                [
                    'key' => wp_hash_password($api_key),
                    'user_id' => $admin->ID,
                    'roles' => $admin->roles,
                ],
            ]);
            $headers['X-API-Key'] = $api_key;
        } else {
            $token = $this->generateJwtToken([
                'user_id' => $admin->ID,
                'username' => $admin->user_login,
            ]);
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $payload = [
            'cleanup' => [
                'by_number' => 5,
                'by_age' => 30,
            ],
        ];

        $request = new class($headers, $payload) {
            /** @var array<string, string> */
            private $headers;

            /** @var array<string, mixed> */
            private $payload;

            /**
             * @param array<string, string> $headers
             * @param array<string, mixed>  $payload
             */
            public function __construct(array $headers, array $payload)
            {
                $this->headers = $headers;
                $this->payload = $payload;
            }

            public function get_header($key)
            {
                return $this->headers[$key] ?? null;
            }

            public function get_json_params()
            {
                return $this->payload;
            }
        };

        $GLOBALS['bjlg_history_entries'] = [];
        $previous_cap = $GLOBALS['bjlg_test_current_user_can'] ?? null;
        $GLOBALS['bjlg_test_current_user_can'] = false;

        $permissions = call_user_func($put_endpoint['permission_callback'], $request);
        $this->assertTrue($permissions);

        $response = call_user_func($put_endpoint['callback'], $request);

        if ($previous_cap !== null) {
            $GLOBALS['bjlg_test_current_user_can'] = $previous_cap;
        }

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertSame($payload['cleanup'], get_option('bjlg_cleanup_settings'));

        $this->assertNotEmpty($GLOBALS['bjlg_history_entries']);
        $last_entry = $GLOBALS['bjlg_history_entries'][count($GLOBALS['bjlg_history_entries']) - 1];
        $this->assertSame('settings_updated', $last_entry['action']);
        $this->assertSame('success', $last_entry['status']);
        $this->assertSame($admin->ID, $last_entry['user_id']);

        $current_user = wp_get_current_user();
        $this->assertIsObject($current_user);
        $this->assertSame($admin->ID, $current_user->ID);
        $this->assertSame($admin->ID, get_current_user_id());
    }

    /**
     * @return array<int, array{0: string}>
     */
    public function provide_authenticated_rest_methods(): array
    {
        return [
            ['api_key'],
            ['jwt'],
        ];
    }

    public function test_backup_endpoints_reject_symlink_outside_backup_directory(): void
    {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not supported in this environment.');
        }

        $api = new BJLG\BJLG_REST_API();

        $target = __DIR__ . '/../bjlg-outside-' . uniqid('', true) . '.zip';
        $symlinkName = 'bjlg-test-symlink-' . uniqid('', true) . '.zip';
        $symlinkPath = BJLG_BACKUP_DIR . $symlinkName;

        file_put_contents($target, 'outside');

        if (file_exists($symlinkPath) || is_link($symlinkPath)) {
            unlink($symlinkPath);
        }

        if (@symlink($target, $symlinkPath) === false) {
            unlink($target);
            $this->markTestSkipped('Unable to create symlink in backup directory.');
        }

        $request = new class($symlinkName) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct($id)
            {
                $this->params = [
                    'id' => $id,
                    'components' => ['all'],
                    'create_restore_point' => true,
                    'token' => null,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        try {
            foreach (['get_backup', 'delete_backup', 'download_backup', 'restore_backup'] as $method) {
                $result = $api->{$method}($request);
                $this->assertInstanceOf(WP_Error::class, $result, sprintf('Expected %s to return WP_Error.', $method));
                $this->assertSame('invalid_backup_id', $result->get_error_code(), sprintf('Expected %s to reject traversal attempts.', $method));
            }
        } finally {
            if (file_exists($symlinkPath) || is_link($symlinkPath)) {
                unlink($symlinkPath);
            }

            if (file_exists($target)) {
                unlink($target);
            }
        }
    }

    public function test_download_backup_accepts_ids_with_file_extensions(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $filename = 'bjlg-test-backup-' . uniqid('', true) . '.zip';
        $filepath = BJLG_BACKUP_DIR . $filename;

        file_put_contents($filepath, 'backup-data');

        $request = new class($filename) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct($id)
            {
                $this->params = [
                    'id' => $id,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        try {
            $response = $api->download_backup($request);

            $this->assertIsArray($response);
            $this->assertArrayHasKey('download_url', $response);
            $this->assertSame($filename, $response['filename']);
            $this->assertArrayHasKey('size', $response);
            $this->assertArrayHasKey('download_token', $response);
            $this->assertIsString($response['download_token']);
            $this->assertGreaterThan(0, $response['size']);
        } finally {
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }

    public function test_download_backup_supports_encrypted_archives(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $zip_path = $this->createBackupWithComponents(['db']);
        $encrypted_path = $zip_path . '.enc';
        $copied = copy($zip_path, $encrypted_path);
        $this->assertTrue($copied);

        $filename = basename($encrypted_path);
        $request_factory = function ($id, $token = null) {
            return new class($id, $token) {
                /** @var array<string, mixed> */
                private $params;

                public function __construct($id, $token)
                {
                    $this->params = [
                        'id' => $id,
                        'token' => $token,
                    ];
                }

                public function get_param($key)
                {
                    return $this->params[$key] ?? null;
                }
            };
        };

        try {
            $initial_response = $api->download_backup($request_factory($filename));

            $this->assertIsArray($initial_response);
            $this->assertSame($filename, $initial_response['filename']);
            $this->assertStringEndsWith('.zip.enc', (string) $initial_response['filename']);
            $this->assertArrayHasKey('download_url', $initial_response);
            $this->assertArrayHasKey('download_token', $initial_response);
            $this->assertArrayHasKey('size', $initial_response);

            $this->assertSame(filesize($encrypted_path), $initial_response['size']);

            $token = (string) $initial_response['download_token'];
            $this->assertNotSame('', $token);

            $parsed_url = parse_url((string) $initial_response['download_url']);
            $this->assertIsArray($parsed_url);

            $query_args = [];

            if (!empty($parsed_url['query'])) {
                parse_str((string) $parsed_url['query'], $query_args);
            }

            $this->assertSame($token, $query_args['token'] ?? null);
            $this->assertArrayHasKey('bjlg_download_' . $token, $GLOBALS['bjlg_test_transients']);
            $stored_payload = $GLOBALS['bjlg_test_transients']['bjlg_download_' . $token] ?? null;
            $this->assertIsArray($stored_payload);
            $this->assertSame($encrypted_path, $stored_payload['file'] ?? null);

            $refreshed_response = $api->download_backup($request_factory($filename, $token));

            $this->assertIsArray($refreshed_response);
            $this->assertSame($token, $refreshed_response['download_token']);
            $this->assertSame(filesize($encrypted_path), $refreshed_response['size']);
            $this->assertArrayHasKey('bjlg_download_' . $token, $GLOBALS['bjlg_test_transients']);
        } finally {
            if (file_exists($encrypted_path)) {
                unlink($encrypted_path);
            }

            if (file_exists($zip_path)) {
                unlink($zip_path);
            }

            $GLOBALS['bjlg_test_transients'] = [];
        }
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    public function provide_download_sizes(): array
    {
        return [
            'zero_byte_archive' => [0, 600, 3600, 600],
            'one_kib_archive' => [1024, 3600, 1200, 1200],
            'five_mib_archive' => [5 * 1024 * 1024, 7200, 7200, 7200],
        ];
    }

    /**
     * @dataProvider provide_download_sizes
     */
    public function test_download_backup_reports_exact_size_for_varied_archives(
        int $size,
        int $download_ttl,
        int $task_ttl,
        int $expected_ttl
    ): void {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $filepath = BJLG_BACKUP_DIR . uniqid('', true) . '.zip';
        $filename = basename($filepath);

        $handle = fopen($filepath, 'wb');
        $this->assertNotFalse($handle, 'Failed to create backup file handle.');

        $truncated = ftruncate($handle, $size);
        $this->assertTrue($truncated, 'Unable to size backup file.');
        fclose($handle);

        clearstatcache(false, $filepath);
        $this->assertSame($size, filesize($filepath));

        $previous_download_filters = $GLOBALS['bjlg_test_hooks']['filters']['bjlg_download_token_ttl'] ?? null;
        $previous_task_filters = $GLOBALS['bjlg_test_hooks']['filters']['bjlg_task_ttl'] ?? null;

        add_filter('bjlg_download_token_ttl', static function ($value) use ($download_ttl) {
            return $download_ttl;
        }, 10, 2);

        add_filter('bjlg_task_ttl', static function ($value) use ($task_ttl) {
            return $task_ttl;
        }, 10, 1);

        $request = new class($filename) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct($id)
            {
                $this->params = [
                    'id' => $id,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        try {
            $response = $api->download_backup($request);

            $this->assertIsArray($response);
            $this->assertArrayHasKey('download_url', $response);
            $this->assertIsString($response['download_url']);
            $this->assertNotEmpty($response['download_url']);
            $this->assertSame($filename, $response['filename']);
            $this->assertArrayHasKey('download_token', $response);
            $this->assertIsString($response['download_token']);
            $this->assertNotEmpty($response['download_token']);
            $this->assertArrayHasKey('expires_in', $response);
            $this->assertIsInt($response['expires_in']);
            $this->assertSame($expected_ttl, $response['expires_in']);
            $this->assertArrayHasKey('size', $response);
            $this->assertIsInt($response['size']);
            $this->assertSame($size, $response['size']);
            $this->assertSame(
                \BJLG\BJLG_Actions::build_download_url($response['download_token']),
                $response['download_url']
            );

            $this->assertCount(1, $GLOBALS['bjlg_test_transients']);

            $transient_key = 'bjlg_download_' . $response['download_token'];
            $this->assertArrayHasKey($transient_key, $GLOBALS['bjlg_test_transients']);

            $stored_payload = $GLOBALS['bjlg_test_transients'][$transient_key];
            $this->assertIsArray($stored_payload);
            $this->assertArrayHasKey('file', $stored_payload);
            $this->assertSame($filepath, $stored_payload['file']);
            $this->assertArrayHasKey('requires_cap', $stored_payload);
            $this->assertSame(BJLG_CAPABILITY, $stored_payload['requires_cap']);
            $this->assertArrayHasKey('issued_at', $stored_payload);
            $this->assertIsInt($stored_payload['issued_at']);
            $this->assertArrayHasKey('issued_by', $stored_payload);
            $this->assertSame(get_current_user_id(), $stored_payload['issued_by']);
        } finally {
            if (file_exists($filepath)) {
                unlink($filepath);
            }

            if ($previous_download_filters !== null) {
                $GLOBALS['bjlg_test_hooks']['filters']['bjlg_download_token_ttl'] = $previous_download_filters;
            } else {
                unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_download_token_ttl']);
            }

            if ($previous_task_filters !== null) {
                $GLOBALS['bjlg_test_hooks']['filters']['bjlg_task_ttl'] = $previous_task_filters;
            } else {
                unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_task_ttl']);
            }

            $GLOBALS['bjlg_test_transients'] = [];
        }
    }

    public function test_download_backup_returns_error_when_filesize_unavailable(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $filepath = BJLG_BACKUP_DIR . uniqid('', true) . '.zip';
        $filename = basename($filepath);

        $bytes_written = file_put_contents($filepath, 'backup-data');
        $this->assertNotFalse($bytes_written, 'Failed to write backup file.');

        $GLOBALS['bjlg_test_filesize_callback'] = static function ($candidate) use ($filepath) {
            $GLOBALS['bjlg_test_filesize_calls'][] = $candidate;

            if ($candidate === $filepath) {
                return false;
            }

            return \filesize($candidate);
        };

        $request = new class($filename) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct($id)
            {
                $this->params = [
                    'id' => $id,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        try {
            $response = $api->download_backup($request);

            $this->assertInstanceOf(\WP_Error::class, $response);
            $this->assertSame('bjlg_backup_size_unavailable', $response->get_error_code());

            $data = $response->get_error_data();
            $this->assertIsArray($data);
            $this->assertSame(500, $data['status']);

            $this->assertNotEmpty(\BJLG\BJLG_Debug::$logs);
            $this->assertStringContainsString($filepath, \BJLG\BJLG_Debug::$logs[0]);
        } finally {
            unset($GLOBALS['bjlg_test_filesize_callback']);

            if (file_exists($filepath)) {
                unlink($filepath);
            }

            $GLOBALS['bjlg_test_transients'] = [];
        }
    }

    public function test_download_backup_returns_error_when_transient_persistence_fails(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $filename = 'bjlg-test-backup-' . uniqid('', true) . '.zip';
        $filepath = BJLG_BACKUP_DIR . $filename;

        file_put_contents($filepath, 'backup-data');

        $GLOBALS['bjlg_test_set_transient_mock'] = static function (string $transient, $value = null, $expiration = null) {
            if (strpos($transient, 'bjlg_download_') === 0) {
                return false;
            }

            return null;
        };

        $request = new class($filename) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct($id)
            {
                $this->params = [
                    'id' => $id,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        try {
            $response = $api->download_backup($request);

            $this->assertInstanceOf(\WP_Error::class, $response);
            $this->assertSame('bjlg_download_token_failure', $response->get_error_code());

            $error_data = $response->get_error_data();
            $this->assertIsArray($error_data);
            $this->assertSame(500, $error_data['status']);

            $this->assertEmpty($GLOBALS['bjlg_test_transients']);
            $this->assertNotEmpty(\BJLG\BJLG_Debug::$logs);
        } finally {
            $GLOBALS['bjlg_test_set_transient_mock'] = null;

            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }

    public function test_download_backup_returns_error_when_refreshing_existing_token_fails(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $filename = 'bjlg-test-backup-' . uniqid('', true) . '.zip';
        $filepath = BJLG_BACKUP_DIR . $filename;

        file_put_contents($filepath, 'backup-data');

        $token = 'bjlg-test-token-' . uniqid('', true);
        $payload = BJLG\BJLG_Actions::build_download_token_payload($filepath);
        $GLOBALS['bjlg_test_transients']['bjlg_download_' . $token] = $payload;

        $GLOBALS['bjlg_test_set_transient_mock'] = static function (string $transient, $value = null, $expiration = null) {
            if (strpos($transient, 'bjlg_download_') === 0) {
                return false;
            }

            return null;
        };

        $request = new class($filename, $token) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct($id, $token)
            {
                $this->params = [
                    'id' => $id,
                    'token' => $token,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        try {
            $response = $api->download_backup($request);

            $this->assertInstanceOf(\WP_Error::class, $response);
            $this->assertSame('bjlg_download_token_failure', $response->get_error_code());

            $error_data = $response->get_error_data();
            $this->assertIsArray($error_data);
            $this->assertSame(500, $error_data['status']);

            $this->assertArrayHasKey('bjlg_download_' . $token, $GLOBALS['bjlg_test_transients']);
            $this->assertNotEmpty(\BJLG\BJLG_Debug::$logs);
        } finally {
            $GLOBALS['bjlg_test_set_transient_mock'] = null;

            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }

    public function test_download_backup_reuses_valid_token(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $filename = 'bjlg-test-backup-' . uniqid('', true) . '.zip';
        $filepath = BJLG_BACKUP_DIR . $filename;

        file_put_contents($filepath, 'backup-data');

        $request_factory = function ($id, $token = null) {
            return new class($id, $token) {
                /** @var array<string, mixed> */
                private $params;

                public function __construct($id, $token)
                {
                    $this->params = [
                        'id' => $id,
                        'token' => $token,
                    ];
                }

                public function get_param($key)
                {
                    return $this->params[$key] ?? null;
                }
            };
        };

        try {
            $first_response = $api->download_backup($request_factory($filename));

            $this->assertIsArray($first_response);
            $this->assertArrayHasKey('download_token', $first_response);

            $token = (string) $first_response['download_token'];

            $second_response = $api->download_backup($request_factory($filename, $token));

            $this->assertIsArray($second_response);
            $this->assertArrayHasKey('download_token', $second_response);
            $this->assertSame($token, $second_response['download_token']);

            $parsed_url = parse_url($second_response['download_url']);
            $query_args = [];

            if (!empty($parsed_url['query'])) {
                parse_str((string) $parsed_url['query'], $query_args);
            }

            $this->assertSame($token, $query_args['token'] ?? null);
            $this->assertCount(1, $GLOBALS['bjlg_test_transients']);
            $stored_payload = $GLOBALS['bjlg_test_transients']['bjlg_download_' . $token] ?? null;
            $this->assertIsArray($stored_payload);
            $this->assertSame($filepath, $stored_payload['file'] ?? null);
            $this->assertSame(BJLG_CAPABILITY, $stored_payload['requires_cap'] ?? null);
            $this->assertArrayHasKey('issued_at', $stored_payload);
            $this->assertArrayHasKey('issued_by', $stored_payload);
            $this->assertSame(0, $stored_payload['issued_by']);
        } finally {
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }

    public function test_download_backup_url_can_be_used_without_session(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_current_user_can'] = true;
        $_REQUEST = [];
        $_GET = [];
        $_POST = [];

        $api = new BJLG\BJLG_REST_API();
        $actions = new BJLG\BJLG_Actions();

        $user = $this->makeUser(777, 'rest-download-user');
        $GLOBALS['bjlg_test_users'] = [
            $user->ID => $user,
        ];
        $GLOBALS['current_user'] = $user;
        $GLOBALS['current_user_id'] = $user->ID;

        $backup = $this->createBackupWithComponents(['db']);
        $filename = basename($backup);

        $request = new class($filename) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct($id)
            {
                $this->params = [
                    'id' => $id,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        try {
            $response = $api->download_backup($request);

            $this->assertIsArray($response);
            $this->assertArrayHasKey('download_url', $response);
            $this->assertArrayHasKey('download_token', $response);

            $download_url = (string) $response['download_url'];
            $parsed_url = parse_url($download_url);
            $this->assertIsArray($parsed_url);
            $this->assertSame('/wp-admin/admin-ajax.php', $parsed_url['path'] ?? null);

            $query_args = [];
            if (!empty($parsed_url['query'])) {
                parse_str((string) $parsed_url['query'], $query_args);
            }

            $token = (string) $response['download_token'];

            $this->assertSame('bjlg_download', $query_args['action'] ?? null);
            $this->assertSame($token, $query_args['token'] ?? null);
            $this->assertArrayHasKey('bjlg_download_' . $token, $GLOBALS['bjlg_test_transients']);
            $this->assertSame(10, has_action('wp_ajax_nopriv_bjlg_download', [$actions, 'handle_download_request']));

            // Simulate an unauthenticated request consuming the download URL.
            $GLOBALS['current_user'] = null;
            $GLOBALS['current_user_id'] = 0;
            $GLOBALS['bjlg_test_current_user_can'] = false;

            $captured_paths = [];
            add_filter('bjlg_pre_stream_backup', function ($value, $filepath) use (&$captured_paths) {
                $captured_paths[] = $filepath;

                return true;
            }, 10, 2);

            $_REQUEST['token'] = $token;
            $_GET['token'] = $token;

            do_action('wp_ajax_nopriv_bjlg_download');

            $this->assertNotEmpty($captured_paths);
            $this->assertSame(realpath($backup), realpath((string) $captured_paths[0]));
            $this->assertSame($user->ID, get_current_user_id());
            $this->assertArrayNotHasKey('bjlg_download_' . $token, $GLOBALS['bjlg_test_transients']);
        } finally {
            unset($GLOBALS['bjlg_test_hooks']['filters']['bjlg_pre_stream_backup']);
            $_REQUEST = [];
            $_GET = [];
            $_POST = [];
            $GLOBALS['bjlg_test_current_user_can'] = true;
            $GLOBALS['current_user'] = null;
            $GLOBALS['current_user_id'] = 0;
            $this->deleteBackupIfExists($backup);
        }
    }

    public function test_download_backup_rejects_unknown_token(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $filename = 'bjlg-test-backup-' . uniqid('', true) . '.zip';
        $filepath = BJLG_BACKUP_DIR . $filename;

        file_put_contents($filepath, 'backup-data');

        $request = new class($filename) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct($id)
            {
                $this->params = [
                    'id' => $id,
                    'token' => 'invalid-token',
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        try {
            $response = $api->download_backup($request);

            $this->assertInstanceOf(\WP_Error::class, $response);
            $this->assertSame('bjlg_invalid_token', $response->get_error_code());
            $data = $response->get_error_data();
            $this->assertIsArray($data);
            $this->assertSame(404, $data['status'] ?? null);
        } finally {
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }

    public function test_download_backup_rejects_token_for_other_backup(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $filename = 'bjlg-test-backup-' . uniqid('', true) . '.zip';
        $filepath = BJLG_BACKUP_DIR . $filename;
        $other_file = BJLG_BACKUP_DIR . 'bjlg-test-backup-' . uniqid('', true) . '.zip';

        file_put_contents($filepath, 'primary-backup');
        file_put_contents($other_file, 'other-backup');

        $token = 'existing-token';
        set_transient('bjlg_download_' . $token, $other_file, DAY_IN_SECONDS);

        $request = new class($filename, $token) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct($id, $token)
            {
                $this->params = [
                    'id' => $id,
                    'token' => $token,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        try {
            $response = $api->download_backup($request);

            $this->assertInstanceOf(\WP_Error::class, $response);
            $this->assertSame('bjlg_invalid_token', $response->get_error_code());
            $data = $response->get_error_data();
            $this->assertIsArray($data);
            $this->assertSame(403, $data['status'] ?? null);
        } finally {
            if (file_exists($filepath)) {
                unlink($filepath);
            }

            if (file_exists($other_file)) {
                unlink($other_file);
            }
        }
    }

    public function test_restore_endpoint_creates_pre_backup_for_database_only_request(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_history_entries'] = [];

        $previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            /** @var array<int, string> */
            public $queries = [];

            /** @var string */
            public $last_error = '';

            /** @var string */
            public $options = 'wp_options';

            /**
             * @param string $query
             * @return int
             */
            public function query($query)
            {
                $this->queries[] = (string) $query;
                $this->last_error = '';

                return 1;
            }
        };

        $api = new BJLG\BJLG_REST_API();

        $archive_path = BJLG_BACKUP_DIR . 'bjlg-rest-restore-' . uniqid('', true) . '.zip';

        $zip = new \ZipArchive();
        $open_result = $zip->open($archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $this->assertTrue($open_result === true || $open_result === \ZipArchive::ER_OK);

        $manifest = [
            'type' => 'full',
            'contains' => ['db', 'plugins'],
        ];

        $zip->addFromString('backup-manifest.json', json_encode($manifest));
        $zip->addFromString('database.sql', "CREATE TABLE `wp_test` (id INT);\n");
        $zip->addFromString('wp-content/plugins/sample/plugin.php', '<?php echo "sample";');
        $zip->close();

        $plugin_destination = WP_PLUGIN_DIR . '/sample/plugin.php';
        if (file_exists($plugin_destination)) {
            unlink($plugin_destination);
        }

        $request = new class($archive_path) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct(string $archive_path)
            {
                $this->params = [
                    'id' => basename($archive_path),
                    'components' => ['db'],
                    'create_restore_point' => true,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        $response = $api->restore_backup($request);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('task_id', $response);

        $task_id = $response['task_id'];
        $task_data = get_transient($task_id);

        $this->assertIsArray($task_data);
        $this->assertSame(['db'], $task_data['components']);
        $this->assertTrue($task_data['create_restore_point']);

        try {
            $restore = new BJLG_Test_Restore_For_Rest();
            $restore->run_restore_task($task_id);

            $final_status = get_transient($task_id);
            $this->assertIsArray($final_status);
            $this->assertSame('complete', $final_status['status']);
            $this->assertSame(100, $final_status['progress']);

            $this->assertSame(1, $restore->pre_backup_calls);
            $this->assertNotEmpty($GLOBALS['wpdb']->queries);
            $this->assertFalse(file_exists($plugin_destination));
        } finally {
            if ($previous_wpdb === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $previous_wpdb;
            }

            if (file_exists($archive_path)) {
                unlink($archive_path);
            }

            $plugin_directory = dirname($plugin_destination);
            if (is_dir($plugin_directory) && count(scandir($plugin_directory)) <= 2) {
                rmdir($plugin_directory);
            }
        }
    }

    public function test_restore_endpoint_restores_encrypted_archive_with_password(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_options']['bjlg_encryption_settings'] = ['enabled' => true];

        $api = new BJLG\BJLG_REST_API();

        $archive_path = BJLG_BACKUP_DIR . 'bjlg-rest-restore-' . uniqid('', true) . '.zip';

        $zip = new \ZipArchive();
        $open_result = $zip->open($archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $this->assertTrue($open_result === true || $open_result === \ZipArchive::ER_OK);

        $manifest = [
            'type' => 'full',
            'contains' => ['db'],
        ];

        $zip->addFromString('backup-manifest.json', json_encode($manifest));
        $zip->addFromString('database.sql', "CREATE TABLE `wp_test` (id INT);\n");
        $zip->close();

        $password = 'super-secret';

        $encryption = new BJLG\BJLG_Encryption();
        $encrypted_path = $encryption->encrypt_backup_file($archive_path, $password);

        $this->assertSame($archive_path . '.enc', $encrypted_path);
        $this->assertFileExists($encrypted_path);
        $this->assertFileDoesNotExist($archive_path);

        $request = new class($encrypted_path, $password) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct(string $encrypted_path, string $password)
            {
                $this->params = [
                    'id' => basename($encrypted_path),
                    'components' => ['db'],
                    'create_restore_point' => false,
                    'password' => $password,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        try {
            $response = $api->restore_backup($request);

            $this->assertIsArray($response);
            $this->assertArrayHasKey('task_id', $response);

            $task_id = $response['task_id'];
            $task_data = get_transient($task_id);

            $this->assertIsArray($task_data);
            $this->assertArrayHasKey('password_encrypted', $task_data);
            $this->assertNotEmpty($task_data['password_encrypted']);

            $restore = new BJLG_Test_Restore_For_Rest();

            $reflection = new \ReflectionClass(BJLG\BJLG_Restore::class);
            $decrypt_method = $reflection->getMethod('decrypt_password_from_transient');
            $decrypt_method->setAccessible(true);

            $decrypted_password = $decrypt_method->invoke($restore, $task_data['password_encrypted']);
            $this->assertSame($password, $decrypted_password);

            $restore->run_restore_task($task_id);

            $final_status = get_transient($task_id);
            $this->assertIsArray($final_status);
            $this->assertSame('complete', $final_status['status']);
            $this->assertSame(100, $final_status['progress']);
        } finally {
            unset($GLOBALS['bjlg_test_options']['bjlg_encryption_settings']);

            if (file_exists($encrypted_path)) {
                unlink($encrypted_path);
            }
        }
    }

    public function test_restore_endpoint_returns_error_when_transient_initialization_fails(): void
    {
        $api = new BJLG\BJLG_REST_API();

        $archive_path = $this->createBackupWithComponents(['db']);

        $request = new class($archive_path) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct(string $archive_path)
            {
                $this->params = [
                    'id' => basename($archive_path),
                    'components' => ['db'],
                    'create_restore_point' => false,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        $GLOBALS['bjlg_test_set_transient_mock'] = static function (string $transient, $value = null, $expiration = null) {
            if (strpos($transient, 'bjlg_restore_') === 0) {
                return false;
            }

            return null;
        };

        $response = $api->restore_backup($request);

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('rest_restore_initialization_failed', $response->get_error_code());

        $error_data = $response->get_error_data();
        $this->assertIsArray($error_data);
        $this->assertSame(500, $error_data['status']);

        $this->assertEmpty($GLOBALS['bjlg_test_transients']);
        $this->assertArrayHasKey('single', $GLOBALS['bjlg_test_scheduled_events']);
        $this->assertEmpty($GLOBALS['bjlg_test_scheduled_events']['single']);

        $this->deleteBackupIfExists($archive_path);
    }

    public function test_restore_endpoint_cleans_up_when_scheduling_fails(): void
    {
        $api = new BJLG\BJLG_REST_API();

        $archive_path = $this->createBackupWithComponents(['db']);

        $request = new class($archive_path) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct(string $archive_path)
            {
                $this->params = [
                    'id' => basename($archive_path),
                    'components' => ['db'],
                    'create_restore_point' => false,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        $captured_task_id = null;

        $GLOBALS['bjlg_test_set_transient_mock'] = static function (string $transient, $value = null, $expiration = null) use (&$captured_task_id) {
            if (strpos($transient, 'bjlg_restore_') === 0) {
                $captured_task_id = $transient;
            }

            return null;
        };

        $GLOBALS['bjlg_test_schedule_single_event_mock'] = static function ($timestamp, $hook, $args = []) {
            if ($hook === 'bjlg_run_restore_task') {
                return false;
            }

            return null;
        };

        $response = $api->restore_backup($request);

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('rest_restore_schedule_failed', $response->get_error_code());
        $this->assertNotNull($captured_task_id);

        $error_data = $response->get_error_data();
        $this->assertIsArray($error_data);
        $this->assertSame(500, $error_data['status']);

        $this->assertArrayNotHasKey($captured_task_id, $GLOBALS['bjlg_test_transients']);
        $this->assertArrayHasKey('single', $GLOBALS['bjlg_test_scheduled_events']);
        $this->assertEmpty($GLOBALS['bjlg_test_scheduled_events']['single']);

        $this->deleteBackupIfExists($archive_path);
    }

    public function test_restore_endpoint_cleans_up_when_scheduling_returns_wp_error(): void
    {
        $api = new BJLG\BJLG_REST_API();

        $archive_path = $this->createBackupWithComponents(['db']);

        $request = new class($archive_path) {
            /** @var array<string, mixed> */
            private $params;

            public function __construct(string $archive_path)
            {
                $this->params = [
                    'id' => basename($archive_path),
                    'components' => ['db'],
                    'create_restore_point' => false,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        $captured_task_id = null;

        $GLOBALS['bjlg_test_set_transient_mock'] = static function (string $transient, $value = null, $expiration = null) use (&$captured_task_id) {
            if (strpos($transient, 'bjlg_restore_') === 0) {
                $captured_task_id = $transient;
            }

            return null;
        };

        $GLOBALS['bjlg_test_schedule_single_event_mock'] = static function ($timestamp, $hook, $args = []) {
            if ($hook === 'bjlg_run_restore_task') {
                return new WP_Error('cron_failure', 'Unexpected cron failure (REST)');
            }

            return null;
        };

        $response = $api->restore_backup($request);

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('rest_restore_schedule_failed', $response->get_error_code());
        $this->assertNotNull($captured_task_id);

        $error_data = $response->get_error_data();
        $this->assertIsArray($error_data);
        $this->assertSame(500, $error_data['status']);
        $this->assertArrayHasKey('details', $error_data);
        $this->assertSame('Unexpected cron failure (REST)', $error_data['details']);

        $this->assertArrayNotHasKey($captured_task_id, $GLOBALS['bjlg_test_transients']);
        $this->assertArrayHasKey('single', $GLOBALS['bjlg_test_scheduled_events']);
        $this->assertEmpty($GLOBALS['bjlg_test_scheduled_events']['single']);

        $this->deleteBackupIfExists($archive_path);
    }

    public function test_get_task_status_rejects_invalid_identifier(): void
    {
        $api = new BJLG\BJLG_REST_API();

        $request = new class {
            /** @var array<string, mixed> */
            private $params;

            public function __construct()
            {
                $this->params = [
                    'id' => 'bjlg_invalid_123',
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        $response = $api->get_task_status($request);

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('invalid_task_id', $response->get_error_code());

        $error_data = $response->get_error_data();
        $this->assertIsArray($error_data);
        $this->assertSame(400, $error_data['status']);
    }

    public function test_handle_save_settings_accepts_checkbox_variants(): void
    {
        $settings = new BJLG\BJLG_Settings();

        $previous_post = $_POST ?? [];

        $_POST = [
            'nonce' => 'test-nonce',
            'plugin_name' => 'Test Plugin',
            'hide_from_non_admins' => 'on',
            'encryption_enabled' => '1',
            'auto_encrypt' => 'true',
            'password_protect' => 'yes',
            'compression_level' => '8',
            'gdrive_client_id' => 'client-id',
            'gdrive_client_secret' => 'client-secret',
            'gdrive_folder_id' => 'folder',
            'gdrive_enabled' => 1,
            'notifications_enabled' => 'on',
            'email_recipients' => 'admin@example.com',
            'notify_backup_complete' => '1',
            'notify_backup_failed' => '0',
            'notify_cleanup_complete' => 'on',
            'notify_storage_warning' => 'false',
            'channel_email' => 'on',
            'channel_slack' => '1',
            'slack_webhook_url' => 'https://example.com/slack',
            'channel_discord' => 'no',
            'discord_webhook_url' => 'https://example.com/discord',
            'multi_threading' => 'yes',
            'max_workers' => '4',
            'chunk_size' => '25',
            'webhook_enabled' => true,
            'webhook_backup_complete' => 'https://example.com/hook-complete',
            'webhook_backup_failed' => 'https://example.com/hook-failed',
            'webhook_cleanup_complete' => 'https://example.com/hook-cleanup',
            'webhook_secret' => 'secret-value',
            'ajax_debug_enabled' => '1',
        ];

        try {
            $settings->handle_save_settings();
            $this->fail('Expected JSON response.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
        } finally {
            $_POST = $previous_post;
        }

        $whitelabel = get_option('bjlg_whitelabel_settings');
        $this->assertIsArray($whitelabel);
        $this->assertTrue($whitelabel['hide_from_non_admins']);

        $encryption = get_option('bjlg_encryption_settings');
        $this->assertTrue($encryption['enabled']);
        $this->assertTrue($encryption['auto_encrypt']);
        $this->assertTrue($encryption['password_protect']);

        $notifications = get_option('bjlg_notification_settings');
        $this->assertTrue($notifications['enabled']);
        $this->assertTrue($notifications['events']['backup_complete']);
        $this->assertFalse($notifications['events']['backup_failed']);
        $this->assertTrue($notifications['events']['cleanup_complete']);
        $this->assertFalse($notifications['events']['storage_warning']);
        $this->assertTrue($notifications['channels']['email']['enabled']);
        $this->assertTrue($notifications['channels']['slack']['enabled']);
        $this->assertFalse($notifications['channels']['discord']['enabled']);

        $performance = get_option('bjlg_performance_settings');
        $this->assertTrue($performance['multi_threading']);

        $gdrive = get_option('bjlg_gdrive_settings');
        $this->assertTrue($gdrive['enabled']);

        $webhooks = get_option('bjlg_webhook_settings');
        $this->assertTrue($webhooks['enabled']);

        $this->assertTrue(get_option('bjlg_ajax_debug_enabled'));
    }

    public function test_handle_save_settings_returns_success_payload(): void
    {
        $settings = new BJLG\BJLG_Settings();

        $previous_post = $_POST ?? [];

        $_POST = [
            'nonce' => 'test-nonce',
            'by_number' => '5',
            'by_age' => '9',
        ];

        try {
            $settings->handle_save_settings();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('message', $response->data);
            $this->assertSame('JSON response', $response->data['message']);
        } finally {
            $_POST = $previous_post;
        }

        $this->assertSame([
            'by_number' => 5,
            'by_age' => 9,
        ], get_option('bjlg_cleanup_settings'));
    }

        public function test_update_settings_rejects_empty_payload(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $original_cleanup = ['by_number' => 3, 'by_age' => 1];
            $GLOBALS['bjlg_test_options']['bjlg_cleanup_settings'] = $original_cleanup;

            $request = new class {
                public function get_json_params()
                {
                    return [];
                }
            };

            $result = $api->update_settings($request);

            $this->assertInstanceOf(WP_Error::class, $result);
            $this->assertSame('invalid_payload', $result->get_error_code());
            $this->assertSame($original_cleanup, get_option('bjlg_cleanup_settings'));
        }

        public function test_update_settings_rejects_invalid_cleanup_structure(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $original_cleanup = ['by_number' => 5, 'by_age' => 2];
            $GLOBALS['bjlg_test_options']['bjlg_cleanup_settings'] = $original_cleanup;

            $request = new class {
                public function get_json_params()
                {
                    return [
                        'cleanup' => ['by_number' => 'not-an-int'],
                    ];
                }
            };

            $result = $api->update_settings($request);

            $this->assertInstanceOf(WP_Error::class, $result);
            $this->assertSame('invalid_cleanup_settings', $result->get_error_code());
            $this->assertSame($original_cleanup, get_option('bjlg_cleanup_settings'));
        }

        public function test_update_settings_sanitizes_payload_before_saving(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $payload = [
                'cleanup' => ['by_number' => '15', 'by_age' => '-20'],
                'schedule' => [
                    'recurrence' => 'WEEKLY',
                    'day' => 'Friday',
                    'time' => ' 05:30 ',
                    'components' => ['db', 'plugins', 'plugins', 'weird<script>'],
                    'encrypt' => '1',
                    'incremental' => 'false',
                ],
                'encryption' => [
                    'enabled' => 'true',
                    'auto_encrypt' => 'yes',
                    'password_protect' => 'no',
                    'compression_level' => '15',
                ],
                'notifications' => [
                    'enabled' => 'yes',
                    'email_recipients' => '   admin@example.com , invalid-email ',
                    'events' => [
                        'backup_complete' => 'TRUE',
                        'backup_failed' => '0',
                        'cleanup_complete' => 'Y',
                        'storage_warning' => 'no',
                    ],
                    'channels' => [
                        'email' => ['enabled' => '1'],
                        'slack' => ['enabled' => 'yes', 'webhook_url' => 'https://example.com/slack<script>'],
                        'discord' => ['enabled' => '', 'webhook_url' => 'ftp://discord.example.com/hook'],
                    ],
                ],
                'performance' => [
                    'multi_threading' => 'on',
                    'max_workers' => '0',
                    'chunk_size' => '-10',
                    'compression_level' => '9',
                ],
                'webhooks' => [
                    'enabled' => '1',
                    'urls' => [
                        'backup_complete' => ' https://example.com/hook?foo=bar ',
                        'backup_failed' => 'not-a-url',
                        'cleanup_complete' => 'https://example.com/cleanup',
                    ],
                    'secret' => '  secret  ',
                ],
            ];

            $request = new class($payload) {
                /** @var array<string, mixed> */
                private $payload;

                /**
                 * @param array<string, mixed> $payload
                 */
                public function __construct(array $payload)
                {
                    $this->payload = $payload;
                }

                public function get_json_params()
                {
                    return $this->payload;
                }
            };

            $response = $api->update_settings($request);

            $this->assertIsArray($response);
            $this->assertSame([
                'by_number' => 15,
                'by_age' => 0,
            ], get_option('bjlg_cleanup_settings'));

            $this->assertSame([
                'recurrence' => 'weekly',
                'day' => 'friday',
                'time' => '05:30',
                'components' => ['db', 'plugins'],
                'encrypt' => true,
                'incremental' => false,
            ], get_option('bjlg_schedule_settings'));

            $this->assertSame([
                'enabled' => true,
                'auto_encrypt' => true,
                'password_protect' => false,
                'compression_level' => 15,
            ], get_option('bjlg_encryption_settings'));

            $this->assertSame([
                'enabled' => true,
                'email_recipients' => 'admin@example.com , invalid-email',
                'events' => [
                    'backup_complete' => true,
                    'backup_failed' => false,
                    'cleanup_complete' => false,
                    'storage_warning' => false,
                ],
                'channels' => [
                    'email' => ['enabled' => true],
                    'slack' => [
                        'enabled' => true,
                        'webhook_url' => 'https://example.com/slack%3Cscript%3E',
                    ],
                    'discord' => [
                        'enabled' => false,
                        'webhook_url' => 'ftp://discord.example.com/hook',
                    ],
                ],
            ], get_option('bjlg_notification_settings'));

            $this->assertSame([
                'multi_threading' => true,
                'max_workers' => 1,
                'chunk_size' => 1,
                'compression_level' => 9,
            ], get_option('bjlg_performance_settings'));

            $this->assertSame([
                'enabled' => true,
                'urls' => [
                    'backup_complete' => 'https://example.com/hook?foo=bar',
                    'backup_failed' => '',
                    'cleanup_complete' => 'https://example.com/cleanup',
                ],
                'secret' => 'secret',
            ], get_option('bjlg_webhook_settings'));
        }

        public function test_create_schedule_rejects_incomplete_payload(): void
        {
            $api = new BJLG\BJLG_REST_API();

            $original_schedule = [
                'recurrence' => 'weekly',
                'day' => 'monday',
                'time' => '01:00',
                'components' => ['db', 'plugins'],
                'encrypt' => false,
                'incremental' => false,
            ];
            $GLOBALS['bjlg_test_options']['bjlg_schedule_settings'] = $original_schedule;

            $request = new class {
                public function get_json_params()
                {
                    return [
                        'recurrence' => 'weekly',
                        'time' => '01:00',
                    ];
                }
            };

            $result = $api->create_schedule($request);

            $this->assertInstanceOf(WP_Error::class, $result);
            $this->assertSame('invalid_schedule_settings', $result->get_error_code());
            $this->assertSame($original_schedule, get_option('bjlg_schedule_settings'));
        }

    public function test_create_backup_stores_incremental_flag_from_rest_request(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_scheduled_events'] = [];

        $api = new BJLG\BJLG_REST_API();

        $request = new class {
            /** @var array<string, mixed> */
            private $params;

            public function __construct()
            {
                $this->params = [
                    'components' => ['db', 'plugins'],
                    'type' => 'incremental',
                    'encrypt' => 'false',
                    'description' => 'Incremental backup via REST',
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        $response = $api->create_backup($request);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('task_id', $response);

        $task_id = $response['task_id'];

        $this->assertArrayHasKey($task_id, $GLOBALS['bjlg_test_transients']);
        $task_data = $GLOBALS['bjlg_test_transients'][$task_id];

        $this->assertArrayHasKey('incremental', $task_data);
        $this->assertTrue($task_data['incremental']);
        $this->assertArrayHasKey('encrypt', $task_data);
        $this->assertFalse($task_data['encrypt']);
    }

    public function test_create_backup_sanitizes_description_for_progress_check(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_scheduled_events'] = [];

        $api = new BJLG\BJLG_REST_API();

        $html_description = '<strong>Backup</strong> via <em>REST</em> <script>alert(1);</script> ' . str_repeat('x', 400);

        $request = new class($html_description) {
            /** @var array<string, mixed> */
            private $params;

            /**
             * @param string $description
             */
            public function __construct($description)
            {
                $this->params = [
                    'components' => ['db'],
                    'type' => 'full',
                    'encrypt' => 'false',
                    'description' => $description,
                ];
            }

            public function get_param($key)
            {
                return $this->params[$key] ?? null;
            }
        };

        $response = $api->create_backup($request);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('task_id', $response);

        $task_id = $response['task_id'];

        $this->assertArrayHasKey($task_id, $GLOBALS['bjlg_test_transients']);
        $task_data = $GLOBALS['bjlg_test_transients'][$task_id];

        $expected_description = sanitize_text_field($html_description);
        $max_description_length = 255;

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($expected_description, 'UTF-8') > $max_description_length) {
                $expected_description = mb_substr($expected_description, 0, $max_description_length, 'UTF-8');
            }
        } elseif (strlen($expected_description) > $max_description_length) {
            $expected_description = substr($expected_description, 0, $max_description_length);
        }

        $this->assertArrayHasKey('description', $task_data);
        $this->assertSame($expected_description, $task_data['description']);

        $_POST['nonce'] = 'test-nonce';
        $_POST['task_id'] = $task_id;

        $backup = new BJLG\BJLG_Backup();

        try {
            $backup->handle_check_backup_progress();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response_exception) {
            $this->assertIsArray($response_exception->data);
            $this->assertArrayHasKey('description', $response_exception->data);
            $this->assertSame($expected_description, $response_exception->data['description']);
        } finally {
            unset($_POST['nonce'], $_POST['task_id']);
        }
    }

    public function test_get_status_handles_missing_backup_directory(): void
    {
        $api = new BJLG\BJLG_REST_API();

        $original_directory = rtrim(BJLG_BACKUP_DIR, '/\\');
        $temporary_directory = $original_directory . '-missing-' . uniqid('', true);

        if (!is_dir($original_directory)) {
            $this->markTestSkipped('Backup directory is not available for the test.');
        }

        if (!@rename($original_directory, $temporary_directory)) {
            $this->markTestSkipped('Unable to move the backup directory for the test.');
        }

        $disk_free_space_calls = 0;
        $GLOBALS['bjlg_test_disk_free_space_mock'] = static function (string $directory) use (&$disk_free_space_calls) {
            $disk_free_space_calls++;

            return 123;
        };

        $original_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class($original_wpdb) {
            /** @var object|null */
            private $original;

            /** @var string */
            public $options;

            public function __construct($original)
            {
                $this->original = $original;
                $this->options = $original->options ?? 'wp_options';
            }

            public function query($query)
            {
                if ($this->original !== null && method_exists($this->original, 'query')) {
                    return $this->original->query($query);
                }

                return true;
            }

            public function get_row($query)
            {
                if ($this->original !== null && method_exists($this->original, 'get_row')) {
                    return $this->original->get_row($query);
                }

                return (object) [
                    'size' => 0,
                    'tables' => 0,
                ];
            }

            public function get_var($query)
            {
                return 0;
            }
        };

        try {
            $response = $api->get_status(null);
        } finally {
            unset($GLOBALS['bjlg_test_disk_free_space_mock']);

            if ($original_wpdb !== null) {
                $GLOBALS['wpdb'] = $original_wpdb;
            } else {
                unset($GLOBALS['wpdb']);
            }

            if (!@rename($temporary_directory, $original_directory) && !is_dir($original_directory)) {
                mkdir($original_directory, 0777, true);
            }
        }

        $this->assertIsArray($response);
        $this->assertArrayHasKey('disk_free_space', $response);
        $this->assertNull($response['disk_free_space']);
        $this->assertArrayHasKey('disk_space_error', $response);
        $this->assertTrue($response['disk_space_error']);
        $this->assertSame(0, $disk_free_space_calls);
    }

    public function test_get_stats_handles_disk_space_failure(): void
    {
        $GLOBALS['bjlg_test_disk_total_space_mock'] = static function (string $directory) {
            return false;
        };

        $GLOBALS['bjlg_test_disk_free_space_mock'] = static function (string $directory) {
            return 1024;
        };

        $api = new BJLG\BJLG_REST_API();

        $request = new class {
            public function get_param($key)
            {
                if ($key === 'period') {
                    return 'week';
                }

                return null;
            }
        };

        $response = $api->get_stats($request);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('disk', $response);
        $this->assertArrayHasKey('usage_percent', $response['disk']);
        $this->assertNull($response['disk']['usage_percent']);
        $this->assertArrayHasKey('calculation_error', $response['disk']);
        $this->assertTrue($response['disk']['calculation_error']);

        unset($GLOBALS['bjlg_test_disk_total_space_mock'], $GLOBALS['bjlg_test_disk_free_space_mock'], $GLOBALS['wp_version']);
    }

    public function test_get_health_handles_missing_total_disk_space(): void
    {
        $GLOBALS['bjlg_test_disk_total_space_mock'] = static function (string $directory) {
            return false;
        };

        $GLOBALS['bjlg_test_disk_free_space_mock'] = static function (string $directory) {
            return 2048;
        };

        $GLOBALS['wp_version'] = '6.4.0';

        $api = new BJLG\BJLG_REST_API();

        $request = new class {
            public function get_param($key)
            {
                return null;
            }
        };

        $response = $api->get_health($request);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('status', $response);
        $this->assertSame('warning', $response['status']);
        $this->assertArrayHasKey('checks', $response);
        $this->assertArrayHasKey('disk_space', $response['checks']);
        $this->assertSame('warning', $response['checks']['disk_space']['status']);
        $this->assertStringContainsString("Impossible de déterminer l'espace disque total", $response['checks']['disk_space']['message']);

        unset($GLOBALS['bjlg_test_disk_total_space_mock'], $GLOBALS['bjlg_test_disk_free_space_mock'], $GLOBALS['wp_version']);
    }

    public function test_get_stats_does_not_register_cleanup_hooks_multiple_times(): void
    {
        $cleanup = BJLG\BJLG_Cleanup::instance();
        $initial_hooks = has_action(BJLG\BJLG_Cleanup::CRON_HOOK);

        $api = new BJLG\BJLG_REST_API();

        $request = new class {
            public function get_param($key)
            {
                if ($key === 'period') {
                    return 'week';
                }

                return null;
            }
        };

        $api->get_stats($request);
        $after_first_call = has_action(BJLG\BJLG_Cleanup::CRON_HOOK);

        $api->get_stats($request);
        $after_second_call = has_action(BJLG\BJLG_Cleanup::CRON_HOOK);

        $this->assertSame($initial_hooks, $after_first_call);
        $this->assertSame($after_first_call, $after_second_call);

        $registered_priority = has_action(BJLG\BJLG_Cleanup::CRON_HOOK, [$cleanup, 'run_cleanup']);
        $this->assertNotFalse($registered_priority);
    }

    public function test_format_backup_data_can_skip_token_generation(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $tempFile = tempnam(BJLG_BACKUP_DIR, 'bjlg-test-backup-');
        file_put_contents($tempFile, 'backup-content');

        $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
        $method = $reflection->getMethod('format_backup_data');
        $method->setAccessible(true);

        $data = $method->invoke($api, $tempFile);

        $this->assertArrayHasKey('download_rest_url', $data);
        $this->assertArrayNotHasKey('download_token', $data);
        $this->assertArrayNotHasKey('download_url', $data);
        $this->assertSame([], $GLOBALS['bjlg_test_transients']);

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    public function test_format_backup_data_generates_download_token_when_requested(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $tempFile = tempnam(BJLG_BACKUP_DIR, 'bjlg-test-backup-');
        file_put_contents($tempFile, 'backup-content');

        $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
        $method = $reflection->getMethod('format_backup_data');
        $method->setAccessible(true);

        $data = $method->invoke($api, $tempFile, null, true);

        $this->assertArrayHasKey('download_url', $data);
        $this->assertArrayHasKey('download_token', $data);
        $this->assertArrayHasKey('download_rest_url', $data);

        $parsed_url = parse_url($data['download_url']);
        $query_args = [];

        if (!empty($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query_args);
        }

        $this->assertArrayHasKey('token', $query_args);
        $this->assertSame($data['download_token'], $query_args['token']);
        $payload = get_transient('bjlg_download_' . $data['download_token']);
        $this->assertIsArray($payload);
        $this->assertSame($tempFile, $payload['file'] ?? null);
        $this->assertSame(BJLG_CAPABILITY, $payload['requires_cap'] ?? null);

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    public function test_format_backup_data_reuses_filesize_and_filemtime_values(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $tempFile = tempnam(BJLG_BACKUP_DIR, 'bjlg-test-backup-');
        file_put_contents($tempFile, 'backup-content');

        $GLOBALS['bjlg_test_filesize_callback'] = function (string $path) use ($tempFile) {
            $GLOBALS['bjlg_test_filesize_calls'][] = $path;

            return 4096;
        };

        $GLOBALS['bjlg_test_filemtime_callback'] = function (string $path) use ($tempFile) {
            $GLOBALS['bjlg_test_filemtime_calls'][] = $path;

            return 1_700_000_000;
        };

        $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
        $method = $reflection->getMethod('format_backup_data');
        $method->setAccessible(true);

        /** @var array<string, mixed> $data */
        $data = $method->invoke($api, $tempFile);

        $this->assertSame([$tempFile], $GLOBALS['bjlg_test_filesize_calls']);
        $this->assertSame([$tempFile], $GLOBALS['bjlg_test_filemtime_calls']);

        $this->assertSame(4096, $data['size']);
        $this->assertSame(size_format(4096), $data['size_formatted']);

        $expectedDate = date('c', 1_700_000_000);
        $this->assertSame($expectedDate, $data['created_at']);
        $this->assertSame($expectedDate, $data['modified_at']);

        unset($GLOBALS['bjlg_test_filesize_callback'], $GLOBALS['bjlg_test_filemtime_callback']);

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    public function test_format_backup_data_handles_missing_file_metadata(): void
    {
        $GLOBALS['bjlg_test_transients'] = [];

        $api = new BJLG\BJLG_REST_API();

        $tempFile = tempnam(BJLG_BACKUP_DIR, 'bjlg-test-backup-');
        file_put_contents($tempFile, 'backup-content');

        $GLOBALS['bjlg_test_filesize_callback'] = function (string $path) use ($tempFile) {
            $GLOBALS['bjlg_test_filesize_calls'][] = $path;

            return false;
        };

        $GLOBALS['bjlg_test_filemtime_callback'] = function (string $path) use ($tempFile) {
            $GLOBALS['bjlg_test_filemtime_calls'][] = $path;

            return false;
        };

        $reflection = new ReflectionClass(BJLG\BJLG_REST_API::class);
        $method = $reflection->getMethod('format_backup_data');
        $method->setAccessible(true);

        /** @var array<string, mixed> $data */
        $data = $method->invoke($api, $tempFile);

        $this->assertSame([$tempFile], $GLOBALS['bjlg_test_filesize_calls']);
        $this->assertSame([$tempFile], $GLOBALS['bjlg_test_filemtime_calls']);

        $this->assertNull($data['size']);
        $this->assertNull($data['size_formatted']);
        $this->assertNull($data['created_at']);
        $this->assertNull($data['modified_at']);

        unset($GLOBALS['bjlg_test_filesize_callback'], $GLOBALS['bjlg_test_filemtime_callback']);

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    public function test_webhook_backup_duration_is_positive_and_reasonable(): void
    {
        $webhook_key = 'webhook-test-key';
        update_option('bjlg_webhook_key', $webhook_key);

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';
        $_GET[BJLG\BJLG_Webhooks::WEBHOOK_QUERY_VAR] = $webhook_key;
        $_GET['components'] = 'db';

        $webhooks = new BJLG\BJLG_Webhooks();

        $previous_single_events = $GLOBALS['bjlg_test_scheduled_events']['single'];

        $task_id = null;

        try {
            $webhooks->listen_for_webhook();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown when webhook responds with JSON.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
            $this->assertArrayHasKey('task_id', $response->data);
            $task_id = (string) $response->data['task_id'];
        }

        $this->assertNotEmpty($task_id ?? null, 'Webhook should provide a task identifier.');
        $this->assertArrayHasKey($task_id, $GLOBALS['bjlg_test_transients']);

        $task_data = $GLOBALS['bjlg_test_transients'][$task_id];
        $this->assertArrayHasKey('start_time', $task_data);
        $this->assertIsInt($task_data['start_time']);

        sleep(1);

        $duration = time() - $task_data['start_time'];
        $this->assertGreaterThan(0, $duration, 'Duration should be strictly positive.');
        $this->assertLessThan(120, $duration, 'Duration should be within a reasonable bound.');

        $GLOBALS['bjlg_test_scheduled_events']['single'] = $previous_single_events;

        unset($GLOBALS['bjlg_test_transients'][$task_id]);
        unset($_GET[BJLG\BJLG_Webhooks::WEBHOOK_QUERY_VAR]);
        unset($_GET['components']);
        unset($_SERVER['REMOTE_ADDR']);
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    public function test_authenticate_remains_accessible_when_rate_limit_allows(): void
    {
        $api = new BJLG\BJLG_REST_API();

        $apiKey = 'valid-key';
        $user = $this->makeUser(1, 'admin');
        $GLOBALS['bjlg_test_users'][$user->ID] = $user;

        update_option('bjlg_api_keys', [
            [
                'key' => wp_hash_password($apiKey),
                'user_id' => $user->ID,
            ],
        ]);

        $request = new BJLG_Test_Auth_Request(
            [
                'api_key' => $apiKey,
            ],
            [
                'X-API-Key' => $apiKey,
            ]
        );

        $permission = $api->check_auth_permissions($request);
        $this->assertTrue($permission);

        $response = $api->authenticate($request);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertSame('Authentication successful', $response['message']);
        $this->assertArrayHasKey('token', $response);
    }

    public function test_auth_permission_is_blocked_after_consecutive_attempts(): void
    {
        $api = new BJLG\BJLG_REST_API();

        $request = new BJLG_Test_Auth_Request([], [
            'X-API-Key' => 'flood-client',
        ]);

        for ($i = 0; $i < BJLG\BJLG_Rate_Limiter::RATE_LIMIT_MINUTE; $i++) {
            $result = $api->check_auth_permissions($request);
            $this->assertTrue($result, 'La vérification devrait réussir tant que la limite n\'est pas dépassée.');
        }

        $blocked = $api->check_auth_permissions($request);
        $this->assertInstanceOf(\WP_Error::class, $blocked);
        $this->assertSame('rate_limit_exceeded', $blocked->get_error_code());

        $errorData = $blocked->get_error_data('rate_limit_exceeded');
        $this->assertIsArray($errorData);
        $this->assertSame(429, $errorData['status']);
    }

    private function generateJwtToken(array $overrides = []): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = array_merge([
            'user_id' => 1,
            'username' => 'test-user',
            'exp' => time() + 3600,
            'iat' => time(),
        ], $overrides);

        $base64Header = $this->base64UrlEncode((string) json_encode($header));
        $base64Payload = $this->base64UrlEncode((string) json_encode($payload));
        $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, AUTH_KEY, true);

        return $base64Header . '.' . $base64Payload . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

}
