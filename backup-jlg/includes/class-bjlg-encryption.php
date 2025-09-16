<?php
/**
 * Classe de chiffrement AES-256 pour sécuriser les sauvegardes
 * Fichier : includes/class-bjlg-encryption.php
 */

if (!defined('ABSPATH')) exit;

class BJLG_Encryption {
    
    const CIPHER_METHOD = 'aes-256-cbc';
    const KEY_LENGTH = 32; // 256 bits
    const IV_LENGTH = 16;  // 128 bits
    
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
        $settings = get_option('bjlg_encryption_settings', []);
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
            return base64_decode(BJLG_ENCRYPTION_KEY);
        }
        
        // Option 2: Depuis la base de données (moins sécurisé)
        $stored_key = get_option('bjlg_encryption_key');
        if ($stored_key) {
            return base64_decode($stored_key);
        }
        
        // Option 3: Générer une nouvelle clé
        return $this->generate_encryption_key();
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
        update_option('bjlg_encryption_key', base64_encode($key));
        
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

            $iv = openssl_random_pseudo_bytes(self::IV_LENGTH);
            if ($iv === false || strlen($iv) !== self::IV_LENGTH) {
                throw new Exception("Impossible de générer l'IV");
            }

            $key = $password ? $this->derive_key_from_password($password) : $this->encryption_key;

            $block_size = openssl_cipher_iv_length(self::CIPHER_METHOD);
            if (!$block_size) {
                $block_size = self::IV_LENGTH;
            }
            $chunk_size = $block_size * 4096; // Lecture par blocs (64 Ko)

            $hmac_context = hash_init('sha256', HASH_HMAC, $key);

            if (fwrite($output_handle, 'BJLGENC1') !== 8 ||
                fwrite($output_handle, chr(1)) !== 1 ||
                fwrite($output_handle, $iv) !== self::IV_LENGTH) {
                throw new Exception("Impossible d'écrire l'en-tête du fichier chiffré");
            }

            $hmac_position = ftell($output_handle);
            if ($hmac_position === false) {
                throw new Exception("Impossible de préparer l'écriture du HMAC");
            }

            if (fwrite($output_handle, str_repeat("\0", 32)) !== 32) {
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

            $header = fread($input_handle, 8);
            if ($header === false || strlen($header) !== 8 || $header !== 'BJLGENC1') {
                throw new Exception("Format de fichier invalide");
            }

            $version_data = fread($input_handle, 1);
            if ($version_data === false || strlen($version_data) !== 1) {
                throw new Exception("Version de chiffrement non supportée");
            }
            $version = ord($version_data);
            if ($version !== 1) {
                throw new Exception("Version de chiffrement non supportée");
            }

            $iv = fread($input_handle, self::IV_LENGTH);
            if ($iv === false || strlen($iv) !== self::IV_LENGTH) {
                throw new Exception("IV manquant ou corrompu");
            }

            $stored_hmac = fread($input_handle, 32);
            if ($stored_hmac === false || strlen($stored_hmac) !== 32) {
                throw new Exception("HMAC manquant ou corrompu");
            }

            $key = $password ? $this->derive_key_from_password($password) : $this->encryption_key;

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
     * Dérive une clé à partir d'un mot de passe
     */
    private function derive_key_from_password($password) {
        $salt = get_option('bjlg_encryption_salt');
        if (!$salt) {
            $salt = openssl_random_pseudo_bytes(16);
            update_option('bjlg_encryption_salt', $salt);
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
        
        $iv = openssl_random_pseudo_bytes(self::IV_LENGTH);
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER_METHOD,
            $this->encryption_key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        // Retourner en base64 pour stockage facile
        return base64_encode($iv . $ciphertext);
    }
    
    /**
     * Déchiffre une chaîne de texte
     */
    public function decrypt_string($encrypted) {
        if (!$this->is_enabled) {
            return $encrypted;
        }
        
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, self::IV_LENGTH);
        $ciphertext = substr($data, self::IV_LENGTH);
        
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
        if (!current_user_can(BJLG_CAPABILITY)) {
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
        if (!current_user_can(BJLG_CAPABILITY)) {
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
            $header = fread($handle, 8);
            fclose($handle);
            return $header === 'BJLGENC1';
        }
        
        return false;
    }
    
    /**
     * Retourne les statistiques de chiffrement
     */
    public function get_encryption_stats() {
        $backups = glob(BJLG_BACKUP_DIR . '*.zip*');
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