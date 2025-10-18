<?php
namespace BJLG;

/**
 * Classe de chiffrement AES-256 pour sécuriser les sauvegardes
 * Fichier : includes/class-bjlg-encryption.php
 */

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class BJLG_Encryption {
    
    const CIPHER_METHOD = 'aes-256-cbc';
    const KEY_LENGTH = 32; // 256 bits
    const IV_LENGTH = 16;  // 128 bits
    const FILE_MAGIC = 'BJLGENC1';
    const FILE_VERSION = 2;
    const FILE_FLAG_PASSWORD = 0x01;
    const HMAC_LENGTH = 32;
    const PASSWORD_SALT_LENGTH = 16;
    const STRING_MAGIC = 'BJLGS1';
    const STRING_VERSION = 1;
    
    private $encryption_key;
    private $is_enabled;
    
    public function __construct() {
        $this->load_settings();
        
        // Hooks pour le chiffrement/déchiffrement automatique
        add_filter('bjlg_before_backup_save', [$this, 'encrypt_backup_file'], 10, 2);
        add_filter('bjlg_before_restore', [$this, 'decrypt_backup_file'], 10, 2);
        
        // Ajax pour la gestion des clés
        add_action('wp_ajax_bjlg_generate_encryption_key', [$this, 'ajax_generate_key']);
        add_action('wp_ajax_bjlg_test_encryption', [$this, 'ajax_test_encryption']);
        add_action('wp_ajax_bjlg_verify_password', [$this, 'ajax_verify_password']);
    }

    /**
     * Charge les paramètres de chiffrement
     */
    private function load_settings() {
        $settings = \bjlg_get_option('bjlg_encryption_settings', []);
        $this->is_enabled = $settings['enabled'] ?? false;
        
        // Récupérer la clé depuis un endroit sécurisé
        $this->encryption_key = $this->get_encryption_key();
    }
    
    /**
     * Récupère ou génère la clé de chiffrement
     */
    private function get_encryption_key() {
        // Option 1: Depuis wp-config.php (recommandé)
        if (defined('BJLG_ENCRYPTION_KEY')) {
            $key = $this->decode_encryption_key(BJLG_ENCRYPTION_KEY, 'BJLG_ENCRYPTION_KEY');
            if ($key !== null) {
                return $key;
            }
        }

        // Option 2: Depuis la base de données (moins sécurisé)
        $stored_key = \bjlg_get_option('bjlg_encryption_key');
        if ($stored_key) {
            $key = $this->decode_encryption_key($stored_key, 'bjlg_encryption_key');
            if ($key !== null) {
                return $key;
            }
        }

        // Option 3: Générer une nouvelle clé
        return $this->generate_encryption_key();
    }

    /**
     * Décode une clé de chiffrement encodée en base64.
     *
     * @param string $encoded_key
     * @param string $source
     * @return string|null
     */
    private function decode_encryption_key($encoded_key, $source) {
        if (!is_string($encoded_key) || $encoded_key === '') {
            $this->handle_invalid_encryption_key($source, 'clé vide.');
            return null;
        }

        $normalized_key = trim($encoded_key);
        if (strpos($normalized_key, 'base64:') === 0) {
            $normalized_key = substr($normalized_key, 7);
        }

        $decoded_key = base64_decode($normalized_key, true);
        if ($decoded_key === false) {
            $this->handle_invalid_encryption_key($source, 'décodage base64 impossible.');
            return null;
        }

        if (strlen($decoded_key) !== self::KEY_LENGTH) {
            $this->handle_invalid_encryption_key(
                $source,
                sprintf(
                    'longueur incorrecte (%d octets reçus, %d attendus).',
                    strlen($decoded_key),
                    self::KEY_LENGTH
                )
            );
            return null;
        }

        return $decoded_key;
    }

    /**
     * Gère une clé de chiffrement invalide en journalisant l'erreur.
     *
     * @param string $source
     * @param string $reason
     * @return void
     */
    private function handle_invalid_encryption_key($source, $reason) {
        $message = sprintf(
            "Clé de chiffrement invalide détectée dans %s : %s Une nouvelle clé sera générée si nécessaire.",
            $source,
            $reason
        );

        BJLG_Debug::error($message);
        BJLG_History::log('invalid_encryption_key', 'error', $message);
    }
    
    /**
     * Génère une nouvelle clé de chiffrement sécurisée
     */
    public function generate_encryption_key() {
        if (function_exists('random_bytes')) {
            $key = random_bytes(self::KEY_LENGTH);
        } else {
            // Fallback pour PHP < 7.0
            $key = openssl_random_pseudo_bytes(self::KEY_LENGTH);
        }
        
        // Sauvegarder la clé
        \bjlg_update_option('bjlg_encryption_key', base64_encode($key));
        
        // Log l'événement
        BJLG_History::log('encryption_key_generated', 'info', 'Nouvelle clé de chiffrement générée');
        BJLG_Debug::log("Nouvelle clé de chiffrement AES-256 générée");
        
        return $key;
    }
    
    /**
     * Chiffre un fichier de sauvegarde
     */
    public function encrypt_backup_file($filepath, $password = null) {
        if (!$this->is_enabled || !file_exists($filepath)) {
            return $filepath;
        }

        $input_handle = null;
        $output_handle = null;
        $encrypted_filepath = $filepath . '.enc';
        $delete_on_failure = false;

        try {
            BJLG_Debug::log("Début du chiffrement du fichier : " . basename($filepath));

            $original_size = @filesize($filepath);
            if ($original_size === false) {
                $original_size = 0;
            }

            $input_handle = fopen($filepath, 'rb');
            if ($input_handle === false) {
                throw new Exception("Impossible de lire le fichier");
            }

            $output_handle = fopen($encrypted_filepath, 'wb');
            if ($output_handle === false) {
                throw new Exception("Impossible de créer le fichier chiffré");
            }
            $delete_on_failure = true;

            $iv = function_exists('random_bytes')
                ? random_bytes(self::IV_LENGTH)
                : openssl_random_pseudo_bytes(self::IV_LENGTH);

            if ($iv === false || strlen($iv) !== self::IV_LENGTH) {
                throw new Exception("Impossible de générer l'IV");
            }

            $uses_password = is_string($password) && $password !== '';
            $salt = '';

            if ($uses_password) {
                $salt = $this->generate_password_salt();
                if ($salt === false || strlen($salt) !== self::PASSWORD_SALT_LENGTH) {
                    throw new Exception("Impossible de générer le sel de mot de passe");
                }
            }

            $key = $uses_password
                ? $this->derive_key_from_password($password, $salt)
                : $this->encryption_key;

            $block_size = openssl_cipher_iv_length(self::CIPHER_METHOD);
            if (!$block_size) {
                $block_size = self::IV_LENGTH;
            }
            $chunk_size = $block_size * 4096; // Lecture par blocs (64 Ko)

            $hmac_context = hash_init('sha256', HASH_HMAC, $key);

            $flags = 0;

            if ($uses_password) {
                $flags |= self::FILE_FLAG_PASSWORD;
            }

            $header = self::FILE_MAGIC . chr(self::FILE_VERSION) . chr($flags) . $iv;

            if ($uses_password) {
                $salt_length = strlen($salt);
                if ($salt_length > 255) {
                    throw new Exception("Sel de mot de passe trop long");
                }
                $header .= chr($salt_length) . $salt;
            }

            if (fwrite($output_handle, $header) !== strlen($header)) {
                throw new Exception("Impossible d'écrire l'en-tête du fichier chiffré");
            }

            $hmac_position = ftell($output_handle);
            if ($hmac_position === false) {
                throw new Exception("Impossible de préparer l'écriture du HMAC");
            }

            if (fwrite($output_handle, str_repeat("\0", self::HMAC_LENGTH)) !== self::HMAC_LENGTH) {
                throw new Exception("Impossible de réserver l'espace pour le HMAC");
            }

            $buffer = '';
            $current_iv = $iv;

            while (!feof($input_handle)) {
                $data = fread($input_handle, $chunk_size);
                if ($data === false) {
                    throw new Exception("Erreur lors de la lecture du fichier");
                }
                if ($data === '') {
                    break;
                }

                $buffer .= $data;
                $blocks_in_buffer = intdiv(strlen($buffer), $block_size);

                if ($blocks_in_buffer > 1) {
                    $blocks_to_process = $blocks_in_buffer - 1;
                    $length_to_process = $blocks_to_process * $block_size;

                    $plain_chunk = substr($buffer, 0, $length_to_process);
                    $buffer = substr($buffer, $length_to_process);

                    if ($plain_chunk !== '') {
                        $encrypted_chunk = openssl_encrypt(
                            $plain_chunk,
                            self::CIPHER_METHOD,
                            $key,
                            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                            $current_iv
                        );

                        if ($encrypted_chunk === false) {
                            throw new Exception("Échec du chiffrement OpenSSL");
                        }

                        if (fwrite($output_handle, $encrypted_chunk) !== strlen($encrypted_chunk)) {
                            throw new Exception("Impossible d'écrire les données chiffrées");
                        }

                        hash_update($hmac_context, $encrypted_chunk);
                        $current_iv = substr($encrypted_chunk, -$block_size);
                    }
                }
            }

            $padding_length = $block_size - (strlen($buffer) % $block_size);
            if ($padding_length <= 0 || $padding_length > $block_size) {
                $padding_length = $block_size;
            }
            $buffer .= str_repeat(chr($padding_length), $padding_length);

            $final_chunk = openssl_encrypt(
                $buffer,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                $current_iv
            );

            if ($final_chunk === false) {
                throw new Exception("Échec du chiffrement OpenSSL");
            }

            if (fwrite($output_handle, $final_chunk) !== strlen($final_chunk)) {
                throw new Exception("Impossible d'écrire les données chiffrées");
            }

            hash_update($hmac_context, $final_chunk);
            $hmac = hash_final($hmac_context, true);

            if (fseek($output_handle, $hmac_position) !== 0) {
                throw new Exception("Impossible d'écrire le HMAC");
            }

            if (fwrite($output_handle, $hmac) !== strlen($hmac)) {
                throw new Exception("Impossible d'écrire le HMAC");
            }

            $delete_on_failure = false;

            if (is_resource($input_handle)) {
                fclose($input_handle);
                $input_handle = null;
            }
            if (is_resource($output_handle)) {
                fflush($output_handle);
                fclose($output_handle);
                $output_handle = null;
            }

            if (!@unlink($filepath)) {
                BJLG_Debug::log("Impossible de supprimer le fichier source après chiffrement : " . basename($filepath));
            }

            $encrypted_size = @filesize($encrypted_filepath);
            if ($encrypted_size === false) {
                $encrypted_size = 0;
            }

            $overhead = $original_size > 0
                ? (($encrypted_size - $original_size) / $original_size) * 100
                : 0;

            BJLG_Debug::log(sprintf(
                "Fichier chiffré avec succès. Taille originale: %s, Taille chiffrée: %s (overhead: %.2f%%)",
                function_exists('size_format') ? size_format($original_size) : $original_size . ' bytes',
                function_exists('size_format') ? size_format($encrypted_size) : $encrypted_size . ' bytes',
                $overhead
            ));

            BJLG_History::log('backup_encrypted', 'success',
                'Fichier: ' . basename($encrypted_filepath) . ' | Méthode: AES-256-CBC');

            unset($buffer);
            unset($final_chunk);

            return $encrypted_filepath;

    } catch (Exception $e) {
        if (is_resource($input_handle)) {
            fclose($input_handle);
        }
        if (is_resource($output_handle)) {
            fclose($output_handle);
        }
        if ($delete_on_failure && file_exists($encrypted_filepath)) {
            @unlink($encrypted_filepath);
        }

        BJLG_Debug::log("ERREUR de chiffrement : " . $e->getMessage());
        BJLG_History::log('backup_encrypted', 'failure', $e->getMessage());

        return $filepath;
    }
    }
    
    /**
     * Déchiffre un fichier chiffré vers une copie temporaire sans modifier l'original.
     *
     * @param string $filepath
     * @param string|null $password
     * @return array{path:string,directory:string}
     * @throws Exception
     */
    public function decrypt_to_temporary_copy($filepath, $password = null) {
        if (!file_exists($filepath) || substr($filepath, -4) !== '.enc') {
            throw new Exception("Fichier chiffré introuvable pour la copie temporaire.");
        }

        $header_handle = fopen($filepath, 'rb');
        if ($header_handle === false) {
            throw new Exception("Impossible de lire le fichier chiffré pour la copie temporaire.");
        }

        try {
            $header = $this->read_encrypted_file_header($header_handle);
        } finally {
            fclose($header_handle);
        }

        $requires_password = $header['version'] >= 2
            && (($header['flags'] ?? 0) & self::FILE_FLAG_PASSWORD) === self::FILE_FLAG_PASSWORD;

        if ($requires_password && (!is_string($password) || $password === '')) {
            throw new Exception('Mot de passe requis pour vérifier cette sauvegarde chiffrée.');
        }

        $temporary_directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'bjlg-decrypt-' . uniqid('', true);

        if (!@mkdir($temporary_directory, 0777, true) && !is_dir($temporary_directory)) {
            throw new Exception("Impossible de créer le répertoire temporaire pour le déchiffrement.");
        }

        $temporary_encrypted = $temporary_directory . DIRECTORY_SEPARATOR . basename($filepath);

        if (!@copy($filepath, $temporary_encrypted)) {
            $this->cleanup_temporary_directory($temporary_directory);
            throw new Exception("Impossible de préparer la copie chiffrée temporaire.");
        }

        try {
            $decrypted_path = $this->decrypt_backup_file($temporary_encrypted, $password);

            if (!is_string($decrypted_path) || !file_exists($decrypted_path)) {
                throw new Exception("La copie déchiffrée n'a pas pu être générée.");
            }

            if (file_exists($temporary_encrypted)) {
                @unlink($temporary_encrypted);
            }

            return [
                'path' => $decrypted_path,
                'directory' => $temporary_directory,
            ];
        } catch (Exception $exception) {
            if (file_exists($temporary_encrypted)) {
                @unlink($temporary_encrypted);
            }

            $this->cleanup_temporary_directory($temporary_directory);

            throw $exception;
        }
    }

    /**
     * Déchiffre un fichier de sauvegarde
     */
    public function decrypt_backup_file($filepath, $password = null) {
        if (!file_exists($filepath) || substr($filepath, -4) !== '.enc') {
            return $filepath;
        }

        $input_handle = null;
        $output_handle = null;
        $temp_filepath = null;

        try {
            BJLG_Debug::log("Début du déchiffrement du fichier : " . basename($filepath));

            $input_handle = fopen($filepath, 'rb');
            if ($input_handle === false) {
                throw new Exception("Impossible de lire le fichier chiffré");
            }

            $header = $this->read_encrypted_file_header($input_handle);

            $version = $header['version'];
            $flags = $header['flags'];
            $iv = $header['iv'];
            $salt = $header['salt'];
            $stored_hmac = $header['hmac'];

            $uses_password = $version >= 2
                ? (($flags & self::FILE_FLAG_PASSWORD) === self::FILE_FLAG_PASSWORD)
                : (is_string($password) && $password !== '');

            if ($uses_password && (!is_string($password) || $password === '')) {
                throw new Exception("Mot de passe requis pour ce fichier chiffré");
            }

            $key = $uses_password
                ? $this->derive_key_from_password($password, $version >= 2 ? $salt : null)
                : $this->encryption_key;

            $block_size = openssl_cipher_iv_length(self::CIPHER_METHOD);
            if (!$block_size) {
                $block_size = self::IV_LENGTH;
            }
            $chunk_size = $block_size * 4096;

            $decrypted_filepath = substr($filepath, 0, -4);
            $temp_filepath = $decrypted_filepath . '.tmp';

            $output_handle = fopen($temp_filepath, 'wb');
            if ($output_handle === false) {
                throw new Exception("Impossible d'écrire le fichier déchiffré");
            }

            $hmac_context = hash_init('sha256', HASH_HMAC, $key);

            $buffer = '';
            $current_iv = $iv;

            while (!feof($input_handle)) {
                $data = fread($input_handle, $chunk_size);
                if ($data === false) {
                    throw new Exception("Erreur lors de la lecture du fichier chiffré");
                }
                if ($data === '') {
                    break;
                }

                $buffer .= $data;
                $blocks_in_buffer = intdiv(strlen($buffer), $block_size);

                if ($blocks_in_buffer > 1) {
                    $blocks_to_process = $blocks_in_buffer - 1;
                    $length_to_process = $blocks_to_process * $block_size;

                    $cipher_chunk = substr($buffer, 0, $length_to_process);
                    $buffer = substr($buffer, $length_to_process);

                    if ($cipher_chunk !== '') {
                        hash_update($hmac_context, $cipher_chunk);

                        $decrypted_chunk = openssl_decrypt(
                            $cipher_chunk,
                            self::CIPHER_METHOD,
                            $key,
                            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                            $current_iv
                        );

                        if ($decrypted_chunk === false) {
                            throw new Exception("Échec du déchiffrement - clé incorrecte ?");
                        }

                        $current_iv = substr($cipher_chunk, -$block_size);

                        if (fwrite($output_handle, $decrypted_chunk) !== strlen($decrypted_chunk)) {
                            throw new Exception("Impossible d'écrire le fichier déchiffré");
                        }
                    }
                }
            }

            if ($buffer === '') {
                throw new Exception("Données chiffrées manquantes");
            }

            hash_update($hmac_context, $buffer);
            $calculated_hmac = hash_final($hmac_context, true);
            if (!hash_equals($stored_hmac, $calculated_hmac)) {
                throw new Exception("Vérification d'intégrité échouée - fichier possiblement corrompu");
            }

            $final_chunk = openssl_decrypt(
                $buffer,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                $current_iv
            );

            if ($final_chunk === false) {
                throw new Exception("Échec du déchiffrement - clé incorrecte ?");
            }

            $padding_length = ord(substr($final_chunk, -1));
            if ($padding_length < 1 || $padding_length > $block_size) {
                throw new Exception("Padding invalide détecté");
            }

            $padding = substr($final_chunk, -$padding_length);
            if ($padding !== str_repeat(chr($padding_length), $padding_length)) {
                throw new Exception("Padding invalide détecté");
            }

            $final_chunk = substr($final_chunk, 0, -$padding_length);

            if ($final_chunk !== '') {
                if (fwrite($output_handle, $final_chunk) !== strlen($final_chunk)) {
                    throw new Exception("Impossible d'écrire le fichier déchiffré");
                }
            }

            fflush($output_handle);
            fclose($output_handle);
            $output_handle = null;

            fclose($input_handle);
            $input_handle = null;

            if (file_exists($decrypted_filepath) && !@unlink($decrypted_filepath)) {
                @unlink($temp_filepath);
                throw new Exception("Impossible de finaliser le fichier déchiffré");
            }

            if (!@rename($temp_filepath, $decrypted_filepath)) {
                @unlink($temp_filepath);
                throw new Exception("Impossible de finaliser le fichier déchiffré");
            }

            BJLG_Debug::log("Fichier déchiffré avec succès : " . basename($decrypted_filepath));
            BJLG_History::log('backup_decrypted', 'success', 'Fichier: ' . basename($decrypted_filepath));

            unset($buffer);
            unset($final_chunk);

            return $decrypted_filepath;

        } catch (Exception $e) {
            if (is_resource($output_handle)) {
                fclose($output_handle);
            }
            if ($temp_filepath && file_exists($temp_filepath)) {
                @unlink($temp_filepath);
            }
            if (is_resource($input_handle)) {
                fclose($input_handle);
            }

            BJLG_Debug::log("ERREUR de déchiffrement : " . $e->getMessage());
            BJLG_History::log('backup_decrypted', 'failure', $e->getMessage());
            throw $e; // Propager l'erreur car la restauration ne peut pas continuer
        }
    }

    /**
     * Supprime récursivement un répertoire temporaire utilisé pendant le déchiffrement.
     *
     * @param string $directory
     * @return void
     */
    private function cleanup_temporary_directory($directory) {
        if (!is_string($directory) || $directory === '' || !is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->cleanup_temporary_directory($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    /**
     * Lit et valide l'en-tête d'un fichier chiffré.
     *
     * @param resource $handle
     * @return array{version:int,flags:int,iv:string,salt:string,hmac:string}
     * @throws Exception
     */
    private function read_encrypted_file_header($handle) {
        $magic = fread($handle, strlen(self::FILE_MAGIC));
        if ($magic === false || strlen($magic) !== strlen(self::FILE_MAGIC) || $magic !== self::FILE_MAGIC) {
            throw new Exception("Format de fichier invalide");
        }

        $version_data = fread($handle, 1);
        if ($version_data === false || strlen($version_data) !== 1) {
            throw new Exception("Version de chiffrement non supportée");
        }

        $version = ord($version_data);
        if ($version < 1 || $version > self::FILE_VERSION) {
            throw new Exception("Version de chiffrement non supportée");
        }

        $flags = 0;

        if ($version >= 2) {
            $flags_data = fread($handle, 1);
            if ($flags_data === false || strlen($flags_data) !== 1) {
                throw new Exception("En-tête de chiffrement corrompu");
            }

            $flags = ord($flags_data);
        }

        $iv = fread($handle, self::IV_LENGTH);
        if ($iv === false || strlen($iv) !== self::IV_LENGTH) {
            throw new Exception("IV manquant ou corrompu");
        }

        $salt = '';

        if ($version >= 2 && ($flags & self::FILE_FLAG_PASSWORD)) {
            $salt_length_data = fread($handle, 1);
            if ($salt_length_data === false || strlen($salt_length_data) !== 1) {
                throw new Exception("Sel de mot de passe manquant ou corrompu");
            }

            $salt_length = ord($salt_length_data);

            if ($salt_length <= 0) {
                throw new Exception("Sel de mot de passe invalide");
            }

            $salt = fread($handle, $salt_length);
            if ($salt === false || strlen($salt) !== $salt_length) {
                throw new Exception("Sel de mot de passe corrompu");
            }
        }

        $stored_hmac = fread($handle, self::HMAC_LENGTH);
        if ($stored_hmac === false || strlen($stored_hmac) !== self::HMAC_LENGTH) {
            throw new Exception("HMAC manquant ou corrompu");
        }

        return [
            'version' => $version,
            'flags' => $flags,
            'iv' => $iv,
            'salt' => $salt,
            'hmac' => $stored_hmac,
        ];
    }

    /**
     * Génère un sel sécurisé pour la dérivation de mot de passe.
     *
     * @return string|false
     */
    private function generate_password_salt() {
        $salt = function_exists('random_bytes')
            ? random_bytes(self::PASSWORD_SALT_LENGTH)
            : openssl_random_pseudo_bytes(self::PASSWORD_SALT_LENGTH);

        if ($salt === false || strlen($salt) !== self::PASSWORD_SALT_LENGTH) {
            return false;
        }

        return $salt;
    }

    /**
     * Dérive une clé à partir d'un mot de passe
     */
    private function derive_key_from_password($password, $salt = null) {
        if ($salt === null) {
            $salt = \bjlg_get_option('bjlg_encryption_salt');
            if (!$salt) {
                $salt = openssl_random_pseudo_bytes(self::PASSWORD_SALT_LENGTH);
                \bjlg_update_option('bjlg_encryption_salt', $salt);
            }
        }

        if (!is_string($salt) || $salt === '') {
            throw new Exception("Sel de dérivation de clé invalide");
        }

        // Utiliser PBKDF2 pour dériver la clé
        return hash_pbkdf2('sha256', $password, $salt, 10000, self::KEY_LENGTH, true);
    }
    
    /**
     * Chiffre une chaîne de texte (pour les mots de passe, tokens, etc.)
     */
    public function encrypt_string($plaintext) {
        if (!$this->is_enabled) {
            return $plaintext;
        }

        $iv = function_exists('random_bytes')
            ? random_bytes(self::IV_LENGTH)
            : openssl_random_pseudo_bytes(self::IV_LENGTH);

        if ($iv === false || strlen($iv) !== self::IV_LENGTH) {
            return false;
        }

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER_METHOD,
            $this->encryption_key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($ciphertext === false) {
            return false;
        }

        $hmac = hash_hmac('sha256', $iv . $ciphertext, $this->encryption_key, true);

        $payload = self::STRING_MAGIC . chr(self::STRING_VERSION) . $iv . $hmac . $ciphertext;

        return base64_encode($payload);
    }
    
    /**
     * Déchiffre une chaîne de texte
     */
    public function decrypt_string($encrypted) {
        if (!$this->is_enabled) {
            return $encrypted;
        }
        
        $data = base64_decode($encrypted, true);

        if ($data === false) {
            return false;
        }

        $magic_length = strlen(self::STRING_MAGIC);

        if (strncmp($data, self::STRING_MAGIC, $magic_length) === 0) {
            $offset = $magic_length;

            if (strlen($data) < $offset + 1 + self::IV_LENGTH + self::HMAC_LENGTH) {
                return false;
            }

            $version = ord($data[$offset]);
            $offset++;

            if ($version !== self::STRING_VERSION) {
                return false;
            }

            $iv = substr($data, $offset, self::IV_LENGTH);
            $offset += self::IV_LENGTH;

            $stored_hmac = substr($data, $offset, self::HMAC_LENGTH);
            $offset += self::HMAC_LENGTH;

            $ciphertext = substr($data, $offset);

            $calculated_hmac = hash_hmac('sha256', $iv . $ciphertext, $this->encryption_key, true);

            if (!hash_equals($stored_hmac, $calculated_hmac)) {
                return false;
            }
        } else {
            if (strlen($data) <= self::IV_LENGTH) {
                return false;
            }

            $iv = substr($data, 0, self::IV_LENGTH);
            $ciphertext = substr($data, self::IV_LENGTH);
        }

        return openssl_decrypt(
            $ciphertext,
            self::CIPHER_METHOD,
            $this->encryption_key,
            OPENSSL_RAW_DATA,
            $iv
        );
    }
    
    /**
     * AJAX: Génère une nouvelle clé
     */
    public function ajax_generate_key() {
        if (!\bjlg_can_manage_settings()) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        $key = $this->generate_encryption_key();
        $key_base64 = base64_encode($key);
        
        // Instruction pour wp-config.php
        $config_line = "define('BJLG_ENCRYPTION_KEY', '{$key_base64}');";
        
        wp_send_json_success([
            'message' => 'Clé générée avec succès',
            'key_preview' => substr($key_base64, 0, 20) . '...',
            'config_line' => $config_line,
            'instructions' => 'Ajoutez cette ligne à votre wp-config.php pour une sécurité maximale'
        ]);
    }
    
    /**
     * AJAX: Test du chiffrement
     */
    public function ajax_test_encryption() {
        if (!\bjlg_can_manage_settings()) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        try {
            // Créer un fichier test
            $test_content = "Test de chiffrement AES-256 - " . date('Y-m-d H:i:s');
            $test_file = BJLG_BACKUP_DIR . 'test_encryption_' . uniqid() . '.txt';
            
            file_put_contents($test_file, $test_content);
            
            // Tester le chiffrement
            $encrypted_file = $this->encrypt_backup_file($test_file);
            
            if (!file_exists($encrypted_file)) {
                throw new Exception("Le fichier chiffré n'a pas été créé");
            }
            
            // Tester le déchiffrement
            $decrypted_file = $this->decrypt_backup_file($encrypted_file);
            
            $decrypted_content = file_get_contents($decrypted_file);
            
            // Vérifier que le contenu est identique
            if ($decrypted_content !== $test_content) {
                throw new Exception("Le contenu déchiffré ne correspond pas à l'original");
            }
            
            // Nettoyer
            @unlink($encrypted_file);
            @unlink($decrypted_file);
            
            wp_send_json_success([
                'message' => 'Test de chiffrement réussi !',
                'details' => [
                    'Méthode' => 'AES-256-CBC',
                    'Taille de clé' => '256 bits',
                    'Intégrité' => 'HMAC-SHA256',
                    'Test' => 'Chiffrement et déchiffrement validés'
                ]
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => 'Test échoué : ' . $e->getMessage()
            ]);
        }
    }

    /**
     * AJAX: Vérifie si un mot de passe peut déchiffrer un fichier donné.
     */
    public function ajax_verify_password() {
        if (!\bjlg_can_manage_settings()) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $password = '';
        if (isset($_POST['password'])) {
            $maybe_password = wp_unslash($_POST['password']);
            if (is_string($maybe_password)) {
                $password = $maybe_password;
            }
        }

        if ($password === '') {
            wp_send_json_error(['message' => 'Mot de passe manquant']);
        }

        $file_param = isset($_POST['file']) ? wp_unslash($_POST['file']) : '';
        $file_param = is_string($file_param) ? trim($file_param) : '';

        if ($file_param === '') {
            wp_send_json_error(['message' => 'Aucun fichier fourni pour la vérification']);
        }

        $file_param = sanitize_text_field($file_param);
        $base_dir = wp_normalize_path(BJLG_BACKUP_DIR);
        if (substr($base_dir, -1) !== '/') {
            $base_dir .= '/';
        }

        $candidate = wp_normalize_path($file_param);
        if (strpos($candidate, '..') !== false) {
            wp_send_json_error(['message' => 'Chemin de fichier invalide']);
        }

        if (strpos($candidate, $base_dir) === 0) {
            $full_path = $candidate;
        } else {
            $full_path = $base_dir . ltrim($candidate, '/');
        }

        $real_path = realpath($full_path);
        if ($real_path === false) {
            wp_send_json_error(['message' => 'Fichier introuvable']);
        }

        $normalized_real = wp_normalize_path($real_path);
        if (strpos($normalized_real, $base_dir) !== 0) {
            wp_send_json_error(['message' => 'Fichier non autorisé']);
        }

        if (!$this->is_encrypted_file($real_path)) {
            wp_send_json_error(['message' => 'Le fichier spécifié n\'est pas chiffré']);
        }

        $handle = @fopen($real_path, 'rb');
        if ($handle === false) {
            wp_send_json_error(['message' => 'Impossible de lire le fichier chiffré']);
        }

        try {
            $header = $this->read_encrypted_file_header($handle);

            $version = $header['version'];
            $flags = $header['flags'];

            if ($version >= 2 && ($flags & self::FILE_FLAG_PASSWORD) === 0) {
                throw new Exception('Ce fichier n\'est pas protégé par mot de passe');
            }

            $iv = $header['iv'];
            $stored_hmac = $header['hmac'];
            $salt = $header['salt'];

            $key = $this->derive_key_from_password($password, $version >= 2 ? $salt : null);

            $block_size = openssl_cipher_iv_length(self::CIPHER_METHOD);
            if (!$block_size) {
                $block_size = self::IV_LENGTH;
            }

            $chunk_size = $block_size * 4096;
            $hmac_context = hash_init('sha256', HASH_HMAC, $key);
            $buffer = '';
            $current_iv = $iv;

            while (!feof($handle)) {
                $data = fread($handle, $chunk_size);
                if ($data === false) {
                    throw new Exception('Erreur lors de la lecture du fichier chiffré');
                }

                if ($data === '') {
                    break;
                }

                $buffer .= $data;
                $blocks_in_buffer = intdiv(strlen($buffer), $block_size);

                if ($blocks_in_buffer > 1) {
                    $blocks_to_process = $blocks_in_buffer - 1;
                    $length_to_process = $blocks_to_process * $block_size;

                    $cipher_chunk = substr($buffer, 0, $length_to_process);
                    $buffer = substr($buffer, $length_to_process);

                    if ($cipher_chunk !== '') {
                        hash_update($hmac_context, $cipher_chunk);

                        $decrypted_chunk = openssl_decrypt(
                            $cipher_chunk,
                            self::CIPHER_METHOD,
                            $key,
                            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                            $current_iv
                        );

                        if ($decrypted_chunk === false) {
                            throw new Exception('Mot de passe incorrect ou données corrompues');
                        }

                        $current_iv = substr($cipher_chunk, -$block_size);
                    }
                }
            }

            if ($buffer === '') {
                throw new Exception('Données chiffrées manquantes');
            }

            hash_update($hmac_context, $buffer);
            $calculated_hmac = hash_final($hmac_context, true);
            if (!hash_equals($stored_hmac, $calculated_hmac)) {
                throw new Exception('Mot de passe incorrect ou fichier corrompu (HMAC)');
            }

            $final_chunk = openssl_decrypt(
                $buffer,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                $current_iv
            );

            if ($final_chunk === false) {
                throw new Exception('Mot de passe incorrect ou données corrompues');
            }

            $padding_length = ord(substr($final_chunk, -1));
            if ($padding_length < 1 || $padding_length > $block_size) {
                throw new Exception('Padding invalide détecté');
            }

            $padding = substr($final_chunk, -$padding_length);
            if ($padding !== str_repeat(chr($padding_length), $padding_length)) {
                throw new Exception('Padding invalide détecté');
            }

            BJLG_Debug::log('Mot de passe de chiffrement validé pour ' . basename($real_path));

            fclose($handle);

            wp_send_json_success([
                'message' => 'Mot de passe valide',
                'file' => basename($real_path)
            ]);
        } catch (Exception $e) {
            BJLG_Debug::log('Échec de validation du mot de passe : ' . $e->getMessage());
            if (is_resource($handle)) {
                fclose($handle);
            }

            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Vérifie si un fichier est chiffré
     */
    public function is_encrypted_file($filepath) {
        if (!file_exists($filepath)) {
            return false;
        }
        
        // Vérifier l'extension
        if (substr($filepath, -4) === '.enc') {
            return true;
        }
        
        // Vérifier le header magique
        $handle = fopen($filepath, 'rb');
        if ($handle) {
            $header = fread($handle, strlen(self::FILE_MAGIC));
            fclose($handle);
            return $header === self::FILE_MAGIC;
        }
        
        return false;
    }
    
    /**
     * Retourne les statistiques de chiffrement
     */
    public function get_encryption_stats() {
        $backups = glob(BJLG_BACKUP_DIR . '*.zip*') ?: [];
        $encrypted = 0;
        $unencrypted = 0;
        $total_encrypted_size = 0;
        
        foreach ($backups as $backup) {
            if ($this->is_encrypted_file($backup)) {
                $encrypted++;
                $total_encrypted_size += filesize($backup);
            } else {
                $unencrypted++;
            }
        }
        
        return [
            'encrypted_count' => $encrypted,
            'unencrypted_count' => $unencrypted,
            'total_encrypted_size' => size_format($total_encrypted_size),
            'encryption_enabled' => $this->is_enabled,
            'encryption_method' => self::CIPHER_METHOD
        ];
    }
}