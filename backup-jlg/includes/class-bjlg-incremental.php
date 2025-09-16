<?php
namespace BJLG;

/**
 * Classe pour gérer les sauvegardes incrémentales
 * Fichier : includes/class-bjlg-incremental.php
 */

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

if (!defined('ABSPATH')) {
    exit;
}

class BJLG_Incremental {
    
    private $manifest_file;
    private $last_backup_data;
    private $file_hash_cache = [];
    
    public function __construct() {
        $this->manifest_file = BJLG_BACKUP_DIR . '.incremental-manifest.json';
        $this->load_manifest();
        
        // Hooks
        add_filter('bjlg_backup_type', [$this, 'determine_backup_type'], 10, 2);
        add_action('bjlg_backup_complete', [$this, 'update_manifest'], 10, 2);
        
        // AJAX
        add_action('wp_ajax_bjlg_get_incremental_info', [$this, 'ajax_get_info']);
        add_action('wp_ajax_bjlg_reset_incremental', [$this, 'ajax_reset']);
        add_action('wp_ajax_bjlg_analyze_changes', [$this, 'ajax_analyze_changes']);
    }
    
    /**
     * Charge le manifeste des dernières sauvegardes
     */
    private function load_manifest() {
        if (file_exists($this->manifest_file)) {
            $content = file_get_contents($this->manifest_file);
            $this->last_backup_data = json_decode($content, true);
            
            // Validation de la structure
            if (!$this->validate_manifest()) {
                $this->reset_manifest();
            }
        } else {
            $this->reset_manifest();
        }
    }
    
    /**
     * Valide la structure du manifeste
     */
    private function validate_manifest() {
        if (!is_array($this->last_backup_data)) {
            return false;
        }
        
        $required_keys = ['full_backup', 'incremental_backups', 'file_hashes', 'database_checksums'];
        foreach ($required_keys as $key) {
            if (!isset($this->last_backup_data[$key])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Réinitialise le manifeste
     */
    private function reset_manifest() {
        $this->last_backup_data = [
            'full_backup' => null,
            'incremental_backups' => [],
            'file_hashes' => [],
            'database_checksums' => [],
            'last_scan' => null,
            'version' => '2.0'
        ];
        $this->save_manifest();
    }
    
    /**
     * Sauvegarde le manifeste
     */
    private function save_manifest() {
        $json = json_encode($this->last_backup_data, JSON_PRETTY_PRINT);
        file_put_contents($this->manifest_file, $json);
        
        // Protéger le fichier
        @chmod($this->manifest_file, 0600);
    }
    
    /**
     * Détermine si une sauvegarde incrémentale est possible
     */
    public function can_do_incremental() {
        // Vérifier qu'une sauvegarde complète existe
        if (empty($this->last_backup_data['full_backup'])) {
            BJLG_Debug::log("Pas de sauvegarde complète de référence.");
            return false;
        }
        
        // Vérifier que la sauvegarde complète existe toujours
        $full_backup_file = $this->last_backup_data['full_backup']['file'];
        if (!file_exists(BJLG_BACKUP_DIR . $full_backup_file)) {
            BJLG_Debug::log("La sauvegarde complète de référence n'existe plus.");
            $this->reset_manifest();
            return false;
        }
        
        // Vérifier l'âge de la dernière sauvegarde complète
        $full_backup_time = $this->last_backup_data['full_backup']['timestamp'];
        $days_old = (time() - $full_backup_time) / DAY_IN_SECONDS;
        
        if ($days_old > 30) {
            BJLG_Debug::log("La sauvegarde complète a plus de 30 jours, une nouvelle est recommandée.");
            return false;
        }
        
        // Vérifier le nombre de sauvegardes incrémentales
        if (count($this->last_backup_data['incremental_backups']) >= 10) {
            BJLG_Debug::log("Limite de 10 sauvegardes incrémentales atteinte.");
            return false;
        }
        
        return true;
    }
    
    /**
     * Obtient les fichiers modifiés depuis la dernière sauvegarde
     */
    public function get_modified_files($directory) {
        $modified_files = [];
        $last_scan_time = $this->last_backup_data['last_scan'] ?? 0;
        
        // Scanner récursivement le répertoire
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            
            $filepath = $file->getRealPath();
            $relative_path = str_replace(ABSPATH, '', $filepath);
            
            // Vérifier si le fichier a été modifié
            $mtime = $file->getMTime();
            $current_hash = $this->get_file_hash($filepath);
            $stored_hash = $this->last_backup_data['file_hashes'][$relative_path] ?? null;
            
            if ($mtime > $last_scan_time || $current_hash !== $stored_hash) {
                $modified_files[] = $filepath;
                
                // Mettre à jour le hash en cache
                $this->file_hash_cache[$relative_path] = $current_hash;
            }
        }
        
        BJLG_Debug::log("Fichiers modifiés trouvés : " . count($modified_files));
        
        return $modified_files;
    }
    
    /**
     * Calcule le hash d'un fichier
     */
    private function get_file_hash($filepath) {
        // Pour les petits fichiers, utiliser md5 du contenu complet
        if (filesize($filepath) < 1024 * 1024) { // Moins de 1MB
            return md5_file($filepath);
        }
        
        // Pour les gros fichiers, hash partiel pour économiser les ressources
        $handle = fopen($filepath, 'rb');
        if (!$handle) return null;
        
        $hash_context = hash_init('md5');
        
        // Hash du début (1MB)
        $data = fread($handle, 1024 * 1024);
        hash_update($hash_context, $data);
        
        // Hash du milieu (1MB)
        fseek($handle, filesize($filepath) / 2);
        $data = fread($handle, 1024 * 1024);
        hash_update($hash_context, $data);
        
        // Hash de la fin (1MB)
        fseek($handle, -1024 * 1024, SEEK_END);
        $data = fread($handle, 1024 * 1024);
        hash_update($hash_context, $data);
        
        // Ajouter la taille et la date de modification
        hash_update($hash_context, filesize($filepath) . filemtime($filepath));
        
        fclose($handle);
        
        return hash_final($hash_context);
    }
    
    /**
     * Vérifie si une table a changé
     */
    public function table_has_changed($table_name) {
        global $wpdb;
        
        // Calculer le checksum de la table
        $checksum_result = $wpdb->get_row("CHECKSUM TABLE `{$table_name}`", ARRAY_A);
        
        if (!$checksum_result || !isset($checksum_result['Checksum'])) {
            // Si pas de checksum disponible, considérer comme modifié
            return true;
        }
        
        $current_checksum = $checksum_result['Checksum'];
        $stored_checksum = $this->last_backup_data['database_checksums'][$table_name] ?? null;
        
        if ($current_checksum !== $stored_checksum) {
            BJLG_Debug::log("Table $table_name modifiée (checksum: $current_checksum)");
            return true;
        }
        
        return false;
    }
    
    /**
     * Met à jour le manifeste après une sauvegarde
     */
    public function update_manifest($backup_file, $components) {
        $backup_info = [
            'file' => basename($backup_file),
            'timestamp' => time(),
            'components' => $components,
            'size' => filesize($backup_file)
        ];
        
        // Déterminer le type de sauvegarde
        if (strpos(basename($backup_file), 'incremental') !== false) {
            // Sauvegarde incrémentale
            $this->last_backup_data['incremental_backups'][] = $backup_info;
            
            // Limiter à 10 sauvegardes incrémentales
            if (count($this->last_backup_data['incremental_backups']) > 10) {
                array_shift($this->last_backup_data['incremental_backups']);
            }
        } else {
            // Sauvegarde complète - réinitialiser
            $this->last_backup_data['full_backup'] = $backup_info;
            $this->last_backup_data['incremental_backups'] = [];
            
            // Mettre à jour tous les checksums
            $this->update_all_checksums();
        }
        
        // Mettre à jour les hashes des fichiers depuis le cache
        foreach ($this->file_hash_cache as $path => $hash) {
            $this->last_backup_data['file_hashes'][$path] = $hash;
        }
        
        // Mettre à jour le timestamp du dernier scan
        $this->last_backup_data['last_scan'] = time();
        
        // Sauvegarder
        $this->save_manifest();
        
        BJLG_Debug::log("Manifeste incrémental mis à jour");
    }
    
    /**
     * Met à jour tous les checksums de la base de données
     */
    private function update_all_checksums() {
        global $wpdb;
        
        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
        $this->last_backup_data['database_checksums'] = [];
        
        foreach ($tables as $table_array) {
            $table = $table_array[0];
            $checksum_result = $wpdb->get_row("CHECKSUM TABLE `{$table}`", ARRAY_A);
            
            if ($checksum_result && isset($checksum_result['Checksum'])) {
                $this->last_backup_data['database_checksums'][$table] = $checksum_result['Checksum'];
            }
        }
        
        BJLG_Debug::log("Checksums de " . count($tables) . " tables mis à jour");
    }
    
    /**
     * AJAX : Obtient les informations sur l'état incrémental
     */
    public function ajax_get_info() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }
        
        $info = [
            'can_do_incremental' => $this->can_do_incremental(),
            'full_backup' => null,
            'incremental_count' => 0,
            'total_size' => 0,
            'last_backup' => null,
            'next_full_recommended' => null,
            'space_saved' => 0
        ];
        
        if ($this->last_backup_data['full_backup']) {
            $info['full_backup'] = [
                'file' => $this->last_backup_data['full_backup']['file'],
                'date' => date('d/m/Y H:i', $this->last_backup_data['full_backup']['timestamp']),
                'size' => size_format($this->last_backup_data['full_backup']['size']),
                'age_days' => round((time() - $this->last_backup_data['full_backup']['timestamp']) / DAY_IN_SECONDS)
            ];
            
            $info['incremental_count'] = count($this->last_backup_data['incremental_backups']);
            
            // Calculer la taille totale
            $total_size = $this->last_backup_data['full_backup']['size'];
            foreach ($this->last_backup_data['incremental_backups'] as $inc) {
                $total_size += $inc['size'];
            }
            $info['total_size'] = size_format($total_size);
            
            // Dernière sauvegarde
            if (!empty($this->last_backup_data['incremental_backups'])) {
                $last = end($this->last_backup_data['incremental_backups']);
                $info['last_backup'] = date('d/m/Y H:i', $last['timestamp']);
            } else {
                $info['last_backup'] = $info['full_backup']['date'];
            }
            
            // Recommandation pour la prochaine complète
            $days_until_next = 30 - $info['full_backup']['age_days'];
            if ($days_until_next > 0) {
                $info['next_full_recommended'] = "Dans $days_until_next jour(s)";
            } else {
                $info['next_full_recommended'] = "Maintenant (plus de 30 jours)";
            }
            
            // Espace économisé (estimation)
            $estimated_full_size = $this->last_backup_data['full_backup']['size'] * ($info['incremental_count'] + 1);
            $info['space_saved'] = size_format($estimated_full_size - $total_size);
        }
        
        wp_send_json_success($info);
    }
    
    /**
     * AJAX : Réinitialise l'état incrémental
     */
    public function ajax_reset() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        $this->reset_manifest();
        
        BJLG_History::log('incremental_reset', 'info', 'Manifeste incrémental réinitialisé');
        
        wp_send_json_success(['message' => 'État incrémental réinitialisé. La prochaine sauvegarde sera complète.']);
    }
    
    /**
     * AJAX : Analyse les changements depuis la dernière sauvegarde
     */
    public function ajax_analyze_changes() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        $changes = [
            'files' => [
                'modified' => 0,
                'added' => 0,
                'deleted' => 0,
                'list' => []
            ],
            'database' => [
                'tables_modified' => 0,
                'list' => []
            ],
            'estimated_size' => 0,
            'recommendation' => ''
        ];
        
        // Analyser les fichiers
        $directories = [
            'plugins' => WP_PLUGIN_DIR,
            'themes' => get_theme_root(),
            'uploads' => wp_get_upload_dir()['basedir']
        ];
        
        foreach ($directories as $type => $dir) {
            $modified = $this->get_modified_files($dir);
            $changes['files']['modified'] += count($modified);
            
            // Limiter la liste à 20 fichiers
            if (count($changes['files']['list']) < 20) {
                foreach ($modified as $file) {
                    $changes['files']['list'][] = [
                        'path' => str_replace(ABSPATH, '', $file),
                        'type' => $type,
                        'size' => size_format(filesize($file)),
                        'modified' => date('d/m/Y H:i', filemtime($file))
                    ];
                    
                    if (count($changes['files']['list']) >= 20) break;
                }
            }
            
            // Estimer la taille
            foreach ($modified as $file) {
                $changes['estimated_size'] += filesize($file);
            }
        }
        
        // Analyser la base de données
        global $wpdb;
        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
        
        foreach ($tables as $table_array) {
            $table = $table_array[0];
            if ($this->table_has_changed($table)) {
                $changes['database']['tables_modified']++;
                
                // Obtenir la taille de la table
                $size_result = $wpdb->get_row(
                    "SELECT 
                        (data_length + index_length) as size 
                     FROM information_schema.TABLES 
                     WHERE table_schema = '" . DB_NAME . "' 
                     AND table_name = '{$table}'",
                    ARRAY_A
                );
                
                $changes['database']['list'][] = [
                    'table' => $table,
                    'size' => size_format($size_result['size'] ?? 0)
                ];
                
                $changes['estimated_size'] += $size_result['size'] ?? 0;
            }
        }
        
        // Recommandation
        if ($changes['files']['modified'] == 0 && $changes['database']['tables_modified'] == 0) {
            $changes['recommendation'] = "Aucun changement détecté. Sauvegarde non nécessaire.";
        } elseif ($changes['estimated_size'] < 10 * 1024 * 1024) { // Moins de 10MB
            $changes['recommendation'] = "Changements mineurs détectés. Sauvegarde incrémentale recommandée.";
        } elseif ($changes['estimated_size'] < 100 * 1024 * 1024) { // Moins de 100MB
            $changes['recommendation'] = "Changements modérés détectés. Sauvegarde incrémentale appropriée.";
        } else {
            $changes['recommendation'] = "Changements importants détectés. Considérez une sauvegarde complète.";
        }
        
        $changes['estimated_size'] = size_format($changes['estimated_size']);
        
        wp_send_json_success($changes);
    }
    
    /**
     * Obtient la chaîne de restauration complète
     */
    public function get_restore_chain() {
        $chain = [];
        
        // Ajouter la sauvegarde complète
        if ($this->last_backup_data['full_backup']) {
            $chain[] = $this->last_backup_data['full_backup'];
        }
        
        // Ajouter toutes les sauvegardes incrémentales dans l'ordre
        foreach ($this->last_backup_data['incremental_backups'] as $inc) {
            $chain[] = $inc;
        }
        
        return $chain;
    }
    
    /**
     * Vérifie l'intégrité de la chaîne de restauration
     */
    public function verify_restore_chain() {
        $chain = $this->get_restore_chain();
        $missing = [];
        
        foreach ($chain as $backup) {
            $filepath = BJLG_BACKUP_DIR . $backup['file'];
            if (!file_exists($filepath)) {
                $missing[] = $backup['file'];
            }
        }
        
        return [
            'valid' => empty($missing),
            'missing' => $missing,
            'chain_length' => count($chain)
        ];
    }
}