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
 * Destination Backblaze B2 Cloud Storage.
 */
class BJLG_Backblaze_B2 implements BJLG_Destination_Interface {

    private const OPTION_SETTINGS = 'bjlg_backblaze_b2_settings';
    private const OPTION_STATUS = 'bjlg_backblaze_b2_status';

    /** @var callable */
    private $request_handler;

    /** @var callable */
    private $time_provider;

    /** @var array<string, mixed>|null */
    private $auth_cache = null;

    public function __construct(?callable $request_handler = null, ?callable $time_provider = null) {
        $this->request_handler = $request_handler ?: static function ($url, array $args = []) {
            return wp_remote_request($url, $args);
        };
        $this->time_provider = $time_provider ?: static function () {
            return time();
        };
    }

    public function get_id() {
        return 'backblaze_b2';
    }

    public function get_name() {
        return 'Backblaze B2';
    }

    public function is_connected() {
        $settings = $this->get_settings();

        return $settings['enabled']
            && $settings['key_id'] !== ''
            && $settings['application_key'] !== ''
            && $settings['bucket_id'] !== ''
            && $settings['bucket_name'] !== '';
    }

    public function disconnect() {
        $defaults = $this->get_default_settings();
        \bjlg_update_option(self::OPTION_SETTINGS, $defaults);

        if (function_exists('bjlg_delete_option')) {
            \bjlg_delete_option(self::OPTION_STATUS);
        } elseif (function_exists('delete_option')) {
            delete_option(self::OPTION_STATUS);
        } else {
            \bjlg_update_option(self::OPTION_STATUS, []);
        }

        $this->auth_cache = null;
    }

    public function render_settings() {
        $settings = $this->get_settings();
        $status = $this->get_status();
        $is_connected = $this->is_connected();

        echo "<div class='bjlg-destination bjlg-destination--backblaze'>";
        echo "<h4><span class='dashicons dashicons-cloud-upload' aria-hidden='true'></span> Backblaze B2</h4>";
        echo "<form class='bjlg-settings-form bjlg-destination-form' novalidate>";
        echo "<div class='bjlg-settings-feedback notice bjlg-hidden' role='status' aria-live='polite'></div>";
        echo "<p class='description'>Sauvegardez vos archives WordPress sur Backblaze B2 avec upload multipart et jetons d'autorisation automatiques.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Clé d'application ID</th><td><input type='text' name='b2_key_id' value='" . esc_attr($settings['key_id']) . "' class='regular-text' autocomplete='off'></td></tr>";
        echo "<tr><th scope='row'>Clé d'application</th><td><input type='password' name='b2_application_key' value='" . esc_attr($settings['application_key']) . "' class='regular-text' autocomplete='off'></td></tr>";
        echo "<tr><th scope='row'>ID du bucket</th><td><input type='text' name='b2_bucket_id' value='" . esc_attr($settings['bucket_id']) . "' class='regular-text'></td></tr>";
        echo "<tr><th scope='row'>Nom du bucket</th><td><input type='text' name='b2_bucket_name' value='" . esc_attr($settings['bucket_name']) . "' class='regular-text'></td></tr>";
        echo "<tr><th scope='row'>Préfixe d'objet</th><td><input type='text' name='b2_object_prefix' value='" . esc_attr($settings['object_prefix']) . "' class='regular-text' placeholder='backups/'></td></tr>";
        echo "<tr><th scope='row'>Taille des parties (Mo)</th><td><input type='number' min='5' max='100' name='b2_chunk_size' value='" . esc_attr($settings['chunk_size_mb']) . "' class='small-text'> <span class='description'>Définit la taille des morceaux pour les gros fichiers.</span></td></tr>";

        $enabled_attr = $settings['enabled'] ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer Backblaze B2</th><td><label><input type='checkbox' name='b2_enabled' value='true'{$enabled_attr}> Activer l'envoi automatique vers Backblaze B2.</label></td></tr>";
        echo "</table>";

        echo "<div class='notice bjlg-b2-test-feedback bjlg-hidden' role='status' aria-live='polite'></div>";
        echo "<p><button type='button' class='button bjlg-b2-test-connection'>Tester la connexion</button></p>";

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
            echo "<p class='description'><span class='dashicons dashicons-lock' aria-hidden='true'></span> Connexion Backblaze B2 configurée.</p>";
        }

        echo "<p class='submit'><button type='submit' class='button button-primary'>Enregistrer les réglages</button></p>";
        echo "</form>";

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
                throw new Exception('Erreurs Backblaze : ' . implode(' | ', $errors));
            }

            return;
        }

        if (!is_readable($filepath)) {
            throw new Exception('Fichier de sauvegarde introuvable : ' . $filepath);
        }

        $settings = $this->get_settings();
        if (!$this->is_connected()) {
            throw new Exception('Backblaze B2 n\'est pas configuré.');
        }

        $object_key = $this->build_object_key(basename($filepath), $settings['object_prefix']);
        $file_size = filesize($filepath);
        if ($file_size === false) {
            throw new Exception('Impossible de déterminer la taille du fichier à envoyer.');
        }

        $chunk_size = max(5, (int) $settings['chunk_size_mb']) * 1024 * 1024;
        if ($file_size <= $chunk_size) {
            $this->upload_small_file($filepath, $object_key, $settings, $task_id);
            return;
        }

        $this->upload_large_file($filepath, $object_key, $settings, $chunk_size, $task_id);
    }

    public function list_remote_backups() {
        if (!$this->is_connected()) {
            return [];
        }

        $settings = $this->get_settings();
        $auth = $this->authorize();
        $api_url = rtrim($auth['apiUrl'], '/');

        $body = wp_json_encode([
            'bucketId' => $settings['bucket_id'],
            'prefix' => $this->build_object_key('', $settings['object_prefix'], false),
            'maxFileCount' => 1000,
        ]);

        try {
            $response = $this->perform_request(
                'POST',
                $api_url . '/b2api/v2/b2_list_file_names',
                [
                    'Authorization' => $auth['authorizationToken'],
                    'Content-Type' => 'application/json',
                ],
                $body
            );
        } catch (Exception $exception) {
            $this->log('ERREUR Backblaze (listing) : ' . $exception->getMessage());
            return [];
        }

        $data = json_decode((string) $response['body'], true);
        if (!is_array($data) || empty($data['files'])) {
            return [];
        }

        $backups = [];
        foreach ($data['files'] as $file) {
            if (!isset($file['fileName'])) {
                continue;
            }

            $name = (string) $file['fileName'];
            $basename = basename($name);
            if (!$this->is_backup_filename($basename)) {
                continue;
            }

            $timestamp = isset($file['uploadTimestamp']) ? (int) $file['uploadTimestamp'] : 0;
            $size = isset($file['contentLength']) ? (int) $file['contentLength'] : 0;

            $backups[] = [
                'id' => $file['fileId'] ?? $name,
                'name' => $basename,
                'key' => $name,
                'timestamp' => $timestamp > 0 ? (int) floor($timestamp / 1000) : $this->get_time(),
                'size' => $size,
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
        foreach ($to_delete as $backup) {
            try {
                $this->delete_remote_backup($backup);
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
            $outcome['message'] = __('Backblaze B2 n\'est pas configuré.', 'backup-jlg');

            return $outcome;
        }

        $filename = basename((string) $filename);
        if ($filename === '') {
            $outcome['message'] = __('Nom de fichier invalide.', 'backup-jlg');

            return $outcome;
        }

        try {
            $backups = $this->list_remote_backups();
            foreach ($backups as $backup) {
                if (($backup['name'] ?? '') !== $filename) {
                    continue;
                }

                $this->delete_remote_backup($backup);
                if (class_exists(BJLG_Debug::class)) {
                    BJLG_Debug::log(sprintf('Purge distante Backblaze réussie pour %s.', $filename));
                }

                $outcome['success'] = true;

                return $outcome;
            }

            $outcome['message'] = __('Sauvegarde distante introuvable sur Backblaze B2.', 'backup-jlg');
        } catch (Exception $exception) {
            $outcome['message'] = $exception->getMessage();
        }

        return $outcome;
    }

    private const ERROR_USAGE_API_FAILURE = 'B2_USAGE_API_ERROR';
    private const ERROR_USAGE_EMPTY = 'B2_USAGE_API_EMPTY';

    public function get_storage_usage() {
        $defaults = [
            'used_bytes' => null,
            'quota_bytes' => null,
            'free_bytes' => null,
            'latency_ms' => null,
            'errors' => [],
        ];

        if (!$this->is_connected()) {
            return $defaults;
        }

        $settings = $this->get_settings();
        $started_at = microtime(true);

        try {
            $snapshot = $this->fetch_usage_snapshot($settings);
        } catch (Exception $exception) {
            $latency = (int) round((microtime(true) - $started_at) * 1000);

            throw new BJLG_Remote_Storage_Usage_Exception(
                'Backblaze B2 : impossible de récupérer les quotas — ' . $exception->getMessage(),
                self::ERROR_USAGE_API_FAILURE,
                $latency,
                (int) $exception->getCode(),
                $exception
            );
        }

        $latency = (int) round((microtime(true) - $started_at) * 1000);

        if (!is_array($snapshot) || (
            !array_key_exists('used_bytes', $snapshot)
            && !array_key_exists('quota_bytes', $snapshot)
            && !array_key_exists('free_bytes', $snapshot)
        )) {
            throw new BJLG_Remote_Storage_Usage_Exception(
                'Backblaze B2 : la réponse de `b2_get_usage` est vide.',
                self::ERROR_USAGE_EMPTY,
                $latency
            );
        }

        $usage = array_merge($defaults, $snapshot);

        if ($usage['quota_bytes'] !== null && $usage['used_bytes'] !== null && $usage['free_bytes'] === null) {
            $usage['free_bytes'] = max(0, (int) $usage['quota_bytes'] - (int) $usage['used_bytes']);
        }
        if ($usage['quota_bytes'] === null && $usage['used_bytes'] !== null && $usage['free_bytes'] !== null) {
            $usage['quota_bytes'] = max(0, (int) $usage['used_bytes'] + (int) $usage['free_bytes']);
        }

        $usage['latency_ms'] = max(0, $latency);
        $usage['source'] = 'provider';
        $usage['refreshed_at'] = $this->get_time();

        if (class_exists(BJLG_Debug::class)) {
            BJLG_Debug::log(sprintf(
                'Métriques Backblaze B2 : used=%s quota=%s free=%s (latence=%sms)',
                $usage['used_bytes'] !== null ? number_format_i18n((int) $usage['used_bytes']) : 'n/a',
                $usage['quota_bytes'] !== null ? number_format_i18n((int) $usage['quota_bytes']) : 'n/a',
                $usage['free_bytes'] !== null ? number_format_i18n((int) $usage['free_bytes']) : 'n/a',
                $usage['latency_ms']
            ));
        }

        return $usage;
    }

    private function fetch_usage_snapshot(array $settings): ?array {
        $auth = $this->authorize($settings);
        $payload = [];
        if (!empty($settings['bucket_id'])) {
            $payload['bucketId'] = $settings['bucket_id'];
        }
        if (isset($auth['accountId'])) {
            $payload['accountId'] = $auth['accountId'];
        }

        $response = $this->perform_request(
            'POST',
            rtrim($auth['apiUrl'], '/') . '/b2api/v2/b2_get_usage',
            [
                'Authorization' => $auth['authorizationToken'],
                'Content-Type' => 'application/json',
            ],
            wp_json_encode($payload)
        );

        $data = json_decode((string) $response['body'], true);
        if (!is_array($data)) {
            return null;
        }

        $used = null;
        $quota = null;
        $free = null;

        if (isset($data['storage']) && is_array($data['storage'])) {
            $storage = $data['storage'];

            if (isset($storage['buckets']) && is_array($storage['buckets'])) {
                foreach ($storage['buckets'] as $bucket) {
                    if (!is_array($bucket)) {
                        continue;
                    }

                    $bucket_id = isset($bucket['bucketId']) ? (string) $bucket['bucketId'] : '';
                    if ($bucket_id !== '' && $settings['bucket_id'] !== '' && $bucket_id !== $settings['bucket_id']) {
                        continue;
                    }

                    $used = $this->sanitize_positive_int($bucket['currentValue'] ?? $bucket['usage'] ?? null);
                    $quota = $this->sanitize_positive_int($bucket['limit'] ?? $bucket['quota'] ?? null);
                    $free = $this->sanitize_positive_int($bucket['remaining'] ?? null);

                    break;
                }
            }

            if ($used === null && isset($storage['currentValue'])) {
                $used = $this->sanitize_positive_int($storage['currentValue']);
            }

            if ($quota === null && isset($storage['limit'])) {
                $quota = $this->sanitize_positive_int($storage['limit']);
            }

            if ($free === null && isset($storage['remaining'])) {
                $free = $this->sanitize_positive_int($storage['remaining']);
            }
        }

        if ($used === null && isset($data['usageInBytes'])) {
            $used = $this->sanitize_positive_int($data['usageInBytes']);
        }

        if ($quota !== null && $used !== null && $free === null) {
            $free = max(0, (int) $quota - (int) $used);
        }
        if ($quota === null && $used !== null && $free !== null) {
            $quota = max(0, (int) $used + (int) $free);
        }

        $snapshot = null;
        if ($used !== null || $quota !== null || $free !== null) {
            $snapshot = [
                'used_bytes' => $used,
                'quota_bytes' => $quota,
                'free_bytes' => $free,
            ];
        }

        /**
         * Filtre les métriques Backblaze B2 calculées depuis l'API `b2_get_usage`.
         *
         * @param array<string,int|null>|null $snapshot
         * @param array<string,mixed>          $payload
         * @param array<string,mixed>          $response_data
         * @param self                         $destination
         */
        $filtered = apply_filters('bjlg_backblaze_usage_snapshot', $snapshot, $payload, $data, $this);

        if (is_array($filtered)) {
            $snapshot = [
                'used_bytes' => isset($filtered['used_bytes']) ? $this->sanitize_positive_int($filtered['used_bytes']) : $used,
                'quota_bytes' => isset($filtered['quota_bytes']) ? $this->sanitize_positive_int($filtered['quota_bytes']) : $quota,
                'free_bytes' => isset($filtered['free_bytes']) ? $this->sanitize_positive_int($filtered['free_bytes']) : $free,
            ];
        }

        if ($snapshot !== null && ($snapshot['used_bytes'] !== null || $snapshot['quota_bytes'] !== null || $snapshot['free_bytes'] !== null)) {
            if ($snapshot['quota_bytes'] !== null && $snapshot['used_bytes'] !== null && $snapshot['free_bytes'] === null) {
                $snapshot['free_bytes'] = max(0, (int) $snapshot['quota_bytes'] - (int) $snapshot['used_bytes']);
            }

            if (class_exists(BJLG_Debug::class)) {
                BJLG_Debug::log(sprintf(
                    'Métriques Backblaze B2 : used=%s quota=%s free=%s',
                    $snapshot['used_bytes'] !== null ? number_format_i18n((int) $snapshot['used_bytes']) : 'n/a',
                    $snapshot['quota_bytes'] !== null ? number_format_i18n((int) $snapshot['quota_bytes']) : 'n/a',
                    $snapshot['free_bytes'] !== null ? number_format_i18n((int) $snapshot['free_bytes']) : 'n/a'
                ));
            }

            return $snapshot;
        }

        return null;
    }

    private function sanitize_positive_int($value): ?int {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_numeric($value)) {
            $numeric = (float) $value;
            if (is_finite($numeric) && $numeric >= 0) {
                return (int) floor($numeric);
            }
        }

        if (is_string($value) && preg_match('/(-?\d+(?:[\.,]\d+)?)/', $value, $matches)) {
            $numeric = (float) str_replace(',', '.', $matches[1]);
            if (is_finite($numeric) && $numeric >= 0) {
                return (int) floor($numeric);
            }
        }

        return null;
    }

    public function test_connection(?array $settings = null) {
        $settings = $settings ? $this->merge_settings($settings) : $this->get_settings();
        $this->assert_settings_complete($settings);

        $auth = $this->authorize($settings, true);

        if (!isset($auth['apiUrl'], $auth['downloadUrl'])) {
            throw new Exception('Réponse Backblaze inattendue lors de l\'autorisation.');
        }

        $message = sprintf('Bucket "%s" (%s).', $settings['bucket_name'], $settings['bucket_id']);
        $this->store_status([
            'last_result' => 'success',
            'tested_at' => $this->get_time(),
            'message' => $message,
        ]);

        $this->log('Connexion Backblaze B2 vérifiée avec succès.');

        return true;
    }

    public function create_download_token($object_key, $duration = 900) {
        if (!$this->is_connected()) {
            throw new Exception('Backblaze B2 n\'est pas configuré.');
        }

        $settings = $this->get_settings();
        $auth = $this->authorize();

        $duration = max(60, min(86400, (int) $duration));
        $file_name = $this->build_object_key($object_key, $settings['object_prefix']);
        $body = wp_json_encode([
            'bucketId' => $settings['bucket_id'],
            'fileNamePrefix' => $file_name,
            'validDurationInSeconds' => $duration,
        ]);

        $response = $this->perform_request(
            'POST',
            rtrim($auth['apiUrl'], '/') . '/b2api/v2/b2_get_download_authorization',
            [
                'Authorization' => $auth['authorizationToken'],
                'Content-Type' => 'application/json',
            ],
            $body
        );

        $data = json_decode((string) $response['body'], true);
        if (!is_array($data) || empty($data['authorizationToken'])) {
            throw new Exception('Réponse Backblaze inattendue lors de la génération du token.');
        }

        $url = rtrim($auth['downloadUrl'], '/') . '/file/' . rawurlencode($settings['bucket_name']) . '/' . str_replace('%2F', '/', rawurlencode($file_name));

        return [
            'authorization' => $data['authorizationToken'],
            'url' => $url,
            'expires_in' => $duration,
        ];
    }

    private function upload_small_file($filepath, $object_key, array $settings, $task_id) {
        $auth = $this->authorize();
        $upload = $this->get_upload_url($auth, $settings['bucket_id']);
        $contents = file_get_contents($filepath);
        if ($contents === false) {
            throw new Exception('Lecture du fichier impossible pour Backblaze.');
        }

        $sha1 = sha1($contents);
        $headers = [
            'Authorization' => $upload['authorizationToken'],
            'X-Bz-File-Name' => $this->encode_file_name($object_key),
            'Content-Type' => 'application/zip',
            'X-Bz-Content-Sha1' => $sha1,
            'X-Bz-Info-bjlg-task' => (string) $task_id,
        ];

        $response = $this->perform_request('POST', $upload['uploadUrl'], $headers, $contents);
        $code = isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        if ($code < 200 || $code >= 300) {
            throw new Exception('Backblaze a renvoyé un statut inattendu lors de l\'upload.');
        }

        $this->log(sprintf('Sauvegarde "%s" envoyée vers Backblaze B2 (upload simple).', basename($filepath)));
    }

    private function upload_large_file($filepath, $object_key, array $settings, $chunk_size, $task_id) {
        $auth = $this->authorize();
        $api_url = rtrim($auth['apiUrl'], '/');

        $body = wp_json_encode([
            'bucketId' => $settings['bucket_id'],
            'fileName' => $object_key,
            'contentType' => 'application/zip',
            'fileInfo' => [
                'bjlg-task' => (string) $task_id,
            ],
        ]);

        $start_response = $this->perform_request(
            'POST',
            $api_url . '/b2api/v2/b2_start_large_file',
            [
                'Authorization' => $auth['authorizationToken'],
                'Content-Type' => 'application/json',
            ],
            $body
        );

        $file_data = json_decode((string) $start_response['body'], true);
        if (!is_array($file_data) || empty($file_data['fileId'])) {
            throw new Exception('Réponse Backblaze inattendue lors du démarrage du gros fichier.');
        }

        $file_id = $file_data['fileId'];
        $handle = fopen($filepath, 'rb');
        if (!$handle) {
            throw new Exception('Impossible d\'ouvrir le fichier pour Backblaze.');
        }

        $part_number = 1;
        $sha1_parts = [];

        while (!feof($handle)) {
            $data = fread($handle, $chunk_size);
            if ($data === false) {
                fclose($handle);
                throw new Exception('Lecture interrompue pendant l\'upload Backblaze.');
            }

            if ($data === '') {
                break;
            }

            $part_info = $this->get_upload_part_url($auth, $file_id);
            $sha1 = sha1($data);

            $headers = [
                'Authorization' => $part_info['authorizationToken'],
                'X-Bz-Part-Number' => $part_number,
                'Content-Length' => strlen($data),
                'X-Bz-Content-Sha1' => $sha1,
            ];

            $this->perform_request('POST', $part_info['uploadUrl'], $headers, $data);

            $sha1_parts[] = $sha1;
            $part_number++;
        }

        fclose($handle);

        if (empty($sha1_parts)) {
            throw new Exception('Aucune donnée envoyée à Backblaze.');
        }

        $finish_body = wp_json_encode([
            'fileId' => $file_id,
            'partSha1Array' => $sha1_parts,
        ]);

        $this->perform_request(
            'POST',
            $api_url . '/b2api/v2/b2_finish_large_file',
            [
                'Authorization' => $auth['authorizationToken'],
                'Content-Type' => 'application/json',
            ],
            $finish_body
        );

        $this->log(sprintf('Sauvegarde "%s" envoyée vers Backblaze B2 (%d parties).', basename($filepath), count($sha1_parts)));
    }

    private function delete_remote_backup(array $backup) {
        if (empty($backup['id']) || empty($backup['key'])) {
            throw new Exception('Informations de suppression Backblaze manquantes.');
        }

        $auth = $this->authorize();
        $body = wp_json_encode([
            'fileName' => $backup['key'],
            'fileId' => $backup['id'],
        ]);

        $this->perform_request(
            'POST',
            rtrim($auth['apiUrl'], '/') . '/b2api/v2/b2_delete_file_version',
            [
                'Authorization' => $auth['authorizationToken'],
                'Content-Type' => 'application/json',
            ],
            $body
        );
    }

    private function get_upload_url(array $auth, $bucket_id) {
        $response = $this->perform_request(
            'POST',
            rtrim($auth['apiUrl'], '/') . '/b2api/v2/b2_get_upload_url',
            [
                'Authorization' => $auth['authorizationToken'],
                'Content-Type' => 'application/json',
            ],
            wp_json_encode(['bucketId' => $bucket_id])
        );

        $data = json_decode((string) $response['body'], true);
        if (!is_array($data) || empty($data['uploadUrl']) || empty($data['authorizationToken'])) {
            throw new Exception('Réponse Backblaze inattendue lors de la récupération de l\'URL d\'upload.');
        }

        return $data;
    }

    private function get_upload_part_url(array $auth, $file_id) {
        $response = $this->perform_request(
            'POST',
            rtrim($auth['apiUrl'], '/') . '/b2api/v2/b2_get_upload_part_url',
            [
                'Authorization' => $auth['authorizationToken'],
                'Content-Type' => 'application/json',
            ],
            wp_json_encode(['fileId' => $file_id])
        );

        $data = json_decode((string) $response['body'], true);
        if (!is_array($data) || empty($data['uploadUrl']) || empty($data['authorizationToken'])) {
            throw new Exception('Réponse Backblaze inattendue lors de la récupération de l\'URL d\'upload de partie.');
        }

        return $data;
    }

    private function authorize(?array $settings = null, $force_refresh = false) {
        if (!$force_refresh && is_array($this->auth_cache)) {
            $expires_at = $this->auth_cache['expires_at'] ?? 0;
            if ($expires_at > $this->get_time()) {
                return $this->auth_cache;
            }
        }

        $settings = $settings ? $this->merge_settings($settings) : $this->get_settings();
        $this->assert_settings_complete($settings);

        $credentials = base64_encode($settings['key_id'] . ':' . $settings['application_key']);
        $response = $this->perform_request(
            'GET',
            'https://api.backblazeb2.com/b2api/v2/b2_authorize_account',
            [
                'Authorization' => 'Basic ' . $credentials,
            ]
        );

        $data = json_decode((string) $response['body'], true);
        if (!is_array($data) || empty($data['authorizationToken'])) {
            throw new Exception('Autorisation Backblaze échouée.');
        }

        $data['expires_at'] = $this->get_time() + DAY_IN_SECONDS;
        $this->auth_cache = $data;

        return $data;
    }

    private function perform_request($method, $url, array $headers = [], $body = null) {
        $method = strtoupper($method);

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => apply_filters('bjlg_backblaze_request_timeout', 90, $method, $url),
        ];

        if ($body !== null && $method !== 'GET') {
            $args['body'] = $body;
        }

        $response = call_user_func($this->request_handler, $url, $args);

        if (is_wp_error($response)) {
            throw new Exception('Erreur de communication avec Backblaze B2 : ' . $response->get_error_message());
        }

        $code = isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        if ($code < 200 || $code >= 300) {
            $message = isset($response['body']) ? (string) $response['body'] : '';
            throw new Exception(sprintf('Backblaze B2 a renvoyé un statut inattendu (%d): %s', $code, $message));
        }

        return $response;
    }

    private function parse_usage_snapshot($body) {
        $body = trim((string) $body);
        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [];
        }

        $used = $this->find_numeric_value($data, ['usedBytes', 'used_bytes', 'totalBytes']);
        $quota = $this->find_numeric_value($data, ['capacityBytes', 'quota_bytes', 'limit']);
        $free = $this->find_numeric_value($data, ['freeBytes', 'remainingBytes', 'free_bytes']);

        if ($used === null && $quota === null && $free === null) {
            return [];
        }

        return [
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'free_bytes' => $free,
            'source' => 'provider',
        ];
    }

    private function find_numeric_value(array $data, array $keys) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (int) $data[$key];
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->find_numeric_value($value, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function estimate_usage_from_listing(array $settings) {
        try {
            $backups = $this->list_remote_backups();
        } catch (Exception $exception) {
            $this->log('Backblaze B2 : estimation locale impossible — ' . $exception->getMessage());

            return [
                'used_bytes' => null,
                'quota_bytes' => null,
                'free_bytes' => null,
                'source' => 'estimate',
                'refreshed_at' => $this->get_time(),
            ];
        }

        $used = 0;
        foreach ($backups as $backup) {
            $used += isset($backup['size']) ? (int) $backup['size'] : 0;
        }

        return [
            'used_bytes' => $used,
            'quota_bytes' => null,
            'free_bytes' => null,
            'source' => 'estimate',
            'refreshed_at' => $this->get_time(),
        ];
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

    private function encode_file_name($name) {
        return str_replace('%2F', '/', rawurlencode($name));
    }

    private function get_settings() {
        $stored = \bjlg_get_option(self::OPTION_SETTINGS, []);
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
            'key_id' => '',
            'application_key' => '',
            'bucket_id' => '',
            'bucket_name' => '',
            'object_prefix' => '',
            'chunk_size_mb' => 100,
            'enabled' => false,
        ];
    }

    private function get_status() {
        $status = \bjlg_get_option(self::OPTION_STATUS, [
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
        \bjlg_update_option(self::OPTION_STATUS, array_merge($current, $status));
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
        $required = ['key_id', 'application_key', 'bucket_id', 'bucket_name'];
        foreach ($required as $key) {
            if (empty($settings[$key])) {
                throw new Exception(sprintf('Le paramètre Backblaze "%s" est manquant.', $key));
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
