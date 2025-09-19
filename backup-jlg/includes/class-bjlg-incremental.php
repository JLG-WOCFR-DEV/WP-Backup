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

    /**
     * @var string|null Empreinte de la dernière mise à jour pour éviter les doublons.
     */
    private $last_manifest_signature = null;

    /**
     * @var self|null Référence vers la dernière instance initialisée.
     */
    private static $latest_instance = null;

    public function __construct() {
        $this->manifest_file = BJLG_BACKUP_DIR . '.incremental-manifest.json';
        $this->load_manifest();

        self::$latest_instance = $this;

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
        $this->last_manifest_signature = null;
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
        $full_backup_entry = $this->last_backup_data['full_backup'];
        $full_backup_path = '';

        if (is_array($full_backup_entry)) {
            if (!empty($full_backup_entry['path']) && is_string($full_backup_entry['path'])) {
                $full_backup_path = $full_backup_entry['path'];
            } elseif (!empty($full_backup_entry['file']) && is_string($full_backup_entry['file'])) {
                $full_backup_path = BJLG_BACKUP_DIR . ltrim($full_backup_entry['file'], '\\/');
            }
        }

        if ($full_backup_path === '') {
            BJLG_Debug::log("Chemin de la sauvegarde complète introuvable dans le manifeste.");
            return false;
        }

        if (!file_exists($full_backup_path)) {
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
        
        $normalized_abspath = $this->normalize_path(ABSPATH);

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;

            $filepath = $file->getRealPath();
            if ($filepath === false) {
                $filepath = $file->getPathname();
            }

            $normalized_filepath = $this->normalize_path($filepath);
            $relative_path = $normalized_filepath;
            if ($normalized_abspath !== '' && strpos($normalized_filepath, $normalized_abspath) === 0) {
                $relative_path = ltrim(substr($normalized_filepath, strlen($normalized_abspath)), '/');
            }

            // Vérifier si le fichier a été modifié
            $mtime = $file->getMTime();
            $current_hash = $this->get_file_hash($filepath);
            $stored_hash = $this->last_backup_data['file_hashes'][$relative_path] ?? null;

            if ($mtime > $last_scan_time || $current_hash !== $stored_hash) {
                $modified_files[] = $normalized_filepath;

                // Mettre à jour le hash en cache
                $this->file_hash_cache[$relative_path] = $current_hash;
            }
        }
        
        BJLG_Debug::log("Fichiers modifiés trouvés : " . count($modified_files));
        
        return $modified_files;
    }

    /**
     * Normalise un chemin de fichier en utilisant les conventions WordPress si disponibles.
     *
     * @param string $path
     * @return string
     */
    private function normalize_path($path) {
        if (!is_string($path) || $path === '') {
            return '';
        }

        if (function_exists('wp_normalize_path')) {
            return wp_normalize_path($path);
        }

        return str_replace('\\', '/', $path);
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
    public function update_manifest($backup_reference, $details = []) {
        $details = is_array($details) ? $details : [];

        $backup_filepath = $this->resolve_backup_path($backup_reference, $details);
        $backup_filename = '';

        if ($backup_filepath !== '') {
            $backup_filename = basename($backup_filepath);
        } elseif (isset($details['file']) && is_string($details['file'])) {
            $backup_filename = basename($details['file']);
        } elseif (is_string($backup_reference) && $backup_reference !== '') {
            $backup_filename = basename($backup_reference);
        }

        if ($backup_filepath === '' && $backup_filename !== '') {
            $backup_filepath = BJLG_BACKUP_DIR . ltrim($backup_filename, '\\/');
        }

        $components = [];
        if (isset($details['components']) && is_array($details['components'])) {
            $components = array_values(array_map('strval', $details['components']));
        }

        $size = isset($details['size']) ? (int) $details['size'] : 0;
        if ($size <= 0 && $backup_filepath !== '' && file_exists($backup_filepath)) {
            $size = filesize($backup_filepath);
        }

        $timestamp = isset($details['timestamp']) ? (int) $details['timestamp'] : 0;
        if ($timestamp <= 0) {
            $timestamp = time();
        }

        $backup_info = [
            'file' => $backup_filename,
            'path' => $backup_filepath,
            'timestamp' => $timestamp,
            'components' => $components,
            'size' => $size
        ];

        $is_incremental = false;
        if (isset($details['incremental'])) {
            $is_incremental = (bool) $details['incremental'];
        } elseif (strpos($backup_filename, 'incremental') !== false) {
            $is_incremental = true;
        }

        $signature_data = [
            'file' => $backup_info['file'],
            'path' => $backup_info['path'],
            'timestamp' => $backup_info['timestamp'],
            'size' => $backup_info['size'],
            'components' => $backup_info['components'],
            'incremental' => $is_incremental ? '1' : '0'
        ];
        $signature = md5(json_encode($signature_data));

        if ($this->last_manifest_signature === $signature) {
            BJLG_Debug::log("Aucune mise à jour du manifeste nécessaire (données identiques).");
            return;
        }

        $this->last_manifest_signature = $signature;

        if ($is_incremental) {
            $this->last_backup_data['incremental_backups'][] = $backup_info;

            if (count($this->last_backup_data['incremental_backups']) > 10) {
                array_shift($this->last_backup_data['incremental_backups']);
            }
        } else {
            $this->last_backup_data['full_backup'] = $backup_info;
            $this->last_backup_data['incremental_backups'] = [];
            $this->update_all_checksums();
        }

        foreach ($this->file_hash_cache as $path => $hash) {
            $this->last_backup_data['file_hashes'][$path] = $hash;
        }

        $this->last_backup_data['last_scan'] = time();

        $this->save_manifest();

        BJLG_Debug::log("Manifeste incrémental mis à jour");
    }

    /**
     * Récupère la dernière instance initialisée.
     *
     * @return self|null
     */
    public static function get_latest_instance() {
        return self::$latest_instance;
    }

    /**
     * Détermine le chemin absolu du fichier de sauvegarde à partir des détails fournis.
     *
     * @param mixed $backup_reference
     * @param array<string, mixed> $details
     * @return string
     */
    private function resolve_backup_path($backup_reference, array $details) {
        $candidates = [];

        if (isset($details['path']) && is_string($details['path']) && $details['path'] !== '') {
            $candidates[] = $details['path'];
        }

        if (isset($details['file']) && is_string($details['file']) && $details['file'] !== '') {
            $candidates[] = $details['file'];
        }

        if (is_string($backup_reference) && $backup_reference !== '') {
            $candidates[] = $backup_reference;
        }

        $fallback = '';

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            if ($this->is_absolute_path($candidate)) {
                if (file_exists($candidate)) {
                    return $candidate;
                }

                if ($fallback === '') {
                    $fallback = $candidate;
                }
                continue;
            }

            $absolute = BJLG_BACKUP_DIR . ltrim($candidate, '\\/');
            if (file_exists($absolute)) {
                return $absolute;
            }

            if ($fallback === '') {
                $fallback = $absolute;
            }
        }

        return $fallback;
    }

    /**
     * Vérifie si un chemin est absolu.
     */
    private function is_absolute_path($path) {
        if ($path === '') {
            return false;
        }

        return $path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
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