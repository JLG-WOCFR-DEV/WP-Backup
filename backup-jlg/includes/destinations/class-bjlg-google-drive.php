<?php
namespace BJLG;

use Exception;
use Google\Client as Google_Client;
use Google\Service\Drive as Google_Service_Drive;
use Google\Service\Drive\DriveFile as Google_Service_DriveFile;
use Google\Service\Exception as Google_Service_Exception;
use Google_Http_MediaFileUpload;

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
    private const OPTION_STATUS = 'bjlg_gdrive_status';
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

    /** @var callable */
    private $media_upload_factory;

    /** @var callable */
    private $time_provider;

    /**
     * @param callable|null $client_factory
     * @param callable|null $drive_factory
     * @param callable|null $state_generator
     * @param callable|null $media_upload_factory
     * @param callable|null $time_provider
     */
    public function __construct(?callable $client_factory = null, ?callable $drive_factory = null, ?callable $state_generator = null, ?callable $media_upload_factory = null, ?callable $time_provider = null) {
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
        $this->media_upload_factory = $media_upload_factory ?: static function (Google_Client $client, $request, string $mime_type, int $chunk_size) {
            return new Google_Http_MediaFileUpload($client, $request, $mime_type, null, true, $chunk_size);
        };
        $this->time_provider = $time_provider ?: static function () {
            return time();
        };

        add_action('admin_init', [$this, 'handle_oauth_callback']);
        add_action('admin_post_bjlg_gdrive_disconnect', [$this, 'handle_disconnect_request']);

        if (function_exists('add_action')) {
            add_action('wp_ajax_bjlg_test_gdrive_connection', [$this, 'handle_test_connection']);
        }
    }

    public function get_id() {
        return 'google_drive';
    }

    public function get_name() {
        return 'Google Drive';
    }

    public function is_sdk_available() {
        return (bool) $this->sdk_available;
    }

    public function is_connected() {
        $token = $this->get_stored_token();

        return $this->sdk_available && !empty($token) && isset($token['refresh_token']);
    }

    public function disconnect() {
        if (function_exists('delete_option')) {
            delete_option(self::OPTION_TOKEN);
            delete_option(self::OPTION_STATUS);
        } else {
            update_option(self::OPTION_TOKEN, []);
            update_option(self::OPTION_STATUS, []);
        }
    }

    public function render_settings() {
        echo "<div class='bjlg-destination bjlg-destination--gdrive'>";
        echo "<h4><span class='dashicons dashicons-google' aria-hidden='true'></span> Google Drive</h4>";

        if (!$this->sdk_available) {
            $message = esc_html__(
                "Le SDK Google n'est pas disponible. Installez les dépendances via Composer pour activer cette destination.",
                'backup-jlg'
            );
            echo "<p class='description'>{$message}</p></div>";
            return;
        }

        $settings = $this->get_settings();
        $status = $this->get_status();
        $is_connected = $this->is_connected();

        echo "<p class='description'>Transférez automatiquement vos sauvegardes vers un dossier Google Drive dédié.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Client ID</th><td><input type='text' name='gdrive_client_id' value='" . esc_attr($settings['client_id']) . "' class='regular-text'></td></tr>";
        echo "<tr><th scope='row'>Client Secret</th><td><input type='text' name='gdrive_client_secret' value='" . esc_attr($settings['client_secret']) . "' class='regular-text'></td></tr>";
        echo "<tr><th scope='row'>ID du dossier cible</th><td><input type='text' name='gdrive_folder_id' value='" . esc_attr($settings['folder_id']) . "' class='regular-text'><p class='description'>Laissez vide pour utiliser le dossier racine.</p></td></tr>";
        $enabled_attr = $settings['enabled'] ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer Google Drive</th><td><label><input type='checkbox' name='gdrive_enabled' value='true'{$enabled_attr}> Activer l'envoi automatique vers Google Drive.</label></td></tr>";
        echo "</table>";

        echo "<div class='notice bjlg-gdrive-test-feedback bjlg-hidden' role='status' aria-live='polite'></div>";
        echo "<p class='bjlg-gdrive-test-actions'><button type='button' class='button bjlg-gdrive-test-connection'>Tester la connexion</button> <span class='spinner bjlg-gdrive-test-spinner' style='float:none;margin:0 0 0 8px;display:none;'></span></p>";

        $last_test_style = '';
        $last_test_classes = 'description bjlg-gdrive-last-test';
        $last_test_content = '';

        if ($status['last_result'] === 'success' && $status['tested_at'] > 0) {
            $tested_at = gmdate('d/m/Y H:i:s', $status['tested_at']);
            $last_test_content = "<span class='dashicons dashicons-yes' aria-hidden='true'></span> Dernier test réussi le {$tested_at}.";
            if ($status['message'] !== '') {
                $last_test_content .= ' ' . esc_html($status['message']);
            }
        } elseif ($status['last_result'] === 'error' && $status['tested_at'] > 0) {
            $tested_at = gmdate('d/m/Y H:i:s', $status['tested_at']);
            $last_test_content = "<span class='dashicons dashicons-warning' aria-hidden='true'></span> Dernier test échoué le {$tested_at}.";
            if ($status['message'] !== '') {
                $last_test_content .= ' ' . esc_html($status['message']);
            }
            $last_test_style = " style='color:#b32d2e;'";
        } else {
            $last_test_style = " style='display:none;'";
        }

        $last_test_aria = " role='status' aria-live='polite'";
        echo "<p class='{$last_test_classes}'{$last_test_style}{$last_test_aria}>{$last_test_content}</p>";

        if (!$settings['enabled']) {
            echo "<p class='description'>Enregistrez vos identifiants puis activez Google Drive pour poursuivre la connexion.</p>";
        }

        if (!$is_connected && $settings['enabled'] && $settings['client_id'] !== '' && $settings['client_secret'] !== '') {
            $auth_url = esc_url($this->build_authorization_url());
            echo "<p><a class='button button-secondary' href='{$auth_url}'>Connecter mon compte Google Drive</a></p>";
        }

        if ($is_connected) {
            echo "<p class='description'><span class='dashicons dashicons-yes' aria-hidden='true'></span> Compte Google Drive connecté.</p>";
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
                throw new Exception('Erreurs Google Drive : ' . implode(' | ', $errors));
            }

            return;
        }

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
        $file_size = filesize($filepath);
        if ($file_size === false) {
            throw new Exception('Impossible de déterminer la taille du fichier à envoyer.');
        }

        $handle = fopen($filepath, 'rb');
        if (!is_resource($handle)) {
            throw new Exception('Impossible d\'ouvrir le fichier de sauvegarde pour lecture.');
        }

        $chunk_size = (int) apply_filters('bjlg_google_drive_chunk_size', 5 * 1024 * 1024, $filepath, $task_id);
        if ($chunk_size <= 0) {
            $chunk_size = 5 * 1024 * 1024;
        }
        $minimum_chunk_size = 256 * 1024; // 256 Ko
        if ($chunk_size < $minimum_chunk_size) {
            $chunk_size = $minimum_chunk_size;
        }
        $chunk_size = (int) ceil($chunk_size / $minimum_chunk_size) * $minimum_chunk_size;

        $client->setDefer(true);
        BJLG_Debug::log(sprintf('Envoi de %s vers Google Drive.', basename($filepath)));

        $uploaded_file = null;
        $bytes_uploaded = 0;

        try {
            $request = $drive_service->files->create(
                $file_metadata,
                [
                    'mimeType' => $mime_type,
                    'uploadType' => 'resumable',
                    'fields' => 'id,name,size',
                ]
            );

            $media = call_user_func($this->media_upload_factory, $client, $request, $mime_type, $chunk_size);
            if (method_exists($media, 'setFileSize')) {
                $media->setFileSize($file_size);
            }

            while (!feof($handle)) {
                $chunk = fread($handle, $chunk_size);
                if ($chunk === false) {
                    throw new Exception('Erreur lors de la lecture du fichier de sauvegarde.');
                }

                if ($chunk === '') {
                    continue;
                }

                $bytes_uploaded += strlen($chunk);
                $status = $media->nextChunk($chunk);

                if (class_exists(BJLG_Debug::class) && $file_size > 0) {
                    $progress = min(100, ($bytes_uploaded / $file_size) * 100);
                    BJLG_Debug::log(sprintf(
                        'Progression de l\'envoi Google Drive : %.2f%% (%s/%s octets).',
                        $progress,
                        number_format_i18n($bytes_uploaded, 0),
                        number_format_i18n($file_size, 0)
                    ));
                }

                if ($status instanceof Google_Service_DriveFile) {
                    $uploaded_file = $status;
                }
            }
        } catch (\Throwable $exception) {
            BJLG_Debug::log('ERREUR Google Drive : ' . $exception->getMessage());
            throw new Exception('Erreur lors de l\'envoi vers Google Drive : ' . $exception->getMessage(), 0, $exception);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            $client->setDefer(false);
        }

        if (!$uploaded_file || !$uploaded_file->getId()) {
            throw new Exception('La réponse de Google Drive ne contient pas d\'identifiant de fichier.');
        }

        $expected_size = filesize($filepath);
        $reported_size = (int) $uploaded_file->getSize();
        if ($reported_size > 0 && $reported_size !== $expected_size) {
            throw new Exception('Le fichier envoyé sur Google Drive est corrompu (taille inattendue).');
        }

        BJLG_Debug::log(sprintf('Sauvegarde "%s" envoyée sur Google Drive (ID: %s).', basename($filepath), $uploaded_file->getId()));
    }

    public function list_remote_backups() {
        if (!$this->sdk_available || !$this->is_connected()) {
            return [];
        }

        try {
            $service = $this->create_drive_service();
            return $this->fetch_remote_backups($service);
        } catch (Exception $exception) {
            BJLG_Debug::log('ERREUR Google Drive (listing) : ' . $exception->getMessage());
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

        if (!$this->sdk_available || !$this->is_connected()) {
            return $result;
        }

        $retain_by_number = (int) $retain_by_number;
        $retain_by_age_days = (int) $retain_by_age_days;

        if ($retain_by_number === 0 && $retain_by_age_days === 0) {
            return $result;
        }

        try {
            $service = $this->create_drive_service();
        } catch (Exception $exception) {
            $result['errors'][] = $exception->getMessage();
            return $result;
        }

        $backups = $this->fetch_remote_backups($service);
        $result['inspected'] = count($backups);

        if (empty($backups)) {
            return $result;
        }

        $to_delete = $this->select_backups_to_delete($backups, $retain_by_number, $retain_by_age_days);

        foreach ($to_delete as $backup) {
            try {
                $this->delete_remote_backup($service, $backup);
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

    private function create_drive_service() {
        $client = $this->build_configured_client();
        return call_user_func($this->drive_factory, $client);
    }

    private function fetch_remote_backups($drive_service) {
        $settings = $this->get_settings();
        $folder_id = $settings['folder_id'] !== '' ? $settings['folder_id'] : 'root';

        $query_parts = ['trashed = false'];
        $query_parts[] = sprintf("'%s' in parents", str_replace("'", "\\'", $folder_id === '' ? 'root' : $folder_id));

        $backups = [];
        $page_token = null;

        do {
            $params = [
                'q' => implode(' and ', $query_parts),
                'fields' => 'nextPageToken, files(id, name, createdTime, modifiedTime, size)',
                'pageSize' => 1000,
                'orderBy' => 'modifiedTime desc',
            ];

            if ($page_token !== null && $page_token !== '') {
                $params['pageToken'] = $page_token;
            }

            try {
                $file_list = $drive_service->files->listFiles($params);
            } catch (Google_Service_Exception $exception) {
                throw new Exception('Erreur lors de la liste des sauvegardes Google Drive : ' . $exception->getMessage(), 0, $exception);
            }

            $files = [];
            if (is_object($file_list) && method_exists($file_list, 'getFiles')) {
                $files = $file_list->getFiles();
            } elseif (is_array($file_list) && isset($file_list['files'])) {
                $files = $file_list['files'];
            }

            if (is_array($files)) {
                foreach ($files as $file) {
                    $entry = $this->normalize_drive_backup_entry($file);
                    if ($entry !== null && $this->is_backup_filename($entry['name'])) {
                        $backups[] = $entry;
                    }
                }
            }

            $page_token = $this->extract_next_page_token($file_list);
        } while ($page_token !== null && $page_token !== '');

        return $backups;
    }

    private function normalize_drive_backup_entry($file) {
        $id = $this->extract_value($file, 'getId', 'id');
        $name = $this->extract_value($file, 'getName', 'name');

        if ($id === null || $name === null) {
            return null;
        }

        $created = $this->extract_value($file, 'getCreatedTime', 'createdTime');
        $modified = $this->extract_value($file, 'getModifiedTime', 'modifiedTime');
        $timestamp_source = $modified !== null ? $modified : $created;
        $timestamp = is_string($timestamp_source) ? strtotime($timestamp_source) : null;

        if (!is_int($timestamp) || $timestamp <= 0) {
            $timestamp = $this->get_time();
        }

        $size = (int) $this->extract_value($file, 'getSize', 'size');

        return [
            'id' => (string) $id,
            'name' => (string) $name,
            'timestamp' => $timestamp,
            'size' => $size,
        ];
    }

    private function delete_remote_backup($drive_service, array $backup) {
        $identifier = isset($backup['id']) ? (string) $backup['id'] : '';
        if ($identifier === '') {
            throw new Exception('Identifiant Google Drive manquant pour la suppression.');
        }

        try {
            $drive_service->files->delete($identifier);
        } catch (Google_Service_Exception $exception) {
            throw new Exception('Suppression Google Drive impossible : ' . $exception->getMessage(), 0, $exception);
        }

        if (class_exists(BJLG_Debug::class)) {
            $name = isset($backup['name']) ? $backup['name'] : $identifier;
            BJLG_Debug::log(sprintf('Sauvegarde distante supprimée sur Google Drive : %s', $name));
        }
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

    private function extract_next_page_token($file_list) {
        if (is_object($file_list)) {
            if (method_exists($file_list, 'getNextPageToken')) {
                $token = $file_list->getNextPageToken();
                if (is_string($token)) {
                    return $token;
                }
            }

            if (isset($file_list->nextPageToken) && is_string($file_list->nextPageToken)) {
                return $file_list->nextPageToken;
            }
        }

        if (is_array($file_list) && isset($file_list['nextPageToken']) && is_string($file_list['nextPageToken'])) {
            return $file_list['nextPageToken'];
        }

        return null;
    }

    private function extract_value($source, $method, $property) {
        if (is_object($source)) {
            if (method_exists($source, $method)) {
                return $source->{$method}();
            }

            if (isset($source->{$property})) {
                return $source->{$property};
            }
        }

        if (is_array($source) && array_key_exists($property, $source)) {
            return $source[$property];
        }

        return null;
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

    /**
     * Teste la connexion à Google Drive.
     *
     * @param array<string, string>|null $settings
     * @return array{message:string,tested_at:int,folder_id:string,folder_name:string}
     * @throws Exception
     */
    public function test_connection(?array $settings = null) {
        if (!$this->sdk_available) {
            throw new Exception('Le SDK Google n\'est pas disponible.');
        }

        $settings = $settings ? $this->merge_settings($settings) : $this->get_settings();

        if ($settings['client_id'] === '' || $settings['client_secret'] === '') {
            throw new Exception('Renseignez le Client ID et le Client Secret Google.');
        }

        $client = $this->build_client();
        $client->setClientId($settings['client_id']);
        $client->setClientSecret($settings['client_secret']);
        $client->setRedirectUri($this->get_redirect_uri());
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setScopes([self::SCOPE]);

        $token = $this->get_stored_token();
        if (empty($token)) {
            throw new Exception('Aucun token OAuth Google Drive n\'a été trouvé. Lancez la procédure de connexion pour autoriser l\'application.');
        }

        $client->setAccessToken($token);

        if ($client->isAccessTokenExpired()) {
            $refresh_token = $client->getRefreshToken();
            if (!$refresh_token) {
                throw new Exception('Le token d\'accès Google Drive est expiré et aucun refresh token n\'est disponible. Reconnectez votre compte.');
            }

            $new_token = $client->fetchAccessTokenWithRefreshToken($refresh_token);
            if (isset($new_token['error'])) {
                throw new Exception('Impossible de rafraîchir le token Google Drive : ' . $new_token['error']);
            }

            if (!isset($new_token['refresh_token']) && isset($token['refresh_token'])) {
                $new_token['refresh_token'] = $token['refresh_token'];
            }

            $this->store_token($new_token);
            $client->setAccessToken($new_token);
        }

        try {
            $drive_service = call_user_func($this->drive_factory, $client);
        } catch (\Throwable $throwable) {
            throw new Exception('Impossible d\'initialiser le client Google Drive : ' . $throwable->getMessage(), 0, $throwable);
        }

        $folder_id = $settings['folder_id'] !== '' ? $settings['folder_id'] : 'root';

        try {
            $metadata = $drive_service->files->get($folder_id, [
                'fields' => 'id,name,mimeType',
                'supportsAllDrives' => true,
            ]);
        } catch (Google_Service_Exception $service_exception) {
            $message = $this->extract_google_error_message($service_exception);
            throw new Exception('Google Drive a renvoyé une erreur : ' . $message, 0, $service_exception);
        } catch (\Throwable $throwable) {
            throw new Exception('Erreur lors de la communication avec Google Drive : ' . $throwable->getMessage(), 0, $throwable);
        }

        if (!$metadata || !$metadata->getId()) {
            throw new Exception('La réponse de Google Drive ne contient pas les métadonnées attendues.');
        }

        $folder_name = $metadata->getName();
        if ($folder_name === null || $folder_name === '') {
            $folder_name = $folder_id === 'root' ? 'Dossier racine' : $metadata->getId();
        }

        $status_message = sprintf('Dossier "%s" (%s) accessible.', $folder_name, $metadata->getId());
        $tested_at = time();

        $this->store_status([
            'last_result' => 'success',
            'tested_at' => $tested_at,
            'message' => $status_message,
        ]);

        return [
            'message' => $status_message,
            'tested_at' => $tested_at,
            'folder_id' => $metadata->getId(),
            'folder_name' => $folder_name,
        ];
    }

    /**
     * Gère la requête AJAX de test de connexion.
     */
    public function handle_test_connection() {
        if (!$this->sdk_available) {
            wp_send_json_error(['message' => 'Le SDK Google n\'est pas disponible.'], 500);
        }

        if (!\bjlg_can_manage_plugin()) {
            wp_send_json_error(['message' => 'Permission refusée.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $settings = [
            'client_id' => isset($_POST['gdrive_client_id']) ? sanitize_text_field(wp_unslash($_POST['gdrive_client_id'])) : '',
            'client_secret' => isset($_POST['gdrive_client_secret']) ? sanitize_text_field(wp_unslash($_POST['gdrive_client_secret'])) : '',
            'folder_id' => isset($_POST['gdrive_folder_id']) ? sanitize_text_field(wp_unslash($_POST['gdrive_folder_id'])) : '',
        ];

        try {
            $result = $this->test_connection($settings);
            $response = [
                'message' => $result['message'],
                'status_message' => $result['message'],
                'tested_at' => $result['tested_at'],
                'tested_at_formatted' => gmdate('d/m/Y H:i:s', $result['tested_at']),
                'folder_id' => $result['folder_id'],
                'folder_name' => $result['folder_name'],
            ];

            if (class_exists(BJLG_Debug::class)) {
                BJLG_Debug::log('Test de connexion Google Drive réussi. ' . $result['message']);
            }

            wp_send_json_success($response);
        } catch (Exception $exception) {
            $tested_at = time();
            $this->store_status([
                'last_result' => 'error',
                'tested_at' => $tested_at,
                'message' => $exception->getMessage(),
            ]);

            $response = [
                'message' => $exception->getMessage(),
                'status_message' => $exception->getMessage(),
                'tested_at' => $tested_at,
                'tested_at_formatted' => gmdate('d/m/Y H:i:s', $tested_at),
            ];

            if (class_exists(BJLG_Debug::class)) {
                BJLG_Debug::log('ERREUR test connexion Google Drive : ' . $exception->getMessage());
            }

            wp_send_json_error($response, 400);
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

        if (!\bjlg_can_manage_plugin()) {
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
        if (!\bjlg_can_manage_plugin()) {
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
        $settings = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        return $this->merge_settings($settings);
    }

    /**
     * Fusionne les réglages fournis avec les valeurs par défaut.
     *
     * @param array<string, mixed> $settings
     * @return array{client_id:string,client_secret:string,folder_id:string,enabled:bool}
     */
    private function merge_settings(array $settings) {
        return array_merge($this->get_default_settings(), $settings);
    }

    /**
     * Retourne les réglages par défaut pour Google Drive.
     *
     * @return array{client_id:string,client_secret:string,folder_id:string,enabled:bool}
     */
    private function get_default_settings() {
        return [
            'client_id' => '',
            'client_secret' => '',
            'folder_id' => '',
            'enabled' => false,
        ];
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

    /**
     * Récupère le statut des derniers tests de connexion.
     *
     * @return array{last_result:?string,tested_at:int,message:string}
     */
    private function get_status() {
        $defaults = [
            'last_result' => null,
            'tested_at' => 0,
            'message' => '',
        ];

        $status = get_option(self::OPTION_STATUS, $defaults);
        if (!is_array($status)) {
            $status = [];
        }

        return array_merge($defaults, $status);
    }

    /**
     * Mémorise le statut d'un test de connexion.
     *
     * @param array{last_result:?string,tested_at:int,message:string} $status
     * @return void
     */
    private function store_status(array $status) {
        $current = $this->get_status();
        update_option(self::OPTION_STATUS, array_merge($current, $status));
    }

    /**
     * Extrait un message pertinent d'une exception Google.
     *
     * @param Google_Service_Exception $exception
     * @return string
     */
    private function extract_google_error_message(Google_Service_Exception $exception) {
        $errors = $exception->getErrors();
        if (is_array($errors) && isset($errors[0]['message']) && $errors[0]['message'] !== '') {
            return $errors[0]['message'];
        }

        $message = $exception->getMessage();
        if ($message) {
            return $message;
        }

        return 'Erreur inconnue retournée par Google Drive.';
    }
}
