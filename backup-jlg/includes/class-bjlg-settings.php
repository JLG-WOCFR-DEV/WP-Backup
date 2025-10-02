<?php
namespace BJLG;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gère la sauvegarde de tous les réglages du plugin via une seule action AJAX.
 */
class BJLG_Settings {

    /** @var self|null */
    private static $instance = null;

    private const VALID_SCHEDULE_RECURRENCES = ['disabled', 'hourly', 'twice_daily', 'daily', 'weekly', 'monthly'];
    private const VALID_SCHEDULE_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    private $default_settings = [
        'cleanup' => [
            'by_number' => 3,
            'by_age' => 0
        ],
        'whitelabel' => [
            'plugin_name' => '',
            'hide_from_non_admins' => false
        ],
        'encryption' => [
            'enabled' => false,
            'auto_encrypt' => false,
            'password_protect' => false,
            'compression_level' => 6
        ],
        'notifications' => [
            'enabled' => false,
            'email_recipients' => '',
            'events' => [
                'backup_complete' => true,
                'backup_failed' => true,
                'cleanup_complete' => false,
                'storage_warning' => true
            ]
        ],
        'performance' => [
            'multi_threading' => false,
            'max_workers' => 2,
            'chunk_size' => 50,
            'compression_level' => 6
        ],
        'gdrive' => [
            'client_id' => '',
            'client_secret' => '',
            'folder_id' => '',
            'enabled' => false,
        ],
        's3' => [
            'access_key' => '',
            'secret_key' => '',
            'region' => '',
            'bucket' => '',
            'server_side_encryption' => '',
            'object_prefix' => '',
            'enabled' => false,
        ],
        'sftp' => [
            'host' => '',
            'port' => 22,
            'username' => '',
            'password' => '',
            'private_key' => '',
            'passphrase' => '',
            'remote_path' => '',
            'fingerprint' => '',
            'enabled' => false,
        ],
        'advanced' => [
            'debug_mode' => false,
            'ajax_debug' => false,
            'exclude_patterns' => [],
            'custom_backup_dir' => ''
        ]
    ];

    private $default_backup_preferences = [
        'include_patterns' => [],
        'exclude_patterns' => [],
        'post_checks' => [
            'checksum' => true,
            'dry_run' => false,
        ],
        'secondary_destinations' => [],
    ];

    public function __construct() {
        if (self::$instance instanceof self) {
            return;
        }

        self::$instance = $this;

        // Un seul point d'entrée pour tous les réglages
        add_action('wp_ajax_bjlg_save_settings', [$this, 'handle_save_settings']);
        add_action('wp_ajax_bjlg_get_settings', [$this, 'handle_get_settings']);
        add_action('wp_ajax_bjlg_reset_settings', [$this, 'handle_reset_settings']);
        add_action('wp_ajax_bjlg_export_settings', [$this, 'handle_export_settings']);
        add_action('wp_ajax_bjlg_import_settings', [$this, 'handle_import_settings']);

        // Initialiser les paramètres par défaut si nécessaire
        add_action('init', [$this, 'init_default_settings']);
    }

    /**
     * Retourne l'instance actuelle ou l'initialise si nécessaire.
     */
    public static function get_instance() {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }
    
    /**
     * Initialise les paramètres par défaut si ils n'existent pas
     */
    public function init_default_settings() {
        foreach ($this->default_settings as $key => $defaults) {
            $option_name = 'bjlg_' . $key . '_settings';
            if (get_option($option_name) === false) {
                update_option($option_name, $defaults);
            }
        }

        $this->init_backup_preferences_defaults();
    }

    /**
     * Gère la requête AJAX pour sauvegarder tous les réglages.
     */
    public function handle_save_settings() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        try {
            $saved_settings = [];
            
            // --- Réglages de la Rétention ---
            if (isset($_POST['by_number']) || isset($_POST['by_age'])) {
                $cleanup_settings = [
                    'by_number' => isset($_POST['by_number']) ? max(0, intval(wp_unslash($_POST['by_number']))) : 3,
                    'by_age'    => isset($_POST['by_age']) ? max(0, intval(wp_unslash($_POST['by_age']))) : 0,
                ];
                update_option('bjlg_cleanup_settings', $cleanup_settings);
                $saved_settings['cleanup'] = $cleanup_settings;
                BJLG_Debug::log("Réglages de nettoyage sauvegardés : " . print_r($cleanup_settings, true));
            }

            // --- Réglages de la Marque Blanche ---
            if (isset($_POST['plugin_name']) || isset($_POST['hide_from_non_admins'])) {
                $wl_settings = [
                    'plugin_name'          => isset($_POST['plugin_name']) ? sanitize_text_field(wp_unslash($_POST['plugin_name'])) : '',
                    'hide_from_non_admins' => isset($_POST['hide_from_non_admins']) ? $this->to_bool(wp_unslash($_POST['hide_from_non_admins'])) : false,
                ];
                update_option('bjlg_whitelabel_settings', $wl_settings);
                $saved_settings['whitelabel'] = $wl_settings;
                BJLG_Debug::log("Réglages de marque blanche sauvegardés : " . print_r($wl_settings, true));
            }

            // --- Réglages de Chiffrement ---
            $encryption_fields = ['encryption_enabled', 'auto_encrypt', 'password_protect', 'compression_level', 'encryption_settings_submitted'];
            $encryption_submitted = false;
            foreach ($encryption_fields as $field) {
                if (array_key_exists($field, $_POST)) {
                    $encryption_submitted = true;
                    break;
                }
            }

            if ($encryption_submitted) {
                $current_encryption = get_option('bjlg_encryption_settings', $this->default_settings['encryption']);

                $compression_level = isset($_POST['compression_level'])
                    ? max(0, intval(wp_unslash($_POST['compression_level'])))
                    : (isset($current_encryption['compression_level']) ? max(0, intval($current_encryption['compression_level'])) : 6);

                $encryption_settings = [
                    'enabled' => array_key_exists('encryption_enabled', $_POST) ? $this->to_bool(wp_unslash($_POST['encryption_enabled'])) : false,
                    'auto_encrypt' => array_key_exists('auto_encrypt', $_POST) ? $this->to_bool(wp_unslash($_POST['auto_encrypt'])) : false,
                    'password_protect' => array_key_exists('password_protect', $_POST) ? $this->to_bool(wp_unslash($_POST['password_protect'])) : false,
                    'compression_level' => $compression_level,
                ];

                update_option('bjlg_encryption_settings', $encryption_settings);
                $saved_settings['encryption'] = $encryption_settings;
                BJLG_Debug::log("Réglages de chiffrement sauvegardés.");
            }

            $filters_submitted = array_key_exists('include_patterns', $_POST)
                || array_key_exists('exclude_patterns', $_POST)
                || array_key_exists('secondary_destinations', $_POST)
                || array_key_exists('post_checks', $_POST);

            if ($filters_submitted) {
                $includes = self::sanitize_pattern_list($_POST['include_patterns'] ?? []);
                $excludes = self::sanitize_pattern_list($_POST['exclude_patterns'] ?? []);
                $destinations = self::sanitize_destination_list(
                    $_POST['secondary_destinations'] ?? [],
                    self::get_known_destination_ids()
                );
                $post_checks = self::sanitize_post_checks(
                    $_POST['post_checks'] ?? [],
                    self::get_default_backup_post_checks()
                );

                $this->update_backup_filters($includes, $excludes, $destinations, $post_checks);

                $saved_settings['backup_preferences'] = [
                    'include_patterns' => $includes,
                    'exclude_patterns' => $excludes,
                    'secondary_destinations' => $destinations,
                    'post_checks' => $post_checks,
                ];

                BJLG_Debug::log('Réglages de filtres de sauvegarde mis à jour.');
            }

            // --- Réglages Google Drive ---
            if (isset($_POST['gdrive_client_id']) && isset($_POST['gdrive_client_secret'])) {
                $gdrive_settings = [
                    'client_id'     => sanitize_text_field(wp_unslash($_POST['gdrive_client_id'])),
                    'client_secret' => sanitize_text_field(wp_unslash($_POST['gdrive_client_secret'])),
                    'folder_id'     => isset($_POST['gdrive_folder_id']) ? sanitize_text_field(wp_unslash($_POST['gdrive_folder_id'])) : '',
                    'enabled'       => isset($_POST['gdrive_enabled']) ? $this->to_bool(wp_unslash($_POST['gdrive_enabled'])) : false
                ];
                update_option('bjlg_gdrive_settings', $gdrive_settings);
                $saved_settings['gdrive'] = $gdrive_settings;
                BJLG_Debug::log("Identifiants Google Drive sauvegardés.");
            }

            // --- Réglages Amazon S3 ---
            if (isset($_POST['s3_access_key']) || isset($_POST['s3_bucket'])) {
                $s3_settings = [
                    'access_key' => isset($_POST['s3_access_key']) ? sanitize_text_field(wp_unslash($_POST['s3_access_key'])) : '',
                    'secret_key' => isset($_POST['s3_secret_key']) ? sanitize_text_field(wp_unslash($_POST['s3_secret_key'])) : '',
                    'region' => isset($_POST['s3_region']) ? sanitize_text_field(wp_unslash($_POST['s3_region'])) : '',
                    'bucket' => isset($_POST['s3_bucket']) ? sanitize_text_field(wp_unslash($_POST['s3_bucket'])) : '',
                    'server_side_encryption' => isset($_POST['s3_server_side_encryption']) ? sanitize_text_field(wp_unslash($_POST['s3_server_side_encryption'])) : '',
                    'kms_key_id' => isset($_POST['s3_kms_key_id']) ? sanitize_text_field(wp_unslash($_POST['s3_kms_key_id'])) : '',
                    'object_prefix' => isset($_POST['s3_object_prefix']) ? sanitize_text_field(wp_unslash($_POST['s3_object_prefix'])) : '',
                    'enabled' => isset($_POST['s3_enabled']) ? $this->to_bool(wp_unslash($_POST['s3_enabled'])) : false,
                ];

                if (!in_array($s3_settings['server_side_encryption'], ['AES256', 'aws:kms'], true)) {
                    $s3_settings['server_side_encryption'] = '';
                }

                if ($s3_settings['server_side_encryption'] !== 'aws:kms') {
                    $s3_settings['kms_key_id'] = '';
                }

                $s3_settings['object_prefix'] = trim($s3_settings['object_prefix']);

                update_option('bjlg_s3_settings', $s3_settings);
                $saved_settings['s3'] = $s3_settings;
                BJLG_Debug::log('Réglages Amazon S3 sauvegardés.');
            }

            // --- Réglages de Notifications ---
            if (isset($_POST['notifications_enabled'])) {
                $notifications_settings = [
                    'enabled' => $this->to_bool(wp_unslash($_POST['notifications_enabled'])),
                    'email_recipients' => isset($_POST['email_recipients']) ? sanitize_text_field(wp_unslash($_POST['email_recipients'])) : '',
                    'events' => [
                        'backup_complete' => isset($_POST['notify_backup_complete']) ? $this->to_bool(wp_unslash($_POST['notify_backup_complete'])) : false,
                        'backup_failed' => isset($_POST['notify_backup_failed']) ? $this->to_bool(wp_unslash($_POST['notify_backup_failed'])) : false,
                        'cleanup_complete' => isset($_POST['notify_cleanup_complete']) ? $this->to_bool(wp_unslash($_POST['notify_cleanup_complete'])) : false,
                        'storage_warning' => isset($_POST['notify_storage_warning']) ? $this->to_bool(wp_unslash($_POST['notify_storage_warning'])) : false
                    ],
                    'channels' => [
                        'email' => [
                            'enabled' => isset($_POST['channel_email']) ? $this->to_bool(wp_unslash($_POST['channel_email'])) : false
                        ],
                        'slack' => [
                            'enabled' => isset($_POST['channel_slack']) ? $this->to_bool(wp_unslash($_POST['channel_slack'])) : false,
                            'webhook_url' => isset($_POST['slack_webhook_url']) ? esc_url_raw(wp_unslash($_POST['slack_webhook_url'])) : ''
                        ],
                        'discord' => [
                            'enabled' => isset($_POST['channel_discord']) ? $this->to_bool(wp_unslash($_POST['channel_discord'])) : false,
                            'webhook_url' => isset($_POST['discord_webhook_url']) ? esc_url_raw(wp_unslash($_POST['discord_webhook_url'])) : ''
                        ]
                    ]
                ];
                update_option('bjlg_notification_settings', $notifications_settings);
                $saved_settings['notifications'] = $notifications_settings;
                BJLG_Debug::log("Réglages de notifications sauvegardés.");
            }

            // --- Réglages de Performance ---
            if (isset($_POST['multi_threading'])) {
                $performance_settings = [
                    'multi_threading' => $this->to_bool(wp_unslash($_POST['multi_threading'])),
                    'max_workers' => isset($_POST['max_workers']) ? max(1, intval(wp_unslash($_POST['max_workers']))) : 2,
                    'chunk_size' => isset($_POST['chunk_size']) ? max(1, intval(wp_unslash($_POST['chunk_size']))) : 50
                ];
                update_option('bjlg_performance_settings', $performance_settings);
                $saved_settings['performance'] = $performance_settings;
                BJLG_Debug::log("Réglages de performance sauvegardés.");
            }

            // --- Réglages Webhooks ---
            if (isset($_POST['webhook_enabled'])) {
                $webhook_settings = [
                    'enabled' => $this->to_bool(wp_unslash($_POST['webhook_enabled'])),
                    'urls' => [
                        'backup_complete' => isset($_POST['webhook_backup_complete']) ? esc_url_raw(wp_unslash($_POST['webhook_backup_complete'])) : '',
                        'backup_failed' => isset($_POST['webhook_backup_failed']) ? esc_url_raw(wp_unslash($_POST['webhook_backup_failed'])) : '',
                        'cleanup_complete' => isset($_POST['webhook_cleanup_complete']) ? esc_url_raw(wp_unslash($_POST['webhook_cleanup_complete'])) : ''
                    ],
                    'secret' => isset($_POST['webhook_secret']) ? sanitize_text_field(wp_unslash($_POST['webhook_secret'])) : ''
                ];
                update_option('bjlg_webhook_settings', $webhook_settings);
                $saved_settings['webhooks'] = $webhook_settings;
                BJLG_Debug::log("Réglages de webhooks sauvegardés.");
            }

            // --- Réglage du débogueur AJAX ---
            if (isset($_POST['ajax_debug_enabled'])) {
                $ajax_debug_enabled = $this->to_bool(wp_unslash($_POST['ajax_debug_enabled']));
                update_option('bjlg_ajax_debug_enabled', $ajax_debug_enabled);
                $saved_settings['ajax_debug'] = $ajax_debug_enabled;
                BJLG_Debug::log("Réglage du débogueur AJAX mis à jour.");
            }

            BJLG_History::log('settings_updated', 'success', 'Les réglages ont été mis à jour.');
            
            do_action('bjlg_settings_saved', $saved_settings);
            
            wp_send_json_success([
                'message' => 'Réglages sauvegardés avec succès !',
                'saved' => $saved_settings
            ]);

        } catch (Exception $e) {
            BJLG_History::log('settings_updated', 'failure', 'Erreur : ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Récupère tous les paramètres
     */
    public function handle_get_settings() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        
        $settings = [
            'cleanup' => get_option('bjlg_cleanup_settings', $this->default_settings['cleanup']),
            'whitelabel' => get_option('bjlg_whitelabel_settings', $this->default_settings['whitelabel']),
            'encryption' => get_option('bjlg_encryption_settings', $this->default_settings['encryption']),
            'notifications' => get_option('bjlg_notification_settings', $this->default_settings['notifications']),
            'performance' => get_option('bjlg_performance_settings', $this->default_settings['performance']),
            'gdrive' => get_option('bjlg_gdrive_settings', $this->default_settings['gdrive']),
            'webhooks' => get_option('bjlg_webhook_settings', []),
            'schedule' => get_option('bjlg_schedule_settings', []),
            'ajax_debug' => get_option('bjlg_ajax_debug_enabled', false)
        ];
        
        wp_send_json_success($settings);
    }
    
    /**
     * Réinitialise tous les paramètres
     */
    public function handle_reset_settings() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        $section = isset($_POST['section'])
            ? sanitize_text_field(wp_unslash($_POST['section']))
            : 'all';
        
        try {
            if ($section === 'all') {
                foreach ($this->default_settings as $key => $defaults) {
                    update_option('bjlg_' . $key . '_settings', $defaults);
                }
                BJLG_History::log('settings_reset', 'info', 'Tous les réglages ont été réinitialisés');
            } else {
                if (isset($this->default_settings[$section])) {
                    update_option('bjlg_' . $section . '_settings', $this->default_settings[$section]);
                    BJLG_History::log('settings_reset', 'info', "Réglages '$section' réinitialisés");
                } else {
                    throw new Exception("Section de réglages invalide.");
                }
            }
            
            wp_send_json_success(['message' => 'Réglages réinitialisés avec succès.']);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Exporte les paramètres
     */
    public function handle_export_settings() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        $settings = [];
        
        // Collecter tous les paramètres
        $option_keys = [
            'bjlg_cleanup_settings',
            'bjlg_whitelabel_settings',
            'bjlg_encryption_settings',
            'bjlg_notification_settings',
            'bjlg_performance_settings',
            'bjlg_gdrive_settings',
            'bjlg_webhook_settings',
            'bjlg_schedule_settings'
        ];
        
        foreach ($option_keys as $key) {
            $value = get_option($key);
            if ($value !== false) {
                $settings[$key] = $value;
            }
        }
        
        // Ajouter des métadonnées
        $export_data = [
            'plugin' => 'Backup JLG',
            'version' => BJLG_VERSION,
            'exported_at' => current_time('c'),
            'site_url' => get_site_url(),
            'settings' => $settings
        ];
        
        BJLG_History::log('settings_exported', 'success', 'Paramètres exportés');
        
        wp_send_json_success([
            'filename' => 'bjlg-settings-' . date('Y-m-d-His') . '.json',
            'data' => base64_encode(json_encode($export_data, JSON_PRETTY_PRINT))
        ]);
    }
    
    /**
     * Importe les paramètres
     */
    public function handle_import_settings() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        if (empty($_POST['import_data'])) {
            wp_send_json_error(['message' => 'Aucune donnée à importer.']);
        }

        try {
            $raw_import = wp_unslash($_POST['import_data']);
            $import_data = json_decode(base64_decode($raw_import), true);

            if (empty($import_data) || !isset($import_data['settings'])) {
                throw new Exception("Format de données invalide.");
            }

            // Vérifier la compatibilité de version
            if (isset($import_data['version'])) {
                $import_version = $import_data['version'];
                if (version_compare($import_version, BJLG_VERSION, '>')) {
                    throw new Exception("Les paramètres proviennent d'une version plus récente du plugin.");
                }
            }
            
            // Importer les paramètres
            $sanitized_settings = $this->sanitize_imported_settings((array) $import_data['settings']);

            if (empty($sanitized_settings)) {
                throw new Exception("Aucun réglage valide à importer.");
            }

            foreach ($sanitized_settings as $key => $value) {
                update_option($key, $value);
            }
            
            BJLG_History::log('settings_imported', 'success', 'Paramètres importés depuis ' . ($import_data['site_url'] ?? 'inconnu'));
            
            wp_send_json_success(['message' => 'Paramètres importés avec succès.']);
            
        } catch (Exception $e) {
            BJLG_History::log('settings_imported', 'failure', 'Erreur : ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Nettoie et valide les paramètres importés.
     *
     * @param array $settings
     * @return array
     */
    private function sanitize_imported_settings(array $settings) {
        $sanitized = [];

        foreach ($settings as $option => $value) {
            $clean_value = $this->sanitize_imported_option($option, $value);
            if ($clean_value !== null) {
                $sanitized[$option] = $clean_value;
            }
        }

        return $sanitized;
    }

    /**
     * Nettoie une option importée spécifique.
     *
     * @param string $option
     * @param mixed  $value
     * @return array|bool|null
     */
    private function sanitize_imported_option($option, $value) {
        switch ($option) {
            case 'bjlg_cleanup_settings':
                $defaults = $this->default_settings['cleanup'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['by_number'])) {
                        $sanitized['by_number'] = max(0, intval($value['by_number']));
                    }
                    if (isset($value['by_age'])) {
                        $sanitized['by_age'] = max(0, intval($value['by_age']));
                    }
                }

                return $sanitized;

            case 'bjlg_whitelabel_settings':
                $defaults = $this->default_settings['whitelabel'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['plugin_name'])) {
                        $sanitized['plugin_name'] = sanitize_text_field($value['plugin_name']);
                    }
                    if (isset($value['hide_from_non_admins'])) {
                        $sanitized['hide_from_non_admins'] = $this->to_bool($value['hide_from_non_admins']);
                    }
                }

                return $sanitized;

            case 'bjlg_encryption_settings':
                $defaults = $this->default_settings['encryption'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['enabled'])) {
                        $sanitized['enabled'] = $this->to_bool($value['enabled']);
                    }
                    if (isset($value['auto_encrypt'])) {
                        $sanitized['auto_encrypt'] = $this->to_bool($value['auto_encrypt']);
                    }
                    if (isset($value['password_protect'])) {
                        $sanitized['password_protect'] = $this->to_bool($value['password_protect']);
                    }
                    if (isset($value['compression_level'])) {
                        $sanitized['compression_level'] = max(0, intval($value['compression_level']));
                    }
                }

                return $sanitized;

            case 'bjlg_notification_settings':
                $defaults = [
                    'enabled' => false,
                    'email_recipients' => '',
                    'events' => $this->default_settings['notifications']['events'],
                    'channels' => [
                        'email' => ['enabled' => false],
                        'slack' => ['enabled' => false, 'webhook_url' => ''],
                        'discord' => ['enabled' => false, 'webhook_url' => ''],
                    ],
                ];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['enabled'])) {
                        $sanitized['enabled'] = $this->to_bool($value['enabled']);
                    }
                    if (isset($value['email_recipients'])) {
                        $sanitized['email_recipients'] = sanitize_text_field($value['email_recipients']);
                    }
                    if (isset($value['events']) && is_array($value['events'])) {
                        foreach ($sanitized['events'] as $event_key => $default_value) {
                            if (isset($value['events'][$event_key])) {
                                $sanitized['events'][$event_key] = $this->to_bool($value['events'][$event_key]);
                            }
                        }
                    }
                    if (isset($value['channels']) && is_array($value['channels'])) {
                        foreach ($sanitized['channels'] as $channel_key => $channel_defaults) {
                            if (!isset($value['channels'][$channel_key]) || !is_array($value['channels'][$channel_key])) {
                                continue;
                            }
                            $channel_value = $value['channels'][$channel_key];

                            if (isset($channel_value['enabled'])) {
                                $sanitized['channels'][$channel_key]['enabled'] = $this->to_bool($channel_value['enabled']);
                            }

                            if (isset($channel_defaults['webhook_url'])) {
                                $sanitized['channels'][$channel_key]['webhook_url'] = isset($channel_value['webhook_url'])
                                    ? esc_url_raw($channel_value['webhook_url'])
                                    : '';
                            }
                        }
                    }
                }

                return $sanitized;

            case 'bjlg_performance_settings':
                $defaults = $this->default_settings['performance'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['multi_threading'])) {
                        $sanitized['multi_threading'] = $this->to_bool($value['multi_threading']);
                    }
                    if (isset($value['max_workers'])) {
                        $sanitized['max_workers'] = max(1, intval($value['max_workers']));
                    }
                    if (isset($value['chunk_size'])) {
                        $sanitized['chunk_size'] = max(1, intval($value['chunk_size']));
                    }
                    if (isset($value['compression_level'])) {
                        $sanitized['compression_level'] = max(0, intval($value['compression_level']));
                    }
                }

                return $sanitized;

            case 'bjlg_gdrive_settings':
                $defaults = [
                    'client_id' => '',
                    'client_secret' => '',
                    'folder_id' => '',
                    'enabled' => false,
                ];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['client_id'])) {
                        $sanitized['client_id'] = sanitize_text_field($value['client_id']);
                    }
                    if (isset($value['client_secret'])) {
                        $sanitized['client_secret'] = sanitize_text_field($value['client_secret']);
                    }
                    if (isset($value['folder_id'])) {
                        $sanitized['folder_id'] = sanitize_text_field($value['folder_id']);
                    }
                    if (isset($value['enabled'])) {
                        $sanitized['enabled'] = $this->to_bool($value['enabled']);
                    }
                }

                return $sanitized;

            case 'bjlg_webhook_settings':
                $defaults = [
                    'enabled' => false,
                    'urls' => [
                        'backup_complete' => '',
                        'backup_failed' => '',
                        'cleanup_complete' => '',
                    ],
                    'secret' => '',
                ];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['enabled'])) {
                        $sanitized['enabled'] = $this->to_bool($value['enabled']);
                    }
                    if (isset($value['urls']) && is_array($value['urls'])) {
                        foreach ($sanitized['urls'] as $url_key => $default) {
                            if (isset($value['urls'][$url_key])) {
                                $sanitized['urls'][$url_key] = esc_url_raw($value['urls'][$url_key]);
                            }
                        }
                    }
                    if (isset($value['secret'])) {
                        $sanitized['secret'] = sanitize_text_field($value['secret']);
                    }
                }

                return $sanitized;

            case 'bjlg_schedule_settings':
                return self::sanitize_schedule_collection($value);

            default:
                return null;
        }
    }

    /**
     * Nettoie un bloc de réglages en appliquant les règles d'importation.
     *
     * @param string $section
     * @param array  $value
     * @return array|null
     */
    public function sanitize_settings_section($section, array $value) {
        $option_map = [
            'cleanup' => 'bjlg_cleanup_settings',
            'whitelabel' => 'bjlg_whitelabel_settings',
            'encryption' => 'bjlg_encryption_settings',
            'notifications' => 'bjlg_notification_settings',
            'performance' => 'bjlg_performance_settings',
            'webhooks' => 'bjlg_webhook_settings',
            'schedule' => 'bjlg_schedule_settings',
            'gdrive' => 'bjlg_gdrive_settings',
        ];

        if (!isset($option_map[$section])) {
            return null;
        }

        return $this->sanitize_imported_option($option_map[$section], $value);
    }

    private function init_backup_preferences_defaults() {
        $defaults = $this->default_backup_preferences;

        if (get_option('bjlg_backup_include_patterns', null) === null) {
            update_option('bjlg_backup_include_patterns', $defaults['include_patterns']);
        }

        if (get_option('bjlg_backup_exclude_patterns', null) === null) {
            update_option('bjlg_backup_exclude_patterns', $defaults['exclude_patterns']);
        }

        if (get_option('bjlg_backup_secondary_destinations', null) === null) {
            update_option('bjlg_backup_secondary_destinations', $defaults['secondary_destinations']);
        }

        if (get_option('bjlg_backup_post_checks', null) === null) {
            update_option('bjlg_backup_post_checks', $defaults['post_checks']);
        }
    }

    public function update_backup_filters(array $includes, array $excludes, array $destinations, array $post_checks) {
        $includes = self::sanitize_pattern_list($includes);
        $excludes = self::sanitize_pattern_list($excludes);
        $destinations = self::sanitize_destination_list($destinations, self::get_known_destination_ids());
        $post_checks = self::sanitize_post_checks($post_checks, self::get_default_backup_post_checks());

        update_option('bjlg_backup_include_patterns', $includes);
        update_option('bjlg_backup_exclude_patterns', $excludes);
        update_option('bjlg_backup_secondary_destinations', $destinations);
        update_option('bjlg_backup_post_checks', $post_checks);
    }

    public static function sanitize_pattern_list($patterns) {
        if (is_string($patterns)) {
            $patterns = preg_split('/[\r\n,]+/', $patterns) ?: [];
        }

        if (!is_array($patterns)) {
            return [];
        }

        $normalized = [];
        foreach ($patterns as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }

            $trimmed = trim($pattern);
            if ($trimmed === '') {
                continue;
            }

            $normalized[] = str_replace('\\', '/', $trimmed);
        }

        return array_values(array_unique($normalized));
    }

    public static function sanitize_destination_list($destinations, array $allowed_ids) {
        if (!is_array($destinations)) {
            $destinations = [$destinations];
        }

        $allowed = array_map('strval', $allowed_ids);
        $normalized = [];

        foreach ($destinations as $destination) {
            if (!is_scalar($destination)) {
                continue;
            }

            $slug = sanitize_key((string) $destination);
            if ($slug === '' || !in_array($slug, $allowed, true)) {
                continue;
            }

            $normalized[$slug] = true;
        }

        return array_keys($normalized);
    }

    public static function sanitize_post_checks($checks, array $defaults) {
        $normalized = [
            'checksum' => false,
            'dry_run' => false,
        ];

        if (is_array($checks)) {
            foreach ($checks as $maybe_key => $value) {
                if (is_string($maybe_key)) {
                    $key = sanitize_key($maybe_key);
                    if (array_key_exists($key, $normalized)) {
                        $normalized[$key] = (bool) $value;
                    }
                    continue;
                }

                $key = sanitize_key((string) $value);
                if (array_key_exists($key, $normalized)) {
                    $normalized[$key] = true;
                }
            }
        }

        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $normalized)) {
                $normalized[$key] = (bool) $value;
            }
        }

        return $normalized;
    }

    public static function get_default_backup_post_checks() {
        return [
            'checksum' => true,
            'dry_run' => false,
        ];
    }

    public static function get_default_schedule_entry(): array {
        return [
            'id' => '',
            'label' => '',
            'recurrence' => 'disabled',
            'day' => 'sunday',
            'time' => '23:59',
            'components' => ['db', 'plugins', 'themes', 'uploads'],
            'encrypt' => false,
            'incremental' => false,
            'include_patterns' => [],
            'exclude_patterns' => [],
            'post_checks' => self::get_default_backup_post_checks(),
            'secondary_destinations' => [],
        ];
    }

    public static function sanitize_schedule_collection($raw) {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        $entries = [];

        if (is_array($raw) && array_key_exists('schedules', $raw)) {
            $entries = is_array($raw['schedules']) ? array_values($raw['schedules']) : [];
        } elseif (self::looks_like_schedule_entry($raw)) {
            $entries = [$raw];
        } elseif (is_array($raw)) {
            $entries = array_values($raw);
        }

        $sanitized = [];
        $seen_ids = [];
        $index = 0;

        foreach ($entries as $entry) {
            $sanitized_entry = self::sanitize_schedule_entry($entry, $index);
            $index++;

            $id = $sanitized_entry['id'];
            if ($id === '') {
                continue;
            }

            if (isset($seen_ids[$id])) {
                continue;
            }

            $seen_ids[$id] = true;
            $sanitized[] = $sanitized_entry;
        }

        if (empty($sanitized)) {
            $default = self::get_default_schedule_entry();
            $default['id'] = self::generate_schedule_id();
            $default['label'] = 'Planification #1';
            $sanitized[] = $default;
        }

        return [
            'version' => 2,
            'schedules' => array_values($sanitized),
        ];
    }

    public static function sanitize_schedule_entry($entry, int $index = 0): array {
        $defaults = self::get_default_schedule_entry();
        $entry = is_array($entry) ? $entry : [];

        $id = '';
        if (isset($entry['id']) && is_scalar($entry['id'])) {
            $id = sanitize_key((string) $entry['id']);
        }
        if ($id === '') {
            $id = self::generate_schedule_id();
        }

        $label = isset($entry['label']) ? sanitize_text_field($entry['label']) : '';
        if ($label === '') {
            $label = sprintf('Planification #%d', $index + 1);
        }

        $recurrence = isset($entry['recurrence']) ? sanitize_key($entry['recurrence']) : $defaults['recurrence'];
        if (!in_array($recurrence, self::VALID_SCHEDULE_RECURRENCES, true)) {
            $recurrence = $defaults['recurrence'];
        }

        $day = isset($entry['day']) ? sanitize_key($entry['day']) : $defaults['day'];
        if (!in_array($day, self::VALID_SCHEDULE_DAYS, true)) {
            $day = $defaults['day'];
        }

        $time = isset($entry['time']) ? sanitize_text_field($entry['time']) : $defaults['time'];
        if (!preg_match('/^([0-1]?\d|2[0-3]):([0-5]\d)$/', $time)) {
            $time = $defaults['time'];
        }

        $components = self::sanitize_schedule_components($entry['components'] ?? $defaults['components']);
        if (empty($components)) {
            $components = $defaults['components'];
        }

        $include_patterns = self::sanitize_pattern_list($entry['include_patterns'] ?? $defaults['include_patterns']);
        $exclude_patterns = self::sanitize_pattern_list($entry['exclude_patterns'] ?? $defaults['exclude_patterns']);

        $post_checks = self::sanitize_post_checks(
            $entry['post_checks'] ?? $defaults['post_checks'],
            self::get_default_backup_post_checks()
        );

        $secondary_destinations = self::sanitize_destination_list(
            $entry['secondary_destinations'] ?? $defaults['secondary_destinations'],
            self::get_known_destination_ids()
        );

        return [
            'id' => $id,
            'label' => $label,
            'recurrence' => $recurrence,
            'day' => $day,
            'time' => $time,
            'components' => array_values($components),
            'encrypt' => self::to_bool_static($entry['encrypt'] ?? $defaults['encrypt']),
            'incremental' => self::to_bool_static($entry['incremental'] ?? $defaults['incremental']),
            'include_patterns' => $include_patterns,
            'exclude_patterns' => $exclude_patterns,
            'post_checks' => $post_checks,
            'secondary_destinations' => $secondary_destinations,
        ];
    }

    private static function sanitize_schedule_components($components): array {
        $allowed_components = ['db', 'plugins', 'themes', 'uploads'];
        $group_aliases = [
            'files' => ['plugins', 'themes', 'uploads'],
            'content' => ['plugins', 'themes', 'uploads'],
            'all_files' => ['plugins', 'themes', 'uploads'],
        ];
        $single_aliases = [
            'database' => 'db',
            'db_only' => 'db',
            'sql' => 'db',
            'plugins_dir' => 'plugins',
            'themes_dir' => 'themes',
            'uploads_dir' => 'uploads',
            'media' => 'uploads',
        ];

        if (is_string($components)) {
            $decoded = json_decode($components, true);
            if (is_array($decoded)) {
                $components = $decoded;
            } else {
                $components = preg_split('/[\s,;|]+/', $components, -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        if (!is_array($components)) {
            $components = (array) $components;
        }

        $sanitized = [];

        foreach ($components as $component) {
            if (!is_scalar($component)) {
                continue;
            }

            $component = (string) $component;

            if (preg_match('#[\\/]#', $component)) {
                continue;
            }

            $component = sanitize_key($component);

            if ($component === '') {
                continue;
            }

            if (in_array($component, ['all', 'full', 'everything'], true)) {
                return $allowed_components;
            }

            if (isset($group_aliases[$component])) {
                foreach ($group_aliases[$component] as $alias) {
                    if (!in_array($alias, $sanitized, true)) {
                        $sanitized[] = $alias;
                    }
                }
                continue;
            }

            if (isset($single_aliases[$component])) {
                $component = $single_aliases[$component];
            }

            if (in_array($component, $allowed_components, true) && !in_array($component, $sanitized, true)) {
                $sanitized[] = $component;
            }
        }

        return array_values($sanitized);
    }

    private static function generate_schedule_id(): string {
        if (function_exists('wp_generate_uuid4')) {
            $uuid = wp_generate_uuid4();
        } else {
            $uuid = uniqid('bjlg_', true);
        }

        $normalized = preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $uuid));
        $normalized = trim((string) $normalized, '_');

        if ($normalized === '') {
            $normalized = (string) time();
        }

        return 'bjlg_schedule_' . $normalized;
    }

    private static function looks_like_schedule_entry($value): bool {
        if (!is_array($value)) {
            return false;
        }

        return array_key_exists('recurrence', $value) || array_key_exists('components', $value);
    }

    private static function to_bool_static($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return (bool) $value;
    }

    public static function get_known_destination_ids() {
        $destinations = ['google_drive', 'aws_s3', 'sftp'];

        /** @var array<int, string> $filtered */
        $filtered = apply_filters('bjlg_known_destination_ids', $destinations);
        if (is_array($filtered) && !empty($filtered)) {
            $sanitized = [];
            foreach ($filtered as $destination) {
                if (!is_scalar($destination)) {
                    continue;
                }

                $slug = sanitize_key((string) $destination);
                if ($slug !== '') {
                    $sanitized[$slug] = true;
                }
            }

            if (!empty($sanitized)) {
                return array_keys($sanitized);
            }
        }

        return $destinations;
    }

    /**
     * Convertit une valeur en booléen normalisé.
     *
     * @param mixed $value
     * @return bool
     */
    private function to_bool($value) {
        if (is_string($value)) {
            $normalized = strtolower($value);
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Valide une adresse email ou une liste d'adresses
     */
    public function validate_email_list($emails) {
        $email_list = explode(',', $emails);
        $valid_emails = [];

        foreach ($email_list as $email) {
            $email = trim($email);
            if (is_email($email)) {
                $valid_emails[] = $email;
            }
        }

        return implode(',', $valid_emails);
    }
    
    /**
     * Obtient un paramètre spécifique
     */
    public function get_setting($section, $key = null, $default = null) {
        $option_name = 'bjlg_' . $section . '_settings';
        $settings = get_option($option_name, $this->default_settings[$section] ?? []);
        
        if ($key === null) {
            return $settings;
        }
        
        return $settings[$key] ?? $default;
    }
    
    /**
     * Met à jour un paramètre spécifique
     */
    public function update_setting($section, $key, $value) {
        $option_name = 'bjlg_' . $section . '_settings';
        $settings = get_option($option_name, $this->default_settings[$section] ?? []);
        
        $settings[$key] = $value;
        
        return update_option($option_name, $settings);
    }
}