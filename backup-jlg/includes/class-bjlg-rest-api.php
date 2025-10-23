<?php
namespace BJLG;

/**
 * API REST pour Backup JLG
 * Fichier : includes/class-bjlg-rest-api.php
 */

use Exception;
use WP_Error;
use ZipArchive;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-bjlg-backup-path-resolver.php';
require_once __DIR__ . '/class-bjlg-restore.php';
require_once __DIR__ . '/class-bjlg-settings.php';

class BJLG_REST_API {
    
    const API_NAMESPACE = 'backup-jlg/v1';
    const API_VERSION = '1.0.0';
    const API_KEY_STATS_TRANSIENT_PREFIX = 'bjlg_api_key_stats_';
    const API_KEYS_LAST_PERSIST_TRANSIENT = 'bjlg_api_keys_last_persist';
    
    private $rate_limiter;

    /** @var BJLG_Settings|null */
    private $settings_manager;
    
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('pre_update_option_bjlg_api_keys', [$this, 'filter_api_keys_before_save'], 10, 3);
        add_action('add_option_bjlg_api_keys', [$this, 'handle_api_keys_added'], 10, 2);

        // Initialiser le rate limiter
        if (class_exists(BJLG_Rate_Limiter::class)) {
            $this->rate_limiter = new BJLG_Rate_Limiter();
        }

        if (class_exists(BJLG_Settings::class)) {
            $this->settings_manager = BJLG_Settings::get_instance();
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
            'permission_callback' => [$this, 'check_auth_permissions'],
            'args' => $this->merge_site_args([
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
            ])
        ]);
        
        // Routes : Gestion des sauvegardes
        register_rest_route(self::API_NAMESPACE, '/backups', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_backups'],
                'permission_callback' => [$this, 'check_permissions'],
                'args' => $this->merge_site_args([
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
                    ],
                    'with_token' => [
                        'required' => false,
                        'type' => 'boolean'
                    ]
                ])
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create_backup'],
                'permission_callback' => [$this, 'check_permissions'],
                'args' => $this->merge_site_args([
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
                ])
            ]
        ]);
        
        // Routes : Opérations sur une sauvegarde spécifique
        register_rest_route(self::API_NAMESPACE, '/backups/(?P<id>[A-Za-z0-9._-]+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_backup'],
                'permission_callback' => [$this, 'check_permissions'],
                'args' => $this->merge_site_args([
                    'with_token' => [
                        'required' => false,
                        'type' => 'boolean'
                    ]
                ])
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_backup'],
                'permission_callback' => [$this, 'check_permissions'],
                'args' => $this->merge_site_args(),
            ]
        ]);

        // Route : Télécharger une sauvegarde
        register_rest_route(self::API_NAMESPACE, '/backups/(?P<id>[A-Za-z0-9._-]+)/download', [
            'methods' => 'GET',
            'callback' => [$this, 'download_backup'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => $this->merge_site_args([
                'token' => [
                    'required' => false,
                    'type' => 'string'
                ]
            ])
        ]);

        // Route : Restaurer une sauvegarde
        register_rest_route(self::API_NAMESPACE, '/backups/(?P<id>[A-Za-z0-9._-]+)/restore', [
            'methods' => 'POST',
            'callback' => [$this, 'restore_backup'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => $this->merge_site_args([
                'components' => [
                    'type' => 'array',
                    'default' => ['all']
                ],
                'create_restore_point' => [
                    'type' => 'boolean',
                    'default' => true
                ],
                'restore_environment' => [
                    'type' => 'string',
                    'default' => BJLG_Restore::ENV_PRODUCTION,
                ],
                'sandbox_path' => [
                    'type' => 'string',
                    'required' => false,
                ],
                'password' => [
                    'type' => 'string',
                    'required' => false
                ]
            ])
        ]);

        // Routes : Statut et monitoring
        register_rest_route(self::API_NAMESPACE, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_status'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => $this->merge_site_args(),
        ]);

        register_rest_route(self::API_NAMESPACE, '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'get_health'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => $this->merge_site_args(),
        ]);

        // Routes : Statistiques
        register_rest_route(self::API_NAMESPACE, '/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => $this->merge_site_args([
                'period' => [
                    'default' => 'week',
                    'enum' => ['day', 'week', 'month', 'year']
                ]
            ])
        ]);
        
        // Routes : Historique
        register_rest_route(self::API_NAMESPACE, '/history', [
            'methods' => 'GET',
            'callback' => [$this, 'get_history'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => $this->merge_site_args([
                'limit' => [
                    'default' => 50,
                    'validate_callback' => function($param) {
                        $value = filter_var(
                            $param,
                            FILTER_VALIDATE_INT,
                            [
                                'options' => [
                                    'min_range' => 1,
                                    'max_range' => 500,
                                ],
                            ]
                        );

                        if ($value === false) {
                            return new WP_Error(
                                'rest_invalid_param',
                                __('Le paramètre limit doit être un entier compris entre 1 et 500.', 'backup-jlg'),
                                ['status' => 400]
                            );
                        }

                        return true;
                    }
                ],
                'action' => [
                    'type' => 'string'
                ],
                'status' => [
                    'enum' => ['success', 'failure', 'info']
                ]
            ])
        ]);

        // Routes : Configuration
        register_rest_route(self::API_NAMESPACE, '/settings', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_settings'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args' => $this->merge_site_args(),
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'update_settings'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args' => $this->merge_site_args(),
            ]
        ]);

        // Routes : Planification
        register_rest_route(self::API_NAMESPACE, '/schedules', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_schedules'],
                'permission_callback' => [$this, 'check_permissions'],
                'args' => $this->merge_site_args(),
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create_schedule'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args' => $this->merge_site_args(),
            ]
        ]);

        // Route : Tâches
        register_rest_route(self::API_NAMESPACE, '/tasks/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_task_status'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => $this->merge_site_args(),
        ]);
    }

    /**
     * Vérification des permissions pour l'authentification
     */
    public function check_auth_permissions($request) {
        if ($this->rate_limiter && !$this->rate_limiter->check($request)) {
            return new WP_Error(
                'rate_limit_exceeded',
                __('Trop de requêtes. Veuillez patienter.', 'backup-jlg'),
                ['status' => 429]
            );
        }

        return true;
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

        $result = $this->with_request_site($request, function () use ($request) {
            // Vérifier l'authentification via API Key
            $api_key = $request->get_header('X-API-Key');
            if ($api_key) {
                $verified_user = $this->verify_api_key($api_key);

                if (is_wp_error($verified_user)) {
                    return $verified_user;
                }

                if (!$verified_user) {
                    return false;
                }

                if (function_exists('wp_set_current_user') && is_object($verified_user) && isset($verified_user->ID)) {
                    wp_set_current_user((int) $verified_user->ID);
                }

                return true;
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
            return \bjlg_can_manage_backups();
        });

        return $result;
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

        return $this->with_request_site($request, function () {
            return \bjlg_can_manage_settings();
        });
    }

    /**
     * Étend les arguments REST avec la sélection de site.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function merge_site_args(array $args = []): array {
        $args['context'] = [
            'required' => false,
            'sanitize_callback' => static function ($param) {
                if (!is_string($param)) {
                    return null;
                }

                $value = strtolower($param);

                return in_array($value, ['site', 'network'], true) ? $value : null;
            },
            'validate_callback' => static function ($param) {
                if ($param === null || $param === '' || $param === false) {
                    return true;
                }

                if (!is_string($param)) {
                    return new WP_Error(
                        'bjlg_invalid_context',
                        __('Le paramètre context doit être une chaîne de caractères.', 'backup-jlg'),
                        ['status' => 400]
                    );
                }

                $value = strtolower($param);
                if (!in_array($value, ['site', 'network'], true)) {
                    return new WP_Error(
                        'bjlg_invalid_context',
                        __('Le paramètre context doit être "site" ou "network".', 'backup-jlg'),
                        ['status' => 400]
                    );
                }

                return true;
            },
        ];

        $args['site_id'] = [
            'required' => false,
            'sanitize_callback' => static function ($param) {
                if ($param === null || $param === '' || $param === false) {
                    return null;
                }

                $value = absint($param);

                return $value > 0 ? $value : null;
            },
            'validate_callback' => static function ($param) {
                if ($param === null || $param === '' || $param === false) {
                    return true;
                }

                if (!is_numeric($param) || absint($param) <= 0) {
                    return new WP_Error(
                        'bjlg_invalid_site_id',
                        __('Le paramètre site_id doit être un entier positif.', 'backup-jlg'),
                        ['status' => 400]
                    );
                }

                return true;
            },
        ];

        return $args;
    }

    /**
     * Détermine l'identifiant de site fourni dans la requête.
     *
     * @param \WP_REST_Request $request
     */
    private function get_requested_site_id($request) {
        if ($this->get_requested_context($request) === 'network') {
            return null;
        }

        $site_id = $request->get_param('site_id');

        if (($site_id === null || $site_id === '' || $site_id === false) && method_exists($request, 'get_header')) {
            $header_value = $request->get_header('X-WP-Site');
            if ($header_value !== null && $header_value !== '' && $header_value !== false) {
                $site_id = $header_value;
            }
        }

        if ($site_id === null || $site_id === '' || $site_id === false) {
            return null;
        }

        $site_id = absint($site_id);

        return $site_id > 0 ? $site_id : null;
    }

    /**
     * Détermine le contexte ciblé par la requête (site ou réseau).
     */
    private function get_requested_context($request): string
    {
        $context = null;

        if (method_exists($request, 'get_param')) {
            $context = $request->get_param('context');
        }

        if (($context === null || $context === '' || $context === false) && method_exists($request, 'get_header')) {
            $header_value = $request->get_header('X-WP-Context');
            if ($header_value !== null && $header_value !== '' && $header_value !== false) {
                $context = $header_value;
            }
        }

        if (!is_string($context)) {
            return 'site';
        }

        $value = strtolower($context);

        return $value === 'network' ? 'network' : 'site';
    }

    /**
     * Exécute un callback dans le contexte multisite demandé.
     *
     * @param callable $callback
     *
     * @return mixed
     */
    private function with_request_site($request, callable $callback) {
        $context = $this->get_requested_context($request);

        if ($context === 'network') {
            if (!function_exists('is_multisite') || !is_multisite()) {
                return new WP_Error(
                    'bjlg_network_context_unavailable',
                    __('Le contexte réseau n’est pas disponible sur cette installation.', 'backup-jlg'),
                    ['status' => 400]
                );
            }

            if ($this->get_requested_site_id($request)) {
                return new WP_Error(
                    'bjlg_context_conflict',
                    __('Impossible de combiner un identifiant de site avec le contexte réseau.', 'backup-jlg'),
                    ['status' => 400]
                );
            }

            $has_network_access = \bjlg_with_network(static function () {
                return \bjlg_can_manage_plugin();
            });

            if (!$has_network_access) {
                return new WP_Error(
                    'bjlg_network_forbidden',
                    __('Vous n’avez pas les permissions requises pour gérer le réseau.', 'backup-jlg'),
                    ['status' => 403]
                );
            }

            return \bjlg_with_network(static function () use ($callback) {
                return $callback();
            });
        }

        $site_id = $this->get_requested_site_id($request);

        if (!$site_id) {
            return $callback();
        }

        if (!function_exists('is_multisite') || !is_multisite()) {
            return new WP_Error(
                'bjlg_multisite_required',
                __('Le paramètre site_id nécessite un réseau multisite.', 'backup-jlg'),
                ['status' => 400]
            );
        }

        if (function_exists('get_site') && !get_site($site_id)) {
            return new WP_Error(
                'bjlg_site_not_found',
                __('Le site demandé est introuvable.', 'backup-jlg'),
                ['status' => 404]
            );
        }

        return \bjlg_with_site($site_id, static function () use ($callback) {
            return $callback();
        });
    }
    
    /**
     * Vérifie une clé API
     */
    private function verify_api_key($api_key) {
        $stored_keys = \bjlg_get_option('bjlg_api_keys', []);

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

            $stats_storage_key = $this->get_api_key_stats_storage_key($stored_value);
            $force_persist = false;

            if (isset($key_data['expires']) && $key_data['expires'] < time()) {
                $this->remove_api_key_entry($stored_keys, $index, true);
                unset($key_data);
                return false;
            }

            if ($needs_rehash) {
                $new_hash = $this->hash_api_key($api_key);
                $new_stats_key = $this->get_api_key_stats_storage_key($new_hash);
                $this->migrate_api_key_stats($stats_storage_key, $new_stats_key);
                $key_data['key'] = $new_hash;
                $stats_storage_key = $new_stats_key;
                $force_persist = true;
            }

            $resolved_user_id = $this->resolve_api_key_user_id($key_data);

            if ($resolved_user_id <= 0) {
                $this->remove_api_key_entry($stored_keys, $index, true);
                unset($key_data);

                return new WP_Error(
                    'api_key_missing_user',
                    __('Cette clé API n\'est associée à aucun utilisateur.', 'backup-jlg'),
                    ['status' => 403]
                );
            }

            $user = get_user_by('id', $resolved_user_id);

            if (!$user) {
                $this->remove_api_key_entry($stored_keys, $index, true);
                unset($key_data);

                return new WP_Error(
                    'api_key_user_not_found',
                    __('L\'utilisateur associé à cette clé API est introuvable.', 'backup-jlg'),
                    ['status' => 403]
                );
            }

            if (!\bjlg_can_manage_backups($user)) {
                $this->remove_api_key_entry($stored_keys, $index, true);
                unset($key_data);

                return new WP_Error(
                    'api_key_insufficient_permissions',
                    __('Les permissions de l\'utilisateur lié à cette clé API sont insuffisantes.', 'backup-jlg'),
                    ['status' => 403]
                );
            }

            $existing_usage_data = false;

            if (isset($key_data['usage_count']) || isset($key_data['last_used'])) {
                $existing_usage_data = [
                    'usage_count' => isset($key_data['usage_count']) ? (int) $key_data['usage_count'] : 0,
                    'last_used' => isset($key_data['last_used']) ? (int) $key_data['last_used'] : 0,
                ];
                $force_persist = true;
            }

            unset($key_data['usage_count'], $key_data['last_used']);

            $stats = $this->get_api_key_stats($stats_storage_key, $existing_usage_data);
            $stats['usage_count'] = isset($stats['usage_count']) ? (int) $stats['usage_count'] + 1 : 1;
            $stats['last_used'] = time();
            $this->set_api_key_stats($stats_storage_key, $stats);

            $current_user_id = (int) $user->ID;

            if (!isset($key_data['user_id']) || (int) $key_data['user_id'] !== $current_user_id) {
                $force_persist = true;
            }

            $key_data['user_id'] = $current_user_id;

            $new_roles = $this->extract_roles_for_key($key_data, $user);
            $existing_roles = isset($key_data['roles']) && is_array($key_data['roles']) ? array_values($key_data['roles']) : [];

            if ($new_roles !== $existing_roles) {
                $force_persist = true;
            }

            $key_data['roles'] = $new_roles;
            $stored_keys[$index] = $key_data;
            $this->maybe_persist_api_keys($stored_keys, $force_persist);

            if (function_exists('wp_set_current_user')) {
                wp_set_current_user($current_user_id);
            }

            unset($key_data);
            return $user;
        }

        unset($key_data);
        return false;
    }

    /**
     * Supprime une entrée de clé API et persiste la mise à jour.
     *
     * @param array $stored_keys
     * @param int   $index
     */
    private function remove_api_key_entry(array &$stored_keys, $index, $force_persist = false) {
        if (!isset($stored_keys[$index])) {
            return;
        }

        $entry = $stored_keys[$index];
        $stats_key = '';

        if (is_array($entry) && isset($entry['key'])) {
            $stats_key = $this->get_api_key_stats_storage_key($entry['key']);
        }

        if ($stats_key !== '') {
            $this->delete_api_key_stats($stats_key);
        }

        unset($stored_keys[$index]);
        $stored_keys = array_values($stored_keys);
        $this->maybe_persist_api_keys($stored_keys, $force_persist);
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

    private function maybe_persist_api_keys(array $keys, $force = false) {
        $prepared_keys = $this->strip_ephemeral_fields_from_keys($keys);

        if (!$force && !$this->should_persist_api_keys()) {
            return;
        }

        \bjlg_update_option('bjlg_api_keys', $prepared_keys);
        set_transient(self::API_KEYS_LAST_PERSIST_TRANSIENT, time(), 0);
    }

    private function sanitize_api_keys($keys) {
        if (!is_array($keys)) {
            return $keys;
        }

        foreach ($keys as $index => $key_data) {
            if (!is_array($key_data)) {
                unset($keys[$index]);
                continue;
            }

            if (isset($key_data['key']) && !$this->is_api_key_hashed($key_data['key'])) {
                $keys[$index]['key'] = $this->hash_api_key($key_data['key']);
            }

            unset($keys[$index]['usage_count'], $keys[$index]['last_used']);

            foreach (['plain_key', 'raw_key', 'display_key', 'api_key_plain', 'api_key_plaintext', 'display_secret', 'secret', 'masked_secret'] as $sensitive_field) {
                if (isset($keys[$index][$sensitive_field])) {
                    unset($keys[$index][$sensitive_field]);
                }
            }

            $user_id = $this->resolve_api_key_user_id($keys[$index]);

            if ($user_id <= 0) {
                unset($keys[$index]);
                continue;
            }

            $keys[$index]['user_id'] = $user_id;
            $user = get_user_by('id', $user_id);

            $user_login = isset($keys[$index]['user_login']) ? sanitize_text_field((string) $keys[$index]['user_login']) : '';
            $user_email = '';

            if (isset($keys[$index]['user_email'])) {
                if (function_exists('sanitize_email')) {
                    $user_email = sanitize_email((string) $keys[$index]['user_email']);
                } else {
                    $user_email = sanitize_text_field((string) $keys[$index]['user_email']);
                }
            }

            if ($user) {
                if (isset($user->user_login)) {
                    $user_login = sanitize_text_field((string) $user->user_login);
                }

                if (isset($user->user_email)) {
                    if (function_exists('sanitize_email')) {
                        $user_email = sanitize_email((string) $user->user_email);
                    } else {
                        $user_email = sanitize_text_field((string) $user->user_email);
                    }
                }
            }

            $keys[$index]['user_login'] = $user_login;
            $keys[$index]['user_email'] = $user_email;
            $keys[$index]['roles'] = $this->extract_roles_for_key($keys[$index], $user);
        }

        return array_values($keys);
    }

    private function strip_ephemeral_fields_from_keys(array $keys) {
        foreach ($keys as $index => $data) {
            if (!is_array($data)) {
                continue;
            }

            unset($keys[$index]['usage_count'], $keys[$index]['last_used']);
        }

        return array_values($keys);
    }

    private function should_persist_api_keys() {
        $last_persist = get_transient(self::API_KEYS_LAST_PERSIST_TRANSIENT);
        $interval = $this->get_api_keys_persist_interval();

        if ($interval <= 0) {
            return true;
        }

        if ($last_persist === false) {
            return true;
        }

        return (time() - (int) $last_persist) >= $interval;
    }

    private function get_api_keys_persist_interval() {
        $default_interval = defined('MINUTE_IN_SECONDS') ? 5 * MINUTE_IN_SECONDS : 300;

        return (int) apply_filters('bjlg_api_keys_persist_interval', $default_interval);
    }

    /**
     * Récupère les statistiques d'utilisation d'une clé API.
     *
     * Lorsque des données d'usage historiques sont détectées dans l'option (legacy
     * usage_count/last_used), elles sont migrées vers le transient dédié afin de
     * conserver la rétrocompatibilité sans multiplier les écritures de l'option.
     *
     * @param string $storage_key
     * @param array|false $fallback
     * @return array{usage_count?:int,last_used?:int}
     */
    private function get_api_key_stats($storage_key, $fallback = false) {
        if (!is_string($storage_key) || $storage_key === '') {
            return is_array($fallback) ? $fallback : [];
        }

        $stats = get_transient($storage_key);

        if ($stats !== false && is_array($stats)) {
            return $stats;
        }

        if (is_array($fallback) && (array_key_exists('usage_count', $fallback) || array_key_exists('last_used', $fallback))) {
            $this->set_api_key_stats($storage_key, $fallback);
            return $fallback;
        }

        return is_array($fallback) ? $fallback : [];
    }

    private function set_api_key_stats($storage_key, array $stats) {
        if (!is_string($storage_key) || $storage_key === '') {
            return;
        }

        set_transient($storage_key, [
            'usage_count' => isset($stats['usage_count']) ? (int) $stats['usage_count'] : 0,
            'last_used' => isset($stats['last_used']) ? (int) $stats['last_used'] : 0,
        ], 0);
    }

    private function delete_api_key_stats($storage_key) {
        if (!is_string($storage_key) || $storage_key === '') {
            return;
        }

        delete_transient($storage_key);
    }

    private function migrate_api_key_stats($old_storage_key, $new_storage_key) {
        if ($old_storage_key === $new_storage_key || $new_storage_key === '') {
            return;
        }

        $old_stats = $this->get_api_key_stats($old_storage_key, []);

        if (empty($old_stats)) {
            return;
        }

        $this->set_api_key_stats($new_storage_key, $old_stats);
        $this->delete_api_key_stats($old_storage_key);
    }

    private function get_api_key_stats_storage_key($stored_value) {
        if (!is_string($stored_value) || $stored_value === '') {
            return '';
        }

        return self::API_KEY_STATS_TRANSIENT_PREFIX . md5($stored_value);
    }

    private function resolve_api_key_user_id($key_data) {
        if (!is_array($key_data)) {
            return 0;
        }

        $candidates = [];

        if (isset($key_data['user_id'])) {
            $candidates[] = $key_data['user_id'];
        }

        foreach (['user', 'user_login', 'username'] as $field) {
            if (isset($key_data[$field])) {
                $candidates[] = $key_data[$field];
            }
        }

        if (isset($key_data['user_email'])) {
            $candidates[] = $key_data['user_email'];
        }

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                $user = get_user_by('id', (int) $candidate);

                if ($user) {
                    return (int) $user->ID;
                }
            }

            if (!is_string($candidate)) {
                continue;
            }

            $clean_candidate = sanitize_text_field($candidate);

            if ($clean_candidate === '') {
                continue;
            }

            $user = get_user_by('login', $clean_candidate);

            if ($user) {
                return (int) $user->ID;
            }

            $user = get_user_by('email', $clean_candidate);

            if ($user) {
                return (int) $user->ID;
            }
        }

        return 0;
    }

    private function extract_roles_for_key($key_data, $user) {
        $roles = [];

        if (is_object($user) && isset($user->roles) && is_array($user->roles)) {
            foreach ($user->roles as $role) {
                if (!is_string($role) || $role === '') {
                    continue;
                }

                $roles[] = sanitize_key($role);
            }
        }

        if (empty($roles) && is_array($key_data) && isset($key_data['roles']) && is_array($key_data['roles'])) {
            foreach ($key_data['roles'] as $role) {
                if (!is_string($role) || $role === '') {
                    continue;
                }

                $roles[] = sanitize_key($role);
            }
        }

        return array_values(array_unique($roles));
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

        $has_capability = \bjlg_can_manage_backups($user);

        if (!$has_capability) {
            return new WP_Error(
                'jwt_insufficient_permissions',
                __('Les permissions de cet utilisateur ne sont plus valides.', 'backup-jlg'),
                ['status' => 403]
            );
        }

        if (function_exists('wp_set_current_user')) {
            wp_set_current_user((int) $user->ID);
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
            'documentation' => admin_url('admin.php?page=backup-jlg&section=integrations')
        ]);
    }
    
    /**
     * Endpoint : Authentification
     */
    public function authenticate($request) {
        return $this->with_request_site($request, function () use ($request) {
            $username = $request->get_param('username');
            $password = $request->get_param('password');
            $api_key = $request->get_param('api_key');

            // Authentification par API Key
            if ($api_key) {
                $verified_user = $this->verify_api_key($api_key);

                if (is_wp_error($verified_user)) {
                    return $verified_user;
                }

                if (!is_object($verified_user) || !isset($verified_user->ID)) {
                    return new WP_Error(
                        'api_key_missing_user',
                        __('Cette clé API n\'est associée à aucun utilisateur.', 'backup-jlg'),
                        ['status' => 403]
                    );
                }

                $user = $verified_user;

                if (!$user) {
                    return new WP_Error(
                        'user_not_found',
                        'User not found',
                        ['status' => 404]
                    );
                }

                if ($username && strcasecmp($username, (string) $user->user_login) !== 0) {
                    return new WP_Error(
                        'api_key_user_mismatch',
                        __('Cette clé API ne peut pas être utilisée pour cet utilisateur.', 'backup-jlg'),
                        ['status' => 403]
                    );
                }

                if (!\bjlg_can_manage_backups($user)) {
                    return new WP_Error(
                        'insufficient_permissions',
                        'User does not have backup permissions',
                        ['status' => 403]
                    );
                }

                $token = $this->generate_jwt_token($user->ID, $user->user_login);

                if (is_wp_error($token)) {
                    return $token;
                }

                return rest_ensure_response([
                    'success' => true,
                    'message' => 'Authentication successful',
                    'token' => $token,
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

            if (!\bjlg_can_manage_backups($user)) {
                return new WP_Error(
                    'insufficient_permissions',
                    'User does not have backup permissions',
                    ['status' => 403]
                );
            }

            $token = $this->generate_jwt_token($user->ID, $user->user_login);

            if (is_wp_error($token)) {
                return $token;
            }

            return rest_ensure_response([
                'success' => true,
                'message' => 'Authentication successful',
                'token' => $token,
                'user' => [
                    'id' => $user->ID,
                    'username' => $user->user_login,
                    'email' => $user->user_email
                ]
            ]);
        });
    }
    
    /**
     * Génère un token JWT
     */
    private function generate_jwt_token($user_id, $username) {
        $auth_key = defined('AUTH_KEY') ? trim((string) AUTH_KEY) : '';

        if ($auth_key === '') {
            if (function_exists('error_log')) {
                error_log('[Backup JLG] AUTH_KEY is missing or empty; unable to generate JWT token.');
            }

            return new WP_Error(
                'jwt_missing_signing_key',
                __('La clé AUTH_KEY est manquante; impossible de générer un token JWT.', 'backup-jlg'),
                ['status' => 500]
            );
        }

        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => (int) $user_id,
            'username' => $username,
            'exp' => time() + (7 * DAY_IN_SECONDS),
            'iat' => time()
        ]);
        
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, $auth_key, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
    }
    
    /**
     * Endpoint : Liste des sauvegardes
     */
    public function get_backups($request) {
        return $this->with_request_site($request, function () use ($request) {
            $page = $request->get_param('page');
            $per_page = $request->get_param('per_page');
            $type = $request->get_param('type');
            $sort = $request->get_param('sort');
            $with_token = $this->interpret_boolean($request->get_param('with_token'));

            if ($with_token === null) {
                $with_token = false;
            }

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

            $manifests_cache = [];

            // Déterminer les composants associés à certains filtres "type"
            $component_filters = [];
            if ($type === 'database') {
                $component_filters = ['db'];
            } elseif ($type === 'files') {
                $component_filters = ['plugins', 'themes', 'uploads'];
            }

            // Filtrer par type
            if ($type !== 'all') {
                $files = array_filter($files, function ($file) use ($type, $component_filters, &$manifests_cache) {
                    $manifest = $this->get_backup_manifest($file);

                    if ($manifest !== null) {
                        $manifests_cache[$file] = $manifest;
                    }

                    if (!empty($component_filters)) {
                        $contains = [];
                        $has_manifest_components = false;
                        if (is_array($manifest) && isset($manifest['contains']) && is_array($manifest['contains'])) {
                            $contains = $manifest['contains'];
                            $has_manifest_components = true;
                        }

                        if (!empty(array_intersect($component_filters, $contains))) {
                            return true;
                        }

                        if ($has_manifest_components) {
                            return false;
                        }

                        $filename = basename($file);

                        // Vérifier également les conventions de nommage historiques
                        $aliases = $component_filters;
                        if ($type === 'database') {
                            $aliases[] = 'database';
                        } elseif ($type === 'files') {
                            $aliases[] = 'files';
                        }

                        foreach (array_unique($aliases) as $component) {
                            if (strpos($filename, $component) !== false) {
                                return true;
                            }
                        }

                        return false;
                    }

                    return $this->backup_matches_type($file, $type, $manifest);
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
                $manifest = $manifests_cache[$file] ?? null;
                if ($manifest === null) {
                    $manifest = $this->get_backup_manifest($file);
                }

                $backups[] = $this->format_backup_data($file, $manifest, (bool) $with_token);
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
        });
    }
    
    /**
     * Endpoint : Créer une sauvegarde
     */
    public function create_backup($request) {
        return $this->with_request_site($request, function () use ($request) {
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
            $sanitized_description = '';

            if (is_scalar($description)) {
                $raw_description = (string) $description;

                if (function_exists('sanitize_text_field')) {
                    $raw_description = sanitize_text_field($raw_description);
                }

                $max_description_length = 255;

                if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                    if (mb_strlen($raw_description, 'UTF-8') > $max_description_length) {
                        $raw_description = mb_substr($raw_description, 0, $max_description_length, 'UTF-8');
                    }
                } elseif (strlen($raw_description) > $max_description_length) {
                    $raw_description = substr($raw_description, 0, $max_description_length);
                }

                $sanitized_description = $raw_description;
            }

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
                'description' => $sanitized_description,
                'source' => 'api',
                'start_time' => time()
            ];

            if (!BJLG_Backup::reserve_task_slot($task_id)) {
                return new WP_Error(
                    'backup_in_progress',
                    __('Une sauvegarde est déjà en cours. Réessayez ultérieurement.', 'backup-jlg'),
                    ['status' => 409]
                );
            }

            $task_initialized = BJLG_Backup::save_task_state($task_id, $task_data);

            if (!$task_initialized) {
                BJLG_Backup::release_task_slot($task_id);

                return new WP_Error(
                    'task_initialization_failed',
                    __('Impossible d\'initialiser la sauvegarde. Veuillez réessayer.', 'backup-jlg'),
                    ['status' => 500]
                );
            }

            // Planifier l'exécution
            $scheduled = wp_schedule_single_event(time(), 'bjlg_run_backup_task', ['task_id' => $task_id]);

            if ($scheduled === false) {
                BJLG_Backup::delete_task_state($task_id);
                BJLG_Backup::release_task_slot($task_id);

                return new WP_Error(
                    'schedule_failed',
                    __('Impossible de planifier la tâche de sauvegarde en arrière-plan.', 'backup-jlg'),
                    ['status' => 500]
                );
            }

            BJLG_History::log('api_backup_created', 'info', 'Backup initiated via API');

            return rest_ensure_response([
                'success' => true,
                'task_id' => $task_id,
                'message' => 'Backup task created successfully',
                'status_url' => rest_url(self::API_NAMESPACE . '/tasks/' . $task_id)
            ]);
        });
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
                    if (!is_wp_error($validated_value)) {
                        $validated_value = $this->sanitize_setting_section($key, $validated_value);
                    }
                    break;
                case 'schedule':
                    $validated_value = $this->validate_schedule_settings($value);
                    if (!is_wp_error($validated_value)) {
                        $validated_value = $this->sanitize_setting_section($key, $validated_value);
                    }
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

        $sanitized = $this->sanitize_setting_section($key, $value);

        if (is_wp_error($sanitized)) {
            return $sanitized;
        }

        return $sanitized;
    }

    private function sanitize_setting_section($section, array $value) {
        $settings_manager = $this->get_settings_manager();

        if ($settings_manager === null) {
            return new WP_Error(
                'settings_sanitizer_unavailable',
                __('The settings sanitizer is not available.', 'backup-jlg'),
                ['status' => 500]
            );
        }

        $sanitized = $settings_manager->sanitize_settings_section($section, $value);

        if ($sanitized === null) {
            return new WP_Error(
                'invalid_setting_key',
                sprintf(__('The "%s" setting cannot be updated via the REST API.', 'backup-jlg'), $section),
                ['status' => 400]
            );
        }

        return $sanitized;
    }

    private function get_settings_manager() {
        if ($this->settings_manager instanceof BJLG_Settings) {
            return $this->settings_manager;
        }

        if (!class_exists(BJLG_Settings::class)) {
            return null;
        }

        $this->settings_manager = BJLG_Settings::get_instance();

        return $this->settings_manager;
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

        if (!class_exists(BJLG_Settings::class)) {
            return new WP_Error(
                'settings_sanitizer_unavailable',
                __('The schedule sanitizer is not available.', 'backup-jlg'),
                ['status' => 500]
            );
        }

        $entries = [];

        if (isset($value['schedules']) && is_array($value['schedules'])) {
            $entries = array_values($value['schedules']);
        } else {
            $entries = [$value];
        }

        if (empty($entries)) {
            return new WP_Error(
                'invalid_schedule_settings',
                __('No schedule entries were provided.', 'backup-jlg'),
                ['status' => 400]
            );
        }

        $required_keys = ['recurrence', 'day', 'time', 'components', 'encrypt', 'incremental'];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                return new WP_Error(
                    'invalid_schedule_settings',
                    __('Each schedule entry must be a JSON object.', 'backup-jlg'),
                    ['status' => 400]
                );
            }

            foreach ($required_keys as $required_key) {
                if (!array_key_exists($required_key, $entry)) {
                    return new WP_Error(
                        'invalid_schedule_settings',
                        sprintf(__('Missing schedule setting "%s".', 'backup-jlg'), $required_key),
                        ['status' => 400]
                    );
                }
            }
        }

        $collection = BJLG_Settings::sanitize_schedule_collection(['schedules' => $entries]);

        if (empty($collection['schedules'])) {
            return new WP_Error(
                'invalid_schedule_settings',
                __('No valid schedule entry could be created from the payload.', 'backup-jlg'),
                ['status' => 400]
            );
        }

        foreach ($collection['schedules'] as $sanitized_entry) {
            if (($sanitized_entry['recurrence'] ?? '') === 'custom' && empty($sanitized_entry['custom_cron'])) {
                return new WP_Error(
                    'invalid_schedule_cron',
                    __('A valid Cron expression is required when using the custom recurrence.', 'backup-jlg'),
                    ['status' => 400]
                );
            }

            if (($sanitized_entry['recurrence'] ?? '') === 'custom') {
                $analysis = BJLG_Scheduler::analyze_custom_cron_expression($sanitized_entry['custom_cron']);
                if (is_wp_error($analysis)) {
                    $details = $analysis->get_error_data();
                    return new WP_Error(
                        'invalid_schedule_cron',
                        $analysis->get_error_message(),
                        [
                            'status' => 400,
                            'details' => isset($details['details']) ? (array) $details['details'] : [],
                        ]
                    );
                }

                if (!empty($analysis['errors'])) {
                    return new WP_Error(
                        'invalid_schedule_cron',
                        $analysis['errors'][0],
                        [
                            'status' => 400,
                            'details' => $analysis['errors'],
                        ]
                    );
                }
            }
        }

        return $collection;
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
        return $this->with_request_site($request, function () use ($request) {
            $filepath = BJLG_Backup_Path_Resolver::resolve($request->get_param('id'));

            if (is_wp_error($filepath)) {
                return $filepath;
            }

            $with_token = $this->interpret_boolean($request->get_param('with_token'));

            if ($with_token === null) {
                $with_token = false;
            }

            return rest_ensure_response($this->format_backup_data($filepath, null, (bool) $with_token));
        });
    }
    
    /**
     * Endpoint : Supprimer une sauvegarde
     */
    public function delete_backup($request) {
        return $this->with_request_site($request, function () use ($request) {
            $filepath = BJLG_Backup_Path_Resolver::resolve($request->get_param('id'));

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
        });
    }
    
    /**
     * Endpoint : Télécharger une sauvegarde
     */
    /**
     * Retourne une URL de téléchargement pour une sauvegarde.
     *
     * @param \WP_REST_Request $request
     * @return array{
     *     download_url: string,
     *     expires_in: int,
     *     download_token: string,
     *     filename: string,
     *     size: int
     * }|WP_Error URL publique générée via l'action AJAX bjlg_download.
     */
    public function download_backup($request) {
        return $this->with_request_site($request, function () use ($request) {
            $token = $request->get_param('token');

            $filepath = BJLG_Backup_Path_Resolver::resolve($request->get_param('id'));

            if (is_wp_error($filepath)) {
                return $filepath;
            }

            $download_token = null;
            $transient_ttl = BJLG_Actions::get_download_token_ttl($filepath);

            if (is_string($token)) {
                $token = trim($token);
            }

            if (!empty($token)) {
                $transient_key = 'bjlg_download_' . $token;
                $existing_payload = get_transient($transient_key);
                $existing_path = is_array($existing_payload) ? ($existing_payload['file'] ?? '') : $existing_payload;

                if (empty($existing_path)) {
                    return new WP_Error(
                        'bjlg_invalid_token',
                        __('Le token de téléchargement fourni est invalide ou expiré.', 'backup-jlg'),
                        ['status' => 404]
                    );
                }

                $normalized_existing = $this->normalize_backup_path($existing_path);
                $normalized_requested = $this->normalize_backup_path($filepath);

                if ($normalized_existing === null || $normalized_requested === null
                    || $normalized_existing !== $normalized_requested
                ) {
                    return new WP_Error(
                        'bjlg_invalid_token',
                        __('Le token fourni ne correspond pas à cette sauvegarde.', 'backup-jlg'),
                        ['status' => 403]
                    );
                }

                $base_payload = BJLG_Actions::build_download_token_payload($filepath);

                if (is_array($existing_payload)) {
                    $payload = $existing_payload;
                    $payload['file'] = $base_payload['file'];
                    $payload['requires_cap'] = $base_payload['requires_cap'];
                    $payload['issued_at'] = $base_payload['issued_at'];
                    $payload['issued_by'] = $base_payload['issued_by'];
                } else {
                    $payload = $base_payload;
                }
                $persisted = set_transient($transient_key, $payload, $transient_ttl);

                if ($persisted === false) {
                    BJLG_Debug::error(sprintf(
                        'Échec de la persistance du token de téléchargement "%s" pour "%s".',
                        $token,
                        $filepath
                    ));

                    return new WP_Error(
                        'bjlg_download_token_failure',
                        __('Impossible de créer un token de téléchargement.', 'backup-jlg'),
                        ['status' => 500]
                    );
                }

                $download_token = $token;
            }

            if ($download_token === null) {
                $download_token = wp_generate_password(32, false);
                $payload = BJLG_Actions::build_download_token_payload($filepath);
                $transient_key = 'bjlg_download_' . $download_token;
                $persisted = set_transient($transient_key, $payload, $transient_ttl);

                if ($persisted === false) {
                    BJLG_Debug::error(sprintf(
                        'Échec de la persistance du token de téléchargement "%s" pour "%s".',
                        $download_token,
                        $filepath
                    ));

                    return new WP_Error(
                        'bjlg_download_token_failure',
                        __('Impossible de créer un token de téléchargement.', 'backup-jlg'),
                        ['status' => 500]
                    );
                }
            }

            $size = filesize($filepath);

            if ($size === false) {
                BJLG_Debug::error(sprintf(
                    'Impossible de récupérer la taille du fichier de sauvegarde pour "%s".',
                    $filepath
                ));

                $status = \file_exists($filepath) ? 500 : 404;
                $message = $status === 404
                    ? __('La sauvegarde demandée est introuvable.', 'backup-jlg')
                    : __('Impossible de déterminer la taille de la sauvegarde.', 'backup-jlg');

                return new WP_Error(
                    'bjlg_backup_size_unavailable',
                    $message,
                    ['status' => $status]
                );
            }

            $download_url = BJLG_Actions::build_download_url($download_token);

            BJLG_History::log(
                'backup_download_link_issued',
                'success',
                sprintf(
                    'Token: %s | Fichier: %s',
                    $download_token,
                    basename($filepath)
                ),
                function_exists('get_current_user_id') ? get_current_user_id() : null
            );

            return rest_ensure_response([
                'download_url' => $download_url,
                'expires_in' => $transient_ttl,
                'download_token' => $download_token,
                'filename' => basename($filepath),
                'size' => $size
            ]);
        });
    }

    /**
     * Normalise un chemin de sauvegarde pour comparaison sécurisée.
     *
     * @param string $path
     * @return string|null
     */
    private function normalize_backup_path($path) {
        if (!is_string($path) || $path === '') {
            return null;
        }

        $resolved = realpath($path);

        if ($resolved === false) {
            return null;
        }

        return str_replace('\\', '/', $resolved);
    }

    /**
     * Endpoint : Restaurer une sauvegarde
     */
    public function restore_backup($request) {
        return $this->with_request_site($request, function () use ($request) {
            $components = $this->normalize_restore_components($request->get_param('components'));
            if (is_wp_error($components)) {
                return $components;
            }

            if (empty($components)) {
                return new WP_Error(
                    'invalid_components',
                    __('Aucun composant valide fourni pour la restauration.', 'backup-jlg'),
                    ['status' => 400]
                );
            }

            $restore_environment = $request->get_param('restore_environment');
            if (is_string($restore_environment)) {
                $restore_environment = sanitize_key($restore_environment);
            } else {
                $restore_environment = BJLG_Restore::ENV_PRODUCTION;
            }

            if ($restore_environment === BJLG_Restore::ENV_SANDBOX && !BJLG_Restore::user_can_use_sandbox()) {
                return new WP_Error(
                    'rest_restore_sandbox_forbidden',
                    __('Vous ne disposez pas des permissions nécessaires pour restaurer dans la sandbox.', 'backup-jlg'),
                    ['status' => 403]
                );
            }

            $sandbox_path = $request->get_param('sandbox_path');
            if (!is_string($sandbox_path)) {
                $sandbox_path = '';
            }

            try {
                $environment_config = BJLG_Restore::prepare_environment($restore_environment, [
                    'sandbox_path' => $sandbox_path,
                ]);
            } catch (Exception $exception) {
                $error_message = sprintf(
                    __('Impossible de préparer la cible de restauration : %s', 'backup-jlg'),
                    $exception->getMessage()
                );

                $error_data = ['status' => 400];

                if ($restore_environment === BJLG_Restore::ENV_SANDBOX) {
                    $error_data['validation_errors'] = [
                        'sandbox_path' => [$exception->getMessage()],
                    ];
                }

                return new WP_Error('rest_restore_invalid_environment', $error_message, $error_data);
            }

            $raw_create_restore_point = $request->get_param('create_restore_point');
            if ($raw_create_restore_point === null) {
                $create_restore_point = true;
            } else {
                $filtered_value = filter_var($raw_create_restore_point, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $create_restore_point = ($filtered_value !== null) ? $filtered_value : false;
            }

            $filepath = BJLG_Backup_Path_Resolver::resolve($request->get_param('id'));

            if (is_wp_error($filepath)) {
                return $filepath;
            }

            $filename = basename($filepath);
            $is_encrypted_backup = substr($filename, -4) === '.enc';

            $password = $request->get_param('password');
            if ($password !== null && !is_string($password)) {
                $password = null;
            }

            if (is_string($password)) {
                if ($password === '') {
                    $password = null;
                } elseif (strlen($password) < 4) {
                    $message = 'Le mot de passe doit contenir au moins 4 caractères.';

                    return new WP_Error(
                        'rest_restore_invalid_password',
                        $message,
                        [
                            'status' => 400,
                            'validation_errors' => [
                                'password' => [$message],
                            ],
                        ]
                    );
                }
            }

            if ($is_encrypted_backup && $password === null) {
                $message = 'Un mot de passe est requis pour restaurer une sauvegarde chiffrée.';

                return new WP_Error(
                    'rest_restore_missing_password',
                    $message,
                    [
                        'status' => 400,
                        'validation_errors' => [
                            'password' => [$message],
                        ],
                    ]
                );
            }

            try {
                $encrypted_password = BJLG_Restore::encrypt_password_for_transient($password);
            } catch (Exception $exception) {
                if (class_exists(BJLG_Debug::class)) {
                    BJLG_Debug::log('Échec du chiffrement du mot de passe de restauration (API) : ' . $exception->getMessage(), 'error');
                }

                return new WP_Error(
                    'rest_restore_password_encryption_failed',
                    __('Impossible de sécuriser le mot de passe fourni.', 'backup-jlg'),
                    ['status' => 500]
                );
            }

            // Créer une tâche de restauration
            $task_id = 'bjlg_restore_' . md5(uniqid('api', true));
            $task_data = [
                'progress' => 0,
                'status' => 'pending',
                'status_text' => 'Initialisation de la restauration (API)...',
                'filepath' => $filepath,
                'components' => $components,
                'create_restore_point' => (bool) $create_restore_point,
                'password_encrypted' => $encrypted_password,
                'filename' => $filename,
                'environment' => $environment_config['environment'],
                'routing_table' => $environment_config['routing_table'],
            ];

            if (!empty($environment_config['sandbox'])) {
                $task_data['sandbox'] = $environment_config['sandbox'];
            }

            $task_ttl = BJLG_Backup::get_task_ttl();
            $transient_set = set_transient($task_id, $task_data, $task_ttl);

            if ($transient_set === false) {
                if (class_exists(BJLG_Debug::class)) {
                    BJLG_Debug::log("ERREUR : Impossible d'initialiser la tâche de restauration {$task_id} via l'API.");
                }

                return new WP_Error(
                    'rest_restore_initialization_failed',
                    __('Impossible d\'initialiser la tâche de restauration.', 'backup-jlg'),
                    ['status' => 500]
                );
            }

            // Planifier l'exécution
            $scheduled = wp_schedule_single_event(time(), 'bjlg_run_restore_task', ['task_id' => $task_id]);

            if ($scheduled === false || is_wp_error($scheduled)) {
                delete_transient($task_id);

                $error_details = is_wp_error($scheduled) ? $scheduled->get_error_message() : null;

                if (class_exists(BJLG_Debug::class)) {
                    $log_message = "ERREUR : Impossible de planifier la tâche de restauration {$task_id} via l'API.";

                    if (!empty($error_details)) {
                        $log_message .= ' Détails : ' . $error_details;
                    }

                    BJLG_Debug::log($log_message);
                }

                $error_data = ['status' => 500];

                if (!empty($error_details)) {
                    $error_data['details'] = $error_details;
                }

                return new WP_Error(
                    'rest_restore_schedule_failed',
                    __('Impossible de planifier la tâche de restauration en arrière-plan.', 'backup-jlg'),
                    $error_data
                );
            }

            return rest_ensure_response([
                'success' => true,
                'task_id' => $task_id,
                'message' => 'Restore task created successfully',
                'status_url' => rest_url(self::API_NAMESPACE . '/tasks/' . $task_id)
            ]);
        });
    }

    private function normalize_restore_components($components) {
        if ($components === null) {
            return [];
        }

        $components = (array) $components;
        $validated_components = [];

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

            $validated_components[] = $component;
        }

        return BJLG_Restore::normalize_requested_components($validated_components, false);
    }

    /**
     * Endpoint : Statut du système
     */
    public function get_status($request) {
        return $this->with_request_site($request, function () use ($request) {
            $backup_files = glob(BJLG_BACKUP_DIR . '*.zip*') ?: [];

            $backup_directory = BJLG_BACKUP_DIR;
            $disk_free_space = null;
            $disk_space_error = false;

            if (is_dir($backup_directory) && is_readable($backup_directory)) {
                $available_space = @disk_free_space($backup_directory);

                if ($available_space !== false) {
                    $disk_free_space = $available_space;
                } else {
                    $disk_space_error = true;
                }
            } else {
                $disk_space_error = true;
            }

            $status = [
                'plugin_version' => BJLG_VERSION,
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'backup_directory' => $backup_directory,
                'backup_directory_writable' => is_writable($backup_directory),
                'total_backups' => count($backup_files),
                'total_size' => $this->get_total_backup_size(),
                'disk_free_space' => $disk_free_space,
                'disk_space_error' => $disk_space_error,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'active_tasks' => $this->get_active_tasks_count()
            ];

            return rest_ensure_response($status);
        });
    }
    
    /**
     * Endpoint : Santé du système
     */
    public function get_health($request) {
        return $this->with_request_site($request, function () use ($request) {
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
        });
    }
    
    /**
     * Endpoint : Statistiques
     */
    public function get_stats($request) {
        return $this->with_request_site($request, function () use ($request) {
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
        });
    }
    
    /**
     * Endpoint : Historique
     */
    public function get_history($request) {
        return $this->with_request_site($request, function () use ($request) {
            $limit = $request->get_param('limit');
            $action = $request->get_param('action');
            $status = $request->get_param('status');

            $filters = [];
            if ($action) $filters['action_type'] = $action;
            if ($status) $filters['status'] = $status;

            $limit = max(1, min(500, (int) $limit));

            $history = BJLG_History::get_history($limit, $filters);

            return rest_ensure_response([
                'entries' => $history,
                'total' => count($history)
            ]);
        });
    }
    
    /**
     * Endpoint : Obtenir les paramètres
     */
    public function get_settings($request) {
        return $this->with_request_site($request, function () use ($request) {
            $settings = [
                'cleanup' => \bjlg_get_option('bjlg_cleanup_settings', []),
                'schedule' => \bjlg_get_option('bjlg_schedule_settings', []),
                'encryption' => \bjlg_get_option('bjlg_encryption_settings', []),
                'notifications' => \bjlg_get_option('bjlg_notification_settings', []),
                'performance' => \bjlg_get_option('bjlg_performance_settings', []),
                'webhooks' => \bjlg_get_option('bjlg_webhook_settings', [])
            ];

            return rest_ensure_response($settings);
        });
    }
    
    /**
     * Endpoint : Mettre à jour les paramètres
     */
    public function update_settings($request) {
        return $this->with_request_site($request, function () use ($request) {
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

            $option_name_map = [
                'notifications' => 'bjlg_notification_settings',
                'webhooks' => 'bjlg_webhook_settings',
            ];

            foreach ($validated_settings as $key => $value) {
                $option_name = $option_name_map[$key] ?? 'bjlg_' . $key . '_settings';
                update_option($option_name, $value);
            }

            BJLG_History::log('settings_updated', 'success', 'Settings updated via API');

            return rest_ensure_response([
                'success' => true,
                'message' => 'Settings updated successfully'
            ]);
        });
    }
    
    /**
     * Endpoint : Obtenir les planifications
     */
    public function get_schedules($request) {
        return $this->with_request_site($request, function () use ($request) {
            $stored = \bjlg_get_option('bjlg_schedule_settings', []);
            $collection = BJLG_Settings::sanitize_schedule_collection($stored);
            $schedules = $collection['schedules'];

            $next_runs = [];

            if (class_exists(BJLG_Scheduler::class)) {
                $scheduler = BJLG_Scheduler::instance();
                if ($scheduler && method_exists($scheduler, 'get_next_runs_summary')) {
                    $next_runs = $scheduler->get_next_runs_summary($schedules);
                }
            }

            if (empty($next_runs)) {
                foreach ($schedules as $schedule) {
                    if (!is_array($schedule) || empty($schedule['id'])) {
                        continue;
                    }

                    $schedule_id = $schedule['id'];
                    $next_runs[$schedule_id] = [
                        'id' => $schedule_id,
                        'label' => $schedule['label'] ?? $schedule_id,
                        'recurrence' => $schedule['recurrence'] ?? 'disabled',
                        'enabled' => ($schedule['recurrence'] ?? 'disabled') !== 'disabled',
                        'next_run' => null,
                        'next_run_formatted' => 'Non planifié',
                        'next_run_relative' => null,
                    ];
                }
            }

            $enabled = false;

            foreach ($schedules as $schedule) {
                if (!is_array($schedule)) {
                    continue;
                }
                if (($schedule['recurrence'] ?? 'disabled') !== 'disabled') {
                    $enabled = true;
                    break;
                }
            }

            return rest_ensure_response([
                'version' => $collection['version'] ?? null,
                'schedules' => $schedules,
                'next_runs' => $next_runs,
                'enabled' => $enabled,
            ]);
        });
    }
    
    /**
     * Endpoint : Créer une planification
     */
    public function create_schedule($request) {
        return $this->with_request_site($request, function () use ($request) {
            $params = $request->get_json_params();

            if (!is_array($params) || empty($params)) {
                return new WP_Error(
                    'invalid_payload',
                    __('The request payload must be a JSON object with schedule data.', 'backup-jlg'),
                    ['status' => 400]
                );
            }

            $validated_collection = $this->validate_schedule_settings($params);

            if (is_wp_error($validated_collection)) {
                return $validated_collection;
            }

            $existing_collection = BJLG_Settings::sanitize_schedule_collection(
                \bjlg_get_option('bjlg_schedule_settings', [])
            );

            $merged_schedules = array_merge(
                $existing_collection['schedules'],
                $validated_collection['schedules']
            );

            $final_collection = BJLG_Settings::sanitize_schedule_collection([
                'schedules' => $merged_schedules,
            ]);

            \bjlg_update_option('bjlg_schedule_settings', $final_collection);

            // Réinitialiser la planification sans multiplier les hooks
            $scheduler = BJLG_Scheduler::instance();
            $scheduler->check_schedule();

            return rest_ensure_response([
                'success' => true,
                'message' => 'Schedule created successfully',
                'schedules' => $final_collection['schedules'],
            ]);
        });
    }
    
    /**
     * Endpoint : Obtenir le statut d'une tâche
     */
    public function get_task_status($request) {
        return $this->with_request_site($request, function () use ($request) {
            $raw_task_id = $request->get_param('id');
            $task_id = sanitize_key($raw_task_id);

            if (empty($task_id) || !preg_match('/^bjlg_(?:backup|restore)_[a-z0-9_]+$/', $task_id)) {
                return new WP_Error(
                    'invalid_task_id',
                    __('Invalid task identifier.', 'backup-jlg'),
                    ['status' => 400]
                );
            }

            $task_data = get_transient($task_id);

            if (!$task_data) {
                return new WP_Error(
                    'task_not_found',
                    'Task not found or expired',
                    ['status' => 404]
                );
            }

            return rest_ensure_response($task_data);
        });
    }
    
    /**
     * Formate les données d'une sauvegarde
     */
    private function format_backup_data($filepath, $manifest = null, $include_token = false) {
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

        if ($manifest === null) {
            $manifest = $this->get_backup_manifest($filepath);
        }

        $rest_download_route = sprintf(
            '/%s/backups/%s/download',
            self::API_NAMESPACE,
            rawurlencode($filename)
        );

        $rest_download_url = function_exists('rest_url')
            ? rest_url(ltrim($rest_download_route, '/'))
            : $rest_download_route;

        $filesize = @filesize($filepath);
        if ($filesize === false) {
            $filesize = null;
            $size_formatted = null;
        } else {
            $size_formatted = size_format($filesize);
        }

        $filemtime = @filemtime($filepath);
        if ($filemtime === false) {
            $created_at = null;
            $modified_at = null;
        } else {
            $timestamp = date('c', $filemtime);
            $created_at = $timestamp;
            $modified_at = $timestamp;
        }

        $data = [
            'id' => $filename,
            'filename' => $filename,
            'type' => $type,
            'size' => $filesize,
            'size_formatted' => $size_formatted,
            'created_at' => $created_at,
            'modified_at' => $modified_at,
            'is_encrypted' => $is_encrypted,
            'components' => $manifest['contains'] ?? [],
            'download_rest_url' => $rest_download_url,
            'manifest' => $manifest
        ];

        if ($include_token) {
            $download_token = wp_generate_password(32, false);
            $transient_key = 'bjlg_download_' . $download_token;
            $token_ttl = BJLG_Actions::get_download_token_ttl($filepath);
            $token_payload = BJLG_Actions::build_download_token_payload($filepath);

            $persisted = set_transient(
                $transient_key,
                $token_payload,
                $token_ttl
            );

            if ($persisted === false) {
                BJLG_Debug::error(sprintf(
                    'Échec de la persistance du token de téléchargement "%s" pour "%s".',
                    $download_token,
                    $filepath
                ));
            } else {
                $download_url = BJLG_Actions::build_download_url($download_token);

                $data['download_url'] = $download_url;
                $data['download_token'] = $download_token;
                $data['download_expires_in'] = $token_ttl;
            }
        }

        return $data;
    }

    /**
     * Détermine si un fichier de sauvegarde correspond à un type de filtre.
     */
    private function backup_matches_type($filepath, $type, $manifest = null) {
        if ($type === 'all') {
            return true;
        }

        if ($manifest === null) {
            $manifest = $this->get_backup_manifest($filepath);
        }

        if (is_array($manifest)) {
            $contains = isset($manifest['contains']) && is_array($manifest['contains'])
                ? $manifest['contains']
                : [];

            switch ($type) {
                case 'full':
                case 'incremental':
                    if (($manifest['type'] ?? null) === $type) {
                        return true;
                    }
                    break;
                case 'database':
                    if (in_array('db', $contains, true)) {
                        return true;
                    }
                    break;
                case 'files':
                    $file_components = ['plugins', 'themes', 'uploads'];
                    if (!empty(array_intersect($file_components, $contains))) {
                        return true;
                    }
                    break;
            }
        }

        $filename = basename($filepath);

        switch ($type) {
            case 'full':
            case 'incremental':
                return strpos($filename, $type) !== false;
            case 'database':
                return strpos($filename, 'database') !== false || strpos($filename, 'db') !== false;
            case 'files':
                foreach (['files', 'plugins', 'themes', 'uploads'] as $component) {
                    if (strpos($filename, $component) !== false) {
                        return true;
                    }
                }
                break;
        }

        return false;
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
        $files = glob(BJLG_BACKUP_DIR . '*.zip*') ?: [];

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

