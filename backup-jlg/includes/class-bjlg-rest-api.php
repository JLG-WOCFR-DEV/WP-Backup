<?php
namespace BJLG;

/**
 * API REST pour Backup JLG
 * Fichier : includes/class-bjlg-rest-api.php
 */

use WP_Error;
use ZipArchive;

if (!defined('ABSPATH')) {
    exit;
}

class BJLG_REST_API {
    
    const API_NAMESPACE = 'backup-jlg/v1';
    const API_VERSION = '1.0.0';
    
    private $rate_limiter;
    
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('pre_update_option_bjlg_api_keys', [$this, 'filter_api_keys_before_save'], 10, 3);
        add_action('add_option_bjlg_api_keys', [$this, 'handle_api_keys_added'], 10, 2);

        // Initialiser le rate limiter
        if (class_exists(BJLG_Rate_Limiter::class)) {
            $this->rate_limiter = new BJLG_Rate_Limiter();
        }
    }
    
    /**
     * Enregistre toutes les routes de l'API
     */
    public function register_routes() {
        
        // Route : Informations sur l'API
        register_rest_route(self::API_NAMESPACE, '/info', [
            'methods' => 'GET',
            'callback' => [$this, 'get_api_info'],
            'permission_callback' => '__return_true'
        ]);
        
        // Route : Authentification
        register_rest_route(self::API_NAMESPACE, '/auth', [
            'methods' => 'POST',
            'callback' => [$this, 'authenticate'],
            'permission_callback' => '__return_true',
            'args' => [
                'username' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'password' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'api_key' => [
                    'required' => false,
                    'type' => 'string',
                ]
            ]
        ]);
        
        // Routes : Gestion des sauvegardes
        register_rest_route(self::API_NAMESPACE, '/backups', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_backups'],
                'permission_callback' => [$this, 'check_permissions'],
                'args' => [
                    'page' => [
                        'default' => 1,
                        'validate_callback' => function($param) {
                            return is_numeric($param);
                        }
                    ],
                    'per_page' => [
                        'default' => 10,
                        'validate_callback' => function($param) {
                            $value = filter_var(
                                $param,
                                FILTER_VALIDATE_INT,
                                [
                                    'options' => [
                                        'min_range' => 1,
                                        'max_range' => 100,
                                    ],
                                ]
                            );

                            if ($value === false) {
                                return new WP_Error(
                                    'rest_invalid_param',
                                    __('Le paramètre per_page doit être un entier compris entre 1 et 100.', 'backup-jlg'),
                                    ['status' => 400]
                                );
                            }

                            return true;
                        }
                    ],
                    'type' => [
                        'default' => 'all',
                        'enum' => ['all', 'full', 'incremental', 'database', 'files']
                    ],
                    'sort' => [
                        'default' => 'date_desc',
                        'enum' => ['date_asc', 'date_desc', 'size_asc', 'size_desc']
                    ]
                ]
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create_backup'],
                'permission_callback' => [$this, 'check_permissions'],
                'args' => [
                    'components' => [
                        'required' => false,
                        'default' => ['db', 'plugins', 'themes', 'uploads'],
                        'type' => 'array'
                    ],
                    'type' => [
                        'default' => 'full',
                        'enum' => ['full', 'incremental']
                    ],
                    'encrypt' => [
                        'default' => false,
                        'type' => 'boolean'
                    ],
                    'description' => [
                        'type' => 'string'
                    ]
                ]
            ]
        ]);
        
        // Routes : Opérations sur une sauvegarde spécifique
        register_rest_route(self::API_NAMESPACE, '/backups/(?P<id>[A-Za-z0-9._-]+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_backup'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_backup'],
                'permission_callback' => [$this, 'check_permissions'],
            ]
        ]);
        
        // Route : Télécharger une sauvegarde
        register_rest_route(self::API_NAMESPACE, '/backups/(?P<id>[A-Za-z0-9._-]+)/download', [
            'methods' => 'GET',
            'callback' => [$this, 'download_backup'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'token' => [
                    'required' => false,
                    'type' => 'string'
                ]
            ]
        ]);
        
        // Route : Restaurer une sauvegarde
        register_rest_route(self::API_NAMESPACE, '/backups/(?P<id>[A-Za-z0-9._-]+)/restore', [
            'methods' => 'POST',
            'callback' => [$this, 'restore_backup'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'components' => [
                    'type' => 'array',
                    'default' => ['all']
                ],
                'create_restore_point' => [
                    'type' => 'boolean',
                    'default' => true
                ]
            ]
        ]);
        
        // Routes : Statut et monitoring
        register_rest_route(self::API_NAMESPACE, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_status'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
        
        register_rest_route(self::API_NAMESPACE, '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'get_health'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
        
        // Routes : Statistiques
        register_rest_route(self::API_NAMESPACE, '/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'period' => [
                    'default' => 'week',
                    'enum' => ['day', 'week', 'month', 'year']
                ]
            ]
        ]);
        
        // Routes : Historique
        register_rest_route(self::API_NAMESPACE, '/history', [
            'methods' => 'GET',
            'callback' => [$this, 'get_history'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'limit' => [
                    'default' => 50,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param <= 500;
                    }
                ],
                'action' => [
                    'type' => 'string'
                ],
                'status' => [
                    'enum' => ['success', 'failure', 'info']
                ]
            ]
        ]);
        
        // Routes : Configuration
        register_rest_route(self::API_NAMESPACE, '/settings', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_settings'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'update_settings'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ]
        ]);
        
        // Routes : Planification
        register_rest_route(self::API_NAMESPACE, '/schedules', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_schedules'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create_schedule'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ]
        ]);
        
        // Route : Tâches
        register_rest_route(self::API_NAMESPACE, '/tasks/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_task_status'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
    }
    
    /**
     * Vérification des permissions de base
     */
    public function check_permissions($request) {
        // Vérifier le rate limiting si disponible
        if ($this->rate_limiter && !$this->rate_limiter->check($request)) {
            return new WP_Error(
                'rate_limit_exceeded',
                'Trop de requêtes. Veuillez patienter.',
                ['status' => 429]
            );
        }
        
        // Vérifier l'authentification via API Key
        $api_key = $request->get_header('X-API-Key');
        if ($api_key) {
            return $this->verify_api_key($api_key);
        }
        
        // Vérifier l'authentification via Bearer Token
        $auth_header = $request->get_header('Authorization');
        if ($auth_header && strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
            $jwt_check = $this->verify_jwt_token($token);

            if (is_wp_error($jwt_check)) {
                return $jwt_check;
            }

            return true;
        }

        // Vérifier l'authentification WordPress standard
        return current_user_can(BJLG_CAPABILITY);
    }
    
    /**
     * Vérification des permissions admin
     */
    public function check_admin_permissions($request) {
        $permissions_check = $this->check_permissions($request);

        if (is_wp_error($permissions_check)) {
            return $permissions_check;
        }

        if (!$permissions_check) {
            return false;
        }

        return current_user_can('manage_options');
    }
    
    /**
     * Vérifie une clé API
     */
    private function verify_api_key($api_key) {
        $stored_keys = get_option('bjlg_api_keys', []);

        foreach ($stored_keys as $index => &$key_data) {
            $stored_value = isset($key_data['key']) ? $key_data['key'] : '';

            if (!is_string($stored_value) || $stored_value === '') {
                continue;
            }

            $is_match = $this->check_api_key($api_key, $stored_value);
            $needs_rehash = $this->should_rehash_api_key($stored_value);

            if (!$is_match && !$this->is_api_key_hashed($stored_value) && hash_equals($stored_value, $api_key)) {
                $is_match = true;
                $needs_rehash = true;
            }

            if (!$is_match) {
                continue;
            }

            if (isset($key_data['expires']) && $key_data['expires'] < time()) {
                unset($key_data);
                return false;
            }

            if ($needs_rehash) {
                $key_data['key'] = $this->hash_api_key($api_key);
            }

            $key_data['last_used'] = time();
            $key_data['usage_count'] = ($key_data['usage_count'] ?? 0) + 1;
            $stored_keys[$index] = $key_data;
            update_option('bjlg_api_keys', $stored_keys);

            unset($key_data);
            return true;
        }

        unset($key_data);
        return false;
    }

    public function filter_api_keys_before_save($new_value, $old_value = null, $option = '') {
        return $this->sanitize_api_keys($new_value);
    }

    public function handle_api_keys_added($option, $value) {
        $sanitized = $this->sanitize_api_keys($value);

        if ($sanitized !== $value) {
            update_option($option, $sanitized);
        }
    }

    private function sanitize_api_keys($keys) {
        if (!is_array($keys)) {
            return $keys;
        }

        foreach ($keys as $index => $key_data) {
            if (!is_array($key_data)) {
                continue;
            }

            if (isset($key_data['key']) && !$this->is_api_key_hashed($key_data['key'])) {
                $keys[$index]['key'] = $this->hash_api_key($key_data['key']);
            }

            foreach (['plain_key', 'raw_key', 'display_key', 'api_key_plain', 'api_key_plaintext'] as $sensitive_field) {
                if (isset($keys[$index][$sensitive_field])) {
                    unset($keys[$index][$sensitive_field]);
                }
            }
        }

        return $keys;
    }

    private function hash_api_key($key) {
        if (function_exists('wp_hash_password')) {
            return wp_hash_password($key);
        }

        if (function_exists('password_hash')) {
            return password_hash($key, PASSWORD_DEFAULT);
        }

        return hash('sha256', $key);
    }

    private function check_api_key($raw_key, $stored_value) {
        if (!is_string($stored_value) || $stored_value === '') {
            return false;
        }

        if ($this->is_api_key_hashed($stored_value)) {
            if (function_exists('wp_check_password')) {
                return wp_check_password($raw_key, $stored_value);
            }

            if (function_exists('password_verify')) {
                return password_verify($raw_key, $stored_value);
            }
        }

        return hash_equals($stored_value, $raw_key);
    }

    private function should_rehash_api_key($stored_value) {
        if (!$this->is_api_key_hashed($stored_value)) {
            return true;
        }

        if (function_exists('wp_password_needs_rehash')) {
            return wp_password_needs_rehash($stored_value);
        }

        if (function_exists('password_needs_rehash')) {
            $info = password_get_info($stored_value);
            if (!empty($info['algo'])) {
                return password_needs_rehash($stored_value, PASSWORD_DEFAULT);
            }
        }

        return false;
    }

    private function is_api_key_hashed($value) {
        if (!is_string($value) || $value === '') {
            return false;
        }

        if (strpos($value, '$P$') === 0 || strpos($value, '$H$') === 0) {
            return true;
        }

        if (strpos($value, '$argon2') === 0 || strpos($value, '$2y$') === 0 || strpos($value, '$2a$') === 0 || strpos($value, '$2b$') === 0) {
            return true;
        }

        if (function_exists('password_get_info')) {
            $info = password_get_info($value);
            if (!empty($info['algo'])) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Vérifie un token JWT
     */
    private function verify_jwt_token($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return new WP_Error(
                'jwt_invalid_token',
                __('Token JWT invalide.', 'backup-jlg'),
                ['status' => 401]
            );
        }

        list($header, $payload, $signature) = $parts;

        $decoded_header = $this->base64url_decode($header);
        $decoded_payload = $this->base64url_decode($payload);

        if ($decoded_header === false || $decoded_payload === false) {
            return new WP_Error(
                'jwt_invalid_token',
                __('Le token JWT est mal formé.', 'backup-jlg'),
                ['status' => 401]
            );
        }

        $payload_data = json_decode($decoded_payload, true);
        if (!is_array($payload_data)) {
            return new WP_Error(
                'jwt_invalid_token',
                __('Impossible de décoder le token JWT.', 'backup-jlg'),
                ['status' => 401]
            );
        }

        if (!isset($payload_data['exp']) || !is_numeric($payload_data['exp'])) {
            return new WP_Error(
                'jwt_invalid_token',
                __('Le token JWT est invalide.', 'backup-jlg'),
                ['status' => 401]
            );
        }

        if ((int) $payload_data['exp'] < time()) {
            return new WP_Error(
                'jwt_expired_token',
                __('Le token JWT a expiré.', 'backup-jlg'),
                ['status' => 401]
            );
        }

        if (!defined('AUTH_KEY')) {
            return new WP_Error(
                'jwt_signature_error',
                __('Clé d’authentification WordPress manquante.', 'backup-jlg'),
                ['status' => 500]
            );
        }

        $expected_signature = hash_hmac('sha256', $header . '.' . $payload, AUTH_KEY, true);
        $expected_signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($expected_signature));

        if (!hash_equals($expected_signature, $signature)) {
            return new WP_Error(
                'jwt_invalid_signature',
                __('La signature du token JWT est invalide.', 'backup-jlg'),
                ['status' => 401]
            );
        }

        $user_id = isset($payload_data['user_id']) ? (int) $payload_data['user_id'] : 0;
        $username = isset($payload_data['username']) ? (string) $payload_data['username'] : '';

        if ($user_id <= 0 && $username === '') {
            return new WP_Error(
                'jwt_invalid_token',
                __('Les informations utilisateur sont manquantes dans le token.', 'backup-jlg'),
                ['status' => 401]
            );
        }

        $user = false;

        if ($user_id > 0) {
            $user = get_user_by('id', $user_id);
        }

        if (!$user && $username !== '') {
            $user = get_user_by('login', $username);
        }

        if (!$user) {
            return new WP_Error(
                'jwt_user_not_found',
                __('Utilisateur introuvable pour ce token.', 'backup-jlg'),
                ['status' => 401]
            );
        }

        $has_capability = function_exists('user_can') ? user_can($user, BJLG_CAPABILITY) : false;

        if (!$has_capability) {
            return new WP_Error(
                'jwt_insufficient_permissions',
                __('Les permissions de cet utilisateur ne sont plus valides.', 'backup-jlg'),
                ['status' => 403]
            );
        }

        return true;
    }

    private function base64url_decode($data) {
        $data = strtr($data, '-_', '+/');
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($data, true);
    }
    
    /**
     * Endpoint : Informations sur l'API
     */
    public function get_api_info($request) {
        return rest_ensure_response([
            'version' => self::API_VERSION,
            'namespace' => self::API_NAMESPACE,
            'authentication' => [
                'methods' => ['api_key', 'jwt', 'wordpress_auth'],
                'oauth2_url' => null
            ],
            'endpoints' => $this->get_available_endpoints(),
            'rate_limits' => [
                'requests_per_minute' => 60,
                'requests_per_hour' => 1000
            ],
            'documentation' => get_site_url() . '/wp-admin/admin.php?page=backup-jlg&tab=api_docs'
        ]);
    }
    
    /**
     * Endpoint : Authentification
     */
    public function authenticate($request) {
        $username = $request->get_param('username');
        $password = $request->get_param('password');
        $api_key = $request->get_param('api_key');
        
        // Authentification par API Key
        if ($api_key && $this->verify_api_key($api_key)) {
            $user = get_user_by('login', $username);

            if (!$user) {
                return new WP_Error(
                    'user_not_found',
                    'User not found',
                    ['status' => 404]
                );
            }

            if (!user_can($user, BJLG_CAPABILITY)) {
                return new WP_Error(
                    'insufficient_permissions',
                    'User does not have backup permissions',
                    ['status' => 403]
                );
            }

            return rest_ensure_response([
                'success' => true,
                'message' => 'Authentication successful',
                'token' => $this->generate_jwt_token($user->ID, $user->user_login),
                'user' => [
                    'id' => $user->ID,
                    'username' => $user->user_login,
                    'email' => $user->user_email
                ]
            ]);
        }
        
        // Authentification par username/password
        $user = wp_authenticate($username, $password);
        
        if (is_wp_error($user)) {
            return new WP_Error(
                'authentication_failed',
                'Invalid credentials',
                ['status' => 401]
            );
        }
        
        if (!user_can($user, BJLG_CAPABILITY)) {
            return new WP_Error(
                'insufficient_permissions',
                'User does not have backup permissions',
                ['status' => 403]
            );
        }
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Authentication successful',
            'token' => $this->generate_jwt_token($user->ID, $user->user_login),
            'user' => [
                'id' => $user->ID,
                'username' => $user->user_login,
                'email' => $user->user_email
            ]
        ]);
    }
    
    /**
     * Génère un token JWT
     */
    private function generate_jwt_token($user_id, $username) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => (int) $user_id,
            'username' => $username,
            'exp' => time() + (7 * DAY_IN_SECONDS),
            'iat' => time()
        ]);
        
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, AUTH_KEY, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
    }
    
    /**
     * Endpoint : Liste des sauvegardes
     */
    public function get_backups($request) {
        $page = $request->get_param('page');
        $per_page = $request->get_param('per_page');
        $type = $request->get_param('type');
        $sort = $request->get_param('sort');

        $page = max(1, (int) $page);
        $per_page = max(1, min(100, (int) $per_page));
        
        $backups = [];
        $files = glob(BJLG_BACKUP_DIR . '*.zip*');
        
        if (empty($files)) {
            return rest_ensure_response([
                'backups' => [],
                'pagination' => [
                    'total' => 0,
                    'pages' => 0,
                    'current_page' => $page,
                    'per_page' => $per_page
                ]
            ]);
        }
        
        // Filtrer par type
        if ($type !== 'all') {
            $files = array_filter($files, function($file) use ($type) {
                $filename = basename($file);
                return strpos($filename, $type) !== false;
            });
        }
        
        // Trier
        $this->sort_files($files, $sort);
        
        // Pagination
        $total = count($files);
        $offset = ($page - 1) * $per_page;
        $files = array_slice($files, $offset, $per_page);
        
        // Construire la réponse
        foreach ($files as $file) {
            $backups[] = $this->format_backup_data($file);
        }
        
        $response = rest_ensure_response([
            'backups' => $backups,
            'pagination' => [
                'total' => $total,
                'pages' => ceil($total / $per_page),
                'current_page' => $page,
                'per_page' => $per_page
            ]
        ]);

        if (is_object($response) && method_exists($response, 'header')) {
            $response->header('X-Total-Count', $total);
        }

        return $response;
    }
    
    /**
     * Endpoint : Créer une sauvegarde
     */
    public function create_backup($request) {
        $components = $request->get_param('components');
        $filtered_components = $this->sanitize_components_list($components);
        if (is_wp_error($filtered_components)) {
            return $filtered_components;
        }
        if (empty($filtered_components)) {
            return new WP_Error(
                'invalid_components',
                __('Aucun composant valide fourni pour la sauvegarde.', 'backup-jlg'),
                ['status' => 400]
            );
        }
        $type = $request->get_param('type');
        $type = ($type === 'incremental') ? 'incremental' : 'full';

        $encrypt_param = $request->get_param('encrypt');
        $encrypt = filter_var($encrypt_param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($encrypt === null) {
            $encrypt = false;
        }

        $incremental = ($type === 'incremental');
        $description = $request->get_param('description');

        // Créer une tâche de sauvegarde
        $task_id = 'bjlg_backup_' . md5(uniqid('api', true));
        $task_data = [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => 'Initialisation (API)...',
            'components' => $filtered_components,
            'encrypt' => $encrypt,
            'incremental' => $incremental,
            'type' => $type,
            'description' => $description,
            'source' => 'api',
            'start_time' => time()
        ];
        
        set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl());
        
        // Planifier l'exécution
        wp_schedule_single_event(time(), 'bjlg_run_backup_task', ['task_id' => $task_id]);
        
        BJLG_History::log('api_backup_created', 'info', 'Backup initiated via API');
        
        return rest_ensure_response([
            'success' => true,
            'task_id' => $task_id,
            'message' => 'Backup task created successfully',
            'status_url' => rest_url(self::API_NAMESPACE . '/tasks/' . $task_id)
        ]);
    }

    private function sanitize_components_list($components) {
        $allowed_components = ['db', 'plugins', 'themes', 'uploads'];
        $components = (array) $components;
        $sanitized = [];

        foreach ($components as $component) {
            if (!is_string($component)) {
                continue;
            }

            if (preg_match('#[\\/]#', $component)) {
                return new WP_Error(
                    'invalid_component_format',
                    __('Format de composant invalide.', 'backup-jlg'),
                    ['status' => 400]
                );
            }

            $component = sanitize_key($component);

            if (in_array($component, $allowed_components, true) && !in_array($component, $sanitized, true)) {
                $sanitized[] = $component;
            }
        }

        return $sanitized;
    }

    private function prepare_settings_payload(array $params) {
        $validated = [];

        foreach ($params as $key => $value) {
            switch ($key) {
                case 'cleanup':
                    $validated_value = $this->validate_cleanup_settings($value);
                    break;
                case 'schedule':
                    $validated_value = $this->validate_schedule_settings($value);
                    break;
                case 'encryption':
                case 'notifications':
                case 'performance':
                case 'webhooks':
                    $validated_value = $this->validate_generic_settings($value, $key);
                    break;
                default:
                    return new WP_Error(
                        'invalid_setting_key',
                        sprintf(__('The "%s" setting cannot be updated via the REST API.', 'backup-jlg'), $key),
                        ['status' => 400]
                    );
            }

            if (is_wp_error($validated_value)) {
                return $validated_value;
            }

            $validated[$key] = $validated_value;
        }

        if (empty($validated)) {
            return new WP_Error(
                'invalid_payload',
                __('No valid settings were provided.', 'backup-jlg'),
                ['status' => 400]
            );
        }

        return $validated;
    }

    private function validate_generic_settings($value, $key) {
        if (!is_array($value)) {
            return new WP_Error(
                'invalid_setting_structure',
                sprintf(__('The "%s" setting must be a JSON object.', 'backup-jlg'), $key),
                ['status' => 400]
            );
        }

        return $value;
    }

    private function validate_cleanup_settings($value) {
        if (!is_array($value)) {
            return new WP_Error(
                'invalid_cleanup_settings',
                __('Cleanup settings must be provided as a JSON object.', 'backup-jlg'),
                ['status' => 400]
            );
        }

        foreach (['by_number', 'by_age'] as $required_key) {
            if (!array_key_exists($required_key, $value)) {
                return new WP_Error(
                    'invalid_cleanup_settings',
                    sprintf(__('Missing cleanup setting "%s".', 'backup-jlg'), $required_key),
                    ['status' => 400]
                );
            }
        }

        $by_number = filter_var($value['by_number'], FILTER_VALIDATE_INT);
        $by_age = filter_var($value['by_age'], FILTER_VALIDATE_INT);

        if ($by_number === false || $by_age === false) {
            return new WP_Error(
                'invalid_cleanup_settings',
                __('Cleanup settings must contain valid integers.', 'backup-jlg'),
                ['status' => 400]
            );
        }

        return [
            'by_number' => $by_number,
            'by_age' => $by_age,
        ];
    }

    private function validate_schedule_settings($value) {
        if (!is_array($value)) {
            return new WP_Error(
                'invalid_schedule_settings',
                __('Schedule settings must be provided as a JSON object.', 'backup-jlg'),
                ['status' => 400]
            );
        }

        $required_keys = ['recurrence', 'day', 'time', 'components', 'encrypt', 'incremental'];

        foreach ($required_keys as $required_key) {
            if (!array_key_exists($required_key, $value)) {
                return new WP_Error(
                    'invalid_schedule_settings',
                    sprintf(__('Missing schedule setting "%s".', 'backup-jlg'), $required_key),
                    ['status' => 400]
                );
            }
        }

        $recurrence = sanitize_key((string) $value['recurrence']);
        $valid_recurrences = ['disabled', 'hourly', 'twice_daily', 'daily', 'weekly', 'monthly'];

        if (!in_array($recurrence, $valid_recurrences, true)) {
            return new WP_Error(
                'invalid_schedule_settings',
                __('Invalid schedule recurrence value.', 'backup-jlg'),
                ['status' => 400]
            );
        }

        $day = sanitize_key((string) $value['day']);
        $valid_days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        if (!in_array($day, $valid_days, true)) {
            return new WP_Error(
                'invalid_schedule_settings',
                __('Invalid schedule day value.', 'backup-jlg'),
                ['status' => 400]
            );
        }

        $time = sanitize_text_field((string) $value['time']);

        if (!preg_match('/^([0-1]?\d|2[0-3]):([0-5]\d)$/', $time)) {
            return new WP_Error(
                'invalid_schedule_settings',
                __('Invalid schedule time format. Expected HH:MM.', 'backup-jlg'),
                ['status' => 400]
            );
        }

        $components = $this->sanitize_components_list($value['components']);

        if (is_wp_error($components)) {
            return $components;
        }

        if (empty($components)) {
            return new WP_Error(
                'invalid_schedule_settings',
                __('At least one valid component must be provided for the schedule.', 'backup-jlg'),
                ['status' => 400]
            );
        }

        $encrypt = $this->interpret_boolean($value['encrypt']);
        $incremental = $this->interpret_boolean($value['incremental']);

        if ($encrypt === null || $incremental === null) {
            return new WP_Error(
                'invalid_schedule_settings',
                __('Schedule boolean settings must be true or false.', 'backup-jlg'),
                ['status' => 400]
            );
        }

        return [
            'recurrence' => $recurrence,
            'day' => $day,
            'time' => $time,
            'components' => $components,
            'encrypt' => $encrypt,
            'incremental' => $incremental,
        ];
    }

    private function interpret_boolean($value) {
        if (is_bool($value)) {
            return $value;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($filtered === null) {
            return null;
        }

        return $filtered;
    }

    /**
     * Endpoint : Obtenir une sauvegarde spécifique
     */
    public function get_backup($request) {
        $filepath = $this->resolve_backup_path($request->get_param('id'));

        if (is_wp_error($filepath)) {
            return $filepath;
        }

        return rest_ensure_response($this->format_backup_data($filepath));
    }
    
    /**
     * Endpoint : Supprimer une sauvegarde
     */
    public function delete_backup($request) {
        $filepath = $this->resolve_backup_path($request->get_param('id'));

        if (is_wp_error($filepath)) {
            return $filepath;
        }

        if (unlink($filepath)) {
            BJLG_History::log('backup_deleted', 'success', 'Deleted via API: ' . basename($filepath));

            return rest_ensure_response([
                'success' => true,
                'message' => 'Backup deleted successfully'
            ]);
        }

        return new WP_Error(
            'deletion_failed',
            'Failed to delete backup',
            ['status' => 500]
        );
    }
    
    /**
     * Endpoint : Télécharger une sauvegarde
     */
    public function download_backup($request) {
        $token = $request->get_param('token');

        $filepath = $this->resolve_backup_path($request->get_param('id'));

        if (is_wp_error($filepath)) {
            return $filepath;
        }

        // Générer un lien de téléchargement temporaire
        $download_token = wp_generate_password(32, false);
        set_transient('bjlg_download_' . $download_token, $filepath, BJLG_Backup::get_task_ttl());

        $download_url = add_query_arg([
            'action' => 'bjlg_download',
            'token' => $download_token,
        ], admin_url('admin-ajax.php'));

        return rest_ensure_response([
            'download_url' => $download_url,
            'expires_in' => BJLG_Backup::get_task_ttl(),
            'filename' => basename($filepath),
            'size' => filesize($filepath)
        ]);
    }
    
    /**
     * Endpoint : Restaurer une sauvegarde
     */
    public function restore_backup($request) {
        $components = $request->get_param('components');
        $create_restore_point = $request->get_param('create_restore_point');

        $filepath = $this->resolve_backup_path($request->get_param('id'));

        if (is_wp_error($filepath)) {
            return $filepath;
        }

        // Créer une tâche de restauration
        $task_id = 'bjlg_restore_' . md5(uniqid('api', true));
        $task_data = [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => 'Initialisation de la restauration (API)...',
            'filepath' => $filepath,
            'components' => $components,
            'create_restore_point' => $create_restore_point
        ];
        
        set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl());
        
        // Planifier l'exécution
        wp_schedule_single_event(time(), 'bjlg_run_restore_task', ['task_id' => $task_id]);
        
        return rest_ensure_response([
            'success' => true,
            'task_id' => $task_id,
            'message' => 'Restore task created successfully',
            'status_url' => rest_url(self::API_NAMESPACE . '/tasks/' . $task_id)
        ]);
    }

    /**
     * Résout et valide le chemin d'une sauvegarde à partir d'un identifiant brut.
     *
     * @param mixed $raw_id
     * @return string|WP_Error
     */
    private function resolve_backup_path($raw_id) {
        $sanitized_id = sanitize_file_name(basename((string) $raw_id));

        if ($sanitized_id === '') {
            return new WP_Error(
                'invalid_backup_id',
                'Invalid backup ID',
                ['status' => 400]
            );
        }

        $canonical_backup_dir = realpath(BJLG_BACKUP_DIR);

        if ($canonical_backup_dir === false) {
            return new WP_Error(
                'invalid_backup_id',
                'Invalid backup ID',
                ['status' => 400]
            );
        }

        $canonical_backup_dir = rtrim($canonical_backup_dir, "/\\") . DIRECTORY_SEPARATOR;

        $candidate_paths = [BJLG_BACKUP_DIR . $sanitized_id];

        if (strtolower(substr($sanitized_id, -4)) !== '.zip') {
            $candidate_paths[] = BJLG_BACKUP_DIR . $sanitized_id . '.zip';
        }

        $canonical_length = strlen($canonical_backup_dir);

        foreach ($candidate_paths as $candidate_path) {
            if (!file_exists($candidate_path)) {
                continue;
            }

            $resolved_path = realpath($candidate_path);

            if ($resolved_path === false) {
                return new WP_Error(
                    'invalid_backup_id',
                    'Invalid backup ID',
                    ['status' => 400]
                );
            }

            if (strlen($resolved_path) < $canonical_length || strncmp($resolved_path, $canonical_backup_dir, $canonical_length) !== 0) {
                return new WP_Error(
                    'invalid_backup_id',
                    'Invalid backup ID',
                    ['status' => 400]
                );
            }

            return $resolved_path;
        }

        return new WP_Error(
            'backup_not_found',
            'Backup not found',
            ['status' => 404]
        );
    }
    
    /**
     * Endpoint : Statut du système
     */
    public function get_status($request) {
        $status = [
            'plugin_version' => BJLG_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'backup_directory' => BJLG_BACKUP_DIR,
            'backup_directory_writable' => is_writable(BJLG_BACKUP_DIR),
            'total_backups' => count(glob(BJLG_BACKUP_DIR . '*.zip*')),
            'total_size' => $this->get_total_backup_size(),
            'disk_free_space' => disk_free_space(BJLG_BACKUP_DIR),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'active_tasks' => $this->get_active_tasks_count()
        ];
        
        return rest_ensure_response($status);
    }
    
    /**
     * Endpoint : Santé du système
     */
    public function get_health($request) {
        $health_check = new BJLG_Health_Check();
        $results = $health_check->get_all_checks();
        
        $overall_status = 'healthy';
        foreach ($results as $check) {
            if ($check['status'] === 'error') {
                $overall_status = 'critical';
                break;
            } elseif ($check['status'] === 'warning' && $overall_status !== 'critical') {
                $overall_status = 'warning';
            }
        }
        
        return rest_ensure_response([
            'status' => $overall_status,
            'checks' => $results,
            'timestamp' => current_time('c')
        ]);
    }
    
    /**
     * Endpoint : Statistiques
     */
    public function get_stats($request) {
        $period = $request->get_param('period');
        
        $history_stats = BJLG_History::get_stats($period);
        $storage_stats = BJLG_Cleanup::get_storage_stats_snapshot();
        
        $disk_total = $storage_stats['disk_total'];
        $disk_free = $storage_stats['disk_free'];

        $disk_calculation_error = false;
        $disk_usage_percent = null;

        if (!is_numeric($disk_total) || $disk_total <= 0 || !is_numeric($disk_free)) {
            $disk_calculation_error = true;
        } else {
            $disk_usage_percent = round((($disk_total - $disk_free) / $disk_total) * 100, 2);
        }

        if (!empty($storage_stats['disk_space_error'])) {
            $disk_calculation_error = true;
        }

        return rest_ensure_response([
            'period' => $period,
            'backups' => [
                'total' => $storage_stats['total_backups'],
                'total_size' => $storage_stats['total_size'],
                'average_size' => $storage_stats['average_size'],
                'oldest' => $storage_stats['oldest_backup'],
                'newest' => $storage_stats['newest_backup']
            ],
            'activity' => $history_stats,
            'disk' => [
                'free' => $storage_stats['disk_free'],
                'total' => $storage_stats['disk_total'],
                'usage_percent' => $disk_usage_percent,
                'calculation_error' => $disk_calculation_error
            ]
        ]);
    }
    
    /**
     * Endpoint : Historique
     */
    public function get_history($request) {
        $limit = $request->get_param('limit');
        $action = $request->get_param('action');
        $status = $request->get_param('status');
        
        $filters = [];
        if ($action) $filters['action_type'] = $action;
        if ($status) $filters['status'] = $status;
        
        $history = BJLG_History::get_history($limit, $filters);
        
        return rest_ensure_response([
            'entries' => $history,
            'total' => count($history)
        ]);
    }
    
    /**
     * Endpoint : Obtenir les paramètres
     */
    public function get_settings($request) {
        $settings = [
            'cleanup' => get_option('bjlg_cleanup_settings', []),
            'schedule' => get_option('bjlg_schedule_settings', []),
            'encryption' => get_option('bjlg_encryption_settings', []),
            'notifications' => get_option('bjlg_notification_settings', []),
            'performance' => get_option('bjlg_performance_settings', []),
            'webhooks' => get_option('bjlg_webhook_settings', [])
        ];
        
        return rest_ensure_response($settings);
    }
    
    /**
     * Endpoint : Mettre à jour les paramètres
     */
    public function update_settings($request) {
        $params = $request->get_json_params();

        if (!is_array($params) || empty($params)) {
            return new WP_Error(
                'invalid_payload',
                __('The request payload must be a JSON object with settings data.', 'backup-jlg'),
                ['status' => 400]
            );
        }

        $validated_settings = $this->prepare_settings_payload($params);

        if (is_wp_error($validated_settings)) {
            return $validated_settings;
        }

        foreach ($validated_settings as $key => $value) {
            $option_name = 'bjlg_' . $key . '_settings';
            update_option($option_name, $value);
        }

        BJLG_History::log('settings_updated', 'success', 'Settings updated via API');

        return rest_ensure_response([
            'success' => true,
            'message' => 'Settings updated successfully'
        ]);
    }
    
    /**
     * Endpoint : Obtenir les planifications
     */
    public function get_schedules($request) {
        $schedule = get_option('bjlg_schedule_settings', []);

        if (!is_array($schedule)) {
            $schedule = [];
        }

        $schedule = wp_parse_args($schedule, [
            'recurrence' => 'disabled',
        ]);

        $recurrence = $schedule['recurrence'];
        $next_run = wp_next_scheduled(BJLG_Scheduler::SCHEDULE_HOOK);

        return rest_ensure_response([
            'current_schedule' => $schedule,
            'next_run' => $next_run ? date('c', $next_run) : null,
            'enabled' => $recurrence !== 'disabled'
        ]);
    }
    
    /**
     * Endpoint : Créer une planification
     */
    public function create_schedule($request) {
        $params = $request->get_json_params();

        if (!is_array($params) || empty($params)) {
            return new WP_Error(
                'invalid_payload',
                __('The request payload must be a JSON object with schedule data.', 'backup-jlg'),
                ['status' => 400]
            );
        }

        $validated_schedule = $this->validate_schedule_settings($params);

        if (is_wp_error($validated_schedule)) {
            return $validated_schedule;
        }

        update_option('bjlg_schedule_settings', $validated_schedule);

        // Réinitialiser la planification
        $scheduler = new BJLG_Scheduler();
        $scheduler->check_schedule();

        return rest_ensure_response([
            'success' => true,
            'message' => 'Schedule created successfully'
        ]);
    }
    
    /**
     * Endpoint : Obtenir le statut d'une tâche
     */
    public function get_task_status($request) {
        $task_id = $request->get_param('id');
        $task_data = get_transient($task_id);
        
        if (!$task_data) {
            return new WP_Error(
                'task_not_found',
                'Task not found or expired',
                ['status' => 404]
            );
        }
        
        return rest_ensure_response($task_data);
    }
    
    /**
     * Formate les données d'une sauvegarde
     */
    private function format_backup_data($filepath) {
        $filename = basename($filepath);
        $is_encrypted = (substr($filename, -4) === '.enc');
        
        $type = 'standard';
        if (strpos($filename, 'full') !== false) {
            $type = 'full';
        } elseif (strpos($filename, 'incremental') !== false) {
            $type = 'incremental';
        } elseif (strpos($filename, 'pre-restore') !== false) {
            $type = 'pre-restore';
        }
        
        $manifest = $this->get_backup_manifest($filepath);
        
        $download_token = wp_generate_password(32, false);
        $transient_key = 'bjlg_download_' . $download_token;

        set_transient($transient_key, $filepath, BJLG_Backup::get_task_ttl());

        $download_url = add_query_arg([
            'action' => 'bjlg_download',
            'token' => $download_token,
        ], admin_url('admin-ajax.php'));

        $rest_download_route = sprintf(
            '/%s/backups/%s/download',
            self::API_NAMESPACE,
            rawurlencode($filename)
        );

        $rest_download_url = function_exists('rest_url')
            ? rest_url(ltrim($rest_download_route, '/'))
            : $rest_download_route;

        return [
            'id' => $filename,
            'filename' => $filename,
            'type' => $type,
            'size' => filesize($filepath),
            'size_formatted' => size_format(filesize($filepath)),
            'created_at' => date('c', filemtime($filepath)),
            'modified_at' => date('c', filemtime($filepath)),
            'is_encrypted' => $is_encrypted,
            'components' => $manifest['contains'] ?? [],
            'download_url' => $download_url,
            'download_token' => $download_token,
            'download_expires_in' => BJLG_Backup::get_task_ttl(),
            'download_rest_url' => $rest_download_url,
            'manifest' => $manifest
        ];
    }
    
    /**
     * Obtient le manifeste d'une sauvegarde
     */
    private function get_backup_manifest($filepath) {
        if (!file_exists($filepath)) {
            return null;
        }
        
        $zip = new ZipArchive();
        if ($zip->open($filepath) !== TRUE) {
            return null;
        }
        
        $manifest_json = $zip->getFromName('backup-manifest.json');
        $zip->close();
        
        if ($manifest_json) {
            return json_decode($manifest_json, true);
        }
        
        return null;
    }
    
    /**
     * Trie les fichiers selon le critère
     */
    private function sort_files(&$files, $sort) {
        switch ($sort) {
            case 'date_asc':
                usort($files, function($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                break;
            case 'date_desc':
                usort($files, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                break;
            case 'size_asc':
                usort($files, function($a, $b) {
                    return filesize($a) - filesize($b);
                });
                break;
            case 'size_desc':
                usort($files, function($a, $b) {
                    return filesize($b) - filesize($a);
                });
                break;
        }
    }
    
    /**
     * Obtient la taille totale des sauvegardes
     */
    private function get_total_backup_size() {
        $total = 0;
        $files = glob(BJLG_BACKUP_DIR . '*.zip*');
        
        foreach ($files as $file) {
            $total += filesize($file);
        }
        
        return $total;
    }
    
    /**
     * Obtient le nombre de tâches actives
     */
    private function get_active_tasks_count() {
        global $wpdb;
        
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_bjlg_backup_%' 
                OR option_name LIKE '_transient_bjlg_restore_%'"
        );
        
        return intval($count);
    }
    
    /**
     * Obtient la liste des endpoints disponibles
     */
    private function get_available_endpoints() {
        return [
            'GET /info' => 'Get API information',
            'POST /auth' => 'Authenticate and get token',
            'GET /backups' => 'List all backups',
            'POST /backups' => 'Create new backup',
            'GET /backups/{id}' => 'Get backup details',
            'DELETE /backups/{id}' => 'Delete backup',
            'GET /backups/{id}/download' => 'Download backup',
            'POST /backups/{id}/restore' => 'Restore backup',
            'GET /status' => 'Get system status',
            'GET /health' => 'Get system health',
            'GET /stats' => 'Get statistics',
            'GET /history' => 'Get activity history',
            'GET /settings' => 'Get current settings',
            'PUT /settings' => 'Update settings',
            'GET /schedules' => 'Get backup schedules',
            'POST /schedules' => 'Create backup schedule',
            'GET /tasks/{id}' => 'Get task status'
        ];
    }
}
