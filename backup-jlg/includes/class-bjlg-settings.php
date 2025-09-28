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
        'advanced' => [
            'debug_mode' => false,
            'ajax_debug' => false,
            'exclude_patterns' => [],
            'custom_backup_dir' => ''
        ]
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
                    'hide_from_non_admins' => isset($_POST['hide_from_non_admins']) && wp_unslash($_POST['hide_from_non_admins']) === 'true',
                ];
                update_option('bjlg_whitelabel_settings', $wl_settings);
                $saved_settings['whitelabel'] = $wl_settings;
                BJLG_Debug::log("Réglages de marque blanche sauvegardés : " . print_r($wl_settings, true));
            }

            // --- Réglages de Chiffrement ---
            if (isset($_POST['encryption_enabled'])) {
                $encryption_settings = [
                    'enabled' => wp_unslash($_POST['encryption_enabled']) === 'true',
                    'auto_encrypt' => isset($_POST['auto_encrypt']) && wp_unslash($_POST['auto_encrypt']) === 'true',
                    'password_protect' => isset($_POST['password_protect']) && wp_unslash($_POST['password_protect']) === 'true',
                    'compression_level' => isset($_POST['compression_level']) ? max(0, intval(wp_unslash($_POST['compression_level']))) : 6
                ];
                update_option('bjlg_encryption_settings', $encryption_settings);
                $saved_settings['encryption'] = $encryption_settings;
                BJLG_Debug::log("Réglages de chiffrement sauvegardés.");
            }

            // --- Réglages Google Drive ---
            if (isset($_POST['gdrive_client_id']) && isset($_POST['gdrive_client_secret'])) {
                $gdrive_settings = [
                    'client_id'     => sanitize_text_field(wp_unslash($_POST['gdrive_client_id'])),
                    'client_secret' => sanitize_text_field(wp_unslash($_POST['gdrive_client_secret'])),
                    'folder_id'     => isset($_POST['gdrive_folder_id']) ? sanitize_text_field(wp_unslash($_POST['gdrive_folder_id'])) : '',
                    'enabled'       => isset($_POST['gdrive_enabled']) && wp_unslash($_POST['gdrive_enabled']) === 'true'
                ];
                update_option('bjlg_gdrive_settings', $gdrive_settings);
                $saved_settings['gdrive'] = $gdrive_settings;
                BJLG_Debug::log("Identifiants Google Drive sauvegardés.");
            }

            // --- Réglages de Notifications ---
            if (isset($_POST['notifications_enabled'])) {
                $notifications_settings = [
                    'enabled' => wp_unslash($_POST['notifications_enabled']) === 'true',
                    'email_recipients' => isset($_POST['email_recipients']) ? sanitize_text_field(wp_unslash($_POST['email_recipients'])) : '',
                    'events' => [
                        'backup_complete' => isset($_POST['notify_backup_complete']) && wp_unslash($_POST['notify_backup_complete']) === 'true',
                        'backup_failed' => isset($_POST['notify_backup_failed']) && wp_unslash($_POST['notify_backup_failed']) === 'true',
                        'cleanup_complete' => isset($_POST['notify_cleanup_complete']) && wp_unslash($_POST['notify_cleanup_complete']) === 'true',
                        'storage_warning' => isset($_POST['notify_storage_warning']) && wp_unslash($_POST['notify_storage_warning']) === 'true'
                    ],
                    'channels' => [
                        'email' => [
                            'enabled' => isset($_POST['channel_email']) && wp_unslash($_POST['channel_email']) === 'true'
                        ],
                        'slack' => [
                            'enabled' => isset($_POST['channel_slack']) && wp_unslash($_POST['channel_slack']) === 'true',
                            'webhook_url' => isset($_POST['slack_webhook_url']) ? esc_url_raw(wp_unslash($_POST['slack_webhook_url'])) : ''
                        ],
                        'discord' => [
                            'enabled' => isset($_POST['channel_discord']) && wp_unslash($_POST['channel_discord']) === 'true',
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
                    'multi_threading' => wp_unslash($_POST['multi_threading']) === 'true',
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
                    'enabled' => wp_unslash($_POST['webhook_enabled']) === 'true',
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
                $ajax_debug_enabled = wp_unslash($_POST['ajax_debug_enabled']) === 'true';
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
            'gdrive' => get_option('bjlg_gdrive_settings', []),
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
                $defaults = [
                    'recurrence' => 'disabled',
                    'day' => 'sunday',
                    'time' => '23:59',
                    'components' => ['db', 'plugins', 'themes', 'uploads'],
                    'encrypt' => false,
                    'incremental' => false,
                ];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['recurrence'])) {
                        $candidate = sanitize_key($value['recurrence']);
                        $valid = ['disabled', 'hourly', 'twice_daily', 'daily', 'weekly', 'monthly'];
                        if (in_array($candidate, $valid, true)) {
                            $sanitized['recurrence'] = $candidate;
                        }
                    }
                    if (isset($value['day'])) {
                        $candidate = sanitize_key($value['day']);
                        $valid_days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                        if (in_array($candidate, $valid_days, true)) {
                            $sanitized['day'] = $candidate;
                        }
                    }
                    if (isset($value['time']) && is_string($value['time'])) {
                        $candidate = sanitize_text_field($value['time']);
                        if (preg_match('/^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/', $candidate)) {
                            $sanitized['time'] = $candidate;
                        }
                    }
                    if (isset($value['components']) && is_array($value['components'])) {
                        $components = array_filter(array_map('sanitize_key', $value['components']));
                        if (!empty($components)) {
                            $sanitized['components'] = array_values(array_unique($components));
                        }
                    }
                    if (isset($value['encrypt'])) {
                        $sanitized['encrypt'] = $this->to_bool($value['encrypt']);
                    }
                    if (isset($value['incremental'])) {
                        $sanitized['incremental'] = $this->to_bool($value['incremental']);
                    }
                }

                return $sanitized;

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