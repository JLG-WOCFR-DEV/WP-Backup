<?php
namespace BJLG;

use Exception;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

class BJLG_Admin_Fallbacks {
    private const NOTICE_PARAM = 'bjlg_notice';
    private const MESSAGE_PARAM = 'bjlg_notice_message';

    public function __construct() {
        add_action('admin_post_bjlg_create_backup', [$this, 'handle_create_backup']);
        add_action('admin_post_bjlg_restore_backup', [$this, 'handle_restore_backup']);
    }

    public function handle_create_backup() {
        if (!\bjlg_can_manage_plugin()) {
            wp_die(__('Vous n\'avez pas les permissions nécessaires pour créer une sauvegarde.', 'backup-jlg'));
        }

        check_admin_referer('bjlg_create_backup', 'bjlg_create_backup_nonce');

        $components = isset($_POST['backup_components']) ? (array) wp_unslash($_POST['backup_components']) : [];
        $components = array_map('sanitize_text_field', $components);

        if (empty($components)) {
            $this->redirect_with_notice('error', __('Aucun composant sélectionné pour la sauvegarde.', 'backup-jlg'));
            return;
        }

        $encrypt = !empty($_POST['encrypt_backup']);
        $incremental = !empty($_POST['incremental_backup']);

        $include_raw = isset($_POST['include_patterns']) ? wp_unslash($_POST['include_patterns']) : '';
        $exclude_raw = isset($_POST['exclude_patterns']) ? wp_unslash($_POST['exclude_patterns']) : '';

        $include_patterns = BJLG_Settings::sanitize_pattern_list($include_raw);
        $exclude_patterns = BJLG_Settings::sanitize_pattern_list($exclude_raw);

        $post_checks = BJLG_Settings::sanitize_post_checks(
            isset($_POST['post_checks']) ? wp_unslash((array) $_POST['post_checks']) : [],
            BJLG_Settings::get_default_backup_post_checks()
        );

        $secondary_destinations = BJLG_Settings::sanitize_destination_list(
            isset($_POST['secondary_destinations']) ? wp_unslash((array) $_POST['secondary_destinations']) : [],
            BJLG_Settings::get_known_destination_ids()
        );

        BJLG_Settings::get_instance()->update_backup_filters(
            $include_patterns,
            $exclude_patterns,
            $secondary_destinations,
            $post_checks
        );

        $task_id = 'bjlg_backup_' . md5(uniqid('fallback', true));

        if (!BJLG_Backup::reserve_task_slot($task_id)) {
            $this->redirect_with_notice('error', __('Une sauvegarde est déjà en cours. Réessayez dans quelques instants.', 'backup-jlg'));
            return;
        }

        $task_data = [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => __('Initialisation de la sauvegarde…', 'backup-jlg'),
            'components' => $components,
            'encrypt' => $encrypt,
            'incremental' => $incremental,
            'source' => 'fallback',
            'start_time' => time(),
            'include_patterns' => $include_patterns,
            'exclude_patterns' => $exclude_patterns,
            'post_checks' => $post_checks,
            'secondary_destinations' => $secondary_destinations,
        ];

        if (!BJLG_Backup::save_task_state($task_id, $task_data)) {
            BJLG_Backup::release_task_slot($task_id);
            $this->redirect_with_notice('error', __('Impossible d\'initialiser la tâche de sauvegarde.', 'backup-jlg'));
            return;
        }

        $scheduled = wp_schedule_single_event(time(), 'bjlg_run_backup_task', ['task_id' => $task_id]);

        if ($scheduled === false || is_wp_error($scheduled)) {
            delete_transient($task_id);
            BJLG_Backup::release_task_slot($task_id);
            $message = __('Impossible de planifier la tâche de sauvegarde en arrière-plan.', 'backup-jlg');

            if ($scheduled instanceof WP_Error) {
                $details = $scheduled->get_error_message();
                if ($details) {
                    $message .= ' ' . $details;
                }
            }

            $this->redirect_with_notice('error', $message);
            return;
        }

        $this->redirect_with_notice('success', __('Sauvegarde lancée. Vous recevrez une notification lorsqu\'elle sera terminée.', 'backup-jlg'));
    }

    public function handle_restore_backup() {
        if (!\bjlg_can_manage_plugin()) {
            wp_die(__('Vous n\'avez pas les permissions nécessaires pour lancer une restauration.', 'backup-jlg'));
        }

        check_admin_referer('bjlg_restore_backup', 'bjlg_restore_backup_nonce');

        $filename = '';
        $filepath = '';

        $upload = isset($_FILES['restore_file']) && is_array($_FILES['restore_file']) ? $_FILES['restore_file'] : null;

        if ($upload && !empty($upload['name'])) {
            $upload_result = $this->process_uploaded_restore_file($upload);
            if (is_wp_error($upload_result)) {
                $this->redirect_with_notice('error', $upload_result->get_error_message());
                return;
            }
            $filename = $upload_result['filename'];
            $filepath = $upload_result['filepath'];
        } else {
            $raw_filename = isset($_POST['restore_filename']) ? wp_unslash($_POST['restore_filename']) : '';
            $filename = basename(sanitize_file_name($raw_filename));

            if ($filename === '') {
                $this->redirect_with_notice('error', __('Aucun fichier de sauvegarde n\'a été sélectionné.', 'backup-jlg'));
                return;
            }

            $resolved_path = BJLG_Backup_Path_Resolver::resolve($filename);
            if (is_wp_error($resolved_path)) {
                $this->redirect_with_notice('error', $resolved_path->get_error_message());
                return;
            }

            $filepath = $resolved_path;
        }

        $is_encrypted = substr($filename, -4) === '.enc';

        $create_backup_before_restore = !empty($_POST['create_backup_before_restore']);

        $raw_components = isset($_POST['restore_components']) ? wp_unslash($_POST['restore_components']) : [];
        $requested_components = BJLG_Restore::normalize_requested_components($raw_components);

        $restore_environment = BJLG_Restore::ENV_PRODUCTION;
        if (isset($_POST['restore_environment'])) {
            $restore_environment = sanitize_key((string) wp_unslash($_POST['restore_environment']));
        } elseif (!empty($_POST['restore_to_sandbox'])) {
            $restore_environment = BJLG_Restore::ENV_SANDBOX;
        }

        if ($restore_environment === BJLG_Restore::ENV_SANDBOX && !BJLG_Restore::user_can_use_sandbox()) {
            $this->redirect_with_notice('error', __('Vous ne pouvez pas utiliser la sandbox de restauration.', 'backup-jlg'));
            return;
        }

        $sandbox_path = '';
        if (isset($_POST['sandbox_path'])) {
            $sandbox_path = trim((string) wp_unslash($_POST['sandbox_path']));
        }

        try {
            $environment_config = BJLG_Restore::prepare_environment($restore_environment, [
                'sandbox_path' => $sandbox_path,
            ]);
        } catch (Exception $exception) {
            $this->redirect_with_notice('error', sprintf(
                __('Impossible de préparer l\'environnement de restauration : %s', 'backup-jlg'),
                $exception->getMessage()
            ));
            return;
        }

        $password = null;
        if (isset($_POST['password'])) {
            $maybe_password = (string) wp_unslash($_POST['password']);
            if ($maybe_password !== '') {
                if (strlen($maybe_password) < 4) {
                    $this->redirect_with_notice('error', __('Le mot de passe doit contenir au moins 4 caractères.', 'backup-jlg'));
                    return;
                }
                $password = $maybe_password;
            }
        }

        if ($is_encrypted && $password === null) {
            $this->redirect_with_notice('error', __('Un mot de passe est requis pour restaurer cette sauvegarde chiffrée.', 'backup-jlg'));
            return;
        }

        try {
            $encrypted_password = BJLG_Restore::encrypt_password_for_transient($password);
        } catch (Exception $exception) {
            $this->redirect_with_notice('error', __('Impossible de sécuriser le mot de passe fourni.', 'backup-jlg'));
            return;
        }

        $task_id = 'bjlg_restore_' . md5(uniqid('restore-fallback', true));
        $task_data = [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => __('Initialisation de la restauration…', 'backup-jlg'),
            'filename' => $filename,
            'filepath' => $filepath,
            'password_encrypted' => $encrypted_password,
            'create_restore_point' => $create_backup_before_restore,
            'components' => $requested_components,
            'environment' => $environment_config['environment'],
            'routing_table' => $environment_config['routing_table'],
        ];

        if (!empty($environment_config['sandbox'])) {
            $task_data['sandbox'] = $environment_config['sandbox'];
        }

        if (!BJLG_Backup::reserve_task_slot($task_id)) {
            $this->redirect_with_notice('error', __('Une restauration est déjà en cours. Merci de patienter.', 'backup-jlg'));
            return;
        }

        $transient_set = set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl());
        if ($transient_set === false) {
            BJLG_Backup::release_task_slot($task_id);
            $this->redirect_with_notice('error', __('Impossible d\'initialiser la tâche de restauration.', 'backup-jlg'));
            return;
        }

        $scheduled = wp_schedule_single_event(time(), 'bjlg_run_restore_task', ['task_id' => $task_id]);
        if ($scheduled === false || is_wp_error($scheduled)) {
            delete_transient($task_id);
            BJLG_Backup::release_task_slot($task_id);

            $message = __('Impossible de planifier la tâche de restauration.', 'backup-jlg');
            if ($scheduled instanceof WP_Error) {
                $details = $scheduled->get_error_message();
                if ($details) {
                    $message .= ' ' . $details;
                }
            }

            $this->redirect_with_notice('error', $message);
            return;
        }

        $this->redirect_with_notice('success', __('Restauration planifiée. Le suivi s\'affichera dans l\'interface.', 'backup-jlg'));
    }

    private function process_uploaded_restore_file(array $uploaded_file)
    {
        $error_code = isset($uploaded_file['error']) ? (int) $uploaded_file['error'] : UPLOAD_ERR_OK;
        if ($error_code !== UPLOAD_ERR_OK) {
            return new WP_Error('bjlg_restore_upload_error', sprintf(
                __('Erreur lors du téléversement du fichier (code %d).', 'backup-jlg'),
                $error_code
            ));
        }

        $original_filename = isset($uploaded_file['name']) ? $uploaded_file['name'] : '';
        $sanitized_filename = sanitize_file_name(wp_unslash($original_filename));
        if ($sanitized_filename === '') {
            return new WP_Error('bjlg_restore_upload_invalid_name', __('Nom de fichier de sauvegarde invalide.', 'backup-jlg'));
        }

        $allowed_mimes = [
            'zip' => 'application/zip',
            'enc' => 'application/octet-stream',
        ];

        $checked_file = wp_check_filetype_and_ext(
            $uploaded_file['tmp_name'] ?? '',
            $sanitized_filename,
            $allowed_mimes
        );

        if (empty($checked_file['ext']) || !array_key_exists($checked_file['ext'], $allowed_mimes)) {
            return new WP_Error('bjlg_restore_upload_invalid_type', __('Type de fichier de sauvegarde non autorisé.', 'backup-jlg'));
        }

        if (!wp_mkdir_p(BJLG_BACKUP_DIR)) {
            return new WP_Error('bjlg_restore_upload_unwritable', __('Répertoire de sauvegarde inaccessible.', 'backup-jlg'));
        }

        $is_writable = function_exists('wp_is_writable') ? wp_is_writable(BJLG_BACKUP_DIR) : is_writable(BJLG_BACKUP_DIR);
        if (!$is_writable) {
            return new WP_Error('bjlg_restore_upload_readonly', __('Le répertoire de sauvegarde n\'est pas accessible en écriture.', 'backup-jlg'));
        }

        if (!empty($uploaded_file['tmp_name'])) {
            $is_uploaded = true;

            if (function_exists('is_uploaded_file')) {
                $is_uploaded = is_uploaded_file($uploaded_file['tmp_name']);
            }

            if (!$is_uploaded) {
                return new WP_Error('bjlg_restore_upload_invalid_tmp', __('Le fichier fourni n\'est pas un téléversement valide.', 'backup-jlg'));
            }
        }

        if (!function_exists('wp_handle_upload')) {
            $maybe_admin_file = rtrim(ABSPATH, '/\\') . '/wp-admin/includes/file.php';
            if (is_readable($maybe_admin_file)) {
                require_once $maybe_admin_file;
            }
        }

        if (!function_exists('wp_handle_upload')) {
            return new WP_Error('bjlg_restore_upload_missing_handler', __('La gestion des téléversements est indisponible.', 'backup-jlg'));
        }

        $handled_upload = wp_handle_upload($uploaded_file, ['test_form' => false]);

        if (is_wp_error($handled_upload)) {
            return new WP_Error('bjlg_restore_upload_failed', $handled_upload->get_error_message());
        }

        if (isset($handled_upload['error'])) {
            $error_message = is_string($handled_upload['error']) ? $handled_upload['error'] : __('Erreur inconnue lors du traitement du fichier téléversé.', 'backup-jlg');
            return new WP_Error('bjlg_restore_upload_handle_error', $error_message);
        }

        if (empty($handled_upload['file']) || !file_exists($handled_upload['file'])) {
            return new WP_Error('bjlg_restore_upload_missing_file', __('Le fichier téléversé est introuvable après traitement.', 'backup-jlg'));
        }

        $destination = BJLG_BACKUP_DIR . 'restore_' . uniqid('', true) . '_' . $sanitized_filename;
        $moved = @rename($handled_upload['file'], $destination);

        if (!$moved) {
            $moved = @copy($handled_upload['file'], $destination);
            if ($moved) {
                @unlink($handled_upload['file']);
            }
        }

        if (!$moved) {
            return new WP_Error('bjlg_restore_upload_move_failed', __('Impossible de déplacer le fichier téléversé vers le dossier de sauvegarde.', 'backup-jlg'));
        }

        return [
            'filename' => basename($destination),
            'filepath' => $destination,
        ];
    }

    private function redirect_with_notice($type, $message) {
        $allowed_types = ['success', 'error', 'warning', 'info'];
        if (!in_array($type, $allowed_types, true)) {
            $type = 'info';
        }

        $redirect = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
        if ($redirect === '') {
            $redirect = admin_url('admin.php?page=backup-jlg');
        }

        $url = add_query_arg([
            self::NOTICE_PARAM => $type,
            self::MESSAGE_PARAM => rawurlencode(wp_strip_all_tags($message)),
        ], $redirect);

        wp_safe_redirect($url);
        exit;
    }
}
