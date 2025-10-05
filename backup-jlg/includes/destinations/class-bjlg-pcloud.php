<?php
namespace BJLG;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

if (!interface_exists(BJLG_Destination_Interface::class)) {
    return;
}

class BJLG_pCloud implements BJLG_Destination_Interface {

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

        echo "<div class='bjlg-destination bjlg-destination--pcloud'>";
        echo "<h4><span class='dashicons dashicons-cloud' aria-hidden='true'></span> pCloud</h4>";
        echo "<p class='description'>Connectez votre espace pCloud via un token API pour stocker vos archives.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Token d'accès</th><td><input type='password' name='pcloud_access_token' value='" . esc_attr($settings['access_token']) . "' class='regular-text' autocomplete='off' placeholder='pcloud-token-...'>";
        echo "<p class='description'>Générez un token personnel pCloud avec accès en lecture/écriture.</p></td></tr>";
        echo "<tr><th scope='row'>Dossier cible</th><td><input type='text' name='pcloud_folder' value='" . esc_attr($settings['folder']) . "' class='regular-text' placeholder='/Backups/WP'>";
        echo "<p class='description'>Chemin relatif dans votre espace pCloud. Laissez vide pour la racine.</p></td></tr>";

        $enabled_attr = $settings['enabled'] ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer pCloud</th><td><label><input type='checkbox' name='pcloud_enabled' value='true'{$enabled_attr}> Activer l'envoi automatique vers pCloud.</label></td></tr>";
        echo "</table>";

        echo "<div class='notice bjlg-pcloud-test-feedback bjlg-hidden' role='status' aria-live='polite'></div>";
        echo "<p class='bjlg-pcloud-test-actions'><button type='button' class='button bjlg-pcloud-test-connection'>Tester la connexion</button> <span class='spinner bjlg-pcloud-test-spinner' style='float:none;margin:0 0 0 8px;display:none;'></span></p>";

        if ($status['last_result'] === 'success' && $status['tested_at'] > 0) {
            $tested_at = gmdate('d/m/Y H:i:s', $status['tested_at']);
            echo "<p class='description'><span class='dashicons dashicons-yes'></span> Dernier test réussi le {$tested_at}.";
            if ($status['message'] !== '') {
                echo ' ' . esc_html($status['message']);
            }
            echo '</p>';
        } elseif ($status['last_result'] === 'error') {
            echo "<p class='description' style='color:#b32d2e;'><span class='dashicons dashicons-warning'></span> " . esc_html($status['message']) . "</p>";
        }

        if ($this->is_connected()) {
            echo "<form method='post' action='" . esc_url(admin_url('admin-post.php')) . "' class='bjlg-pcloud-disconnect-form'>";
            echo "<input type='hidden' name='action' value='bjlg_pcloud_disconnect'>";
            if (function_exists('wp_nonce_field')) {
                wp_nonce_field('bjlg_pcloud_disconnect', 'bjlg_pcloud_nonce');
            }
            echo "<button type='submit' class='button'>Déconnecter pCloud</button></form>";
        }

        echo '</div>';
    }

    public function test_connection(?array $settings = null) {
        $settings = $settings ? array_merge($this->get_default_settings(), $settings) : $this->get_settings();

        if (empty($settings['access_token'])) {
            throw new Exception("Token d'accès pCloud manquant.");
        }

        $path = $this->normalize_folder($settings['folder']);
        $this->api_request('https://api.pcloud.com/listfolder', [
            'path' => $path === '' ? '/' : $path,
            'recursive' => 0,
        ], $settings);

        $this->store_status([
            'last_result' => 'success',
            'tested_at' => $this->get_time(),
            'message' => 'Connexion pCloud vérifiée avec succès.',
        ]);

        return true;
    }

    public function handle_test_connection() {
        if (!\bjlg_can_manage_plugin()) {
            wp_send_json_error(['message' => 'Permission refusée.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $settings = [
            'access_token' => isset($_POST['pcloud_access_token']) ? sanitize_text_field(wp_unslash($_POST['pcloud_access_token'])) : '',
            'folder' => isset($_POST['pcloud_folder']) ? sanitize_text_field(wp_unslash($_POST['pcloud_folder'])) : '',
            'enabled' => true,
        ];

        try {
            $this->test_connection($settings);
            wp_send_json_success(['message' => 'Connexion pCloud réussie.']);
        } catch (Exception $exception) {
            $this->store_status([
                'last_result' => 'error',
                'tested_at' => $this->get_time(),
                'message' => $exception->getMessage(),
            ]);

            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public function handle_disconnect_request() {
        if (!\bjlg_can_manage_plugin()) {
            return;
        }

        if (isset($_POST['bjlg_pcloud_nonce'])) {
            $nonce = wp_unslash($_POST['bjlg_pcloud_nonce']);
            if (function_exists('wp_verify_nonce') && !wp_verify_nonce($nonce, 'bjlg_pcloud_disconnect')) {
                return;
            }
        }

        $this->disconnect();

        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect(admin_url('admin.php?page=backup-jlg&tab=settings'));
            exit;
        }
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
            throw new Exception("pCloud n'est pas configuré.");
        }

        $contents = file_get_contents($filepath);
        if ($contents === false) {
            throw new Exception('Impossible de lire le fichier à envoyer vers pCloud.');
        }

        $pcloud_path = $this->build_pcloud_path(basename($filepath), $settings['folder']);
        $headers = [
            'Authorization' => 'Bearer ' . $settings['access_token'],
            'Content-Type' => 'application/octet-stream',
            'X-PCloud-Path' => $pcloud_path,
            'X-PCloud-Overwrite' => '1',
        ];

        $args = [
            'method' => 'POST',
            'headers' => $headers,
            'body' => $contents,
            'timeout' => apply_filters('bjlg_pcloud_upload_timeout', 60, $pcloud_path),
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

        try {
            $response = $this->api_request('https://api.pcloud.com/listfolder', [
                'path' => $path === '' ? '/' : $path,
                'recursive' => 0,
            ], $settings);
        } catch (Exception $exception) {
            return [];
        }

        if (!isset($response['metadata']['contents']) || !is_array($response['metadata']['contents'])) {
            return [];
        }

        $backups = [];
        foreach ($response['metadata']['contents'] as $entry) {
            if (!is_array($entry) || !empty($entry['isfolder'])) {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');
            if (!$this->is_backup_filename($name)) {
                continue;
            }

            $timestamp = isset($entry['modified']) ? strtotime($entry['modified']) : 0;
            if (!is_int($timestamp) || $timestamp <= 0) {
                $timestamp = $this->get_time();
            }

            $backups[] = [
                'id' => (string) ($entry['fileid'] ?? ''),
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
            $file_id = (string) ($backup['id'] ?? '');
            if ($file_id === '') {
                continue;
            }

            try {
                $this->api_request('https://api.pcloud.com/deletefile', [
                    'fileid' => $file_id,
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

    private function build_pcloud_path($filename, $folder) {
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
            'message' => 'Envoi pCloud réalisé avec succès.',
        ]);
    }

    private function api_request($url, array $body, array $settings) {
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
        if ($code < 200 || $code >= 300) {
            $raw = isset($response['body']) ? (string) $response['body'] : '';
            throw new Exception(sprintf('pCloud a renvoyé un statut inattendu (%d) : %s', $code, $raw));
        }

        $raw_body = isset($response['body']) ? (string) $response['body'] : '';
        if ($raw_body === '') {
            return [];
        }

        $decoded = json_decode($raw_body, true);
        if (!is_array($decoded)) {
            throw new Exception('Réponse pCloud invalide.');
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

        return (bool) preg_match('/\\.zip(\\.[A-Za-z0-9]+)?$/i', $name);
    }

    private function get_time() {
        return (int) call_user_func($this->time_provider);
    }
}
