<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Utilitaires pour gérer le contexte site/réseau et centraliser la persistance des options.
 */
class BJLG_Site_Context {

    private const NO_VALUE = '__bjlg__no_value__';
    public const HISTORY_SCOPE_OPTION = 'bjlg_history_scope';
    public const HISTORY_SCOPE_SITE = 'site';
    public const HISTORY_SCOPE_NETWORK = 'network';

    public const NETWORK_MODE_OPTION = 'bjlg_network_mode';
    public const NETWORK_MODE_SITE = 'site';
    public const NETWORK_MODE_NETWORK = 'network';

    private const NETWORK_SYNCED_OPTIONS = [
        'bjlg_api_keys',
        'bjlg_notification_settings',
        'bjlg_notification_queue',
        'bjlg_monitoring_settings',
        'bjlg_remote_storage_metrics',
        'bjlg_supervised_sites',
    ];

    /**
     * Liste des options gérées par le plugin. Sert à brancher les hooks de synchronisation multisite.
     *
     * @return array<int,string>
     */
    public static function get_managed_option_names(): array {
        return [
            'bjlg_ajax_debug_enabled',
            'bjlg_api_keys',
            'bjlg_azure_blob_settings',
            'bjlg_azure_blob_status',
            'bjlg_backblaze_b2_settings',
            'bjlg_backblaze_b2_status',
            'bjlg_backup_exclude_patterns',
            'bjlg_backup_include_patterns',
            'bjlg_backup_post_checks',
            'bjlg_backup_presets',
            'bjlg_backup_secondary_destinations',
            'bjlg_capability_map',
            'bjlg_cleanup_settings',
            'bjlg_dropbox_settings',
            'bjlg_dropbox_status',
            'bjlg_enabled_modules',
            'bjlg_encryption_key',
            'bjlg_encryption_salt',
            'bjlg_encryption_settings',
            'bjlg_gdrive_settings',
            'bjlg_gdrive_state',
            'bjlg_gdrive_status',
            'bjlg_gdrive_token',
            'bjlg_incremental_settings',
            'bjlg_monitoring_settings',
            'bjlg_notification_queue',
            'bjlg_notification_settings',
            'bjlg_onedrive_settings',
            'bjlg_onedrive_status',
            'bjlg_pcloud_settings',
            'bjlg_pcloud_status',
            'bjlg_performance_settings',
            'bjlg_performance_stats',
            'bjlg_remote_purge_sla_metrics',
            'bjlg_required_capability',
            'bjlg_s3_settings',
            'bjlg_s3_status',
            'bjlg_safe_mode',
            'bjlg_schedule_settings',
            'bjlg_settings',
            'bjlg_sftp_settings',
            'bjlg_supervised_sites',
            'bjlg_managed_vault_settings',
            'bjlg_managed_vault_status',
            'bjlg_managed_vault_metrics',
            'bjlg_managed_vault_versions',
            'bjlg_managed_vault_resume',
            'bjlg_wasabi_settings',
            'bjlg_webhook_key',
            'bjlg_webhook_settings',
            'bjlg_whitelabel_settings',
        ];
    }

    /** @var int */
    private static $network_stack = 0;

    /** @var array<string, bool> */
    private static $network_sync_stack = [];

    /**
     * Sanitize a history scope value.
     */
    public static function sanitize_history_scope($scope): string {
        $sanitized = sanitize_key((string) $scope);

        if ($sanitized === self::HISTORY_SCOPE_NETWORK) {
            return self::HISTORY_SCOPE_NETWORK;
        }

        return self::HISTORY_SCOPE_SITE;
    }

    /**
     * Returns the configured history storage scope.
     */
    public static function get_history_scope(): string {
        $default = apply_filters('bjlg_default_history_scope', self::HISTORY_SCOPE_SITE);
        $default = self::sanitize_history_scope($default);

        $stored = $default;

        if (function_exists('is_multisite') && is_multisite() && function_exists('get_site_option')) {
            $value = get_site_option(self::HISTORY_SCOPE_OPTION, $default);
        } else {
            $value = get_option(self::HISTORY_SCOPE_OPTION, $default);
        }

        if (is_string($value)) {
            $stored = self::sanitize_history_scope($value);
        }

        if ($stored === '') {
            $stored = self::HISTORY_SCOPE_SITE;
        }

        return apply_filters('bjlg_history_scope', $stored);
    }

    /**
     * Updates the history scope option.
     */
    public static function set_history_scope(string $scope): void {
        $scope = self::sanitize_history_scope($scope);

        if (function_exists('is_multisite') && is_multisite() && function_exists('update_site_option')) {
            update_site_option(self::HISTORY_SCOPE_OPTION, $scope);

            return;
        }

        if (function_exists('update_option')) {
            update_option(self::HISTORY_SCOPE_OPTION, $scope);
        }
    }

    /**
     * Returns whether the network scope is enabled for history/API keys.
     */
    public static function history_uses_network_storage(): bool {
        return function_exists('is_multisite')
            && is_multisite()
            && self::is_network_mode_enabled()
            && self::get_history_scope() === self::HISTORY_SCOPE_NETWORK;
    }

    /**
     * Helper exposing option arguments for history-aware storage.
     *
     * @return array<string, bool>
     */
    public static function get_history_option_args(): array {
        if (self::history_uses_network_storage()) {
            return ['network' => true];
        }

        return [];
    }

    /**
     * Returns the table prefix for the requested context.
     */
    public static function get_table_prefix(string $context = self::HISTORY_SCOPE_SITE, ?int $site_id = null): string {
        global $wpdb;

        $default = 'wp_';

        if (!is_object($wpdb)) {
            return $default;
        }

        if ($context === self::HISTORY_SCOPE_NETWORK) {
            if (property_exists($wpdb, 'base_prefix') && is_string($wpdb->base_prefix) && $wpdb->base_prefix !== '') {
                return $wpdb->base_prefix;
            }

            if (property_exists($wpdb, 'prefix') && is_string($wpdb->prefix) && $wpdb->prefix !== '') {
                return $wpdb->prefix;
            }

            return $default;
        }

        $target_blog_id = null;

        if ($site_id !== null) {
            $target_blog_id = max(0, (int) $site_id);
        } elseif (function_exists('get_current_blog_id')) {
            $target_blog_id = max(0, (int) get_current_blog_id());
        }

        if ($target_blog_id !== null && method_exists($wpdb, 'get_blog_prefix')) {
            $blog_prefix = $wpdb->get_blog_prefix($target_blog_id);
            if (is_string($blog_prefix) && $blog_prefix !== '') {
                return $blog_prefix;
            }
        }

        if (property_exists($wpdb, 'prefix') && is_string($wpdb->prefix) && $wpdb->prefix !== '') {
            return $wpdb->prefix;
        }

        if (property_exists($wpdb, 'base_prefix') && is_string($wpdb->base_prefix) && $wpdb->base_prefix !== '') {
            return $wpdb->base_prefix;
        }

        return $default;
    }

    /**
     * Builds the full table name for the network context.
     */
    public static function get_network_table_name(string $table_suffix): string {
        $suffix = ltrim($table_suffix, '_');

        return self::get_table_prefix(self::HISTORY_SCOPE_NETWORK) . $suffix;
    }

    /**
     * Returns the canonical backup directory for the requested context.
     */
    public static function get_backup_directory(?int $site_id = null, string $context = self::HISTORY_SCOPE_SITE): string
    {
        $resolver = static function () use ($context) {
            $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : null;
            $basedir = is_array($uploads) && isset($uploads['basedir']) && is_string($uploads['basedir'])
                ? (string) $uploads['basedir']
                : trailingslashit(WP_CONTENT_DIR) . 'uploads';

            $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;

            if ($context === self::HISTORY_SCOPE_NETWORK) {
                $subdir = 'network';
            } else {
                $subdir = 'site-' . max(0, $blog_id);
            }

            return trailingslashit(trailingslashit($basedir) . 'bjlg-backups/' . $subdir);
        };

        if ($context === self::HISTORY_SCOPE_NETWORK) {
            return (string) self::with_network(static function () use ($resolver) {
                return $resolver();
            });
        }

        if ($site_id !== null && function_exists('is_multisite') && is_multisite()) {
            $site_id = (int) $site_id;
            if ($site_id > 0) {
                return (string) self::with_site($site_id, static function () use ($resolver) {
                    return $resolver();
                });
            }
        }

        return $resolver();
    }

    /**
     * Initialise les hooks pour synchroniser les options site/réseau.
     */
    public static function bootstrap(): void {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return;
        }

        foreach (self::get_managed_option_names() as $option_name) {
            \add_filter(
                "option_{$option_name}",
                static function ($value) use ($option_name) {
                    return BJLG_Site_Context::maybe_override_with_network_value($option_name, $value, false);
                },
                10,
                1
            );

            \add_filter(
                "default_option_{$option_name}",
                static function ($value) use ($option_name) {
                    return BJLG_Site_Context::maybe_override_with_network_value($option_name, $value, true);
                },
                10,
                1
            );

            \add_action(
                "add_option_{$option_name}",
                static function ($option, $value) use ($option_name) {
                    if ($option === $option_name) {
                        BJLG_Site_Context::sync_option_to_network($option_name, $value);
                    }
                },
                10,
                2
            );

            \add_action(
                "update_option_{$option_name}",
                static function ($old_value, $value, $option) use ($option_name) {
                    if ($option === $option_name) {
                        BJLG_Site_Context::sync_option_to_network($option_name, $value);
                    }
                },
                10,
                3
            );

            \add_action(
                "delete_option_{$option_name}",
                static function ($option) use ($option_name) {
                    if ($option === $option_name) {
                        BJLG_Site_Context::maybe_delete_network_option($option_name);
                    }
                },
                10,
                1
            );
        }
    }

    /**
     * Récupère une option en tenant compte du contexte multisite.
     *
     * @param string $option_name
     * @param mixed  $default
     * @param array  $args
     *
     * @return mixed
     */
    public static function get_option(string $option_name, $default = false, array $args = []) {
        if (isset($args['site_id']) && function_exists('is_multisite') && is_multisite()) {
            $site_id = (int) $args['site_id'];
            if ($site_id > 0) {
                return self::with_site($site_id, static function () use ($option_name, $default, $args) {
                    $next_args = $args;
                    unset($next_args['site_id']);

                    return BJLG_Site_Context::get_option($option_name, $default, $next_args);
                });
            }
        }

        if (self::should_use_network($args, $option_name)) {
            return get_site_option($option_name, $default);
        }

        return get_option($option_name, $default);
    }

    /**
     * Met à jour une option en tenant compte du contexte multisite.
     *
     * @param string $option_name
     * @param mixed  $value
     * @param array  $args
     *
     * @return bool
     */
    public static function update_option(string $option_name, $value, array $args = []): bool {
        if (isset($args['site_id']) && function_exists('is_multisite') && is_multisite()) {
            $site_id = (int) $args['site_id'];
            if ($site_id > 0) {
                return self::with_site($site_id, static function () use ($option_name, $value, $args) {
                    $next_args = $args;
                    unset($next_args['site_id']);

                    return BJLG_Site_Context::update_option($option_name, $value, $next_args);
                });
            }
        }

        if (self::should_use_network($args, $option_name)) {
            return update_site_option($option_name, $value);
        }

        return update_option($option_name, $value);
    }

    /**
     * Supprime une option en tenant compte du contexte multisite.
     */
    public static function delete_option(string $option_name, array $args = []): bool {
        if (isset($args['site_id']) && function_exists('is_multisite') && is_multisite()) {
            $site_id = (int) $args['site_id'];
            if ($site_id > 0) {
                return self::with_site($site_id, static function () use ($option_name, $args) {
                    $next_args = $args;
                    unset($next_args['site_id']);

                    return BJLG_Site_Context::delete_option($option_name, $next_args);
                });
            }
        }

        if (self::should_use_network($args, $option_name)) {
            return delete_site_option($option_name);
        }

        return delete_option($option_name);
    }

    /**
     * Exécute un callback dans le contexte réseau (force is_network_context()).
     *
     * @param callable $callback
     *
     * @return mixed
     */
    public static function with_network(callable $callback) {
        self::$network_stack++;

        try {
            return $callback();
        } finally {
            self::$network_stack = max(0, self::$network_stack - 1);
        }
    }

    /**
     * Exécute un callback après avoir basculé sur le site donné.
     *
     * @param int      $site_id
     * @param callable $callback
     *
     * @return mixed
     */
    public static function with_site(int $site_id, callable $callback) {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return $callback();
        }

        $site_id = (int) $site_id;
        if ($site_id <= 0 || !function_exists('switch_to_blog')) {
            return $callback();
        }

        $current = get_current_blog_id();
        if ($current === $site_id) {
            return $callback();
        }

        switch_to_blog($site_id);

        try {
            return $callback();
        } finally {
            restore_current_blog();
        }
    }

    /**
     * Indique si le contexte réseau doit être utilisé.
     */
    public static function is_network_context(): bool {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return false;
        }

        if (self::$network_stack > 0) {
            return true;
        }

        if (function_exists('is_network_admin') && is_network_admin()) {
            return true;
        }

        return false;
    }

    private static function should_use_network(array $args, string $option_name): bool {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return false;
        }

        if (array_key_exists('network', $args)) {
            return (bool) $args['network'];
        }

        if (!self::is_network_mode_enabled()) {
            return false;
        }

        if (self::is_network_synced_option($option_name)) {
            return true;
        }

        return self::is_network_context();
    }

    private static function maybe_override_with_network_value(string $option_name, $value, bool $is_default)
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return $value;
        }

        $network_value = get_site_option($option_name, self::NO_VALUE);

        if (self::is_network_synced_option($option_name)) {
            return $network_value !== self::NO_VALUE ? $network_value : $value;
        }

        if (self::is_network_context()) {
            if ($network_value !== self::NO_VALUE) {
                return $network_value;
            }

            return $value;
        }

        if ($value !== false && $value !== self::NO_VALUE) {
            return $value;
        }

        if ($network_value !== self::NO_VALUE) {
            return $network_value;
        }

        if ($is_default) {
            return $value;
        }

        return $value;
    }

    private static function sync_option_to_network(string $option_name, $value): void {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return;
        }

        if (!self::is_network_mode_enabled()) {
            return;
        }

        if (!self::is_network_context() && !self::is_network_synced_option($option_name)) {
            return;
        }

        if (isset(self::$network_sync_stack[$option_name])) {
            return;
        }

        self::$network_sync_stack[$option_name] = true;

        try {
            update_site_option($option_name, $value);
        } finally {
            unset(self::$network_sync_stack[$option_name]);
        }
    }

    private static function maybe_delete_network_option(string $option_name): void {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return;
        }

        if (!self::is_network_context()) {
            return;
        }

        delete_site_option($option_name);
    }

    private static function is_network_synced_option(string $option_name): bool
    {
        return self::is_network_mode_enabled() && in_array($option_name, self::NETWORK_SYNCED_OPTIONS, true);
    }

    /**
     * Retrieves the configured network mode.
     */
    public static function get_network_mode(): string
    {
        $default = self::NETWORK_MODE_SITE;

        if (!function_exists('is_multisite') || !is_multisite()) {
            return $default;
        }

        $raw = get_site_option(self::NETWORK_MODE_OPTION, $default);

        return self::sanitize_network_mode($raw);
    }

    /**
     * Persists the desired network mode.
     */
    public static function set_network_mode(string $mode): void
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return;
        }

        $mode = self::sanitize_network_mode($mode);

        update_site_option(self::NETWORK_MODE_OPTION, $mode);
    }

    /**
     * Determines whether network mode is enabled.
     */
    public static function is_network_mode_enabled(): bool
    {
        return self::get_network_mode() === self::NETWORK_MODE_NETWORK;
    }

    private static function sanitize_network_mode($value): string
    {
        $normalized = sanitize_key((string) $value);

        return $normalized === self::NETWORK_MODE_NETWORK
            ? self::NETWORK_MODE_NETWORK
            : self::NETWORK_MODE_SITE;
    }
}
