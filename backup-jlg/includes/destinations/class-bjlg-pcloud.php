<?php
namespace BJLG;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

if (!interface_exists(BJLG_Destination_Interface::class)) {
    return;
}

class BJLG_PCloud implements BJLG_Destination_Interface {

    private const OPTION_SETTINGS = 'bjlg_pcloud_settings';
    private const OPTION_STATUS = 'bjlg_pcloud_status';

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
            add_action('wp_ajax_bjlg_test_pcloud_connection', [$this, 'handle_test_connection']);
            add_action('admin_post_bjlg_pcloud_disconnect', [$this, 'handle_disconnect_request']);
        }
    }

    public function get_id() {
        return 'pcloud';
    }

    public function get_name() {
        return 'pCloud';
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
        $is_connected = $this->is_connected();

        echo "<div class='bjlg-destination bjlg-destination--pcloud'>";
        echo "<h4><span class='dashicons dashicons-cloud' aria-hidden='true'></span> pCloud</h4>";
        echo "<form class='bjlg-settings-form bjlg-destination-form' novalidate>";
        echo "<div class='bjlg-settings-feedback notice bjlg-hidden' role='status' aria-live='polite'></div>";
        echo "<p class='description'>Stockez vos sauvegardes WordPress dans un dossier pCloud dédié.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Access Token</th><td><input type='password' name='pcloud_access_token' value='" . esc_attr($settings['access_token']) . "' class='regular-text' autocomplete='off' placeholder='pcld_...'>";
        echo "<p class='description'>Générez un token d'accès avec les permissions d'upload et de lecture.</p></td></tr>";
        echo "<tr><th scope='row'>Dossier cible</th><td><input type='text' name='pcloud_folder' value='" . esc_attr($settings['folder']) . "' class='regular-text' placeholder='/Backups/WP'>";
        echo "<p class='description'>Chemin relatif dans votre espace pCloud. Exemple : <code>/Apps/Backup-JLG</code>.</p></td></tr>";

        $enabled_attr = $settings['enabled'] ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer pCloud</th><td><label><input type='checkbox' name='pcloud_enabled' value='true'{$enabled_attr}> Activer l'envoi automatique vers pCloud.</label></td></tr>";
        echo "</table>";

        echo "<div class='notice bjlg-pcloud-test-feedback bjlg-hidden' role='status' aria-live='polite'></div>";
        echo "<p class='bjlg-pcloud-test-actions'><button type='button' class='button bjlg-pcloud-test-connection'>Tester la connexion</button> <span class='spinner bjlg-pcloud-test-spinner' style='float:none;margin:0 0 0 8px;display:none;'></span></p>";

        if ($status['tested_at'] > 0) {
            $icon = $status['last_result'] === 'success' ? 'dashicons-yes' : 'dashicons-warning';
            $color = $status['last_result'] === 'success' ? '' : '#b32d2e';
            $tested_at = gmdate('d/m/Y H:i:s', $status['tested_at']);
            $message = $status['message'] !== '' ? $status['message'] : ($status['last_result'] === 'success' ? 'Connexion vérifiée avec succès.' : 'Le dernier test a échoué.');
            echo "<p class='description bjlg-pcloud-last-test' style='color:{$color};'><span class='dashicons {$icon}'></span> Dernier test le {$tested_at}. " . esc_html($message) . "</p>";
        } else {
            echo "<p class='description bjlg-pcloud-last-test bjlg-hidden'></p>";
        }

        if ($is_connected) {
            $disconnect_url = $this->get_disconnect_url();
            echo "<p><a class='button button-secondary' href='" . esc_url($disconnect_url) . "'>Déconnecter pCloud</a></p>";
        }

        echo "<p class='submit'><button type='submit' class='button button-primary'>Enregistrer les réglages</button></p>";
        echo "</form>";

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
                throw new Exception('Erreurs pCloud : ' . implode(' | ', $errors));
            }

            return;
        }

        if (!is_readable($filepath)) {
            throw new Exception('Fichier introuvable pour pCloud : ' . $filepath);
        }

        $settings = $this->get_settings();
        if (!$this->is_connected()) {
            throw new Exception('pCloud n\'est pas configuré.');
        }

        $contents = file_get_contents($filepath);
        if ($contents === false) {
            throw new Exception('Impossible de lire le fichier à envoyer vers pCloud.');
        }

        $remote_path = $this->build_remote_path(basename($filepath), $settings['folder']);

        $args = [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $settings['access_token'],
                'Content-Type' => 'application/octet-stream',
                'X-PCloud-Path' => $remote_path,
                'X-PCloud-Overwrite' => '1',
            ],
            'body' => $contents,
            'timeout' => apply_filters('bjlg_pcloud_upload_timeout', 60, $remote_path),
        ];

        $response = call_user_func($this->request_handler, 'https://api.pcloud.com/uploadfile', $args);
        $this->guard_response($response, 'Envoi pCloud échoué');
    }

    public function list_remote_backups() {
        if (!$this->is_connected()) {
            return [];
        }

        $settings = $this->get_settings();
        $path = $this->normalize_folder($settings['folder']);
        $body = [
            'path' => $path === '' ? '/' : $path,
            'recursive' => 0,
        ];

        $response = $this->api_json('https://api.pcloud.com/listfolder', $body, $settings);
        if (!isset($response['metadata']['contents']) || !is_array($response['metadata']['contents'])) {
            return [];
        }

        $backups = [];
        foreach ($response['metadata']['contents'] as $entry) {
            if (!is_array($entry) || !empty($entry['isfolder'])) {
                continue;
            }

            $name = basename((string) ($entry['path'] ?? $entry['name'] ?? ''));
            if (!$this->is_backup_filename($name)) {
                continue;
            }

            $timestamp = isset($entry['modified']) ? strtotime((string) $entry['modified']) : 0;
            if (!is_int($timestamp) || $timestamp <= 0) {
                $timestamp = $this->get_time();
            }

            $backups[] = [
                'id' => isset($entry['fileid']) ? (string) $entry['fileid'] : '',
                'name' => $name,
                'path' => (string) ($entry['path'] ?? ''),
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
                $body = [];
                if (!empty($backup['id'])) {
                    $body['fileid'] = $backup['id'];
                } elseif (!empty($backup['path'])) {
                    $body['path'] = $backup['path'];
                } else {
                    continue;
                }

                $this->api_json('https://api.pcloud.com/deletefile', $body, $settings);
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

    public function delete_remote_backup_by_name($filename) {
        $outcome = [
            'success' => false,
            'message' => '',
        ];

        if (!$this->is_connected()) {
            $outcome['message'] = __('pCloud n\'est pas configuré.', 'backup-jlg');

            return $outcome;
        }

        $filename = basename((string) $filename);
        if ($filename === '') {
            $outcome['message'] = __('Nom de fichier invalide.', 'backup-jlg');

            return $outcome;
        }

        $settings = $this->get_settings();

        try {
            $backups = $this->list_remote_backups();
            foreach ($backups as $backup) {
                if (($backup['name'] ?? '') !== $filename) {
                    continue;
                }

                $body = [];
                if (!empty($backup['id'])) {
                    $body['fileid'] = $backup['id'];
                } elseif (!empty($backup['path'])) {
                    $body['path'] = $backup['path'];
                }

                if (empty($body)) {
                    continue;
                }

                $this->api_json('https://api.pcloud.com/deletefile', $body, $settings);

                if (class_exists(BJLG_Debug::class)) {
                    BJLG_Debug::log(sprintf('Purge distante pCloud réussie pour %s.', $filename));
                }

                $outcome['success'] = true;

                return $outcome;
            }

            $outcome['message'] = __('Sauvegarde distante introuvable sur pCloud.', 'backup-jlg');
        } catch (Exception $exception) {
            $outcome['message'] = $exception->getMessage();
        }

        return $outcome;
    }

    public function get_storage_usage() {
        $defaults = [
            'used_bytes' => null,
            'quota_bytes' => null,
            'free_bytes' => null,
        ];

        if (!$this->is_connected()) {
            return $defaults;
        }

        try {
            $backups = $this->list_remote_backups();
        } catch (Exception $exception) {
            if (class_exists(BJLG_Debug::class)) {
                BJLG_Debug::log('Impossible de récupérer les métriques pCloud : ' . $exception->getMessage());
            }

            return $defaults;
        }

        $used = 0;
        foreach ($backups as $backup) {
            $used += isset($backup['size']) ? (int) $backup['size'] : 0;
        }

        return [
            'used_bytes' => $used,
            'quota_bytes' => null,
            'free_bytes' => null,
        ];
    }

    public function handle_test_connection() {
        if (!\bjlg_can_manage_integrations()) {
            wp_send_json_error(['message' => 'Permission refusée.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $settings = [
            'access_token' => isset($_POST['pcloud_access_token']) ? sanitize_text_field(wp_unslash($_POST['pcloud_access_token'])) : '',
            'folder' => isset($_POST['pcloud_folder']) ? sanitize_text_field(wp_unslash($_POST['pcloud_folder'])) : '',
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
        if (!\bjlg_can_manage_integrations()) {
            wp_die('Permission refusée.');
        }

        check_admin_referer('bjlg_pcloud_disconnect');

        $this->disconnect();

        $redirect = isset($_REQUEST['_wp_http_referer']) ? esc_url_raw(wp_unslash($_REQUEST['_wp_http_referer'])) : admin_url('admin.php?page=backup-jlg&tab=settings');
        wp_safe_redirect(add_query_arg('bjlg_pcloud_disconnected', '1', $redirect));
        exit;
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
            'timeout' => apply_filters('bjlg_pcloud_request_timeout', 45, $url),
        ];

        $response = call_user_func($this->request_handler, $url, $args);
        if (is_wp_error($response)) {
            throw new Exception('Erreur de communication avec pCloud : ' . $response->get_error_message());
        }

        $code = isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        $raw = isset($response['body']) ? (string) $response['body'] : '';
        if ($code < 200 || $code >= 300) {
            throw new Exception(sprintf('pCloud a renvoyé un statut inattendu (%d) : %s', $code, $raw));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new Exception('Réponse pCloud invalide.');
        }

        if (isset($decoded['error']) && (int) $decoded['error'] !== 0) {
            $message = isset($decoded['error']) ? (string) $decoded['error'] : 'Erreur API pCloud';
            throw new Exception($message);
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

    private function build_remote_path($filename, $folder) {
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

    private function test_connection(array $settings) {
        if ($settings['access_token'] === '') {
            throw new Exception('Fournissez un token d\'accès pCloud.');
        }

        $body = [
            'path' => $this->normalize_folder($settings['folder']) ?: '/',
            'recursive' => 0,
        ];

        $this->api_json('https://api.pcloud.com/listfolder', $body, $settings);

        return [
            'message' => 'Connexion pCloud validée.',
            'tested_at' => $this->get_time(),
        ];
    }

    private function get_disconnect_url() {
        $referer = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : admin_url('admin.php?page=backup-jlg&tab=settings');

        $url = wp_nonce_url(add_query_arg('action', 'bjlg_pcloud_disconnect', admin_url('admin-post.php')), 'bjlg_pcloud_disconnect');

        return add_query_arg('_wp_http_referer', rawurlencode($referer), $url);
    }

    private function get_time() {
        return (int) call_user_func($this->time_provider);
    }
}
