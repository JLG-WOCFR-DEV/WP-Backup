<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gère les diagnostics et le bilan de santé du système et du plugin.
 */
class BJLG_Health_Check {

    /**
     * Exécute tous les tests et retourne un tableau de résultats.
     * @return array
     */
    public function get_all_checks() {
        BJLG_Debug::log("Exécution du bilan de santé du système.");
        
        $checks = [];
        
        // --- Section "État du Plugin" ---
        $checks['debug_mode'] = $this->check_debug_mode_status();
        $checks['cron_status'] = $this->check_cron_status();
        
        // --- Section "Configuration Serveur" ---
        $checks['backup_dir'] = $this->check_backup_directory();
        $checks['disk_space'] = $this->check_disk_space();
        $checks['php_memory_limit'] = $this->check_php_memory_limit();
        $checks['php_execution_time'] = $this->check_php_execution_time();
        
        // --- Nouvelles vérifications ---
        $checks['php_version'] = $this->check_php_version();
        $checks['wordpress_version'] = $this->check_wordpress_version();
        $checks['database_size'] = $this->check_database_size();
        $checks['zip_extension'] = $this->check_zip_extension();
        $checks['encryption_support'] = $this->check_encryption_support();
        $checks['exec_function'] = $this->check_exec_function();

        return $checks;
    }

    /**
     * Vérifie si le mode débogage du plugin est actif.
     * @return array ['status' => 'success'|'info', 'message' => string]
     */
    private function check_debug_mode_status() {
        if (defined('BJLG_DEBUG') && BJLG_DEBUG === true) {
            $log_size = BJLG_Debug::get_log_size();
            $size_formatted = size_format($log_size);
            return [
                'status' => 'success', 
                'message' => "Activé. Les actions sont enregistrées dans le fichier de log ($size_formatted)."
            ];
        }
        return [
            'status' => 'info', 
            'message' => 'Désactivé. Pour l\'activer, ajoutez define(\'BJLG_DEBUG\', true); à votre wp-config.php.'
        ];
    }

    /**
     * Vérifie le statut du planificateur de tâches de WordPress.
     * @return array ['status' => 'success'|'warning'|'error', 'message' => string]
     */
    private function check_cron_status() {
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            return [
                'status' => 'error', 
                'message' => "WP-Cron est désactivé via la constante DISABLE_WP_CRON. Les tâches planifiées ne fonctionneront pas automatiquement."
            ];
        }

        // Vérifier la tâche de nettoyage
        $cleanup_timestamp = wp_next_scheduled(BJLG_Cleanup::CRON_HOOK);
        
        // Vérifier la tâche de sauvegarde planifiée
        $backup_timestamp = wp_next_scheduled(BJLG_Scheduler::SCHEDULE_HOOK);
        
        $messages = [];
        
        if ($cleanup_timestamp) {
            if ($cleanup_timestamp < time()) {
                $messages[] = "Nettoyage en retard de " . human_time_diff($cleanup_timestamp, time());
            } else {
                $messages[] = "Nettoyage : " . get_date_from_gmt($this->format_gmt_datetime($cleanup_timestamp), 'd/m/Y H:i');
            }
        }

        if ($backup_timestamp) {
            if ($backup_timestamp < time()) {
                $messages[] = "Sauvegarde en retard de " . human_time_diff($backup_timestamp, time());
            } else {
                $messages[] = "Sauvegarde : " . get_date_from_gmt($this->format_gmt_datetime($backup_timestamp), 'd/m/Y H:i');
            }
        }
        
        if (empty($messages)) {
            return [
                'status' => 'info',
                'message' => 'Aucune tâche planifiée active.'
            ];
        }
        
        $has_warning = (strpos(implode(' ', $messages), 'retard') !== false);
        
        return [
            'status' => $has_warning ? 'warning' : 'success',
            'message' => implode(' | ', $messages)
        ];
    }

    /**
     * Retourne la date/heure GMT formatée attendue par get_date_from_gmt().
     */
    private function format_gmt_datetime($timestamp) {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Vérifie si le dossier de sauvegarde est accessible en écriture.
     * @return array ['status' => 'success'|'error', 'message' => string]
     */
    private function check_backup_directory() {
        if (!is_dir(BJLG_BACKUP_DIR)) {
            if (!@mkdir(BJLG_BACKUP_DIR, 0755, true)) {
                return [
                    'status' => 'error', 
                    'message' => "Le dossier de sauvegarde n'existe pas et n'a pas pu être créé. Chemin : " . BJLG_BACKUP_DIR
                ];
            }
        }
        
        if (!is_writable(BJLG_BACKUP_DIR)) {
            return [
                'status' => 'error', 
                'message' => "Le dossier de sauvegarde n'est pas accessible en écriture ! Veuillez vérifier les permissions (CHMOD 755 ou 775)."
            ];
        }
        
        // Vérifier la présence des fichiers sentinelles
        $sentinels = [
            '.htaccess' => "deny from all\n",
            'index.php' => "<?php\nexit;\n",
        ];

        foreach ($sentinels as $filename => $contents) {
            $path = BJLG_BACKUP_DIR . $filename;
            if (!file_exists($path)) {
                @file_put_contents($path, $contents);
            }
        }

        $web_config_path = BJLG_BACKUP_DIR . 'web.config';
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

            @file_put_contents($web_config_path, $web_config_contents);
        }

        // Compter les sauvegardes
        $backups = glob(BJLG_BACKUP_DIR . '*.zip*') ?: [];
        $count = count($backups);
        $size = 0;
        foreach ($backups as $backup) {
            $size += filesize($backup);
        }
        
        return [
            'status' => 'success', 
            'message' => sprintf(
                'Le dossier est accessible en écriture. %d sauvegarde(s), %s utilisés.',
                $count,
                size_format($size)
            )
        ];
    }

    /**
     * Vérifie l'espace disque disponible sur le serveur.
     * @return array ['status' => 'success'|'warning', 'message' => string]
     */
    private function check_disk_space() {
        $free_space = @disk_free_space(ABSPATH);
        if ($free_space === false) {
            return [
                'status' => 'warning', 
                'message' => "Impossible de déterminer l'espace disque disponible."
            ];
        }
        
        $total_space = @disk_total_space(ABSPATH);
        if ($total_space === false || $total_space <= 0) {
            return [
                'status' => 'warning',
                'message' => "Impossible de déterminer l'espace disque total. L'utilisation du disque n'a pas pu être calculée.",
            ];
        }

        $used_space = $total_space - $free_space;
        $usage_percent = round(($used_space / $total_space) * 100, 2);
        
        $free_space_gb = $free_space / (1024*1024*1024);
        $message = sprintf(
            "Espace libre : %s / %s (%s%% utilisé)",
            size_format($free_space),
            size_format($total_space),
            $usage_percent
        );

        if ($free_space_gb < 1) { // Moins de 1 Go
            return [
                'status' => 'error', 
                'message' => $message . " - CRITIQUE : Espace insuffisant !"
            ];
        } elseif ($free_space_gb < 5) { // Moins de 5 Go
            return [
                'status' => 'warning', 
                'message' => $message . " - Attention, l'espace disque est faible."
            ];
        }
        
        return ['status' => 'success', 'message' => $message];
    }
    
    /**
     * Vérifie la limite de mémoire PHP.
     * @return array ['status' => 'success'|'warning', 'message' => string]
     */
    private function check_php_memory_limit() {
        $memory_limit_str = ini_get('memory_limit');
        $memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit_str);
        
        // Obtenir l'utilisation actuelle
        $current_usage = memory_get_usage(true);
        $peak_usage = memory_get_peak_usage(true);
        
        $message = sprintf(
            "Limite : %s | Utilisation actuelle : %s | Pic : %s",
            $memory_limit_str,
            size_format($current_usage),
            size_format($peak_usage)
        );

        if ($memory_limit_bytes < 128 * 1024 * 1024) { // Moins de 128M
            return [
                'status' => 'warning', 
                'message' => $message . " - Recommandé : 256M ou plus pour les gros sites."
            ];
        } elseif ($memory_limit_bytes < 256 * 1024 * 1024) { // Moins de 256M
            return [
                'status' => 'info', 
                'message' => $message . " - Acceptable, mais 256M serait préférable."
            ];
        }
        
        return ['status' => 'success', 'message' => $message];
    }

    /**
     * Vérifie le temps d'exécution maximum des scripts PHP.
     * @return array ['status' => 'success'|'warning', 'message' => string]
     */
    private function check_php_execution_time() {
        $max_execution_time = ini_get('max_execution_time');
        $message = "Temps d'exécution max : {$max_execution_time}s";

        if ($max_execution_time != 0 && $max_execution_time < 300) {
            return [
                'status' => 'warning', 
                'message' => $message . " - Recommandé : 300s ou 0 (illimité). Un temps court peut interrompre les sauvegardes."
            ];
        }
        return ['status' => 'success', 'message' => $message];
    }
    
    /**
     * Vérifie la version de PHP
     */
    private function check_php_version() {
        $current_version = PHP_VERSION;
        $minimum_version = '7.4.0';
        $recommended_version = '8.0.0';
        
        if (version_compare($current_version, $minimum_version, '<')) {
            return [
                'status' => 'error',
                'message' => "PHP $current_version - Mise à jour REQUISE ! Minimum : PHP 7.4"
            ];
        } elseif (version_compare($current_version, $recommended_version, '<')) {
            return [
                'status' => 'warning',
                'message' => "PHP $current_version - Fonctionnel, mais PHP 8.0+ recommandé pour de meilleures performances."
            ];
        }
        
        return [
            'status' => 'success',
            'message' => "PHP $current_version - Version optimale."
        ];
    }
    
    /**
     * Vérifie la version de WordPress
     */
    private function check_wordpress_version() {
        global $wp_version;
        $minimum_version = '5.0';
        
        if (version_compare($wp_version, $minimum_version, '<')) {
            return [
                'status' => 'error',
                'message' => "WordPress $wp_version - Mise à jour requise ! Minimum : WordPress 5.0"
            ];
        }
        
        // Vérifier si c'est la dernière version
        $update_data = wp_get_update_data();
        if ($update_data['counts']['total'] > 0) {
            return [
                'status' => 'warning',
                'message' => "WordPress $wp_version - Une mise à jour est disponible."
            ];
        }
        
        return [
            'status' => 'success',
            'message' => "WordPress $wp_version - À jour."
        ];
    }
    
    /**
     * Vérifie la taille de la base de données
     */
    private function check_database_size() {
        global $wpdb;
        
        $size_query = $wpdb->get_row(
            "SELECT 
                SUM(data_length + index_length) as size,
                COUNT(*) as tables
             FROM information_schema.TABLES 
             WHERE table_schema = '" . DB_NAME . "'"
        );
        
        if (!$size_query) {
            return [
                'status' => 'warning',
                'message' => 'Impossible de déterminer la taille de la base de données.'
            ];
        }
        
        $size = $size_query->size;
        $tables = $size_query->tables;
        $size_formatted = size_format($size);
        
        $message = "$size_formatted ($tables tables)";
        
        if ($size > 1024 * 1024 * 1024) { // Plus de 1GB
            return [
                'status' => 'warning',
                'message' => $message . " - Base de données volumineuse, les sauvegardes peuvent être longues."
            ];
        }
        
        return [
            'status' => 'success',
            'message' => $message
        ];
    }
    
    /**
     * Vérifie l'extension ZIP
     */
    private function check_zip_extension() {
        if (!class_exists(\ZipArchive::class)) {
            return [
                'status' => 'error',
                'message' => 'Extension PHP ZIP manquante ! Requise pour créer les sauvegardes.'
            ];
        }

        // Tester la création d'une archive
        $test_file = BJLG_BACKUP_DIR . 'test_' . uniqid() . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($test_file, \ZipArchive::CREATE) === TRUE) {
            $zip->addFromString('test.txt', 'test');
            $zip->close();
            @unlink($test_file);
            
            return [
                'status' => 'success',
                'message' => 'Extension ZIP installée et fonctionnelle.'
            ];
        }
        
        return [
            'status' => 'warning',
            'message' => 'Extension ZIP présente mais problème de création d\'archive détecté.'
        ];
    }
    
    /**
     * Vérifie le support du chiffrement
     */
    private function check_encryption_support() {
        if (!extension_loaded('openssl')) {
            return [
                'status' => 'warning',
                'message' => 'Extension OpenSSL manquante. Le chiffrement ne sera pas disponible.'
            ];
        }
        
        $ciphers = openssl_get_cipher_methods();
        if (!in_array('aes-256-cbc', $ciphers)) {
            return [
                'status' => 'warning',
                'message' => 'AES-256-CBC non supporté. Chiffrement limité.'
            ];
        }
        
        return [
            'status' => 'success',
            'message' => 'Chiffrement AES-256 disponible et fonctionnel.'
        ];
    }
    
    /**
     * Vérifie la fonction exec()
     */
    private function check_exec_function() {
        if (!function_exists('exec')) {
            return [
                'status' => 'info',
                'message' => 'Fonction exec() non disponible. Multi-threading désactivé.'
            ];
        }
        
        $disabled = explode(',', ini_get('disable_functions'));
        if (in_array('exec', array_map('trim', $disabled))) {
            return [
                'status' => 'info',
                'message' => 'Fonction exec() désactivée. Multi-threading non disponible.'
            ];
        }
        
        // Tester l'exécution
        @exec('echo test', $output, $return_var);
        if ($return_var === 0) {
            return [
                'status' => 'success',
                'message' => 'Fonction exec() disponible. Multi-threading possible.'
            ];
        }
        
        return [
            'status' => 'warning',
            'message' => 'Fonction exec() présente mais non fonctionnelle.'
        ];
    }
    
    /**
     * Exporte le bilan de santé
     */
    public function export_health_report() {
        $checks = $this->get_all_checks();
        $report = "Bilan de Santé - Backup JLG\n";
        $report .= "Date : " . current_time('d/m/Y H:i:s') . "\n";
        $report .= "Site : " . get_site_url() . "\n";
        $report .= str_repeat("=", 50) . "\n\n";
        
        foreach ($checks as $key => $check) {
            $status_icon = '✓';
            if ($check['status'] === 'warning') $status_icon = '⚠';
            if ($check['status'] === 'error') $status_icon = '✗';
            
            $report .= sprintf(
                "%s %s\n   %s\n\n",
                $status_icon,
                ucwords(str_replace('_', ' ', $key)),
                $check['message']
            );
        }
        
        return $report;
    }
}