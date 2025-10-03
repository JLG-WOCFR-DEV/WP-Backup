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
        $policies = BJLG_Settings::get_cleanup_policies();

        if (empty($policies)) {
            BJLG_Debug::log("Nettoyage : aucune politique de rétention trouvée.");
            return 0;
        }

        $backups = glob(BJLG_BACKUP_DIR . '*.zip*');
        if (empty($backups)) {
            BJLG_Debug::log("Nettoyage : Aucun fichier de sauvegarde à vérifier.");
            return 0;
        }

        $records = [];
        foreach ($backups as $backup) {
            if (!is_file($backup)) {
                continue;
            }

            $records[] = $this->build_backup_metadata($backup);
        }

        if (empty($records)) {
            BJLG_Debug::log("Nettoyage : Aucun enregistrement de sauvegarde valide trouvé.");
            return 0;
        }

        $buckets = [];
        foreach ($policies as $policy) {
            $policy_id = isset($policy['id']) ? (string) $policy['id'] : uniqid('policy_', true);
            $buckets[$policy_id] = [
                'policy' => $policy,
                'matches' => [],
            ];
        }

        $fallback_policy_id = null;
        foreach ($policies as $policy) {
            if (isset($policy['scope']) && $policy['scope'] === 'global') {
                $fallback_policy_id = $policy['id'];
                break;
            }
        }
        if ($fallback_policy_id === null) {
            $first_policy = reset($policies);
            $fallback_policy_id = isset($first_policy['id']) ? $first_policy['id'] : 'policy_1';
        }

        foreach ($records as $record) {
            $matched_policy_id = null;

            foreach ($policies as $policy) {
                if ($this->backup_matches_policy($record, $policy)) {
                    $matched_policy_id = $policy['id'];
                    break;
                }
            }

            if ($matched_policy_id === null) {
                $matched_policy_id = $fallback_policy_id;
            }

            if (!isset($buckets[$matched_policy_id])) {
                $buckets[$matched_policy_id] = [
                    'policy' => [
                        'id' => $matched_policy_id,
                        'scope' => 'global',
                        'value' => '*',
                        'label' => '',
                        'retain_number' => 0,
                        'retain_age' => 0,
                    ],
                    'matches' => [],
                ];
            }

            $buckets[$matched_policy_id]['matches'][] = $record;
        }

        $now = time();
        $files_to_delete = [];

        foreach ($buckets as $bucket) {
            $policy = $bucket['policy'];
            $matches = $bucket['matches'];

            if (empty($matches)) {
                continue;
            }

            $policy_label = $this->describe_policy($policy);

            $retain_age_days = isset($policy['retain_age']) ? intval($policy['retain_age']) : 0;
            if ($retain_age_days > 0) {
                $age_limit = $now - ($retain_age_days * DAY_IN_SECONDS);

                foreach ($matches as $record) {
                    if ($record['mtime'] > 0 && $record['mtime'] < $age_limit) {
                        $path = $record['path'];
                        if (!isset($files_to_delete[$path])) {
                            $files_to_delete[$path] = sprintf(
                                "Règle '%s' : âge > %d jours",
                                $policy_label,
                                $retain_age_days
                            );
                            BJLG_Debug::log(sprintf(
                                "Nettoyage par âge (%s) : le fichier '%s' est marqué pour suppression.",
                                $policy_label,
                                basename($path)
                            ));
                        }
                    }
                }
            }

            $retain_number = isset($policy['retain_number']) ? intval($policy['retain_number']) : 0;
            $retain_number = max(0, $retain_number);

            $eligible = [];
            foreach ($matches as $record) {
                if (!isset($files_to_delete[$record['path']])) {
                    $eligible[] = $record;
                }
            }

            if ($retain_number === 0) {
                foreach ($eligible as $record) {
                    $path = $record['path'];
                    if (!isset($files_to_delete[$path])) {
                        $files_to_delete[$path] = sprintf(
                            "Règle '%s' : conserver 0 sauvegarde",
                            $policy_label
                        );
                        BJLG_Debug::log(sprintf(
                            "Nettoyage par nombre (%s) : le fichier '%s' est marqué pour suppression.",
                            $policy_label,
                            basename($path)
                        ));
                    }
                }
                continue;
            }

            if (count($eligible) > $retain_number) {
                usort($eligible, static function ($a, $b) {
                    return $b['mtime'] <=> $a['mtime'];
                });

                $to_remove = array_slice($eligible, $retain_number);
                foreach ($to_remove as $record) {
                    $path = $record['path'];
                    if (!isset($files_to_delete[$path])) {
                        $files_to_delete[$path] = sprintf(
                            "Règle '%s' : limite de %d sauvegardes",
                            $policy_label,
                            $retain_number
                        );
                        BJLG_Debug::log(sprintf(
                            "Nettoyage par nombre (%s) : le fichier '%s' est marqué pour suppression.",
                            $policy_label,
                            basename($path)
                        ));
                    }
                }
            }
        }

        // Toujours supprimer les sauvegardes pré-restauration de plus de 7 jours.
        $pre_restore_limit = $now - (7 * DAY_IN_SECONDS);
        foreach ($records as $record) {
            if ($record['type'] === 'pre_restore' && $record['mtime'] > 0 && $record['mtime'] < $pre_restore_limit) {
                $path = $record['path'];
                if (!isset($files_to_delete[$path])) {
                    $files_to_delete[$path] = "Suppression automatique des sauvegardes pré-restauration (> 7 jours)";
                    BJLG_Debug::log(sprintf(
                        "Nettoyage : Suppression de l'ancienne sauvegarde pré-restauration '%s'.",
                        basename($path)
                    ));
                }
            }
        }

        if (empty($files_to_delete)) {
            BJLG_Debug::log("Nettoyage : aucune sauvegarde à supprimer après application des politiques.");
            return 0;
        }

        $deleted_count = 0;
        foreach ($files_to_delete as $filepath => $reason) {
            if (!file_exists($filepath)) {
                continue;
            }

            if (@unlink($filepath)) {
                $deleted_count++;
                BJLG_Debug::log(sprintf(
                    "Fichier supprimé (%s) : %s",
                    $reason,
                    basename($filepath)
                ));
            } else {
                BJLG_Debug::log("ERREUR de nettoyage : Impossible de supprimer le fichier " . basename($filepath));
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
            $temp_files = glob($pattern) ?: [];

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

    private function build_backup_metadata($filepath) {
        $filename = basename($filepath);
        $isEncrypted = substr($filename, -4) === '.enc';
        $baseFilename = $isEncrypted ? substr($filename, 0, -4) : $filename;
        $manifest = $isEncrypted ? null : $this->load_backup_manifest($filepath);

        $destinations = [];
        if (is_array($manifest) && isset($manifest['destinations']) && is_array($manifest['destinations'])) {
            foreach ($manifest['destinations'] as $destination) {
                if (!is_scalar($destination)) {
                    continue;
                }
                $slug = sanitize_key((string) $destination);
                if ($slug !== '') {
                    $destinations[$slug] = true;
                }
            }
        }

        $mtime = @filemtime($filepath);
        if ($mtime === false) {
            $mtime = 0;
        }

        $metadata = [
            'path' => $filepath,
            'filename' => $filename,
            'mtime' => $mtime,
            'type' => $this->determine_backup_type($manifest, $baseFilename),
            'is_encrypted' => $isEncrypted,
            'destinations' => array_keys($destinations),
        ];

        $metadata['statuses'] = $this->collect_backup_statuses($metadata);

        return $metadata;
    }

    private function load_backup_manifest($filepath) {
        if (!class_exists('\\ZipArchive')) {
            return null;
        }

        $zip = new \ZipArchive();
        $opened = @$zip->open($filepath);
        if ($opened !== true) {
            return null;
        }

        $manifestJson = $zip->getFromName('backup-manifest.json');
        $zip->close();

        if ($manifestJson === false) {
            return null;
        }

        $decoded = json_decode($manifestJson, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function determine_backup_type($manifest, $filename) {
        if (is_array($manifest) && isset($manifest['type'])) {
            $type = (string) $manifest['type'];
            if ($type === 'pre-restore-backup') {
                return 'pre_restore';
            }
            if ($type === 'incremental') {
                return 'incremental';
            }
            if ($type === 'full') {
                return 'full';
            }
        }

        $lower = strtolower($filename);
        if (strpos($lower, 'pre-restore') !== false) {
            return 'pre_restore';
        }
        if (strpos($lower, 'incremental') !== false) {
            return 'incremental';
        }
        if (strpos($lower, 'full') !== false) {
            return 'full';
        }

        return 'standard';
    }

    private function collect_backup_statuses(array $metadata) {
        $statuses = [];
        $statuses[] = !empty($metadata['is_encrypted']) ? 'encrypted' : 'unencrypted';
        $statuses[] = !empty($metadata['destinations']) ? 'remote_synced' : 'local_only';

        return array_values(array_unique($statuses));
    }

    private function backup_matches_policy(array $record, array $policy) {
        $scope = isset($policy['scope']) ? (string) $policy['scope'] : 'global';
        $value = isset($policy['value']) ? (string) $policy['value'] : '';

        switch ($scope) {
            case 'destination':
                if ($value === 'local' || $value === '') {
                    return true;
                }
                return in_array($value, $record['destinations'], true);

            case 'type':
                if ($value === 'any' || $value === '') {
                    return true;
                }
                return $record['type'] === $value;

            case 'status':
                if ($value === 'any' || $value === '') {
                    return true;
                }
                return in_array($value, $record['statuses'], true);

            case 'global':
            default:
                return true;
        }
    }

    private function describe_policy(array $policy) {
        $label = isset($policy['label']) ? trim((string) $policy['label']) : '';
        if ($label !== '') {
            return $label;
        }

        $scope = isset($policy['scope']) ? (string) $policy['scope'] : 'global';
        $value = isset($policy['value']) ? (string) $policy['value'] : '';

        switch ($scope) {
            case 'destination':
                return sprintf('destination=%s', $value !== '' ? $value : 'local');
            case 'type':
                return sprintf('type=%s', $value !== '' ? $value : 'any');
            case 'status':
                return sprintf('statut=%s', $value !== '' ? $value : 'any');
            default:
                return 'règle globale';
        }
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