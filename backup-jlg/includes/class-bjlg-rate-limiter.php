<?php
/**
 * Gère la limitation de taux pour l'API REST
 * Fichier : includes/class-bjlg-rate-limiter.php
 */

if (!defined('ABSPATH')) exit;

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
            return false;
        }
        
        // Vérifier le taux par heure
        $hour_key = 'bjlg_rate_' . $client_id . '_' . date('YmdH');
        $hour_count = get_transient($hour_key) ?: 0;
        
        if ($hour_count >= self::RATE_LIMIT_HOUR) {
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
            return md5($api_key);
        }
        
        // Sinon utiliser l'IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return md5($ip);
    }
}
