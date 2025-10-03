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
        add_action('wp_ajax_bjlg_prepare_download', [$this, 'prepare_download']);
        add_action('wp_ajax_bjlg_download', [$this, 'handle_download_request']);
        add_action('wp_ajax_nopriv_bjlg_download', [$this, 'handle_download_request']);
        add_action('init', [$this, 'maybe_handle_public_download']);
        add_action('template_redirect', [$this, 'maybe_handle_public_download']);
    }

    /**
     * Génère un token de téléchargement à la demande pour un fichier spécifique.
     */
    public function prepare_download() {
        if (!\bjlg_can_manage_plugin()) {
            wp_send_json_error(['message' => 'Permission refusée.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        if (empty($_POST['filename'])) {
            wp_send_json_error(['message' => 'Nom de fichier manquant.'], 400);
        }

        $raw_filename = wp_unslash($_POST['filename']);
        $sanitized_filename = sanitize_file_name($raw_filename);

        if ($sanitized_filename === '') {
            wp_send_json_error(['message' => 'Nom de fichier invalide.'], 400);
        }

        $real_backup_dir = realpath(BJLG_BACKUP_DIR);

        if ($real_backup_dir === false) {
            wp_send_json_error(['message' => 'Répertoire de sauvegarde introuvable.'], 500);
        }

        $filepath = $real_backup_dir . DIRECTORY_SEPARATOR . $sanitized_filename;
        $real_filepath = realpath($filepath);

        if ($real_filepath === false || !is_readable($real_filepath)) {
            wp_send_json_error(['message' => 'Fichier de sauvegarde introuvable.'], 404);
        }

        $normalized_dir = rtrim(str_replace('\\', '/', $real_backup_dir), '/') . '/';
        $normalized_path = str_replace('\\', '/', $real_filepath);

        if (strpos($normalized_path, $normalized_dir) !== 0) {
            wp_send_json_error(['message' => 'Accès au fichier refusé.'], 403);
        }

        $download_token = wp_generate_password(32, false);
        $transient_key = 'bjlg_download_' . $download_token;
        $ttl = self::get_download_token_ttl($real_filepath);
        $payload = self::build_download_token_payload($real_filepath);

        $persisted = set_transient($transient_key, $payload, $ttl);

        if ($persisted === false) {
            BJLG_Debug::error(sprintf(
                'Échec de la persistance du token de téléchargement "%s" pour "%s".',
                $download_token,
                $real_filepath
            ));

            wp_send_json_error([
                'message' => __('Impossible de créer un token de téléchargement.', 'backup-jlg'),
            ], 500);
        }

        $download_url = self::build_download_url($download_token);

        wp_send_json_success([
            'download_url' => $download_url,
            'token' => $download_token,
            'expires_in' => $ttl,
        ]);
    }

    /**
     * Supprime un fichier de sauvegarde via AJAX.
     */
    public function handle_delete_backup() {
        if (!\bjlg_can_manage_plugin()) {
            wp_send_json_error(['message' => 'Permission refusée.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        if (empty($_POST['filename'])) {
            wp_send_json_error(['message' => 'Nom de fichier manquant.'], 400);
        }

        $filename = '';
        $real_backup_dir = realpath(BJLG_BACKUP_DIR);

        if ($real_backup_dir === false) {
            wp_send_json_error(['message' => 'Répertoire de sauvegarde introuvable.'], 500);
        }

        try {
            // Valider et nettoyer le nom du fichier pour la sécurité
            $filename = basename(sanitize_file_name(wp_unslash($_POST['filename'])));
            if (empty($filename) || strpos($filename, '..') !== false) {
                throw new Exception("Nom de fichier invalide.");
            }

            // Construire le chemin initial vers le fichier en utilisant le chemin canonique du répertoire
            $filepath = $real_backup_dir . DIRECTORY_SEPARATOR . $filename;

            // Obtenir le chemin canonique du fichier pour la sécurité
            $real_filepath = realpath($filepath);

            // Normaliser les séparateurs de répertoire (barres obliques) pour une comparaison fiable
            $normalized_backup_dir = rtrim(str_replace('\\', '/', $real_backup_dir), '/') . '/';

            // Si realpath échoue (par ex. fichier inexistant), on vérifie quand même le chemin de base
            if ($real_filepath === false) {
                $real_file_directory = realpath(dirname($filepath));

                if ($real_file_directory === false) {
                    throw new Exception("Chemin de fichier non valide.");
                }

                $normalized_file_directory = rtrim(str_replace('\\', '/', $real_file_directory), '/') . '/';

                if (strpos($normalized_file_directory, $normalized_backup_dir) !== 0) {
                    throw new Exception("Chemin de fichier non valide.");
                }

                // Le fichier n'existe pas, donc on envoie l'erreur appropriée
                throw new Exception("Fichier introuvable.");
            }

            $normalized_filepath = str_replace('\\', '/', $real_filepath);

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
            $this->log_download_event(
                'backup_download_failure',
                'failure',
                $token,
                null,
                'Erreur: ' . $validation->get_error_message()
            );

            $status = $this->determine_error_status($validation);
            wp_send_json_error(['message' => $validation->get_error_message()], $status);
        }

        list($filepath, $transient_key, $delete_after_download) = array_pad($validation, 3, false);

        $this->log_download_event('backup_download_success', 'success', $token, $filepath);

        delete_transient($transient_key);

        $this->stream_backup_file($filepath);

        if ($delete_after_download && file_exists($filepath)) {
            if (!@unlink($filepath)) {
                BJLG_Debug::error(sprintf('Impossible de supprimer le fichier "%s" après téléchargement.', $filepath));
            }
        }
    }

    /**
     * Intercepte les requêtes publiques (API REST) pour diffuser une sauvegarde.
     */
    public function maybe_handle_public_download() {
        if (!isset($_GET['bjlg_download'])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET['bjlg_download']));

        $validation = $this->validate_download_token($token);
        if (is_wp_error($validation)) {
            $this->log_download_event(
                'backup_download_failure',
                'failure',
                $token,
                null,
                'Erreur: ' . $validation->get_error_message()
            );

            $status = $this->determine_error_status($validation);
            status_header($status);
            wp_die(esc_html($validation->get_error_message()), '', ['response' => $status]);
        }

        list($filepath, $transient_key, $delete_after_download) = array_pad($validation, 3, false);

        $this->log_download_event('backup_download_success', 'success', $token, $filepath);

        delete_transient($transient_key);

        $this->stream_backup_file($filepath);

        if ($delete_after_download && file_exists($filepath)) {
            if (!@unlink($filepath)) {
                BJLG_Debug::error(sprintf('Impossible de supprimer le fichier "%s" après téléchargement.', $filepath));
            }
        }
    }

    /**
     * Valide un token de téléchargement et renvoie le chemin sécurisé.
     *
     * @param string $token
     * @return array{0: string, 1: string, 2?: bool}|WP_Error
     */
    private function validate_download_token($token) {
        if (empty($token)) {
            return new WP_Error('bjlg_missing_token', 'Token de téléchargement manquant.', ['status' => 400]);
        }

        $transient_key = 'bjlg_download_' . $token;
        $payload = get_transient($transient_key);

        if (is_array($payload)) {
            $filepath = isset($payload['file']) ? $payload['file'] : '';
            $required_capability = isset($payload['requires_cap']) ? $payload['requires_cap'] : null;
            $issued_by = isset($payload['issued_by']) ? (int) $payload['issued_by'] : 0;
            $delete_after_download = !empty($payload['delete_after_download']);
        } else {
            $filepath = $payload;
            $required_capability = null;
            $issued_by = 0;
            $delete_after_download = false;
        }

        if (empty($filepath)) {
            return new WP_Error('bjlg_invalid_token', 'Lien de téléchargement invalide ou expiré.', ['status' => 403]);
        }

        if ($issued_by > 0 && function_exists('wp_set_current_user')) {
            wp_set_current_user($issued_by);
        }

        if ($required_capability && !current_user_can($required_capability)) {
            return new WP_Error('bjlg_forbidden', 'Permissions insuffisantes pour télécharger cette sauvegarde.', ['status' => 403]);
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

        return [$real_filepath, $transient_key, $delete_after_download];
    }

    /**
     * Construit la charge utile stockée avec un token de téléchargement.
     *
     * @param string $filepath
     * @param string|null $required_capability
     * @return array
     */
    public static function build_download_token_payload($filepath, $required_capability = null) {
        $issued_by = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $required_capability = $required_capability ?: \bjlg_get_required_capability();

        return [
            'file' => $filepath,
            'requires_cap' => $required_capability,
            'issued_at' => time(),
            'issued_by' => $issued_by,
        ];
    }

    /**
     * Construit l'URL publique permettant de télécharger un fichier via son token.
     *
     * @param string $token
     * @return string
     */
    public static function build_download_url($token) {
        $base_url = function_exists('admin_url') ? admin_url('admin-ajax.php') : 'wp-admin/admin-ajax.php';

        $download_url = add_query_arg([
            'action' => 'bjlg_download',
            'token' => $token,
        ], $base_url);

        return apply_filters('bjlg_download_url', $download_url, $token);
    }

    /**
     * Retourne la durée de vie d'un token de téléchargement.
     *
     * @param string $filepath
     * @return int
     */
    public static function get_download_token_ttl($filepath) {
        $minute_in_seconds = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
        $default_ttl = 15 * $minute_in_seconds;
        $filtered_ttl = apply_filters('bjlg_download_token_ttl', $default_ttl, $filepath);

        if (!is_int($filtered_ttl)) {
            $filtered_ttl = (int) $filtered_ttl;
        }

        if ($filtered_ttl <= 0) {
            $filtered_ttl = $default_ttl;
        }

        $task_ttl = BJLG_Backup::get_task_ttl();

        if (is_int($task_ttl) && $task_ttl > 0) {
            $filtered_ttl = min($filtered_ttl, $task_ttl);
        }

        return $filtered_ttl;
    }

    /**
     * Journalise une tentative de téléchargement avec les informations pertinentes.
     *
     * @param string      $action
     * @param string      $status
     * @param string|null $token
     * @param string|null $filepath
     * @param string      $extra_message
     */
    private function log_download_event($action, $status, $token, $filepath, $extra_message = '') {
        if (!class_exists(BJLG_History::class)) {
            return;
        }

        $ip_address = $this->get_request_ip();
        $token_value = is_string($token) && $token !== '' ? $token : 'non fourni';
        $file_value = is_string($filepath) && $filepath !== '' ? basename($filepath) : 'inconnu';

        $details = sprintf(
            'IP: %s | Token: %s | Fichier: %s',
            $ip_address,
            $token_value,
            $file_value
        );

        if ($extra_message !== '') {
            $details .= ' | ' . $extra_message;
        }

        BJLG_History::log($action, $status, $details);
    }

    /**
     * Récupère l'adresse IP de la requête courante.
     *
     * @return string
     */
    private function get_request_ip() {
        if (!isset($_SERVER) || !is_array($_SERVER)) {
            return 'Unknown';
        }

        $ip_keys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        ];

        foreach ($ip_keys as $key) {
            if (!array_key_exists($key, $_SERVER)) {
                continue;
            }

            $raw_ip = $_SERVER[$key];

            if (!is_string($raw_ip) || $raw_ip === '') {
                continue;
            }

            if (strpos($raw_ip, ',') !== false) {
                $raw_ip = explode(',', $raw_ip)[0];
            }

            $raw_ip = trim($raw_ip);

            $validated = filter_var($raw_ip, FILTER_VALIDATE_IP);

            if ($validated !== false) {
                return $validated;
            }
        }

        return 'Unknown';
    }

    /**
     * Determine the HTTP status code that should be used for a WP_Error response.
     */
    private function determine_error_status(WP_Error $error) {
        $data = $error->get_error_data();

        if (!is_array($data)) {
            $error_code = $error->get_error_code();
            if (!empty($error_code)) {
                $data = $error->get_error_data($error_code);
            }
        }

        if (is_array($data) && isset($data['status'])) {
            return (int) $data['status'];
        }

        return 403;
    }

    /**
     * Diffuse un fichier de sauvegarde avec les bons en-têtes HTTP et arrête l'exécution.
     *
     * @param string $filepath
     */
    private function stream_backup_file($filepath) {
        $short_circuit = apply_filters('bjlg_pre_stream_backup', null, $filepath);

        if ($short_circuit !== null) {
            return;
        }

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
