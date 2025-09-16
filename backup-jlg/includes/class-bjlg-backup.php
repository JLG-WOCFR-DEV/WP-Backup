<?php
if (!defined('ABSPATH')) exit;

/**
 * Gère le processus complet de création de sauvegardes
 */
class BJLG_Backup {

    private $performance_optimizer;
    private $encryption_handler;
    
    public function __construct() {
        // Hooks AJAX
        add_action('wp_ajax_bjlg_start_backup_task', [$this, 'handle_start_backup_task']);
        add_action('wp_ajax_bjlg_check_backup_progress', [$this, 'handle_check_backup_progress']);
        
        // Hook pour l'exécution en arrière-plan
        add_action('bjlg_run_backup_task', [$this, 'run_backup_task']);
        
        // Initialiser les handlers
        add_action('init', [$this, 'init_handlers']);
    }
    
    /**
     * Initialise les handlers
     */
    public function init_handlers() {
        if (class_exists('BJLG_Performance')) {
            $this->performance_optimizer = new BJLG_Performance();
        }
        if (class_exists('BJLG_Encryption')) {
            $this->encryption_handler = new BJLG_Encryption();
        }
    }

    /**
     * Gère la requête AJAX pour démarrer une tâche de sauvegarde
     */
    public function handle_start_backup_task() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        $components = isset($_POST['components']) ? array_map('sanitize_text_field', $_POST['components']) : [];
        $encrypt = isset($_POST['encrypt']) && $_POST['encrypt'] === 'true';
        $incremental = isset($_POST['incremental']) && $_POST['incremental'] === 'true';
        
        if (empty($components)) {
            wp_send_json_error(['message' => 'Aucun composant sélectionné.']);
        }

        // Créer un ID unique pour cette tâche
        $task_id = 'bjlg_backup_' . md5(uniqid('manual', true));
        
        // Initialiser les données de la tâche
        $task_data = [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => 'Initialisation de la sauvegarde...',
            'components' => $components,
            'encrypt' => $encrypt,
            'incremental' => $incremental,
            'source' => 'manual',
            'start_time' => time()
        ];
        
        // Sauvegarder temporairement
        set_transient($task_id, $task_data, HOUR_IN_SECONDS);
        
        BJLG_Debug::log("Nouvelle tâche de sauvegarde créée : $task_id");
        BJLG_History::log('backup_started', 'info', 'Composants : ' . implode(', ', $components));
        
        // Planifier l'exécution immédiate en arrière-plan
        wp_schedule_single_event(time(), 'bjlg_run_backup_task', ['task_id' => $task_id]);
        
        wp_send_json_success([
            'task_id' => $task_id,
            'message' => 'Sauvegarde lancée en arrière-plan.'
        ]);
    }

    /**
     * Vérifie la progression d'une tâche
     */
    public function handle_check_backup_progress() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        $task_id = sanitize_key($_POST['task_id']);
        $progress_data = get_transient($task_id);

        if ($progress_data === false) {
            wp_send_json_error(['message' => 'Tâche non trouvée ou expirée.']);
        }

        wp_send_json_success($progress_data);
    }

    /**
     * Exécute la tâche de sauvegarde en arrière-plan
     */
    public function run_backup_task($task_id) {
        $task_data = get_transient($task_id);
        if (!$task_data) {
            BJLG_Debug::log("ERREUR: Tâche $task_id introuvable.");
            return;
        }

        try {
            // Configuration initiale
            set_time_limit(0);
            @ini_set('memory_limit', '512M');
            
            BJLG_Debug::log("Début de la sauvegarde - Task ID: $task_id");
            
            // Mise à jour : Début
            $this->update_task_progress($task_id, 10, 'running', 'Préparation de la sauvegarde...');
            
            // Déterminer le type de sauvegarde
            $backup_type = $task_data['incremental'] ? 'incremental' : 'full';
            
            // Si incrémentale, vérifier qu'une sauvegarde complète existe
            if ($task_data['incremental']) {
                $incremental_handler = new BJLG_Incremental();
                if (!$incremental_handler->can_do_incremental()) {
                    BJLG_Debug::log("Pas de sauvegarde complète trouvée, bascule en mode complet.");
                    $backup_type = 'full';
                    $task_data['incremental'] = false;
                }
            }
            
            // Créer le fichier de sauvegarde
            $backup_filename = $this->generate_backup_filename($backup_type, $task_data['components']);
            $backup_filepath = BJLG_BACKUP_DIR . $backup_filename;
            
            BJLG_Debug::log("Création du fichier : $backup_filename");
            
            // Créer l'archive ZIP
            $zip = new ZipArchive();
            if ($zip->open($backup_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception("Impossible de créer l'archive ZIP.");
            }
            
            // Ajouter le manifeste
            $manifest = $this->create_manifest($task_data['components'], $backup_type);
            $zip->addFromString('backup-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
            
            $progress = 20;
            $components_count = count($task_data['components']);
            $progress_per_component = 70 / $components_count;
            
            // Traiter chaque composant
            foreach ($task_data['components'] as $component) {
                $this->update_task_progress($task_id, $progress, 'running', "Sauvegarde : $component");
                
                switch ($component) {
                    case 'db':
                        $this->backup_database($zip, $task_data['incremental']);
                        break;
                    case 'plugins':
                        $this->backup_directory($zip, WP_PLUGIN_DIR, 'wp-content/plugins/', $task_data['incremental']);
                        break;
                    case 'themes':
                        $this->backup_directory($zip, get_theme_root(), 'wp-content/themes/', $task_data['incremental']);
                        break;
                    case 'uploads':
                        $upload_dir = wp_get_upload_dir();
                        $this->backup_directory($zip, $upload_dir['basedir'], 'wp-content/uploads/', $task_data['incremental']);
                        break;
                }
                
                $progress += $progress_per_component;
                $this->update_task_progress($task_id, round($progress), 'running', "Composant $component terminé");
            }
            
            // Fermer l'archive
            $zip->close();
            
            // Chiffrement si demandé
            if ($task_data['encrypt'] && $this->encryption_handler) {
                $this->update_task_progress($task_id, 95, 'running', 'Chiffrement de la sauvegarde...');
                $encrypted_file = $this->encryption_handler->encrypt_backup_file($backup_filepath);
                if ($encrypted_file !== $backup_filepath) {
                    $backup_filepath = $encrypted_file;
                    $backup_filename = basename($encrypted_file);
                }
            }
            
            // Calculer les statistiques
            $file_size = filesize($backup_filepath);
            $duration = time() - $task_data['start_time'];
            
            // Mettre à jour le manifeste incrémental si nécessaire
            if ($task_data['incremental']) {
                $incremental_handler = new BJLG_Incremental();
                $incremental_handler->update_manifest($backup_filepath, $task_data['components']);
            }
            
            // Enregistrer le succès
            BJLG_History::log('backup_created', 'success', sprintf(
                'Fichier : %s | Taille : %s | Durée : %ds',
                $backup_filename,
                size_format($file_size),
                $duration
            ));
            
            // Notification de succès
            do_action('bjlg_backup_complete', $backup_filename, [
                'size' => $file_size,
                'components' => $task_data['components'],
                'encrypted' => $task_data['encrypt'],
                'incremental' => $task_data['incremental'],
                'duration' => $duration
            ]);
            
            // Mise à jour finale
            $this->update_task_progress($task_id, 100, 'complete', 'Sauvegarde terminée avec succès !');
            
            BJLG_Debug::log("Sauvegarde terminée : $backup_filename (" . size_format($file_size) . ")");
            
        } catch (Exception $e) {
            BJLG_Debug::log("ERREUR dans la sauvegarde : " . $e->getMessage());
            BJLG_History::log('backup_created', 'failure', 'Erreur : ' . $e->getMessage());
            
            // Notification d'échec
            do_action('bjlg_backup_failed', $e->getMessage(), [
                'task_id' => $task_id,
                'components' => $task_data['components'] ?? []
            ]);
            
            $this->update_task_progress($task_id, 100, 'error', 'Erreur : ' . $e->getMessage());
            
            // Nettoyer les fichiers partiels
            if (isset($backup_filepath) && file_exists($backup_filepath)) {
                @unlink($backup_filepath);
            }
        }
    }

    /**
     * Génère un nom de fichier pour la sauvegarde
     */
    private function generate_backup_filename($type, $components) {
        $date = date('Y-m-d-H-i-s');
        $prefix = ($type === 'incremental') ? 'incremental' : 'backup';
        
        // Ajouter un identifiant des composants si ce n'est pas tout
        $all_components = ['db', 'plugins', 'themes', 'uploads'];
        if (count(array_diff($all_components, $components)) > 0) {
            $components_str = implode('-', $components);
            return "{$prefix}-{$components_str}-{$date}.zip";
        }
        
        return "{$prefix}-full-{$date}.zip";
    }

    /**
     * Crée le manifeste de la sauvegarde
     */
    private function create_manifest($components, $type) {
        global $wp_version;
        
        return [
            'version' => BJLG_VERSION,
            'wp_version' => $wp_version,
            'php_version' => PHP_VERSION,
            'type' => $type,
            'contains' => $components,
            'created_at' => current_time('c'),
            'site_url' => get_site_url(),
            'site_name' => get_bloginfo('name'),
            'db_prefix' => $GLOBALS['wpdb']->prefix,
            'theme_active' => get_option('stylesheet'),
            'plugins_active' => get_option('active_plugins'),
            'multisite' => is_multisite(),
            'file_count' => 0,
            'checksum' => ''
        ];
    }

    /**
     * Sauvegarde la base de données en écrivant le dump SQL dans un fichier temporaire
     * pour limiter la consommation mémoire avant de l'ajouter à l'archive.
     *
     * @throws Exception Si le fichier temporaire ne peut pas être créé ou ajouté à l'archive.
     */
    private function backup_database(&$zip, $incremental = false) {
        global $wpdb;

        BJLG_Debug::log("Export de la base de données...");

        $sql_filename = 'database.sql';
        $temp_file = function_exists('wp_tempnam') ? wp_tempnam('bjlg-db-export.sql') : tempnam(sys_get_temp_dir(), 'bjlg-db-');

        if (!$temp_file) {
            throw new Exception("Impossible de créer le fichier temporaire pour l'export SQL.");
        }

        $handle = fopen($temp_file, 'w');

        if (!$handle) {
            @unlink($temp_file);
            throw new Exception("Impossible d'ouvrir le fichier temporaire pour l'export SQL.");
        }

        try {
            // Header SQL
            fwrite($handle, "-- Backup JLG Database Export\n");
            fwrite($handle, "-- Version: " . BJLG_VERSION . "\n");
            fwrite($handle, "-- Date: " . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, "-- Site: " . get_site_url() . "\n\n");
            fwrite($handle, "SET NAMES utf8mb4;\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            // Obtenir toutes les tables
            $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);

            foreach ($tables as $table_array) {
                $table = $table_array[0];

                // Pour l'incrémental, vérifier si la table a changé
                if ($incremental) {
                    $incremental_handler = new BJLG_Incremental();
                    if (!$incremental_handler->table_has_changed($table)) {
                        BJLG_Debug::log("Table $table ignorée (pas de changement)");
                        continue;
                    }
                }

                // Structure de la table
                $create_table = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
                fwrite($handle, "\n-- Table: {$table}\n");
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, $create_table[1] . ";\n\n");

                // Données de la table
                $row_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");

                if ($row_count > 0) {
                    fwrite($handle, "-- Data for table: {$table}\n");

                    // Traiter par lots pour économiser la mémoire
                    $batch_size = 1000;
                    for ($offset = 0; $offset < $row_count; $offset += $batch_size) {
                        $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT {$offset}, {$batch_size}", ARRAY_A);

                        if ($rows) {
                            fwrite($handle, $this->create_insert_statement($table, $rows));
                        }
                    }
                }
            }

            fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }

        // Ajouter au ZIP via un fichier temporaire puis le supprimer
        if (!$zip->addFile($temp_file, $sql_filename)) {
            @unlink($temp_file);
            throw new Exception("Impossible d'ajouter l'export SQL à l'archive.");
        }

        @unlink($temp_file);

        BJLG_Debug::log("Export de la base de données terminé.");
    }

    /**
     * Crée les instructions INSERT
     */
    private function create_insert_statement($table, $rows) {
        if (empty($rows)) return '';
        
        $columns = array_keys($rows[0]);
        $columns_str = '`' . implode('`, `', $columns) . '`';
        
        $values = [];
        foreach ($rows as $row) {
            $row_values = [];
            foreach ($row as $value) {
                $row_values[] = $this->format_sql_value($value);
            }
            $values[] = '(' . implode(', ', $row_values) . ')';
        }

        return "INSERT INTO `{$table}` ({$columns_str}) VALUES\n" . implode(",\n", $values) . ";\n\n";
    }

    /**
     * Prépare une valeur pour une instruction SQL INSERT.
     *
     * @param mixed $value
     * @return string
     */
    private function format_sql_value($value) {
        if (is_null($value)) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            if ($this->is_binary_string($value)) {
                return '0x' . bin2hex($value);
            }

            return "'" . esc_sql($value) . "'";
        }

        $serialized = function_exists('maybe_serialize') ? maybe_serialize($value) : serialize($value);

        return "'" . esc_sql($serialized) . "'";
    }

    /**
     * Détermine si une chaîne contient des données binaires.
     *
     * @param mixed $value
     * @return bool
     */
    private function is_binary_string($value) {
        if (!is_string($value)) {
            return false;
        }

        if (strpos($value, "\0") !== false) {
            return true;
        }

        return @preg_match('//u', $value) !== 1;
    }

    /**
     * Sauvegarde un répertoire
     */
    private function backup_directory(&$zip, $source_dir, $zip_path, $incremental = false) {
        if (!is_dir($source_dir)) {
            BJLG_Debug::log("Répertoire introuvable : $source_dir");
            return;
        }
        
        BJLG_Debug::log("Sauvegarde du répertoire : " . basename($source_dir));
        
        $exclude_patterns = [
            '*/cache/*',
            '*/node_modules/*',
            '*/.git/*',
            '*.log',
            '*/backup-*',
            '*/bjlg-backups/*'
        ];
        
        // Pour l'incrémental, obtenir la liste des fichiers modifiés
        $modified_files = [];
        if ($incremental) {
            $incremental_handler = new BJLG_Incremental();
            $modified_files = $incremental_handler->get_modified_files($source_dir);
            
            if (empty($modified_files)) {
                BJLG_Debug::log("Aucun fichier modifié dans : " . basename($source_dir));
                return;
            }
        }
        
        $this->add_folder_to_zip($zip, $source_dir, $zip_path, $exclude_patterns, $incremental, $modified_files);
    }

    /**
     * Ajoute récursivement un dossier au ZIP
     */
    public function add_folder_to_zip(&$zip, $folder, $zip_path, $exclude = [], $incremental = false, $modified_files = []) {
        $handle = @opendir($folder);
        if ($handle === false) {
            BJLG_Debug::log("Impossible d'ouvrir le répertoire : $folder");
            return;
        }

        while (($file = readdir($handle)) !== false) {
            if ($file == '.' || $file == '..') continue;
            
            $file_path = $folder . '/' . $file;
            $relative_path = $zip_path . $file;
            
            // Vérifier les exclusions
            $skip = false;
            foreach ($exclude as $pattern) {
                if (fnmatch($pattern, $file_path)) {
                    $skip = true;
                    break;
                }
            }
            
            if ($skip) continue;
            
            if (is_dir($file_path)) {
                // Récursion pour les sous-dossiers
                $this->add_folder_to_zip($zip, $file_path, $relative_path . '/', $exclude, $incremental, $modified_files);
            } else {
                // Pour l'incrémental, vérifier si le fichier est dans la liste des modifiés
                if ($incremental && !empty($modified_files)) {
                    if (!in_array($file_path, $modified_files)) {
                        continue;
                    }
                }
                
                // Ajouter le fichier
                if (filesize($file_path) < 50 * 1024 * 1024) { // Moins de 50MB
                    $zip->addFile($file_path, $relative_path);
                } else {
                    // Pour les gros fichiers, utiliser le streaming
                    $zip->addFile($file_path, $relative_path);
                    $zip->setCompressionName($relative_path, ZipArchive::CM_STORE);
                }
            }
        }
        
        closedir($handle);
    }

    /**
     * Met à jour la progression de la tâche
     */
    private function update_task_progress($task_id, $progress, $status, $status_text) {
        $task_data = get_transient($task_id);
        if ($task_data) {
            $task_data['progress'] = $progress;
            $task_data['status'] = $status;
            $task_data['status_text'] = $status_text;
            set_transient($task_id, $task_data, HOUR_IN_SECONDS);
        }
    }

    /**
     * Exporte la base de données (méthode publique pour la sauvegarde pré-restauration)
     */
    public function dump_database($filepath) {
        global $wpdb;
        
        $handle = fopen($filepath, 'w');
        if (!$handle) {
            throw new Exception("Impossible de créer le fichier SQL");
        }
        
        // Header
        fwrite($handle, "-- Backup JLG Database Dump\n");
        fwrite($handle, "-- Date: " . date('Y-m-d H:i:s') . "\n\n");
        fwrite($handle, "SET NAMES utf8mb4;\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");
        
        // Tables
        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
        
        foreach ($tables as $table_array) {
            $table = $table_array[0];
            
            // Structure
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($handle, $create[1] . ";\n\n");
            
            // Données
            $row_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            
            if ($row_count > 0) {
                $batch_size = 1000;
                
                for ($offset = 0; $offset < $row_count; $offset += $batch_size) {
                    $rows = $wpdb->get_results(
                        "SELECT * FROM `{$table}` LIMIT {$offset}, {$batch_size}",
                        ARRAY_A
                    );
                    
                    if ($rows) {
                        $insert = $this->create_insert_statement($table, $rows);
                        fwrite($handle, $insert);
                    }
                }
            }
        }
        
        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }
}