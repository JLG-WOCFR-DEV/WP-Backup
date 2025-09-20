<?php
namespace BJLG;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gère la tâche cron quotidienne de nettoyage des sauvegardes et des logs.
 */
class BJLG_Cleanup {

    const CRON_HOOK = 'bjlg_daily_cleanup_hook';

    /** @var self|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        return new self();
    }

    public function __construct() {
        if (self::$instance instanceof self) {
            return;
        }

        self::$instance = $this;

        // L'action qui sera réellement exécutée par le cron
        add_action(self::CRON_HOOK, [$this, 'run_cleanup']);

        // Action manuelle de nettoyage
        add_action('wp_ajax_bjlg_manual_cleanup', [$this, 'handle_manual_cleanup']);

        // Planifier la tâche si elle n'est pas déjà planifiée
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // Planifie l'événement pour qu'il s'exécute tous les jours à 3h du matin
            $timestamp = strtotime('tomorrow 3:00am');
            wp_schedule_event($timestamp, 'daily', self::CRON_HOOK);
            BJLG_Debug::log("Tâche de nettoyage planifiée pour s'exécuter quotidiennement à 3h.");
        }
    }

    /**
     * Méthode principale exécutée par le cron.
     */
    public function run_cleanup() {
        BJLG_Debug::log("--- DÉBUT DE LA TÂCHE DE NETTOYAGE QUOTIDIEN ---");
        BJLG_History::log('cleanup_task_started', 'info', 'La tâche de nettoyage automatique a démarré.');

        try {
            // Rotation des logs
            $this->rotate_log_file();
            
            // Nettoyage des sauvegardes
            $deleted_backups = $this->cleanup_backups();
            
            // Nettoyage des fichiers temporaires
            $deleted_temp = $this->cleanup_temp_files();
            
            // Nettoyage de l'historique ancien
            $deleted_history = $this->cleanup_old_history();
            
            // Nettoyage des transients expirés
            $this->cleanup_transients();
            
            $summary = sprintf(
                '%d sauvegarde(s), %d fichier(s) temporaire(s), %d entrée(s) d\'historique supprimés',
                $deleted_backups,
                $deleted_temp,
                $deleted_history
            );
            
            BJLG_History::log('cleanup_task_finished', 'success', $summary);
            BJLG_Debug::log("--- FIN DE LA TÂCHE DE NETTOYAGE : $summary ---");
            
            // Notification si configuré
            do_action('bjlg_cleanup_complete', [
                'backups_deleted' => $deleted_backups,
                'temp_files_deleted' => $deleted_temp,
                'history_entries_deleted' => $deleted_history
            ]);

        } catch (Exception $e) {
            BJLG_History::log('cleanup_task_failed', 'failure', 'Erreur : ' . $e->getMessage());
            BJLG_Debug::log("--- ERREUR DANS LA TÂCHE DE NETTOYAGE : " . $e->getMessage() . " ---");
        }
    }
    
    /**
     * Gère le nettoyage manuel déclenché par l'utilisateur
     */
    public function handle_manual_cleanup() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        $type = sanitize_text_field($_POST['type'] ?? 'all');
        $results = [];
        
        try {
            switch ($type) {
                case 'backups':
                    $results['backups'] = $this->cleanup_backups();
                    break;
                case 'temp':
                    $results['temp'] = $this->cleanup_temp_files();
                    break;
                case 'logs':
                    $results['logs'] = $this->rotate_log_file(true);
                    break;
                case 'history':
                    $results['history'] = $this->cleanup_old_history();
                    break;
                case 'all':
                default:
                    $results['backups'] = $this->cleanup_backups();
                    $results['temp'] = $this->cleanup_temp_files();
                    $results['logs'] = $this->rotate_log_file(true);
                    $results['history'] = $this->cleanup_old_history();
                    break;
            }
            
            wp_send_json_success([
                'message' => 'Nettoyage effectué avec succès.',
                'results' => $results
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Gère la rotation du fichier de log s'il est trop volumineux.
     */
    private function rotate_log_file($force = false) {
        $rotated_count = 0;
        
        // Log du plugin
        $plugin_log = WP_CONTENT_DIR . '/bjlg-debug.log';
        $max_size = 10 * 1024 * 1024; // 10 Mo

        if (file_exists($plugin_log) && (filesize($plugin_log) > $max_size || $force)) {
            $old_log_file = $plugin_log . '.old';
            
            // Supprimer l'ancien log archivé s'il existe
            if (file_exists($old_log_file)) {
                unlink($old_log_file);
            }
            
            // Archiver le log actuel
            if (rename($plugin_log, $old_log_file)) {
                BJLG_Debug::log("Rotation du fichier de log effectuée. L'ancien log est dans bjlg-debug.log.old");
                $rotated_count++;
            } else {
                BJLG_Debug::log("ERREUR : La rotation du fichier de log a échoué.");
            }
        }
        
        // Log WordPress si nécessaire
        $wp_log = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($wp_log) && (filesize($wp_log) > $max_size * 2 || $force)) {
            $wp_old_log = $wp_log . '.old';
            
            if (file_exists($wp_old_log)) {
                unlink($wp_old_log);
            }
            
            if (rename($wp_log, $wp_old_log)) {
                $rotated_count++;
            }
        }
        
        return $rotated_count;
    }
    
    /**
     * Applique les règles de rétention pour supprimer les vieilles sauvegardes.
     * @return int Le nombre de fichiers supprimés.
     */
    private function cleanup_backups() {
        $settings = get_option('bjlg_cleanup_settings', ['by_number' => 3, 'by_age' => 0]);
        $retain_by_number = intval($settings['by_number']);
        $retain_by_age_days = intval($settings['by_age']);

        if ($retain_by_number === 0 && $retain_by_age_days === 0) {
            BJLG_Debug::log("Nettoyage : Aucune règle de rétention active.");
            return 0;
        }

        $backups = glob(BJLG_BACKUP_DIR . '*.zip*');
        if (empty($backups)) {
            BJLG_Debug::log("Nettoyage : Aucun fichier de sauvegarde à vérifier.");
            return 0;
        }

        // Séparer les sauvegardes pré-restauration des sauvegardes normales
        $pre_restore_backups = [];
        $normal_backups = [];
        
        foreach ($backups as $backup) {
            if (strpos(basename($backup), 'pre-restore-backup') !== false) {
                $pre_restore_backups[] = $backup;
            } else {
                $normal_backups[] = $backup;
            }
        }

        $files_to_delete = [];

        // Règle 1 : Nettoyage par ancienneté
        if ($retain_by_age_days > 0) {
            $age_limit_seconds = $retain_by_age_days * DAY_IN_SECONDS;
            
            foreach ($normal_backups as $filepath) {
                if ((time() - filemtime($filepath)) > $age_limit_seconds) {
                    $files_to_delete[] = $filepath;
                    BJLG_Debug::log("Nettoyage par âge : Le fichier '" . basename($filepath) . "' est marqué pour suppression.");
                }
            }
        }
        
        // Règle 2 : Nettoyage par nombre (seulement pour les sauvegardes normales)
        if ($retain_by_number > 0 && count($normal_backups) > $retain_by_number) {
            // Trier les fichiers du plus récent au plus ancien
            usort($normal_backups, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            // Obtenir la liste des fichiers à supprimer (tous sauf les X plus récents)
            $old_files = array_slice($normal_backups, $retain_by_number);
            foreach ($old_files as $filepath) {
                $files_to_delete[] = $filepath;
                BJLG_Debug::log("Nettoyage par nombre : Le fichier '" . basename($filepath) . "' est marqué pour suppression.");
            }
        }
        
        // Toujours supprimer les sauvegardes pré-restauration de plus de 7 jours
        foreach ($pre_restore_backups as $filepath) {
            if ((time() - filemtime($filepath)) > (7 * DAY_IN_SECONDS)) {
                $files_to_delete[] = $filepath;
                BJLG_Debug::log("Nettoyage : Suppression de l'ancienne sauvegarde pré-restauration '" . basename($filepath) . "'");
            }
        }

        // Supprimer les fichiers en s'assurant qu'il n'y a pas de doublons
        $deleted_count = 0;
        $unique_files_to_delete = array_unique($files_to_delete);

        foreach ($unique_files_to_delete as $filepath) {
            if (file_exists($filepath)) {
                if (unlink($filepath)) {
                    $deleted_count++;
                    BJLG_Debug::log("Fichier supprimé : " . basename($filepath));
                } else {
                    BJLG_Debug::log("ERREUR de nettoyage : Impossible de supprimer le fichier " . basename($filepath));
                }
            }
        }
        
        return $deleted_count;
    }
    
    /**
     * Nettoie les fichiers temporaires
     */
    private function cleanup_temp_files() {
        $deleted_count = 0;
        
        // Patterns de fichiers temporaires à nettoyer
        $temp_patterns = [
            BJLG_BACKUP_DIR . 'temp_*',
            BJLG_BACKUP_DIR . 'worker_*',
            BJLG_BACKUP_DIR . 'database_temp_*',
            BJLG_BACKUP_DIR . 'benchmark_*',
            BJLG_BACKUP_DIR . '*.tmp'
        ];
        
        foreach ($temp_patterns as $pattern) {
            $temp_files = glob($pattern);
            
            foreach ($temp_files as $file) {
                // Supprimer les fichiers de plus d'une heure
                if ((time() - filemtime($file)) > HOUR_IN_SECONDS) {
                    if (is_file($file)) {
                        if (unlink($file)) {
                            $deleted_count++;
                            BJLG_Debug::log("Fichier temporaire supprimé : " . basename($file));
                        }
                    } elseif (is_dir($file)) {
                        $this->recursive_delete($file);
                        $deleted_count++;
                        BJLG_Debug::log("Dossier temporaire supprimé : " . basename($file));
                    }
                }
            }
        }
        
        return $deleted_count;
    }
    
    /**
     * Nettoie l'historique ancien
     */
    private function cleanup_old_history() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bjlg_history';
        
        // Garder seulement les 30 derniers jours
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE timestamp < %s",
                $cutoff_date
            )
        );
        
        if ($deleted > 0) {
            BJLG_Debug::log("$deleted entrée(s) d'historique supprimée(s) (plus de 30 jours).");
        }
        
        // Limiter le nombre total d'entrées à 1000
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        
        if ($count > 1000) {
            $to_keep = 1000;
            $wpdb->query(
                "DELETE FROM $table_name 
                 WHERE id NOT IN (
                     SELECT id FROM (
                         SELECT id FROM $table_name 
                         ORDER BY timestamp DESC 
                         LIMIT $to_keep
                     ) AS t
                 )"
            );
            
            $additional_deleted = $count - 1000;
            BJLG_Debug::log("$additional_deleted entrée(s) d'historique supprimée(s) (limite de 1000 dépassée).");
            $deleted += $additional_deleted;
        }
        
        return $deleted;
    }
    
    /**
     * Nettoie les transients expirés
     */
    private function cleanup_transients() {
        global $wpdb;
        
        // Supprimer les transients expirés
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_timeout_bjlg_%' 
             AND option_value < UNIX_TIMESTAMP()"
        );
        
        if ($deleted > 0) {
            // Supprimer aussi les valeurs correspondantes
            $wpdb->query(
                "DELETE FROM {$wpdb->options} 
                 WHERE option_name LIKE '_transient_bjlg_%' 
                 AND option_name NOT IN (
                     SELECT REPLACE(option_name, '_transient_timeout_', '_transient_') 
                     FROM (
                         SELECT option_name 
                         FROM {$wpdb->options} 
                         WHERE option_name LIKE '_transient_timeout_bjlg_%'
                     ) AS t
                 )"
            );
            
            BJLG_Debug::log("$deleted transient(s) expiré(s) supprimé(s).");
        }
        
        return $deleted;
    }
    
    /**
     * Suppression récursive d'un dossier
     */
    private function recursive_delete($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursive_delete($path) : unlink($path);
        }
        
        rmdir($dir);
    }
    
    /**
     * Obtient des statistiques sur l'espace utilisé
     */
    public function get_storage_stats() {
        return self::get_storage_stats_snapshot();
    }

    public static function get_storage_stats_snapshot() {
        return self::calculate_storage_stats();
    }

    private static function calculate_storage_stats() {
        $disk_free = @disk_free_space(BJLG_BACKUP_DIR);
        $disk_total = @disk_total_space(BJLG_BACKUP_DIR);

        $stats = [
            'total_backups' => 0,
            'total_size' => 0,
            'oldest_backup' => null,
            'newest_backup' => null,
            'average_size' => 0,
            'disk_free' => $disk_free !== false ? (float) $disk_free : null,
            'disk_total' => $disk_total !== false ? (float) $disk_total : null,
            'disk_space_error' => $disk_free === false || $disk_total === false
        ];

        $backups = glob(BJLG_BACKUP_DIR . '*.zip*');

        if (!empty($backups)) {
            $stats['total_backups'] = count($backups);

            $sizes = [];
            $dates = [];

            foreach ($backups as $backup) {
                $size = filesize($backup);
                $date = filemtime($backup);

                $sizes[] = $size;
                $dates[] = $date;
                $stats['total_size'] += $size;
            }

            $stats['average_size'] = $stats['total_size'] / $stats['total_backups'];
            $stats['oldest_backup'] = min($dates);
            $stats['newest_backup'] = max($dates);
        }

        return $stats;
    }
}