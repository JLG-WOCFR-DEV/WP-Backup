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
 * Destination Amazon S3 pour l'envoi et la suppression de sauvegardes.
 */
class BJLG_AWS_S3 implements BJLG_Destination_Interface {

    private const OPTION_SETTINGS = 'bjlg_s3_settings';
    private const OPTION_STATUS = 'bjlg_s3_status';

    /** @var callable */
    private $request_handler;

    /** @var callable */
    private $time_provider;

    /**
     * @param callable|null $request_handler Permet d'injecter un gestionnaire HTTP (tests).
     * @param callable|null $time_provider   Permet d'injecter la source de temps (tests).
     */
    public function __construct(?callable $request_handler = null, ?callable $time_provider = null) {
        $this->request_handler = $request_handler ?: static function ($url, array $args = []) {
            return wp_remote_request($url, $args);
        };
        $this->time_provider = $time_provider ?: static function () {
            return time();
        };

        if (function_exists('add_action')) {
            add_action('wp_ajax_bjlg_test_s3_connection', [$this, 'handle_test_connection']);
            add_action('admin_post_bjlg_s3_disconnect', [$this, 'handle_disconnect_request']);
        }
    }

    public function get_id() {
        return 'aws_s3';
    }

    public function get_name() {
        return 'Amazon S3';
    }

    public function is_connected() {
        $settings = $this->get_settings();

        return $settings['enabled']
            && $settings['access_key'] !== ''
            && $settings['secret_key'] !== ''
            && $settings['region'] !== ''
            && $settings['bucket'] !== '';
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

        echo "<div class='bjlg-destination bjlg-destination--s3'>";
        echo "<h4><span class='dashicons dashicons-amazon'></span> Amazon S3</h4>";
        echo "<p class='description'>Envoyez automatiquement vos sauvegardes WordPress vers un bucket Amazon S3.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Access Key ID</th><td><input type='text' name='s3_access_key' value='" . esc_attr($settings['access_key']) . "' class='regular-text' autocomplete='off'></td></tr>";
        echo "<tr><th scope='row'>Secret Access Key</th><td><input type='password' name='s3_secret_key' value='" . esc_attr($settings['secret_key']) . "' class='regular-text' autocomplete='off'></td></tr>";
        echo "<tr><th scope='row'>Région</th><td><input type='text' name='s3_region' value='" . esc_attr($settings['region']) . "' class='regular-text' placeholder='eu-west-3'></td></tr>";
        echo "<tr><th scope='row'>Bucket</th><td><input type='text' name='s3_bucket' value='" . esc_attr($settings['bucket']) . "' class='regular-text' placeholder='mon-bucket-backups'></td></tr>";
        echo "<tr><th scope='row'>Préfixe d'objet</th><td><input type='text' name='s3_object_prefix' value='" . esc_attr($settings['object_prefix']) . "' class='regular-text' placeholder='backups/'><p class='description'>Optionnel. Permet de ranger les sauvegardes dans un sous-dossier du bucket.</p></td></tr>";

        $sse_value = $settings['server_side_encryption'];
        echo "<tr><th scope='row'>Chiffrement côté serveur</th><td><select name='s3_server_side_encryption' class='regular-text'>";
        echo "<option value=''" . selected($sse_value, '', false) . ">Désactivé</option>";
        echo "<option value='AES256'" . selected($sse_value, 'AES256', false) . ">AES-256 (SSE-S3)</option>";
        echo "<option value='aws:kms'" . selected($sse_value, 'aws:kms', false) . ">AWS KMS (clé gérée)</option>";
        echo "</select><p class='description'>Activez le chiffrement côté serveur pour protéger vos sauvegardes.</p>";

        echo "<div class='bjlg-field bjlg-field--kms'>";
        echo "<label for='bjlg-s3-kms-key' class='screen-reader-text'>ID de la clé KMS</label>";
        echo "<input type='text' id='bjlg-s3-kms-key' name='s3_kms_key_id' value='" . esc_attr($settings['kms_key_id']) . "' class='regular-text' placeholder='arn:aws:kms:...'>";
        echo "<p class='description'>Requis uniquement pour l'option AWS KMS. Laissez vide pour utiliser la clé gérée par AWS.</p>";
        echo "</div></td></tr>";

        $enabled_attr = $settings['enabled'] ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer Amazon S3</th><td><label><input type='checkbox' name='s3_enabled' value='true'{$enabled_attr}> Activer l'envoi automatique vers Amazon S3.</label></td></tr>";
        echo "</table>";

        echo "<div class='notice bjlg-s3-test-feedback' role='status' aria-live='polite' style='display:none;'></div>";
        echo "<p><button type='button' class='button bjlg-s3-test-connection'>Tester la connexion</button></p>";

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

        if ($is_connected) {
            echo "<p class='description'><span class='dashicons dashicons-lock'></span> Connexion Amazon S3 configurée.</p>";
            echo "<form method='post' action='" . esc_url(admin_url('admin-post.php')) . "' style='margin-top:10px;'>";
            echo "<input type='hidden' name='action' value='bjlg_s3_disconnect'>";
            if (function_exists('wp_nonce_field')) {
                wp_nonce_field('bjlg_s3_disconnect', 'bjlg_s3_nonce');
            }
            echo "<button type='submit' class='button'>Déconnecter Amazon S3</button>";
            echo '</form>';
        }

        echo '</div>';
    }

    public function upload_file($filepath, $task_id) {
        if (!is_readable($filepath)) {
            throw new Exception('Fichier de sauvegarde introuvable : ' . $filepath);
        }

        $settings = $this->get_settings();
        if (!$this->is_connected()) {
            throw new Exception('Amazon S3 n\'est pas configuré.');
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

        $this->perform_request('PUT', $object_key, $contents, $headers, $settings);

        $this->log(sprintf('Sauvegarde "%s" envoyée sur Amazon S3 (%s).', basename($filepath), $object_key));
    }

    /**
     * Supprime un objet du bucket S3 configuré.
     *
     * @param string $object_key
     * @return void
     * @throws Exception
     */
    public function delete_file($object_key) {
        $settings = $this->get_settings();
        if (!$this->is_connected()) {
            throw new Exception('Amazon S3 n\'est pas configuré.');
        }

        $object_key = trim((string) $object_key);
        if ($object_key === '') {
            throw new Exception('Clé d\'objet Amazon S3 invalide.');
        }

        $object_key = $this->build_object_key($object_key, $settings['object_prefix'], false);
        $this->perform_request('DELETE', $object_key, '', [], $settings);

        $this->log(sprintf('Objet "%s" supprimé du bucket Amazon S3.', $object_key));
    }

    /**
     * Teste la connexion au bucket S3.
     *
     * @param array|null $settings
     * @return bool
     * @throws Exception
     */
    public function test_connection(?array $settings = null) {
        $settings = $settings ? $this->merge_settings($settings) : $this->get_settings();

        $this->assert_settings_complete($settings);

        $this->perform_request('HEAD', '', '', [], $settings);

        $message = sprintf('Bucket "%s" dans la région %s.', $settings['bucket'], $settings['region']);
        $this->store_status([
            'last_result' => 'success',
            'tested_at' => $this->get_time(),
            'message' => $message,
        ]);

        $this->log('Connexion Amazon S3 vérifiée avec succès.');

        return true;
    }

    /**
     * Gère la requête AJAX de test de connexion.
     */
    public function handle_test_connection() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $settings = [
            'access_key' => isset($_POST['s3_access_key']) ? sanitize_text_field(wp_unslash($_POST['s3_access_key'])) : '',
            'secret_key' => isset($_POST['s3_secret_key']) ? sanitize_text_field(wp_unslash($_POST['s3_secret_key'])) : '',
            'region' => isset($_POST['s3_region']) ? sanitize_text_field(wp_unslash($_POST['s3_region'])) : '',
            'bucket' => isset($_POST['s3_bucket']) ? sanitize_text_field(wp_unslash($_POST['s3_bucket'])) : '',
            'server_side_encryption' => isset($_POST['s3_server_side_encryption']) ? sanitize_text_field(wp_unslash($_POST['s3_server_side_encryption'])) : '',
            'kms_key_id' => isset($_POST['s3_kms_key_id']) ? sanitize_text_field(wp_unslash($_POST['s3_kms_key_id'])) : '',
            'object_prefix' => isset($_POST['s3_object_prefix']) ? sanitize_text_field(wp_unslash($_POST['s3_object_prefix'])) : '',
            'enabled' => true,
        ];

        if ($settings['server_side_encryption'] !== 'aws:kms') {
            $settings['kms_key_id'] = '';
        }

        try {
            $this->test_connection($settings);
            $message = sprintf('Connexion établie avec le bucket "%s".', $settings['bucket']);
            wp_send_json_success(['message' => $message]);
        } catch (Exception $exception) {
            $this->store_status([
                'last_result' => 'error',
                'tested_at' => $this->get_time(),
                'message' => $exception->getMessage(),
            ]);

            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    /**
     * Gère la demande de déconnexion depuis l'administration.
     */
    public function handle_disconnect_request() {
        if (function_exists('current_user_can') && !current_user_can(BJLG_CAPABILITY)) {
            return;
        }

        if (isset($_POST['bjlg_s3_nonce'])) {
            $nonce = wp_unslash($_POST['bjlg_s3_nonce']);
            if (function_exists('wp_verify_nonce') && !wp_verify_nonce($nonce, 'bjlg_s3_disconnect')) {
                return;
            }
        }

        $this->disconnect();

        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect(admin_url('admin.php?page=backup-jlg&tab=settings'));
            exit;
        }
    }

    private function perform_request($method, $object_key, $body, array $headers, array $settings) {
        $this->assert_settings_complete($settings);

        $timestamp = $this->get_time();
        $amz_date = gmdate('Ymd\THis\Z', $timestamp);
        $date_stamp = gmdate('Ymd', $timestamp);

        $bucket = $settings['bucket'];
        $region = $settings['region'];
        $host = $bucket . '.s3.' . $region . '.amazonaws.com';
        if ($region === 'us-east-1') {
            $host = $bucket . '.s3.amazonaws.com';
        }

        $canonical_uri = '/' . ltrim($this->encode_uri($object_key), '/');
        if ($object_key === '') {
            $canonical_uri = '/';
        }

        $endpoint = 'https://' . $host . $canonical_uri;
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
            '',
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
            'method'  => strtoupper($method),
            'headers' => $final_headers,
            'timeout' => apply_filters('bjlg_s3_request_timeout', 60, $method, $object_key),
        ];

        if ($method === 'PUT') {
            $args['body'] = $body;
        }

        $response = call_user_func($this->request_handler, $endpoint, $args);

        if (is_wp_error($response)) {
            throw new Exception('Erreur de communication avec Amazon S3 : ' . $response->get_error_message());
        }

        $status_code = isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        if ($status_code < 200 || $status_code >= 300) {
            $message = isset($response['response']['message']) ? $response['response']['message'] : '';
            throw new Exception(sprintf('Amazon S3 a renvoyé un statut inattendu (%d %s).', $status_code, $message));
        }

        return $response;
    }

    private function build_object_key($filename, $prefix, $apply_basename = true) {
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
            'access_key' => '',
            'secret_key' => '',
            'region' => '',
            'bucket' => '',
            'server_side_encryption' => '',
            'kms_key_id' => '',
            'object_prefix' => '',
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

    private function normalize_header_value($value) {
        $value = is_array($value) ? implode(',', $value) : (string) $value;
        $value = preg_replace('/\s+/', ' ', trim($value));

        return $value;
    }

    private function get_signing_key($secret_key, $date_stamp, $region) {
        $k_date = hash_hmac('sha256', $date_stamp, 'AWS4' . $secret_key, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);

        return hash_hmac('sha256', 'aws4_request', $k_service, true);
    }

    private function encode_uri($object_key) {
        if ($object_key === '' || $object_key === null) {
            return '';
        }

        $segments = explode('/', $object_key);
        $encoded = array_map(static function ($segment) {
            return str_replace('%2B', '+', rawurlencode($segment));
        }, $segments);

        return implode('/', $encoded);
    }

    private function get_time() {
        return (int) call_user_func($this->time_provider);
    }

    private function assert_settings_complete(array $settings) {
        $required = ['access_key', 'secret_key', 'region', 'bucket'];
        foreach ($required as $key) {
            if (empty($settings[$key])) {
                throw new Exception(sprintf('Le paramètre Amazon S3 "%s" est manquant.', $key));
            }
        }
    }

    private function log($message) {
        if (class_exists(BJLG_Debug::class)) {
            BJLG_Debug::log($message);
        }
    }
}
