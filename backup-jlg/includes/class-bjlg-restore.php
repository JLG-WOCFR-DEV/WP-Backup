<?php
if (!defined('ABSPATH')) exit;

/**
 * Gère tout le processus de restauration, y compris la pré-sauvegarde de sécurité.
 */
class BJLG_Restore {

    public function __construct() {
        add_action('wp_ajax_bjlg_create_pre_restore_backup', [$this, 'handle_create_pre_restore_backup']);
        add_action('wp_ajax_bjlg_run_restore', [$this, 'handle_run_restore']);
        add_action('wp_ajax_bjlg_upload_restore_file', [$this, 'handle_upload_restore_file']);
        add_action('wp_ajax_bjlg_check_restore_progress', [$this, 'handle_check_restore_progress']);
    }

    /**
     * Crée une sauvegarde de sécurité complète avant de lancer une restauration.
     */
    public function handle_create_pre_restore_backup() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        try {
            BJLG_Debug::log("Lancement de la sauvegarde de sécurité pré-restauration.");
            
            $backup_manager = new BJLG_Backup();
            $reflection = new ReflectionClass($backup_manager);

            $dump_database_method = $reflection->getMethod('dump_database');
            $dump_database_method->setAccessible(true);
            
            $add_folder_to_zip_method = $reflection->getMethod('add_folder_to_zip');
            $add_folder_to_zip_method->setAccessible(true);

            $backup_filename = 'pre-restore-backup-' . date('Y-m-d-H-i-s') . '.zip';
            $backup_filepath = BJLG_BACKUP_DIR . $backup_filename;
            $sql_filepath = BJLG_BACKUP_DIR . 'database_temp_prerestore.sql';

            $zip = new ZipArchive();
            if ($zip->open($backup_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception("Impossible de créer l'archive de pré-restauration.");
            }

            $components = ['db', 'plugins', 'themes', 'uploads'];
            $manifest = [
                'type' => 'pre-restore-backup',
                'contains' => $components,
                'version' => BJLG_VERSION,
                'created_at' => current_time('mysql'),
                'reason' => 'Sauvegarde automatique avant restauration'
            ];
            $zip->addFromString('backup-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
            
            // Export de la base de données
            $dump_database_method->invoke($backup_manager, $sql_filepath);
            $zip->addFile($sql_filepath, 'database.sql');

            // Ajout des dossiers
            $upload_dir_info = wp_get_upload_dir();
            $add_folder_to_zip_method->invoke($backup_manager, $zip, WP_PLUGIN_DIR, 'wp-content/plugins/', []);
            $add_folder_to_zip_method->invoke($backup_manager, $zip, get_theme_root(), 'wp-content/themes/', []);
            $add_folder_to_zip_method->invoke($backup_manager, $zip, $upload_dir_info['basedir'], 'wp-content/uploads/', []);

            $zip->close();
            
            if (file_exists($sql_filepath)) {
                unlink($sql_filepath);
            }

            BJLG_History::log('pre_restore_backup', 'success', 'Fichier : ' . $backup_filename);
            BJLG_Debug::log("Sauvegarde de sécurité terminée : " . $backup_filename);
            
            wp_send_json_success([
                'message' => 'Sauvegarde de sécurité créée avec succès.',
                'backup_file' => $backup_filename
            ]);

        } catch (Exception $e) {
            BJLG_History::log('pre_restore_backup', 'failure', 'Erreur : ' . $e->getMessage());
            wp_send_json_error(['message' => 'La sauvegarde de sécurité a échoué : ' . $e->getMessage()]);
        }
    }

    /**
     * Gère l'upload d'un fichier de restauration
     */
    public function handle_upload_restore_file() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        if (empty($_FILES['restore_file'])) {
            wp_send_json_error(['message' => 'Aucun fichier téléversé.']);
        }

        $uploaded_file = $_FILES['restore_file'];
        
        // Vérifications de sécurité
        $allowed_types = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
        if (!in_array($uploaded_file['type'], $allowed_types)) {
            wp_send_json_error(['message' => 'Type de fichier non autorisé.']);
        }

        $file_extension = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_extension, ['zip', 'enc'])) {
            wp_send_json_error(['message' => 'Extension de fichier non autorisée.']);
        }

        // Déplacer le fichier uploadé
        $destination = BJLG_BACKUP_DIR . 'restore_' . uniqid() . '_' . basename($uploaded_file['name']);
        
        if (!move_uploaded_file($uploaded_file['tmp_name'], $destination)) {
            wp_send_json_error(['message' => 'Impossible de déplacer le fichier téléversé.']);
        }

        wp_send_json_success([
            'message' => 'Fichier téléversé avec succès.',
            'filename' => basename($destination),
            'filepath' => $destination
        ]);
    }

    /**
     * Exécute la restauration granulaire à partir d'un fichier de sauvegarde.
     */
    public function handle_run_restore() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        if (empty($_POST['filename'])) {
            wp_send_json_error(['message' => 'Nom de fichier manquant.']);
        }
        
        $filename = basename(sanitize_file_name($_POST['filename']));
        $filepath = BJLG_BACKUP_DIR . $filename;
        $password = isset($_POST['password']) ? $_POST['password'] : null;
        
        // Créer une tâche de restauration
        $task_id = 'bjlg_restore_' . md5(uniqid('restore', true));
        $task_data = [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => 'Initialisation de la restauration...',
            'filename' => $filename,
            'filepath' => $filepath,
            'password' => $password
        ];
        
        set_transient($task_id, $task_data, HOUR_IN_SECONDS);
        
        // Planifier l'exécution
        wp_schedule_single_event(time(), 'bjlg_run_restore_task', ['task_id' => $task_id]);
        
        wp_send_json_success(['task_id' => $task_id]);
    }

    /**
     * Vérifie la progression de la restauration
     */
    public function handle_check_restore_progress() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        $task_id = sanitize_key($_POST['task_id']);
        $progress_data = get_transient($task_id);

        if ($progress_data === false) {
            wp_send_json_error(['message' => 'Tâche non trouvée.']);
        }

        wp_send_json_success($progress_data);
    }

    /**
     * Exécute la tâche de restauration en arrière-plan
     */
    public function run_restore_task($task_id) {
        $task_data = get_transient($task_id);
        if (!$task_data) {
            BJLG_Debug::log("ERREUR: Tâche de restauration $task_id introuvable.");
            return;
        }

        $filepath = $task_data['filepath'];
        $password = $task_data['password'] ?? null;
        $temp_extract_dir = BJLG_BACKUP_DIR . 'temp_restore_' . uniqid();

        try {
            set_time_limit(0);
            @ini_set('memory_limit', '256M');
            
            BJLG_Debug::log("Début de la restauration pour le fichier : " . basename($filepath));

            // Mise à jour : Vérification
            set_transient($task_id, [
                'progress' => 10,
                'status' => 'running',
                'status_text' => 'Vérification du fichier de sauvegarde...'
            ], HOUR_IN_SECONDS);

            if (!file_exists($filepath)) {
                throw new Exception("Le fichier de sauvegarde n'a pas été trouvé.");
            }

            // Déchiffrement si nécessaire
            if (substr($filepath, -4) === '.enc') {
                set_transient($task_id, [
                    'progress' => 20,
                    'status' => 'running',
                    'status_text' => 'Déchiffrement de l\'archive...'
                ], HOUR_IN_SECONDS);
                
                $encryption = new BJLG_Encryption();
                $filepath = $encryption->decrypt_backup_file($filepath, $password);
            }

            // Création du répertoire temporaire
            if (!mkdir($temp_extract_dir, 0755, true)) {
                throw new Exception("Impossible de créer le répertoire temporaire.");
            }

            // Ouverture de l'archive
            $zip = new ZipArchive;
            if ($zip->open($filepath) !== TRUE) {
                throw new Exception("Impossible d'ouvrir l'archive. Fichier corrompu ?");
            }

            // Lecture du manifeste
            $manifest_json = $zip->getFromName('backup-manifest.json');
            if ($manifest_json === false) {
                throw new Exception("Manifeste de sauvegarde manquant.");
            }
            
            $manifest = json_decode($manifest_json, true);
            $components_to_restore = $manifest['contains'] ?? [];

            BJLG_Debug::log("Composants à restaurer : " . implode(', ', $components_to_restore));

            // Restauration de la base de données
            if (in_array('db', $components_to_restore)) {
                set_transient($task_id, [
                    'progress' => 30,
                    'status' => 'running',
                    'status_text' => 'Restauration de la base de données...'
                ], HOUR_IN_SECONDS);
                
                if ($zip->locateName('database.sql') !== false) {
                    $zip->extractTo($temp_extract_dir, 'database.sql');
                    $sql_filepath = $temp_extract_dir . '/database.sql';
                    
                    BJLG_Debug::log("Import de la base de données...");
                    $this->import_database($sql_filepath);
                    
                    set_transient($task_id, [
                        'progress' => 50,
                        'status' => 'running',
                        'status_text' => 'Base de données restaurée.'
                    ], HOUR_IN_SECONDS);
                }
            }

            // Restauration des fichiers
            $folders_to_restore = [];
            
            if (in_array('plugins', $components_to_restore)) {
                $folders_to_restore['plugins'] = WP_PLUGIN_DIR;
            }
            if (in_array('themes', $components_to_restore)) {
                $folders_to_restore['themes'] = get_theme_root();
            }
            if (in_array('uploads', $components_to_restore)) {
                $upload_dir = wp_get_upload_dir();
                $folders_to_restore['uploads'] = $upload_dir['basedir'];
            }

            $progress = 50;
            $progress_step = 40 / count($folders_to_restore);
            
            foreach ($folders_to_restore as $type => $destination) {
                $progress += $progress_step;
                
                set_transient($task_id, [
                    'progress' => round($progress),
                    'status' => 'running',
                    'status_text' => "Restauration des {$type}..."
                ], HOUR_IN_SECONDS);
                
                $source_folder = "wp-content/{$type}";
                
                // Extraire vers le dossier temporaire
                $files_to_extract = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $file = $zip->getNameIndex($i);
                    if (strpos($file, $source_folder) === 0) {
                        $files_to_extract[] = $file;
                    }
                }
                
                if (!empty($files_to_extract)) {
                    $zip->extractTo($temp_extract_dir, $files_to_extract);
                    
                    // Copier vers la destination finale
                    $this->recursive_copy(
                        $temp_extract_dir . '/' . $source_folder,
                        $destination
                    );
                    
                    BJLG_Debug::log("Restauration de {$type} terminée.");
                }
            }

            $zip->close();
            
            // Nettoyage
            set_transient($task_id, [
                'progress' => 95,
                'status' => 'running',
                'status_text' => 'Nettoyage...'
            ], HOUR_IN_SECONDS);
            
            $this->recursive_delete($temp_extract_dir);
            
            // Vider les caches
            $this->clear_all_caches();
            
            BJLG_History::log('restore_run', 'success', "Fichier : " . basename($filepath));
            
            set_transient($task_id, [
                'progress' => 100,
                'status' => 'complete',
                'status_text' => 'Restauration terminée avec succès !'
            ], HOUR_IN_SECONDS);

        } catch (Exception $e) {
            BJLG_History::log('restore_run', 'failure', "Erreur : " . $e->getMessage());
            
            // Nettoyage en cas d'erreur
            if (is_dir($temp_extract_dir)) {
                $this->recursive_delete($temp_extract_dir);
            }
            
            set_transient($task_id, [
                'progress' => 100,
                'status' => 'error',
                'status_text' => 'Erreur : ' . $e->getMessage()
            ], HOUR_IN_SECONDS);
        }
    }

    /**
     * Importe un fichier SQL dans la base de données
     */
    private function import_database($sql_filepath) {
        global $wpdb;
        
        if (!file_exists($sql_filepath)) {
            throw new Exception("Fichier SQL introuvable.");
        }
        
        $handle = @fopen($sql_filepath, 'r');
        if (!$handle) {
            throw new Exception("Impossible de lire le fichier SQL.");
        }

        // Désactiver temporairement les contraintes
        $wpdb->query('SET foreign_key_checks = 0');
        $wpdb->query('SET autocommit = 0');
        $wpdb->query('START TRANSACTION');

        $query = '';
        $queries_executed = 0;
        $errors = [];
        
        while (($line = fgets($handle)) !== false) {
            // Ignorer les commentaires et les lignes vides
            if (substr($line, 0, 2) == '--' || trim($line) == '') {
                continue;
            }
            
            $query .= $line;
            
            // Exécuter la requête quand on atteint un point-virgule à la fin d'une ligne
            if (substr(trim($line), -1, 1) == ';') {
                $result = $wpdb->query($query);
                
                if ($result === false) {
                    $error_msg = "Erreur SQL : " . $wpdb->last_error;
                    BJLG_Debug::log($error_msg);
                    $errors[] = $error_msg;
                    
                    // Continuer malgré les erreurs pour les tables optionnelles
                    if (strpos($wpdb->last_error, 'already exists') === false) {
                        // Ce n'est pas une erreur de table existante
                        if (count($errors) > 10) {
                            // Trop d'erreurs, abandonner
                            throw new Exception("Trop d'erreurs lors de l'import SQL.");
                        }
                    }
                } else {
                    $queries_executed++;
                }
                
                $query = ''; // Réinitialiser pour la prochaine requête
            }
        }
        
        fclose($handle);
        
        // Valider la transaction
        $wpdb->query('COMMIT');
        $wpdb->query('SET autocommit = 1');
        $wpdb->query('SET foreign_key_checks = 1');
        
        BJLG_Debug::log("Import SQL terminé : $queries_executed requêtes exécutées.");
        
        if (!empty($errors)) {
            BJLG_Debug::log("Erreurs rencontrées : " . implode(", ", array_slice($errors, 0, 5)));
        }
    }

    /**
     * Copie récursive de fichiers
     */
    private function recursive_copy($source, $destination) {
        if (!is_dir($source)) {
            return false;
        }
        
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        
        $dir = opendir($source);
        
        while (($file = readdir($dir)) !== false) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            
            $src_path = $source . '/' . $file;
            $dst_path = $destination . '/' . $file;
            
            if (is_dir($src_path)) {
                $this->recursive_copy($src_path, $dst_path);
            } else {
                copy($src_path, $dst_path);
            }
        }
        
        closedir($dir);
        return true;
    }

    /**
     * Suppression récursive de dossier
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
     * Vide tous les caches connus
     */
    private function clear_all_caches() {
        // Cache WordPress
        wp_cache_flush();
        
        // Cache des options
        wp_cache_delete('alloptions', 'options');
        
        // Cache des transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'");
        
        // Cache des objets
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Plugins de cache populaires
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain(); // WP Rocket
        }
        
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all(); // W3 Total Cache
        }
        
        if (function_exists('wp_super_cache_clear_cache')) {
            wp_super_cache_clear_cache(); // WP Super Cache
        }
        
        BJLG_Debug::log("Tous les caches ont été vidés.");
    }
}