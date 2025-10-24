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

    private const VALID_SCHEDULE_RECURRENCES = [
        'disabled',
        'every_five_minutes',
        'every_fifteen_minutes',
        'hourly',
        'twice_daily',
        'daily',
        'weekly',
        'monthly',
        'custom'
    ];
    private const VALID_SCHEDULE_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    private const DEFAULT_REMOTE_STORAGE_THRESHOLD = 0.85;

    private const DEFAULT_SANDBOX_AUTOMATION_SETTINGS = [
        'enabled' => false,
        'recurrence' => 'weekly',
        'sandbox_path' => '',
    ];

    private const VALID_SANDBOX_AUTOMATION_RECURRENCES = [
        'disabled',
        'every_five_minutes',
        'every_fifteen_minutes',
        'hourly',
        'twice_daily',
        'daily',
        'weekly',
        'monthly',
    ];

    private const DEFAULT_MANAGED_REPLICATION_SETTINGS = [
        'enabled' => false,
        'primary' => [
            'provider' => 'aws_glacier',
            'region' => '',
        ],
        'replica' => [
            'provider' => 'azure_ra_grs',
            'region' => '',
            'secondary_region' => '',
        ],
        'retention' => [
            'retain_by_number' => 3,
            'retain_by_age_days' => 0,
        ],
        'expected_copies' => 2,
    ];

    private const MANAGED_REPLICATION_PROVIDER_BLUEPRINT = [
        'aws_glacier' => [
            'label' => 'Amazon S3 Glacier',
            'destination_id' => 'aws_s3',
            'regions' => [
                'us-east-1' => 'USA Est (Virginie du Nord)',
                'us-west-2' => 'USA Ouest (Oregon)',
                'eu-west-1' => 'Europe (Irlande)',
                'eu-west-3' => 'Europe (Paris)',
                'ap-south-1' => 'Asie Pacifique (Mumbai)',
            ],
        ],
        'azure_ra_grs' => [
            'label' => 'Azure Blob RA-GRS',
            'destination_id' => 'azure_blob',
            'regions' => [
                'francecentral' => 'France Central',
                'northeurope' => 'Europe Nord',
                'westeurope' => 'Europe Ouest',
                'uksouth' => 'UK South',
                'centralus' => 'Centre des États-Unis',
            ],
        ],
    ];

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
        'incremental' => [
            'max_incrementals' => 10,
            'max_full_age_days' => 30,
            'rotation_enabled' => true,
        ],
        'notifications' => [
            'enabled' => false,
            'email_recipients' => '',
            'events' => [
                'backup_complete' => true,
                'backup_failed' => true,
                'cleanup_complete' => false,
                'storage_warning' => true,
                'remote_purge_failed' => true,
                'remote_purge_delayed' => true,
                'remote_storage_forecast_warning' => true,
                'restore_self_test_passed' => false,
                'restore_self_test_failed' => true,
            ],
            'channels' => [
                'email' => ['enabled' => false],
                'slack' => ['enabled' => false, 'webhook_url' => ''],
                'discord' => ['enabled' => false, 'webhook_url' => ''],
                'teams' => ['enabled' => false, 'webhook_url' => ''],
                'sms' => ['enabled' => false, 'webhook_url' => ''],
            ],
            'quiet_hours' => [
                'enabled' => false,
                'start' => '22:00',
                'end' => '07:00',
                'allow_critical' => true,
                'timezone' => '',
            ],
            'escalation' => [
                'enabled' => false,
                'delay_minutes' => 15,
                'only_critical' => true,
                'channels' => [
                    'email' => false,
                    'slack' => false,
                    'discord' => false,
                    'teams' => false,
                    'sms' => true,
                ],
                'mode' => 'simple',
                'stages' => [
                    'slack' => [
                        'enabled' => false,
                        'delay_minutes' => 15,
                    ],
                    'discord' => [
                        'enabled' => false,
                        'delay_minutes' => 15,
                    ],
                    'teams' => [
                        'enabled' => false,
                        'delay_minutes' => 15,
                    ],
                    'sms' => [
                        'enabled' => false,
                        'delay_minutes' => 30,
                    ],
                ],
            ],
            'templates' => [],
        ],
        'update_guard' => [
            'enabled' => true,
            'mode' => 'full',
            'components' => ['db', 'plugins', 'themes', 'uploads'],
            'targets' => [
                'core' => true,
                'plugin' => true,
                'theme' => true,
            ],
            'reminder' => [
                'enabled' => false,
                'message' => 'Pensez à déclencher une sauvegarde manuelle avant d\'appliquer vos mises à jour.',
                'delay_minutes' => 0,
                'channels' => [
                    'notification' => [
                        'enabled' => false,
                    ],
                    'email' => [
                        'enabled' => false,
                        'recipients' => '',
                    ],
                ],
            ],
        ],
        'performance' => [
            'multi_threading' => false,
            'max_workers' => 2,
            'chunk_size' => 50,
            'compression_level' => 6
        ],
        'monitoring' => [
            'storage_quota_warning_threshold' => 85,
            'remote_metrics_ttl_minutes' => 15,
            'remote_capacity_warning_hours' => 72,
            'remote_capacity_critical_hours' => 24,
        ],
        'gdrive' => [
            'client_id' => '',
            'client_secret' => '',
            'folder_id' => '',
            'enabled' => false,
        ],
        'dropbox' => [
            'access_token' => '',
            'folder' => '',
            'enabled' => false,
        ],
        'onedrive' => [
            'access_token' => '',
            'folder' => '',
            'enabled' => false,
        ],
        'pcloud' => [
            'access_token' => '',
            'folder' => '',
            'enabled' => false,
        ],
        's3' => [
            'access_key' => '',
            'secret_key' => '',
            'region' => '',
            'bucket' => '',
            'kms_key_id' => '',
            'server_side_encryption' => '',
            'object_prefix' => '',
            'enabled' => false,
        ],
        'wasabi' => [
            'access_key' => '',
            'secret_key' => '',
            'region' => '',
            'bucket' => '',
            'object_prefix' => '',
            'enabled' => false,
        ],
        'managed_vault' => [
            'access_key' => '',
            'secret_key' => '',
            'bucket' => '',
            'region' => '',
            'primary_region' => '',
            'replica_regions' => [],
            'object_prefix' => '',
            'immutability_days' => 0,
            'retention_max_versions' => 20,
            'credential_strategy' => 'manual',
            'credential_rotation_interval' => 90,
            'last_credential_rotation' => 0,
            'latency_budget_ms' => 4000,
            'object_lock_mode' => 'GOVERNANCE',
            'versioning' => true,
            'server_side_encryption' => '',
            'kms_key_id' => '',
            'enabled' => false,
        ],
        'azure_blob' => [
            'account_name' => '',
            'account_key' => '',
            'container' => '',
            'object_prefix' => '',
            'endpoint_suffix' => 'core.windows.net',
            'chunk_size_mb' => 4,
            'use_https' => true,
            'enabled' => false,
        ],
        'backblaze_b2' => [
            'key_id' => '',
            'application_key' => '',
            'bucket_id' => '',
            'bucket_name' => '',
            'object_prefix' => '',
            'chunk_size_mb' => 100,
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
            'custom_backup_dir' => '',
            'remote_storage_threshold' => self::DEFAULT_REMOTE_STORAGE_THRESHOLD,
        ],
        'managed_replication' => self::DEFAULT_MANAGED_REPLICATION_SETTINGS,
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

    private $default_backup_presets = [];

    /**
     * Retourne la définition des étapes d’escalade pour les formulaires.
     *
     * @return array<string,array<string,mixed>>
     */
    private function get_escalation_stage_blueprint(): array {
        if (class_exists(BJLG_Notifications::class) && method_exists(BJLG_Notifications::class, 'get_escalation_stage_blueprint')) {
            return BJLG_Notifications::get_escalation_stage_blueprint();
        }

        return [
            'slack' => ['default_delay_minutes' => 15],
            'discord' => ['default_delay_minutes' => 15],
            'teams' => ['default_delay_minutes' => 15],
            'sms' => ['default_delay_minutes' => 30],
        ];
    }

    /**
     * Retourne la définition des modèles de notification pour les formulaires.
     */
    private function get_notification_template_blueprint(): array {
        if (class_exists(BJLG_Notifications::class) && method_exists(BJLG_Notifications::class, 'get_severity_template_blueprint')) {
            return BJLG_Notifications::get_severity_template_blueprint();
        }

        return [
            'info' => [
                'label' => __('Information', 'backup-jlg'),
                'intro' => __('Mise à jour de routine pour votre visibilité.', 'backup-jlg'),
                'outro' => __('Aucune action immédiate n’est requise.', 'backup-jlg'),
                'resolution' => __('Archivez l’événement une fois les vérifications terminées.', 'backup-jlg'),
                'intent' => 'info',
                'actions' => [
                    __('Ajoutez un commentaire dans l’historique si une vérification manuelle a été effectuée.', 'backup-jlg'),
                ],
            ],
            'warning' => [
                'label' => __('Avertissement', 'backup-jlg'),
                'intro' => __('Surveillez l’incident : une intervention préventive peut être nécessaire.', 'backup-jlg'),
                'outro' => __('Planifiez une action de suivi si la situation persiste.', 'backup-jlg'),
                'resolution' => __('Actualisez l’état dans le panneau Monitoring pour informer l’équipe.', 'backup-jlg'),
                'intent' => 'warning',
                'actions' => [
                    __('Vérifiez la capacité de stockage et les dernières purges distantes.', 'backup-jlg'),
                    __('Planifiez un nouveau point de contrôle pour confirmer que l’alerte diminue.', 'backup-jlg'),
                ],
            ],
            'critical' => [
                'label' => __('Critique', 'backup-jlg'),
                'intro' => __('Action immédiate recommandée : l’incident est suivi et sera escaladé.', 'backup-jlg'),
                'outro' => __('Une escalade automatique sera déclenchée si le statut ne change pas.', 'backup-jlg'),
                'resolution' => __('Consignez la résolution dans le tableau de bord pour clôturer l’escalade.', 'backup-jlg'),
                'intent' => 'error',
                'actions' => [
                    __('Inspectez les journaux détaillés et identifiez la dernière action réussie.', 'backup-jlg'),
                    __('Contactez l’astreinte et préparez un plan de remédiation ou de restauration.', 'backup-jlg'),
                ],
            ],
        ];
    }

    private $active_context = [];

    private function with_context(array $context, callable $callback)
    {
        $previous = $this->active_context;
        $this->active_context = $context;

        try {
            return $callback();
        } finally {
            $this->active_context = $previous;
        }
    }

    private function context_args(array $args = []): array
    {
        if (empty($this->active_context)) {
            return $args;
        }

        return array_merge($this->active_context, $args);
    }

    private function get_option_value(string $option_name, $default = null, array $args = [])
    {
        return \bjlg_get_option($option_name, $default, $this->context_args($args));
    }

    private function update_option_value(string $option_name, $value, array $args = []): bool
    {
        return (bool) \bjlg_update_option($option_name, $value, $this->context_args($args));
    }

    private function delete_option_value(string $option_name, array $args = []): bool
    {
        return (bool) \bjlg_delete_option($option_name, $this->context_args($args));
    }

    private function resolve_request_context_from_input(array $input): array
    {
        $context = [];

        if (isset($input['site_id'])) {
            $site_id = is_scalar($input['site_id']) ? (int) $input['site_id'] : 0;
            if ($site_id > 0) {
                $context['site_id'] = $site_id;
            }
        }

        if (isset($input['scope']) && sanitize_key((string) $input['scope']) === 'network') {
            if (function_exists('is_multisite') && is_multisite()) {
                $context['network'] = true;
                unset($context['site_id']);
            }
        }

        return $context;
    }

    private function ensure_request_context_capabilities(array $context): void
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return;
        }

        if (!empty($context['network']) && !current_user_can('manage_network_options')) {
            wp_send_json_error(['message' => __('Permissions réseau insuffisantes.', 'backup-jlg')], 403);
        }

        if (isset($context['site_id'])) {
            $site_id = (int) $context['site_id'];
            if ($site_id > 0 && function_exists('get_current_blog_id') && $site_id !== get_current_blog_id()) {
                if (!current_user_can('manage_network_options')) {
                    wp_send_json_error(['message' => __('Permissions multisite insuffisantes pour ce site.', 'backup-jlg')], 403);
                }
            }
        }
    }

    private function execute_in_site_scope(array $context, callable $callback)
    {
        $site_id = isset($context['site_id']) ? (int) $context['site_id'] : null;
        $is_network = !empty($context['network']);

        if ($is_network) {
            return \bjlg_with_network($callback);
        }

        if ($site_id !== null && $site_id > 0) {
            return \bjlg_with_site($site_id, $callback);
        }

        return $callback();
    }

    /**
     * Fusionne récursivement les réglages existants avec les valeurs par défaut.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public static function merge_settings_with_defaults(array $current, array $defaults): array {
        foreach ($defaults as $key => $default_value) {
            if (array_key_exists($key, $current)) {
                $current_value = $current[$key];

                if (is_array($default_value)) {
                    $current[$key] = self::merge_settings_with_defaults(
                        is_array($current_value) ? $current_value : [],
                        $default_value
                    );
                }

                continue;
            }

            $current[$key] = is_array($default_value)
                ? self::merge_settings_with_defaults([], $default_value)
                : $default_value;
        }

        return $current;
    }

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
        add_action('wp_ajax_bjlg_get_backup_presets', [$this, 'handle_get_backup_presets']);
        add_action('wp_ajax_bjlg_save_backup_preset', [$this, 'handle_save_backup_preset']);
        add_action('wp_ajax_bjlg_send_notification_test', [$this, 'handle_send_notification_test']);

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
            $option_name = $this->get_option_name_for_section($key);
            $stored = $this->get_option_value($option_name, null);

            if ($stored === null) {
                $this->update_option_value($option_name, $defaults);
                continue;
            }

            if (!is_array($stored)) {
                $stored = [];
            }

            $merged = self::merge_settings_with_defaults($stored, $defaults);

            if ($merged !== $stored) {
                $this->update_option_value($option_name, $merged);
            }
        }

        if ($this->get_option_value('bjlg_required_capability', null) === null) {
            $this->update_option_value('bjlg_required_capability', \BJLG_DEFAULT_CAPABILITY);
        }

        $this->init_backup_preferences_defaults();
    }

    /**
     * Retourne les réglages d'une section fusionnés avec les valeurs par défaut.
     *
     * @param string $section
     * @return array<string, mixed>
     */
    private function get_section_settings_with_defaults($section): array {
        $option_name = $this->get_option_name_for_section($section);
        $stored = $this->get_option_value($option_name, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        if (!isset($this->default_settings[$section])) {
            return $stored;
        }

        return self::merge_settings_with_defaults($stored, $this->default_settings[$section]);
    }

    /**
     * Calcule le nom d'option associé à une section donnée.
     */
    private function get_option_name_for_section($section): string {
        $map = [
            'notifications' => 'bjlg_notification_settings',
        ];

        if (isset($map[$section])) {
            return $map[$section];
        }

        return 'bjlg_' . $section . '_settings';
    }

    /**
     * Gère la requête AJAX pour sauvegarder tous les réglages.
     */
    public function handle_save_settings() {
        if (!\bjlg_can_manage_settings()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        $context = $this->resolve_request_context_from_input($_POST);
        $this->ensure_request_context_capabilities($context);

        try {
            $saved_settings = [];

            $operation = function () use (&$saved_settings) {
            
            // --- Réglages de la Rétention ---
            if (isset($_POST['by_number']) || isset($_POST['by_age'])) {
                $cleanup_settings = [
                    'by_number' => isset($_POST['by_number']) ? max(0, intval(wp_unslash($_POST['by_number']))) : 3,
                    'by_age'    => isset($_POST['by_age']) ? max(0, intval(wp_unslash($_POST['by_age']))) : 0,
                ];
                $this->update_option_value('bjlg_cleanup_settings', $cleanup_settings);
                $saved_settings['cleanup'] = $cleanup_settings;
                BJLG_Debug::log("Réglages de nettoyage sauvegardés : " . print_r($cleanup_settings, true));
            }

            // --- Réglages des sauvegardes incrémentales ---
            if (
                isset($_POST['incremental_max_incrementals'])
                || isset($_POST['incremental_max_age'])
                || array_key_exists('incremental_rotation_enabled', $_POST)
            ) {
                $current_incremental = $this->get_section_settings_with_defaults('incremental');

                $max_incrementals = isset($_POST['incremental_max_incrementals'])
                    ? max(0, intval(wp_unslash($_POST['incremental_max_incrementals'])))
                    : (isset($current_incremental['max_incrementals']) ? max(0, intval($current_incremental['max_incrementals'])) : 10);
                $max_age_days = isset($_POST['incremental_max_age'])
                    ? max(0, intval(wp_unslash($_POST['incremental_max_age'])))
                    : (isset($current_incremental['max_full_age_days']) ? max(0, intval($current_incremental['max_full_age_days'])) : 30);
                $rotation_enabled = array_key_exists('incremental_rotation_enabled', $_POST)
                    ? $this->to_bool(wp_unslash($_POST['incremental_rotation_enabled']))
                    : (!empty($current_incremental['rotation_enabled']));

                $incremental_settings = [
                    'max_incrementals' => $max_incrementals,
                    'max_full_age_days' => $max_age_days,
                    'rotation_enabled' => $rotation_enabled,
                ];

                $this->update_option_value('bjlg_incremental_settings', $incremental_settings);
                $saved_settings['incremental'] = $incremental_settings;

                BJLG_Debug::log('Réglages incrémentaux sauvegardés : ' . print_r($incremental_settings, true));
            }

            // --- Snapshot pré-mise à jour ---
            $update_guard_fields = [
                'update_guard_enabled',
            'update_guard_mode',
            'update_guard_components',
            'update_guard_targets',
            'update_guard_reminder_enabled',
            'update_guard_reminder_message',
            'update_guard_reminder_channel_notification',
            'update_guard_reminder_channel_email',
            'update_guard_reminder_email_recipients',
            'update_guard_reminder_delay',
        ];
            $update_guard_submitted = false;
            foreach ($update_guard_fields as $field) {
                if (array_key_exists($field, $_POST)) {
                    $update_guard_submitted = true;
                    break;
                }
            }

            if ($update_guard_submitted) {
                $raw_components = isset($_POST['update_guard_components']) ? (array) $_POST['update_guard_components'] : [];
                $components = [];
                $allowed_components = self::get_default_backup_components();

                foreach ($raw_components as $component) {
                    $key = sanitize_key((string) $component);
                    if ($key === '' || !in_array($key, $allowed_components, true) || in_array($key, $components, true)) {
                        continue;
                    }
                    $components[] = $key;
                }

                $raw_mode = isset($_POST['update_guard_mode'])
                    ? sanitize_key(wp_unslash($_POST['update_guard_mode']))
                    : 'full';
                $allowed_modes = ['full', 'targeted'];
                if (!in_array($raw_mode, $allowed_modes, true)) {
                    $raw_mode = 'full';
                }

                $raw_targets = isset($_POST['update_guard_targets']) ? (array) $_POST['update_guard_targets'] : [];
                $sanitized_targets = array_map('sanitize_key', $raw_targets);
                $targets = [];
                $allowed_targets = ['core', 'plugin', 'theme'];
                foreach ($allowed_targets as $target_key) {
                    $targets[$target_key] = in_array($target_key, $sanitized_targets, true);
                }

                $reminder_message = isset($_POST['update_guard_reminder_message'])
                    ? sanitize_text_field(wp_unslash($_POST['update_guard_reminder_message']))
                    : '';

                $email_recipients = isset($_POST['update_guard_reminder_email_recipients'])
                    ? sanitize_textarea_field(wp_unslash($_POST['update_guard_reminder_email_recipients']))
                    : '';

                $delay_minutes = isset($_POST['update_guard_reminder_delay'])
                    ? intval(wp_unslash($_POST['update_guard_reminder_delay']))
                    : 0;
                if ($delay_minutes < 0) {
                    $delay_minutes = 0;
                } elseif ($delay_minutes > 2880) {
                    $delay_minutes = 2880;
                }

                $update_guard_settings = [
                    'enabled' => array_key_exists('update_guard_enabled', $_POST) ? $this->to_bool(wp_unslash($_POST['update_guard_enabled'])) : false,
                    'mode' => $raw_mode,
                    'components' => $components,
                    'targets' => $targets,
                    'reminder' => [
                        'enabled' => array_key_exists('update_guard_reminder_enabled', $_POST) ? $this->to_bool(wp_unslash($_POST['update_guard_reminder_enabled'])) : false,
                        'message' => $reminder_message,
                        'delay_minutes' => $delay_minutes,
                        'channels' => [
                            'notification' => [
                                'enabled' => array_key_exists('update_guard_reminder_channel_notification', $_POST)
                                    ? $this->to_bool(wp_unslash($_POST['update_guard_reminder_channel_notification']))
                                    : false,
                            ],
                            'email' => [
                                'enabled' => array_key_exists('update_guard_reminder_channel_email', $_POST)
                                    ? $this->to_bool(wp_unslash($_POST['update_guard_reminder_channel_email']))
                                    : false,
                                'recipients' => $email_recipients,
                            ],
                        ],
                    ],
                ];

                $this->update_option_value('bjlg_update_guard_settings', $update_guard_settings);
                $saved_settings['update_guard'] = $update_guard_settings;
                BJLG_Debug::log('Réglages du snapshot pré-mise à jour sauvegardés : ' . print_r($update_guard_settings, true));
            }

            // --- Réglages de la Marque Blanche ---
            if (isset($_POST['plugin_name']) || isset($_POST['hide_from_non_admins']) || isset($_POST['required_capability'])) {
                $wl_settings = [
                    'plugin_name'          => isset($_POST['plugin_name']) ? sanitize_text_field(wp_unslash($_POST['plugin_name'])) : '',
                    'hide_from_non_admins' => isset($_POST['hide_from_non_admins']) ? $this->to_bool(wp_unslash($_POST['hide_from_non_admins'])) : false,
                ];
                $this->update_option_value('bjlg_whitelabel_settings', $wl_settings);
                $saved_settings['whitelabel'] = $wl_settings;
                BJLG_Debug::log("Réglages de marque blanche sauvegardés : " . print_r($wl_settings, true));

                if (array_key_exists('required_capability', $_POST)) {
                    $raw_permission = wp_unslash($_POST['required_capability']);
                    $required_capability = $this->sanitize_required_capability_value($raw_permission);
                    $this->update_option_value('bjlg_required_capability', $required_capability);
                    $this->sync_manage_plugin_capability_map($required_capability);
                    $saved_settings['permissions'] = [
                        'required_capability' => $required_capability,
                        'type' => $this->is_role_permission($required_capability) ? 'role' : 'capability',
                    ];
                    BJLG_Debug::log('Permission requise mise à jour : ' . $required_capability);
                }
            }

            // --- Planification des restaurations sandbox ---
            $automation_fields = ['sandbox_schedule_enabled', 'sandbox_schedule_recurrence', 'sandbox_schedule_path'];
            $automation_submitted = false;
            foreach ($automation_fields as $field) {
                if (array_key_exists($field, $_POST)) {
                    $automation_submitted = true;
                    break;
                }
            }

            if ($automation_submitted) {
                $raw_settings = [
                    'enabled' => array_key_exists('sandbox_schedule_enabled', $_POST)
                        ? $this->to_bool(wp_unslash($_POST['sandbox_schedule_enabled']))
                        : false,
                    'recurrence' => isset($_POST['sandbox_schedule_recurrence'])
                        ? wp_unslash($_POST['sandbox_schedule_recurrence'])
                        : self::DEFAULT_SANDBOX_AUTOMATION_SETTINGS['recurrence'],
                    'sandbox_path' => isset($_POST['sandbox_schedule_path'])
                        ? wp_unslash($_POST['sandbox_schedule_path'])
                        : '',
                ];

                $automation_settings = self::sanitize_sandbox_automation_settings($raw_settings);

                if (!$automation_settings['enabled'] || $automation_settings['recurrence'] === 'disabled') {
                    $automation_settings['enabled'] = false;
                    $automation_settings['recurrence'] = 'disabled';
                }

                $this->update_option_value('bjlg_sandbox_automation_settings', $automation_settings);
                $saved_settings['sandbox_automation'] = $automation_settings;

                BJLG_Debug::log('Réglages d’automatisation sandbox mis à jour : ' . print_r($automation_settings, true));
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
                $current_encryption = $this->get_section_settings_with_defaults('encryption');

                $compression_level = isset($_POST['compression_level'])
                    ? max(0, intval(wp_unslash($_POST['compression_level'])))
                    : (isset($current_encryption['compression_level']) ? max(0, intval($current_encryption['compression_level'])) : 6);

                $encryption_settings = [
                    'enabled' => array_key_exists('encryption_enabled', $_POST) ? $this->to_bool(wp_unslash($_POST['encryption_enabled'])) : false,
                    'auto_encrypt' => array_key_exists('auto_encrypt', $_POST) ? $this->to_bool(wp_unslash($_POST['auto_encrypt'])) : false,
                    'password_protect' => array_key_exists('password_protect', $_POST) ? $this->to_bool(wp_unslash($_POST['password_protect'])) : false,
                    'compression_level' => $compression_level,
                ];

                $this->update_option_value('bjlg_encryption_settings', $encryption_settings);
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
                $this->update_option_value('bjlg_gdrive_settings', $gdrive_settings);
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

                $this->update_option_value('bjlg_s3_settings', $s3_settings);
                $saved_settings['s3'] = $s3_settings;
                BJLG_Debug::log('Réglages Amazon S3 sauvegardés.');
            }

            if (!empty($_POST['managed_replication_submitted'])) {
                $managed_settings = $this->sanitize_managed_replication_from_request($_POST);
                $this->update_option_value('bjlg_managed_replication_settings', $managed_settings);
                $saved_settings['managed_replication'] = $managed_settings;
                BJLG_Debug::log('Réglages de réplication managée sauvegardés.');
            }

            // --- Réglages Wasabi ---
            if (isset($_POST['wasabi_access_key']) || isset($_POST['wasabi_bucket'])) {
                $wasabi_settings = [
                    'access_key' => isset($_POST['wasabi_access_key']) ? sanitize_text_field(wp_unslash($_POST['wasabi_access_key'])) : '',
                    'secret_key' => isset($_POST['wasabi_secret_key']) ? sanitize_text_field(wp_unslash($_POST['wasabi_secret_key'])) : '',
                    'region' => isset($_POST['wasabi_region']) ? sanitize_text_field(wp_unslash($_POST['wasabi_region'])) : '',
                    'bucket' => isset($_POST['wasabi_bucket']) ? sanitize_text_field(wp_unslash($_POST['wasabi_bucket'])) : '',
                    'object_prefix' => isset($_POST['wasabi_object_prefix']) ? sanitize_text_field(wp_unslash($_POST['wasabi_object_prefix'])) : '',
                    'enabled' => isset($_POST['wasabi_enabled']) ? $this->to_bool(wp_unslash($_POST['wasabi_enabled'])) : false,
                ];

                $wasabi_settings['object_prefix'] = trim($wasabi_settings['object_prefix']);

                $this->update_option_value('bjlg_wasabi_settings', $wasabi_settings);
                $saved_settings['wasabi'] = $wasabi_settings;
                BJLG_Debug::log('Réglages Wasabi sauvegardés.');
            }

            // --- Réglages Managed Vault ---
            if (isset($_POST['managed_vault_access_key']) || isset($_POST['managed_vault_bucket'])) {
                $replica_input = $_POST['managed_vault_replica_regions'] ?? '';
                $replica_regions = $this->sanitize_region_input($replica_input);

                $primary_region = isset($_POST['managed_vault_primary_region'])
                    ? sanitize_text_field(wp_unslash($_POST['managed_vault_primary_region']))
                    : '';

                $managed_vault_settings = [
                    'access_key' => isset($_POST['managed_vault_access_key']) ? sanitize_text_field(wp_unslash($_POST['managed_vault_access_key'])) : '',
                    'secret_key' => isset($_POST['managed_vault_secret_key']) ? sanitize_text_field(wp_unslash($_POST['managed_vault_secret_key'])) : '',
                    'bucket' => isset($_POST['managed_vault_bucket']) ? sanitize_text_field(wp_unslash($_POST['managed_vault_bucket'])) : '',
                    'object_prefix' => isset($_POST['managed_vault_object_prefix']) ? sanitize_text_field(wp_unslash($_POST['managed_vault_object_prefix'])) : '',
                    'primary_region' => $primary_region,
                    'replica_regions' => $replica_regions,
                    'region' => $primary_region,
                    'immutability_days' => isset($_POST['managed_vault_immutability_days']) ? max(0, (int) $_POST['managed_vault_immutability_days']) : 0,
                    'retention_max_versions' => isset($_POST['managed_vault_retention_versions']) ? max(1, (int) $_POST['managed_vault_retention_versions']) : 20,
                    'credential_strategy' => 'manual',
                    'credential_rotation_interval' => isset($_POST['managed_vault_rotation_interval']) ? max(1, (int) $_POST['managed_vault_rotation_interval']) : 90,
                    'last_credential_rotation' => $this->get_option_value('bjlg_managed_vault_settings', [])['last_credential_rotation'] ?? 0,
                    'latency_budget_ms' => isset($_POST['managed_vault_latency_budget']) ? max(100, (int) $_POST['managed_vault_latency_budget']) : 4000,
                    'object_lock_mode' => 'GOVERNANCE',
                    'versioning' => true,
                    'server_side_encryption' => isset($_POST['managed_vault_server_side_encryption']) ? sanitize_text_field(wp_unslash($_POST['managed_vault_server_side_encryption'])) : '',
                    'kms_key_id' => isset($_POST['managed_vault_kms_key_id']) ? sanitize_text_field(wp_unslash($_POST['managed_vault_kms_key_id'])) : '',
                    'enabled' => isset($_POST['managed_vault_enabled']) ? $this->to_bool(wp_unslash($_POST['managed_vault_enabled'])) : false,
                ];

                if (!in_array($managed_vault_settings['server_side_encryption'], ['', 'AES256', 'aws:kms'], true)) {
                    $managed_vault_settings['server_side_encryption'] = '';
                }

                if ($managed_vault_settings['server_side_encryption'] !== 'aws:kms') {
                    $managed_vault_settings['kms_key_id'] = '';
                }

                if (!empty($managed_vault_settings['server_side_encryption']) && $managed_vault_settings['kms_key_id'] === '') {
                    $managed_vault_settings['kms_key_id'] = '';
                }

                if ($managed_vault_settings['primary_region'] === '' && !empty($managed_vault_settings['replica_regions'])) {
                    $managed_vault_settings['primary_region'] = (string) array_shift($managed_vault_settings['replica_regions']);
                    $managed_vault_settings['region'] = $managed_vault_settings['primary_region'];
                }

                $managed_vault_settings['object_prefix'] = trim($managed_vault_settings['object_prefix']);
                $managed_vault_settings['replica_regions'] = $this->sanitize_region_input($managed_vault_settings['replica_regions']);

                $this->update_option_value('bjlg_managed_vault_settings', $managed_vault_settings);
                $saved_settings['managed_vault'] = $managed_vault_settings;
                BJLG_Debug::log('Réglages Managed Vault sauvegardés.');
            }

            // --- Réglages Dropbox ---
            if (isset($_POST['dropbox_access_token']) || isset($_POST['dropbox_folder'])) {
                $dropbox_settings = [
                    'access_token' => isset($_POST['dropbox_access_token']) ? sanitize_text_field(wp_unslash($_POST['dropbox_access_token'])) : '',
                    'folder' => isset($_POST['dropbox_folder']) ? sanitize_text_field(wp_unslash($_POST['dropbox_folder'])) : '',
                    'enabled' => isset($_POST['dropbox_enabled']) ? $this->to_bool(wp_unslash($_POST['dropbox_enabled'])) : false,
                ];

                $this->update_option_value('bjlg_dropbox_settings', $dropbox_settings);
                $saved_settings['dropbox'] = $dropbox_settings;
                BJLG_Debug::log('Réglages Dropbox sauvegardés.');
            }

            // --- Réglages OneDrive ---
            if (isset($_POST['onedrive_access_token']) || isset($_POST['onedrive_folder'])) {
                $onedrive_settings = [
                    'access_token' => isset($_POST['onedrive_access_token']) ? sanitize_text_field(wp_unslash($_POST['onedrive_access_token'])) : '',
                    'folder' => isset($_POST['onedrive_folder']) ? sanitize_text_field(wp_unslash($_POST['onedrive_folder'])) : '',
                    'enabled' => isset($_POST['onedrive_enabled']) ? $this->to_bool(wp_unslash($_POST['onedrive_enabled'])) : false,
                ];

                $this->update_option_value('bjlg_onedrive_settings', $onedrive_settings);
                $saved_settings['onedrive'] = $onedrive_settings;
                BJLG_Debug::log('Réglages OneDrive sauvegardés.');
            }

            // --- Réglages pCloud ---
            if (isset($_POST['pcloud_access_token']) || isset($_POST['pcloud_folder'])) {
                $pcloud_settings = [
                    'access_token' => isset($_POST['pcloud_access_token']) ? sanitize_text_field(wp_unslash($_POST['pcloud_access_token'])) : '',
                    'folder' => isset($_POST['pcloud_folder']) ? sanitize_text_field(wp_unslash($_POST['pcloud_folder'])) : '',
                    'enabled' => isset($_POST['pcloud_enabled']) ? $this->to_bool(wp_unslash($_POST['pcloud_enabled'])) : false,
                ];

                $this->update_option_value('bjlg_pcloud_settings', $pcloud_settings);
                $saved_settings['pcloud'] = $pcloud_settings;
                BJLG_Debug::log('Réglages pCloud sauvegardés.');
            }


            // --- Réglages de Notifications ---
            $notification_request_fields = [
                'notifications_enabled',
                'email_recipients',
                'notify_backup_complete',
                'notify_backup_failed',
                'notify_cleanup_complete',
                'notify_storage_warning',
                'notify_remote_purge_failed',
                'notify_remote_purge_delayed',
                'notify_remote_storage_forecast_warning',
                'notify_restore_self_test_passed',
                'notify_restore_self_test_failed',
                'channel_email',
                'channel_slack',
                'slack_webhook_url',
                'channel_discord',
                'discord_webhook_url',
            ];

            $should_update_notifications = false;
            foreach ($notification_request_fields as $field) {
                if (array_key_exists($field, $_POST)) {
                    $should_update_notifications = true;
                    break;
                }
            }

            if ($should_update_notifications) {
                $notifications_settings = $this->prepare_notifications_settings_from_request($_POST);

                $this->update_option_value('bjlg_notification_settings', $notifications_settings);
                $saved_settings['notifications'] = $notifications_settings;
                BJLG_Debug::log('Réglages de notifications sauvegardés.');
            }

            // --- Réglages de Performance ---
            $performance_fields = ['multi_threading', 'max_workers', 'chunk_size', 'compression_level'];
            $should_update_performance = false;
            foreach ($performance_fields as $field) {
                if (array_key_exists($field, $_POST)) {
                    $should_update_performance = true;
                    break;
                }
            }

            if ($should_update_performance) {
                $performance_defaults = $this->default_settings['performance'];
                $performance_settings = $this->get_option_value('bjlg_performance_settings', []);
                if (!is_array($performance_settings)) {
                    $performance_settings = [];
                }
                $performance_settings = wp_parse_args($performance_settings, $performance_defaults);

                if (array_key_exists('multi_threading', $_POST)) {
                    $performance_settings['multi_threading'] = $this->to_bool(wp_unslash($_POST['multi_threading']));
                }
                if (array_key_exists('max_workers', $_POST)) {
                    $performance_settings['max_workers'] = max(1, min(20, intval(wp_unslash($_POST['max_workers']))));
                }
                if (array_key_exists('chunk_size', $_POST)) {
                    $performance_settings['chunk_size'] = max(1, min(500, intval(wp_unslash($_POST['chunk_size']))));
                }
                if (array_key_exists('compression_level', $_POST)) {
                    $performance_settings['compression_level'] = min(9, max(0, intval(wp_unslash($_POST['compression_level']))));
                }

                $this->update_option_value('bjlg_performance_settings', $performance_settings);
                $saved_settings['performance'] = $performance_settings;
                BJLG_Debug::log('Réglages de performance sauvegardés.');
            }

            if (
                isset($_POST['storage_quota_warning_threshold'])
                || isset($_POST['remote_metrics_ttl_minutes'])
                || isset($_POST['remote_capacity_warning_hours'])
                || isset($_POST['remote_capacity_critical_hours'])
            ) {
                $monitoring_defaults = $this->default_settings['monitoring'];
                $monitoring_settings = $this->get_option_value('bjlg_monitoring_settings', []);
                if (!is_array($monitoring_settings)) {
                    $monitoring_settings = [];
                }
                $monitoring_settings = wp_parse_args($monitoring_settings, $monitoring_defaults);

                if (isset($_POST['storage_quota_warning_threshold'])) {
                    $threshold = floatval(wp_unslash($_POST['storage_quota_warning_threshold']));
                    $monitoring_settings['storage_quota_warning_threshold'] = max(1.0, min(100.0, $threshold));
                }

                if (isset($_POST['remote_metrics_ttl_minutes'])) {
                    $ttl_minutes = intval(wp_unslash($_POST['remote_metrics_ttl_minutes']));
                    $monitoring_settings['remote_metrics_ttl_minutes'] = max(5, min(1440, $ttl_minutes));
                }

                if (isset($_POST['remote_capacity_warning_hours'])) {
                    $warning_hours = intval(wp_unslash($_POST['remote_capacity_warning_hours']));
                    $monitoring_settings['remote_capacity_warning_hours'] = max(1, min(24 * 7, $warning_hours));
                }

                if (isset($_POST['remote_capacity_critical_hours'])) {
                    $critical_hours = intval(wp_unslash($_POST['remote_capacity_critical_hours']));
                    $warning_reference = isset($monitoring_settings['remote_capacity_warning_hours'])
                        ? (int) $monitoring_settings['remote_capacity_warning_hours']
                        : 72;
                    $monitoring_settings['remote_capacity_critical_hours'] = max(1, min($warning_reference, $critical_hours));
                }

                $this->update_option_value('bjlg_monitoring_settings', $monitoring_settings);
                $saved_settings['monitoring'] = $monitoring_settings;
                BJLG_Debug::log('Réglages de monitoring sauvegardés : ' . print_r($monitoring_settings, true));
            }

            // --- Réglages Webhooks ---
            $webhook_fields = [
                'webhook_enabled',
                'webhook_backup_complete',
                'webhook_backup_failed',
                'webhook_cleanup_complete',
                'webhook_secret',
            ];

            $should_update_webhooks = false;
            foreach ($webhook_fields as $field) {
                if (array_key_exists($field, $_POST)) {
                    $should_update_webhooks = true;
                    break;
                }
            }

            if ($should_update_webhooks) {
                $webhook_defaults = [
                    'enabled' => false,
                    'urls' => [
                        'backup_complete' => '',
                        'backup_failed' => '',
                        'cleanup_complete' => '',
                    ],
                    'secret' => '',
                ];

                $webhook_settings = $this->get_option_value('bjlg_webhook_settings', []);
                if (!is_array($webhook_settings)) {
                    $webhook_settings = [];
                }
                $webhook_settings = wp_parse_args($webhook_settings, $webhook_defaults);
                $webhook_settings['urls'] = isset($webhook_settings['urls']) && is_array($webhook_settings['urls'])
                    ? wp_parse_args($webhook_settings['urls'], $webhook_defaults['urls'])
                    : $webhook_defaults['urls'];

                if (array_key_exists('webhook_enabled', $_POST)) {
                    $webhook_settings['enabled'] = $this->to_bool(wp_unslash($_POST['webhook_enabled']));
                }

                $webhook_labels = [
                    'backup_complete' => 'de sauvegarde terminée',
                    'backup_failed' => "d'échec de sauvegarde",
                    'cleanup_complete' => 'de fin de nettoyage',
                ];

                foreach ($webhook_labels as $url_key => $label) {
                    $field = 'webhook_' . $url_key;
                    $source = array_key_exists($field, $_POST)
                        ? wp_unslash($_POST[$field])
                        : ($webhook_settings['urls'][$url_key] ?? '');
                    $webhook_settings['urls'][$url_key] = $this->validate_optional_url($source, $label);
                }

                if (array_key_exists('webhook_secret', $_POST)) {
                    $webhook_settings['secret'] = sanitize_text_field(wp_unslash($_POST['webhook_secret']));
                }

                if (!empty($webhook_settings['enabled'])) {
                    $non_empty = array_filter($webhook_settings['urls'], static function ($value) {
                        return is_string($value) && $value !== '';
                    });
                    if (empty($non_empty)) {
                        throw new Exception('Veuillez renseigner au moins une URL de webhook active lorsque les webhooks personnalisés sont activés.');
                    }
                }

                $this->update_option_value('bjlg_webhook_settings', $webhook_settings);
                $saved_settings['webhooks'] = $webhook_settings;
                BJLG_Debug::log('Réglages de webhooks sauvegardés.');
            }

            // --- Réglage du débogueur AJAX ---
            if (isset($_POST['ajax_debug_enabled'])) {
                $ajax_debug_enabled = $this->to_bool(wp_unslash($_POST['ajax_debug_enabled']));
                $this->update_option_value('bjlg_ajax_debug_enabled', $ajax_debug_enabled);
                $saved_settings['ajax_debug'] = $ajax_debug_enabled;
                BJLG_Debug::log("Réglage du débogueur AJAX mis à jour.");
            }

            };

            $this->execute_in_site_scope($context, function () use ($operation, $context) {
                return $this->with_context($context, $operation);
            });

            BJLG_History::log('settings_updated', 'success', 'Les réglages ont été mis à jour.');
            
            do_action('bjlg_settings_saved', $saved_settings);
            
            wp_send_json_success([
                'message' => 'Réglages sauvegardés avec succès !',
                'saved' => $saved_settings
            ]);

        } catch (Exception $e) {
            BJLG_History::log('settings_updated', 'failure', 'Erreur : ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        } finally {
            if ($site_switched) {
                BJLG_Site_Context::restore_site($site_switched);
            }
        }
    }

    public function handle_get_backup_presets() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $presets = array_values(self::get_backup_presets());

        wp_send_json_success([
            'presets' => $presets,
        ]);
    }

    public function handle_save_backup_preset() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $raw_name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        if ($raw_name === '') {
            wp_send_json_error(['message' => "Le nom du modèle est requis."]);
        }

        $raw_preset = $_POST['preset'] ?? [];
        if (is_string($raw_preset)) {
            $decoded = json_decode(wp_unslash($raw_preset), true);
            $raw_preset = is_array($decoded) ? $decoded : [];
        } elseif (is_array($raw_preset)) {
            $raw_preset = wp_unslash($raw_preset);
        } else {
            $raw_preset = [];
        }

        if (!isset($raw_preset['label']) || !is_string($raw_preset['label']) || trim($raw_preset['label']) === '') {
            $raw_preset['label'] = $raw_name;
        }

        $preset_id = isset($_POST['preset_id']) ? sanitize_key(wp_unslash($_POST['preset_id'])) : '';

        $sanitized = self::sanitize_backup_preset($raw_preset, $preset_id !== '' ? $preset_id : $raw_name);
        if (!$sanitized) {
            wp_send_json_error(['message' => "Impossible d'enregistrer le modèle fourni."]);
        }

        if ($preset_id !== '') {
            $sanitized['id'] = $preset_id;
        }

        $existing = self::sanitize_backup_presets($this->get_option_value('bjlg_backup_presets', []));
        $existing[$sanitized['id']] = $sanitized;

        $this->update_option_value('bjlg_backup_presets', $existing);

        BJLG_Debug::log('Modèle de sauvegarde enregistré : ' . $sanitized['id']);

        wp_send_json_success([
            'message' => sprintf('Modèle "%s" enregistré avec succès.', $sanitized['label']),
            'saved' => $sanitized,
            'presets' => array_values($existing),
        ]);
    }

    /**
     * Déclenche l'envoi d'une notification de test sur les canaux actifs.
     */
    public function handle_send_notification_test() {
        if (!\bjlg_can_manage_settings()) {
            wp_send_json_error(['message' => __('Permission refusée.', 'backup-jlg')], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        try {
            $request = is_array($_POST) ? $_POST : [];
            $notifications_settings = $this->prepare_notifications_settings_from_request($request);
        } catch (Exception $exception) {
            wp_send_json_error(['message' => $exception->getMessage()], 400);
        }

        $notifications = BJLG_Notifications::instance();
        $result = $notifications->send_test_notification($notifications_settings);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        $channels = isset($result['channels']) && is_array($result['channels'])
            ? array_values(array_filter(array_map('strval', $result['channels'])))
            : [];

        $labels = [];
        $map = [
            'email' => __('e-mail', 'backup-jlg'),
            'slack' => __('Slack', 'backup-jlg'),
            'discord' => __('Discord', 'backup-jlg'),
        ];

        foreach ($channels as $channel) {
            $labels[] = $map[$channel] ?? $channel;
        }

        if (empty($labels)) {
            $labels[] = __('aucun canal actif', 'backup-jlg');
        }

        $message = sprintf(
            __('Notification de test planifiée pour %s.', 'backup-jlg'),
            implode(', ', $labels)
        );

        wp_send_json_success([
            'message' => $message,
            'channels' => $channels,
            'entry_id' => isset($result['entry']['id']) ? (string) $result['entry']['id'] : '',
        ]);
    }

    /**
     * Récupère tous les paramètres
     */
    public function handle_get_settings() {
        if (!\bjlg_can_manage_settings()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }

        $context = $this->resolve_request_context_from_input($_REQUEST);
        $this->ensure_request_context_capabilities($context);

        $settings = $this->execute_in_site_scope($context, function () use ($context) {
            return $this->with_context($context, function () {
                $required_permission = \bjlg_get_required_capability();

                return [
                    'cleanup' => $this->get_section_settings_with_defaults('cleanup'),
                    'whitelabel' => $this->get_section_settings_with_defaults('whitelabel'),
                    'encryption' => $this->get_section_settings_with_defaults('encryption'),
                    'notifications' => $this->get_section_settings_with_defaults('notifications'),
                    'performance' => $this->get_section_settings_with_defaults('performance'),
                    'monitoring' => $this->get_section_settings_with_defaults('monitoring'),
                    'gdrive' => $this->get_section_settings_with_defaults('gdrive'),
                    's3' => $this->get_section_settings_with_defaults('s3'),
                    'wasabi' => $this->get_section_settings_with_defaults('wasabi'),
                    'managed_vault' => $this->get_section_settings_with_defaults('managed_vault'),
                    'dropbox' => $this->get_section_settings_with_defaults('dropbox'),
                    'onedrive' => $this->get_section_settings_with_defaults('onedrive'),
                    'pcloud' => $this->get_section_settings_with_defaults('pcloud'),
                    'azure_blob' => $this->get_section_settings_with_defaults('azure_blob'),
                    'backblaze_b2' => $this->get_section_settings_with_defaults('backblaze_b2'),
                    'sftp' => $this->get_section_settings_with_defaults('sftp'),
                    'advanced' => $this->get_section_settings_with_defaults('advanced'),
                    'webhooks' => $this->get_option_value('bjlg_webhook_settings', []),
                    'schedule' => $this->get_option_value('bjlg_schedule_settings', []),
                    'permissions' => [
                        'required_capability' => $required_permission,
                        'type' => $this->is_role_permission($required_permission) ? 'role' : 'capability',
                    ],
                    'ajax_debug' => $this->get_option_value('bjlg_ajax_debug_enabled', false)
                ];
            });
        });

        wp_send_json_success($settings);
    }
    
    /**
     * Réinitialise tous les paramètres
     */
    public function handle_reset_settings() {
        if (!\bjlg_can_manage_settings()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        $context = $this->resolve_request_context_from_input($_POST);
        $this->ensure_request_context_capabilities($context);

        $section = isset($_POST['section'])
            ? sanitize_text_field(wp_unslash($_POST['section']))
            : 'all';

        try {
            $operation = function () use ($section) {
                if ($section === 'all') {
                    foreach ($this->default_settings as $key => $defaults) {
                        $this->update_option_value($this->get_option_name_for_section($key), $defaults);
                    }
                    $this->update_option_value('bjlg_required_capability', \BJLG_DEFAULT_CAPABILITY);
                    $this->sync_manage_plugin_capability_map(\BJLG_DEFAULT_CAPABILITY);
                    BJLG_History::log('settings_reset', 'info', 'Tous les réglages ont été réinitialisés');
                } else {
                    if ($section === 'permissions') {
                        $this->update_option_value('bjlg_required_capability', \BJLG_DEFAULT_CAPABILITY);
                        $this->sync_manage_plugin_capability_map(\BJLG_DEFAULT_CAPABILITY);
                        BJLG_History::log('settings_reset', 'info', "Réglages 'permissions' réinitialisés");
                    } elseif (isset($this->default_settings[$section])) {
                        $this->update_option_value($this->get_option_name_for_section($section), $this->default_settings[$section]);
                        BJLG_History::log('settings_reset', 'info', "Réglages '$section' réinitialisés");
                    } else {
                        throw new Exception("Section de réglages invalide.");
                    }
                }
            };

            $this->execute_in_site_scope($context, function () use ($operation, $context) {
                return $this->with_context($context, $operation);
            });

            wp_send_json_success(['message' => 'Réglages réinitialisés avec succès.']);

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Exporte les paramètres
     */
    public function handle_export_settings() {
        if (!\bjlg_can_manage_settings()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        $context = $this->resolve_request_context_from_input($_POST);
        $this->ensure_request_context_capabilities($context);

        $export_payload = $this->execute_in_site_scope($context, function () use ($context) {
            return $this->with_context($context, function () {
                $option_keys = [
                    'bjlg_cleanup_settings',
                    'bjlg_whitelabel_settings',
                    'bjlg_encryption_settings',
                    'bjlg_incremental_settings',
                    'bjlg_notification_settings',
                    'bjlg_performance_settings',
                    'bjlg_monitoring_settings',
                    'bjlg_gdrive_settings',
                    'bjlg_dropbox_settings',
                    'bjlg_onedrive_settings',
                    'bjlg_pcloud_settings',
                    'bjlg_s3_settings',
                    'bjlg_managed_vault_settings',
                    'bjlg_wasabi_settings',
                    'bjlg_azure_blob_settings',
                    'bjlg_backblaze_b2_settings',
                    'bjlg_sftp_settings',
                    'bjlg_webhook_settings',
                    'bjlg_schedule_settings',
                    'bjlg_advanced_settings',
                    'bjlg_backup_include_patterns',
                    'bjlg_backup_exclude_patterns',
                    'bjlg_backup_secondary_destinations',
                    'bjlg_backup_post_checks',
                    'bjlg_backup_presets',
                    'bjlg_required_capability'
                ];

                $settings = [];
                foreach ($option_keys as $key) {
                    $value = $this->get_option_value($key);
                    if ($value !== false) {
                        $settings[$key] = $value;
                    }
                }

                $export_data = [
                    'plugin' => 'Backup JLG',
                    'version' => BJLG_VERSION,
                    'exported_at' => current_time('c'),
                    'site_url' => get_site_url(),
                    'settings' => $settings,
                ];

                BJLG_History::log('settings_exported', 'success', 'Paramètres exportés');

                return $export_data;
            });
        });

        wp_send_json_success([
            'filename' => 'bjlg-settings-' . date('Y-m-d-His') . '.json',
            'data' => base64_encode(json_encode($export_payload, JSON_PRETTY_PRINT)),
        ]);
    }
    
    /**
     * Importe les paramètres
     */
    public function handle_import_settings() {
        if (!\bjlg_can_manage_settings()) {
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
                $this->update_option_value($key, $value);
            }

            if (array_key_exists('bjlg_required_capability', $sanitized_settings)) {
                $this->sync_manage_plugin_capability_map($sanitized_settings['bjlg_required_capability']);
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

            case 'bjlg_required_capability':
                return $this->sanitize_required_capability_value($value);

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

            case 'bjlg_incremental_settings':
                $defaults = $this->default_settings['incremental'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['max_incrementals'])) {
                        $sanitized['max_incrementals'] = max(0, intval($value['max_incrementals']));
                    }
                    if (isset($value['max_full_age_days'])) {
                        $sanitized['max_full_age_days'] = max(0, intval($value['max_full_age_days']));
                    }
                    if (isset($value['rotation_enabled'])) {
                        $sanitized['rotation_enabled'] = $this->to_bool($value['rotation_enabled']);
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
                        'teams' => ['enabled' => false, 'webhook_url' => ''],
                        'sms' => ['enabled' => false, 'webhook_url' => ''],
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

            case 'bjlg_update_guard_settings':
                $defaults = $this->default_settings['update_guard'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (array_key_exists('enabled', $value)) {
                        $sanitized['enabled'] = $this->to_bool($value['enabled']);
                    }

                    if (array_key_exists('components', $value)) {
                        $components = [];
                        if (is_array($value['components'])) {
                            $allowed = self::get_default_backup_components();
                            foreach ($value['components'] as $component) {
                                $component = sanitize_key((string) $component);
                                if ($component !== '' && in_array($component, $allowed, true) && !in_array($component, $components, true)) {
                                    $components[] = $component;
                                }
                            }
                        }

                        $sanitized['components'] = $components;
                    }

                    if (array_key_exists('targets', $value) && is_array($value['targets'])) {
                        $allowed_targets = ['core', 'plugin', 'theme'];
                        $targets = [];
                        foreach ($allowed_targets as $target_key) {
                            $targets[$target_key] = !empty($value['targets'][$target_key]);
                        }
                        $sanitized['targets'] = $targets;
                    }

                    if (isset($value['reminder']) && is_array($value['reminder'])) {
                        $reminder = $value['reminder'];
                        if (array_key_exists('enabled', $reminder)) {
                            $sanitized['reminder']['enabled'] = $this->to_bool($reminder['enabled']);
                        }
                        if (array_key_exists('message', $reminder)) {
                            $sanitized['reminder']['message'] = sanitize_text_field((string) $reminder['message']);
                        }

                        if (isset($reminder['channels']) && is_array($reminder['channels'])) {
                            $channels = $sanitized['reminder']['channels'];

                            if (isset($reminder['channels']['notification']) && is_array($reminder['channels']['notification'])) {
                                $channels['notification']['enabled'] = $this->to_bool($reminder['channels']['notification']['enabled'] ?? false);
                            }

                            if (isset($reminder['channels']['email']) && is_array($reminder['channels']['email'])) {
                                $channels['email']['enabled'] = $this->to_bool($reminder['channels']['email']['enabled'] ?? false);
                                if (array_key_exists('recipients', $reminder['channels']['email'])) {
                                    $channels['email']['recipients'] = sanitize_textarea_field((string) $reminder['channels']['email']['recipients']);
                                }
                            }

                            $sanitized['reminder']['channels'] = $channels;
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

            case 'bjlg_monitoring_settings':
                $defaults = $this->default_settings['monitoring'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['storage_quota_warning_threshold'])) {
                        $sanitized['storage_quota_warning_threshold'] = max(1.0, min(100.0, (float) $value['storage_quota_warning_threshold']));
                    }
                    if (isset($value['remote_metrics_ttl_minutes'])) {
                        $sanitized['remote_metrics_ttl_minutes'] = max(5, min(1440, (int) $value['remote_metrics_ttl_minutes']));
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

            case 'bjlg_dropbox_settings':
                $defaults = $this->default_settings['dropbox'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['access_token'])) {
                        $sanitized['access_token'] = sanitize_text_field((string) $value['access_token']);
                    }
                    if (isset($value['folder'])) {
                        $sanitized['folder'] = sanitize_text_field((string) $value['folder']);
                    }
                    if (isset($value['enabled'])) {
                        $sanitized['enabled'] = $this->to_bool($value['enabled']);
                    }
                }

                return $sanitized;

            case 'bjlg_onedrive_settings':
                $defaults = $this->default_settings['onedrive'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['access_token'])) {
                        $sanitized['access_token'] = sanitize_text_field((string) $value['access_token']);
                    }
                    if (isset($value['folder'])) {
                        $sanitized['folder'] = sanitize_text_field((string) $value['folder']);
                    }
                    if (isset($value['enabled'])) {
                        $sanitized['enabled'] = $this->to_bool($value['enabled']);
                    }
                }

                return $sanitized;

            case 'bjlg_pcloud_settings':
                $defaults = $this->default_settings['pcloud'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['access_token'])) {
                        $sanitized['access_token'] = sanitize_text_field((string) $value['access_token']);
                    }
                    if (isset($value['folder'])) {
                        $sanitized['folder'] = sanitize_text_field((string) $value['folder']);
                    }
                    if (isset($value['enabled'])) {
                        $sanitized['enabled'] = $this->to_bool($value['enabled']);
                    }
                }

                return $sanitized;

            case 'bjlg_s3_settings':
                $defaults = $this->default_settings['s3'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['access_key'])) {
                        $sanitized['access_key'] = sanitize_text_field((string) $value['access_key']);
                    }
                    if (isset($value['secret_key'])) {
                        $sanitized['secret_key'] = sanitize_text_field((string) $value['secret_key']);
                    }
                    if (isset($value['region'])) {
                        $sanitized['region'] = sanitize_text_field((string) $value['region']);
                    }
                    if (isset($value['bucket'])) {
                        $sanitized['bucket'] = sanitize_text_field((string) $value['bucket']);
                    }
                    if (isset($value['server_side_encryption'])) {
                        $sanitized['server_side_encryption'] = sanitize_text_field((string) $value['server_side_encryption']);
                    }
                    if (isset($value['kms_key_id'])) {
                        $sanitized['kms_key_id'] = sanitize_text_field((string) $value['kms_key_id']);
                    }
                    if (isset($value['object_prefix'])) {
                        $sanitized['object_prefix'] = sanitize_text_field((string) $value['object_prefix']);
                    }
                    if (isset($value['enabled'])) {
                        $sanitized['enabled'] = $this->to_bool($value['enabled']);
                    }
                }

                if (!in_array($sanitized['server_side_encryption'], ['AES256', 'aws:kms'], true)) {
                    $sanitized['server_side_encryption'] = '';
                }

                if ($sanitized['server_side_encryption'] !== 'aws:kms') {
                    $sanitized['kms_key_id'] = '';
                }

                $sanitized['object_prefix'] = trim($sanitized['object_prefix']);

                return $sanitized;

            case 'bjlg_wasabi_settings':
                $defaults = $this->default_settings['wasabi'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['access_key'])) {
                        $sanitized['access_key'] = sanitize_text_field((string) $value['access_key']);
                    }
                    if (isset($value['secret_key'])) {
                        $sanitized['secret_key'] = sanitize_text_field((string) $value['secret_key']);
                    }
                    if (isset($value['region'])) {
                        $sanitized['region'] = sanitize_text_field((string) $value['region']);
                    }
                    if (isset($value['bucket'])) {
                        $sanitized['bucket'] = sanitize_text_field((string) $value['bucket']);
                    }
                    if (isset($value['object_prefix'])) {
                        $sanitized['object_prefix'] = sanitize_text_field((string) $value['object_prefix']);
                    }
                    if (isset($value['enabled'])) {
                        $sanitized['enabled'] = $this->to_bool($value['enabled']);
                    }
                }

                $sanitized['object_prefix'] = trim($sanitized['object_prefix']);

                return $sanitized;

            case 'bjlg_managed_vault_settings':
                $defaults = $this->default_settings['managed_vault'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['access_key'])) {
                        $sanitized['access_key'] = sanitize_text_field((string) $value['access_key']);
                    }
                    if (isset($value['secret_key'])) {
                        $sanitized['secret_key'] = sanitize_text_field((string) $value['secret_key']);
                    }
                    if (isset($value['bucket'])) {
                        $sanitized['bucket'] = sanitize_text_field((string) $value['bucket']);
                    }
                    if (isset($value['object_prefix'])) {
                        $sanitized['object_prefix'] = sanitize_text_field((string) $value['object_prefix']);
                    }
                    if (isset($value['primary_region'])) {
                        $sanitized['primary_region'] = sanitize_text_field((string) $value['primary_region']);
                        $sanitized['region'] = $sanitized['primary_region'];
                    }
                    if (isset($value['replica_regions'])) {
                        $sanitized['replica_regions'] = $this->sanitize_region_input($value['replica_regions']);
                    }
                    if (isset($value['immutability_days'])) {
                        $sanitized['immutability_days'] = max(0, (int) $value['immutability_days']);
                    }
                    if (isset($value['retention_max_versions'])) {
                        $sanitized['retention_max_versions'] = max(1, (int) $value['retention_max_versions']);
                    }
                    if (isset($value['latency_budget_ms'])) {
                        $sanitized['latency_budget_ms'] = max(100, (int) $value['latency_budget_ms']);
                    }
                    if (isset($value['object_lock_mode']) && in_array($value['object_lock_mode'], ['GOVERNANCE', 'COMPLIANCE'], true)) {
                        $sanitized['object_lock_mode'] = $value['object_lock_mode'];
                    }
                    if (isset($value['server_side_encryption']) && in_array($value['server_side_encryption'], ['', 'AES256', 'aws:kms'], true)) {
                        $sanitized['server_side_encryption'] = $value['server_side_encryption'];
                    }
                    if ($sanitized['server_side_encryption'] === 'aws:kms' && isset($value['kms_key_id'])) {
                        $sanitized['kms_key_id'] = sanitize_text_field((string) $value['kms_key_id']);
                    }
                    if (isset($value['enabled'])) {
                        $sanitized['enabled'] = $this->to_bool($value['enabled']);
                    }
                }

                $sanitized['object_prefix'] = trim($sanitized['object_prefix']);

                return $sanitized;

            case 'bjlg_azure_blob_settings':
                $defaults = $this->default_settings['azure_blob'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['account_name'])) {
                        $sanitized['account_name'] = sanitize_text_field((string) $value['account_name']);
                    }
                    if (isset($value['account_key'])) {
                        $sanitized['account_key'] = sanitize_text_field((string) $value['account_key']);
                    }
                    if (isset($value['container'])) {
                        $sanitized['container'] = sanitize_text_field((string) $value['container']);
                    }
                    if (isset($value['object_prefix'])) {
                        $sanitized['object_prefix'] = sanitize_text_field((string) $value['object_prefix']);
                    }
                    if (isset($value['endpoint_suffix'])) {
                        $sanitized['endpoint_suffix'] = sanitize_text_field((string) $value['endpoint_suffix']);
                    }
                    if (isset($value['chunk_size_mb'])) {
                        $sanitized['chunk_size_mb'] = max(1, intval($value['chunk_size_mb']));
                    }
                    if (isset($value['use_https'])) {
                        $sanitized['use_https'] = $this->to_bool($value['use_https']);
                    }
                    if (isset($value['enabled'])) {
                        $sanitized['enabled'] = $this->to_bool($value['enabled']);
                    }
                }

                $sanitized['object_prefix'] = trim($sanitized['object_prefix']);

                return $sanitized;

            case 'bjlg_backblaze_b2_settings':
                $defaults = $this->default_settings['backblaze_b2'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['key_id'])) {
                        $sanitized['key_id'] = sanitize_text_field((string) $value['key_id']);
                    }
                    if (isset($value['application_key'])) {
                        $sanitized['application_key'] = sanitize_text_field((string) $value['application_key']);
                    }
                    if (isset($value['bucket_id'])) {
                        $sanitized['bucket_id'] = sanitize_text_field((string) $value['bucket_id']);
                    }
                    if (isset($value['bucket_name'])) {
                        $sanitized['bucket_name'] = sanitize_text_field((string) $value['bucket_name']);
                    }
                    if (isset($value['object_prefix'])) {
                        $sanitized['object_prefix'] = sanitize_text_field((string) $value['object_prefix']);
                    }
                    if (isset($value['chunk_size_mb'])) {
                        $sanitized['chunk_size_mb'] = max(1, intval($value['chunk_size_mb']));
                    }
                    if (isset($value['enabled'])) {
                        $sanitized['enabled'] = $this->to_bool($value['enabled']);
                    }
                }

                $sanitized['object_prefix'] = trim($sanitized['object_prefix']);

                return $sanitized;

            case 'bjlg_sftp_settings':
                $defaults = $this->default_settings['sftp'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['host'])) {
                        $sanitized['host'] = sanitize_text_field((string) $value['host']);
                    }
                    if (isset($value['port'])) {
                        $port = intval($value['port']);
                        $sanitized['port'] = max(1, min(65535, $port));
                    }
                    if (isset($value['username'])) {
                        $sanitized['username'] = sanitize_text_field((string) $value['username']);
                    }
                    if (isset($value['password'])) {
                        $sanitized['password'] = sanitize_text_field((string) $value['password']);
                    }
                    if (isset($value['private_key'])) {
                        $sanitized['private_key'] = sanitize_textarea_field((string) $value['private_key']);
                    }
                    if (isset($value['passphrase'])) {
                        $sanitized['passphrase'] = sanitize_text_field((string) $value['passphrase']);
                    }
                    if (isset($value['remote_path'])) {
                        $sanitized['remote_path'] = sanitize_text_field((string) $value['remote_path']);
                    }
                    if (isset($value['fingerprint'])) {
                        $sanitized['fingerprint'] = sanitize_text_field((string) $value['fingerprint']);
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

            case 'bjlg_advanced_settings':
                $defaults = $this->default_settings['advanced'];
                $sanitized = $defaults;

                if (is_array($value)) {
                    if (isset($value['debug_mode'])) {
                        $sanitized['debug_mode'] = $this->to_bool($value['debug_mode']);
                    }
                    if (isset($value['ajax_debug'])) {
                        $sanitized['ajax_debug'] = $this->to_bool($value['ajax_debug']);
                    }
                    if (isset($value['exclude_patterns'])) {
                        $sanitized['exclude_patterns'] = self::sanitize_pattern_list($value['exclude_patterns']);
                    }
                    if (isset($value['custom_backup_dir'])) {
                        $sanitized['custom_backup_dir'] = sanitize_text_field((string) $value['custom_backup_dir']);
                    }
                    if (isset($value['remote_storage_threshold'])) {
                        $sanitized['remote_storage_threshold'] = self::normalize_ratio(
                            $value['remote_storage_threshold'],
                            (float) $defaults['remote_storage_threshold']
                        );
                    }
                }

                return $sanitized;

            case 'bjlg_backup_include_patterns':
                return self::sanitize_pattern_list($value);

            case 'bjlg_backup_exclude_patterns':
                return self::sanitize_pattern_list($value);

            case 'bjlg_backup_secondary_destinations':
                return self::sanitize_destination_list($value, self::get_known_destination_ids());

            case 'bjlg_backup_post_checks':
                return self::sanitize_post_checks($value, self::get_default_backup_post_checks());

            case 'bjlg_backup_presets':
                return self::sanitize_backup_presets($value);

            default:
                return null;
        }
    }

    /**
     * Synchronise la capability map avec la permission principale.
     */
    private function sync_manage_plugin_capability_map($permission): void {
        if (!function_exists('bjlg_get_capability_map')) {
            return;
        }

        $normalized_permission = is_string($permission) ? sanitize_text_field($permission) : '';
        if ($normalized_permission === '') {
            $normalized_permission = \BJLG_DEFAULT_CAPABILITY;
        }

        $stored_map = $this->get_option_value('bjlg_capability_map', []);
        if (!is_array($stored_map)) {
            $stored_map = [];
        }

        $stored_value = isset($stored_map['manage_plugin']) ? (string) $stored_map['manage_plugin'] : '';

        if ($stored_value === $normalized_permission) {
            return;
        }

        $stored_map['manage_plugin'] = $normalized_permission;

        $this->update_option_value('bjlg_capability_map', $stored_map);
    }

    /**
     * Nettoie et valide la capability ou le rôle requis.
     *
     * @param mixed $value
     * @return string
     * @throws Exception
     */
    private function sanitize_required_capability_value($value) {
        $permission = is_string($value) ? sanitize_text_field($value) : '';

        if ($permission === '') {
            return \BJLG_DEFAULT_CAPABILITY;
        }

        if (!$this->required_capability_exists_in_wp($permission)) {
            throw new Exception(sprintf(
                __('La permission "%s" est introuvable dans WordPress.', 'backup-jlg'),
                $permission
            ));
        }

        return $permission;
    }

    private function sanitize_managed_replication_from_request(array $source): array {
        $defaults = self::get_default_managed_replication_settings();
        $providers = self::get_managed_replication_providers();

        $enabled = !empty($source['managed_replication_enabled'])
            ? $this->to_bool(wp_unslash($source['managed_replication_enabled']))
            : false;

        $primary_provider = isset($source['managed_replication_primary_provider'])
            ? sanitize_key((string) wp_unslash($source['managed_replication_primary_provider']))
            : $defaults['primary']['provider'];
        if (!isset($providers[$primary_provider])) {
            $primary_provider = $defaults['primary']['provider'];
        }

        $primary_region = isset($source['managed_replication_primary_region'])
            ? self::sanitize_managed_replication_region($primary_provider, (string) wp_unslash($source['managed_replication_primary_region']))
            : $defaults['primary']['region'];

        $replica_provider = isset($source['managed_replication_replica_provider'])
            ? sanitize_key((string) wp_unslash($source['managed_replication_replica_provider']))
            : $defaults['replica']['provider'];
        if (!isset($providers[$replica_provider])) {
            $replica_provider = $defaults['replica']['provider'];
        }

        $replica_region = isset($source['managed_replication_replica_region'])
            ? self::sanitize_managed_replication_region($replica_provider, (string) wp_unslash($source['managed_replication_replica_region']))
            : $defaults['replica']['region'];

        $secondary_region = isset($source['managed_replication_replica_secondary'])
            ? sanitize_text_field(wp_unslash($source['managed_replication_replica_secondary']))
            : $defaults['replica']['secondary_region'];

        $retain_number = isset($source['managed_replication_retain_number'])
            ? (int) wp_unslash($source['managed_replication_retain_number'])
            : $defaults['retention']['retain_by_number'];
        $retain_number = max(1, min(50, $retain_number));

        $retain_days = isset($source['managed_replication_retain_days'])
            ? (int) wp_unslash($source['managed_replication_retain_days'])
            : $defaults['retention']['retain_by_age_days'];
        $retain_days = max(0, min(3650, $retain_days));

        $expected_copies = isset($source['managed_replication_expected_copies'])
            ? (int) wp_unslash($source['managed_replication_expected_copies'])
            : $defaults['expected_copies'];
        $expected_copies = max(1, min(5, $expected_copies));

        return [
            'enabled' => $enabled,
            'primary' => [
                'provider' => $primary_provider,
                'region' => $primary_region,
            ],
            'replica' => [
                'provider' => $replica_provider,
                'region' => $replica_region,
                'secondary_region' => $secondary_region,
            ],
            'retention' => [
                'retain_by_number' => $retain_number,
                'retain_by_age_days' => $retain_days,
            ],
            'expected_copies' => $expected_copies,
        ];
    }

    /**
     * Nettoie une liste de régions (string ou array).
     *
     * @param mixed $value
     * @return array<int,string>
     */
    private function sanitize_region_input($value): array
    {
        if (is_string($value)) {
            $tokens = preg_split('/[\s,]+/', wp_unslash($value));
        } elseif (is_array($value)) {
            $tokens = [];
            foreach ($value as $token) {
                if (is_array($token)) {
                    $tokens = array_merge($tokens, $token);
                    continue;
                }
                $tokens[] = $token;
            }
        } else {
            return [];
        }

        $regions = [];
        foreach ($tokens as $token) {
            if (!is_scalar($token)) {
                continue;
            }
            $candidate = strtolower(trim((string) $token));
            if ($candidate === '') {
                continue;
            }
            $candidate = preg_replace('/[^a-z0-9-]/', '', $candidate);
            if ($candidate === '') {
                continue;
            }
            $regions[$candidate] = true;
        }

        return array_values(array_keys($regions));
    }

    /**
     * Vérifie si la permission correspond à un rôle enregistré.
     */
    private function is_role_permission($permission): bool {
        if (!is_string($permission) || $permission === '') {
            return false;
        }

        $roles = function_exists('wp_roles') ? wp_roles() : null;

        return $roles && class_exists('WP_Roles') && $roles instanceof \WP_Roles && $roles->is_role($permission);
    }

    /**
     * Vérifie si la capability ou le rôle existe dans WordPress.
     */
    private function required_capability_exists_in_wp($permission): bool {
        if (!is_string($permission) || $permission === '') {
            return false;
        }

        $roles = function_exists('wp_roles') ? wp_roles() : null;

        if ($roles && class_exists('WP_Roles') && $roles instanceof \WP_Roles) {
            if ($roles->is_role($permission)) {
                return true;
            }

            foreach ($roles->roles as $role) {
                if (isset($role['capabilities'][$permission]) && $role['capabilities'][$permission]) {
                    return true;
                }
            }
        }

        return $permission === \BJLG_DEFAULT_CAPABILITY;
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
            'incremental' => 'bjlg_incremental_settings',
            'notifications' => 'bjlg_notification_settings',
            'update_guard' => 'bjlg_update_guard_settings',
            'performance' => 'bjlg_performance_settings',
            'webhooks' => 'bjlg_webhook_settings',
            'schedule' => 'bjlg_schedule_settings',
            'gdrive' => 'bjlg_gdrive_settings',
            'dropbox' => 'bjlg_dropbox_settings',
            'onedrive' => 'bjlg_onedrive_settings',
            'pcloud' => 'bjlg_pcloud_settings',
            's3' => 'bjlg_s3_settings',
            'managed_vault' => 'bjlg_managed_vault_settings',
            'wasabi' => 'bjlg_wasabi_settings',
            'azure_blob' => 'bjlg_azure_blob_settings',
            'backblaze_b2' => 'bjlg_backblaze_b2_settings',
            'sftp' => 'bjlg_sftp_settings',
            'advanced' => 'bjlg_advanced_settings',
        ];

        if (!isset($option_map[$section])) {
            return null;
        }

        return $this->sanitize_imported_option($option_map[$section], $value);
    }

    private function init_backup_preferences_defaults() {
        $defaults = $this->default_backup_preferences;

        if ($this->get_option_value('bjlg_backup_include_patterns', null) === null) {
            $this->update_option_value('bjlg_backup_include_patterns', $defaults['include_patterns']);
        }

        if ($this->get_option_value('bjlg_backup_exclude_patterns', null) === null) {
            $this->update_option_value('bjlg_backup_exclude_patterns', $defaults['exclude_patterns']);
        }

        if ($this->get_option_value('bjlg_backup_secondary_destinations', null) === null) {
            $this->update_option_value('bjlg_backup_secondary_destinations', $defaults['secondary_destinations']);
        }

        if ($this->get_option_value('bjlg_backup_post_checks', null) === null) {
            $this->update_option_value('bjlg_backup_post_checks', $defaults['post_checks']);
        }

        if ($this->get_option_value('bjlg_backup_presets', null) === null) {
            $this->update_option_value('bjlg_backup_presets', $this->default_backup_presets);
        }
    }

    public function update_backup_filters(array $includes, array $excludes, array $destinations, array $post_checks) {
        $includes = self::sanitize_pattern_list($includes);
        $excludes = self::sanitize_pattern_list($excludes);
        $destinations = self::sanitize_destination_list($destinations, self::get_known_destination_ids());
        $post_checks = self::sanitize_post_checks($post_checks, self::get_default_backup_post_checks());

        $this->update_option_value('bjlg_backup_include_patterns', $includes);
        $this->update_option_value('bjlg_backup_exclude_patterns', $excludes);
        $this->update_option_value('bjlg_backup_secondary_destinations', $destinations);
        $this->update_option_value('bjlg_backup_post_checks', $post_checks);
    }

    public static function get_backup_presets(): array {
        return self::sanitize_backup_presets(
            self::get_instance()->get_option_value('bjlg_backup_presets', [])
        );
    }

    public static function sanitize_backup_presets($presets): array {
        if (is_string($presets)) {
            $decoded = json_decode($presets, true);
            if (is_array($decoded)) {
                $presets = $decoded;
            }
        }

        if (!is_array($presets)) {
            return [];
        }

        $sanitized = [];

        foreach ($presets as $key => $preset) {
            $hint = '';
            if (is_string($key)) {
                $hint = $key;
            } elseif (is_array($preset) && isset($preset['id']) && is_string($preset['id'])) {
                $hint = $preset['id'];
            }

            $normalized = self::sanitize_backup_preset($preset, $hint);
            if (!$normalized) {
                continue;
            }

            $sanitized[$normalized['id']] = $normalized;
        }

        return $sanitized;
    }

    public static function sanitize_backup_preset($preset, string $slug_hint = ''): ?array {
        if (is_string($preset)) {
            $decoded = json_decode($preset, true);
            if (is_array($decoded)) {
                $preset = $decoded;
            }
        }

        if (!is_array($preset)) {
            return null;
        }

        $label = '';
        if (isset($preset['label'])) {
            $label = sanitize_text_field((string) $preset['label']);
        } elseif (isset($preset['name'])) {
            $label = sanitize_text_field((string) $preset['name']);
        }

        if ($label === '' && $slug_hint !== '') {
            $label = ucwords(str_replace('_', ' ', $slug_hint));
        }

        $label = trim($label);
        if ($label === '') {
            return null;
        }

        $id = '';
        if (isset($preset['id'])) {
            $id = sanitize_key((string) $preset['id']);
        }
        if ($id === '' && $slug_hint !== '') {
            $id = sanitize_key($slug_hint);
        }
        if ($id === '') {
            $id = sanitize_key($label);
        }
        if ($id === '') {
            $id = 'bjlg_preset_' . substr(md5($label . microtime(true)), 0, 8);
        }

        $components = self::sanitize_backup_components($preset['components'] ?? []);
        if (empty($components)) {
            $components = self::get_default_backup_components();
        }

        $include_patterns = self::sanitize_pattern_list($preset['include_patterns'] ?? []);
        $exclude_patterns = self::sanitize_pattern_list($preset['exclude_patterns'] ?? []);
        $post_checks = self::sanitize_post_checks($preset['post_checks'] ?? [], self::get_default_backup_post_checks());
        $secondary_destinations = self::sanitize_destination_list(
            $preset['secondary_destinations'] ?? [],
            self::get_known_destination_ids()
        );

        return [
            'id' => $id,
            'label' => $label,
            'components' => array_values($components),
            'encrypt' => self::to_bool_static($preset['encrypt'] ?? false),
            'incremental' => self::to_bool_static($preset['incremental'] ?? false),
            'include_patterns' => $include_patterns,
            'exclude_patterns' => $exclude_patterns,
            'post_checks' => $post_checks,
            'secondary_destinations' => $secondary_destinations,
        ];
    }

    public static function sanitize_backup_components($components): array {
        $components = self::sanitize_schedule_components($components);

        $allowed = self::get_default_backup_components();
        if (empty($components)) {
            return $allowed;
        }

        $filtered = [];
        foreach ($components as $component) {
            if (in_array($component, $allowed, true) && !in_array($component, $filtered, true)) {
                $filtered[] = $component;
            }
        }

        return $filtered;
    }

    public static function get_default_backup_components(): array {
        return ['db', 'plugins', 'themes', 'uploads'];
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

    public static function sanitize_destination_batches($batches, array $allowed_ids): array {
        if (!is_array($batches)) {
            return [];
        }

        $allowed = array_map('strval', $allowed_ids);
        $sanitized = [];
        foreach ($batches as $batch) {
            if (!is_array($batch)) {
                continue;
            }

            $clean_batch = [];
            foreach ($batch as $destination) {
                if (!is_scalar($destination)) {
                    continue;
                }

                $slug = sanitize_key((string) $destination);
                if ($slug === '' || !in_array($slug, $allowed, true)) {
                    continue;
                }

                if (!in_array($slug, $clean_batch, true)) {
                    $clean_batch[] = $slug;
                }
            }

            if (!empty($clean_batch)) {
                $sanitized[] = $clean_batch;
            }
        }

        return $sanitized;
    }

    public static function flatten_destination_batches(array $batches): array {
        $flattened = [];
        foreach ($batches as $batch) {
            if (!is_array($batch)) {
                continue;
            }

            foreach ($batch as $destination) {
                if (!in_array($destination, $flattened, true)) {
                    $flattened[] = $destination;
                }
            }
        }

        return $flattened;
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
            'previous_recurrence' => '',
            'day' => 'sunday',
            'day_of_month' => 1,
            'time' => '23:59',
            'custom_cron' => '',
            'macro' => '',
            'components' => ['db', 'plugins', 'themes', 'uploads'],
            'encrypt' => false,
            'incremental' => false,
            'include_patterns' => [],
            'exclude_patterns' => [],
            'post_checks' => self::get_default_backup_post_checks(),
            'secondary_destinations' => [],
            'secondary_destination_batches' => [],
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

        $day_of_month = $defaults['day_of_month'];
        if (isset($entry['day_of_month'])) {
            $maybe_day = filter_var($entry['day_of_month'], FILTER_VALIDATE_INT);
            if ($maybe_day !== false) {
                $day_of_month = max(1, min(31, (int) $maybe_day));
            }
        }

        $time = isset($entry['time']) ? sanitize_text_field($entry['time']) : $defaults['time'];
        if (!preg_match('/^([0-1]?\d|2[0-3]):([0-5]\d)$/', $time)) {
            $time = $defaults['time'];
        }

        $custom_cron = '';
        if (isset($entry['custom_cron'])) {
            $custom_cron = self::sanitize_cron_expression($entry['custom_cron']);
        }

        $macro = '';
        if (isset($entry['macro'])) {
            $candidate_macro = sanitize_key((string) $entry['macro']);
            if ($candidate_macro !== '' && self::get_schedule_macro_by_id($candidate_macro)) {
                $macro = $candidate_macro;
            }
        }

        $previous_recurrence = '';
        if (isset($entry['previous_recurrence'])) {
            $maybe_previous = sanitize_key((string) $entry['previous_recurrence']);
            if (in_array($maybe_previous, self::VALID_SCHEDULE_RECURRENCES, true)) {
                $previous_recurrence = $maybe_previous;
            }
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

        $destination_batches = self::sanitize_destination_batches(
            $entry['secondary_destination_batches'] ?? $defaults['secondary_destination_batches'],
            self::get_known_destination_ids()
        );

        if (empty($destination_batches) && !empty($secondary_destinations)) {
            $destination_batches = [$secondary_destinations];
        }

        return [
            'id' => $id,
            'label' => $label,
            'recurrence' => $recurrence,
            'day' => $day,
            'day_of_month' => $day_of_month,
            'time' => $time,
            'previous_recurrence' => $previous_recurrence,
            'custom_cron' => $recurrence === 'custom' ? $custom_cron : '',
            'macro' => $recurrence === 'custom' ? $macro : '',
            'components' => array_values($components),
            'encrypt' => self::to_bool_static($entry['encrypt'] ?? $defaults['encrypt']),
            'incremental' => self::to_bool_static($entry['incremental'] ?? $defaults['incremental']),
            'include_patterns' => $include_patterns,
            'exclude_patterns' => $exclude_patterns,
            'post_checks' => $post_checks,
            'secondary_destinations' => $secondary_destinations,
            'secondary_destination_batches' => $destination_batches,
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

    /**
     * Retourne la liste normalisée des macros de planification.
     */
    public static function get_schedule_macro_catalog(): array {
        $catalog = [
            [
                'id' => 'hourly_guard',
                'label' => __('Sauvegarde horaire continue', 'backup-jlg'),
                'description' => __('Capture la base et les extensions à chaque heure pleine pour sécuriser les mises à jour fréquentes.', 'backup-jlg'),
                'expression' => '0 * * * *',
                'category' => 'hourly',
                'adjustments' => [
                    'label' => __('Sauvegarde horaire', 'backup-jlg'),
                    'components' => ['db', 'plugins'],
                    'incremental' => false,
                    'encrypt' => true,
                    'post_checks' => ['checksum'],
                ],
            ],
            [
                'id' => 'pre_deploy',
                'label' => __('Snapshot pré-déploiement', 'backup-jlg'),
                'description' => __('Renforce la fenêtre de changement en déclenchant un snapshot toutes les dix minutes.', 'backup-jlg'),
                'expression' => '*/10 * * * *',
                'category' => 'change-window',
                'adjustments' => [
                    'label' => __('Snapshot pré-déploiement', 'backup-jlg'),
                    'components' => ['db', 'plugins', 'themes'],
                    'incremental' => false,
                    'encrypt' => true,
                    'post_checks' => ['checksum', 'dry_run'],
                ],
            ],
            [
                'id' => 'weekend_snapshot',
                'label' => __('Snapshot week-end', 'backup-jlg'),
                'description' => __('Capture complète le samedi et le dimanche à l’aube pour sécuriser les contenus publiés.', 'backup-jlg'),
                'expression' => '0 5 * * sat,sun',
                'category' => 'weekend',
                'adjustments' => [
                    'label' => __('Snapshot week-end', 'backup-jlg'),
                    'components' => ['db', 'uploads'],
                    'incremental' => true,
                    'encrypt' => false,
                    'post_checks' => ['checksum'],
                ],
            ],
        ];

        $filtered = apply_filters('bjlg_schedule_macros', $catalog);

        $sanitized = [];
        if (is_array($filtered)) {
            foreach ($filtered as $entry) {
                if (!is_array($entry) || empty($entry['id']) || empty($entry['expression'])) {
                    continue;
                }
                $id = sanitize_key((string) $entry['id']);
                if ($id === '') {
                    continue;
                }
                $expression = self::sanitize_cron_expression($entry['expression']);
                if ($expression === '') {
                    continue;
                }
                $sanitized[$id] = [
                    'id' => $id,
                    'label' => isset($entry['label']) ? sanitize_text_field($entry['label']) : $id,
                    'description' => isset($entry['description']) ? sanitize_text_field($entry['description']) : '',
                    'expression' => $expression,
                    'category' => isset($entry['category']) ? sanitize_key((string) $entry['category']) : 'custom',
                    'adjustments' => self::sanitize_schedule_macro_adjustments($entry['adjustments'] ?? []),
                ];
            }
        }

        return array_values($sanitized);
    }

    /**
     * Retourne la macro correspondant à l’identifiant demandé.
     */
    public static function get_schedule_macro_by_id(string $id)
    {
        $catalog = self::get_schedule_macro_catalog();
        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['id'] ?? '') === $id) {
                return $entry;
            }
        }

        return null;
    }

    private static function sanitize_schedule_macro_adjustments($raw): array
    {
        $defaults = [
            'label' => '',
            'components' => ['db', 'plugins', 'themes', 'uploads'],
            'incremental' => false,
            'encrypt' => false,
            'post_checks' => self::get_default_backup_post_checks(),
        ];

        $adjustments = is_array($raw) ? $raw : [];

        $label = isset($adjustments['label']) ? sanitize_text_field($adjustments['label']) : '';
        $components = self::sanitize_schedule_components($adjustments['components'] ?? $defaults['components']);
        if (empty($components)) {
            $components = $defaults['components'];
        }
        $incremental = self::to_bool_static($adjustments['incremental'] ?? $defaults['incremental']);
        $encrypt = self::to_bool_static($adjustments['encrypt'] ?? $defaults['encrypt']);
        $post_checks = self::sanitize_post_checks(
            $adjustments['post_checks'] ?? $defaults['post_checks'],
            self::get_default_backup_post_checks()
        );

        return [
            'label' => $label,
            'components' => array_values($components),
            'incremental' => $incremental,
            'encrypt' => $encrypt,
            'post_checks' => $post_checks,
        ];
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

    public static function get_monitoring_settings(): array {
        $instance = self::get_instance();
        $defaults = isset($instance->default_settings['monitoring']) ? $instance->default_settings['monitoring'] : [];
        $stored = $instance->get_option_value('bjlg_monitoring_settings', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return self::merge_settings_with_defaults($stored, $defaults);
    }

    public static function get_managed_replication_providers(): array {
        $providers = self::MANAGED_REPLICATION_PROVIDER_BLUEPRINT;
        $normalized = [];

        foreach ($providers as $key => $definition) {
            $slug = sanitize_key((string) $key);
            if ($slug === '') {
                continue;
            }

            $label = isset($definition['label']) ? (string) $definition['label'] : ucwords(str_replace('_', ' ', $slug));
            $destination_id = isset($definition['destination_id']) ? sanitize_key((string) $definition['destination_id']) : '';
            $regions = isset($definition['regions']) && is_array($definition['regions']) ? $definition['regions'] : [];

            $normalized[$slug] = [
                'label' => __($label, 'backup-jlg'),
                'destination_id' => $destination_id !== '' ? $destination_id : $slug,
                'regions' => array_map('strval', $regions),
            ];
        }

        /** @var array<string, array<string, mixed>>|null $filtered */
        $filtered = apply_filters('bjlg_managed_replication_providers', $normalized);
        if (is_array($filtered) && !empty($filtered)) {
            $normalized = [];
            foreach ($filtered as $key => $definition) {
                $slug = sanitize_key((string) $key);
                if ($slug === '') {
                    continue;
                }

                $normalized[$slug] = [
                    'label' => isset($definition['label']) ? (string) $definition['label'] : ucwords(str_replace('_', ' ', $slug)),
                    'destination_id' => isset($definition['destination_id']) ? sanitize_key((string) $definition['destination_id']) : $slug,
                    'regions' => isset($definition['regions']) && is_array($definition['regions']) ? array_map('strval', $definition['regions']) : [],
                ];
            }
        }

        return $normalized;
    }

    public static function get_managed_replication_region_choices(?string $provider = null): array {
        $providers = self::get_managed_replication_providers();

        if ($provider !== null) {
            $slug = sanitize_key($provider);
            if (isset($providers[$slug]['regions']) && is_array($providers[$slug]['regions'])) {
                return $providers[$slug]['regions'];
            }

            return [];
        }

        $regions = [];
        foreach ($providers as $definition) {
            if (empty($definition['regions']) || !is_array($definition['regions'])) {
                continue;
            }

            foreach ($definition['regions'] as $region_key => $region_label) {
                $regions[(string) $region_key] = (string) $region_label;
            }
        }

        return $regions;
    }

    public static function get_default_managed_replication_settings(): array {
        $defaults = self::DEFAULT_MANAGED_REPLICATION_SETTINGS;
        $providers = self::get_managed_replication_providers();

        if (!isset($providers[$defaults['primary']['provider']])) {
            $defaults['primary']['provider'] = (string) array_key_first($providers);
        }

        if (!isset($providers[$defaults['replica']['provider']])) {
            $defaults['replica']['provider'] = $defaults['primary']['provider'];
        }

        return apply_filters('bjlg_default_managed_replication_settings', $defaults);
    }

    private static function sanitize_managed_replication_region(string $provider, string $region): string {
        $regions = self::get_managed_replication_region_choices($provider);
        $region_key = sanitize_key($region);

        if ($region_key !== '' && isset($regions[$region_key])) {
            return $region_key;
        }

        return '';
    }

    public static function get_storage_warning_threshold(): float {
        $settings = self::get_monitoring_settings();
        $threshold = isset($settings['storage_quota_warning_threshold'])
            ? (float) $settings['storage_quota_warning_threshold']
            : 85.0;

        return max(1.0, min(100.0, $threshold));
    }

    public static function get_known_destination_ids() {
        $destinations = ['google_drive', 'aws_s3', 'sftp', 'dropbox', 'onedrive', 'pcloud', 'wasabi', 'managed_vault'];

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
     * Retourne un libellé lisible pour une destination donnée.
     */
    public static function get_destination_label($destination_id) {
        if (!is_scalar($destination_id)) {
            return '';
        }

        $slug = sanitize_key((string) $destination_id);
        if ($slug === '') {
            return '';
        }

        $default_labels = [
            'google_drive' => 'Google Drive',
            'aws_s3' => 'Amazon S3',
            'dropbox' => 'Dropbox',
            'onedrive' => 'Microsoft OneDrive',
            'pcloud' => 'pCloud',
            'sftp' => 'Serveur SFTP',
            'wasabi' => 'Wasabi',
            'managed_vault' => 'Managed Vault',
            'azure_blob' => 'Azure Blob Storage',
            'backblaze_b2' => 'Backblaze B2',
            'managed_replication' => __('Stockage managé multi-régions', 'backup-jlg'),
        ];

        $label = isset($default_labels[$slug]) ? $default_labels[$slug] : '';

        /** @var string|false $filtered */
        $filtered = apply_filters('bjlg_destination_label', $label, $slug);
        if (is_string($filtered) && $filtered !== '') {
            return $filtered;
        }

        if ($label !== '') {
            return $label;
        }

        return ucwords(str_replace(['_', '-'], ' ', $slug));
    }

    /**
     * Retourne le seuil d'alerte pour les destinations distantes.
     */
    public static function get_remote_storage_threshold(): float {
        $settings = get_option('bjlg_advanced_settings', []);
        $default = self::DEFAULT_REMOTE_STORAGE_THRESHOLD;

        if (!is_array($settings)) {
            return $default;
        }

        $value = $settings['remote_storage_threshold'] ?? $default;

        return self::normalize_ratio($value, $default);
    }

    /**
     * Retourne la configuration d'automatisation de la sandbox.
     */
    public static function get_sandbox_automation_settings(): array {
        $stored = \bjlg_get_option('bjlg_sandbox_automation_settings', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $sanitized = self::sanitize_sandbox_automation_settings($stored);

        return wp_parse_args($sanitized, self::DEFAULT_SANDBOX_AUTOMATION_SETTINGS);
    }

    /**
     * Normalise une configuration d'automatisation de sandbox.
     *
     * @param array<string,mixed>|string $settings
     */
    public static function sanitize_sandbox_automation_settings($settings): array {
        if (is_string($settings)) {
            $decoded = json_decode($settings, true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        if (!is_array($settings)) {
            $settings = [];
        }

        $normalized = self::DEFAULT_SANDBOX_AUTOMATION_SETTINGS;

        if (array_key_exists('enabled', $settings)) {
            $normalized['enabled'] = (bool) $settings['enabled'];
        }

        if (array_key_exists('recurrence', $settings)) {
            $normalized['recurrence'] = self::normalize_sandbox_automation_recurrence($settings['recurrence']);
        }

        if (array_key_exists('sandbox_path', $settings)) {
            $normalized['sandbox_path'] = self::sanitize_sandbox_path($settings['sandbox_path']);
        }

        return $normalized;
    }

    private static function normalize_sandbox_automation_recurrence($recurrence): string {
        if (is_string($recurrence)) {
            $candidate = sanitize_key($recurrence);
            if (in_array($candidate, self::VALID_SANDBOX_AUTOMATION_RECURRENCES, true)) {
                return $candidate;
            }
        }

        return self::DEFAULT_SANDBOX_AUTOMATION_SETTINGS['recurrence'];
    }

    private static function sanitize_sandbox_path($path): string {
        if (!is_string($path)) {
            return '';
        }

        $path = trim($path);

        if ($path === '') {
            return '';
        }

        return sanitize_text_field($path);
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
        $normalized = $this->normalize_email_recipients($emails);
        return implode(',', $normalized['valid']);
    }

    /**
     * Normalise et valide une liste d'adresses e-mail.
     *
     * @param string|array $value
     * @return array{valid: array<int, string>, invalid: array<int, string>}
     */
    private function normalize_email_recipients($value) {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $parts = preg_split('/[,;\n]+/', $value);

        $valid = [];
        $seen = [];
        $invalid = [];

        if (is_array($parts)) {
            foreach ($parts as $part) {
                $candidate = trim($part);
                if ($candidate === '') {
                    continue;
                }

                $sanitized = sanitize_email($candidate);
                if ($sanitized && is_email($sanitized)) {
                    if (!isset($seen[$sanitized])) {
                        $seen[$sanitized] = true;
                        $valid[] = $sanitized;
                    }
                } else {
                    $invalid[] = $candidate;
                }
            }
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
        ];
    }

    /**
     * Prépare les réglages de notification en fusionnant la requête avec les valeurs stockées.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     * @throws Exception
     */
    private function prepare_notifications_settings_from_request($request) {
        if (!is_array($request)) {
            $request = [];
        }

        $notification_defaults = $this->default_settings['notifications'];
        $settings = $this->get_section_settings_with_defaults('notifications');

        $enabled_current = !empty($settings['enabled']);
        $enabled_raw = $this->get_scalar_request_value($request, 'notifications_enabled', $enabled_current ? '1' : '0');
        $settings['enabled'] = $this->to_bool($enabled_raw);

        $event_map = [
            'backup_complete' => 'notify_backup_complete',
            'backup_failed' => 'notify_backup_failed',
            'cleanup_complete' => 'notify_cleanup_complete',
            'storage_warning' => 'notify_storage_warning',
            'remote_purge_failed' => 'notify_remote_purge_failed',
            'remote_purge_delayed' => 'notify_remote_purge_delayed',
            'remote_storage_forecast_warning' => 'notify_remote_storage_forecast_warning',
            'restore_self_test_passed' => 'notify_restore_self_test_passed',
            'restore_self_test_failed' => 'notify_restore_self_test_failed',
        ];
        foreach ($event_map as $event_key => $field_name) {
            $current = !empty($settings['events'][$event_key]);
            $raw = $this->get_scalar_request_value($request, $field_name, $current ? '1' : '0');
            $settings['events'][$event_key] = $this->to_bool($raw);
        }

        $channel_map = [
            'email' => 'channel_email',
            'slack' => 'channel_slack',
            'discord' => 'channel_discord',
            'teams' => 'channel_teams',
            'sms' => 'channel_sms',
        ];
        foreach ($channel_map as $channel_key => $field_name) {
            if (!isset($settings['channels'][$channel_key])) {
                $settings['channels'][$channel_key] = $notification_defaults['channels'][$channel_key];
            }

            $current = !empty($settings['channels'][$channel_key]['enabled']);
            $raw = $this->get_scalar_request_value($request, $field_name, $current ? '1' : '0');
            $settings['channels'][$channel_key]['enabled'] = $this->to_bool($raw);
        }

        $slack_source = $this->get_scalar_request_value(
            $request,
            'slack_webhook_url',
            $settings['channels']['slack']['webhook_url'] ?? ''
        );
        $slack_url = $this->validate_optional_url($slack_source, 'Slack');
        $settings['channels']['slack']['webhook_url'] = $slack_url;

        $discord_source = $this->get_scalar_request_value(
            $request,
            'discord_webhook_url',
            $settings['channels']['discord']['webhook_url'] ?? ''
        );
        $discord_url = $this->validate_optional_url($discord_source, 'Discord');
        $settings['channels']['discord']['webhook_url'] = $discord_url;

        $teams_source = $this->get_scalar_request_value(
            $request,
            'teams_webhook_url',
            $settings['channels']['teams']['webhook_url'] ?? ''
        );
        $teams_url = $this->validate_optional_url($teams_source, 'Teams');
        $settings['channels']['teams']['webhook_url'] = $teams_url;

        $sms_source = $this->get_scalar_request_value(
            $request,
            'sms_webhook_url',
            $settings['channels']['sms']['webhook_url'] ?? ''
        );
        $sms_url = $this->validate_optional_url($sms_source, 'SMS');
        $settings['channels']['sms']['webhook_url'] = $sms_url;

        $email_source = $this->get_scalar_request_value(
            $request,
            'email_recipients',
            $settings['email_recipients'] ?? ''
        );
        $email_validation = $this->normalize_email_recipients($email_source);

        $quiet_defaults = $notification_defaults['quiet_hours'];
        $quiet_current = isset($settings['quiet_hours']) && is_array($settings['quiet_hours']) ? $settings['quiet_hours'] : $quiet_defaults;
        $quiet_enabled = $this->to_bool($this->get_scalar_request_value($request, 'quiet_hours_enabled', !empty($quiet_current['enabled']) ? '1' : '0'));
        $quiet_start = $this->sanitize_time_field($this->get_scalar_request_value($request, 'quiet_hours_start', $quiet_current['start'] ?? $quiet_defaults['start']), $quiet_defaults['start']);
        $quiet_end = $this->sanitize_time_field($this->get_scalar_request_value($request, 'quiet_hours_end', $quiet_current['end'] ?? $quiet_defaults['end']), $quiet_defaults['end']);
        $quiet_allow = $this->to_bool($this->get_scalar_request_value($request, 'quiet_hours_allow_critical', !empty($quiet_current['allow_critical']) ? '1' : '0'));
        $quiet_timezone = $this->sanitize_timezone_field($this->get_scalar_request_value($request, 'quiet_hours_timezone', $quiet_current['timezone'] ?? ''));

        $settings['quiet_hours'] = [
            'enabled' => $quiet_enabled,
            'start' => $quiet_start,
            'end' => $quiet_end,
            'allow_critical' => $quiet_allow,
            'timezone' => $quiet_timezone,
        ];

        $escalation_defaults = $notification_defaults['escalation'];
        $escalation_current = isset($settings['escalation']) && is_array($settings['escalation']) ? $settings['escalation'] : $escalation_defaults;
        $escalation_enabled = $this->to_bool($this->get_scalar_request_value($request, 'escalation_enabled', !empty($escalation_current['enabled']) ? '1' : '0'));
        $escalation_delay_raw = (int) $this->get_scalar_request_value($request, 'escalation_delay', (string) ($escalation_current['delay_minutes'] ?? $escalation_defaults['delay_minutes']));
        $escalation_delay = max(1, $escalation_delay_raw);
        $escalation_only_critical = $this->to_bool($this->get_scalar_request_value($request, 'escalation_only_critical', !empty($escalation_current['only_critical']) ? '1' : '0'));

        $escalation_channels = [];
        foreach ($escalation_defaults['channels'] as $channel_key => $default_enabled) {
            $field = 'escalation_channel_' . $channel_key;
            $current = !empty($escalation_current['channels'][$channel_key]);
            $escalation_channels[$channel_key] = $this->to_bool($this->get_scalar_request_value($request, $field, $current ? '1' : '0'));
        }

        $allowed_modes = ['simple', 'staged'];
        $mode_current = isset($escalation_current['mode']) ? (string) $escalation_current['mode'] : ($escalation_defaults['mode'] ?? 'simple');
        $mode_raw = $this->get_scalar_request_value($request, 'escalation_mode', $mode_current);
        $mode_normalized = strtolower(trim((string) $mode_raw));
        if (!in_array($mode_normalized, $allowed_modes, true)) {
            $mode_normalized = 'simple';
        }

        $stage_blueprint = $this->get_escalation_stage_blueprint();
        $escalation_stage_defaults = isset($escalation_defaults['stages']) && is_array($escalation_defaults['stages'])
            ? $escalation_defaults['stages']
            : [];
        $escalation_stage_current = isset($escalation_current['stages']) && is_array($escalation_current['stages'])
            ? $escalation_current['stages']
            : $escalation_stage_defaults;

        $escalation_stages = [];
        foreach ($stage_blueprint as $stage_key => $stage_definition) {
            $default_stage = isset($escalation_stage_defaults[$stage_key]) && is_array($escalation_stage_defaults[$stage_key])
                ? $escalation_stage_defaults[$stage_key]
                : ['enabled' => false, 'delay_minutes' => (int) ($stage_definition['default_delay_minutes'] ?? 15)];
            $current_stage = isset($escalation_stage_current[$stage_key]) && is_array($escalation_stage_current[$stage_key])
                ? $escalation_stage_current[$stage_key]
                : $default_stage;

            $enabled_field = 'escalation_stage_' . $stage_key . '_enabled';
            $delay_field = 'escalation_stage_' . $stage_key . '_delay';

            $enabled_default = !empty($current_stage['enabled']);
            $delay_default = isset($current_stage['delay_minutes'])
                ? (int) $current_stage['delay_minutes']
                : (int) ($stage_definition['default_delay_minutes'] ?? 15);

            $stage_enabled = $this->to_bool($this->get_scalar_request_value($request, $enabled_field, $enabled_default ? '1' : '0'));
            $stage_delay_raw = (int) $this->get_scalar_request_value($request, $delay_field, (string) $delay_default);

            $escalation_stages[$stage_key] = [
                'enabled' => $stage_enabled,
                'delay_minutes' => max(0, $stage_delay_raw),
            ];
        }

        if (!empty($escalation_stage_defaults)) {
            foreach ($escalation_stage_defaults as $stage_key => $default_stage) {
                if (isset($escalation_stages[$stage_key])) {
                    continue;
                }

                $enabled_field = 'escalation_stage_' . $stage_key . '_enabled';
                $delay_field = 'escalation_stage_' . $stage_key . '_delay';

                $enabled_default = !empty($default_stage['enabled']);
                $delay_default = isset($default_stage['delay_minutes']) ? (int) $default_stage['delay_minutes'] : 15;

                $stage_enabled = $this->to_bool($this->get_scalar_request_value($request, $enabled_field, $enabled_default ? '1' : '0'));
                $stage_delay_raw = (int) $this->get_scalar_request_value($request, $delay_field, (string) $delay_default);

                $escalation_stages[$stage_key] = [
                    'enabled' => $stage_enabled,
                    'delay_minutes' => max(0, $stage_delay_raw),
                ];
            }
        }

        $settings['escalation'] = [
            'enabled' => $escalation_enabled,
            'delay_minutes' => $escalation_delay,
            'only_critical' => $escalation_only_critical,
            'channels' => $escalation_channels,
            'mode' => $mode_normalized,
            'stages' => $escalation_stages,
        ];

        $template_blueprint = $this->get_notification_template_blueprint();
        $template_current = isset($settings['templates']) && is_array($settings['templates'])
            ? $settings['templates']
            : [];

        $templates = [];
        foreach ($template_blueprint as $severity => $definition) {
            if (!is_string($severity) || $severity === '') {
                continue;
            }

            $current_template = isset($template_current[$severity]) && is_array($template_current[$severity])
                ? $template_current[$severity]
                : [];

            $label_default = isset($current_template['label'])
                ? (string) $current_template['label']
                : (string) ($definition['label'] ?? '');
            $intro_default = isset($current_template['intro'])
                ? (string) $current_template['intro']
                : (string) ($definition['intro'] ?? '');
            $outro_default = isset($current_template['outro'])
                ? (string) $current_template['outro']
                : (string) ($definition['outro'] ?? '');
            $resolution_default = isset($current_template['resolution'])
                ? (string) $current_template['resolution']
                : (string) ($definition['resolution'] ?? '');
            $actions_default = isset($current_template['actions']) && is_array($current_template['actions'])
                ? $current_template['actions']
                : (isset($definition['actions']) && is_array($definition['actions']) ? $definition['actions'] : []);

            $label_value = $this->get_scalar_request_value($request, 'template_' . $severity . '_label', $label_default);
            $intro_value = $this->get_scalar_request_value($request, 'template_' . $severity . '_intro', $intro_default);
            $outro_value = $this->get_scalar_request_value($request, 'template_' . $severity . '_outro', $outro_default);
            $resolution_value = $this->get_scalar_request_value($request, 'template_' . $severity . '_resolution', $resolution_default);
            $actions_value = $this->get_scalar_request_value(
                $request,
                'template_' . $severity . '_actions',
                implode("\n", array_map('strval', $actions_default))
            );

            $actions_lines = [];
            if (is_string($actions_value) && $actions_value !== '') {
                $raw_lines = preg_split('/[\r\n]+/', $actions_value);
                if (is_array($raw_lines)) {
                    foreach ($raw_lines as $line) {
                        $line = is_string($line) ? trim($line) : '';
                        if ($line === '') {
                            continue;
                        }

                        $actions_lines[] = function_exists('sanitize_textarea_field')
                            ? sanitize_textarea_field($line)
                            : trim($line);
                    }
                }
            }

            if (empty($actions_lines) && !empty($actions_default)) {
                $actions_lines = array_map('strval', $actions_default);
            }

            $intent_default = isset($current_template['intent'])
                ? (string) $current_template['intent']
                : (string) ($definition['intent'] ?? 'info');
            $intent_normalized = strtolower(trim($intent_default));
            if (!in_array($intent_normalized, ['info', 'warning', 'error'], true)) {
                $intent_normalized = 'info';
            }

            $templates[$severity] = [
                'label' => function_exists('sanitize_text_field') ? sanitize_text_field($label_value) : trim((string) $label_value),
                'intro' => function_exists('sanitize_textarea_field') ? sanitize_textarea_field($intro_value) : trim((string) $intro_value),
                'outro' => function_exists('sanitize_textarea_field') ? sanitize_textarea_field($outro_value) : trim((string) $outro_value),
                'resolution' => function_exists('sanitize_textarea_field') ? sanitize_textarea_field($resolution_value) : trim((string) $resolution_value),
                'actions' => $actions_lines,
                'intent' => $intent_normalized,
            ];
        }

        if (!empty($templates)) {
            $settings['templates'] = $templates;
        }

        if (!empty($email_validation['invalid'])) {
            throw new Exception(sprintf(
                'Les adresses e-mail suivantes sont invalides : %s.',
                implode(', ', $email_validation['invalid'])
            ));
        }

        if (
            !empty($settings['enabled'])
            && !empty($settings['channels']['email']['enabled'])
            && empty($email_validation['valid'])
        ) {
            throw new Exception('Veuillez renseigner au moins une adresse e-mail valide pour les notifications.');
        }

        if (!empty($settings['channels']['slack']['enabled']) && $slack_url === '') {
            throw new Exception('Veuillez fournir une URL de webhook Slack valide pour activer ce canal.');
        }

        if (!empty($settings['channels']['discord']['enabled']) && $discord_url === '') {
            throw new Exception('Veuillez fournir une URL de webhook Discord valide pour activer ce canal.');
        }

        if (!empty($settings['channels']['teams']['enabled']) && $teams_url === '') {
            throw new Exception('Veuillez fournir une URL de webhook Teams valide pour activer ce canal.');
        }

        if (!empty($settings['channels']['sms']['enabled']) && $sms_url === '') {
            throw new Exception('Veuillez fournir une URL de webhook SMS valide pour activer ce canal.');
        }

        $settings['email_recipients'] = implode(', ', $email_validation['valid']);

        return $settings;
    }

    /**
     * Récupère une valeur scalaire envoyée dans la requête.
     *
     * @param array<string,mixed> $request
     * @param string              $key
     * @param string              $default
     */
    private function get_scalar_request_value(array $request, $key, $default = '') {
        if (!array_key_exists($key, $request)) {
            return $default;
        }

        $value = $request[$key];
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? wp_unslash((string) $value) : $default;
    }

    private function sanitize_time_field($value, $default) {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            $value = $default;
        }

        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
            return $default;
        }

        $hour = min(23, max(0, (int) $matches[1]));
        $minute = min(59, max(0, (int) $matches[2]));

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function sanitize_timezone_field($value) {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }

        try {
            new \DateTimeZone($value);
            return $value;
        } catch (\Exception $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        }

        if (function_exists('wp_timezone_string')) {
            $timezone = wp_timezone_string();
            if (is_string($timezone) && $timezone !== '') {
                try {
                    new \DateTimeZone($timezone);
                    return $timezone;
                } catch (\Exception $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                }
            }
        }

        return '';
    }

    /**
     * Valide une URL de webhook optionnelle.
     *
     * @param string $value
     * @param string $context_label
     * @return string
     * @throws Exception
     */
    private function validate_optional_url($value, $context_label) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $sanitized = esc_url_raw($value);
        $is_valid = $sanitized !== '';

        if ($is_valid) {
            if (function_exists('wp_http_validate_url')) {
                $is_valid = (bool) wp_http_validate_url($sanitized);
            } else {
                $is_valid = (bool) filter_var($sanitized, FILTER_VALIDATE_URL);
            }
        }

        if (!$is_valid) {
            throw new Exception(sprintf("L'URL du webhook %s est invalide.", $context_label));
        }

        return $sanitized;
    }

    public static function sanitize_cron_expression($expression): string {
        if (!is_string($expression)) {
            if (is_array($expression)) {
                $expression = implode(' ', $expression);
            } else {
                $expression = (string) $expression;
            }
        }

        $expression = trim(preg_replace('/\s+/', ' ', $expression));
        if ($expression === '') {
            return '';
        }

        if (!preg_match('/^[\d\*\-,\/A-Za-z]+(\s+[\d\*\-,\/A-Za-z]+){4}$/', $expression)) {
            return '';
        }

        return strtolower($expression);
    }
    
    /**
     * Obtient un paramètre spécifique
     */
    public function get_setting($section, $key = null, $default = null) {
        $option_name = 'bjlg_' . $section . '_settings';
        $settings = $this->get_option_value($option_name, $this->default_settings[$section] ?? []);
        
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
        $settings = $this->get_option_value($option_name, $this->default_settings[$section] ?? []);
        
        $settings[$key] = $value;
        
        return $this->update_option_value($option_name, $settings);
    }
}