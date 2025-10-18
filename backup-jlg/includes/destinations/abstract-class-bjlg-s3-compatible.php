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
 * Fournit l'ossature commune aux services compatibles S3.
 */
abstract class BJLG_S3_Compatible_Destination implements BJLG_Destination_Interface {

    /** @var callable */
    protected $request_handler;

    /** @var callable */
    protected $time_provider;

    /**
     * @param callable|null $request_handler Permet l'injection d'un client HTTP pour les tests.
     * @param callable|null $time_provider   Permet d'injecter une source de temps pour les tests.
     */
    public function __construct(?callable $request_handler = null, ?callable $time_provider = null) {
        $this->request_handler = $request_handler ?: static function ($url, array $args = []) {
            return wp_remote_request($url, $args);
        };
        $this->time_provider = $time_provider ?: static function () {
            return time();
        };
    }

    /**
     * Identifiant unique de la destination (ex: "wasabi").
     *
     * @return string
     */
    abstract protected function get_service_id();

    /**
     * Nom lisible de la destination.
     *
     * @return string
     */
    abstract protected function get_service_name();

    /**
     * Nom de l'option qui stocke les réglages.
     *
     * @return string
     */
    abstract protected function get_settings_option_name();

    /**
     * Nom de l'option qui stocke l'état des tests de connexion.
     *
     * @return string
     */
    abstract protected function get_status_option_name();

    /**
     * Retourne l'hôte à contacter pour l'API S3.
     *
     * @param array<string, mixed> $settings
     * @return string
     */
    abstract protected function build_host(array $settings);

    /**
     * Texte utilisé dans les logs pour identifier le service.
     *
     * @return string
     */
    protected function get_log_label() {
        return $this->get_service_name();
    }

    /**
     * Permet d'ajouter des en-têtes spécifiques au service.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $settings
     * @return array<string, string>
     */
    protected function filter_headers(array $headers, array $settings) {
        return $headers;
    }

    /**
     * Valeurs par défaut des réglages.
     *
     * @return array<string, mixed>
     */
    protected function get_default_settings() {
        return [
            'access_key' => '',
            'secret_key' => '',
            'region' => '',
            'bucket' => '',
            'object_prefix' => '',
            'server_side_encryption' => '',
            'kms_key_id' => '',
            'enabled' => false,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function get_id() {
        return $this->get_service_id();
    }

    /**
     * {@inheritdoc}
     */
    public function get_name() {
        return $this->get_service_name();
    }

    /**
     * {@inheritdoc}
     */
    public function is_connected() {
        $settings = $this->get_settings();

        return $settings['enabled']
            && $settings['access_key'] !== ''
            && $settings['secret_key'] !== ''
            && $settings['region'] !== ''
            && $settings['bucket'] !== '';
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect() {
        bjlg_update_option($this->get_settings_option_name(), $this->get_default_settings());

        if (function_exists('delete_option')) {
            bjlg_delete_option($this->get_status_option_name());
        } else {
            bjlg_update_option($this->get_status_option_name(), []);
        }
    }

    /**
     * {@inheritdoc}
     */
    abstract public function render_settings();

    /**
     * {@inheritdoc}
     */
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
                throw new Exception($this->get_log_label() . ' : ' . implode(' | ', $errors));
            }

            return;
        }

        if (!is_readable($filepath)) {
            throw new Exception('Fichier de sauvegarde introuvable : ' . $filepath);
        }

        $settings = $this->get_settings();
        if (!$this->is_connected()) {
            throw new Exception($this->get_log_label() . ' n\'est pas configuré.');
        }

        $object_key = $this->build_object_key(basename($filepath), $settings['object_prefix']);
        $contents = file_get_contents($filepath);
        if ($contents === false) {
            throw new Exception('Impossible de lire le fichier de sauvegarde à envoyer.');
        }

        $file_size = filesize($filepath);
        if ($file_size === false) {
            throw new Exception('Impossible de déterminer la taille du fichier à envoyer.');
        }

        $headers = [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) $file_size,
            'x-amz-meta-bjlg-task' => (string) $task_id,
        ];

        if ($settings['server_side_encryption'] !== '') {
            $headers['x-amz-server-side-encryption'] = $settings['server_side_encryption'];

            if ($settings['server_side_encryption'] === 'aws:kms' && $settings['kms_key_id'] !== '') {
                $headers['x-amz-server-side-encryption-aws-kms-key-id'] = $settings['kms_key_id'];
            }
        }

        $headers = $this->filter_headers($headers, $settings);

        $this->log(sprintf('Envoi de "%s" vers %s (%s).', basename($filepath), $this->get_log_label(), $object_key));

        try {
            $this->perform_request('PUT', $object_key, $contents, $headers, $settings);
        } catch (Exception $exception) {
            $this->log('ERREUR ' . $this->get_log_label() . ' : ' . $exception->getMessage());
            throw $exception;
        }

        $this->log(sprintf('Sauvegarde "%s" envoyée sur %s (%s).', basename($filepath), $this->get_log_label(), $object_key));
    }

    /**
     * {@inheritdoc}
     */
    public function list_remote_backups() {
        if (!$this->is_connected()) {
            return [];
        }

        $settings = $this->get_settings();

        try {
            return $this->fetch_remote_backups($settings);
        } catch (Exception $exception) {
            $this->log('ERREUR ' . $this->get_log_label() . ' (listing) : ' . $exception->getMessage());
            return [];
        }
    }

    /**
     * {@inheritdoc}
     */
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

        $settings = $this->get_settings();

        try {
            $backups = $this->fetch_remote_backups($settings);
        } catch (Exception $exception) {
            $result['errors'][] = $exception->getMessage();
            return $result;
        }

        $result['inspected'] = count($backups);

        if (empty($backups)) {
            return $result;
        }

        $to_delete = $this->select_backups_to_delete($backups, $retain_by_number, $retain_by_age_days);

        foreach ($to_delete as $backup) {
            try {
                $this->delete_remote_backup($settings, $backup);
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

    /**
     * @param array<string, mixed> $settings
     * @return array<int, array<string, mixed>>
     */
    protected function fetch_remote_backups(array $settings) {
        $backups = [];

        $prefix = trim((string) $settings['object_prefix']);
        if ($prefix !== '') {
            $prefix = str_replace('\\', '/', $prefix);
            $prefix = trim($prefix, '/');
        }

        $base_query = ['list-type' => '2'];
        if ($prefix !== '') {
            $base_query['prefix'] = $prefix . '/';
        }

        $continuation = null;

        do {
            $query = $base_query;
            if ($continuation !== null && $continuation !== '') {
                $query['continuation-token'] = $continuation;
            }

            $response = $this->perform_request('GET', '', '', [], $settings, $query);
            $body = isset($response['body']) ? (string) $response['body'] : '';

            if ($body === '') {
                break;
            }

            $xml = @simplexml_load_string($body);
            if ($xml === false) {
                throw new Exception('Réponse S3 invalide lors du listing.');
            }

            if (isset($xml->Contents)) {
                foreach ($xml->Contents as $content) {
                    $key = (string) $content->Key;
                    if ($key === '') {
                        continue;
                    }

                    $name = basename($key);
                    if (!$this->is_backup_filename($name)) {
                        continue;
                    }

                    $timestamp = strtotime((string) $content->LastModified);
                    if (!is_int($timestamp) || $timestamp <= 0) {
                        $timestamp = $this->get_time();
                    }

                    $size = isset($content->Size) ? (int) $content->Size : 0;

                    $backups[] = [
                        'id' => $key,
                        'key' => $key,
                        'name' => $name,
                        'timestamp' => $timestamp,
                        'size' => $size,
                    ];
                }
            }

            $is_truncated = isset($xml->IsTruncated) && (string) $xml->IsTruncated === 'true';
            if ($is_truncated && isset($xml->NextContinuationToken)) {
                $continuation = (string) $xml->NextContinuationToken;
            } else {
                $continuation = null;
            }
        } while ($continuation !== null && $continuation !== '');

        return $backups;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $backup
     * @return void
     * @throws Exception
     */
    protected function delete_remote_backup(array $settings, array $backup) {
        $key = '';
        if (!empty($backup['key'])) {
            $key = (string) $backup['key'];
        } elseif (!empty($backup['id'])) {
            $key = (string) $backup['id'];
        }

        if ($key === '') {
            throw new Exception('Clé de sauvegarde distante manquante pour la suppression.');
        }

        $this->perform_request('DELETE', $key, '', [], $settings);
        $this->log(sprintf('Sauvegarde distante supprimée sur %s : %s', $this->get_log_label(), $key));
    }

    /**
     * @param array<int, array<string, mixed>> $backups
     * @return array<int, array<string, mixed>>
     */
    protected function select_backups_to_delete(array $backups, int $retain_by_number, int $retain_by_age_days) {
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

    /**
     * @param array<string, mixed> $backup
     * @return string
     */
    protected function get_backup_identifier(array $backup) {
        foreach (['key', 'id', 'name'] as $key) {
            if (!empty($backup[$key])) {
                return (string) $backup[$key];
            }
        }

        return sha1(json_encode($backup));
    }

    /**
     * @param string $method
     * @param string $object_key
     * @param string $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     * @throws Exception
     */
    protected function perform_request($method, $object_key, $body, array $headers, array $settings, array $query = []) {
        $this->assert_settings_complete($settings);

        $timestamp = $this->get_time();
        $amz_date = gmdate('Ymd\THis\Z', $timestamp);
        $date_stamp = gmdate('Ymd', $timestamp);

        $host = $this->build_host($settings);
        $canonical_uri = '/' . ltrim($this->encode_uri($object_key), '/');
        if ($object_key === '') {
            $canonical_uri = '/';
        }

        $canonical_query = $this->build_canonical_query_string($query);

        $endpoint = 'https://' . $host . $canonical_uri;
        if ($canonical_query !== '') {
            $endpoint .= '?' . $canonical_query;
        }

        $payload_hash = hash('sha256', (string) $body);

        $headers = array_merge([
            'Host' => $host,
            'X-Amz-Date' => $amz_date,
            'X-Amz-Content-Sha256' => $payload_hash,
        ], $headers);

        $sorted_headers = [];
        foreach ($headers as $name => $value) {
            $sorted_headers[strtolower($name)] = $this->normalize_header_value($value);
        }
        ksort($sorted_headers);

        $canonical_headers = '';
        foreach ($sorted_headers as $name => $value) {
            $canonical_headers .= $name . ':' . $value . "\n";
        }

        $signed_headers = implode(';', array_keys($sorted_headers));
        $canonical_request = implode("\n", [
            strtoupper($method),
            $canonical_uri,
            $canonical_query,
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ]);

        $credential_scope = $date_stamp . '/' . $settings['region'] . '/s3/aws4_request';
        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amz_date,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);

        $signing_key = $this->get_signing_key($settings['secret_key'], $date_stamp, $settings['region']);
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $settings['access_key'],
            $credential_scope,
            $signed_headers,
            $signature
        );

        $final_headers = $headers;
        $final_headers['Authorization'] = $authorization;

        $args = [
            'method'  => strtoupper($method),
            'headers' => $final_headers,
            'timeout' => apply_filters('bjlg_s3_compatible_request_timeout', 60, $method, $object_key, $this->get_service_id()),
        ];

        if ($method === 'PUT' || $method === 'POST') {
            $args['body'] = $body;
        }

        $response = call_user_func($this->request_handler, $endpoint, $args);

        if (is_wp_error($response)) {
            throw new Exception('Erreur de communication avec ' . $this->get_log_label() . ' : ' . $response->get_error_message());
        }

        $status_code = isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        if ($status_code < 200 || $status_code >= 300) {
            $message = isset($response['response']['message']) ? $response['response']['message'] : '';
            throw new Exception(sprintf('%s a renvoyé un statut inattendu (%d %s).', $this->get_log_label(), $status_code, $message));
        }

        return $response;
    }

    /**
     * @param string $filename
     * @param string $prefix
     * @param bool   $apply_basename
     * @return string
     */
    protected function build_object_key($filename, $prefix, $apply_basename = true) {
        $key = $apply_basename ? basename((string) $filename) : (string) $filename;
        $prefix = trim((string) $prefix);

        if ($prefix !== '') {
            $prefix = str_replace('\\', '/', $prefix);
            $prefix = trim($prefix, '/');
            if ($prefix !== '' && strpos($key, $prefix . '/') !== 0) {
                $key = $prefix . '/' . ltrim($key, '/');
            }
        }

        return trim($key, '/');
    }

    /**
     * @param array<string, mixed> $query
     * @return string
     */
    protected function build_canonical_query_string(array $query) {
        if (empty($query)) {
            return '';
        }

        $pairs = [];

        foreach ($query as $key => $value) {
            $encoded_key = rawurlencode((string) $key);

            if (is_array($value)) {
                $values = $value;
                sort($values);
                foreach ($values as $single) {
                    $pairs[] = $encoded_key . '=' . rawurlencode((string) $single);
                }
            } else {
                $pairs[] = $encoded_key . '=' . rawurlencode((string) $value);
            }
        }

        sort($pairs);

        return implode('&', $pairs);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    protected function get_settings() {
        $stored = bjlg_get_option($this->get_settings_option_name(), []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return $this->merge_settings($stored);
    }

    /**
     * {@inheritdoc}
     */
    public function delete_remote_backup_by_name($filename) {
        $result = [
            'success' => false,
            'message' => '',
        ];

        $filename = basename((string) $filename);
        if ($filename === '') {
            $result['message'] = __('Nom de fichier invalide.', 'backup-jlg');

            return $result;
        }

        if (!$this->is_connected()) {
            $result['message'] = sprintf(__('%s n\'est pas configuré.', 'backup-jlg'), $this->get_service_name());

            return $result;
        }

        $settings = $this->get_settings();
        $object_key = $this->build_object_key($filename, $settings['object_prefix']);

        try {
            $this->perform_request('DELETE', $object_key, '', [], $settings);
            if (class_exists(BJLG_Debug::class)) {
                BJLG_Debug::log(sprintf('Purge distante : %s supprimé sur %s.', $object_key, $this->get_log_label()));
            }

            $result['success'] = true;
        } catch (Exception $exception) {
            $result['message'] = $exception->getMessage();
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
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
                BJLG_Debug::log(sprintf('Impossible de récupérer les métriques distantes %s : %s', $this->get_log_label(), $exception->getMessage()));
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

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    protected function merge_settings(array $settings) {
        return array_merge($this->get_default_settings(), $settings);
    }

    /**
     * @return array<string, mixed>
     */
    protected function get_status() {
        $status = bjlg_get_option($this->get_status_option_name(), [
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

    /**
     * @param array<string, mixed> $status
     * @return void
     */
    protected function store_status(array $status) {
        $current = $this->get_status();
        bjlg_update_option($this->get_status_option_name(), array_merge($current, $status));
    }

    /**
     * @param mixed $value
     * @return string
     */
    protected function normalize_header_value($value) {
        $value = is_array($value) ? implode(',', $value) : (string) $value;
        $value = preg_replace('/\s+/', ' ', trim($value));

        return $value;
    }

    /**
     * @param string $secret_key
     * @param string $date_stamp
     * @param string $region
     * @return string
     */
    protected function get_signing_key($secret_key, $date_stamp, $region) {
        $k_date = hash_hmac('sha256', $date_stamp, 'AWS4' . $secret_key, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);

        return hash_hmac('sha256', 'aws4_request', $k_service, true);
    }

    /**
     * @param string $object_key
     * @return string
     */
    protected function encode_uri($object_key) {
        if ($object_key === '' || $object_key === null) {
            return '';
        }

        $segments = explode('/', $object_key);
        $encoded = array_map(static function ($segment) {
            return str_replace('%2B', '+', rawurlencode($segment));
        }, $segments);

        return implode('/', $encoded);
    }

    /**
     * @return int
     */
    protected function get_time() {
        return (int) call_user_func($this->time_provider);
    }

    /**
     * @param array<string, mixed> $settings
     * @return void
     * @throws Exception
     */
    protected function assert_settings_complete(array $settings) {
        $required = ['access_key', 'secret_key', 'region', 'bucket'];
        foreach ($required as $key) {
            if (empty($settings[$key])) {
                throw new Exception(sprintf('Le paramètre %s "%s" est manquant.', $this->get_log_label(), $key));
            }
        }
    }

    /**
     * @param string $name
     * @return bool
     */
    protected function is_backup_filename($name) {
        if (!is_string($name) || $name === '') {
            return false;
        }

        return (bool) preg_match('/\.zip(\.[A-Za-z0-9]+)?$/i', $name);
    }

    /**
     * @param string $message
     * @return void
     */
    protected function log($message) {
        if (class_exists(BJLG_Debug::class)) {
            BJLG_Debug::log($message);
        }
    }
}
