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
 * Destination SFTP pour l'envoi de sauvegardes.
 */
class BJLG_SFTP implements BJLG_Destination_Interface {

    private const OPTION_SETTINGS = 'bjlg_sftp_settings';

    /** @var callable|null */
    private $sftp_factory;

    /** @var callable|null */
    private $key_loader;

    /**
     * @param callable|null $sftp_factory
     * @param callable|null $key_loader
     */
    public function __construct(?callable $sftp_factory = null, ?callable $key_loader = null) {
        $this->sftp_factory = $sftp_factory;
        $this->key_loader = $key_loader;
    }

    public function get_id() {
        return 'sftp';
    }

    public function get_name() {
        return 'SFTP';
    }

    public function is_connected() {
        $settings = $this->get_settings();

        if (!$settings['enabled']) {
            return false;
        }

        if ($settings['host'] === '' || $settings['username'] === '') {
            return false;
        }

        if ($settings['password'] === '' && $settings['private_key'] === '') {
            return false;
        }

        return true;
    }

    public function disconnect() {
        $defaults = $this->get_default_settings();
        update_option(self::OPTION_SETTINGS, $defaults);
    }

    public function render_settings() {
        $settings = $this->get_settings();
        $is_ready = $this->is_connected();

        echo "<div class='bjlg-destination bjlg-destination--sftp'>";
        echo "<h4><span class='dashicons dashicons-migrate'></span> SFTP</h4>";

        if (!$this->is_library_available() && !is_callable($this->sftp_factory)) {
            echo "<p class='description'>La bibliothèque <code>phpseclib</code> (v3) ou l'extension <code>ssh2</code> est requise pour utiliser SFTP.</p>";
        } else {
            echo "<p class='description'>Transférez vos sauvegardes vers un serveur SFTP (compatible avec phpseclib v3).</p>";
        }

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>Hôte</th><td><input type='text' name='sftp_host' value='" . esc_attr($settings['host']) . "' class='regular-text' placeholder='sftp.example.com'></td></tr>";
        echo "<tr><th scope='row'>Port</th><td><input type='number' name='sftp_port' value='" . esc_attr((string) $settings['port']) . "' class='small-text' min='1' max='65535'></td></tr>";
        echo "<tr><th scope='row'>Utilisateur</th><td><input type='text' name='sftp_username' value='" . esc_attr($settings['username']) . "' class='regular-text'></td></tr>";
        echo "<tr><th scope='row'>Mot de passe</th><td><input type='password' name='sftp_password' value='" . esc_attr($settings['password']) . "' class='regular-text'><p class='description'>Laissez vide si vous utilisez une clé privée.</p></td></tr>";
        echo "<tr><th scope='row'>Clé privée</th><td><textarea name='sftp_private_key' rows='6' class='large-text code'>" . esc_textarea($settings['private_key']) . "</textarea><p class='description'>Collez votre clé privée OpenSSH (optionnel). Utilisez la même phrase secrète que le mot de passe si nécessaire.</p></td></tr>";
        echo "<tr><th scope='row'>Dossier distant</th><td><input type='text' name='sftp_remote_path' value='" . esc_attr($settings['remote_path']) . "' class='regular-text' placeholder='/backups/wordpress'><p class='description'>Chemin distant où stocker les sauvegardes. Laissez vide pour le dossier par défaut.</p></td></tr>";
        $enabled_attr = $settings['enabled'] ? " checked='checked'" : '';
        echo "<tr><th scope='row'>Activer SFTP</th><td><label><input type='checkbox' name='sftp_enabled' value='true'{$enabled_attr}> Activer le transfert automatique via SFTP.</label></td></tr>";
        echo "</table>";

        if ($is_ready) {
            echo "<p class='description'><span class='dashicons dashicons-yes'></span> Connexion prête. Les sauvegardes seront transférées vers le serveur SFTP lorsque sélectionné.</p>";
        } else {
            echo "<p class='description'>Complétez la configuration et activez l'intégration pour pouvoir utiliser SFTP.</p>";
        }

        echo "</div>";
    }

    public function upload_file($filepath, $task_id) {
        if (!is_readable($filepath)) {
            throw new Exception('Fichier de sauvegarde introuvable : ' . $filepath);
        }

        $settings = $this->get_settings();

        if (!$settings['enabled']) {
            throw new Exception('L\'intégration SFTP est désactivée.');
        }

        if (!$this->is_connected()) {
            throw new Exception('La destination SFTP n\'est pas configurée correctement.');
        }

        $sftp = $this->create_sftp_client($settings['host'], (int) $settings['port']);

        if (!is_object($sftp)) {
            throw new Exception('Impossible d\'initialiser le client SFTP.');
        }

        $authenticated = false;

        try {
            if ($settings['private_key'] !== '') {
                $key = $this->load_private_key($settings['private_key'], $settings['password']);
                $authenticated = $sftp->login($settings['username'], $key);
            } else {
                $authenticated = $sftp->login($settings['username'], $settings['password']);
            }
        } catch (\Throwable $throwable) {
            throw new Exception('Échec de l\'authentification SFTP : ' . $throwable->getMessage(), 0, $throwable);
        }

        if (!$authenticated) {
            throw new Exception('Identifiants SFTP invalides.');
        }

        $remote_path = trim((string) $settings['remote_path']);
        if ($remote_path !== '') {
            $remote_path = rtrim($remote_path, '/') . '/';
        }
        $remote_path .= basename($filepath);

        $mode_constant = $this->get_sftp_source_constant($sftp);

        try {
            $result = $sftp->put($remote_path, $filepath, $mode_constant);
        } catch (\Throwable $throwable) {
            throw new Exception('Échec de l\'envoi SFTP : ' . $throwable->getMessage(), 0, $throwable);
        }

        if (!$result) {
            throw new Exception('Le transfert SFTP a été refusé par le serveur distant.');
        }

        if (method_exists($sftp, 'disconnect')) {
            $sftp->disconnect();
        }

        if (class_exists(BJLG_Debug::class)) {
            BJLG_Debug::log(sprintf('Sauvegarde "%s" transférée via SFTP (%s).', basename($filepath), $remote_path));
        }
    }

    /**
     * @return array{host:string,port:int,username:string,password:string,private_key:string,remote_path:string,enabled:bool}
     */
    private function get_settings() {
        $settings = get_option(self::OPTION_SETTINGS, $this->get_default_settings());

        if (!is_array($settings)) {
            $settings = [];
        }

        $merged = array_merge($this->get_default_settings(), $settings);
        $merged['port'] = (int) $merged['port'];

        return $merged;
    }

    /**
     * @return array{host:string,port:int,username:string,password:string,private_key:string,remote_path:string,enabled:bool}
     */
    private function get_default_settings() {
        return [
            'host' => '',
            'port' => 22,
            'username' => '',
            'password' => '',
            'private_key' => '',
            'remote_path' => '',
            'enabled' => false,
        ];
    }

    /**
     * @param string $host
     * @param int    $port
     * @return object
     */
    private function create_sftp_client($host, $port) {
        if (is_callable($this->sftp_factory)) {
            return call_user_func($this->sftp_factory, $host, $port);
        }

        $sftp_class = '\\phpseclib3\\Net\\SFTP';
        if (!class_exists($sftp_class)) {
            throw new Exception('Le client SFTP phpseclib n\'est pas disponible.');
        }

        return new $sftp_class($host, $port);
    }

    /**
     * @param string $private_key
     * @param string $password
     * @return mixed
     */
    private function load_private_key($private_key, $password) {
        if (is_callable($this->key_loader)) {
            return call_user_func($this->key_loader, $private_key, $password);
        }

        $loader_class = '\\phpseclib3\\Crypt\\PublicKeyLoader';
        if (!class_exists($loader_class)) {
            throw new Exception('Le chargeur de clé privée phpseclib n\'est pas disponible.');
        }

        return $loader_class::load($private_key, $password !== '' ? $password : false);
    }

    /**
     * @param object $sftp
     * @return int
     */
    private function get_sftp_source_constant($sftp) {
        $default = 1; // Valeur par défaut pour SOURCE_LOCAL_FILE

        if (is_object($sftp)) {
            $constant = '\\phpseclib3\\Net\\SFTP::SOURCE_LOCAL_FILE';
            if (defined($constant)) {
                return constant($constant);
            }

            if (property_exists($sftp, 'sourceLocalFile')) {
                return (int) $sftp->sourceLocalFile;
            }
        }

        return $default;
    }

    private function is_library_available() {
        return class_exists('phpseclib3\\Net\\SFTP') || function_exists('ssh2_connect');
    }
}
