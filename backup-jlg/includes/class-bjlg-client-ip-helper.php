<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Utilitaires pour déterminer l'adresse IP du client en tenant compte des proxies de confiance.
 */
class BJLG_Client_IP_Helper {
    /**
     * Récupère l'adresse IP du client en appliquant une liste d'en-têtes autorisés.
     *
     * @param string|array<int, string> $filter_hooks Filtres à appliquer sur la liste des en-têtes autorisés.
     * @param string                    $option_name  Option contenant les en-têtes autorisés configurés.
     * @param string                    $unknown_ip   Valeur retournée si aucune IP valide n'est trouvée.
     */
    public static function get_client_ip($filter_hooks = 'bjlg_rate_limiter_trusted_proxy_headers', $option_name = 'bjlg_trusted_proxy_headers', $unknown_ip = 'Unknown') {
        $trusted_headers = self::get_trusted_proxy_headers($filter_hooks, $option_name);

        foreach ($trusted_headers as $header) {
            $server_key = self::normalize_server_key($header);

            if ($server_key === null || !array_key_exists($server_key, $_SERVER)) {
                continue;
            }

            $ip = self::extract_ip_from_value($_SERVER[$server_key]);

            if ($ip !== null) {
                return $ip;
            }
        }

        return self::get_remote_address($unknown_ip);
    }

    /**
     * Retourne la liste des en-têtes autorisés après application des filtres fournis.
     *
     * @param string|array<int, string> $filter_hooks
     * @param string                    $option_name
     * @return array<int, string>
     */
    public static function get_trusted_proxy_headers($filter_hooks = 'bjlg_rate_limiter_trusted_proxy_headers', $option_name = 'bjlg_trusted_proxy_headers') {
        $option_headers = get_option($option_name, []);

        if (is_string($option_headers)) {
            $option_headers = array_filter(array_map('trim', explode(',', $option_headers)));
        }

        if (!is_array($option_headers)) {
            $option_headers = [];
        }

        $headers = $option_headers;
        $hooks = self::normalize_filter_hooks($filter_hooks);

        foreach ($hooks as $hook) {
            $filtered = apply_filters($hook, $headers);

            if (!is_array($filtered)) {
                $headers = [];
                continue;
            }

            $headers = $filtered;
        }

        $sanitized = [];

        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }

            $normalized = trim($header);

            if ($normalized === '') {
                continue;
            }

            $sanitized[] = $normalized;
        }

        return array_values(array_unique($sanitized));
    }

    /**
     * Normalise la liste de filtres à appliquer.
     *
     * @param string|array<int, string> $filter_hooks
     * @return array<int, string>
     */
    private static function normalize_filter_hooks($filter_hooks) {
        if (is_string($filter_hooks)) {
            $filter_hooks = [$filter_hooks];
        } elseif (!is_array($filter_hooks)) {
            $filter_hooks = [];
        }

        $normalized = [];

        foreach ($filter_hooks as $hook) {
            if (!is_string($hook)) {
                continue;
            }

            $hook = trim($hook);

            if ($hook === '') {
                continue;
            }

            $normalized[] = $hook;
        }

        if (empty($normalized)) {
            $normalized[] = 'bjlg_rate_limiter_trusted_proxy_headers';
        }

        return $normalized;
    }

    /**
     * Transforme un nom d'en-tête HTTP en clé compatible avec $_SERVER.
     */
    private static function normalize_server_key($header) {
        if (!is_string($header)) {
            return null;
        }

        $server_key = strtoupper(str_replace('-', '_', $header));

        if ($server_key === '') {
            return null;
        }

        if (strpos($server_key, 'HTTP_') !== 0 && $server_key !== 'REMOTE_ADDR') {
            $server_key = 'HTTP_' . $server_key;
        }

        return $server_key;
    }

    /**
     * Extrait une IP valide depuis une valeur d'en-tête.
     */
    private static function extract_ip_from_value($value) {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $ip = $value;

        if (strpos($ip, ',') !== false) {
            $parts = explode(',', $ip);
            $ip = $parts[0];
        }

        $ip = trim($ip);

        if ($ip === '') {
            return null;
        }

        $validated = filter_var($ip, FILTER_VALIDATE_IP);

        if ($validated === false) {
            return null;
        }

        return $validated;
    }

    /**
     * Retourne l'adresse IP de secours.
     */
    private static function get_remote_address($unknown_ip) {
        $fallback_ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if (is_string($fallback_ip) && $fallback_ip !== '') {
            $validated = filter_var($fallback_ip, FILTER_VALIDATE_IP);

            if ($validated !== false) {
                return $validated;
            }
        }

        return $unknown_ip;
    }
}
