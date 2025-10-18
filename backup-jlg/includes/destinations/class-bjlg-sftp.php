<?php
namespace BJLG;

use Exception;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP as PhpseclibSFTP;
use phpseclib3\Exception\UnableToConnectException;

if (!defined('ABSPATH')) {
    exit;
}

if (!interface_exists(BJLG_Destination_Interface::class)) {
    return;
}

$bjlg_base_dir = defined('BJLG_PLUGIN_DIR') ? BJLG_PLUGIN_DIR : dirname(__DIR__, 2) . '/';
$bjlg_autoload = $bjlg_base_dir . 'vendor-bjlg/autoload.php';
if (file_exists($bjlg_autoload)) {
    require_once $bjlg_autoload;
}

/**
 * Destination SFTP permettant de téléverser les sauvegardes sur un serveur SSH.
 */
class BJLG_SFTP implements BJLG_Destination_Interface {

    private const OPTION_SETTINGS = 'bjlg_sftp_settings';
    private const OPTION_STATUS = 'bjlg_sftp_status';

    /** @var callable */
    private $connection_factory;

    /** @var callable */
    private $time_provider;

    public function __construct(?callable $connection_factory = null, ?callable $time_provider = null) {
        $this->connection_factory = $connection_factory ?: static function (string $host, int $port) {
            return new PhpseclibSFTP($host, $port);
        };
        $this->time_provider = $time_provider ?: static function () {
            return time();
        };

        if (function_exists('add_action')) {
            add_action('wp_ajax_bjlg_test_sftp_connection', [$this, 'handle_test_connection']);
            add_action('admin_post_bjlg_sftp_disconnect', [$this, 'handle_disconnect_request']);
        }
    }

    public function get_id() {
        return 'sftp';
    }

    public function get_name() {
        return 'Serveur SFTP';
    }

    public function is_connected() {
        $settings = $this->get_settings();

        return !empty($settings['enabled'])
            && $settings['host'] !== ''
            && $settings['username'] !== ''
            && ($settings['password'] !== '' || $settings['private_key'] !== '');
    }

    public function disconnect() {
        $defaults = $this->get_default_settings();
        bjlg_update_option(self::OPTION_SETTINGS, $defaults);

        if (function_exists('delete_option')) {
            bjlg_delete_option(self::OPTION_STATUS);
        } else {
            bjlg_update_option(self::OPTION_STATUS, []);
        }
    }

    public function render_settings() {
        $settings = $this->get_settings();
        $status = $this->get_status();
        $connected = $this->is_connected();
        $phpseclib_available = class_exists(PhpseclibSFTP::class);

        echo "<div class='bjlg-destination bjlg-destination--sftp'>";
        echo "<h4><span class='dashicons dashicons-shield-alt' aria-hidden='true'></span> Serveur SFTP</h4>";

        if (!$phpseclib_available) {
            echo "<p class='description'>L'extension PHP <code>phpseclib3</code> est requise pour le support SFTP. Installez les dépendances via Composer.</p></div>";
            return;
        }

        echo "<form class='bjlg-settings-form bjlg-destination-form' novalidate>";
        echo "<div class='bjlg-settings-feedback notice bjlg-hidden' role='status' aria-live='polite'></div>";
        echo "<p class='description'>Connectez un serveur SFTP sécurisé pour répliquer vos sauvegardes hors site.</p>";

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Hôte</th><td><input type='text' name='sftp_host' value='" . esc_attr($settings['host']) . "' class='regular-text' placeholder='sftp.example.com' autocomplete='off'></td></tr>";
        echo "<tr><th scope='row'>Port</th><td><input type='number' name='sftp_port' value='" . esc_attr((string) $settings['port']) . "' class='small-text' min='1' max='65535'></td></tr>";
        echo "<tr><th scope='row'>Utilisateur</th><td><input type='text' name='sftp_username' value='" . esc_attr($settings['username']) . "' class='regular-text' autocomplete='off'></td></tr>";
        echo "<tr><th scope='row'>Mot de passe</th><td><input type='password' name='sftp_password' value='" . esc_attr($settings['password']) . "' class='regular-text' autocomplete='new-password'><p class='description'>Laissez vide si vous utilisez une clé privée.</p></td></tr>";
        echo "<tr><th scope='row'>Clé privée</th><td><textarea name='sftp_private_key' rows='5' class='large-text code' placeholder='-----BEGIN OPENSSH PRIVATE KEY-----&#10;...&#10;-----END OPENSSH PRIVATE KEY-----'>" . esc_textarea($settings['private_key']) . "</textarea><p class='description'>Format OpenSSH ou PEM. Optionnel.</p></td></tr>";
        echo "<tr><th scope='row'>Phrase secrète</th><td><input type='password' name='sftp_passphrase' value='" . esc_attr($settings['passphrase']) . "' class='regular-text' autocomplete='new-password'></td></tr>";
        echo "<tr><th scope='row'>Chemin distant</th><td><input type='text' name='sftp_remote_path' value='" . esc_attr($settings['remote_path']) . "' class='regular-text' placeholder='/backups/wordpress'><p class='description'>Dossier cible pour les sauvegardes. Il sera créé s'il n'existe pas.</p></td></tr>";
        echo "<tr><th scope='row'>Empreinte SSH attendue</th><td><input type='text' name='sftp_fingerprint' value='" . esc_attr($settings['fingerprint']) . "' class='regular-text' placeholder='SHA256:...'><p class='description'>Optionnel. Ajoutez l'empreinte SHA256 du serveur pour renforcer la sécurité.</p></td></tr>";
        echo "<tr><th scope='row'>Activer SFTP</th><td><label><input type='checkbox' name='sftp_enabled' value='true'" . ($settings['enabled'] ? " checked='checked'" : '') . "> Activer l'envoi automatique vers ce serveur.</label></td></tr>";
        echo "</table>";

        echo "<div class='notice bjlg-sftp-test-feedback' role='status' aria-live='polite' style='display:none;'></div>";
        echo "<p class='bjlg-sftp-test-actions'><button type='button' class='button bjlg-sftp-test-connection'>Tester la connexion</button> <span class='spinner bjlg-sftp-test-spinner' style='float:none;margin-left:8px;display:none;'></span></p>";

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

        if ($connected) {
            echo "<p class='description'><span class='dashicons dashicons-lock' aria-hidden='true'></span> Connexion SFTP configurée.</p>";
        }

        echo "<p class='submit'><button type='submit' class='button button-primary'>Enregistrer les réglages</button></p>";
        echo "</form>";

        if ($connected) {
            echo "<form method='post' action='" . esc_url(admin_url('admin-post.php')) . "' class='bjlg-destination-disconnect-form'>";
            echo "<input type='hidden' name='action' value='bjlg_sftp_disconnect'>";
            if (function_exists('wp_nonce_field')) {
                wp_nonce_field('bjlg_sftp_disconnect', 'bjlg_sftp_nonce');
            }
            echo "<button type='submit' class='button'>Déconnecter SFTP</button>";
            echo '</form>';
        }

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
                throw new Exception('Erreurs SFTP : ' . implode(' | ', $errors));
            }

            return;
        }

        if (!class_exists(PhpseclibSFTP::class)) {
            throw new Exception("La bibliothèque phpseclib n'est pas disponible pour SFTP.");
        }

        if (!is_readable($filepath)) {
            throw new Exception('Fichier de sauvegarde introuvable : ' . $filepath);
        }

        $settings = $this->get_settings();
        if (!$this->is_connected()) {
            throw new Exception("Le connecteur SFTP n'est pas configuré.");
        }

        $connection = $this->connect($settings);

        $remote_path = $this->normalize_remote_path($settings['remote_path']);
        if ($remote_path !== '' && !$this->ensure_remote_directory($connection, $remote_path)) {
            throw new Exception('Impossible de créer le dossier distant : ' . $remote_path);
        }

        $remote_file = rtrim($remote_path, '/') . '/' . basename($filepath);
        $remote_file = ltrim($remote_file, '/');

        $this->log(sprintf('Transfert de "%s" vers SFTP (%s).', basename($filepath), $remote_file));

        $success = $connection->put($remote_file, $filepath, PhpseclibSFTP::SOURCE_LOCAL_FILE);

        if (!$success) {
            throw new Exception('Impossible de téléverser la sauvegarde via SFTP.');
        }

        $this->log(sprintf('Sauvegarde "%s" envoyée sur le serveur SFTP.', basename($filepath)));
    }

    public function list_remote_backups() {
        if (!class_exists(PhpseclibSFTP::class) || !$this->is_connected()) {
            return [];
        }

        $settings = $this->get_settings();

        try {
            $connection = $this->connect($settings);
            return $this->fetch_remote_backups($connection, $settings);
        } catch (Exception $exception) {
            $this->log('ERREUR SFTP (listing) : ' . $exception->getMessage());
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

        if (!class_exists(PhpseclibSFTP::class) || !$this->is_connected()) {
            return $result;
        }

        $retain_by_number = (int) $retain_by_number;
        $retain_by_age_days = (int) $retain_by_age_days;

        if ($retain_by_number === 0 && $retain_by_age_days === 0) {
            return $result;
        }

        $settings = $this->get_settings();

        try {
            $connection = $this->connect($settings);
            $backups = $this->fetch_remote_backups($connection, $settings);
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
                $this->delete_remote_backup($connection, $backup);
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

        if (!class_exists(PhpseclibSFTP::class) || !$this->is_connected()) {
            $outcome['message'] = __('Le connecteur SFTP n\'est pas disponible.', 'backup-jlg');

            return $outcome;
        }

        $filename = basename((string) $filename);
        if ($filename === '') {
            $outcome['message'] = __('Nom de fichier invalide.', 'backup-jlg');

            return $outcome;
        }

        $settings = $this->get_settings();

        try {
            $backups = $this->list_remote_backups();
            foreach ($backups as $backup) {
                if (($backup['name'] ?? '') !== $filename) {
                    continue;
                }

                $connection = $this->connect($settings);
                $this->delete_remote_backup($connection, $backup);

                $outcome['success'] = true;

                return $outcome;
            }

            $outcome['message'] = __('Sauvegarde distante introuvable sur le serveur SFTP.', 'backup-jlg');
        } catch (Exception $exception) {
            $outcome['message'] = $exception->getMessage();
        }

        return $outcome;
    }

    public function get_storage_usage() {
        $defaults = [
            'used_bytes' => null,
            'quota_bytes' => null,
            'free_bytes' => null,
        ];

        if (!class_exists(PhpseclibSFTP::class) || !$this->is_connected()) {
            return $defaults;
        }

        try {
            $backups = $this->list_remote_backups();
        } catch (Exception $exception) {
            if (class_exists(BJLG_Debug::class)) {
                BJLG_Debug::log('Impossible de récupérer les métriques SFTP : ' . $exception->getMessage());
            }

            return $defaults;
        }

        $used = 0;
        foreach ($backups as $backup) {
            $used += isset($backup['size']) ? (int) $backup['size'] : 0;
        }

        return [
            'used_bytes' => $used,
            'quota_bytes' => null,
            'free_bytes' => null,
        ];
    }

    private function fetch_remote_backups(PhpseclibSFTP $connection, array $settings): array {
        $remote_path = $this->normalize_remote_path($settings['remote_path']);
        $target = $remote_path !== '' ? $remote_path : '.';

        $list = $connection->rawlist($target);
        if (!is_array($list)) {
            return [];
        }

        $backups = [];

        foreach ($list as $name => $details) {
            if (!is_string($name) || $name === '' || $name === '.' || $name === '..') {
                continue;
            }

            if (!is_array($details)) {
                continue;
            }

            $type = isset($details['type']) ? (int) $details['type'] : null;
            $is_file = $type === null || $type === PhpseclibSFTP::TYPE_REGULAR;

            if (!$is_file) {
                continue;
            }

            if (!$this->is_backup_filename($name)) {
                continue;
            }

            $path = $remote_path !== '' ? $remote_path . '/' . $name : $name;
            $timestamp = isset($details['mtime']) ? (int) $details['mtime'] : $this->get_time();
            $size = isset($details['size']) ? (int) $details['size'] : 0;

            $backups[] = [
                'id' => $path,
                'path' => $path,
                'name' => $name,
                'timestamp' => $timestamp,
                'size' => $size,
            ];
        }

        return $backups;
    }

    private function delete_remote_backup(PhpseclibSFTP $connection, array $backup): void {
        $path = '';
        if (!empty($backup['path'])) {
            $path = (string) $backup['path'];
        } elseif (!empty($backup['id'])) {
            $path = (string) $backup['id'];
        }

        if ($path === '') {
            throw new Exception('Chemin distant manquant pour la suppression SFTP.');
        }

        if (!$connection->delete($path)) {
            throw new Exception(sprintf('Impossible de supprimer la sauvegarde SFTP "%s".', $path));
        }

        $this->log(sprintf('Sauvegarde distante supprimée sur le serveur SFTP : %s', $path));
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
        foreach (['path', 'id', 'name'] as $key) {
            if (!empty($backup[$key])) {
                return (string) $backup[$key];
            }
        }

        return sha1(json_encode($backup));
    }

    private function is_backup_filename($name): bool {
        if (!is_string($name) || $name === '') {
            return false;
        }

        return (bool) preg_match('/\.zip(\.[A-Za-z0-9]+)?$/i', $name);
    }

    public function handle_test_connection() {
        if (!\bjlg_can_manage_integrations()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $site_switched = false;
        if (function_exists('is_multisite') && is_multisite()) {
            $requested = isset($_POST['site_id']) ? absint(wp_unslash($_POST['site_id'])) : 0;

            if ($requested > 0) {
                if (!current_user_can('manage_network_options')) {
                    wp_send_json_error(['message' => __('Droits réseau insuffisants.', 'backup-jlg')], 403);
                }

                if (!function_exists('get_site') || !get_site($requested)) {
                    wp_send_json_error(['message' => __('Site introuvable.', 'backup-jlg')], 404);
                }

                $site_switched = BJLG_Site_Context::switch_to_site($requested);

                if (!$site_switched && (!function_exists('get_current_blog_id') || get_current_blog_id() !== $requested)) {
                    wp_send_json_error(['message' => __('Impossible de basculer sur le site demandé.', 'backup-jlg')], 500);
                }
            }
        }

        $posted = wp_unslash($_POST);

        $settings = [
            'host' => isset($posted['sftp_host']) ? sanitize_text_field($posted['sftp_host']) : '',
            'port' => isset($posted['sftp_port']) ? max(1, min(65535, (int) $posted['sftp_port'])) : 22,
            'username' => isset($posted['sftp_username']) ? sanitize_text_field($posted['sftp_username']) : '',
            'password' => isset($posted['sftp_password']) ? (string) $posted['sftp_password'] : '',
            'private_key' => isset($posted['sftp_private_key']) ? (string) $posted['sftp_private_key'] : '',
            'passphrase' => isset($posted['sftp_passphrase']) ? (string) $posted['sftp_passphrase'] : '',
            'remote_path' => isset($posted['sftp_remote_path']) ? sanitize_text_field($posted['sftp_remote_path']) : '',
            'fingerprint' => isset($posted['sftp_fingerprint']) ? sanitize_text_field($posted['sftp_fingerprint']) : '',
            'enabled' => !empty($posted['sftp_enabled']) && $posted['sftp_enabled'] !== 'false',
        ];

        if ($settings['host'] === '' || $settings['username'] === '') {
            if ($site_switched) {
                BJLG_Site_Context::restore_site($site_switched);
            }
            wp_send_json_error([
                'message' => "Impossible de tester la connexion.",
                'errors' => ['Renseignez au minimum l\'hôte et l\'utilisateur.'],
            ]);
        }

        try {
            $connection = $this->connect($settings);
            $cwd = $connection->pwd();
            $message = $cwd ? sprintf('Répertoire courant : %s', $cwd) : 'Connexion établie.';

            $this->store_settings($settings);
            $this->store_status('success', $message);

            if ($site_switched) {
                BJLG_Site_Context::restore_site($site_switched);
            }

            wp_send_json_success([
                'message' => 'Connexion SFTP réussie !',
                'details' => $message,
            ]);
        } catch (Exception $exception) {
            $this->store_status('error', $exception->getMessage());
            if ($site_switched) {
                BJLG_Site_Context::restore_site($site_switched);
            }
            wp_send_json_error([
                'message' => "Connexion SFTP impossible.",
                'errors' => [$exception->getMessage()],
            ]);
        }
    }

    public function handle_disconnect_request() {
        if (!\bjlg_can_manage_integrations()) {
            wp_die('Permission refusée.');
        }

        check_admin_referer('bjlg_sftp_disconnect', 'bjlg_sftp_nonce');

        $this->disconnect();

        wp_safe_redirect(add_query_arg(['page' => 'backup-jlg', 'section' => 'settings'], admin_url('admin.php')));
        exit;
    }

    private function connect(array $settings) {
        if (!class_exists(PhpseclibSFTP::class)) {
            throw new Exception("La bibliothèque phpseclib n'est pas disponible.");
        }

        try {
            /** @var PhpseclibSFTP $connection */
            $connection = call_user_func($this->connection_factory, $settings['host'], (int) $settings['port']);
        } catch (UnableToConnectException $exception) {
            throw new Exception('Connexion impossible : ' . $exception->getMessage());
        }

        if (!$connection instanceof PhpseclibSFTP) {
            throw new Exception('Impossible d\'initialiser la connexion SFTP.');
        }

        $fingerprint = $settings['fingerprint'];
        if ($fingerprint !== '') {
            $server_fp = $connection->getServerPublicHostKey() ? $connection->getServerPublicHostKey()->getFingerprint('sha256') : '';
            if ($server_fp === '' || !hash_equals(strtolower($fingerprint), strtolower($server_fp))) {
                throw new Exception('Empreinte du serveur inattendue.');
            }
        }

        $auth_success = false;
        if ($settings['private_key'] !== '') {
            try {
                $key = PublicKeyLoader::load($settings['private_key'], $settings['passphrase'] !== '' ? $settings['passphrase'] : false);
            } catch (Exception $exception) {
                throw new Exception('Clé privée invalide : ' . $exception->getMessage());
            }
            $auth_success = $connection->login($settings['username'], $key);
        }

        if (!$auth_success && $settings['password'] !== '') {
            $auth_success = $connection->login($settings['username'], $settings['password']);
        }

        if (!$auth_success) {
            throw new Exception('Authentification SFTP refusée.');
        }

        return $connection;
    }

    private function ensure_remote_directory(PhpseclibSFTP $connection, string $path): bool {
        $normalized = $this->normalize_remote_path($path);
        if ($normalized === '') {
            return true;
        }

        $parts = array_filter(explode('/', $normalized), static function ($part) {
            return $part !== '' && $part !== '.';
        });

        $current = '';
        foreach ($parts as $part) {
            $current .= '/' . $part;
            if (!$connection->is_dir($current)) {
                if (!$connection->mkdir($current)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function normalize_remote_path(string $path): string {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $path = str_replace(['\\'], '/', $path);
        $path = preg_replace('#/+#', '/', $path);

        return trim($path, '/');
    }

    private function get_settings(): array {
        $stored = bjlg_get_option(self::OPTION_SETTINGS, []);
        $defaults = $this->get_default_settings();

        if (!is_array($stored)) {
            $stored = [];
        }

        $settings = wp_parse_args($stored, $defaults);

        $settings['host'] = sanitize_text_field($settings['host']);
        $settings['port'] = (int) $settings['port'];
        if ($settings['port'] <= 0 || $settings['port'] > 65535) {
            $settings['port'] = 22;
        }

        $settings['username'] = sanitize_text_field($settings['username']);
        $settings['password'] = (string) $settings['password'];
        $settings['private_key'] = (string) $settings['private_key'];
        $settings['passphrase'] = (string) $settings['passphrase'];
        $settings['remote_path'] = sanitize_text_field($settings['remote_path']);
        $settings['fingerprint'] = sanitize_text_field($settings['fingerprint']);
        $settings['enabled'] = !empty($settings['enabled']);

        return $settings;
    }

    private function get_default_settings(): array {
        return [
            'host' => '',
            'port' => 22,
            'username' => '',
            'password' => '',
            'private_key' => '',
            'passphrase' => '',
            'remote_path' => '',
            'fingerprint' => '',
            'enabled' => false,
        ];
    }

    private function store_settings(array $settings): void {
        $defaults = $this->get_default_settings();
        $normalized = array_merge($defaults, $settings);
        bjlg_update_option(self::OPTION_SETTINGS, $normalized);
    }

    private function get_status(): array {
        $status = bjlg_get_option(self::OPTION_STATUS, []);
        if (!is_array($status)) {
            $status = [];
        }

        return wp_parse_args($status, [
            'last_result' => 'unknown',
            'tested_at' => 0,
            'message' => '',
        ]);
    }

    private function get_time(): int {
        return (int) call_user_func($this->time_provider);
    }

    private function store_status(string $result, string $message): void {
        $status = [
            'last_result' => $result,
            'tested_at' => $this->get_time(),
            'message' => $message,
        ];

        bjlg_update_option(self::OPTION_STATUS, $status);
    }

    private function log($message): void {
        if (class_exists(BJLG_Debug::class)) {
            BJLG_Debug::log($message);
        }
    }
}

