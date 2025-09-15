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
        
        try {
            BJLG_Debug::log("Début du chiffrement du fichier : " . basename($filepath));
            
            // Lire le contenu du fichier
            $plaintext = file_get_contents($filepath);
            if ($plaintext === false) {
                throw new Exception("Impossible de lire le fichier");
            }
            
            // Générer un IV unique pour ce fichier
            $iv = openssl_random_pseudo_bytes(self::IV_LENGTH);
            
            // Utiliser le mot de passe si fourni, sinon la clé par défaut
            $key = $password ? $this->derive_key_from_password($password) : $this->encryption_key;
            
            // Chiffrer le contenu
            $ciphertext = openssl_encrypt(
                $plaintext,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ($ciphertext === false) {
                throw new Exception("Échec du chiffrement OpenSSL");
            }
            
            // Créer un hash pour vérifier l'intégrité
            $hmac = hash_hmac('sha256', $ciphertext, $key, true);
            
            // Structure du fichier chiffré : [Magic Header][Version][IV][HMAC][Ciphertext]
            $encrypted_content = 'BJLGENC1' . // Magic header (8 bytes)
                                chr(1) .      // Version (1 byte)
                                $iv .         // IV (16 bytes)
                                $hmac .       // HMAC (32 bytes)
                                $ciphertext;  // Données chiffrées
            
            // Sauvegarder le fichier chiffré
            $encrypted_filepath = $filepath . '.enc';
            if (file_put_contents($encrypted_filepath, $encrypted_content) === false) {
                throw new Exception("Impossible d'écrire le fichier chiffré");
            }
            
            // Supprimer le fichier original non chiffré
            unlink($filepath);
            
            // Calculer les statistiques
            $original_size = strlen($plaintext);
            $encrypted_size = strlen($encrypted_content);
            $overhead = (($encrypted_size - $original_size) / $original_size) * 100;
            
            BJLG_Debug::log(sprintf(
                "Fichier chiffré avec succès. Taille originale: %s, Taille chiffrée: %s (overhead: %.2f%%)",
                size_format($original_size),
                size_format($encrypted_size),
                $overhead
            ));
            
            BJLG_History::log('backup_encrypted', 'success', 
                'Fichier: ' . basename($encrypted_filepath) . ' | Méthode: AES-256-CBC');
            
            // Nettoyer la mémoire
            unset($plaintext);
            unset($ciphertext);
            
            return $encrypted_filepath;
            
        } catch (Exception $e) {
            BJLG_Debug::log("ERREUR de chiffrement : " . $e->getMessage());
            BJLG_History::log('backup_encrypted', 'failure', $e->getMessage());
            
            // En cas d'erreur, retourner le fichier original
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
        
        try {
            BJLG_Debug::log("Début du déchiffrement du fichier : " . basename($filepath));
            
            // Lire le fichier chiffré
            $encrypted_content = file_get_contents($filepath);
            if ($encrypted_content === false) {
                throw new Exception("Impossible de lire le fichier chiffré");
            }
            
            // Vérifier le header magique
            if (substr($encrypted_content, 0, 8) !== 'BJLGENC1') {
                throw new Exception("Format de fichier invalide");
            }
            
            // Extraire les composants
            $version = ord($encrypted_content[8]);
            if ($version !== 1) {
                throw new Exception("Version de chiffrement non supportée");
            }
            
            $iv = substr($encrypted_content, 9, self::IV_LENGTH);
            $hmac = substr($encrypted_content, 9 + self::IV_LENGTH, 32);
            $ciphertext = substr($encrypted_content, 9 + self::IV_LENGTH + 32);
            
            // Utiliser le mot de passe si fourni
            $key = $password ? $this->derive_key_from_password($password) : $this->encryption_key;
            
            // Vérifier l'intégrité avec HMAC
            $calculated_hmac = hash_hmac('sha256', $ciphertext, $key, true);
            if (!hash_equals($hmac, $calculated_hmac)) {
                throw new Exception("Vérification d'intégrité échouée - fichier possiblement corrompu");
            }
            
            // Déchiffrer
            $plaintext = openssl_decrypt(
                $ciphertext,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ($plaintext === false) {
                throw new Exception("Échec du déchiffrement - clé incorrecte ?");
            }
            
            // Sauvegarder le fichier déchiffré
            $decrypted_filepath = substr($filepath, 0, -4); // Enlever .enc
            if (file_put_contents($decrypted_filepath, $plaintext) === false) {
                throw new Exception("Impossible d'écrire le fichier déchiffré");
            }
            
            BJLG_Debug::log("Fichier déchiffré avec succès : " . basename($decrypted_filepath));
            BJLG_History::log('backup_decrypted', 'success', 'Fichier: ' . basename($decrypted_filepath));
            
            // Nettoyer la mémoire
            unset($plaintext);
            unset($ciphertext);
            unset($encrypted_content);
            
            return $decrypted_filepath;
            
        } catch (Exception $e) {
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