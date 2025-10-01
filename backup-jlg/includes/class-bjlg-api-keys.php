<?php
namespace BJLG;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestionnaire des clés API du plugin.
 */
class BJLG_API_Keys {

    private const STATS_TRANSIENT_PREFIX = 'bjlg_api_key_stats_';

    public function __construct() {
        add_action('wp_ajax_bjlg_create_api_key', [$this, 'handle_create_key']);
        add_action('wp_ajax_bjlg_revoke_api_key', [$this, 'handle_revoke_key']);
        add_action('wp_ajax_bjlg_rotate_api_key', [$this, 'handle_rotate_key']);
    }

    public function handle_create_key() {
        $this->assert_can_manage();
        check_ajax_referer('bjlg_nonce', 'nonce');

        $payload = wp_unslash($_POST);

        $label = isset($payload['label']) ? sanitize_text_field($payload['label']) : '';
        $user = $this->resolve_target_user($payload);

        if (!$user) {
            wp_send_json_error([
                'message' => __('Impossible de déterminer l\'utilisateur cible pour la clé API.', 'backup-jlg'),
            ], 400);
        }

        if (!user_can($user, BJLG_CAPABILITY)) {
            wp_send_json_error([
                'message' => __('Les permissions de l\'utilisateur sélectionné sont insuffisantes.', 'backup-jlg'),
            ], 403);
        }

        $plain_key = $this->generate_plain_key();
        $hashed_key = $this->hash_api_key($plain_key);
        $now = time();

        $entry = [
            'id' => $this->generate_key_id(),
            'key' => $hashed_key,
            'label' => $label,
            'created' => $now,
            'last_rotated' => $now,
            'user_id' => (int) $user->ID,
            'user_login' => isset($user->user_login) ? sanitize_text_field((string) $user->user_login) : '',
            'user_email' => isset($user->user_email) ? sanitize_text_field((string) $user->user_email) : '',
            'roles' => $this->extract_roles($user),
            'created_by' => get_current_user_id(),
        ];

        $keys = get_option('bjlg_api_keys', []);
        if (!is_array($keys)) {
            $keys = [];
        }

        array_unshift($keys, $entry);
        update_option('bjlg_api_keys', array_values($keys));

        $response = self::format_key_for_response($entry);
        $response['plain_key'] = $plain_key;
        $response['message'] = __('Nouvelle clé API générée avec succès.', 'backup-jlg');

        wp_send_json_success($response);
    }

    public function handle_revoke_key() {
        $this->assert_can_manage();
        check_ajax_referer('bjlg_nonce', 'nonce');

        $payload = wp_unslash($_POST);
        $key_id = isset($payload['id']) ? sanitize_text_field($payload['id']) : '';

        if ($key_id === '') {
            wp_send_json_error([
                'message' => __('Identifiant de clé API manquant.', 'backup-jlg'),
            ], 400);
        }

        $keys = get_option('bjlg_api_keys', []);
        if (!is_array($keys) || empty($keys)) {
            wp_send_json_error([
                'message' => __('Clé API introuvable.', 'backup-jlg'),
            ], 404);
        }

        foreach ($keys as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entry_id = isset($entry['id']) ? (string) $entry['id'] : '';

            if ($entry_id !== $key_id) {
                continue;
            }

            $this->delete_key_stats($entry);
            unset($keys[$index]);
            update_option('bjlg_api_keys', array_values($keys));

            wp_send_json_success([
                'id' => $key_id,
                'message' => __('La clé API a été révoquée.', 'backup-jlg'),
            ]);
        }

        wp_send_json_error([
            'message' => __('Clé API introuvable.', 'backup-jlg'),
        ], 404);
    }

    public function handle_rotate_key() {
        $this->assert_can_manage();
        check_ajax_referer('bjlg_nonce', 'nonce');

        $payload = wp_unslash($_POST);
        $key_id = isset($payload['id']) ? sanitize_text_field($payload['id']) : '';

        if ($key_id === '') {
            wp_send_json_error([
                'message' => __('Identifiant de clé API manquant.', 'backup-jlg'),
            ], 400);
        }

        $keys = get_option('bjlg_api_keys', []);
        if (!is_array($keys) || empty($keys)) {
            wp_send_json_error([
                'message' => __('Clé API introuvable.', 'backup-jlg'),
            ], 404);
        }

        foreach ($keys as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entry_id = isset($entry['id']) ? (string) $entry['id'] : '';

            if ($entry_id !== $key_id) {
                continue;
            }

            $this->delete_key_stats($entry);

            $plain_key = $this->generate_plain_key();
            $entry['key'] = $this->hash_api_key($plain_key);
            $entry['last_rotated'] = time();
            $keys[$index] = $entry;
            update_option('bjlg_api_keys', array_values($keys));

            $response = self::format_key_for_response($entry);
            $response['plain_key'] = $plain_key;
            $response['message'] = __('La clé API a été renouvelée.', 'backup-jlg');

            wp_send_json_success($response);
        }

        wp_send_json_error([
            'message' => __('Clé API introuvable.', 'backup-jlg'),
        ], 404);
    }

    private function assert_can_manage() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error([
                'message' => __('Vous n\'avez pas la permission d\'effectuer cette action.', 'backup-jlg'),
            ], 403);
        }
    }

    private function resolve_target_user($payload) {
        $candidates = [];

        if (isset($payload['user_id'])) {
            $candidates[] = (int) $payload['user_id'];
        }

        if (isset($payload['user_login'])) {
            $candidates[] = sanitize_text_field($payload['user_login']);
        }

        foreach ($candidates as $candidate) {
            if (is_int($candidate)) {
                $user = get_user_by('id', $candidate);
                if ($user) {
                    return $user;
                }
            }

            if (is_string($candidate) && $candidate !== '') {
                $user = get_user_by('login', $candidate);
                if ($user) {
                    return $user;
                }
            }
        }

        $current = wp_get_current_user();
        if ($current && isset($current->ID) && $current->ID) {
            return $current;
        }

        return null;
    }

    private function generate_plain_key() {
        $length = apply_filters('bjlg_api_key_length', 40);
        $password = wp_generate_password((int) $length, false, false);

        if (!is_string($password) || $password === '') {
            throw new RuntimeException('Unable to generate API key.');
        }

        return $password;
    }

    private function generate_key_id() {
        if (function_exists('wp_generate_uuid4')) {
            return (string) wp_generate_uuid4();
        }

        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(16));
        }

        return uniqid('bjlg_', true);
    }

    private function hash_api_key($plain_key) {
        if (function_exists('wp_hash_password')) {
            return wp_hash_password($plain_key);
        }

        if (function_exists('password_hash')) {
            return password_hash($plain_key, PASSWORD_DEFAULT);
        }

        return hash('sha256', $plain_key);
    }

    private function extract_roles($user) {
        $roles = [];

        if (is_object($user) && isset($user->roles) && is_array($user->roles)) {
            foreach ($user->roles as $role) {
                if (!is_string($role) || $role === '') {
                    continue;
                }

                $roles[] = sanitize_key($role);
            }
        }

        return array_values(array_unique($roles));
    }

    private function delete_key_stats(array $entry) {
        if (!isset($entry['key']) || !is_string($entry['key']) || $entry['key'] === '') {
            return;
        }

        $transient = $this->get_stats_transient_key($entry['key']);
        if ($transient === '') {
            return;
        }

        delete_transient($transient);
    }

    private function get_stats_transient_key($stored_value) {
        if (!is_string($stored_value) || $stored_value === '') {
            return '';
        }

        $prefix = self::STATS_TRANSIENT_PREFIX;

        if (class_exists(BJLG_REST_API::class)) {
            $prefix = BJLG_REST_API::API_KEY_STATS_TRANSIENT_PREFIX;
        }

        return $prefix . md5($stored_value);
    }

    public static function format_key_for_list($entry) {
        if (!is_array($entry)) {
            return null;
        }

        $id = isset($entry['id']) ? (string) $entry['id'] : '';
        if ($id === '') {
            return null;
        }

        $label = isset($entry['label']) ? sanitize_text_field((string) $entry['label']) : '';
        $user = null;

        if (isset($entry['user_id'])) {
            $user = get_user_by('id', (int) $entry['user_id']);
        }

        if (!$user && isset($entry['user_login'])) {
            $user = get_user_by('login', sanitize_text_field((string) $entry['user_login']));
        }

        $user_login = '';
        $user_display = '';

        if ($user) {
            $user_login = isset($user->user_login) ? (string) $user->user_login : '';
            $user_display = isset($user->display_name) ? (string) $user->display_name : '';

            if ($user_display === '' && $user_login !== '') {
                $user_display = $user_login;
            }
        } else {
            if (isset($entry['user_login'])) {
                $user_login = sanitize_text_field((string) $entry['user_login']);
                $user_display = $user_login;
            }
        }

        if ($user_display === '') {
            $user_display = __('Utilisateur inconnu', 'backup-jlg');
        }

        $created = isset($entry['created']) ? (int) $entry['created'] : 0;
        $last_rotated = isset($entry['last_rotated']) ? (int) $entry['last_rotated'] : $created;
        $fingerprint = self::build_fingerprint(isset($entry['key']) ? $entry['key'] : '');

        return [
            'id' => $id,
            'label' => $label,
            'user_display' => $user_display,
            'user_login' => $user_login,
            'created' => $created > 0 ? self::format_timestamp($created) : '',
            'last_rotated' => $last_rotated > 0 ? self::format_timestamp($last_rotated) : '',
            'fingerprint' => $fingerprint,
        ];
    }

    public static function format_key_for_response(array $entry) {
        $data = self::format_key_for_list($entry);

        if ($data === null) {
            return [
                'id' => '',
                'label' => '',
                'user_display' => '',
                'user_login' => '',
                'created' => '',
                'last_rotated' => '',
                'fingerprint' => '',
            ];
        }

        return $data;
    }

    private static function build_fingerprint($stored_value) {
        if (!is_string($stored_value) || $stored_value === '') {
            return '—';
        }

        $hash = md5($stored_value);
        $segments = str_split($hash, 4);
        $segments = array_slice($segments, 0, 3);

        return strtoupper(implode('‑', $segments));
    }

    private static function format_timestamp($timestamp) {
        $timestamp = (int) $timestamp;
        if ($timestamp <= 0) {
            return '';
        }

        if (function_exists('wp_date')) {
            return wp_date('Y-m-d H:i', $timestamp);
        }

        if (function_exists('date_i18n')) {
            return date_i18n('Y-m-d H:i', $timestamp);
        }

        return gmdate('Y-m-d H:i', $timestamp);
    }
}
