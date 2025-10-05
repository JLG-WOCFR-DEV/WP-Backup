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
 * Destination Microsoft OneDrive utilisant l'API Microsoft Graph.
 */
class BJLG_OneDrive implements BJLG_Destination_Interface {

    private const OPTION_SETTINGS = 'bjlg_onedrive_settings';
    private const OPTION_STATUS = 'bjlg_onedrive_status';

    /** @var callable */
    private $request_handler;

    /** @var callable */
    private $time_provider;

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

        if (function_exists('add_action')) {
            add_action('wp_ajax_bjlg_test_onedrive_connection', [$this, 'handle_test_connection']);
            add_action('admin_post_bjlg_onedrive_disconnect', [$this, 'handle_disconnect_request']);
        }
    }

    public function get_id() {
        return 'onedrive';
    }

    public function get_name() {
        return 'Microsoft OneDrive';
    }

    public function is_connected() {
        $settings = $this->get_settings();

        return !empty($settings['enabled'])
            && $settings['access_token'] !== ''
            && $settings['drive_id'] !== '';
    }

    public function disconnect() {
        update_option(self::OPTION_SETTINGS, $this->get_default_settings());

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

        echo "<div class='bjlg-destination bjlg-destination--onedrive'>";
        echo "<h4><span class='dashicons dashicons-cloud' aria-hidden='true'></span> Microsoft OneDrive</h4>";
        echo "<p class='description'>Centralisez vos sauvegardes dans un dossier OneDrive via Microsoft Graph.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Access Token</th><td><textarea name='onedrive_access_token' rows='4' class='large-text code' placeholder='eyJ0eXAiOiJKV1QiLCJhbGciOiJ...'>" . esc_textarea($settings['access_token']) . "</textarea><p class='description'>Token OAuth 2.0 disposant des permissions <code>Files.ReadWrite.All</code>.</p></td></tr>";
        echo "<tr><th scope='row'>Drive ID</th><td><input type='text' name='onedrive_drive_id' value='" . esc_attr($settings['drive_id']) . "' class='regular-text' placeholder='b!abc123...'><p class='description'>Identifiant du Drive cible (OneDrive Personnel ou Business).</p></td></tr>";
        echo "<tr><th scope='row'>Dossier cible</th><td><input type='text' name='onedrive_folder_path' value='" . esc_attr($settings['folder_path']) . "' class='regular-text' placeholder='/Sauvegardes/WordPress'><p class='description'>Chemin relatif dans le drive. Laissez vide pour la racine.</p></td></tr>";

        $enabled_attr = !empty($settings['enabled']) ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer OneDrive</th><td><label><input type='checkbox' name='onedrive_enabled' value='true'{$enabled_attr}> Activer l'envoi automatique vers OneDrive.</label></td></tr>";
        echo "</table>";

        echo "<div class='notice bjlg-onedrive-test-feedback bjlg-hidden' role='status' aria-live='polite'></div>";
        echo "<p><button type='button' class='button bjlg-onedrive-test-connection'>Tester la connexion</button></p>";

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
            echo "<p class='description'><span class='dashicons dashicons-lock' aria-hidden='true'></span> Connexion OneDrive configurée.</p>";
            echo "<form method='post' action='" . esc_url(admin_url('admin-post.php')) . "' style='margin-top:10px;'>";
            echo "<input type='hidden' name='action' value='bjlg_onedrive_disconnect'>";
            if (function_exists('wp_nonce_field')) {
                wp_nonce_field('bjlg_onedrive_disconnect', 'bjlg_onedrive_nonce');
            }
            echo "<button type='submit' class='button'>Déconnecter OneDrive</button>";
            echo '</form>';
        }

        echo '</div>';
    }

    public function upload_file($filepath, $task_id) {
        if (is_array($filepath)) {
            $errors = [];
            foreach ($filepath as $single) {
                try {
                    $this->upload_file($single, $task_id);
                } catch (Exception $exception) {
                    $errors[] = $exception->getMessage();
                }
            }

            if (!empty($errors)) {
                throw new Exception('OneDrive : ' . implode(' | ', $errors));
            }

            return;
        }

        if (!is_readable($filepath)) {
            throw new Exception('Fichier de sauvegarde introuvable : ' . $filepath);
        }

        $settings = $this->get_settings();
        if (!$this->is_connected()) {
            throw new Exception("OneDrive n'est pas configuré.");
        }

        $body = file_get_contents($filepath);
        if ($body === false) {
            throw new Exception('Impossible de lire le fichier de sauvegarde.');
        }

        $upload_url = $this->build_upload_url($settings['drive_id'], $settings['folder_path'], basename($filepath));

        $response = call_user_func($this->request_handler, $upload_url, [
            'method' => 'PUT',
            'headers' => [
                'Authorization' => 'Bearer ' . $settings['access_token'],
                'Content-Type' => 'application/zip',
            ],
            'body' => $body,
            'timeout' => apply_filters('bjlg_onedrive_upload_timeout', 60, $upload_url),
        ]);

        $this->assert_graph_response($response, 'upload');
        $this->log(sprintf('Sauvegarde "%s" envoyée sur OneDrive.', basename($filepath)));
    }

    public function list_remote_backups() {
        if (!$this->is_connected()) {
            return [];
        }

        $settings = $this->get_settings();
        $path = $this->normalize_folder_path($settings['folder_path']);
        $drive_id = $settings['drive_id'];
        $token = $settings['access_token'];

        if ($path === '') {
            $endpoint = sprintf('https://graph.microsoft.com/v1.0/drives/%s/root/children?$select=id,name,size,file,lastModifiedDateTime,createdDateTime', rawurlencode($drive_id));
        } else {
            $encoded_path = $this->encode_graph_path($path);
            $endpoint = sprintf('https://graph.microsoft.com/v1.0/drives/%s/root:/%s:/children?$select=id,name,size,file,lastModifiedDateTime,createdDateTime', rawurlencode($drive_id), $encoded_path);
        }

        $response = call_user_func($this->request_handler, $endpoint, [
            'method' => 'GET',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);

        $data = $this->parse_json_response($response, 'list');

        $files = [];
        if (!empty($data['value']) && is_array($data['value'])) {
            foreach ($data['value'] as $item) {
                if (!is_array($item) || empty($item['file'])) {
                    continue;
                }

                $name = (string) ($item['name'] ?? '');
                if (!$this->is_backup_filename($name)) {
                    continue;
                }

                $files[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'name' => $name,
                    'timestamp' => isset($item['lastModifiedDateTime']) ? strtotime($item['lastModifiedDateTime']) : $this->get_time(),
                    'size' => isset($item['size']) ? (int) $item['size'] : 0,
                ];
            }
        }

        return $files;
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

        try {
            $files = $this->list_remote_backups();
        } catch (Exception $exception) {
            $result['errors'][] = $exception->getMessage();
            return $result;
        }

        $result['inspected'] = count($files);
        if (empty($files)) {
            return $result;
        }

        $to_delete = $this->select_backups_to_delete($files, $retain_by_number, $retain_by_age_days);
        foreach ($to_delete as $file) {
            try {
                $this->delete_remote_backup($file);
                $result['deleted']++;
                if (!empty($file['name'])) {
                    $result['deleted_items'][] = $file['name'];
                }
            } catch (Exception $exception) {
                $result['errors'][] = $exception->getMessage();
            }
        }

        return $result;
    }

    public function handle_test_connection() {
        if (!\bjlg_can_manage_plugin()) {
            wp_send_json_error(['message' => 'Permission refusée.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $token = isset($_POST['onedrive_access_token']) ? wp_unslash($_POST['onedrive_access_token']) : '';
        $drive_id = isset($_POST['onedrive_drive_id']) ? sanitize_text_field(wp_unslash($_POST['onedrive_drive_id'])) : '';

        try {
            $this->test_connection($token, $drive_id);
            $message = 'Connexion OneDrive vérifiée.';
            $this->store_status([
                'last_result' => 'success',
                'tested_at' => $this->get_time(),
                'message' => $message,
            ]);

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

        if (isset($_POST['bjlg_onedrive_nonce'])) {
            $nonce = wp_unslash($_POST['bjlg_onedrive_nonce']);
            if (function_exists('wp_verify_nonce') && !wp_verify_nonce($nonce, 'bjlg_onedrive_disconnect')) {
                return;
            }
        }

        $this->disconnect();

        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect(admin_url('admin.php?page=backup-jlg&tab=settings'));
            exit;
        }
    }

    private function test_connection(string $token, string $drive_id): void {
        $token = trim($token);
        $drive_id = trim($drive_id);

        if ($token === '' || $drive_id === '') {
            throw new Exception('Veuillez renseigner un Access Token et un Drive ID OneDrive.');
        }

        $endpoint = sprintf('https://graph.microsoft.com/v1.0/drives/%s', rawurlencode($drive_id));
        $response = call_user_func($this->request_handler, $endpoint, [
            'method' => 'GET',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 20,
        ]);

        $this->assert_graph_response($response, 'test');
    }

    private function delete_remote_backup(array $file): void {
        $settings = $this->get_settings();
        $drive_id = $settings['drive_id'];
        $token = $settings['access_token'];
        $id = $file['id'] ?? '';

        if ($id === '') {
            throw new Exception('Identifiant OneDrive manquant pour la suppression.');
        }

        $endpoint = sprintf('https://graph.microsoft.com/v1.0/drives/%s/items/%s', rawurlencode($drive_id), rawurlencode($id));
        $response = call_user_func($this->request_handler, $endpoint, [
            'method' => 'DELETE',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
            'timeout' => 20,
        ]);

        $this->assert_graph_response($response, 'delete');
        $this->log(sprintf('Sauvegarde OneDrive supprimée : %s', $file['name'] ?? $id));
    }

    private function select_backups_to_delete(array $backups, int $retain_by_number, int $retain_by_age_days): array {
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

    private function get_backup_identifier(array $backup): string {
        foreach (['id', 'name'] as $key) {
            if (!empty($backup[$key])) {
                return (string) $backup[$key];
            }
        }

        return sha1(json_encode($backup));
    }

    private function build_upload_url(string $drive_id, string $folder_path, string $filename): string {
        $folder = $this->normalize_folder_path($folder_path);
        $encoded_filename = rawurlencode($filename);

        if ($folder === '') {
            return sprintf('https://graph.microsoft.com/v1.0/drives/%s/root:/%s:/content', rawurlencode($drive_id), $encoded_filename);
        }

        $encoded_folder = $this->encode_graph_path($folder);

        return sprintf('https://graph.microsoft.com/v1.0/drives/%s/root:/%s/%s:/content', rawurlencode($drive_id), $encoded_folder, $encoded_filename);
    }

    private function normalize_folder_path(string $folder_path): string {
        $folder_path = trim($folder_path);
        if ($folder_path === '') {
            return '';
        }

        $folder_path = str_replace('\\', '/', $folder_path);
        $folder_path = preg_replace('#/+#', '/', $folder_path);
        $folder_path = trim($folder_path, '/');

        return $folder_path;
    }

    private function encode_graph_path(string $path): string {
        $segments = explode('/', $path);
        $segments = array_map(static function ($segment) {
            return rawurlencode($segment);
        }, $segments);

        return implode('/', $segments);
    }

    private function is_backup_filename($name): bool {
        if (!is_string($name) || $name === '') {
            return false;
        }

        return (bool) preg_match('/\.zip(\.[A-Za-z0-9]+)?$/i', $name);
    }

    private function get_settings(): array {
        $stored = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge($this->get_default_settings(), $stored);
    }

    private function get_default_settings(): array {
        return [
            'access_token' => '',
            'drive_id' => '',
            'folder_path' => '',
            'enabled' => false,
        ];
    }

    private function get_status(): array {
        $status = get_option(self::OPTION_STATUS, []);
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

    private function store_status(array $status): void {
        $current = $this->get_status();
        update_option(self::OPTION_STATUS, array_merge($current, $status));
    }

    private function parse_json_response($response, string $context): array {
        $this->assert_graph_response($response, $context);

        $body = isset($response['body']) ? (string) $response['body'] : '';
        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new Exception(sprintf('Réponse OneDrive invalide lors de %s.', $context));
        }

        return $data;
    }

    private function assert_graph_response($response, string $context): void {
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            throw new Exception(sprintf('Erreur OneDrive (%s) : %s', $context, $response->get_error_message()));
        }

        $status = isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        if ($status < 200 || $status >= 300) {
            $body = isset($response['body']) ? (string) $response['body'] : '';
            throw new Exception(sprintf('OneDrive a renvoyé %d : %s', $status, $body));
        }
    }

    private function get_time(): int {
        return (int) call_user_func($this->time_provider);
    }

    private function log($message): void {
        if (class_exists(BJLG_Debug::class)) {
            BJLG_Debug::log($message);
        }
    }
}

