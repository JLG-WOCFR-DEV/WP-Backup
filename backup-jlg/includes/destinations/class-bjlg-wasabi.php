<?php
namespace BJLG;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

if (!interface_exists(BJLG_Destination_Interface::class)) {
    return;
}

if (!class_exists(BJLG_S3_Compatible_Destination::class)) {
    require_once __DIR__ . '/abstract-class-bjlg-s3-compatible.php';
}

/**
 * Destination Wasabi S3 compatible.
 */
class BJLG_Wasabi extends BJLG_S3_Compatible_Destination {

    private const OPTION_SETTINGS = 'bjlg_wasabi_settings';
    private const OPTION_STATUS = 'bjlg_wasabi_status';

    public function __construct(?callable $request_handler = null, ?callable $time_provider = null) {
        parent::__construct($request_handler, $time_provider);
    }

    protected function get_service_id() {
        return 'wasabi';
    }

    protected function get_service_name() {
        return 'Wasabi Cloud Storage';
    }

    protected function get_settings_option_name() {
        return self::OPTION_SETTINGS;
    }

    protected function get_status_option_name() {
        return self::OPTION_STATUS;
    }

    protected function build_host(array $settings) {
        $bucket = trim((string) $settings['bucket']);
        $region = trim((string) $settings['region']);

        if ($bucket === '' || $region === '') {
            throw new Exception('Bucket ou région Wasabi manquant.');
        }

        return $bucket . '.s3.' . $region . '.wasabisys.com';
    }

    protected function get_log_label() {
        return 'Wasabi';
    }

    public function render_settings() {
        $settings = $this->get_settings();
        $status = $this->get_status();
        $is_connected = $this->is_connected();

        echo "<div class='bjlg-destination bjlg-destination--wasabi'>";
        echo "<h4><span class='dashicons dashicons-cloud' aria-hidden='true'></span> Wasabi</h4>";
        echo "<p class='description'>Synchronisez vos sauvegardes WordPress vers un bucket Wasabi S3 compatible.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Access Key ID</th><td><input type='text' name='wasabi_access_key' value='" . esc_attr($settings['access_key']) . "' class='regular-text' autocomplete='off'></td></tr>";
        echo "<tr><th scope='row'>Secret Access Key</th><td><input type='password' name='wasabi_secret_key' value='" . esc_attr($settings['secret_key']) . "' class='regular-text' autocomplete='off'></td></tr>";
        echo "<tr><th scope='row'>Région</th><td><input type='text' name='wasabi_region' value='" . esc_attr($settings['region']) . "' class='regular-text' placeholder='eu-west-1'></td></tr>";
        echo "<tr><th scope='row'>Bucket</th><td><input type='text' name='wasabi_bucket' value='" . esc_attr($settings['bucket']) . "' class='regular-text' placeholder='mes-backups'></td></tr>";
        echo "<tr><th scope='row'>Préfixe d'objet</th><td><input type='text' name='wasabi_object_prefix' value='" . esc_attr($settings['object_prefix']) . "' class='regular-text' placeholder='wp-backups/'><p class='description'>Optionnel, permet de ranger les sauvegardes dans un sous-dossier.</p></td></tr>";

        $enabled_attr = $settings['enabled'] ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer Wasabi</th><td><label><input type='checkbox' name='wasabi_enabled' value='true'{$enabled_attr}> Activer l'envoi automatique vers Wasabi.</label></td></tr>";
        echo "</table>";

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
            echo "<p class='description'><span class='dashicons dashicons-lock' aria-hidden='true'></span> Connexion Wasabi configurée.</p>";
        } else {
            echo "<p class='description'>Enregistrez vos identifiants et activez la destination puis testez une sauvegarde pour valider la connexion.</p>";
        }

        echo '</div>';
    }
}
