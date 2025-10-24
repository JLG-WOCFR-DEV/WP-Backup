<?php
/**
 * Plugin Name: Backup - JLG
 * Plugin URI:  https://jlg.dev
 * Description: Sauvegarde & restauration pour WordPress avec chiffrement, API REST et intégrations.
 * Version:     2.0.3
 * Author:      JLG
 * Author URI:  https://jlg.dev
 * License:     GPL-2.0-or-later
 * Text Domain: backup-jlg
 * Requires PHP: 7.4
 * Requires at least: 5.0
 */
if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------- */
/* Constantes du Plugin                                                      */
/* -------------------------------------------------------------------------- */
if (!defined('BJLG_VERSION')) {
    define('BJLG_VERSION', '2.0.3');
}

if (!defined('BJLG_PLUGIN_FILE')) {
    define('BJLG_PLUGIN_FILE', __FILE__);
}

if (!defined('BJLG_PLUGIN_BASENAME')) {
    define('BJLG_PLUGIN_BASENAME', plugin_basename(__FILE__));
}

if (!defined('BJLG_PLUGIN_DIR')) {
    define('BJLG_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('BJLG_PLUGIN_URL')) {
    define('BJLG_PLUGIN_URL', plugin_dir_url(__FILE__));
}

if (!defined('BJLG_INCLUDES_DIR')) {
    define('BJLG_INCLUDES_DIR', BJLG_PLUGIN_DIR . 'includes/');
}

require_once BJLG_INCLUDES_DIR . 'class-bjlg-site-context.php';

if (!function_exists('bjlg_normalize_option_context_args')) {
    /**
     * Normalise les paramètres de contexte pour la gestion des options.
     *
     * @param array|int|null $args_or_site_id Tableau d'arguments ou identifiant de site.
     * @param bool|null      $network         Indicateur réseau (ancienne signature).
     *
     * @return array<string,mixed>
     */
    function bjlg_normalize_option_context_args($args_or_site_id = null, $network = null) {
        $args = [];

        if (is_array($args_or_site_id)) {
            $args = $args_or_site_id;
        } elseif ($args_or_site_id !== null) {
            $args['site_id'] = (int) $args_or_site_id;
        }

        if ($network !== null) {
            $args['network'] = (bool) $network;
        }

        if (array_key_exists('site_id', $args)) {
            $site_id = (int) $args['site_id'];
            if ($site_id > 0) {
                $args['site_id'] = $site_id;
            } else {
                unset($args['site_id']);
            }
        }

        if (array_key_exists('network', $args)) {
            $args['network'] = (bool) $args['network'];
        }

        return $args;
    }
}

if (!function_exists('bjlg_get_option')) {
    /**
     * Wrapper vers BJLG_Site_Context::get_option().
     *
     * @param string          $option_name
     * @param mixed           $default
     * @param array|int|null  $args_or_site_id
     * @param bool|null       $network
     *
     * @return mixed
     */
    function bjlg_get_option($option_name, $default = false, $args_or_site_id = null, $network = null) {
        if (!is_string($option_name) || $option_name === '') {
            return $default;
        }

        $args = bjlg_normalize_option_context_args($args_or_site_id, $network);

        if (class_exists('BJLG\\BJLG_Site_Context')) {
            return \BJLG\BJLG_Site_Context::get_option($option_name, $default, $args);
        }

        if (isset($args['site_id']) && function_exists('get_blog_option')) {
            return get_blog_option($args['site_id'], $option_name, $default);
        }

        if (!empty($args['network']) && function_exists('get_site_option')) {
            return get_site_option($option_name, $default);
        }

        return get_option($option_name, $default);
    }
}

if (!function_exists('bjlg_update_option')) {
    /**
     * Wrapper vers BJLG_Site_Context::update_option().
     *
     * @param string          $option_name
     * @param mixed           $value
     * @param array|int|null  $args_or_site_id
     * @param bool|null       $network
     * @param string|bool|null $autoload
     */
    function bjlg_update_option($option_name, $value, $args_or_site_id = null, $network = null, $autoload = null) {
        if (!is_string($option_name) || $option_name === '') {
            return false;
        }

        $args = bjlg_normalize_option_context_args($args_or_site_id, $network);

        if ($autoload !== null && !array_key_exists('autoload', $args)) {
            $args['autoload'] = $autoload;
        }

        if (class_exists('BJLG\\BJLG_Site_Context')) {
            return (bool) \BJLG\BJLG_Site_Context::update_option($option_name, $value, $args);
        }

        if (isset($args['site_id']) && function_exists('update_blog_option')) {
            return (bool) update_blog_option($args['site_id'], $option_name, $value);
        }

        if (!empty($args['network']) && function_exists('update_site_option')) {
            return (bool) update_site_option($option_name, $value);
        }

        if ($autoload !== null) {
            return (bool) update_option($option_name, $value, $autoload);
        }

        return (bool) update_option($option_name, $value);
    }
}

if (!function_exists('bjlg_delete_option')) {
    /**
     * Wrapper vers BJLG_Site_Context::delete_option().
     *
     * @param string         $option_name
     * @param array|int|null $args_or_site_id
     * @param bool|null      $network
     */
    function bjlg_delete_option($option_name, $args_or_site_id = null, $network = null) {
        if (!is_string($option_name) || $option_name === '') {
            return false;
        }

        $args = bjlg_normalize_option_context_args($args_or_site_id, $network);

        if (class_exists('BJLG\\BJLG_Site_Context')) {
            return (bool) \BJLG\BJLG_Site_Context::delete_option($option_name, $args);
        }

        if (isset($args['site_id']) && function_exists('delete_blog_option')) {
            return (bool) delete_blog_option($args['site_id'], $option_name);
        }

        if (!empty($args['network']) && function_exists('delete_site_option')) {
            return (bool) delete_site_option($option_name);
        }

        return (bool) delete_option($option_name);
    }
}

if (!defined('BJLG_DEFAULT_CAPABILITY')) {
    define('BJLG_DEFAULT_CAPABILITY', 'manage_options');
}

if (!function_exists('bjlg_get_required_capability')) {
    /**
     * Returns the capability or role required to access the plugin features.
     *
     * @param string $context Capability context key.
     */
    function bjlg_get_required_capability($context = 'manage_plugin') {
        $map = bjlg_get_capability_map();

        if ($context === 'manage_plugin' && class_exists('BJLG\\BJLG_Site_Context') && \BJLG\BJLG_Site_Context::is_network_context()) {
            if (isset($map['manage_network'])) {
                $context = 'manage_network';
            }
        }

        if (!is_string($context) || $context === '') {
            $context = 'manage_plugin';
        }

        $capability = isset($map[$context]) ? $map[$context] : '';

        if (!is_string($capability) || $capability === '') {
            $capability = isset($map['manage_plugin']) ? $map['manage_plugin'] : BJLG_DEFAULT_CAPABILITY;
        }

        if (!is_string($capability) || $capability === '') {
            $capability = BJLG_DEFAULT_CAPABILITY;
        }

        /**
         * Filters the capability or role required to access the plugin features.
         *
         * @param string $capability Capability or role slug.
         * @param string $context    Capability context key.
         */
        return apply_filters('bjlg_required_capability', $capability, $context);
    }
}

if (!function_exists('bjlg_get_capability_map')) {
    /**
     * Returns the capability map, merging stored values with defaults.
     *
     * @return array<string,string>
     */
    function bjlg_get_capability_map() {
        $is_multisite = function_exists('is_multisite') && is_multisite();
        $is_network_admin = $is_multisite && function_exists('is_network_admin') && is_network_admin();
        $is_network_context = $is_multisite
            && (
                $is_network_admin
                || (class_exists('BJLG\\BJLG_Site_Context') && \BJLG\BJLG_Site_Context::is_network_context())
            );
        $default_capability = $is_network_context ? 'manage_network_options' : BJLG_DEFAULT_CAPABILITY;

        $defaults = [
            'manage_plugin' => $default_capability,
            'manage_backups' => $default_capability,
            'restore' => $default_capability,
            'manage_settings' => $default_capability,
            'manage_integrations' => $default_capability,
            'view_logs' => $default_capability,
        ];

        if (function_exists('is_multisite') && is_multisite()) {
            $defaults['manage_network'] = 'manage_network_options';
        }

        $legacy_permission = bjlg_get_option('bjlg_required_capability', '', ['network' => true]);
        if (is_string($legacy_permission) && $legacy_permission !== '') {
            $defaults['manage_plugin'] = sanitize_text_field($legacy_permission);
        }

        $stored = bjlg_get_option('bjlg_capability_map', [], ['network' => true]);
        if (!is_array($stored)) {
            $stored = [];
        }

        $sanitized = [];
        foreach ($stored as $key => $value) {
            if (!is_string($key) || $key === '' || !array_key_exists($key, $defaults)) {
                continue;
            }

            if (!is_string($value) || $value === '') {
                continue;
            }

            $sanitized[$key] = sanitize_text_field($value);
        }

        $map = array_merge($defaults, $sanitized);

        /**
         * Filters the capability map used by the plugin.
         *
         * @param array<string,string> $map
         */
        $map = apply_filters('bjlg_capability_map', $map);

        if (!is_array($map)) {
            return $defaults;
        }

        foreach ($defaults as $key => $default_value) {
            if (!isset($map[$key]) || !is_string($map[$key]) || $map[$key] === '') {
                $map[$key] = $default_value;
            }
        }

        return $map;
    }
}

if (!function_exists('bjlg_permission_is_role')) {
    /**
     * Determines whether the provided permission name maps to a role.
     *
     * @param string $permission
     *
     * @return bool
     */
    function bjlg_permission_is_role($permission) {
        if (!is_string($permission) || $permission === '') {
            return false;
        }

        $wp_roles = function_exists('wp_roles') ? wp_roles() : null;

        return $wp_roles && class_exists('WP_Roles') && $wp_roles instanceof \WP_Roles && $wp_roles->is_role($permission);
    }
}

if (!function_exists('bjlg_can_manage_plugin')) {
    /**
     * Checks whether a user (or the current user) can access a plugin capability context.
     *
     * @param int|\WP_User|null $user    Optional user to check. Defaults to current user.
     * @param string             $context Capability context key.
     */
    function bjlg_can_manage_plugin($user = null, $context = 'manage_plugin') {
        $permission = bjlg_get_required_capability($context);

        if (!is_string($permission) || $permission === '') {
            $permission = BJLG_DEFAULT_CAPABILITY;
        }

        $is_role = bjlg_permission_is_role($permission);

        if ($is_role) {
            if ($user === null) {
                if (!function_exists('wp_get_current_user')) {
                    return false;
                }
                $user = wp_get_current_user();
            } elseif (is_numeric($user)) {
                $user = get_user_by('id', (int) $user);
            } elseif (is_object($user) && !isset($user->roles) && isset($user->ID)) {
                $user = get_user_by('id', (int) $user->ID);
            }

            if (!is_object($user)) {
                return false;
            }

            $roles = isset($user->roles) ? (array) $user->roles : [];

            return in_array($permission, $roles, true);
        }

        if ($user === null) {
            return function_exists('current_user_can') ? current_user_can($permission) : false;
        }

        return function_exists('user_can') ? user_can($user, $permission) : false;
    }
}

if (!function_exists('bjlg_can_manage_backups')) {
    /**
     * Checks whether the user can manage backup operations (run, schedule, clean).
     */
    function bjlg_can_manage_backups($user = null) {
        return bjlg_can_manage_plugin($user, 'manage_backups');
    }
}

if (!function_exists('bjlg_can_restore_backups')) {
    /**
     * Checks whether the user can run restore operations.
     */
    function bjlg_can_restore_backups($user = null) {
        return bjlg_can_manage_plugin($user, 'restore');
    }
}

if (!function_exists('bjlg_can_manage_settings')) {
    /**
     * Checks whether the user can manage plugin settings.
     */
    function bjlg_can_manage_settings($user = null) {
        return bjlg_can_manage_plugin($user, 'manage_settings');
    }
}

if (!function_exists('bjlg_can_manage_integrations')) {
    /**
     * Checks whether the user can manage external integrations and destinations.
     */
    function bjlg_can_manage_integrations($user = null) {
        return bjlg_can_manage_plugin($user, 'manage_integrations');
    }
}

if (!function_exists('bjlg_can_view_logs')) {
    /**
     * Checks whether the user can view audit logs and history.
     */
    function bjlg_can_view_logs($user = null) {
        return bjlg_can_manage_plugin($user, 'view_logs');
    }
}

if (!function_exists('bjlg_map_required_capability')) {
    /**
     * Maps the custom meta capability used for the admin menu to the configured permission.
     *
     * @param array  $caps
     * @param string $cap
     * @param int    $user_id
     * @param array  $args
     *
     * @return array
     */
    function bjlg_map_required_capability($caps, $cap, $user_id, $args) {
        if ($cap !== 'bjlg_manage_plugin') {
            return $caps;
        }

        $context = class_exists('BJLG\\BJLG_Site_Context') && \BJLG\BJLG_Site_Context::is_network_context()
            ? 'manage_network'
            : 'manage_plugin';

        $permission = bjlg_get_required_capability($context);
        $is_role = bjlg_permission_is_role($permission);

        if ($is_role) {
            $user = is_numeric($user_id) ? get_user_by('id', (int) $user_id) : null;

            if (!is_object($user)) {
                return ['do_not_allow'];
            }

            $roles = isset($user->roles) ? (array) $user->roles : [];

            return in_array($permission, $roles, true) ? [] : ['do_not_allow'];
        }

        return [$permission];
    }

    add_filter('map_meta_cap', 'bjlg_map_required_capability', 10, 4);
}

if (!defined('BJLG_CAPABILITY')) {
    define('BJLG_CAPABILITY', bjlg_get_required_capability());
}

if (!defined('BJLG_BACKUP_DIR')) {
    $current_blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : null;
    $backup_dir = \BJLG\BJLG_Site_Context::get_backup_directory($current_blog_id);
    define('BJLG_BACKUP_DIR', $backup_dir);
}

/* -------------------------------------------------------------------------- */
/* Fonctions Utilitaires Globales                                            */
/* -------------------------------------------------------------------------- */

if (!function_exists('bjlg_with_site')) {
    /**
     * Exécute un callback en basculant sur un site donné.
     */
    function bjlg_with_site($site_id, callable $callback)
    {
        if (!class_exists('BJLG\\BJLG_Site_Context')) {
            return $callback();
        }

        return \BJLG\BJLG_Site_Context::with_site((int) $site_id, $callback);
    }
}

if (!function_exists('bjlg_with_network')) {
    /**
     * Exécute un callback en forçant le contexte réseau.
     */
    function bjlg_with_network(callable $callback)
    {
        if (!class_exists('BJLG\\BJLG_Site_Context')) {
            return $callback();
        }

        return \BJLG\BJLG_Site_Context::with_network($callback);
    }
}

if (!function_exists('bjlg_get_backup_size')) {
    /**
     * Calcule la taille totale de tous les fichiers de sauvegarde.
     * @return int La taille totale en octets.
     */
    function bjlg_get_backup_size() {
        $total_size = 0;
        $files = glob(bjlg_get_backup_directory() . '*.zip*');
        if (!empty($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    $total_size += filesize($file);
                }
            }
        }
        return $total_size;
    }
}

if (!function_exists('bjlg_get_backup_directory')) {
    /**
     * Returns the backup directory for the requested scope.
     *
     * @param int|null    $site_id Optional site identifier.
     * @param string|null $context Either 'site' or 'network'.
     */
    function bjlg_get_backup_directory($site_id = null, $context = null) {
        if (!class_exists('BJLG\\BJLG_Site_Context')) {
            return defined('BJLG_BACKUP_DIR') ? BJLG_BACKUP_DIR : trailingslashit(WP_CONTENT_DIR) . 'uploads/bjlg-backups/';
        }

        $scope = $context === 'network'
            ? \BJLG\BJLG_Site_Context::HISTORY_SCOPE_NETWORK
            : \BJLG\BJLG_Site_Context::HISTORY_SCOPE_SITE;

        $site_id = $site_id !== null ? (int) $site_id : null;

        return \BJLG\BJLG_Site_Context::get_backup_directory($site_id, $scope);
    }
}


/* -------------------------------------------------------------------------- */
/* Classe Principale du Plugin                                               */
/* -------------------------------------------------------------------------- */
final class BJLG_Plugin {

    private static $instance = null;

    /** @var bool */
    private $autoloader_loaded = false;

    /** @var bool */
    private $autoloader_missing_logged = false;

    /** @var array<string, bool> */
    private $missing_includes_logged = [];

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function init() {
        add_action('plugins_loaded', [$this, 'bootstrap']);
        register_activation_hook(BJLG_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(BJLG_PLUGIN_FILE, [$this, 'deactivate']);
    }

    public function bootstrap() {
        $this->include_files();
        $this->init_services();
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('init', [$this, 'load_textdomain'], 5);
    }
    
    private function include_files() {
        $this->maybe_load_autoloader();

        $files_to_load = [
            'class-bjlg-debug.php', 'class-bjlg-client-ip-helper.php', 'class-bjlg-history.php', 'class-bjlg-site-context.php', 'class-bjlg-settings.php',
            'class-bjlg-backup.php', 'class-bjlg-restore.php', 'class-bjlg-scheduler.php',
            'class-bjlg-cleanup.php', 'class-bjlg-encryption.php', 'class-bjlg-health-check.php',
            'class-bjlg-diagnostics.php', 'class-bjlg-webhooks.php', 'class-bjlg-incremental.php',
            'class-bjlg-notification-transport.php', 'class-bjlg-notification-receipts.php', 'class-bjlg-notification-queue.php', 'class-bjlg-notifications.php', 'class-bjlg-destination-factory.php', 'class-bjlg-remote-storage-metrics.php', 'class-bjlg-remote-purge-worker.php',
            'class-bjlg-restore-self-test.php',
            'class-bjlg-update-guard.php',
            'class-bjlg-performance.php', 'class-bjlg-rate-limiter.php', 'class-bjlg-rest-api.php',
            'class-bjlg-api-keys.php', 'class-bjlg-admin-advanced.php', 'class-bjlg-admin.php', 'class-bjlg-admin-fallbacks.php', 'class-bjlg-actions.php',
            'destinations/interface-bjlg-destination.php', 'destinations/class-bjlg-remote-storage-usage-exception.php', 'destinations/abstract-class-bjlg-s3-compatible.php',
            'destinations/class-bjlg-google-drive.php', 'destinations/class-bjlg-aws-s3.php', 'destinations/class-bjlg-sftp.php',
            'destinations/class-bjlg-wasabi.php', 'destinations/class-bjlg-dropbox.php', 'destinations/class-bjlg-onedrive.php',
            'destinations/class-bjlg-pcloud.php', 'destinations/class-bjlg-managed-replication.php',
        ];
        foreach ($files_to_load as $file) {
            $path = BJLG_INCLUDES_DIR . $file;
            if (file_exists($path)) {
                require_once $path;
                continue;
            }

            if (defined('WP_DEBUG') && WP_DEBUG && empty($this->missing_includes_logged[$path])) {
                error_log(sprintf('[Backup JLG] Fichier attendu manquant : %s', $path));
                $this->missing_includes_logged[$path] = true;
            }
        }
    }

    private function maybe_load_autoloader() {
        if ($this->autoloader_loaded) {
            return;
        }

        $autoloader = BJLG_PLUGIN_DIR . 'vendor-bjlg/autoload.php';

        if (is_readable($autoloader)) {
            require_once $autoloader;
            $this->autoloader_loaded = true;
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG && !$this->autoloader_missing_logged) {
            error_log(sprintf('[Backup JLG] Autoloader introuvable : %s', $autoloader));
            $this->autoloader_missing_logged = true;
        }
    }
    
    private function init_services() {
        if (class_exists(BJLG\BJLG_Site_Context::class)) {
            BJLG\BJLG_Site_Context::bootstrap();
        }

        new BJLG\BJLG_Admin();
        new BJLG\BJLG_Admin_Fallbacks();
        BJLG\BJLG_Actions::bootstrap();
        new BJLG\BJLG_Actions();

        $encryption_service = new BJLG\BJLG_Encryption();
        $performance_service = new BJLG\BJLG_Performance();

        $backup_manager = new BJLG\BJLG_Backup($performance_service, $encryption_service);
        new BJLG\BJLG_Restore($backup_manager, $encryption_service);

        BJLG\BJLG_Scheduler::instance();
        BJLG\BJLG_Scheduler::init_hooks();
        BJLG\BJLG_Cleanup::instance();
        new BJLG\BJLG_Health_Check();
        new BJLG\BJLG_Diagnostics();
        new BJLG\BJLG_Webhooks();
        new BJLG\BJLG_Incremental();
        new BJLG\BJLG_Notification_Queue();
        add_action('init', [\BJLG\BJLG_Notifications::class, 'instance'], 20);
        new BJLG\BJLG_REST_API();
        new BJLG\BJLG_Settings();
        new BJLG\BJLG_API_Keys();
        new BJLG\BJLG_Remote_Purge_Worker();
        new BJLG\BJLG_Remote_Storage_Metrics();
        new BJLG\BJLG_Restore_Self_Test();
        new BJLG\BJLG_Update_Guard();
        BJLG\BJLG_Event_Triggers::instance();
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'backup-jlg') === false) {
            return;
        }

        $is_network_page = strpos($hook, 'backup-jlg-network') !== false;

        $active_section = isset($_GET['section']) ? sanitize_key($_GET['section']) : '';
        if ($active_section === '' && isset($_GET['tab'])) {
            $active_section = sanitize_key($_GET['tab']);
        }

        if ($is_network_page) {
            $active_section = 'network';
        } elseif ($active_section === '') {
            $active_section = 'monitoring';
        }

        wp_enqueue_style('bjlg-admin', BJLG_PLUGIN_URL . 'assets/css/admin.css', [], BJLG_VERSION);
        wp_enqueue_style('bjlg-admin-advanced', BJLG_PLUGIN_URL . 'assets/css/admin-advanced.css', [], BJLG_VERSION);

        $chart_asset_path = BJLG_PLUGIN_DIR . 'assets/js/vendor/chart.umd.min.js';
        $chart_asset_version = file_exists($chart_asset_path) ? filemtime($chart_asset_path) : '4.4.4';
        $chart_asset_url = BJLG_PLUGIN_URL . 'assets/js/vendor/chart.umd.min.js';

        $module_files = [
            'advanced' => 'assets/js/admin-advanced.js',
            'dashboard' => 'assets/js/admin-dashboard.js',
            'backup' => 'assets/js/admin-backup.js',
            'scheduling' => 'assets/js/admin-scheduling.js',
            'settings' => 'assets/js/admin-settings.js',
            'logs' => 'assets/js/admin-logs.js',
            'api' => 'assets/js/admin-api.js',
            'rbac' => 'assets/js/admin-rbac.js',
            'network' => 'assets/js/admin-network.js',
        ];

        $module_urls = [];
        foreach ($module_files as $module_key => $relative_path) {
            $absolute_path = BJLG_PLUGIN_DIR . $relative_path;
            $absolute_url = BJLG_PLUGIN_URL . $relative_path;
            $version = file_exists($absolute_path) ? filemtime($absolute_path) : BJLG_VERSION;
            $module_urls[$module_key] = esc_url_raw(add_query_arg('ver', $version, $absolute_url));
        }

        $section_modules = [
            'monitoring' => ['dashboard', 'logs'],
            'backup' => ['dashboard', 'backup', 'scheduling'],
            'restore' => ['backup'],
            'settings' => ['settings'],
            'integrations' => ['api'],
            'rbac' => ['rbac'],
            'network' => ['network'],
        ];

        wp_enqueue_script(
            'bjlg-admin',
            BJLG_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'wp-a11y', 'wp-i18n', 'wp-element', 'wp-components', 'wp-api-fetch'],
            BJLG_VERSION,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('bjlg-admin', 'backup-jlg', BJLG_PLUGIN_DIR . 'languages');
        }

        $localized_data = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('bjlg_nonce'),
            'api_keys_nonce' => wp_create_nonce('bjlg_api_keys'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'rest_namespace' => 'backup-jlg/v1',
            'rest_root' => esc_url_raw(rest_url()),
            'rest_backups' => esc_url_raw(rest_url('backup-jlg/v1/backups')),
            'active_tab' => $active_section,
            'active_section' => $active_section,
            'chart_url' => esc_url_raw(add_query_arg('ver', $chart_asset_version, $chart_asset_url)),
            'modules' => $module_urls,
            'tab_modules' => $section_modules,
            'section_modules' => $section_modules,
            'onboarding_nonce' => wp_create_nonce('bjlg_onboarding_progress'),
            'cron_assistant' => [
                'examples' => [
                    [
                        'label' => __('Tous les jours à 03h00', 'backup-jlg'),
                        'expression' => '0 3 * * *',
                    ],
                    [
                        'label' => __('Chaque lundi à 01h30', 'backup-jlg'),
                        'expression' => '30 1 * * 1',
                    ],
                    [
                        'label' => __('Du lundi au vendredi à 22h00', 'backup-jlg'),
                        'expression' => '0 22 * * 1-5',
                    ],
                    [
                        'label' => __('Le premier jour du mois à 04h15', 'backup-jlg'),
                        'expression' => '15 4 1 * *',
                    ],
                    [
                        'label' => __('Toutes les deux heures', 'backup-jlg'),
                        'expression' => '0 */2 * * *',
                    ],
                ],
                'labels' => [
                    'empty' => __('Saisissez une expression Cron (minute heure jour mois jour-semaine).', 'backup-jlg'),
                    'loading' => __('Analyse de l’expression…', 'backup-jlg'),
                    'error' => __('Impossible d’analyser cette expression Cron.', 'backup-jlg'),
                    'apply_example' => __('Utiliser “%s”', 'backup-jlg'),
                    'preview_title' => __('Prochaines exécutions', 'backup-jlg'),
                    'share_macro' => __('Partager', 'backup-jlg'),
                    'share_macro_success' => __('Macro copiée dans le presse-papiers.', 'backup-jlg'),
                    'share_macro_failure' => __('Impossible de copier automatiquement la macro. Copiez la configuration suivante : %s', 'backup-jlg'),
                    'catalog_title' => __('Catalogue d’exemples', 'backup-jlg'),
                    'catalog_empty' => __('Aucun exemple enregistré pour le moment.', 'backup-jlg'),
                    'preset_apply' => __('Appliquer ce preset', 'backup-jlg'),
                    'preset_applied' => __('Preset appliqué à la planification active.', 'backup-jlg'),
                    'preset_empty' => __('Aucun preset n’est disponible pour le moment.', 'backup-jlg'),
                    'relative_future' => __('dans %s', 'backup-jlg'),
                    'relative_past' => __('il y a %s', 'backup-jlg'),
                ],
            ],
        ];

        if ($is_network_page) {
            $localized_data['network'] = [
                'enabled' => \BJLG\BJLG_Site_Context::is_network_mode_enabled(),
                'endpoints' => [
                    'schedules' => esc_url_raw(rest_url(\BJLG\BJLG_REST_API::API_NAMESPACE . '/settings/schedule')),
                    'history' => esc_url_raw(rest_url(\BJLG\BJLG_REST_API::API_NAMESPACE . '/history')),
                    'stats' => esc_url_raw(rest_url(\BJLG\BJLG_REST_API::API_NAMESPACE . '/stats')),
                ],
            ];
        }

        wp_localize_script('bjlg-admin', 'bjlg_ajax', $localized_data);
    }

    public function load_textdomain() {
        load_plugin_textdomain('backup-jlg', false, dirname(BJLG_PLUGIN_BASENAME) . '/languages');
    }

    public function activate() {
        require_once BJLG_INCLUDES_DIR . 'class-bjlg-debug.php';
        require_once BJLG_INCLUDES_DIR . 'class-bjlg-history.php';

        $default_history_scope = \BJLG\BJLG_Site_Context::sanitize_history_scope(
            apply_filters('bjlg_default_history_scope', \BJLG\BJLG_Site_Context::HISTORY_SCOPE_SITE)
        );

        if (function_exists('is_multisite') && is_multisite()) {
            $site_ids = get_sites(['fields' => 'ids']);

            foreach ((array) $site_ids as $site_id) {
                bjlg_with_site((int) $site_id, function () use ($site_id) {
                    $this->activate_single_site((int) $site_id);
                });
            }

            bjlg_with_network(function () use ($default_history_scope) {
                if (get_site_option(\BJLG\BJLG_Site_Context::NETWORK_MODE_OPTION, null) === null) {
                    \BJLG\BJLG_Site_Context::set_network_mode(\BJLG\BJLG_Site_Context::NETWORK_MODE_SITE);
                }

                BJLG\BJLG_History::create_table(0);
                if (bjlg_get_option('bjlg_required_capability', null, ['network' => true]) === null) {
                    bjlg_update_option('bjlg_required_capability', BJLG_DEFAULT_CAPABILITY, ['network' => true]);
                }

                if (bjlg_get_option('bjlg_capability_map', null, ['network' => true]) === null) {
                    bjlg_update_option('bjlg_capability_map', [], ['network' => true]);
                }

                if (get_site_option(\BJLG\BJLG_Site_Context::HISTORY_SCOPE_OPTION, null) === null) {
                    \BJLG\BJLG_Site_Context::set_history_scope($default_history_scope);
                }

                $network_backup_dir = \BJLG\BJLG_Site_Context::get_backup_directory(null, \BJLG\BJLG_Site_Context::HISTORY_SCOPE_NETWORK);
                $this->ensure_backup_directory($network_backup_dir);
            });

            return;
        }

        $this->activate_single_site();

        if (get_option(\BJLG\BJLG_Site_Context::HISTORY_SCOPE_OPTION, null) === null) {
            \BJLG\BJLG_Site_Context::set_history_scope($default_history_scope);
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('bjlg_scheduled_backup_hook');
        wp_clear_scheduled_hook('bjlg_daily_cleanup_hook');
    }

    private function activate_single_site(?int $blog_id = null): void {
        BJLG\BJLG_History::create_table($blog_id);

        if (bjlg_get_option('bjlg_required_capability', null) === null) {
            bjlg_update_option('bjlg_required_capability', BJLG_DEFAULT_CAPABILITY);
        }

        if (bjlg_get_option('bjlg_capability_map', null) === null) {
            bjlg_update_option('bjlg_capability_map', []);
        }

        $target_blog_id = $blog_id;
        if ($target_blog_id === null && function_exists('get_current_blog_id')) {
            $target_blog_id = (int) get_current_blog_id();
        }

        $backup_dir = \BJLG\BJLG_Site_Context::get_backup_directory($target_blog_id);
        $this->ensure_backup_directory($backup_dir);
    }

    private function ensure_backup_directory(string $backup_dir): void {
        if ($backup_dir === '') {
            return;
        }

        if (!is_dir($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }

        if (!is_dir($backup_dir) || !is_writable($backup_dir)) {
            return;
        }

        $sentinels = [
            '.htaccess' => "deny from all\n",
            'index.php' => "<?php\nexit;\n",
        ];

        foreach ($sentinels as $filename => $contents) {
            $path = $backup_dir . $filename;
            if (!file_exists($path)) {
                file_put_contents($path, $contents);
            }
        }

        $web_config_path = $backup_dir . 'web.config';
        if (!file_exists($web_config_path)) {
            $web_config_contents = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <authorization>
            <deny users="*" />
        </authorization>
    </system.webServer>
</configuration>
XML;

            file_put_contents($web_config_path, $web_config_contents);
        }
    }
}

// Lancement du plugin
BJLG_Plugin::instance();

