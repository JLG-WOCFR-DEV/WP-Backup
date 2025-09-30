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
 * Destination Amazon S3 pour l'envoi de sauvegardes.
 */
class BJLG_S3 implements BJLG_Destination_Interface {

    private const OPTION_SETTINGS = 'bjlg_s3_settings';

    /** @var callable|null */
    private $client_factory;

    /**
     * @param callable|null $client_factory
     */
    public function __construct(?callable $client_factory = null) {
        $this->client_factory = $client_factory;
    }

    public function get_id() {
        return 's3';
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
    }

    public function render_settings() {
        $settings = $this->get_settings();
        $is_ready = $this->is_connected();

        echo "<div class='bjlg-destination bjlg-destination--s3'>";
        echo "<h4><span class='dashicons dashicons-cloud'></span> Amazon S3</h4>";
        echo "<p class='description'>Envoyez automatiquement vos archives sur un bucket S3 compatible.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Access Key ID</th><td><input type='text' name='s3_access_key' value='" . esc_attr($settings['access_key']) . "' class='regular-text'></td></tr>";
        echo "<tr><th scope='row'>Secret Access Key</th><td><input type='password' name='s3_secret_key' value='" . esc_attr($settings['secret_key']) . "' class='regular-text'></td></tr>";
        echo "<tr><th scope='row'>Région</th><td><input type='text' name='s3_region' value='" . esc_attr($settings['region']) . "' class='regular-text' placeholder='eu-west-3'></td></tr>";
        echo "<tr><th scope='row'>Bucket</th><td><input type='text' name='s3_bucket' value='" . esc_attr($settings['bucket']) . "' class='regular-text'></td></tr>";
        echo "<tr><th scope='row'>Préfixe</th><td><input type='text' name='s3_prefix' value='" . esc_attr($settings['prefix']) . "' class='regular-text' placeholder='backups/sites'><p class='description'>Optionnel : répertoire distant dans lequel stocker les sauvegardes.</p></td></tr>";
        $enabled_attr = $settings['enabled'] ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer Amazon S3</th><td><label><input type='checkbox' name='s3_enabled' value='true'{$enabled_attr}> Activer l'envoi automatique vers S3.</label></td></tr>";
        echo "</table>";

        if ($is_ready) {
            echo "<p class='description'><span class='dashicons dashicons-yes'></span> Connexion prête. Les sauvegardes seront transférées vers S3 lorsque sélectionné.</p>";
        } else {
            echo "<p class='description'>Complétez vos identifiants et activez l'intégration pour pouvoir sélectionner cette destination.</p>";
        }

        echo "</div>";
    }

    public function upload_file($filepath, $task_id) {
        if (!is_readable($filepath)) {
            throw new Exception('Fichier de sauvegarde introuvable : ' . $filepath);
        }

        $settings = $this->get_settings();

        if (!$settings['enabled']) {
            throw new Exception('L\'intégration Amazon S3 est désactivée.');
        }

        if (!$this->is_connected()) {
            throw new Exception('Amazon S3 n\'est pas configuré correctement.');
        }

        $client = $this->create_client($settings);

        $prefix = trim((string) $settings['prefix']);
        $prefix = trim($prefix, '/');
        $key = basename($filepath);
        if ($prefix !== '') {
            $key = $prefix . '/' . $key;
        }

        $args = [
            'Bucket' => $settings['bucket'],
            'Key' => $key,
            'SourceFile' => $filepath,
        ];

        /**
         * Filtre les paramètres envoyés lors du téléversement vers S3.
         *
         * @param array<string, mixed> $args
         * @param array<string, mixed> $settings
         * @param string $task_id
         */
        $args = apply_filters('bjlg_s3_put_object_args', $args, $settings, $task_id);

        try {
            $result = $client->putObject($args);
        } catch (\Throwable $throwable) {
            throw new Exception('Échec de l\'envoi vers Amazon S3 : ' . $throwable->getMessage(), 0, $throwable);
        }

        if (class_exists(BJLG_Debug::class)) {
            $summary = isset($result['ObjectURL']) ? (string) $result['ObjectURL'] : $key;
            BJLG_Debug::log(sprintf('Sauvegarde "%s" envoyée vers Amazon S3 (%s).', basename($filepath), $summary));
        }
    }

    /**
     * @return array{access_key:string,secret_key:string,region:string,bucket:string,prefix:string,enabled:bool}
     */
    private function get_settings() {
        $settings = get_option(self::OPTION_SETTINGS, $this->get_default_settings());

        if (!is_array($settings)) {
            $settings = [];
        }

        return array_merge($this->get_default_settings(), $settings);
    }

    /**
     * @return array{access_key:string,secret_key:string,region:string,bucket:string,prefix:string,enabled:bool}
     */
    private function get_default_settings() {
        return [
            'access_key' => '',
            'secret_key' => '',
            'region' => '',
            'bucket' => '',
            'prefix' => '',
            'enabled' => false,
        ];
    }

    /**
     * Crée un client S3 configuré pour les réglages fournis.
     *
     * @param array{access_key:string,secret_key:string,region:string,bucket:string,prefix:string,enabled:bool} $settings
     * @return object
     */
    private function create_client(array $settings) {
        if (is_callable($this->client_factory)) {
            return call_user_func($this->client_factory, $settings);
        }

        $client_class = '\\Aws\\S3\\S3Client';
        if (!class_exists($client_class)) {
            throw new Exception('Le SDK AWS pour PHP n\'est pas disponible.');
        }

        return new $client_class([
            'version' => 'latest',
            'region' => $settings['region'],
            'credentials' => [
                'key' => $settings['access_key'],
                'secret' => $settings['secret_key'],
            ],
        ]);
    }
}
