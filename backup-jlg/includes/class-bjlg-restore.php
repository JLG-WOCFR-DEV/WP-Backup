<?php
namespace BJLG;

use Exception;
use RuntimeException;
use Throwable;
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
        add_action('bjlg_run_restore_task', [$this, 'run_restore_task'], 10, 1);
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
            $result = $this->perform_pre_restore_backup();

            wp_send_json_success([
                'message' => 'Sauvegarde de sécurité créée avec succès.',
                'backup_file' => $result['filename']
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => 'La sauvegarde de sécurité a échoué : ' . $e->getMessage()]);
        }
    }

    /**
     * Exécute la logique de sauvegarde préalable à la restauration.
     *
     * @return array{filename: string, filepath: string}
     * @throws Exception
     */
    protected function perform_pre_restore_backup(): array {
        BJLG_Debug::log("Lancement de la sauvegarde de sécurité pré-restauration.");

        if (!($this->backup_manager instanceof BJLG_Backup)) {
            throw new Exception('Gestionnaire de sauvegarde indisponible.');
        }

        $backup_manager = $this->backup_manager;

        $backup_filename = 'pre-restore-backup-' . date('Y-m-d-H-i-s') . '.zip';
        $backup_filepath = BJLG_BACKUP_DIR . $backup_filename;
        $sql_filepath = BJLG_BACKUP_DIR . 'database_temp_prerestore.sql';

        $zip = new ZipArchive();

        $cleanup_sql_file = static function () use ($sql_filepath) {
            if (file_exists($sql_filepath)) {
                unlink($sql_filepath);
            }
        };

        try {
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
            $directories = [
                [
                    'path' => WP_PLUGIN_DIR,
                    'zip' => 'wp-content/plugins/',
                    'label' => 'plugins',
                ],
                [
                    'path' => get_theme_root(),
                    'zip' => 'wp-content/themes/',
                    'label' => 'thèmes',
                ],
                [
                    'path' => $upload_dir_info['basedir'],
                    'zip' => 'wp-content/uploads/',
                    'label' => 'uploads',
                ],
            ];

            $backup_dir_path = BJLG_BACKUP_DIR;
            if (function_exists('wp_normalize_path')) {
                $backup_dir_path = wp_normalize_path($backup_dir_path);
            }
            $backup_dir_path = rtrim($backup_dir_path, '/');

            $normalized_backup_filepath = $backup_filepath;
            if (function_exists('wp_normalize_path')) {
                $normalized_backup_filepath = wp_normalize_path($backup_filepath);
            }

            $exclusions = array_values(array_unique(array_filter([
                '*/bjlg-backups',
                '*/bjlg-backups/*',
                $backup_dir_path,
                $backup_dir_path . '/*',
                $normalized_backup_filepath,
            ])));

            if (class_exists('BJLG_Debug')) {
                BJLG_Debug::log('Exclusions appliquées à la sauvegarde pré-restauration : ' . implode(', ', $exclusions));
            }

            foreach ($directories as $directory) {
                try {
                    $backup_manager->add_folder_to_zip($zip, $directory['path'], $directory['zip'], $exclusions);
                } catch (Exception $exception) {
                    $message = sprintf(
                        "Impossible d'ajouter le répertoire %s (%s) à la sauvegarde de sécurité : %s",
                        $directory['label'],
                        $directory['path'],
                        $exception->getMessage()
                    );

                    if (class_exists('BJLG_Debug')) {
                        BJLG_Debug::log($message);
                    }

                    throw new Exception($message, 0, $exception);
                }
            }

            if (class_exists('BJLG_Debug')) {
                $has_backup_dir = (
                    $zip->locateName('wp-content/uploads/bjlg-backups/', ZipArchive::FL_NOCASE) !== false
                    || $zip->locateName('wp-content/uploads/bjlg-backups', ZipArchive::FL_NOCASE) !== false
                );

                if ($has_backup_dir) {
                    BJLG_Debug::log("Contrôle de sécurité : le dossier bjlg-backups est encore présent dans l'archive pré-restauration.");
                } else {
                    BJLG_Debug::log("Contrôle de sécurité : le dossier bjlg-backups est exclu de l'archive pré-restauration.");
                }
            }

            $zip->close();

            BJLG_History::log('pre_restore_backup', 'success', 'Fichier : ' . $backup_filename);
            BJLG_Debug::log("Sauvegarde de sécurité terminée : " . $backup_filename);

            return [
                'filename' => $backup_filename,
                'filepath' => $backup_filepath,
            ];
        } catch (Exception $exception) {
            BJLG_History::log('pre_restore_backup', 'failure', 'Erreur : ' . $exception->getMessage());

            if (class_exists('BJLG_Debug')) {
                BJLG_Debug::log('Sauvegarde de sécurité pré-restauration échouée : ' . $exception->getMessage());
            }

            throw $exception;
        } finally {
            try {
                if ($zip instanceof ZipArchive) {
                    $zip->close();
                }
            } catch (Throwable $close_exception) {
                if (class_exists('BJLG_Debug')) {
                    BJLG_Debug::log('Impossible de fermer l\'archive de pré-restauration : ' . $close_exception->getMessage());
                }
            }

            $cleanup_sql_file();
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

        $original_filename = isset($uploaded_file['name']) ? $uploaded_file['name'] : '';
        $sanitized_filename = sanitize_file_name(wp_unslash($original_filename));
        if ($sanitized_filename === '') {
            wp_send_json_error(['message' => 'Nom de fichier invalide.']);
        }

        // Vérifications de sécurité
        $allowed_mimes = [
            'zip' => 'application/zip',
            'enc' => 'application/octet-stream',
        ];
        $checked_file = wp_check_filetype_and_ext(
            $uploaded_file['tmp_name'],
            $sanitized_filename,
            $allowed_mimes
        );

        if (empty($checked_file['ext']) || empty($checked_file['type']) || !array_key_exists($checked_file['ext'], $allowed_mimes)) {
            wp_send_json_error(['message' => 'Type ou extension de fichier non autorisé.']);
        }

        if (!wp_mkdir_p(BJLG_BACKUP_DIR)) {
            wp_send_json_error(['message' => 'Répertoire de sauvegarde inaccessible.']);
        }

        $is_writable = function_exists('wp_is_writable') ? wp_is_writable(BJLG_BACKUP_DIR) : is_writable(BJLG_BACKUP_DIR);
        if (!$is_writable) {
            wp_send_json_error(['message' => 'Répertoire de sauvegarde non accessible en écriture.']);
        }

        // Déplacer le fichier uploadé
        $destination = BJLG_BACKUP_DIR . 'restore_' . uniqid() . '_' . $sanitized_filename;
        
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
        $is_encrypted_backup = substr($filename, -4) === '.enc';

        $password = null;
        if (array_key_exists('password', $_POST)) {
            $maybe_password = wp_unslash($_POST['password']);
            if (is_string($maybe_password)) {
                $password = $maybe_password;
            }
        }

        if ($password !== null) {
            if ($password === '') {
                $password = null;
            } elseif (strlen($password) < 4) {
                wp_send_json_error(['message' => 'Le mot de passe doit contenir au moins 4 caractères.']);
            }
        }

        if ($is_encrypted_backup && $password === null) {
            wp_send_json_error(['message' => 'Un mot de passe est requis pour restaurer une sauvegarde chiffrée.']);
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
        $create_backup_before_restore = true;
        if (array_key_exists('create_backup_before_restore', $_POST)) {
            $raw_create_backup = wp_unslash($_POST['create_backup_before_restore']);
            $filtered_value = filter_var($raw_create_backup, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($filtered_value === null) {
                $create_backup_before_restore = !empty($raw_create_backup);
            } else {
                $create_backup_before_restore = (bool) $filtered_value;
            }
        }

        $task_data = [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => 'Initialisation de la restauration...',
            'filename' => $filename,
            'filepath' => $filepath,
            'password_encrypted' => $encrypted_password,
            'create_restore_point' => (bool) $create_backup_before_restore
        ];
        
        set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl());
        
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
        $original_archive_path = $filepath;
        $decrypted_archive_path = null;
        $create_restore_point = !empty($task_data['create_restore_point']);
        $requested_components = $this->normalize_requested_components($task_data['components'] ?? null);
        $current_status = is_array($task_data) ? $task_data : [];

        if (!empty($encrypted_password)) {
            try {
                $password = $this->decrypt_password_from_transient($encrypted_password);
            } catch (Exception $exception) {
                if (class_exists(BJLG_Debug::class)) {
                    BJLG_Debug::log(
                        "ERREUR: Échec du déchiffrement du mot de passe pour la tâche {$task_id} : " . $exception->getMessage(),
                        'error'
                    );
                }
            }
        }

        $temp_extract_dir = BJLG_BACKUP_DIR . 'temp_restore_' . uniqid();
        $final_error_status = null;
        $error_status_recorded = false;

        try {
            set_time_limit(0);
            @ini_set('memory_limit', '256M');

            BJLG_Debug::log("Début de la restauration pour le fichier : " . basename($filepath));

            if ($create_restore_point) {
                $current_status = array_merge($current_status, [
                    'progress' => 5,
                    'status' => 'running',
                    'status_text' => 'Création d\'un point de restauration...'
                ]);
                set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());

                $this->perform_pre_restore_backup();
            }

            $current_status = array_merge($current_status, [
                'progress' => 10,
                'status' => 'running',
                'status_text' => 'Vérification du fichier de sauvegarde...'
            ]);
            set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());

            if (!file_exists($filepath)) {
                throw new Exception("Le fichier de sauvegarde n'a pas été trouvé.");
            }

            if (substr($filepath, -4) === '.enc') {
                $current_status = array_merge($current_status, [
                    'progress' => 20,
                    'status' => 'running',
                    'status_text' => 'Déchiffrement de l\'archive...'
                ]);
                set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());

                $encryption = new BJLG_Encryption();
                $decrypted_archive_path = $encryption->decrypt_backup_file($filepath, $password);
                $filepath = $decrypted_archive_path;
            }

            if (!mkdir($temp_extract_dir, 0755, true)) {
                throw new Exception("Impossible de créer le répertoire temporaire.");
            }

            $zip = new ZipArchive();
            if ($zip->open($filepath) !== true) {
                throw new Exception("Impossible d'ouvrir l'archive. Fichier corrompu ?");
            }

            $manifest_json = $zip->getFromName('backup-manifest.json');
            if ($manifest_json === false) {
                throw new Exception("Manifeste de sauvegarde manquant.");
            }

            $manifest = json_decode($manifest_json, true);
            $allowed_components = ['db', 'plugins', 'themes', 'uploads'];
            $manifest_components = [];

            if (is_array($manifest) && !empty($manifest['contains']) && is_array($manifest['contains'])) {
                foreach ($manifest['contains'] as $component) {
                    if (!is_string($component)) {
                        continue;
                    }

                    $component_key = sanitize_key($component);

                    if (
                        in_array($component_key, $allowed_components, true)
                        && !in_array($component_key, $manifest_components, true)
                    ) {
                        $manifest_components[] = $component_key;
                    }
                }
            }

            $components_to_restore = array_values(array_intersect($manifest_components, $requested_components));

            if (class_exists('BJLG_Debug')) {
                BJLG_Debug::log(
                    sprintf(
                        'Composants demandés : %s | Présents dans le manifeste : %s | Retenus : %s',
                        empty($requested_components) ? 'aucun' : implode(', ', $requested_components),
                        empty($manifest_components) ? 'aucun' : implode(', ', $manifest_components),
                        empty($components_to_restore) ? 'aucun' : implode(', ', $components_to_restore)
                    )
                );
            }

            if (in_array('db', $components_to_restore, true)) {
                $current_status = array_merge($current_status, [
                    'progress' => 30,
                    'status' => 'running',
                    'status_text' => 'Restauration de la base de données...'
                ]);
                set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());

                if ($zip->locateName('database.sql') !== false) {
                    $allowed_entries = $this->build_allowed_zip_entries($zip, $temp_extract_dir);

                    if (!array_key_exists('database.sql', $allowed_entries)) {
                        throw new Exception("Entrée d'archive invalide détectée : database.sql");
                    }

                    $zip->extractTo($temp_extract_dir, 'database.sql');
                    $sql_filepath = $temp_extract_dir . '/database.sql';

                    BJLG_Debug::log("Import de la base de données...");
                    $this->import_database($sql_filepath);

                    $current_status = array_merge($current_status, [
                        'progress' => 50,
                        'status' => 'running',
                        'status_text' => 'Base de données restaurée.'
                    ]);
                    set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());
                }
            }

            $folders_to_restore = [];

            if (in_array('plugins', $components_to_restore, true)) {
                $folders_to_restore['plugins'] = WP_PLUGIN_DIR;
            }
            if (in_array('themes', $components_to_restore, true)) {
                $folders_to_restore['themes'] = get_theme_root();
            }
            if (in_array('uploads', $components_to_restore, true)) {
                $upload_dir = wp_get_upload_dir();
                $folders_to_restore['uploads'] = $upload_dir['basedir'];
            }

            $progress = $current_status['progress'] ?? 50;

            if (!empty($folders_to_restore)) {
                $progress_step = 40 / count($folders_to_restore);

                foreach ($folders_to_restore as $type => $destination) {
                    $progress += $progress_step;

                    $current_status = array_merge($current_status, [
                        'progress' => (int) round($progress),
                        'status' => 'running',
                        'status_text' => "Restauration des {$type}..."
                    ]);
                    set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());

                    $source_folder = "wp-content/{$type}";
                    $files_to_extract = [];

                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $file = $zip->getNameIndex($i);
                        if ($file !== false && strpos($file, $source_folder) === 0) {
                            $files_to_extract[] = $file;
                        }
                    }

                    if (!empty($files_to_extract)) {
                        $allowed_entries = $this->build_allowed_zip_entries($zip, $temp_extract_dir);

                        foreach ($files_to_extract as $file_to_extract) {
                            if (!array_key_exists($file_to_extract, $allowed_entries)) {
                                throw new Exception("Entrée d'archive invalide détectée : {$file_to_extract}");
                            }
                        }

                        $zip->extractTo($temp_extract_dir, $files_to_extract);

                        $this->recursive_copy(
                            $temp_extract_dir . '/' . $source_folder,
                            $destination
                        );

                        BJLG_Debug::log("Restauration de {$type} terminée.");
                    }
                }
            }

            $zip->close();

            $current_status = array_merge($current_status, [
                'progress' => 95,
                'status' => 'running',
                'status_text' => 'Nettoyage...'
            ]);
            set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());

            $this->recursive_delete($temp_extract_dir);
            $this->clear_all_caches();

            BJLG_History::log('restore_run', 'success', "Fichier : " . basename($original_archive_path));

            $current_status = array_merge($current_status, [
                'progress' => 100,
                'status' => 'complete',
                'status_text' => 'Restauration terminée avec succès !'
            ]);
            set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());

        } catch (Throwable $throwable) {
            $error_message = 'Erreur : ' . $throwable->getMessage();
            $current_status = array_merge($current_status, [
                'progress' => 100,
                'status' => 'error',
                'status_text' => $error_message,
            ]);
            $final_error_status = $current_status;

            try {
                BJLG_History::log('restore_run', 'failure', $error_message);
            } catch (Throwable $history_exception) {
                BJLG_Debug::log(
                    "ERREUR: Impossible d'enregistrer l'échec de la restauration : " . $history_exception->getMessage(),
                    'error'
                );
            }

            if (is_dir($temp_extract_dir)) {
                $this->recursive_delete($temp_extract_dir);
            }

            try {
                set_transient($task_id, $final_error_status, BJLG_Backup::get_task_ttl());
                $error_status_recorded = true;
            } catch (Throwable $transient_exception) {
                BJLG_Debug::log(
                    "ERREUR: Impossible de mettre à jour le statut de la tâche {$task_id} : " . $transient_exception->getMessage(),
                    'error'
                );
            }
        } finally {
            if ($decrypted_archive_path && $decrypted_archive_path !== $original_archive_path) {
                if (file_exists($decrypted_archive_path)) {
                    if (@unlink($decrypted_archive_path)) {
                        BJLG_Debug::log('Suppression du fichier déchiffré temporaire : ' . basename($decrypted_archive_path));
                    } else {
                        $cleanup_message = 'Impossible de supprimer le fichier déchiffré temporaire : ' . $decrypted_archive_path;
                        BJLG_Debug::log($cleanup_message, 'error');
                        BJLG_History::log('restore_cleanup', 'failure', $cleanup_message);
                    }
                }
            }

            if ($final_error_status !== null && !$error_status_recorded) {
                try {
                    set_transient($task_id, $final_error_status, BJLG_Backup::get_task_ttl());
                    $error_status_recorded = true;
                } catch (Throwable $transient_exception) {
                    BJLG_Debug::log(
                        "ERREUR: Impossible de mettre à jour le statut final de la tâche {$task_id} : " . $transient_exception->getMessage(),
                        'error'
                    );
                }
            }
        }
    }

    /**
     * Construit la liste des entrées d'archive autorisées pour une extraction sécurisée.
     *
     * @param ZipArchive $zip
     * @param string     $temp_extract_dir
     * @return array<string, string>
     * @throws Exception
     */
    private function build_allowed_zip_entries(ZipArchive $zip, $temp_extract_dir) {
        $allowed_entries = [];

        $base_realpath = realpath($temp_extract_dir);
        if ($base_realpath === false) {
            throw new Exception('Impossible de valider le répertoire temporaire.');
        }

        $base_realpath = $this->normalize_path_for_validation($base_realpath);

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry_name = $zip->getNameIndex($index);

            if ($entry_name === false) {
                continue;
            }

            $normalized_entry = $this->normalize_zip_entry_name($entry_name);

            if ($normalized_entry === '') {
                $allowed_entries[$entry_name] = '';
                continue;
            }

            if (strpos($normalized_entry, '..') !== false) {
                throw new Exception("Entrée d'archive invalide détectée : {$entry_name}");
            }

            if ($normalized_entry[0] === '/' || preg_match('/^[A-Za-z]:/', $normalized_entry) === 1) {
                throw new Exception("Entrée d'archive invalide détectée : {$entry_name}");
            }

            $relative_entry = ltrim($normalized_entry, '/');
            $target_path = $this->join_paths($temp_extract_dir, $relative_entry);

            if (substr($normalized_entry, -1) === '/') {
                $directory_path = rtrim($target_path, '/\\');

                if ($directory_path === '') {
                    $directory_path = $temp_extract_dir;
                }

                $this->ensure_directory_exists($directory_path);

                $real_target = realpath($directory_path);
                if ($real_target === false) {
                    throw new Exception("Entrée d'archive invalide détectée : {$entry_name}");
                }

                $real_target = $this->normalize_path_for_validation($real_target);
                $this->assert_path_within_base($base_realpath, $real_target, $entry_name);

                $allowed_entries[$entry_name] = $relative_entry;
                continue;
            }

            $parent_directory = dirname($target_path);
            if ($parent_directory !== '' && $parent_directory !== '.' && $parent_directory !== DIRECTORY_SEPARATOR) {
                $this->ensure_directory_exists($parent_directory);
            }

            $real_parent = realpath($parent_directory ?: $temp_extract_dir);
            if ($real_parent === false) {
                throw new Exception("Entrée d'archive invalide détectée : {$entry_name}");
            }

            $real_parent = $this->normalize_path_for_validation($real_parent);
            $this->assert_path_within_base($base_realpath, $real_parent, $entry_name);

            $final_candidate = $this->normalize_path_for_validation($real_parent . '/' . basename($target_path));
            $this->assert_path_within_base($base_realpath, $final_candidate, $entry_name);

            $allowed_entries[$entry_name] = $relative_entry;
        }

        return $allowed_entries;
    }

    /**
     * Normalise la liste des composants demandés pour la restauration.
     *
     * @param mixed $components
     * @return array<int, string>
     */
    private function normalize_requested_components($components) {
        $allowed_components = ['db', 'plugins', 'themes', 'uploads'];

        if ($components === null) {
            return $allowed_components;
        }

        $components = (array) $components;
        $normalized = [];
        $has_all = empty($components);

        foreach ($components as $component) {
            if (!is_string($component)) {
                continue;
            }

            $component_key = sanitize_key($component);

            if ($component_key === 'all') {
                $has_all = true;
                continue;
            }

            if (in_array($component_key, $allowed_components, true) && !in_array($component_key, $normalized, true)) {
                $normalized[] = $component_key;
            }
        }

        if ($has_all || empty($normalized)) {
            return $allowed_components;
        }

        return $normalized;
    }

    /**
     * Normalise un nom d'entrée d'archive pour validation.
     *
     * @param string $entry_name
     * @return string
     */
    private function normalize_zip_entry_name($entry_name) {
        if (function_exists('wp_normalize_path')) {
            $normalized = wp_normalize_path($entry_name);
        } else {
            $normalized = str_replace('\\', '/', (string) $entry_name);
        }

        while (strpos($normalized, './') === 0) {
            $normalized = substr($normalized, 2);
        }

        return $normalized;
    }

    /**
     * Normalise un chemin pour la comparaison des préfixes.
     *
     * @param string $path
     * @return string
     */
    private function normalize_path_for_validation($path) {
        if (function_exists('wp_normalize_path')) {
            $normalized = wp_normalize_path($path);
        } else {
            $normalized = str_replace('\\', '/', (string) $path);
        }

        if ($normalized === '') {
            return '';
        }

        if ($normalized !== '/') {
            $normalized = rtrim($normalized, '/');
        }

        return $normalized;
    }

    /**
     * Concatène un chemin de base avec un chemin relatif.
     *
     * @param string $base
     * @param string $path
     * @return string
     */
    private function join_paths($base, $path) {
        $trimmed_base = rtrim($base, '/\\');

        if ($path === '') {
            return $trimmed_base;
        }

        return $trimmed_base . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Vérifie qu'un chemin est contenu dans un répertoire de base.
     *
     * @param string $base
     * @param string $path
     * @param string $entry_name
     * @return void
     * @throws Exception
     */
    private function assert_path_within_base($base, $path, $entry_name) {
        if ($path === $base) {
            return;
        }

        if (strpos($path, $base . '/') !== 0) {
            throw new Exception("Entrée d'archive invalide détectée : {$entry_name}");
        }
    }

    /**
     * S'assure qu'un répertoire existe pour la validation des chemins.
     *
     * @param string $directory
     * @return void
     * @throws Exception
     */
    private function ensure_directory_exists($directory) {
        if ($directory === '' || is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new Exception('Impossible de préparer le répertoire temporaire pour la validation.');
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

        $query = '';
        $queries_executed = 0;
        $errors = [];

        $transaction_started = false;
        $should_commit = false;
        $transaction_exception = null;

        try {
            // Désactiver temporairement les contraintes
            $wpdb->query('SET foreign_key_checks = 0');
            $wpdb->query('SET autocommit = 0');

            if ($wpdb->query('START TRANSACTION') !== false) {
                $transaction_started = true;
            }

            try {
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

                $should_commit = true;

                BJLG_Debug::log("Import SQL terminé : {$queries_executed} requêtes exécutées.");

                if (!empty($errors)) {
                    BJLG_Debug::log("Erreurs rencontrées : " . implode(", ", array_slice($errors, 0, 5)));
                }
            } catch (Throwable $throwable) {
                $transaction_exception = $throwable;
                throw $throwable;
            } finally {
                if ($transaction_started) {
                    if ($transaction_exception === null && $should_commit) {
                        $wpdb->query('COMMIT');
                    } else {
                        $wpdb->query('ROLLBACK');
                    }
                }

                $wpdb->query('SET autocommit = 1');
                $wpdb->query('SET foreign_key_checks = 1');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
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
        
        // Cache des transients spécifiques au plugin
        global $wpdb;

        if (isset($wpdb)) {
            $options_table = $wpdb->options ?? ($wpdb->prefix ?? 'wp_') . 'options';

            if (method_exists($wpdb, 'get_col')) {
                $transient_option_names = (array) $wpdb->get_col("SELECT option_name FROM {$options_table} WHERE option_name LIKE '\\_transient\\_bjlg\\_%'");
                $this->delete_plugin_transients($transient_option_names, false);

                if (function_exists('delete_site_transient')) {
                    $site_transient_option_names = (array) $wpdb->get_col("SELECT option_name FROM {$options_table} WHERE option_name LIKE '\\_site\\_transient\\_bjlg\\_%'");
                    $this->delete_plugin_transients($site_transient_option_names, true);

                    if (isset($wpdb->sitemeta)) {
                        $site_meta_table = $wpdb->sitemeta;
                        $network_transient_keys = (array) $wpdb->get_col("SELECT meta_key FROM {$site_meta_table} WHERE meta_key LIKE '\\_site\\_transient\\_bjlg\\_%'");
                        $this->delete_plugin_transients($network_transient_keys, true);
                    }
                }
            } elseif (method_exists($wpdb, 'query')) {
                $wpdb->query("DELETE FROM {$options_table} WHERE option_name LIKE '\\_transient\\_bjlg\\_%'");
                $wpdb->query("DELETE FROM {$options_table} WHERE option_name LIKE '\\_site\\_transient\\_bjlg\\_%'");

                if (isset($wpdb->sitemeta)) {
                    $site_meta_table = $wpdb->sitemeta;
                    $wpdb->query("DELETE FROM {$site_meta_table} WHERE meta_key LIKE '\\_site\\_transient\\_bjlg\\_%'");
                }
            }
        }
        
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

    /**
     * Supprime les transients du plugin en utilisant les API WordPress.
     *
     * @param array<int, string> $option_names Liste des noms d'options ou métas contenant les transients.
     * @param bool $site_scope Indique si l'on supprime des transients de site.
     */
    private function delete_plugin_transients(array $option_names, bool $site_scope): void {
        $prefix = $site_scope ? '_site_transient_' : '_transient_';

        foreach ($option_names as $option_name) {
            $option_name = (string) $option_name;

            if (strpos($option_name, $prefix) !== 0) {
                continue;
            }

            $transient = substr($option_name, strlen($prefix));

            if ($transient === '') {
                continue;
            }

            if ($site_scope) {
                if (function_exists('delete_site_transient')) {
                    delete_site_transient($transient);
                }

                continue;
            }

            if (function_exists('delete_transient')) {
                delete_transient($transient);
            }
        }
    }
}