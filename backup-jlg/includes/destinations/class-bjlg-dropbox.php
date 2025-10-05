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
 * Destination Dropbox basée sur l'API HTTP officielle.
 */
class BJLG_Dropbox implements BJLG_Destination_Interface {

    private const OPTION_SETTINGS = 'bjlg_dropbox_settings';
    private const OPTION_STATUS = 'bjlg_dropbox_status';

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
            add_action('wp_ajax_bjlg_test_dropbox_connection', [$this, 'handle_test_connection']);
            add_action('admin_post_bjlg_dropbox_disconnect', [$this, 'handle_disconnect_request']);
        }
    }

    public function get_id() {
        return 'dropbox';
    }

    public function get_name() {
        return 'Dropbox';
    }

    public function is_connected() {
        $settings = $this->get_settings();

        return !empty($settings['enabled']) && $settings['access_token'] !== '';
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

        echo "<div class='bjlg-destination bjlg-destination--dropbox'>";
        echo "<h4><span class='dashicons dashicons-open-folder' aria-hidden='true'></span> Dropbox</h4>";
        echo "<p class='description'>Envoyez automatiquement vos sauvegardes vers un dossier Dropbox dédié.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Access Token</th><td><input type='text' name='dropbox_access_token' value='" . esc_attr($settings['access_token']) . "' class='regular-text' autocomplete='off' placeholder='sl.BC...'><p class='description'>Générez un token d'accès avec les permissions <code>files.content.write</code> et <code>files.content.read</code>.</p></td></tr>";
        echo "<tr><th scope='row'>Dossier cible</th><td><input type='text' name='dropbox_remote_path' value='" . esc_attr($settings['remote_path']) . "' class='regular-text' placeholder='/Apps/Backup JLG'><p class='description'>Laissez vide pour utiliser le dossier racine de Dropbox.</p></td></tr>";
        $enabled_attr = !empty($settings['enabled']) ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer Dropbox</th><td><label><input type='checkbox' name='dropbox_enabled' value='true'{$enabled_attr}> Activer l'envoi automatique vers Dropbox.</label></td></tr>";
        echo "</table>";

        echo "<div class='notice bjlg-dropbox-test-feedback bjlg-hidden' role='status' aria-live='polite'></div>";
        echo "<p><button type='button' class='button bjlg-dropbox-test-connection'>Tester la connexion</button></p>";

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
            echo "<p class='description'><span class='dashicons dashicons-lock' aria-hidden='true'></span> Connexion Dropbox configurée.</p>";
            echo "<form method='post' action='" . esc_url(admin_url('admin-post.php')) . "' style='margin-top:10px;'>";
            echo "<input type='hidden' name='action' value='bjlg_dropbox_disconnect'>";
            if (function_exists('wp_nonce_field')) {
                wp_nonce_field('bjlg_dropbox_disconnect', 'bjlg_dropbox_nonce');
            }
            echo "<button type='submit' class='button'>Déconnecter Dropbox</button>";
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
                throw new Exception('Dropbox : ' . implode(' | ', $errors));
            }

            return;
        }

        if (!is_readable($filepath)) {
            throw new Exception('Fichier de sauvegarde introuvable : ' . $filepath);
        }

        $settings = $this->get_settings();
        if (!$this->is_connected()) {
            throw new Exception("Dropbox n'est pas configuré.");
        }

        $contents = file_get_contents($filepath);
        if ($contents === false) {
            throw new Exception('Impossible de lire le fichier à envoyer.');
        }

        $dropbox_path = $this->build_dropbox_path($settings['remote_path'], basename($filepath));

        $headers = [
            'Authorization' => 'Bearer ' . $settings['access_token'],
            'Content-Type' => 'application/octet-stream',
            'Dropbox-API-Arg' => $this->encode_json([
                'path' => $dropbox_path,
                'mode' => 'overwrite',
                'mute' => true,
                'strict_conflict' => false,
                'client_modified' => gmdate('c', $this->get_time()),
            ]),
        ];

        $args = [
            'method' => 'POST',
            'headers' => $headers,
            'body' => $contents,
            'timeout' => apply_filters('bjlg_dropbox_upload_timeout', 60, $dropbox_path),
        ];

        $response = call_user_func($this->request_handler, 'https://content.dropboxapi.com/2/files/upload', $args);
        $this->assert_response_success($response, 'upload');

        $this->log(sprintf('Sauvegarde "%s" envoyée sur Dropbox (%s).', basename($filepath), $dropbox_path));
    }

    public function list_remote_backups() {
        if (!$this->is_connected()) {
            return [];
        }

        $settings = $this->get_settings();
        $token = $settings['access_token'];
        $path = $this->normalize_remote_path($settings['remote_path']);

        $entries = [];
        $has_more = true;
        $cursor = null;

        while ($has_more) {
            if ($cursor === null) {
                $body = ['path' => $path, 'recursive' => false, 'include_media_info' => false, 'include_deleted' => false];
                $endpoint = 'https://api.dropboxapi.com/2/files/list_folder';
            } else {
                $body = ['cursor' => $cursor];
                $endpoint = 'https://api.dropboxapi.com/2/files/list_folder/continue';
            }

            $response = call_user_func($this->request_handler, $endpoint, [
                'method' => 'POST',
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => $this->encode_json($body),
                'timeout' => 30,
            ]);

            $data = $this->parse_json_response($response, 'list_folder');

            if (!empty($data['entries']) && is_array($data['entries'])) {
                foreach ($data['entries'] as $entry) {
                    if (!is_array($entry) || ($entry['.tag'] ?? '') !== 'file') {
                        continue;
                    }

                    $name = (string) ($entry['name'] ?? '');
                    if (!$this->is_backup_filename($name)) {
                        continue;
                    }

                    $entries[] = [
                        'id' => (string) ($entry['id'] ?? ''),
                        'path_lower' => (string) ($entry['path_lower'] ?? ''),
                        'name' => $name,
                        'timestamp' => isset($entry['client_modified']) ? strtotime($entry['client_modified']) : $this->get_time(),
                        'size' => isset($entry['size']) ? (int) $entry['size'] : 0,
                    ];
                }
            }

            $has_more = !empty($data['has_more']);
            $cursor = isset($data['cursor']) ? (string) $data['cursor'] : null;
        }

        return $entries;
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
            $entries = $this->list_remote_backups();
        } catch (Exception $exception) {
            $result['errors'][] = $exception->getMessage();
            return $result;
        }

        $result['inspected'] = count($entries);
        if (empty($entries)) {
            return $result;
        }

        $to_delete = $this->select_backups_to_delete($entries, $retain_by_number, $retain_by_age_days);

        foreach ($to_delete as $entry) {
            try {
                $this->delete_remote_backup($entry);
                $result['deleted']++;
                if (!empty($entry['name'])) {
                    $result['deleted_items'][] = $entry['name'];
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

        $token = isset($_POST['dropbox_access_token']) ? sanitize_text_field(wp_unslash($_POST['dropbox_access_token'])) : '';
        $remote_path = isset($_POST['dropbox_remote_path']) ? sanitize_text_field(wp_unslash($_POST['dropbox_remote_path'])) : '';

        try {
            $this->test_connection($token);
            $message = 'Connexion Dropbox vérifiée.';
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

        if (isset($_POST['bjlg_dropbox_nonce'])) {
            $nonce = wp_unslash($_POST['bjlg_dropbox_nonce']);
            if (function_exists('wp_verify_nonce') && !wp_verify_nonce($nonce, 'bjlg_dropbox_disconnect')) {
                return;
            }
        }

        $this->disconnect();

        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect(admin_url('admin.php?page=backup-jlg&tab=settings'));
            exit;
        }
    }

    public function list_remote_backups_for_tests(): array {
        return $this->list_remote_backups();
    }

    private function test_connection(string $token): void {
        if ($token === '') {
            throw new Exception('Veuillez renseigner un Access Token Dropbox.');
        }

        $response = call_user_func($this->request_handler, 'https://api.dropboxapi.com/2/users/get_current_account', [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => '{}',
            'timeout' => 20,
        ]);

        $this->assert_response_success($response, 'test');
    }

    private function delete_remote_backup(array $entry): void {
        $settings = $this->get_settings();
        $token = $settings['access_token'];
        $path = $entry['path_lower'] ?? '';

        if ($path === '') {
            throw new Exception('Chemin Dropbox manquant pour la suppression.');
        }

        $response = call_user_func($this->request_handler, 'https://api.dropboxapi.com/2/files/delete_v2', [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => $this->encode_json(['path' => $path]),
            'timeout' => 30,
        ]);

        $this->assert_response_success($response, 'delete');
        $this->log(sprintf('Sauvegarde Dropbox supprimée : %s', $path));
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
        foreach (['path_lower', 'id', 'name'] as $key) {
            if (!empty($backup[$key])) {
                return (string) $backup[$key];
            }
        }

        return sha1(json_encode($backup));
    }

    private function assert_response_success($response, string $context): void {
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            throw new Exception(sprintf('Erreur Dropbox (%s) : %s', $context, $response->get_error_message()));
        }

        $status = isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        if ($status < 200 || $status >= 300) {
            $body = isset($response['body']) ? (string) $response['body'] : '';
            throw new Exception(sprintf('Dropbox a renvoyé %d : %s', $status, $body));
        }
    }

    private function parse_json_response($response, string $context): array {
        $this->assert_response_success($response, $context);

        $body = isset($response['body']) ? (string) $response['body'] : '';
        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new Exception(sprintf('Réponse Dropbox invalide lors de %s.', $context));
        }

        return $data;
    }

    private function build_dropbox_path(string $remote_path, string $filename): string {
        $base = $this->normalize_remote_path($remote_path);
        $filename = '/' . ltrim($filename, '/');

        if ($base === '') {
            return $filename;
        }

        return rtrim($base, '/') . $filename;
    }

    private function normalize_remote_path(string $remote_path): string {
        $remote_path = trim($remote_path);
        if ($remote_path === '') {
            return '';
        }

        if ($remote_path[0] !== '/') {
            $remote_path = '/' . $remote_path;
        }

        return rtrim($remote_path, '/');
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
            'remote_path' => '',
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

    private function encode_json(array $payload): string {
        if (function_exists('wp_json_encode')) {
            return wp_json_encode($payload);
        }

        return json_encode($payload);
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

