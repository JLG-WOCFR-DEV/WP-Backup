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
define('BJLG_VERSION', '2.0.3');
define('BJLG_PLUGIN_FILE', __FILE__);
define('BJLG_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('BJLG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BJLG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BJLG_INCLUDES_DIR', BJLG_PLUGIN_DIR . 'includes/');
define('BJLG_CAPABILITY', 'manage_options');

if (!defined('BJLG_BACKUP_DIR')) {
    $uploads = wp_get_upload_dir();
    define('BJLG_BACKUP_DIR', trailingslashit($uploads['basedir']) . 'bjlg-backups/');
}

/* -------------------------------------------------------------------------- */
/* Fonctions Utilitaires Globales                                            */
/* -------------------------------------------------------------------------- */

if (!function_exists('bjlg_get_backup_size')) {
    /**
     * Calcule la taille totale de tous les fichiers de sauvegarde.
     * @return int La taille totale en octets.
     */
    function bjlg_get_backup_size() {
        $total_size = 0;
        $files = glob(BJLG_BACKUP_DIR . '*.zip*');
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


/* -------------------------------------------------------------------------- */
/* Classe Principale du Plugin                                               */
/* -------------------------------------------------------------------------- */
final class BJLG_Plugin {

    private static $instance = null;

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
        add_action('init', [$this, 'load_textdomain']);
    }
    
    private function include_files() {
        $files_to_load = [
            'class-bjlg-debug.php', 'class-bjlg-client-ip-helper.php', 'class-bjlg-history.php', 'class-bjlg-settings.php',
            'class-bjlg-backup.php', 'class-bjlg-restore.php', 'class-bjlg-scheduler.php',
            'class-bjlg-cleanup.php', 'class-bjlg-encryption.php', 'class-bjlg-health-check.php',
            'class-bjlg-diagnostics.php', 'class-bjlg-webhooks.php', 'class-bjlg-incremental.php',
            'class-bjlg-performance.php', 'class-bjlg-rate-limiter.php', 'class-bjlg-rest-api.php',
            'class-bjlg-api-keys.php', 'class-bjlg-admin-advanced.php', 'class-bjlg-admin.php', 'class-bjlg-actions.php',
            'destinations/interface-bjlg-destination.php', 'destinations/class-bjlg-google-drive.php', 'destinations/class-bjlg-aws-s3.php', 'destinations/class-bjlg-sftp.php',
        ];
        foreach ($files_to_load as $file) {
            $path = BJLG_INCLUDES_DIR . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }
    
    private function init_services() {
        new BJLG\BJLG_Admin();
        new BJLG\BJLG_Actions();

        $encryption_service = new BJLG\BJLG_Encryption();
        $performance_service = new BJLG\BJLG_Performance();

        $backup_manager = new BJLG\BJLG_Backup($performance_service, $encryption_service);
        new BJLG\BJLG_Restore($backup_manager, $encryption_service);

        BJLG\BJLG_Scheduler::instance();
        BJLG\BJLG_Cleanup::instance();
        new BJLG\BJLG_Health_Check();
        new BJLG\BJLG_Diagnostics();
        new BJLG\BJLG_Webhooks();
        new BJLG\BJLG_Incremental();
        new BJLG\BJLG_REST_API();
        new BJLG\BJLG_Settings();
        new BJLG\BJLG_API_Keys();
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'backup-jlg') === false) return;

        wp_enqueue_style('bjlg-admin', BJLG_PLUGIN_URL . 'assets/css/admin.css', [], BJLG_VERSION);
        wp_enqueue_style('bjlg-admin-advanced', BJLG_PLUGIN_URL . 'assets/css/admin-advanced.css', [], BJLG_VERSION);
        wp_enqueue_script('bjlg-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', [], '4.4.4', true);
        wp_enqueue_script('bjlg-admin', BJLG_PLUGIN_URL . 'assets/js/admin.js', ['jquery', 'bjlg-chartjs'], BJLG_VERSION, true);
        wp_localize_script('bjlg-admin', 'bjlg_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('bjlg_nonce'),
            'api_keys_nonce' => wp_create_nonce('bjlg_api_keys'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'rest_namespace' => 'backup-jlg/v1',
            'rest_root' => esc_url_raw(rest_url()),
            'rest_backups' => esc_url_raw(rest_url('backup-jlg/v1/backups')),
        ]);
    }

    public function load_textdomain() {
        load_plugin_textdomain('backup-jlg', false, dirname(BJLG_PLUGIN_BASENAME) . '/languages');
    }

    public function activate() {
        require_once BJLG_INCLUDES_DIR . 'class-bjlg-debug.php';
        require_once BJLG_INCLUDES_DIR . 'class-bjlg-history.php';
        BJLG\BJLG_History::create_table();

        if (!is_dir(BJLG_BACKUP_DIR)) {
            wp_mkdir_p(BJLG_BACKUP_DIR);
        }
        if (is_dir(BJLG_BACKUP_DIR) && is_writable(BJLG_BACKUP_DIR)) {
            $htaccess_path = BJLG_BACKUP_DIR . '.htaccess';
            if (!file_exists($htaccess_path)) {
                file_put_contents($htaccess_path, 'deny from all');
            }
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('bjlg_scheduled_backup_hook');
        wp_clear_scheduled_hook('bjlg_daily_cleanup_hook');
    }
}

// Lancement du plugin
BJLG_Plugin::instance();

