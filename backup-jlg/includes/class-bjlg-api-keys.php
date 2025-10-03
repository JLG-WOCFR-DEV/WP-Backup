<?php
namespace BJLG;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestionnaire des clés API utilisées par les intégrations externes.
 */
class BJLG_API_Keys {

    public const OPTION_NAME = 'bjlg_api_keys';
    private const SECRET_LENGTH = 40;

    public function __construct() {
        add_action('wp_ajax_bjlg_create_api_key', [$this, 'handle_create_key']);
        add_action('wp_ajax_bjlg_revoke_api_key', [$this, 'handle_revoke_key']);
        add_action('wp_ajax_bjlg_rotate_api_key', [$this, 'handle_rotate_key']);
    }

    /**
     * Retourne toutes les clés API formatées pour l'affichage.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_keys() {
        $keys = self::get_indexed_keys();

        uasort($keys, static function ($a, $b) {
            $a_time = isset($a['created_at']) ? (int) $a['created_at'] : 0;
            $b_time = isset($b['created_at']) ? (int) $b['created_at'] : 0;

            if ($a_time === $b_time) {
                return 0;
            }

            return ($a_time < $b_time) ? 1 : -1;
        });

        return array_map([self::class, 'format_key_for_output'], $keys);
    }

    /**
     * Crée une nouvelle clé API.
     */
    public function handle_create_key() {
        $this->validate_request();

        $label = '';
        if (isset($_POST['label'])) {
            $label = sanitize_text_field(wp_unslash((string) $_POST['label']));
        }

        $label = self::truncate($label, 200);

        $keys = self::get_indexed_keys();

        $identifier = self::generate_identifier();
        while (isset($keys[$identifier])) {
            $identifier = self::generate_identifier();
        }

        $secret = self::generate_secret();
        $timestamp = time();
        $user_meta = self::get_current_user_metadata();

        $keys[$identifier] = array_merge(
            [
                'id' => $identifier,
                'label' => $label,
                'key' => self::hash_secret($secret),
                'created_at' => $timestamp,
                'last_rotated_at' => $timestamp,
                'display_secret' => self::sanitize_secret($secret),
            ],
            $user_meta
        );

        self::save_indexed_keys($keys);

        wp_send_json_success([
            'message' => __('Clé API créée avec succès.', 'backup-jlg'),
            'key' => self::format_key_for_output($keys[$identifier]),
            'nonce' => self::generate_nonce_value(),
        ]);
    }

    /**
     * Révoque (supprime) une clé API existante.
     */
    public function handle_revoke_key() {
        $this->validate_request();

        $key_id = self::sanitize_identifier(self::get_request_value('key_id'));

        if ($key_id === '') {
            wp_send_json_error([
                'message' => __('Identifiant de clé invalide.', 'backup-jlg'),
            ], 400);
        }

        $keys = self::get_indexed_keys();

        if (!isset($keys[$key_id])) {
            wp_send_json_error([
                'message' => __('Clé API introuvable.', 'backup-jlg'),
            ], 404);
        }

        unset($keys[$key_id]);
        self::save_indexed_keys($keys);

        wp_send_json_success([
            'message' => __('Clé API révoquée.', 'backup-jlg'),
            'key_id' => $key_id,
            'nonce' => self::generate_nonce_value(),
        ]);
    }

    /**
     * Régénère le secret associé à une clé API existante.
     */
    public function handle_rotate_key() {
        $this->validate_request();

        $key_id = self::sanitize_identifier(self::get_request_value('key_id'));

        if ($key_id === '') {
            wp_send_json_error([
                'message' => __('Identifiant de clé invalide.', 'backup-jlg'),
            ], 400);
        }

        $keys = self::get_indexed_keys();

        if (!isset($keys[$key_id])) {
            wp_send_json_error([
                'message' => __('Clé API introuvable.', 'backup-jlg'),
            ], 404);
        }

        $new_secret = self::generate_secret();
        $keys[$key_id]['display_secret'] = self::sanitize_secret($new_secret);
        $keys[$key_id]['key'] = self::hash_secret($new_secret);
        unset($keys[$key_id]['secret']);
        $keys[$key_id]['last_rotated_at'] = time();
        $keys[$key_id] = array_merge($keys[$key_id], self::get_current_user_metadata());

        self::save_indexed_keys($keys);

        wp_send_json_success([
            'message' => __('Clé API régénérée.', 'backup-jlg'),
            'key' => self::format_key_for_output($keys[$key_id]),
            'nonce' => self::generate_nonce_value(),
        ]);
    }

    /**
     * Valide la requête AJAX.
     */
    private function validate_request() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error([
                'message' => __('Permission refusée.', 'backup-jlg'),
            ], 403);
        }

        check_ajax_referer('bjlg_api_keys', 'nonce');
    }

    /**
     * Récupère toutes les clés stockées sous la forme d'un tableau associatif indexé par identifiant.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function get_indexed_keys() {
        $stored = get_option(self::OPTION_NAME, []);

        if (!is_array($stored)) {
            return [];
        }

        $indexed = [];

        foreach ($stored as $key => $record) {
            if (!is_array($record)) {
                continue;
            }

            if (!isset($record['id']) && is_string($key)) {
                $record['id'] = $key;
            }

            $normalized = self::normalize_record($record);

            if ($normalized === null) {
                continue;
            }

            $indexed[$normalized['id']] = $normalized;
        }

        return $indexed;
    }

    /**
     * Enregistre la liste des clés API.
     *
     * @param array<string, array<string, mixed>> $keys
     */
    private static function save_indexed_keys(array $keys) {
        $prepared = [];

        foreach ($keys as $key => $record) {
            if (!is_array($record)) {
                continue;
            }

            $normalized = self::normalize_record($record, true);

            if ($normalized === null) {
                continue;
            }

            $prepared[$normalized['id']] = $normalized;
        }

        update_option(self::OPTION_NAME, $prepared);
    }

    /**
     * Prépare une clé pour l'affichage ou la réponse JSON.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private static function format_key_for_output(array $record) {
        $created_at = isset($record['created_at']) ? (int) $record['created_at'] : time();
        $rotated_at = isset($record['last_rotated_at']) ? (int) $record['last_rotated_at'] : $created_at;

        if ($rotated_at <= 0) {
            $rotated_at = $created_at;
        }

        if ($created_at <= 0) {
            $created_at = time();
        }

        $label = isset($record['label']) ? (string) $record['label'] : '';
        $label = self::truncate($label, 200);

        if ($label === '') {
            $label = __('Sans nom', 'backup-jlg');
        }

        $display_secret = '';
        if (isset($record['display_secret']) && is_string($record['display_secret'])) {
            $display_secret = self::sanitize_secret($record['display_secret']);
        }

        $masked_secret = __('Clé masquée', 'backup-jlg');
        $is_hidden = ($display_secret === '');

        $user_meta = self::extract_user_metadata($record);

        return array_merge(
            [
                'id' => (string) $record['id'],
                'label' => $label,
                'display_secret' => $display_secret,
                'masked_secret' => $masked_secret,
                'is_secret_hidden' => $is_hidden,
                'created_at' => $created_at,
                'last_rotated_at' => $rotated_at,
                'created_at_iso' => gmdate('c', $created_at),
                'last_rotated_at_iso' => gmdate('c', $rotated_at),
                'created_at_human' => gmdate('Y-m-d H:i:s', $created_at),
                'last_rotated_at_human' => gmdate('Y-m-d H:i:s', $rotated_at),
            ],
            $user_meta
        );
    }

    /**
     * Normalise un enregistrement brut issu de la base de données.
     *
     * @param array<string, mixed> $record
     * @param bool $for_storage Indique si l'enregistrement doit être préparé pour la persistance.
     *
     * @return array<string, mixed>|null
     */
    private static function normalize_record(array $record, $for_storage = false) {
        if (!isset($record['id']) && isset($record['key'])) {
            $record['id'] = $record['key'];
        }

        $identifier = isset($record['id']) ? self::sanitize_identifier($record['id']) : '';

        if ($identifier === '') {
            return null;
        }

        $label = '';
        if (isset($record['label'])) {
            $label = sanitize_text_field((string) $record['label']);
            $label = self::truncate($label, 200);
        }

        $hashed_key = '';
        if (isset($record['key']) && is_string($record['key'])) {
            $hashed_key = trim($record['key']);
        }

        $plain_secret = '';
        $has_display_secret_field = false;
        if (isset($record['display_secret']) && is_string($record['display_secret'])) {
            $plain_secret = self::sanitize_secret($record['display_secret']);
            $has_display_secret_field = ($plain_secret !== '');
        } elseif (isset($record['secret'])) {
            $plain_secret = self::sanitize_secret($record['secret']);
        }

        if ($hashed_key === '' && $plain_secret !== '') {
            $hashed_key = self::hash_secret($plain_secret);
        }

        if ($hashed_key === '' && isset($record['raw_key']) && is_string($record['raw_key'])) {
            $hashed_key = trim($record['raw_key']);
        }

        if ($hashed_key === '') {
            return null;
        }

        if (!self::is_secret_hashed($hashed_key)) {
            if ($plain_secret !== '') {
                $hashed_key = self::hash_secret($plain_secret);
            } else {
                $hashed_key = self::hash_secret($hashed_key);
            }
        }

        $created_at = isset($record['created_at']) ? (int) $record['created_at'] : time();
        $rotated_at = isset($record['last_rotated_at']) ? (int) $record['last_rotated_at'] : $created_at;

        if ($created_at <= 0) {
            $created_at = time();
        }

        if ($rotated_at <= 0) {
            $rotated_at = $created_at;
        }

        $user_meta = self::extract_user_metadata($record);
        $user_meta['user_id'] = isset($user_meta['user_id']) ? (int) $user_meta['user_id'] : 0;

        if ($user_meta['user_id'] < 0) {
            $user_meta['user_id'] = 0;
        }

        $normalized = array_merge(
            [
                'id' => $identifier,
                'label' => $label,
                'key' => $hashed_key,
                'created_at' => $created_at,
                'last_rotated_at' => $rotated_at,
            ],
            $user_meta
        );

        if (!$for_storage && $has_display_secret_field && $plain_secret !== '') {
            $normalized['display_secret'] = $plain_secret;
        }

        return $normalized;
    }

    /**
     * Génère un identifiant unique pour une clé API.
     */
    private static function generate_identifier() {
        try {
            return bin2hex(random_bytes(16));
        } catch (Exception $exception) {
            return strtolower(wp_generate_password(32, false, false));
        }
    }

    /**
     * Génère un secret pour une clé API.
     */
    private static function generate_secret() {
        return wp_generate_password(self::SECRET_LENGTH, false, false);
    }

    /**
     * Hash a secret using WordPress utilities when available.
     */
    private static function hash_secret($secret) {
        if (!is_string($secret) || $secret === '') {
            return '';
        }

        if (function_exists('wp_hash_password')) {
            return wp_hash_password($secret);
        }

        if (function_exists('password_hash')) {
            return password_hash($secret, PASSWORD_DEFAULT);
        }

        return hash('sha256', $secret);
    }

    /**
     * Sanitize an identifier string.
     */
    private static function sanitize_identifier($value) {
        $value = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value);

        return is_string($value) ? $value : '';
    }

    /**
     * Sanitize a secret string.
     */
    private static function sanitize_secret($value) {
        $value = preg_replace('/[^A-Za-z0-9]/', '', (string) $value);

        return is_string($value) ? $value : '';
    }

    /**
     * Determine whether a stored value looks like a hashed secret.
     */
    private static function is_secret_hashed($value) {
        if (!is_string($value) || $value === '') {
            return false;
        }

        if (strpos($value, '$P$') === 0 || strpos($value, '$H$') === 0) {
            return true;
        }

        if (strpos($value, '$argon2') === 0 || strpos($value, '$2y$') === 0 || strpos($value, '$2a$') === 0 || strpos($value, '$2b$') === 0) {
            return true;
        }

        if (function_exists('password_get_info')) {
            $info = password_get_info($value);

            if (!empty($info['algo'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns sanitized metadata for the currently authenticated user.
     *
     * @return array{user_id:int,user_login:string,user_email:string}
     */
    private static function get_current_user_metadata() {
        $defaults = [
            'user_id' => 0,
            'user_login' => '',
            'user_email' => '',
        ];

        if (!function_exists('wp_get_current_user')) {
            return $defaults;
        }

        $user = wp_get_current_user();

        if (!is_object($user)) {
            return $defaults;
        }

        $user_id = isset($user->ID) ? (int) $user->ID : 0;
        $user_login = '';
        $user_email = '';

        if (isset($user->user_login)) {
            $user_login = sanitize_text_field((string) $user->user_login);
        }

        if (isset($user->user_email)) {
            if (function_exists('sanitize_email')) {
                $user_email = sanitize_email((string) $user->user_email);
            } else {
                $user_email = sanitize_text_field((string) $user->user_email);
            }
        }

        return [
            'user_id' => $user_id,
            'user_login' => $user_login,
            'user_email' => $user_email,
        ];
    }

    /**
     * Extract sanitized metadata from a record array.
     *
     * @param array<string, mixed> $record
     *
     * @return array{user_id:int,user_login:string,user_email:string}
     */
    private static function extract_user_metadata(array $record) {
        $user_id = isset($record['user_id']) ? (int) $record['user_id'] : 0;
        $user_login = '';
        $user_email = '';

        if (isset($record['user_login'])) {
            $user_login = sanitize_text_field((string) $record['user_login']);
        }

        if (isset($record['user_email'])) {
            if (function_exists('sanitize_email')) {
                $user_email = sanitize_email((string) $record['user_email']);
            } else {
                $user_email = sanitize_text_field((string) $record['user_email']);
            }
        }

        return [
            'user_id' => $user_id,
            'user_login' => $user_login,
            'user_email' => $user_email,
        ];
    }

    /**
     * Retourne une valeur depuis la requête courante.
     */
    private static function get_request_value($key) {
        if (isset($_POST[$key])) {
            return wp_unslash($_POST[$key]);
        }

        if (isset($_REQUEST[$key])) {
            return wp_unslash($_REQUEST[$key]);
        }

        return '';
    }

    /**
     * Limite la longueur d'une chaîne.
     */
    private static function truncate($value, $length) {
        $value = (string) $value;

        if ($length <= 0) {
            return '';
        }

        if (strlen($value) <= $length) {
            return $value;
        }

        return substr($value, 0, $length);
    }

    /**
     * Génère un nonce pour sécuriser les actions AJAX.
     */
    private static function generate_nonce_value() {
        if (function_exists('wp_create_nonce')) {
            return wp_create_nonce('bjlg_api_keys');
        }

        return sha1('bjlg-api-keys-' . microtime(true));
    }
}
