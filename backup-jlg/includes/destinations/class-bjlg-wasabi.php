<?php
namespace BJLG;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

if (!interface_exists(BJLG_Destination_Interface::class)) {
    return;
}

if (!class_exists(BJLG_Abstract_S3_Compatible::class)) {
    return;
}

/**
 * Destination Wasabi utilisant une API compatible S3.
 */
class BJLG_Wasabi extends BJLG_Abstract_S3_Compatible {

    private const OPTION_SETTINGS = 'bjlg_wasabi_settings';
    private const OPTION_STATUS = 'bjlg_wasabi_status';

    public function __construct(?callable $request_handler = null, ?callable $time_provider = null) {
        parent::__construct($request_handler, $time_provider);

        if (function_exists('add_action')) {
            add_action('wp_ajax_bjlg_test_wasabi_connection', [$this, 'handle_test_connection']);
            add_action('admin_post_bjlg_wasabi_disconnect', [$this, 'handle_disconnect_request']);
        }
    }

    public function get_id() {
        return 'wasabi';
    }

    public function get_name() {
        return 'Wasabi S3';
    }

    protected function get_settings_option_name(): string {
        return self::OPTION_SETTINGS;
    }

    protected function get_status_option_name(): string {
        return self::OPTION_STATUS;
    }

    protected function get_service_label(): string {
        return 'Wasabi';
    }

    protected function get_default_settings(): array {
        $defaults = parent::get_default_settings();
        $defaults['region'] = 'us-east-1';
        $defaults['endpoint'] = 's3.wasabisys.com';
        $defaults['multipart_threshold_mb'] = 200;
        $defaults['multipart_chunk_mb'] = 16;

        return $defaults;
    }

    public function render_settings() {
        $settings = $this->get_settings();
        $status = $this->get_status();
        $is_connected = $this->is_connected();

        echo "<div class='bjlg-destination bjlg-destination--wasabi'>";
        echo "<h4><span class='dashicons dashicons-cloud-upload' aria-hidden='true'></span> Wasabi S3</h4>";
        echo "<p class='description'>Répliquez vos sauvegardes WordPress vers un bucket Wasabi compatible S3.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Access Key</th><td><input type='text' name='wasabi_access_key' value='" . esc_attr($settings['access_key']) . "' class='regular-text' autocomplete='off'></td></tr>";
        echo "<tr><th scope='row'>Secret Key</th><td><input type='password' name='wasabi_secret_key' value='" . esc_attr($settings['secret_key']) . "' class='regular-text' autocomplete='off'></td></tr>";
        echo "<tr><th scope='row'>Région</th><td><input type='text' name='wasabi_region' value='" . esc_attr($settings['region']) . "' class='regular-text' placeholder='eu-central-1'></td></tr>";
        echo "<tr><th scope='row'>Endpoint</th><td><input type='text' name='wasabi_endpoint' value='" . esc_attr($settings['endpoint']) . "' class='regular-text' placeholder='s3.eu-central-1.wasabisys.com'><p class='description'>Laissez vide pour utiliser l'endpoint par défaut de la région.</p></td></tr>";
        echo "<tr><th scope='row'>Bucket</th><td><input type='text' name='wasabi_bucket' value='" . esc_attr($settings['bucket']) . "' class='regular-text' placeholder='mes-sauvegardes-wordpress'></td></tr>";
        echo "<tr><th scope='row'>Préfixe</th><td><input type='text' name='wasabi_object_prefix' value='" . esc_attr($settings['object_prefix']) . "' class='regular-text' placeholder='backups/'><p class='description'>Optionnel, permet de ranger les archives dans un dossier.</p></td></tr>";

        $path_style_checked = !empty($settings['use_path_style_endpoint']) ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Mode path-style</th><td><label><input type='checkbox' name='wasabi_use_path_style' value='true'{$path_style_checked}> Utiliser un endpoint de type <code>https://endpoint/bucket</code>.</label></td></tr>";

        $threshold = isset($settings['multipart_threshold_mb']) ? (int) $settings['multipart_threshold_mb'] : 200;
        $chunk = isset($settings['multipart_chunk_mb']) ? (int) $settings['multipart_chunk_mb'] : 16;
        echo "<tr><th scope='row'>Seuil multipart</th><td><input type='number' name='wasabi_multipart_threshold' value='" . esc_attr((string) $threshold) . "' class='small-text' min='5' step='1'> Mo <p class='description'>Au-delà de ce seuil, l'upload se fait en plusieurs parties pour fiabiliser le transfert.</p></td></tr>";
        echo "<tr><th scope='row'>Taille des blocs</th><td><input type='number' name='wasabi_multipart_chunk' value='" . esc_attr((string) $chunk) . "' class='small-text' min='5' step='1'> Mo <p class='description'>Doit être supérieur ou égal à 5&nbsp;Mo.</p></td></tr>";

        $enabled_attr = !empty($settings['enabled']) ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer Wasabi</th><td><label><input type='checkbox' name='wasabi_enabled' value='true'{$enabled_attr}> Activer l'envoi automatique vers Wasabi.</label></td></tr>";
        echo "</table>";

        echo "<div class='notice bjlg-wasabi-test-feedback bjlg-hidden' role='status' aria-live='polite'></div>";
        echo "<p><button type='button' class='button bjlg-wasabi-test-connection'>Tester la connexion</button></p>";

        if ($status['last_result'] === 'success' && $status['tested_at'] > 0) {
            $tested_at = gmdate('d/m/Y H:i:s', $status['tested_at']);
            echo "<p class='description'><span class='dashicons dashicons-yes' aria-hidden='true'></span> Dernier test réussi le {$tested_at}.";
            if ($status['message'] !== '') {
                echo ' ' . esc_html($status['message']);
            }
            echo '</p>';
        } elseif ($status['last_result'] === 'error' && $status['tested_at'] > 0) {
            $tested_at = gmdate('d/m/Y H:i:s', $status['tested_at']);
            echo "<p class='description' style='color:#b32d2e;'><span class='dashicons dashicons-warning' aria-hidden='true'></span> Dernier test échoué le {$tested_at}.";
            if ($status['message'] !== '') {
                echo ' ' . esc_html($status['message']);
            }
            echo '</p>';
        }

        if ($is_connected) {
            echo "<p class='description'><span class='dashicons dashicons-lock' aria-hidden='true'></span> Connexion Wasabi configurée.</p>";
            echo "<form method='post' action='" . esc_url(admin_url('admin-post.php')) . "' style='margin-top:10px;'>";
            echo "<input type='hidden' name='action' value='bjlg_wasabi_disconnect'>";
            if (function_exists('wp_nonce_field')) {
                wp_nonce_field('bjlg_wasabi_disconnect', 'bjlg_wasabi_nonce');
            }
            echo "<button type='submit' class='button'>Déconnecter Wasabi</button>";
            echo '</form>';
        }

        echo '</div>';
    }

    public function handle_test_connection() {
        if (!\bjlg_can_manage_plugin()) {
            wp_send_json_error(['message' => 'Permission refusée.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $posted = wp_unslash($_POST);
        $settings = [
            'access_key' => isset($posted['wasabi_access_key']) ? sanitize_text_field($posted['wasabi_access_key']) : '',
            'secret_key' => isset($posted['wasabi_secret_key']) ? sanitize_text_field($posted['wasabi_secret_key']) : '',
            'region' => isset($posted['wasabi_region']) ? sanitize_text_field($posted['wasabi_region']) : '',
            'bucket' => isset($posted['wasabi_bucket']) ? sanitize_text_field($posted['wasabi_bucket']) : '',
            'endpoint' => isset($posted['wasabi_endpoint']) ? sanitize_text_field($posted['wasabi_endpoint']) : '',
            'object_prefix' => isset($posted['wasabi_object_prefix']) ? sanitize_text_field($posted['wasabi_object_prefix']) : '',
            'endpoint_scheme' => 'https',
            'use_path_style_endpoint' => !empty($posted['wasabi_use_path_style']) && $posted['wasabi_use_path_style'] !== 'false',
            'multipart_threshold_mb' => isset($posted['wasabi_multipart_threshold']) ? (int) $posted['wasabi_multipart_threshold'] : 200,
            'multipart_chunk_mb' => isset($posted['wasabi_multipart_chunk']) ? (int) $posted['wasabi_multipart_chunk'] : 16,
            'enabled' => true,
        ];

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

    public function handle_disconnect_request() {
        if (!\bjlg_can_manage_plugin()) {
            return;
        }

        if (isset($_POST['bjlg_wasabi_nonce'])) {
            $nonce = wp_unslash($_POST['bjlg_wasabi_nonce']);
            if (function_exists('wp_verify_nonce') && !wp_verify_nonce($nonce, 'bjlg_wasabi_disconnect')) {
                return;
            }
        }

        $this->disconnect();

        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect(admin_url('admin.php?page=backup-jlg&tab=settings'));
            exit;
        }
    }
}

