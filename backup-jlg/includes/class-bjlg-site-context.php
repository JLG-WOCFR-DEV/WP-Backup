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
            'bjlg_wasabi_settings',
            'bjlg_webhook_key',
            'bjlg_webhook_settings',
            'bjlg_whitelabel_settings',
        ];
    }

    /** @var int */
    private static $network_stack = 0;

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

        if (self::should_use_network($args)) {
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

        if (self::should_use_network($args)) {
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

        if (self::should_use_network($args)) {
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

    private static function should_use_network(array $args): bool {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return false;
        }

        if (array_key_exists('network', $args)) {
            return (bool) $args['network'];
        }

        return self::is_network_context();
    }

    private static function maybe_override_with_network_value(string $option_name, $value, bool $is_default)
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return $value;
        }

        $network_value = get_site_option($option_name, self::NO_VALUE);

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

        if (!self::is_network_context()) {
            return;
        }

        update_site_option($option_name, $value);
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
}
