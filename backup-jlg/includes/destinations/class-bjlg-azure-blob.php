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
 * Destination Azure Blob Storage.
 */
class BJLG_Azure_Blob implements BJLG_Destination_Interface {

    private const OPTION_SETTINGS = 'bjlg_azure_blob_settings';
    private const OPTION_STATUS = 'bjlg_azure_blob_status';

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
        return 'azure_blob';
    }

    public function get_name() {
        return 'Azure Blob Storage';
    }

    public function is_connected() {
        $settings = $this->get_settings();

        return $settings['enabled']
            && $settings['account_name'] !== ''
            && $settings['account_key'] !== ''
            && $settings['container'] !== '';
    }

    public function disconnect() {
        $defaults = $this->get_default_settings();
        update_option(self::OPTION_SETTINGS, $defaults);

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

        echo "<div class='bjlg-destination bjlg-destination--azure'>";
        echo "<h4><span class='dashicons dashicons-cloud' aria-hidden='true'></span> Azure Blob Storage</h4>";
        echo "<p class='description'>Envoyez vos sauvegardes vers un conteneur Azure Blob Storage avec authentification par clé partagée.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Compte</th><td><input type='text' name='azure_account_name' value='" . esc_attr($settings['account_name']) . "' class='regular-text' autocomplete='off'></td></tr>";
        echo "<tr><th scope='row'>Clé d'accès</th><td><input type='password' name='azure_account_key' value='" . esc_attr($settings['account_key']) . "' class='regular-text' autocomplete='off'></td></tr>";
        echo "<tr><th scope='row'>Conteneur</th><td><input type='text' name='azure_container' value='" . esc_attr($settings['container']) . "' class='regular-text' placeholder='sauvegardes'></td></tr>";
        echo "<tr><th scope='row'>Préfixe d'objet</th><td><input type='text' name='azure_object_prefix' value='" . esc_attr($settings['object_prefix']) . "' class='regular-text' placeholder='backups/'></td></tr>";
        echo "<tr><th scope='row'>Taille des blocs (Mo)</th><td><input type='number' min='1' max='100' name='azure_chunk_size' value='" . esc_attr($settings['chunk_size_mb']) . "' class='small-text'> <span class='description'>Utilisé pour l'upload multipart.</span></td></tr>";
        echo "<tr><th scope='row'>Suffixe d'hôte</th><td><input type='text' name='azure_endpoint_suffix' value='" . esc_attr($settings['endpoint_suffix']) . "' class='regular-text' placeholder='blob.core.windows.net'></td></tr>";

        $enabled_attr = $settings['enabled'] ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer Azure Blob</th><td><label><input type='checkbox' name='azure_enabled' value='true'{$enabled_attr}> Activer l'envoi automatique vers Azure Blob Storage.</label></td></tr>";
        echo "</table>";

        echo "<div class='notice bjlg-azure-test-feedback bjlg-hidden' role='status' aria-live='polite'></div>";
        echo "<p><button type='button' class='button bjlg-azure-test-connection'>Tester la connexion</button></p>";

        if ($status['last_result'] === 'success' && $status['tested_at'] > 0) {
            $tested_at = gmdate('d/m/Y H:i:s', $status['tested_at']);
            echo "<p class='description'><span class='dashicons dashicons-yes' aria-hidden='true'></span> Dernier test réussi le {$tested_at}.";
            if ($status['message'] !== '') {
                echo ' ' . esc_html($status['message']);
            }
            echo '</p>';
        } elseif ($status['last_result'] === 'error') {
            echo "<p class='description' style='color:#b32d2e;'><span class='dashicons dashicons-warning' aria-hidden='true'></span> " . esc_html($status['message']) . "</p>";
        }

        if ($is_connected) {
            echo "<p class='description'><span class='dashicons dashicons-lock' aria-hidden='true'></span> Connexion Azure Blob configurée.</p>";
        }

        echo '</div>';
    }

    public function upload_file($filepath, $task_id) {
        if (is_array($filepath)) {
            $errors = [];

            foreach ($filepath as $single_path) {
                try {
                    $this->upload_file($single_path, $task_id);
                } catch (Exception $exception) {
                    $errors[] = $exception->getMessage();
                }
            }

            if (!empty($errors)) {
                throw new Exception('Erreurs Azure Blob : ' . implode(' | ', $errors));
            }

            return;
        }

        if (!is_readable($filepath)) {
            throw new Exception('Fichier de sauvegarde introuvable : ' . $filepath);
        }

        $settings = $this->get_settings();
        if (!$this->is_connected()) {
            throw new Exception('Azure Blob Storage n\'est pas configuré.');
        }

        $object_key = $this->build_object_key(basename($filepath), $settings['object_prefix']);
        $file_size = filesize($filepath);
        if ($file_size === false) {
            throw new Exception('Impossible de déterminer la taille du fichier à envoyer.');
        }

        $chunk_size = max(1, (int) $settings['chunk_size_mb']) * 1024 * 1024;
        $handle = fopen($filepath, 'rb');
        if (!$handle) {
            throw new Exception('Impossible d\'ouvrir le fichier de sauvegarde.');
        }

        $block_ids = [];
        $part_number = 0;

        while (!feof($handle)) {
            $data = fread($handle, $chunk_size);
            if ($data === false) {
                fclose($handle);
                throw new Exception('Lecture de fichier interrompue pendant l\'upload Azure.');
            }

            if ($data === '') {
                break;
            }

            $block_id = $this->generate_block_id($part_number);
            $headers = [
                'x-ms-blob-type' => 'BlockBlob',
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) strlen($data),
                'x-ms-meta-bjlg-task' => (string) $task_id,
            ];

            $this->perform_request(
                'PUT',
                $object_key,
                $data,
                $headers,
                $settings,
                [
                    'comp' => 'block',
                    'blockid' => $block_id,
                ]
            );

            $block_ids[] = $block_id;
            $part_number++;
        }

        fclose($handle);

        if (empty($block_ids)) {
            throw new Exception('Aucune donnée envoyée vers Azure Blob.');
        }

        $block_list_xml = $this->build_block_list_xml($block_ids);
        $this->perform_request(
            'PUT',
            $object_key,
            $block_list_xml,
            [
                'Content-Type' => 'application/xml',
                'Content-Length' => (string) strlen($block_list_xml),
                'x-ms-blob-content-type' => 'application/zip',
            ],
            $settings,
            ['comp' => 'blocklist']
        );

        $this->log(sprintf('Sauvegarde "%s" envoyée sur Azure Blob (%s).', basename($filepath), $object_key));
    }

    public function list_remote_backups() {
        if (!$this->is_connected()) {
            return [];
        }

        $settings = $this->get_settings();
        $prefix = $this->build_object_key('', $settings['object_prefix'], false);
        $query = [
            'restype' => 'container',
            'comp' => 'list',
        ];

        if ($prefix !== '') {
            $query['prefix'] = rtrim($prefix, '/') . '/';
        }

        try {
            $response = $this->perform_request('GET', '', '', [], $settings, $query);
        } catch (Exception $exception) {
            $this->log('ERREUR Azure Blob (listing) : ' . $exception->getMessage());
            return [];
        }

        $body = isset($response['body']) ? (string) $response['body'] : '';
        if ($body === '') {
            return [];
        }

        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            return [];
        }

        $backups = [];
        if (isset($xml->Blobs->Blob)) {
            foreach ($xml->Blobs->Blob as $blob) {
                $name = (string) ($blob->Name ?? '');
                if ($name === '') {
                    continue;
                }

                $basename = basename($name);
                if (!$this->is_backup_filename($basename)) {
                    continue;
                }

                $timestamp = 0;
                if (isset($blob->Properties->LastModified)) {
                    $timestamp = strtotime((string) $blob->Properties->LastModified);
                    if (!is_int($timestamp) || $timestamp <= 0) {
                        $timestamp = 0;
                    }
                }

                $size = 0;
                if (isset($blob->Properties->ContentLength)) {
                    $size = (int) $blob->Properties->ContentLength;
                }

                $backups[] = [
                    'id' => $name,
                    'name' => $basename,
                    'timestamp' => $timestamp ?: $this->get_time(),
                    'size' => $size,
                ];
            }
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
                $this->perform_request('DELETE', $backup['id'], '', [], $settings);
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

    public function test_connection(?array $settings = null) {
        $settings = $settings ? $this->merge_settings($settings) : $this->get_settings();
        $this->assert_settings_complete($settings);

        $response = $this->perform_request('GET', '', '', [], $settings, ['restype' => 'container']);
        if (!isset($response['response']['code']) || (int) $response['response']['code'] >= 400) {
            throw new Exception('Azure Blob a renvoyé un statut inattendu.');
        }

        $message = sprintf('Conteneur "%s" sur %s.', $settings['container'], $settings['account_name']);
        $this->store_status([
            'last_result' => 'success',
            'tested_at' => $this->get_time(),
            'message' => $message,
        ]);

        $this->log('Connexion Azure Blob vérifiée avec succès.');

        return true;
    }

    public function create_download_token($object_key, $validity = 900) {
        if (!$this->is_connected()) {
            throw new Exception('Azure Blob Storage n\'est pas configuré.');
        }

        $settings = $this->get_settings();
        $validity = max(60, (int) $validity);
        $expires_at = $this->get_time() + $validity;
        $sas_token = $this->generate_sas_token($settings, $object_key, $expires_at);
        $url = $this->build_blob_url($settings, $object_key);
        $separator = strpos($url, '?') === false ? '?' : '&';

        return [
            'url' => $url . $separator . $sas_token,
            'expires_at' => $expires_at,
            'sas' => $sas_token,
        ];
    }

    private function perform_request($method, $object_key, $body, array $headers, array $settings, array $query = []) {
        $this->assert_settings_complete($settings);

        $method = strtoupper($method);
        $timestamp = $this->get_time();
        $date_header = gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';

        $host = $settings['account_name'] . '.blob.' . $settings['endpoint_suffix'];
        $scheme = $settings['use_https'] ? 'https' : 'http';
        $path = '/' . trim($settings['container'], '/');
        if ($object_key !== '') {
            $path .= '/' . ltrim($this->encode_path($object_key), '/');
        }

        $canonical_query = $this->build_query_string($query);
        $endpoint = $scheme . '://' . $host . $path;
        if ($canonical_query !== '') {
            $endpoint .= '?' . $canonical_query;
        }

        $body_string = (string) $body;

        if (!isset($headers['Content-Length']) && ($method === 'PUT' || $method === 'POST')) {
            $headers['Content-Length'] = (string) strlen($body_string);
        }

        $headers = array_merge([
            'x-ms-date' => $date_header,
            'x-ms-version' => '2023-08-03',
        ], $headers);

        if (!isset($headers['Content-Type']) && ($method === 'PUT' || $method === 'POST')) {
            $headers['Content-Type'] = 'application/octet-stream';
        }

        $string_to_sign = $this->build_string_to_sign($method, $path, $headers, $query, $body_string, $settings['account_name']);
        $signature = $this->sign($string_to_sign, $settings['account_key']);
        $headers['Authorization'] = 'SharedKey ' . $settings['account_name'] . ':' . $signature;

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => apply_filters('bjlg_azure_request_timeout', 90, $method, $object_key),
        ];

        if ($method === 'PUT' || $method === 'POST') {
            $args['body'] = $body_string;
        }

        $response = call_user_func($this->request_handler, $endpoint, $args);

        if (is_wp_error($response)) {
            throw new Exception('Erreur de communication avec Azure Blob : ' . $response->get_error_message());
        }

        $code = isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        if ($code < 200 || $code >= 300) {
            $message = isset($response['response']['message']) ? (string) $response['response']['message'] : '';
            throw new Exception(sprintf('Azure Blob a renvoyé un statut inattendu (%d %s).', $code, $message));
        }

        return $response;
    }

    private function build_object_key($filename, $prefix, $apply_basename = true) {
        $key = $apply_basename ? basename((string) $filename) : (string) $filename;
        $prefix = trim((string) $prefix);

        if ($prefix !== '') {
            $prefix = str_replace('\\', '/', $prefix);
            $prefix = trim($prefix, '/');
            if ($prefix !== '') {
                $key = $prefix . '/' . ltrim($key, '/');
            }
        }

        return trim($key, '/');
    }

    private function build_block_list_xml(array $block_ids) {
        $xml = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<BlockList>';
        foreach ($block_ids as $block_id) {
            $xml .= '<Latest>' . esc_html($block_id) . '</Latest>';
        }
        $xml .= '</BlockList>';

        return $xml;
    }

    private function generate_block_id($part_number) {
        return base64_encode(sprintf('bjlg-block-%010d', $part_number));
    }

    private function build_query_string(array $query) {
        if (empty($query)) {
            return '';
        }

        $pairs = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $single) {
                    $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $single);
                }
            } elseif ($value === '') {
                $pairs[] = rawurlencode((string) $key);
            } else {
                $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
            }
        }

        sort($pairs);

        return implode('&', $pairs);
    }

    private function build_string_to_sign($method, $path, array $headers, array $query, $body, $account_name) {
        $content_length = '';
        if (isset($headers['Content-Length']) && $headers['Content-Length'] !== '0') {
            $content_length = $headers['Content-Length'];
        }

        $content_type = isset($headers['Content-Type']) ? $headers['Content-Type'] : '';
        $content_md5 = isset($headers['Content-MD5']) ? $headers['Content-MD5'] : '';

        $canonical_headers = $this->build_canonical_headers($headers);
        $canonical_resource = $this->build_canonical_resource($account_name, $path, $query);

        $parts = [
            $method,
            '',
            '',
            $content_length,
            $content_md5,
            $content_type,
            '',
            '',
            '',
            '',
            '',
            '',
            $canonical_headers,
            $canonical_resource,
        ];

        return implode("\n", $parts);
    }

    private function build_canonical_headers(array $headers) {
        $canonical = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if (strpos($lower, 'x-ms-') !== 0) {
                continue;
            }

            $trimmed = preg_replace('/\s+/', ' ', is_array($value) ? implode(',', $value) : (string) $value);
            $canonical[$lower] = trim($trimmed);
        }

        ksort($canonical);

        $lines = [];
        foreach ($canonical as $name => $value) {
            $lines[] = $name . ':' . $value;
        }

        return implode("\n", $lines);
    }

    private function build_canonical_resource($account_name, $path, array $query) {
        $resource = '/' . $account_name . $path;

        if (!empty($query)) {
            $pairs = [];
            $lowered = [];
            foreach ($query as $key => $value) {
                $lower = strtolower((string) $key);
                if (!isset($lowered[$lower])) {
                    $lowered[$lower] = [];
                }

                if (is_array($value)) {
                    foreach ($value as $single) {
                        $lowered[$lower][] = (string) $single;
                    }
                } else {
                    $lowered[$lower][] = (string) $value;
                }
            }

            ksort($lowered);

            foreach ($lowered as $key => $values) {
                sort($values);
                $pairs[] = $key . ':' . implode(',', $values);
            }

            $resource .= "\n" . implode("\n", $pairs);
        }

        return $resource;
    }

    private function sign($string_to_sign, $account_key) {
        $decoded_key = base64_decode((string) $account_key, true);
        if ($decoded_key === false) {
            throw new Exception('Clé Azure Blob invalide.');
        }

        return base64_encode(hash_hmac('sha256', $string_to_sign, $decoded_key, true));
    }

    private function generate_sas_token(array $settings, $object_key, $expires_at) {
        $resource_name = $this->build_object_key($object_key, $settings['object_prefix']);
        $resource = sprintf('/blob/%s/%s/%s', $settings['account_name'], trim($settings['container'], '/'), $resource_name);
        $start = gmdate('Y-m-d\TH:i:s\Z', $this->get_time() - 300);
        $expiry = gmdate('Y-m-d\TH:i:s\Z', $expires_at);
        $permissions = 'r';
        $version = '2023-08-03';

        $string_to_sign = implode("\n", [
            $permissions,
            $start,
            $expiry,
            $resource,
            '',
            $version,
            '',
            '',
            '',
            '',
            '',
        ]);

        $signature = $this->sign($string_to_sign, $settings['account_key']);

        $query = [
            'sv' => $version,
            'ss' => 'b',
            'srt' => 'o',
            'sp' => $permissions,
            'se' => $expiry,
            'st' => $start,
            'spr' => $settings['use_https'] ? 'https' : 'http',
            'sig' => $signature,
        ];

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function build_blob_url(array $settings, $object_key) {
        $scheme = $settings['use_https'] ? 'https' : 'http';
        $host = $settings['account_name'] . '.blob.' . $settings['endpoint_suffix'];
        $path = '/' . trim($settings['container'], '/');
        if ($object_key !== '') {
            $path .= '/' . ltrim($this->encode_path($this->build_object_key($object_key, $settings['object_prefix'])), '/');
        }

        return $scheme . '://' . $host . $path;
    }

    private function encode_path($path) {
        $segments = explode('/', (string) $path);
        $encoded = array_map(static function ($segment) {
            return rawurlencode($segment);
        }, $segments);

        return implode('/', $encoded);
    }

    private function get_settings() {
        $stored = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return $this->merge_settings($stored);
    }

    private function merge_settings(array $settings) {
        return array_merge($this->get_default_settings(), $settings);
    }

    private function get_default_settings() {
        return [
            'account_name' => '',
            'account_key' => '',
            'container' => '',
            'object_prefix' => '',
            'endpoint_suffix' => 'core.windows.net',
            'chunk_size_mb' => 4,
            'use_https' => true,
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

    private function select_backups_to_delete(array $backups, int $retain_by_number, int $retain_by_age_days) {
        $to_delete = [];
        $now = $this->get_time();

        if ($retain_by_age_days > 0) {
            $age_limit = $retain_by_age_days * DAY_IN_SECONDS;
            foreach ($backups as $backup) {
                $timestamp = (int) ($backup['timestamp'] ?? 0);
                if ($timestamp > 0 && ($now - $timestamp) > $age_limit) {
                    $to_delete[] = $backup;
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
                $to_delete[] = $backup;
            }
        }

        return $to_delete;
    }

    private function is_backup_filename($name) {
        if (!is_string($name) || $name === '') {
            return false;
        }

        return (bool) preg_match('/\.zip(\.[A-Za-z0-9]+)?$/i', $name);
    }

    private function assert_settings_complete(array $settings) {
        $required = ['account_name', 'account_key', 'container'];
        foreach ($required as $key) {
            if (empty($settings[$key])) {
                throw new Exception(sprintf('Le paramètre Azure Blob "%s" est manquant.', $key));
            }
        }
    }

    private function get_time() {
        return (int) call_user_func($this->time_provider);
    }

    private function log($message) {
        if (class_exists(BJLG_Debug::class)) {
            BJLG_Debug::log($message);
        }
    }
}
