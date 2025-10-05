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
 * Destination Microsoft OneDrive via Microsoft Graph.
 */
class BJLG_OneDrive implements BJLG_Destination_Interface {

    private const OPTION_SETTINGS = 'bjlg_onedrive_settings';
    private const OPTION_STATUS = 'bjlg_onedrive_status';

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
    }

    public function get_id() {
        return 'onedrive';
    }

    public function get_name() {
        return 'Microsoft OneDrive';
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

        echo "<div class='bjlg-destination bjlg-destination--onedrive'>";
        echo "<h4><span class='dashicons dashicons-cloud-upload' aria-hidden='true'></span> Microsoft OneDrive</h4>";
        echo "<p class='description'>Transférez vos sauvegardes vers OneDrive à l'aide d'un token d'accès Microsoft Graph.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Access Token</th><td><input type='password' name='onedrive_access_token' value='" . esc_attr($settings['access_token']) . "' class='regular-text' autocomplete='off' placeholder='eyJ0eXAi...'>";
        echo "<p class='description'>Fournissez un token OAuth Microsoft Graph disposant des permissions <code>Files.ReadWrite</code>.</p></td></tr>";
        echo "<tr><th scope='row'>Dossier cible</th><td><input type='text' name='onedrive_folder' value='" . esc_attr($settings['folder']) . "' class='regular-text' placeholder='/Backups/WP'>";
        echo "<p class='description'>Chemin relatif à la racine OneDrive. Exemple : <code>/Apps/Backup-JLG</code>.</p></td></tr>";

        $enabled_attr = $settings['enabled'] ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer OneDrive</th><td><label><input type='checkbox' name='onedrive_enabled' value='true'{$enabled_attr}> Activer l'envoi automatique vers OneDrive.</label></td></tr>";
        echo "</table>";

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
                throw new Exception('Erreurs OneDrive : ' . implode(' | ', $errors));
            }

            return;
        }

        if (!is_readable($filepath)) {
            throw new Exception('Fichier introuvable pour OneDrive : ' . $filepath);
        }

        $settings = $this->get_settings();
        if (!$this->is_connected()) {
            throw new Exception('Microsoft OneDrive n\'est pas configuré.');
        }

        $contents = file_get_contents($filepath);
        if ($contents === false) {
            throw new Exception('Impossible de lire le fichier à envoyer vers OneDrive.');
        }

        $path = $this->build_onedrive_path(basename($filepath), $settings['folder']);
        $encoded_path = $this->encode_graph_path($path);
        $url = 'https://graph.microsoft.com/v1.0/me/drive/root:' . $encoded_path . ':/content';

        $args = [
            'method' => 'PUT',
            'headers' => [
                'Authorization' => 'Bearer ' . $settings['access_token'],
                'Content-Type' => 'application/octet-stream',
            ],
            'body' => $contents,
            'timeout' => apply_filters('bjlg_onedrive_upload_timeout', 60, $path),
        ];

        $response = call_user_func($this->request_handler, $url, $args);
        $this->guard_response($response, 'Envoi OneDrive échoué');
    }

    public function list_remote_backups() {
        if (!$this->is_connected()) {
            return [];
        }

        $settings = $this->get_settings();
        $path = $this->normalize_folder($settings['folder']);
        $endpoint = 'https://graph.microsoft.com/v1.0/me/drive/root';
        if ($path !== '') {
            $endpoint .= ':' . $this->encode_graph_path($path) . ':';
        }
        $endpoint .= '/children?$select=id,name,size,lastModifiedDateTime,file,createdDateTime';

        $response = $this->api_request($endpoint, $settings, 'GET');
        if (!isset($response['value']) || !is_array($response['value'])) {
            return [];
        }

        $backups = [];
        foreach ($response['value'] as $item) {
            if (!is_array($item) || empty($item['file'])) {
                continue;
            }

            $name = (string) ($item['name'] ?? '');
            if (!$this->is_backup_filename($name)) {
                continue;
            }

            $timestamp = isset($item['lastModifiedDateTime']) ? strtotime($item['lastModifiedDateTime']) : 0;
            if (!is_int($timestamp) || $timestamp <= 0) {
                $timestamp = $this->get_time();
            }

            $backups[] = [
                'id' => (string) ($item['id'] ?? ''),
                'name' => $name,
                'timestamp' => $timestamp,
                'size' => isset($item['size']) ? (int) $item['size'] : 0,
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
            $id = (string) ($backup['id'] ?? '');
            if ($id === '') {
                continue;
            }

            try {
                $endpoint = 'https://graph.microsoft.com/v1.0/me/drive/items/' . rawurlencode($id);
                $this->api_request($endpoint, $settings, 'DELETE');
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

    private function build_onedrive_path($filename, $folder) {
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


    private function encode_graph_path($path) {
        $path = trim((string) $path);
        if ($path === '' || $path === '/') {
            return '';
        }

        $segments = array_filter(explode('/', trim($path, '/')), 'strlen');
        $encoded = array_map(static function ($segment) {
            return rawurlencode($segment);
        }, $segments);

        return '/' . implode('/', $encoded);
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

    private function api_request($url, array $settings, $method = 'GET', ?array $body = null) {
        $args = [
            'method' => strtoupper($method),
            'headers' => [
                'Authorization' => 'Bearer ' . $settings['access_token'],
                'Content-Type' => 'application/json',
            ],
            'timeout' => apply_filters('bjlg_onedrive_request_timeout', 45, $url, $method),
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = call_user_func($this->request_handler, $url, $args);
        if (is_wp_error($response)) {
            throw new Exception('Erreur de communication avec Microsoft OneDrive : ' . $response->get_error_message());
        }

        $code = isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        if ($code < 200 || $code >= 300) {
            $raw = isset($response['body']) ? (string) $response['body'] : '';
            throw new Exception(sprintf('Microsoft Graph a renvoyé un statut inattendu (%d) : %s', $code, $raw));
        }

        $body_content = isset($response['body']) ? (string) $response['body'] : '';
        if ($method === 'DELETE' || $body_content === '') {
            return [];
        }

        $decoded = json_decode($body_content, true);
        if (!is_array($decoded)) {
            throw new Exception('Réponse Microsoft Graph invalide.');
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
        foreach (['id', 'name'] as $key) {
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
}
