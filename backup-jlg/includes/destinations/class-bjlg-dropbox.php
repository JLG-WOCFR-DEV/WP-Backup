<?php
namespace BJLG;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

if (!interface_exists(BJLG_Destination_Interface::class)) {
    return;
}

/**
 * Destination Dropbox via l'API v2.
 */
class BJLG_Dropbox implements BJLG_Destination_Interface {

    private const OPTION_SETTINGS = 'bjlg_dropbox_settings';
    private const OPTION_STATUS = 'bjlg_dropbox_status';

    /** @var callable */
    private $request_handler;

    /** @var callable */
    private $time_provider;

    public function __construct(?callable $request_handler = null, ?callable $time_provider = null) {
        $this->request_handler = $request_handler ?: static function ($url, array $args = []) {
            return wp_remote_request($url, $args);
        };
        $this->time_provider = $time_provider ?: static function () {
            return time();
        };

        if (function_exists('add_action')) {
            add_action('wp_ajax_bjlg_test_dropbox_connection', [$this, 'handle_test_connection']);
            add_action('admin_post_bjlg_dropbox_disconnect', [$this, 'handle_disconnect_request']);
        }
    }

    public function get_id() {
        return 'dropbox';
    }

    public function get_name() {
        return 'Dropbox';
    }

    public function is_connected() {
        $settings = $this->get_settings();

        return $settings['enabled'] && $settings['access_token'] !== '';
    }

    public function disconnect() {
        update_option(self::OPTION_SETTINGS, $this->get_default_settings());

        if (function_exists('delete_option')) {
            delete_option(self::OPTION_STATUS);
        } else {
            update_option(self::OPTION_STATUS, []);
        }
    }

    public function render_settings() {
        $settings = $this->get_settings();
        $status = $this->get_status();

        echo "<div class='bjlg-destination bjlg-destination--dropbox'>";
        echo "<h4><span class='dashicons dashicons-archive' aria-hidden='true'></span> Dropbox</h4>";
        echo "<p class='description'>Connectez un dossier Dropbox pour stocker automatiquement vos archives de sauvegarde.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Access Token</th><td><input type='password' name='dropbox_access_token' value='" . esc_attr($settings['access_token']) . "' class='regular-text' autocomplete='off' placeholder='sl.BA...'>";
        echo "<p class='description'>Générez un token OAuth avec les permissions <code>files.content.write</code> et <code>files.content.read</code>.</p></td></tr>";
        echo "<tr><th scope='row'>Dossier cible</th><td><input type='text' name='dropbox_folder' value='" . esc_attr($settings['folder']) . "' class='regular-text' placeholder='/Backups/WP'>";
        echo "<p class='description'>Chemin relatif dans Dropbox. Laissez vide pour la racine.</p></td></tr>";

        $enabled_attr = $settings['enabled'] ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer Dropbox</th><td><label><input type='checkbox' name='dropbox_enabled' value='true'{$enabled_attr}> Activer l'envoi automatique vers Dropbox.</label></td></tr>";
        echo "</table>";

        echo "<div class='notice bjlg-dropbox-test-feedback bjlg-hidden' role='status' aria-live='polite'></div>";
        echo "<p class='bjlg-dropbox-test-actions'><button type='button' class='button bjlg-dropbox-test-connection'>Tester la connexion</button> <span class='spinner bjlg-dropbox-test-spinner' style='float:none;margin:0 0 0 8px;display:none;'></span></p>";

        if ($status['tested_at'] > 0) {
            $icon = $status['last_result'] === 'success' ? 'dashicons-yes' : 'dashicons-warning';
            $color = $status['last_result'] === 'success' ? '' : '#b32d2e';
            $tested_at = gmdate('d/m/Y H:i:s', $status['tested_at']);
            $message = $status['message'] !== '' ? $status['message'] : ($status['last_result'] === 'success' ? 'Connexion vérifiée avec succès.' : 'Le dernier test a échoué.');
            echo "<p class='description bjlg-dropbox-last-test' style='color:{$color};'><span class='dashicons {$icon}'></span> Dernier test le {$tested_at}. " . esc_html($message) . "</p>";
        } else {
            echo "<p class='description bjlg-dropbox-last-test bjlg-hidden'></p>";
        }

        if ($this->is_connected()) {
            $disconnect_url = $this->get_disconnect_url();
            echo "<p><a class='button button-secondary' href='" . esc_url($disconnect_url) . "'>Déconnecter Dropbox</a></p>";
        }

        echo '</div>';
    }

    public function upload_file($filepath, $task_id) {
        if (is_array($filepath)) {
            $errors = [];
            foreach ($filepath as $single) {
                try {
                    $this->upload_file($single, $task_id);
                } catch (Exception $exception) {
                    $errors[] = $exception->getMessage();
                }
            }

            if (!empty($errors)) {
                throw new Exception('Erreurs Dropbox : ' . implode(' | ', $errors));
            }

            return;
        }

        if (!is_readable($filepath)) {
            throw new Exception('Fichier introuvable pour Dropbox : ' . $filepath);
        }

        $settings = $this->get_settings();
        if (!$this->is_connected()) {
            throw new Exception('Dropbox n\'est pas configuré.');
        }

        $contents = file_get_contents($filepath);
        if ($contents === false) {
            throw new Exception('Impossible de lire le fichier à envoyer vers Dropbox.');
        }

        $dropbox_path = $this->build_dropbox_path(basename($filepath), $settings['folder']);
        $headers = [
            'Authorization' => 'Bearer ' . $settings['access_token'],
            'Content-Type' => 'application/octet-stream',
            'Dropbox-API-Arg' => wp_json_encode([
                'path' => $dropbox_path,
                'mode' => ['.tag' => 'overwrite'],
                'mute' => false,
                'strict_conflict' => false,
            ]),
        ];

        $args = [
            'method' => 'POST',
            'headers' => $headers,
            'body' => $contents,
            'timeout' => apply_filters('bjlg_dropbox_upload_timeout', 60, $dropbox_path),
        ];

        $response = call_user_func($this->request_handler, 'https://content.dropboxapi.com/2/files/upload', $args);

        $this->guard_response($response, 'Envoi Dropbox échoué');
    }

    public function list_remote_backups() {
        if (!$this->is_connected()) {
            return [];
        }

        $settings = $this->get_settings();
        $path = $this->normalize_folder($settings['folder']);
        $body = [
            'path' => $path === '' ? '' : $path,
            'recursive' => false,
            'include_media_info' => false,
            'include_deleted' => false,
        ];

        $response = $this->api_json('https://api.dropboxapi.com/2/files/list_folder', $body, $settings);
        if (!isset($response['entries']) || !is_array($response['entries'])) {
            return [];
        }

        $backups = [];
        foreach ($response['entries'] as $entry) {
            if (!is_array($entry) || ($entry['.tag'] ?? '') !== 'file') {
                continue;
            }

            $name = basename((string) ($entry['path_display'] ?? $entry['name'] ?? ''));
            if (!$this->is_backup_filename($name)) {
                continue;
            }

            $timestamp = isset($entry['server_modified']) ? strtotime($entry['server_modified']) : 0;
            if (!is_int($timestamp) || $timestamp <= 0) {
                $timestamp = $this->get_time();
            }

            $backups[] = [
                'id' => (string) ($entry['id'] ?? ''),
                'name' => $name,
                'path' => (string) ($entry['path_display'] ?? ''),
                'timestamp' => $timestamp,
                'size' => isset($entry['size']) ? (int) $entry['size'] : 0,
            ];
        }

        return $backups;
    }

    public function prune_remote_backups($retain_by_number, $retain_by_age_days) {
        $result = [
            'deleted' => 0,
            'errors' => [],
            'inspected' => 0,
            'deleted_items' => [],
        ];

        if (!$this->is_connected()) {
            return $result;
        }

        $retain_by_number = (int) $retain_by_number;
        $retain_by_age_days = (int) $retain_by_age_days;

        if ($retain_by_number === 0 && $retain_by_age_days === 0) {
            return $result;
        }

        $backups = $this->list_remote_backups();
        $result['inspected'] = count($backups);

        if (empty($backups)) {
            return $result;
        }

        $to_delete = $this->select_backups_to_delete($backups, $retain_by_number, $retain_by_age_days);
        $settings = $this->get_settings();

        foreach ($to_delete as $backup) {
            try {
                $this->api_json('https://api.dropboxapi.com/2/files/delete_v2', [
                    'path' => (string) ($backup['path'] ?? ''),
                ], $settings);
                $result['deleted']++;
                if (!empty($backup['name'])) {
                    $result['deleted_items'][] = $backup['name'];
                }
            } catch (Exception $exception) {
                $result['errors'][] = $exception->getMessage();
            }
        }

        return $result;
    }

    private function get_settings() {
        $stored = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge($this->get_default_settings(), $stored);
    }

    private function get_default_settings() {
        return [
            'access_token' => '',
            'folder' => '',
            'enabled' => false,
        ];
    }

    private function get_status() {
        $status = get_option(self::OPTION_STATUS, [
            'last_result' => null,
            'tested_at' => 0,
            'message' => '',
        ]);

        if (!is_array($status)) {
            $status = [];
        }

        $defaults = [
            'last_result' => null,
            'tested_at' => 0,
            'message' => '',
        ];

        return array_merge($defaults, $status);
    }

    private function store_status(array $status) {
        $current = $this->get_status();
        update_option(self::OPTION_STATUS, array_merge($current, $status));
    }

    private function build_dropbox_path($filename, $folder) {
        $folder = $this->normalize_folder($folder);
        $filename = ltrim($filename, '/');

        if ($folder === '') {
            return '/' . $filename;
        }

        return $folder . '/' . $filename;
    }

    private function normalize_folder($folder) {
        $folder = trim((string) $folder);
        if ($folder === '' || $folder === '/') {
            return '';
        }

        $folder = str_replace('\\', '/', $folder);
        $folder = '/' . trim($folder, '/');

        return $folder;
    }

    private function guard_response($response, $error_prefix) {
        if (is_wp_error($response)) {
            $this->store_status([
                'last_result' => 'error',
                'tested_at' => $this->get_time(),
                'message' => $response->get_error_message(),
            ]);
            throw new Exception($error_prefix . ' : ' . $response->get_error_message());
        }

        $code = isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        if ($code < 200 || $code >= 300) {
            $body = isset($response['body']) ? (string) $response['body'] : '';
            $message = sprintf('%s (HTTP %d) %s', $error_prefix, $code, $body);
            $this->store_status([
                'last_result' => 'error',
                'tested_at' => $this->get_time(),
                'message' => $message,
            ]);
            throw new Exception($message);
        }

        $this->store_status([
            'last_result' => 'success',
            'tested_at' => $this->get_time(),
            'message' => 'Envoi réalisé avec succès.',
        ]);
    }

    private function api_json($url, array $body, array $settings) {
        $args = [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $settings['access_token'],
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
            'timeout' => apply_filters('bjlg_dropbox_request_timeout', 45, $url),
        ];

        $response = call_user_func($this->request_handler, $url, $args);
        if (is_wp_error($response)) {
            throw new Exception('Erreur de communication avec Dropbox : ' . $response->get_error_message());
        }

        $code = isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        $raw = isset($response['body']) ? (string) $response['body'] : '';
        if ($code < 200 || $code >= 300) {
            throw new Exception(sprintf('Dropbox a renvoyé un statut inattendu (%d) : %s', $code, $raw));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new Exception('Réponse Dropbox invalide.');
        }

        return $decoded;
    }

    private function select_backups_to_delete(array $backups, int $retain_by_number, int $retain_by_age_days) {
        $to_delete = [];
        $now = $this->get_time();

        if ($retain_by_age_days > 0) {
            $age_limit = $retain_by_age_days * DAY_IN_SECONDS;
            foreach ($backups as $backup) {
                $timestamp = (int) ($backup['timestamp'] ?? 0);
                if ($timestamp > 0 && ($now - $timestamp) > $age_limit) {
                    $to_delete[$this->get_backup_identifier($backup)] = $backup;
                }
            }
        }

        if ($retain_by_number > 0 && count($backups) > $retain_by_number) {
            usort($backups, static function ($a, $b) {
                $time_a = (int) ($a['timestamp'] ?? 0);
                $time_b = (int) ($b['timestamp'] ?? 0);

                if ($time_a === $time_b) {
                    return 0;
                }

                return $time_b <=> $time_a;
            });

            $excess = array_slice($backups, $retain_by_number);
            foreach ($excess as $backup) {
                $to_delete[$this->get_backup_identifier($backup)] = $backup;
            }
        }

        return array_values($to_delete);
    }

    private function get_backup_identifier(array $backup) {
        foreach (['id', 'path', 'name'] as $key) {
            if (!empty($backup[$key])) {
                return (string) $backup[$key];
            }
        }

        return sha1(json_encode($backup));
    }

    private function is_backup_filename($name) {
        if (!is_string($name) || $name === '') {
            return false;
        }

        return (bool) preg_match('/\.zip(\.[A-Za-z0-9]+)?$/i', $name);
    }

    private function get_time() {
        return (int) call_user_func($this->time_provider);
    }

    public function handle_test_connection() {
        if (!\bjlg_can_manage_plugin()) {
            wp_send_json_error(['message' => 'Permission refusée.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $settings = [
            'access_token' => isset($_POST['dropbox_access_token']) ? sanitize_text_field(wp_unslash($_POST['dropbox_access_token'])) : '',
            'folder' => isset($_POST['dropbox_folder']) ? sanitize_text_field(wp_unslash($_POST['dropbox_folder'])) : '',
            'enabled' => true,
        ];

        try {
            $result = $this->test_connection($settings);
            $this->store_status([
                'last_result' => 'success',
                'tested_at' => $result['tested_at'],
                'message' => $result['message'],
            ]);

            wp_send_json_success([
                'message' => $result['message'],
                'status_message' => $result['message'],
                'tested_at' => $result['tested_at'],
                'tested_at_formatted' => gmdate('d/m/Y H:i:s', $result['tested_at']),
            ]);
        } catch (Exception $exception) {
            $tested_at = $this->get_time();
            $this->store_status([
                'last_result' => 'error',
                'tested_at' => $tested_at,
                'message' => $exception->getMessage(),
            ]);

            wp_send_json_error([
                'message' => $exception->getMessage(),
                'status_message' => $exception->getMessage(),
                'tested_at' => $tested_at,
                'tested_at_formatted' => gmdate('d/m/Y H:i:s', $tested_at),
            ], 400);
        }
    }

    public function handle_disconnect_request() {
        if (!\bjlg_can_manage_plugin()) {
            wp_die('Permission refusée.');
        }

        check_admin_referer('bjlg_dropbox_disconnect');

        $this->disconnect();

        $redirect = isset($_REQUEST['_wp_http_referer']) ? esc_url_raw(wp_unslash($_REQUEST['_wp_http_referer'])) : admin_url('admin.php?page=backup-jlg&tab=settings');
        wp_safe_redirect(add_query_arg('bjlg_dropbox_disconnected', '1', $redirect));
        exit;
    }

    private function test_connection(array $settings) {
        if ($settings['access_token'] === '') {
            throw new Exception('Fournissez un token d\'accès Dropbox.');
        }

        $body = [
            'path' => $this->normalize_folder($settings['folder']),
            'recursive' => false,
            'include_deleted' => false,
            'include_non_downloadable_files' => false,
        ];

        $this->api_json('https://api.dropboxapi.com/2/files/list_folder', $body, $settings);

        return [
            'message' => 'Connexion à Dropbox validée.',
            'tested_at' => $this->get_time(),
        ];
    }

    private function get_disconnect_url() {
        $referer = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : admin_url('admin.php?page=backup-jlg&tab=settings');

        $url = wp_nonce_url(add_query_arg('action', 'bjlg_dropbox_disconnect', admin_url('admin-post.php')), 'bjlg_dropbox_disconnect');

        return add_query_arg('_wp_http_referer', rawurlencode($referer), $url);
    }
}
