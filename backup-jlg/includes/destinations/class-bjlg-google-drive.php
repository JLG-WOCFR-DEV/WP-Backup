<?php
namespace BJLG;

use Exception;
use Google\Client as Google_Client;
use Google\Service\Drive as Google_Service_Drive;
use Google\Service\Drive\DriveFile as Google_Service_DriveFile;

if (!defined('ABSPATH')) {
    exit;
}

if (!interface_exists(BJLG_Destination_Interface::class)) {
    return;
}

// Charger automatiquement les dépendances du SDK Google si elles sont disponibles.
$bjlg_base_dir = defined('BJLG_PLUGIN_DIR') ? BJLG_PLUGIN_DIR : dirname(__DIR__, 2) . '/';
$bjlg_autoload = $bjlg_base_dir . 'vendor-bjlg/autoload.php';
if (file_exists($bjlg_autoload)) {
    require_once $bjlg_autoload;
}

/**
 * Destination Google Drive pour l'envoi de sauvegardes.
 */
class BJLG_Google_Drive implements BJLG_Destination_Interface {

    private const OPTION_SETTINGS = 'bjlg_gdrive_settings';
    private const OPTION_TOKEN = 'bjlg_gdrive_token';
    private const OPTION_STATE = 'bjlg_gdrive_state';
    private const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    /** @var callable */
    private $client_factory;

    /** @var callable */
    private $drive_factory;

    /** @var callable */
    private $state_generator;

    /** @var bool */
    private $sdk_available;

    /**
     * @param callable|null $client_factory
     * @param callable|null $drive_factory
     * @param callable|null $state_generator
     */
    public function __construct(?callable $client_factory = null, ?callable $drive_factory = null, ?callable $state_generator = null) {
        $this->sdk_available = class_exists(Google_Client::class) && class_exists(Google_Service_Drive::class);

        $this->client_factory = $client_factory ?: static function () {
            return new Google_Client();
        };
        $this->drive_factory = $drive_factory ?: static function (Google_Client $client) {
            return new Google_Service_Drive($client);
        };
        $this->state_generator = $state_generator ?: static function () {
            return bin2hex(random_bytes(16));
        };

        add_action('admin_init', [$this, 'handle_oauth_callback']);
        add_action('admin_post_bjlg_gdrive_disconnect', [$this, 'handle_disconnect_request']);
    }

    public function get_id() {
        return 'google_drive';
    }

    public function get_name() {
        return 'Google Drive';
    }

    public function is_connected() {
        $token = $this->get_stored_token();

        return $this->sdk_available && !empty($token) && isset($token['refresh_token']);
    }

    public function disconnect() {
        if (function_exists('delete_option')) {
            delete_option(self::OPTION_TOKEN);
        } else {
            update_option(self::OPTION_TOKEN, []);
        }
    }

    public function render_settings() {
        echo "<div class='bjlg-destination bjlg-destination--gdrive'>";
        echo "<h4><span class='dashicons dashicons-google'></span> Google Drive</h4>";

        if (!$this->sdk_available) {
            echo "<p class='description'>Le SDK Google n'est pas disponible. Installez les dépendances via Composer pour activer cette destination.</p></div>";
            return;
        }

        $settings = $this->get_settings();
        $is_connected = $this->is_connected();

        echo "<p class='description'>Transférez automatiquement vos sauvegardes vers un dossier Google Drive dédié.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Client ID</th><td><input type='text' name='gdrive_client_id' value='" . esc_attr($settings['client_id']) . "' class='regular-text'></td></tr>";
        echo "<tr><th scope='row'>Client Secret</th><td><input type='text' name='gdrive_client_secret' value='" . esc_attr($settings['client_secret']) . "' class='regular-text'></td></tr>";
        echo "<tr><th scope='row'>ID du dossier cible</th><td><input type='text' name='gdrive_folder_id' value='" . esc_attr($settings['folder_id']) . "' class='regular-text'><p class='description'>Laissez vide pour utiliser le dossier racine.</p></td></tr>";
        $enabled_attr = $settings['enabled'] ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer Google Drive</th><td><label><input type='checkbox' name='gdrive_enabled' value='true'{$enabled_attr}> Activer l'envoi automatique vers Google Drive.</label></td></tr>";
        echo "</table>";

        if (!$settings['enabled']) {
            echo "<p class='description'>Enregistrez vos identifiants puis activez Google Drive pour poursuivre la connexion.</p>";
        }

        if (!$is_connected && $settings['enabled'] && $settings['client_id'] !== '' && $settings['client_secret'] !== '') {
            $auth_url = esc_url($this->build_authorization_url());
            echo "<p><a class='button button-secondary' href='{$auth_url}'>Connecter mon compte Google Drive</a></p>";
        }

        if ($is_connected) {
            echo "<p class='description'><span class='dashicons dashicons-yes'></span> Compte Google Drive connecté.</p>";
            echo "<form method='post' action='" . esc_url(admin_url('admin-post.php')) . "'>";
            echo "<input type='hidden' name='action' value='bjlg_gdrive_disconnect'>";
            if (function_exists('wp_nonce_field')) {
                wp_nonce_field('bjlg_gdrive_disconnect', 'bjlg_gdrive_nonce');
            }
            echo "<button type='submit' class='button'>Déconnecter Google Drive</button></form>";
        }

        echo "</div>";
    }

    public function upload_file($filepath, $task_id) {
        if (!$this->sdk_available) {
            throw new Exception('Le SDK Google n\'est pas disponible.');
        }

        if (!is_readable($filepath)) {
            throw new Exception('Fichier de sauvegarde introuvable : ' . $filepath);
        }

        if (!$this->is_connected()) {
            throw new Exception('Google Drive n\'est pas connecté.');
        }

        $client = $this->build_configured_client();
        $drive_service = call_user_func($this->drive_factory, $client);

        $settings = $this->get_settings();
        $folder_id = $settings['folder_id'] !== '' ? $settings['folder_id'] : 'root';

        $file_metadata = new Google_Service_DriveFile([
            'name' => basename($filepath),
            'parents' => [$folder_id],
        ]);

        $mime_type = 'application/zip';
        $content = file_get_contents($filepath);

        try {
            $uploaded_file = $drive_service->files->create(
                $file_metadata,
                [
                    'data' => $content,
                    'mimeType' => $mime_type,
                    'uploadType' => 'multipart',
                    'fields' => 'id,name,size',
                ]
            );
        } catch (\Throwable $exception) {
            throw new Exception('Erreur lors de l\'envoi vers Google Drive : ' . $exception->getMessage(), 0, $exception);
        }

        if (!$uploaded_file || !$uploaded_file->getId()) {
            throw new Exception('La réponse de Google Drive ne contient pas d\'identifiant de fichier.');
        }

        $expected_size = filesize($filepath);
        $reported_size = (int) $uploaded_file->getSize();
        if ($reported_size > 0 && $reported_size !== $expected_size) {
            throw new Exception('Le fichier envoyé sur Google Drive est corrompu (taille inattendue).');
        }

        if (class_exists(BJLG_Debug::class)) {
            BJLG_Debug::log(sprintf('Sauvegarde "%s" envoyée sur Google Drive (ID: %s).', basename($filepath), $uploaded_file->getId()));
        }
    }

    /**
     * Traite le callback OAuth de Google.
     */
    public function handle_oauth_callback() {
        if (!$this->sdk_available) {
            return;
        }

        if (!isset($_GET['bjlg_gdrive_auth'])) {
            return;
        }

        if (function_exists('current_user_can') && !current_user_can(BJLG_CAPABILITY)) {
            return;
        }

        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';

        if ($code === '') {
            return;
        }

        $expected_state = get_option(self::OPTION_STATE, '');
        if ($expected_state === '' || !hash_equals($expected_state, $state)) {
            return;
        }

        $client = $this->build_client();
        $client->setRedirectUri($this->get_redirect_uri());
        $client->setClientId($this->get_settings()['client_id']);
        $client->setClientSecret($this->get_settings()['client_secret']);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->addScope(self::SCOPE);

        $token = $client->fetchAccessTokenWithAuthCode($code);
        if (isset($token['error'])) {
            return;
        }

        $this->store_token($token);

        if (function_exists('delete_option')) {
            delete_option(self::OPTION_STATE);
        } else {
            update_option(self::OPTION_STATE, '');
        }
    }

    /**
     * Gère la requête de déconnexion.
     */
    public function handle_disconnect_request() {
        if (function_exists('current_user_can') && !current_user_can(BJLG_CAPABILITY)) {
            return;
        }

        if (function_exists('wp_verify_nonce') && isset($_POST['bjlg_gdrive_nonce'])) {
            if (!wp_verify_nonce(wp_unslash($_POST['bjlg_gdrive_nonce']), 'bjlg_gdrive_disconnect')) {
                return;
            }
        }

        $this->disconnect();
    }

    /**
     * Crée un client Google configuré sans gérer le token.
     *
     * @return Google_Client
     */
    private function build_client() {
        return call_user_func($this->client_factory);
    }

    /**
     * Construit un client configuré pour les requêtes authentifiées.
     *
     * @return Google_Client
     */
    private function build_configured_client() {
        $settings = $this->get_settings();
        $client = $this->build_client();

        $client->setClientId($settings['client_id']);
        $client->setClientSecret($settings['client_secret']);
        $client->setRedirectUri($this->get_redirect_uri());
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setScopes([self::SCOPE]);

        $token = $this->get_stored_token();
        if (!empty($token)) {
            $client->setAccessToken($token);

            if ($client->isAccessTokenExpired() && $client->getRefreshToken()) {
                $new_token = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                if (!isset($new_token['error'])) {
                    if (!isset($new_token['refresh_token']) && isset($token['refresh_token'])) {
                        $new_token['refresh_token'] = $token['refresh_token'];
                    }
                    $this->store_token($new_token);
                    $client->setAccessToken($new_token);
                }
            }
        }

        return $client;
    }

    /**
     * Retourne l'URL de redirection utilisée pour OAuth.
     *
     * @return string
     */
    private function get_redirect_uri() {
        $redirect = admin_url('admin.php?page=backup-jlg&tab=settings');
        $redirect = add_query_arg(['bjlg_gdrive_auth' => '1'], $redirect);

        return $redirect;
    }

    /**
     * Crée et stocke un nouvel état OAuth puis génère l'URL d'autorisation.
     *
     * @return string
     */
    private function build_authorization_url() {
        $settings = $this->get_settings();
        $client = $this->build_client();

        $client->setClientId($settings['client_id']);
        $client->setClientSecret($settings['client_secret']);
        $client->setRedirectUri($this->get_redirect_uri());
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setScopes([self::SCOPE]);

        $state = call_user_func($this->state_generator);
        if (method_exists($client, 'setState')) {
            $client->setState($state);
        }

        update_option(self::OPTION_STATE, $state);

        return $client->createAuthUrl();
    }

    /**
     * Retourne les réglages Google Drive.
     *
     * @return array{client_id:string,client_secret:string,folder_id:string,enabled:bool}
     */
    private function get_settings() {
        $defaults = [
            'client_id' => '',
            'client_secret' => '',
            'folder_id' => '',
            'enabled' => false,
        ];

        $settings = get_option(self::OPTION_SETTINGS, $defaults);
        if (!is_array($settings)) {
            $settings = [];
        }

        return array_merge($defaults, $settings);
    }

    /**
     * Récupère le token d'accès stocké.
     *
     * @return array<string, mixed>
     */
    private function get_stored_token() {
        $token = get_option(self::OPTION_TOKEN, []);

        return is_array($token) ? $token : [];
    }

    /**
     * Persiste le token obtenu depuis Google.
     *
     * @param array<string, mixed> $token
     * @return void
     */
    private function store_token(array $token) {
        update_option(self::OPTION_TOKEN, $token);
    }
}
