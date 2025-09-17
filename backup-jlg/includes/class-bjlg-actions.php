<?php
namespace BJLG;

use Exception;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Actions AJAX (suppression de sauvegarde, etc.)
 */
class BJLG_Actions {

    public function __construct() {
        add_action('wp_ajax_bjlg_delete_backup', [$this, 'handle_delete_backup']);
        add_action('wp_ajax_bjlg_download', [$this, 'handle_download_request']);
        add_action('wp_ajax_nopriv_bjlg_download', [$this, 'handle_download_request']);
        add_action('template_redirect', [$this, 'maybe_handle_public_download']);
    }

    /**
     * Supprime un fichier de sauvegarde via AJAX.
     */
    public function handle_delete_backup() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        if (empty($_POST['filename'])) {
            wp_send_json_error(['message' => 'Nom de fichier manquant.'], 400);
        }

        $filename = '';
        try {
            // Valider et nettoyer le nom du fichier pour la sécurité
            $filename = basename(sanitize_file_name(wp_unslash($_POST['filename'])));
            if (empty($filename) || strpos($filename, '..') !== false) {
                throw new Exception("Nom de fichier invalide.");
            }

            // Construire le chemin initial vers le fichier
            $filepath = BJLG_BACKUP_DIR . $filename;

            // Obtenir les chemins absolus et canoniques pour la sécurité
            $real_backup_dir = realpath(BJLG_BACKUP_DIR);
            $real_filepath = realpath($filepath);

            // Si realpath échoue (par ex. fichier inexistant), on vérifie quand même le chemin de base
            if ($real_filepath === false) {
                 if (strpos(realpath(dirname($filepath)), $real_backup_dir) !== 0) {
                    throw new Exception("Chemin de fichier non valide.");
                 }
                 // Le fichier n'existe pas, donc on envoie l'erreur appropriée
                 throw new Exception("Fichier introuvable.");
            }
            
            // Normaliser les séparateurs de répertoire (barres obliques) pour une comparaison fiable
            $normalized_backup_dir = str_replace('\\', '/', $real_backup_dir);
            $normalized_filepath = str_replace('\\', '/', $real_filepath);

            // S'assurer que le chemin du répertoire se termine par un slash pour la comparaison
            $normalized_backup_dir = rtrim($normalized_backup_dir, '/') . '/';

            // Contrôle de sécurité final : le chemin du fichier doit commencer par le chemin du répertoire de sauvegarde
            if (strpos($normalized_filepath, $normalized_backup_dir) !== 0) {
                 throw new Exception("Accès au fichier non autorisé.");
            }
            
            if (!is_writable($real_filepath)) {
                throw new Exception("Fichier non supprimable (permissions).");
            }

            if (!@unlink($real_filepath)) {
                throw new Exception("Impossible de supprimer le fichier.");
            }

            if (class_exists(BJLG_History::class)) {
                BJLG_History::log('backup_deleted', 'success', 'Fichier : ' . $filename);
            }

            wp_send_json_success(['message' => 'Fichier supprimé avec succès.']);

        } catch (Exception $e) {
            if (class_exists(BJLG_History::class)) {
                BJLG_History::log('backup_deleted', 'failure', 'Fichier : ' . $filename . ' - Erreur : ' . $e->getMessage());
            }
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Gère la diffusion d'une sauvegarde via AJAX (authentifié ou non).
     */
    public function handle_download_request() {
        $token = isset($_REQUEST['token']) ? sanitize_text_field(wp_unslash($_REQUEST['token'])) : '';

        $validation = $this->validate_download_token($token);
        if (is_wp_error($validation)) {
            $status = (int) ($validation->get_error_data('status') ?? 403);
            wp_send_json_error(['message' => $validation->get_error_message()], $status);
        }

        list($filepath, $transient_key) = $validation;

        delete_transient($transient_key);

        $this->stream_backup_file($filepath);
    }

    /**
     * Intercepte les requêtes publiques (API REST) pour diffuser une sauvegarde.
     */
    public function maybe_handle_public_download() {
        if (empty($_GET['bjlg_download'])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET['bjlg_download']));

        $validation = $this->validate_download_token($token);
        if (is_wp_error($validation)) {
            $status = (int) ($validation->get_error_data('status') ?? 403);
            status_header($status);
            wp_die(esc_html($validation->get_error_message()), '', ['response' => $status]);
        }

        list($filepath, $transient_key) = $validation;

        delete_transient($transient_key);

        $this->stream_backup_file($filepath);
    }

    /**
     * Valide un token de téléchargement et renvoie le chemin sécurisé.
     *
     * @param string $token
     * @return array{0: string, 1: string}|WP_Error
     */
    private function validate_download_token($token) {
        if (empty($token)) {
            return new WP_Error('bjlg_missing_token', 'Token de téléchargement manquant.', ['status' => 400]);
        }

        $transient_key = 'bjlg_download_' . $token;
        $filepath = get_transient($transient_key);

        if (empty($filepath)) {
            return new WP_Error('bjlg_invalid_token', 'Lien de téléchargement invalide ou expiré.', ['status' => 403]);
        }

        $real_backup_dir = realpath(BJLG_BACKUP_DIR);
        $real_filepath = realpath($filepath);

        if ($real_backup_dir === false || $real_filepath === false) {
            return new WP_Error('bjlg_invalid_path', 'Chemin de sauvegarde invalide.', ['status' => 404]);
        }

        $normalized_dir = rtrim(str_replace('\\', '/', $real_backup_dir), '/') . '/';
        $normalized_path = str_replace('\\', '/', $real_filepath);

        if (strpos($normalized_path, $normalized_dir) !== 0) {
            return new WP_Error('bjlg_invalid_path', 'Accès à la sauvegarde refusé.', ['status' => 403]);
        }

        if (!file_exists($real_filepath)) {
            delete_transient($transient_key);
            return new WP_Error('bjlg_missing_file', 'Le fichier de sauvegarde est introuvable.', ['status' => 404]);
        }

        if (!is_readable($real_filepath)) {
            return new WP_Error('bjlg_unreadable_file', 'Le fichier de sauvegarde est inaccessible.', ['status' => 500]);
        }

        return [$real_filepath, $transient_key];
    }

    /**
     * Diffuse un fichier de sauvegarde avec les bons en-têtes HTTP et arrête l'exécution.
     *
     * @param string $filepath
     */
    private function stream_backup_file($filepath) {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            status_header(404);
            wp_die('Fichier de sauvegarde introuvable.', '', ['response' => 404]);
        }

        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        nocache_headers();
        status_header(200);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Content-Transfer-Encoding: binary');
        header('Connection: close');

        while (ob_get_level()) {
            ob_end_clean();
        }

        $handle = fopen($filepath, 'rb');
        if ($handle === false) {
            status_header(500);
            wp_die('Impossible de lire le fichier de sauvegarde.', '', ['response' => 500]);
        }

        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }

        fclose($handle);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            flush();
        }
        exit;
    }
}
