<?php
namespace BJLG;

use Exception;
use ZipArchive;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-bjlg-backup.php';

/**
 * Gère tout le processus de restauration, y compris la pré-sauvegarde de sécurité.
 */
class BJLG_Restore {

    /**
     * Instance du gestionnaire de sauvegarde.
     *
     * @var BJLG_Backup|null
     */
    private $backup_manager;

    public function __construct($backup_manager = null) {
        if ($backup_manager === null && class_exists(BJLG_Backup::class)) {
            $backup_manager = new BJLG_Backup();
        }

        $this->backup_manager = $backup_manager;

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
            
            if (!($this->backup_manager instanceof BJLG_Backup)) {
                throw new Exception('Gestionnaire de sauvegarde indisponible.');
            }

            $backup_manager = $this->backup_manager;

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
            $backup_manager->dump_database($sql_filepath);
            $zip->addFile($sql_filepath, 'database.sql');

            // Ajout des dossiers
            $upload_dir_info = wp_get_upload_dir();
            $backup_manager->add_folder_to_zip($zip, WP_PLUGIN_DIR, 'wp-content/plugins/', []);
            $backup_manager->add_folder_to_zip($zip, get_theme_root(), 'wp-content/themes/', []);
            $backup_manager->add_folder_to_zip($zip, $upload_dir_info['basedir'], 'wp-content/uploads/', []);

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

        $password = null;
        if (isset($_POST['password'])) {
            $password = sanitize_text_field(wp_unslash($_POST['password']));
            if ($password === '') {
                $password = null;
            }
        }

        try {
            $encrypted_password = $this->encrypt_password_for_transient($password);
        } catch (Exception $exception) {
            if (class_exists(BJLG_Debug::class)) {
                BJLG_Debug::log('Échec du chiffrement du mot de passe de restauration : ' . $exception->getMessage(), 'error');
            }
            wp_send_json_error(['message' => 'Impossible de sécuriser le mot de passe fourni.']);
        }

        // Créer une tâche de restauration
        $task_id = 'bjlg_restore_' . md5(uniqid('restore', true));
        $task_data = [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => 'Initialisation de la restauration...',
            'filename' => $filename,
            'filepath' => $filepath,
            'password_encrypted' => $encrypted_password
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
        $encrypted_password = $task_data['password_encrypted'] ?? null;
        $password = null;

        if (!empty($encrypted_password)) {
            try {
                $password = $this->decrypt_password_from_transient($encrypted_password);
            } catch (Exception $exception) {
                if (class_exists(BJLG_Debug::class)) {
                    BJLG_Debug::log("ERREUR: Échec du déchiffrement du mot de passe pour la tâche {$task_id} : " . $exception->getMessage(), 'error');
                }
            }
        }
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
     * Chiffre un mot de passe avant stockage dans un transient.
     *
     * Utilise AES-256-CBC avec un IV aléatoire et ajoute un HMAC-SHA256 pour
     * garantir l'intégrité, le tout basé sur une clé dérivée des salts
     * WordPress. Cela évite de conserver le secret en clair tout en restant
     * déchiffrable par le site qui a créé la tâche de restauration.
     *
     * @param string|null $password
     * @return string|null
     */
    private function encrypt_password_for_transient($password) {
        if ($password === null) {
            return null;
        }

        $key = $this->get_password_encryption_key();
        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        if ($iv_length === false) {
            throw new RuntimeException('Méthode de chiffrement indisponible.');
        }

        $iv = random_bytes($iv_length);
        $ciphertext = openssl_encrypt($password, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Impossible de chiffrer le mot de passe.');
        }

        $hmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);

        return base64_encode($iv . $hmac . $ciphertext);
    }

    /**
     * Déchiffre un mot de passe stocké dans un transient.
     *
     * L'algorithme applique AES-256-CBC avec un vecteur d'initialisation aléatoire,
     * complété par un HMAC-SHA256 pour vérifier l'intégrité. La clé symétrique est
     * dérivée des différentes clés et salts WordPress disponibles, ce qui évite de
     * conserver le secret en clair tout en restant déchiffrable par cette instance.
     *
     * @param string $encrypted_password
     * @return string|null
     */
    private function decrypt_password_from_transient($encrypted_password) {
        if ($encrypted_password === null || $encrypted_password === '') {
            return null;
        }

        $key = $this->get_password_encryption_key();
        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        if ($iv_length === false) {
            throw new RuntimeException('Méthode de déchiffrement indisponible.');
        }

        $decoded = base64_decode($encrypted_password, true);
        if ($decoded === false || strlen($decoded) <= ($iv_length + 32)) {
            throw new RuntimeException('Données chiffrées invalides.');
        }

        $iv = substr($decoded, 0, $iv_length);
        $hmac = substr($decoded, $iv_length, 32);
        $ciphertext = substr($decoded, $iv_length + 32);

        $calculated_hmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        if (!hash_equals($hmac, $calculated_hmac)) {
            throw new RuntimeException('Vérification d\'intégrité du mot de passe échouée.');
        }

        $password = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($password === false) {
            throw new RuntimeException('Impossible de déchiffrer le mot de passe.');
        }

        return $password;
    }

    /**
     * Dérive une clé symétrique à partir des salts WordPress.
     *
     * @return string
     */
    private function get_password_encryption_key() {
        $salts = [];

        if (function_exists('wp_salt')) {
            $salts[] = wp_salt('auth');
            $salts[] = wp_salt('secure_auth');
            $salts[] = wp_salt('logged_in');
            $salts[] = wp_salt('nonce');
        }

        $constants = [
            'AUTH_KEY',
            'SECURE_AUTH_KEY',
            'LOGGED_IN_KEY',
            'NONCE_KEY',
            'AUTH_SALT',
            'SECURE_AUTH_SALT',
            'LOGGED_IN_SALT',
            'NONCE_SALT'
        ];

        foreach ($constants as $constant) {
            if (defined($constant)) {
                $salts[] = constant($constant);
            }
        }

        $key_material = implode('|', array_filter($salts));

        if ($key_material === '') {
            $key_material = 'bjlg-transient-password-fallback';
        }

        return hash('sha256', $key_material, true);
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