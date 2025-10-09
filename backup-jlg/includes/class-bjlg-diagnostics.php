<?php
namespace BJLG;

use Exception;
use ZipArchive;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gère la création d'un pack de support pour le diagnostic.
 */
class BJLG_Diagnostics {

    public function __construct() {
        add_action('wp_ajax_bjlg_generate_support_package', [$this, 'handle_generate_support_package']);
        add_action('wp_ajax_bjlg_run_diagnostic_test', [$this, 'handle_run_diagnostic_test']);
        add_action('wp_ajax_bjlg_get_system_info', [$this, 'handle_get_system_info']);
    }

    /**
     * Gère la requête AJAX pour créer et fournir le pack de support.
     */
    public function handle_generate_support_package() {
        if (!\bjlg_can_view_logs()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        try {
            BJLG_Debug::log("Début de la génération du pack de support.");

            // Vérification des prérequis
            if (!is_writable(BJLG_BACKUP_DIR)) {
                throw new Exception("Le dossier de sauvegarde n'est pas accessible en écriture.");
            }
            if (!class_exists('ZipArchive')) {
                throw new Exception("La classe PHP ZipArchive est manquante et requise.");
            }

            $zip_filename = 'support-package-' . date('Y-m-d-H-i-s') . '.zip';
            $zip_filepath = BJLG_BACKUP_DIR . $zip_filename;
            
            $zip = new ZipArchive();
            if ($zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception("Impossible de créer le fichier ZIP de support.");
            }

            // 1. Ajouter le log du plugin
            $plugin_log_path = WP_CONTENT_DIR . '/bjlg-debug.log';
            if (file_exists($plugin_log_path)) {
                $zip->addFile($plugin_log_path, 'bjlg-debug.log');
            } else {
                $zip->addFromString('bjlg-debug.log', 'Aucun log de débogage trouvé.');
            }

            // 2. Ajouter le log d'erreurs WP (limité aux 1000 dernières lignes)
            $wp_log_content = BJLG_Debug::get_wp_error_log_content(1000);
            $zip->addFromString('wp-debug.log', $wp_log_content);

            // 3. Ajouter le bilan de santé
            $health_checker = new BJLG_Health_Check();
            $health_report = $health_checker->export_health_report();
            $zip->addFromString('bilan-de-sante.txt', $health_report);

            // 4. Ajouter les infos serveur
            $server_info = $this->get_server_info();
            $zip->addFromString('infos-serveur.txt', $server_info);
            
            // 5. Ajouter les infos PHP
            $php_info = $this->get_php_info();
            $zip->addFromString('php-info.txt', $php_info);
            
            // 6. Ajouter la configuration du plugin
            $plugin_config = $this->get_plugin_configuration();
            $zip->addFromString('configuration-plugin.json', json_encode($plugin_config, JSON_PRETTY_PRINT));
            
            // 7. Ajouter l'historique récent
            $history = BJLG_History::get_history(100);
            $history_csv = $this->format_history_as_csv($history);
            $zip->addFromString('historique-recent.csv', $history_csv);
            
            // 8. Ajouter la liste des plugins actifs
            $plugins_info = $this->get_plugins_info();
            $zip->addFromString('plugins-actifs.txt', $plugins_info);
            
            // 9. Ajouter les statistiques de sauvegarde
            $backup_stats = $this->get_backup_statistics();
            $zip->addFromString('statistiques-sauvegardes.json', json_encode($backup_stats, JSON_PRETTY_PRINT));

            $zip->close();

            $real_zip_path = realpath($zip_filepath);

            if ($real_zip_path === false) {
                throw new Exception("Impossible de localiser le pack de support généré.");
            }

            $download_token = wp_generate_password(32, false);
            $transient_key = 'bjlg_download_' . $download_token;
            $payload = BJLG_Actions::build_download_token_payload($real_zip_path, \bjlg_get_required_capability());
            $payload['delete_after_download'] = true;
            $ttl = BJLG_Actions::get_download_token_ttl($real_zip_path);

            if (!set_transient($transient_key, $payload, $ttl)) {
                @unlink($real_zip_path);
                throw new Exception("Impossible de préparer le téléchargement du pack de support.");
            }

            $download_url = BJLG_Actions::build_download_url($download_token);
            $file_size = filesize($real_zip_path);
            
            BJLG_Debug::log("Pack de support créé avec succès : " . $zip_filename);
            BJLG_History::log('support_package', 'success', 'Pack de support créé : ' . $zip_filename);
            
            wp_send_json_success([
                'download_url' => $download_url,
                'filename' => $zip_filename,
                'size' => size_format($file_size),
                'message' => 'Pack de support généré avec succès.'
            ]);

        } catch (Exception $e) {
            BJLG_Debug::log("ERREUR lors de la création du pack de support : " . $e->getMessage());
            BJLG_History::log('support_package', 'failure', "Erreur : " . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Exécute un test de diagnostic spécifique
     */
    public function handle_run_diagnostic_test() {
        if (!\bjlg_can_view_logs()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        $test = sanitize_text_field($_POST['test'] ?? '');
        
        try {
            $result = [];
            
            switch ($test) {
                case 'database_connection':
                    $result = $this->test_database_connection();
                    break;
                    
                case 'file_permissions':
                    $result = $this->test_file_permissions();
                    break;
                    
                case 'backup_creation':
                    $result = $this->test_backup_creation();
                    break;
                    
                case 'memory_usage':
                    $result = $this->test_memory_usage();
                    break;
                    
                case 'network_connectivity':
                    $result = $this->test_network_connectivity();
                    break;
                    
                default:
                    throw new Exception("Test inconnu : $test");
            }
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Obtient les informations système
     */
    public function handle_get_system_info() {
        if (!\bjlg_can_view_logs()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        
        $info = [
            'server' => $this->get_server_array(),
            'php' => $this->get_php_array(),
            'wordpress' => $this->get_wordpress_array(),
            'database' => $this->get_database_array(),
            'plugin' => $this->get_plugin_array()
        ];
        
        wp_send_json_success($info);
    }
    
    /**
     * Obtient les informations serveur formatées
     */
    private function get_server_info() {
        global $wpdb;
        
        $info = "=== INFORMATIONS SERVEUR ===\n\n";
        
        $info .= "--- WordPress ---\n";
        $info .= "Version WP: " . get_bloginfo('version') . "\n";
        $info .= "URL du site: " . get_site_url() . "\n";
        $info .= "URL d'accueil: " . get_home_url() . "\n";
        $info .= "Multisite: " . (is_multisite() ? 'Oui' : 'Non') . "\n";
        $info .= "Langue: " . get_locale() . "\n";
        $info .= "Fuseau horaire: " . get_option('timezone_string') . "\n";
        $info .= "Format de date: " . get_option('date_format') . "\n";
        $info .= "Format d'heure: " . get_option('time_format') . "\n\n";
        
        $info .= "--- Serveur Web ---\n";
        $info .= "Logiciel: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Inconnu') . "\n";
        $info .= "Système: " . php_uname() . "\n";
        $info .= "Nom du serveur: " . ($_SERVER['SERVER_NAME'] ?? 'Inconnu') . "\n";
        $info .= "Adresse IP: " . ($_SERVER['SERVER_ADDR'] ?? 'Inconnue') . "\n";
        $info .= "Port: " . ($_SERVER['SERVER_PORT'] ?? 'Inconnu') . "\n";
        $info .= "Protocole: " . ($_SERVER['SERVER_PROTOCOL'] ?? 'Inconnu') . "\n\n";
        
        $info .= "--- PHP ---\n";
        $info .= "Version PHP: " . phpversion() . "\n";
        $info .= "SAPI: " . php_sapi_name() . "\n";
        $info .= "Limite mémoire WP: " . WP_MEMORY_LIMIT . "\n";
        $info .= "Limite mémoire PHP: " . ini_get('memory_limit') . "\n";
        $info .= "Temps d'exécution max: " . ini_get('max_execution_time') . " secondes\n";
        $info .= "Taille max upload: " . ini_get('upload_max_filesize') . "\n";
        $info .= "Taille max POST: " . ini_get('post_max_size') . "\n\n";
        
        $info .= "--- Base de données ---\n";
        $info .= "Version MySQL: " . $wpdb->db_version() . "\n";
        $info .= "Nom de la base: " . DB_NAME . "\n";
        $info .= "Hôte: " . DB_HOST . "\n";
        $info .= "Charset: " . DB_CHARSET . "\n";
        $info .= "Collation: " . DB_COLLATE . "\n";
        $info .= "Préfixe des tables: " . $wpdb->prefix . "\n\n";
        
        $info .= "--- Espace disque ---\n";
        $info .= "Espace libre: " . size_format(disk_free_space(ABSPATH)) . "\n";
        $info .= "Espace total: " . size_format(disk_total_space(ABSPATH)) . "\n";
        
        return $info;
    }
    
    /**
     * Obtient les informations PHP détaillées
     */
private function get_php_info() {
        $info = "=== CONFIGURATION PHP ===\n\n";
        
        $info .= "--- Extensions chargées ---\n";
        $extensions = get_loaded_extensions();
        sort($extensions);
        foreach ($extensions as $ext) {
            $info .= "- $ext\n";
        }
        
        $info .= "\n--- Variables importantes ---\n";
        $important_ini = [
            'memory_limit',
            'max_execution_time',
            'upload_max_filesize',
            'post_max_size',
            'max_input_time',
            'max_input_vars',
            'display_errors',
            'log_errors',
            'error_reporting'
        ];
        
        foreach ($important_ini as $key) {
            $info .= "$key = " . ini_get($key) . "\n";
        }
        
        return $info;
    }
    
    // Ajoutez les autres méthodes manquantes
    private function get_plugin_configuration() {
        return get_option('bjlg_settings', []);
    }
    
    private function format_history_as_csv($history) {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['Date', 'Action', 'Statut', 'Détails', 'Utilisateur'], ',', '"', '\\');

        foreach ($history as $entry) {
            fputcsv($handle, [
                $entry['timestamp'] ?? '',
                $entry['action_type'] ?? '',
                $entry['status'] ?? '',
                $entry['details'] ?? '',
                $entry['user_name'] ?? '',
            ], ',', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }
    
    private function get_plugins_info() {
        $info = "=== PLUGINS ACTIFS ===\n\n";
        $plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        
        foreach ($active_plugins as $plugin_path) {
            if (isset($plugins[$plugin_path])) {
                $plugin = $plugins[$plugin_path];
                $info .= "- {$plugin['Name']} v{$plugin['Version']}\n";
            }
        }
        return $info;
    }
    
    private function get_backup_statistics() {
        return [
            'total_backups' => 0,
            'last_backup' => null,
            'total_size' => 0
        ];
    }
    
    // Méthodes de test
    private function test_database_connection() {
        global $wpdb;
        $wpdb->query("SELECT 1");
        return ['status' => 'success', 'message' => 'Connexion OK'];
    }
    
    private function test_file_permissions() {
        $writable = is_writable(BJLG_BACKUP_DIR);
        return [
            'status' => $writable ? 'success' : 'error',
            'message' => $writable ? 'Permissions OK' : 'Dossier non accessible en écriture'
        ];
    }
    
    private function test_backup_creation() {
        $test_file = BJLG_BACKUP_DIR . 'test-' . time() . '.txt';
        $created = file_put_contents($test_file, 'test');
        if ($created) {
            unlink($test_file);
            return ['status' => 'success', 'message' => 'Test réussi'];
        }
        return ['status' => 'error', 'message' => 'Impossible de créer un fichier test'];
    }
    
    private function test_memory_usage() {
        $usage = memory_get_usage(true);
        $limit = ini_get('memory_limit');
        return [
            'status' => 'success',
            'usage' => size_format($usage),
            'limit' => $limit
        ];
    }
    
    private function test_network_connectivity() {
        $response = wp_remote_get('https://api.wordpress.org/core/version-check/1.7/');
        if (!is_wp_error($response)) {
            return ['status' => 'success', 'message' => 'Connectivité OK'];
        }
        return ['status' => 'error', 'message' => 'Problème de connectivité'];
    }
    
    // Méthodes pour arrays
    private function get_server_array() {
        return [
            'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Inconnu',
            'system' => php_uname()
        ];
    }
    
    private function get_php_array() {
        return [
            'version' => phpversion(),
            'memory_limit' => ini_get('memory_limit')
        ];
    }
    
    private function get_wordpress_array() {
        return [
            'version' => get_bloginfo('version'),
            'multisite' => is_multisite()
        ];
    }
    
    private function get_database_array() {
        global $wpdb;
        return [
            'version' => $wpdb->db_version(),
            'database' => DB_NAME
        ];
    }
    
    private function get_plugin_array() {
        return [
            'version' => BJLG_VERSION,
            'directory' => BJLG_BACKUP_DIR
        ];
    }
}