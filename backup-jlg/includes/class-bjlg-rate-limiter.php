<?php
namespace BJLG;

/**
 * Gère la limitation de taux pour l'API REST
 * Fichier : includes/class-bjlg-rate-limiter.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class BJLG_Rate_Limiter {

    const RATE_LIMIT_MINUTE = 60;
    const RATE_LIMIT_HOUR = 1000;

    /**
     * Vérifie si la requête dépasse les limites
     */
    public function check($request) {
        $client_id = $this->get_client_identifier($request);

        // Vérifier le taux par minute
        $minute_key = 'bjlg_rate_' . $client_id . '_' . date('YmdHi');
        $minute_count = get_transient($minute_key) ?: 0;

        if ($minute_count >= self::RATE_LIMIT_MINUTE) {
            BJLG_Debug::log("Rate limit exceeded for client: $client_id (minute limit)");
            return false;
        }

        // Vérifier le taux par heure
        $hour_key = 'bjlg_rate_' . $client_id . '_' . date('YmdH');
        $hour_count = get_transient($hour_key) ?: 0;

        if ($hour_count >= self::RATE_LIMIT_HOUR) {
            BJLG_Debug::log("Rate limit exceeded for client: $client_id (hour limit)");
            return false;
        }
        
        // Incrémenter les compteurs
        set_transient($minute_key, $minute_count + 1, 60);
        set_transient($hour_key, $hour_count + 1, 3600);
        
        return true;
    }
    
    /**
     * Obtient l'identifiant unique du client
     */
    private function get_client_identifier($request) {
        // Utiliser l'API key si présente
        $api_key = $request->get_header('X-API-Key');
        if ($api_key) {
            return md5('key_' . $api_key);
        }

        // Utiliser le token JWT si présent
        $auth_header = $request->get_header('Authorization');
        if ($auth_header && strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
            return md5('token_' . $token);
        }

        // Sinon utiliser l'IP
        $ip = $this->get_client_ip();
        return md5('ip_' . $ip);
    }

    /**
     * Obtient l'adresse IP du client
     */
    private function get_client_ip() {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR',
                   'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR',
                   'HTTP_FORWARDED', 'REMOTE_ADDR'];

        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = $_SERVER[$key];

                // Pour X-Forwarded-For, prendre la première IP
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }

                $ip = trim($ip);

                if (filter_var($ip, FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Réinitialise les limites pour un client
     */
    public function reset_limits($client_identifier) {
        $minute_key = 'bjlg_rate_' . $client_identifier . '_' . date('YmdHi');
        $hour_key = 'bjlg_rate_' . $client_identifier . '_' . date('YmdH');

        delete_transient($minute_key);
        delete_transient($hour_key);

        BJLG_Debug::log("Rate limits reset for client: $client_identifier");
    }

    /**
     * Obtient les statistiques de limitation
     */
    public function get_stats($client_identifier = null) {
        if ($client_identifier) {
            $minute_key = 'bjlg_rate_' . $client_identifier . '_' . date('YmdHi');
            $hour_key = 'bjlg_rate_' . $client_identifier . '_' . date('YmdH');

            return [
                'minute_count' => get_transient($minute_key) ?: 0,
                'minute_limit' => self::RATE_LIMIT_MINUTE,
                'hour_count' => get_transient($hour_key) ?: 0,
                'hour_limit' => self::RATE_LIMIT_HOUR
            ];
        }

        // Statistiques globales
        global $wpdb;
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_bjlg_rate_%'"
        );

        return [
            'active_clients' => intval($count / 2), // Divisé par 2 car minute + hour
            'minute_limit' => self::RATE_LIMIT_MINUTE,
            'hour_limit' => self::RATE_LIMIT_HOUR
        ];
    }
}
