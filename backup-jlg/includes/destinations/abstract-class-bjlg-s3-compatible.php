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
 * Classe de base pour les services compatibles Amazon S3.
 */
abstract class BJLG_Abstract_S3_Compatible implements BJLG_Destination_Interface {

    /** @var callable */
    protected $request_handler;

    /** @var callable */
    protected $time_provider;

    /**
     * @param callable|null $request_handler Gestionnaire HTTP (tests).
     * @param callable|null $time_provider   Source de temps (tests).
     */
    public function __construct(?callable $request_handler = null, ?callable $time_provider = null) {
        $this->request_handler = $request_handler ?: static function ($url, array $args = []) {
            if (!function_exists('wp_remote_request')) {
                throw new Exception('wp_remote_request() est indisponible.');
            }

            return wp_remote_request($url, $args);
        };

        $this->time_provider = $time_provider ?: static function () {
            return time();
        };
    }

    /**
     * Identifiant de l'option contenant la configuration.
     */
    abstract protected function get_settings_option_name(): string;

    /**
     * Option qui stocke le statut de connexion.
     */
    abstract protected function get_status_option_name(): string;

    /**
     * Libellé lisible du service.
     */
    abstract protected function get_service_label(): string;

    /**
     * Retourne l'hôte utilisé pour signer les requêtes.
     */
    protected function build_host(array $settings): string {
        $bucket = (string) ($settings['bucket'] ?? '');
        $region = trim((string) ($settings['region'] ?? ''));
        $endpoint = trim((string) ($settings['endpoint'] ?? ''));

        if ($endpoint !== '') {
            $endpoint = preg_replace('#^https?://#', '', $endpoint);
            $endpoint = trim((string) $endpoint, '/');

            if ($this->use_path_style($settings)) {
                return $endpoint;
            }

            if (strpos($endpoint, $bucket . '.') === 0) {
                return $endpoint;
            }

            return $bucket . '.' . ltrim($endpoint, '.');
        }

        if ($region === '') {
            $region = 'us-east-1';
        }

        if ($region === 'us-east-1') {
            return $bucket . '.s3.amazonaws.com';
        }

        return $bucket . '.s3.' . $region . '.amazonaws.com';
    }

    /**
     * Détermine le schéma de connexion.
     */
    protected function get_scheme(array $settings): string {
        $scheme = isset($settings['endpoint_scheme']) ? strtolower((string) $settings['endpoint_scheme']) : 'https';
        if (!in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }

        return $scheme;
    }

    /**
     * Active le mode path-style si nécessaire.
     */
    protected function use_path_style(array $settings): bool {
        return !empty($settings['use_path_style_endpoint']);
    }

    /**
     * Paramètres par défaut partagés.
     */
    protected function get_default_settings(): array {
        return [
            'access_key' => '',
            'secret_key' => '',
            'region' => '',
            'bucket' => '',
            'endpoint' => '',
            'endpoint_scheme' => 'https',
            'object_prefix' => '',
            'use_path_style_endpoint' => false,
            'multipart_threshold_mb' => 128,
            'multipart_chunk_mb' => 16,
            'enabled' => false,
        ];
    }

    public function is_connected() {
        $settings = $this->get_settings();
        $required = ['access_key', 'secret_key', 'bucket'];

        foreach ($required as $key) {
            if (empty($settings[$key])) {
                return false;
            }
        }

        if (!$this->use_path_style($settings) && empty($settings['region']) && empty($settings['endpoint'])) {
            return false;
        }

        return !empty($settings['enabled']);
    }

    public function disconnect() {
        update_option($this->get_settings_option_name(), $this->get_default_settings());

        $status_option = $this->get_status_option_name();
        if ($status_option !== '') {
            if (function_exists('delete_option')) {
                delete_option($status_option);
            } else {
                update_option($status_option, []);
            }
        }
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
                throw new Exception($this->get_service_label() . ' : ' . implode(' | ', $errors));
            }

            return;
        }

        if (!is_readable($filepath)) {
            throw new Exception('Fichier de sauvegarde introuvable : ' . $filepath);
        }

        $settings = $this->get_settings();
        if (!$this->is_connected()) {
            throw new Exception(sprintf('%s n\'est pas configuré.', $this->get_service_label()));
        }

        $object_key = $this->build_object_key(basename($filepath), $settings['object_prefix']);
        $file_size = filesize($filepath);
        if ($file_size === false) {
            throw new Exception('Impossible de déterminer la taille du fichier.');
        }

        $headers = array_merge(
            [
                'Content-Type' => 'application/zip',
                'x-amz-meta-bjlg-task' => (string) $task_id,
            ],
            $this->get_extra_upload_headers($settings)
        );

        $this->log(sprintf('Envoi de "%s" vers %s (%s).', basename($filepath), $this->get_service_label(), $object_key));

        if ($this->should_use_multipart($file_size, $settings)) {
            $this->upload_multipart($filepath, $object_key, $headers, $settings, $file_size);
        } else {
            $body = file_get_contents($filepath);
            if ($body === false) {
                throw new Exception('Lecture du fichier impossible.');
            }

            $headers['Content-Length'] = (string) strlen($body);
            $this->perform_request('PUT', $object_key, $body, $headers, $settings);
        }

        $this->log(sprintf('Sauvegarde "%s" envoyée sur %s.', basename($filepath), $this->get_service_label()));
    }

    public function list_remote_backups() {
        if (!$this->is_connected()) {
            return [];
        }

        $settings = $this->get_settings();

        try {
            return $this->fetch_remote_backups($settings);
        } catch (Exception $exception) {
            $this->log(sprintf('Erreur %s (listing) : %s', $this->get_service_label(), $exception->getMessage()));
            return [];
        }
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
     * Test de connexion en effectuant une requête HEAD.
     */
    public function test_connection(?array $settings = null) {
        $settings = $settings ? $this->merge_settings($settings) : $this->get_settings();
        $this->assert_settings_complete($settings);

        $this->perform_request('HEAD', '', '', [], $settings);

        $message = sprintf('Bucket "%s" prêt dans la région %s.', $settings['bucket'], $settings['region']);
        $this->store_status([
            'last_result' => 'success',
            'tested_at' => $this->get_time(),
            'message' => $message,
        ]);

        $this->log(sprintf('Connexion %s vérifiée.', $this->get_service_label()));

        return true;
    }

    /**
     * En-têtes supplémentaires lors d'un upload.
     */
    protected function get_extra_upload_headers(array $settings): array {
        return [];
    }

    protected function should_use_multipart(int $file_size, array $settings): bool {
        $threshold = $this->get_multipart_threshold_bytes($settings);
        $chunk = $this->get_multipart_chunk_size_bytes($settings);

        return $file_size >= $threshold && $chunk > 0;
    }

    protected function get_multipart_threshold_bytes(array $settings): int {
        $value = isset($settings['multipart_threshold_mb']) ? (int) $settings['multipart_threshold_mb'] : 128;
        if ($value <= 0) {
            $value = 128;
        }

        return $value * 1024 * 1024;
    }

    protected function get_multipart_chunk_size_bytes(array $settings): int {
        $value = isset($settings['multipart_chunk_mb']) ? (int) $settings['multipart_chunk_mb'] : 16;
        $value = max(5, $value);

        return $value * 1024 * 1024;
    }

    protected function upload_multipart(string $filepath, string $object_key, array $headers, array $settings, int $file_size): void {
        $upload_id = null;
        $chunk_size = $this->get_multipart_chunk_size_bytes($settings);

        $body_headers = $headers;
        unset($body_headers['Content-Length']);

        try {
            $upload_id = $this->initiate_multipart_upload($object_key, $body_headers, $settings);
            $parts = [];

            $handle = fopen($filepath, 'rb');
            if ($handle === false) {
                throw new Exception('Impossible d\'ouvrir la sauvegarde pour un upload multipart.');
            }

            $part_number = 1;
            while (!feof($handle)) {
                $chunk = fread($handle, $chunk_size);
                if ($chunk === false) {
                    fclose($handle);
                    throw new Exception('Lecture de bloc impossible lors du multipart upload.');
                }

                if ($chunk === '') {
                    break;
                }

                $response = $this->upload_part($object_key, $upload_id, $part_number, $chunk, $headers, $settings);
                $etag = $this->extract_header($response, 'etag');
                if ($etag === null || $etag === '') {
                    $etag = md5($chunk);
                }

                $parts[] = [
                    'PartNumber' => $part_number,
                    'ETag' => $etag,
                ];

                $part_number++;
            }

            if (is_resource($handle)) {
                fclose($handle);
            }

            if (empty($parts)) {
                throw new Exception('Aucune partie envoyée lors du multipart upload.');
            }

            $this->complete_multipart_upload($object_key, $upload_id, $parts, $settings);
        } catch (Exception $exception) {
            if ($upload_id !== null) {
                $this->abort_multipart_upload($object_key, $upload_id, $settings);
            }

            throw $exception;
        }
    }

    protected function initiate_multipart_upload(string $object_key, array $headers, array $settings): string {
        $response = $this->perform_request('POST', $object_key, '', $headers, $settings, ['uploads' => '']);
        $body = isset($response['body']) ? (string) $response['body'] : '';
        if ($body === '') {
            throw new Exception('Réponse vide lors de l\'initialisation du multipart upload.');
        }

        $xml = @simplexml_load_string($body);
        if ($xml === false || !isset($xml->UploadId)) {
            throw new Exception('Réponse invalide lors de l\'initialisation du multipart upload.');
        }

        return (string) $xml->UploadId;
    }

    protected function upload_part(string $object_key, string $upload_id, int $part_number, string $body, array $headers, array $settings) {
        $headers['Content-Length'] = (string) strlen($body);

        return $this->perform_request(
            'PUT',
            $object_key,
            $body,
            $headers,
            $settings,
            [
                'partNumber' => (string) $part_number,
                'uploadId' => $upload_id,
            ]
        );
    }

    protected function complete_multipart_upload(string $object_key, string $upload_id, array $parts, array $settings): void {
        $xml = '<CompleteMultipartUpload>';
        foreach ($parts as $part) {
            $xml .= '<Part><PartNumber>' . intval($part['PartNumber']) . '</PartNumber><ETag>' . htmlspecialchars((string) $part['ETag']) . '</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';

        $headers = [
            'Content-Type' => 'application/xml',
            'Content-Length' => (string) strlen($xml),
        ];

        $this->perform_request('POST', $object_key, $xml, $headers, $settings, ['uploadId' => $upload_id]);
    }

    protected function abort_multipart_upload(string $object_key, string $upload_id, array $settings): void {
        try {
            $this->perform_request('DELETE', $object_key, '', [], $settings, ['uploadId' => $upload_id]);
        } catch (Exception $ignored) {
            $this->log(sprintf('Annulation multipart échouée pour %s : %s', $object_key, $ignored->getMessage()));
        }
    }

    protected function fetch_remote_backups(array $settings): array {
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
                throw new Exception('Réponse incompatible lors du listing.');
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

    protected function delete_remote_backup(array $settings, array $backup): void {
        $key = '';
        if (!empty($backup['key'])) {
            $key = (string) $backup['key'];
        } elseif (!empty($backup['id'])) {
            $key = (string) $backup['id'];
        }

        if ($key === '') {
            throw new Exception(sprintf('Clé manquante pour la suppression sur %s.', $this->get_service_label()));
        }

        $this->perform_request('DELETE', $key, '', [], $settings);
        $this->log(sprintf('Sauvegarde supprimée sur %s : %s', $this->get_service_label(), $key));
    }

    protected function select_backups_to_delete(array $backups, int $retain_by_number, int $retain_by_age_days): array {
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

    protected function get_backup_identifier(array $backup): string {
        foreach (['key', 'id', 'name'] as $key) {
            if (!empty($backup[$key])) {
                return (string) $backup[$key];
            }
        }

        return sha1(json_encode($backup));
    }

    protected function perform_request($method, $object_key, $body, array $headers, array $settings, array $query = []) {
        $this->assert_settings_complete($settings);

        $timestamp = $this->get_time();
        $amz_date = gmdate('Ymd\THis\Z', $timestamp);
        $date_stamp = gmdate('Ymd', $timestamp);

        $bucket = (string) $settings['bucket'];
        $region = (string) $settings['region'];
        $host = $this->build_host($settings);
        $scheme = $this->get_scheme($settings);

        $use_path = $this->use_path_style($settings);
        $bucket_path = $use_path ? '/' . ltrim($bucket, '/') : '';
        $encoded_key = $this->encode_uri($object_key);
        $canonical_uri = $bucket_path;
        if ($encoded_key !== '') {
            $canonical_uri .= '/' . ltrim($encoded_key, '/');
        }

        if ($canonical_uri === '') {
            $canonical_uri = '/';
        }

        $canonical_query = $this->build_canonical_query_string($query);

        $endpoint = $scheme . '://' . $host . $canonical_uri;
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

        $credential_scope = $date_stamp . '/' . $region . '/s3/aws4_request';
        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amz_date,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);

        $signing_key = $this->get_signing_key($settings['secret_key'], $date_stamp, $region);
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
            'method' => strtoupper($method),
            'headers' => $final_headers,
            'timeout' => apply_filters('bjlg_s3_request_timeout', 60, $method, $object_key),
        ];

        if ($method === 'PUT' || $method === 'POST') {
            $args['body'] = $body;
        }

        $response = call_user_func($this->request_handler, $endpoint, $args);

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            throw new Exception(sprintf('Erreur de communication avec %s : %s', $this->get_service_label(), $response->get_error_message()));
        }

        $status_code = isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        if ($status_code < 200 || $status_code >= 300) {
            $message = isset($response['response']['message']) ? $response['response']['message'] : '';
            throw new Exception(sprintf('%s a renvoyé un statut inattendu (%d %s).', $this->get_service_label(), $status_code, $message));
        }

        return $response;
    }

    protected function build_object_key($filename, $prefix, $apply_basename = true): string {
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

    protected function build_canonical_query_string(array $query): string {
        if (empty($query)) {
            return '';
        }

        $pairs = [];
        foreach ($query as $key => $value) {
            $encoded_key = rawurlencode((string) $key);

            if ($value === '') {
                $pairs[] = $encoded_key . '=';
                continue;
            }

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

    protected function normalize_header_value($value): string {
        $value = is_array($value) ? implode(',', $value) : (string) $value;

        return preg_replace('/\s+/', ' ', trim($value));
    }

    protected function get_signing_key($secret_key, $date_stamp, $region) {
        $k_date = hash_hmac('sha256', $date_stamp, 'AWS4' . $secret_key, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);

        return hash_hmac('sha256', 'aws4_request', $k_service, true);
    }

    protected function encode_uri($object_key): string {
        if ($object_key === '' || $object_key === null) {
            return '';
        }

        $segments = explode('/', $object_key);
        $encoded = array_map(static function ($segment) {
            return str_replace('%2B', '+', rawurlencode($segment));
        }, $segments);

        return implode('/', $encoded);
    }

    protected function get_settings(): array {
        $stored = get_option($this->get_settings_option_name(), []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return $this->merge_settings($stored);
    }

    protected function merge_settings(array $settings): array {
        return array_merge($this->get_default_settings(), $settings);
    }

    protected function assert_settings_complete(array $settings): void {
        foreach (['access_key', 'secret_key', 'bucket'] as $key) {
            if (empty($settings[$key])) {
                throw new Exception(sprintf('Le paramètre %s "%s" est manquant.', $this->get_service_label(), $key));
            }
        }

        if (empty($settings['region']) && empty($settings['endpoint'])) {
            throw new Exception(sprintf('Veuillez renseigner la région ou le point de terminaison pour %s.', $this->get_service_label()));
        }
    }

    protected function get_status(): array {
        $status_option = $this->get_status_option_name();
        if ($status_option === '') {
            return [
                'last_result' => null,
                'tested_at' => 0,
                'message' => '',
            ];
        }

        $status = get_option($status_option, []);
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

    protected function store_status(array $status): void {
        $status_option = $this->get_status_option_name();
        if ($status_option === '') {
            return;
        }

        $current = $this->get_status();
        update_option($status_option, array_merge($current, $status));
    }

    protected function extract_header($response, string $name): ?string {
        if (!isset($response['headers'])) {
            return null;
        }

        $headers = $response['headers'];
        if (is_array($headers)) {
            foreach ($headers as $header_name => $value) {
                if (strcasecmp((string) $header_name, $name) === 0) {
                    return is_array($value) ? (string) end($value) : (string) $value;
                }
            }
        } elseif (is_object($headers) && method_exists($headers, 'offsetGet')) {
            $value = $headers->offsetGet($name);
            if ($value !== null) {
                return (string) $value;
            }
        }

        return null;
    }

    protected function is_backup_filename($name): bool {
        if (!is_string($name) || $name === '') {
            return false;
        }

        return (bool) preg_match('/\.zip(\.[A-Za-z0-9]+)?$/i', $name);
    }

    protected function get_time(): int {
        return (int) call_user_func($this->time_provider);
    }

    protected function log($message): void {
        if (class_exists(BJLG_Debug::class)) {
            BJLG_Debug::log($message);
        }
    }
}

