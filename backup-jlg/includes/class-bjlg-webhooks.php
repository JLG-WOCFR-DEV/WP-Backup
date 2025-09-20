<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gère la création et l'écoute du webhook pour déclencher les sauvegardes à distance.
 */
class BJLG_Webhooks {

    const WEBHOOK_QUERY_VAR = 'bjlg_trigger_backup';

    public function __construct() {
        // Écoute sur chaque chargement de page pour détecter l'appel du webhook
        add_action('template_redirect', [$this, 'listen_for_webhook'], 5);
        
        // Gère la requête AJAX pour régénérer la clé secrète
        add_action('wp_ajax_bjlg_regenerate_webhook_key', [$this, 'handle_regenerate_key_ajax']);
        
        // Gère les webhooks sortants pour notifications
        add_action('bjlg_send_webhook', [$this, 'send_webhook_notification'], 10, 2);
        
        // Webhooks pour événements
        add_action('bjlg_backup_complete', [$this, 'notify_backup_complete'], 10, 2);
        add_action('bjlg_backup_failed', [$this, 'notify_backup_failed'], 10, 2);
    }

    /**
     * Récupère la clé secrète du webhook depuis la base de données.
     * Si elle n'existe pas, en crée une.
     * @return string La clé secrète.
     */
    public static function get_webhook_key() {
        $key = get_option('bjlg_webhook_key');
        if (empty($key)) {
            $key = self::regenerate_key();
        }
        return $key;
    }
    
    /**
     * Génère une nouvelle clé secrète et la sauvegarde.
     * @return string La nouvelle clé.
     */
    public static function regenerate_key() {
        $new_key = wp_generate_password(40, false, false);
        update_option('bjlg_webhook_key', $new_key);
        
        BJLG_Debug::log("Nouvelle clé de webhook générée.");
        BJLG_History::log('webhook_key_regenerated', 'info', 'Clé de webhook régénérée');
        
        return $new_key;
    }
    
    /**
     * Gère l'appel AJAX pour régénérer la clé.
     */
    public function handle_regenerate_key_ajax() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        $new_key = self::regenerate_key();
        $webhook_url = home_url('/?bjlg_trigger_backup=' . $new_key);
        
        wp_send_json_success([
            'message' => 'Clé régénérée avec succès',
            'webhook_url' => $webhook_url
        ]);
    }

    /**
     * Méthode qui écoute les requêtes entrantes pour détecter l'appel au webhook.
     */
    public function listen_for_webhook() {
        if (!isset($_GET[self::WEBHOOK_QUERY_VAR])) {
            return;
        }

        $provided_key = sanitize_text_field($_GET[self::WEBHOOK_QUERY_VAR]);
        $stored_key = self::get_webhook_key();
        
        // Comparaison sécurisée pour éviter les attaques par analyse temporelle (timing attacks)
        if (!hash_equals($stored_key, $provided_key)) {
            BJLG_Debug::log("Échec du déclenchement du webhook : clé invalide fournie.");
            
            // Log de sécurité
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            BJLG_History::log('webhook_failed', 'failure', "Tentative avec clé invalide depuis IP: $ip");
            
            wp_send_json_error(['message' => 'Invalid or missing key.'], 403);
            exit;
        }
        
        // Paramètres optionnels du webhook
        $components = isset($_GET['components']) ? 
            explode(',', sanitize_text_field($_GET['components'])) : 
            ['db', 'plugins', 'themes', 'uploads'];
        
        $encrypt = isset($_GET['encrypt']) && $_GET['encrypt'] === 'true';
        $incremental = isset($_GET['incremental']) && $_GET['incremental'] === 'true';
        
        BJLG_Debug::log("Webhook déclenché avec succès. Planification d'une sauvegarde en arrière-plan.");
        
        // Enregistrer l'appel du webhook
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        BJLG_History::log('webhook_triggered', 'success', "IP: $ip | User-Agent: $user_agent");
        
        // Créer la tâche de sauvegarde
        $task_id = 'bjlg_backup_' . md5(uniqid('webhook', true));
        $task_data = [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => 'Initialisation (webhook)...',
            'components' => $components,
            'encrypt' => $encrypt,
            'incremental' => $incremental,
            'source' => 'webhook'
        ];
        
        set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl());
        
        // Planifier l'exécution immédiate
        wp_schedule_single_event(time(), 'bjlg_run_backup_task', ['task_id' => $task_id]);
        
        // Renvoyer une réponse de succès
        wp_send_json_success([
            'message' => 'Backup job scheduled successfully.',
            'task_id' => $task_id,
            'components' => $components,
            'encrypt' => $encrypt,
            'incremental' => $incremental
        ]);
        exit;
    }
    
    /**
     * Envoie une notification webhook à une URL externe
     */
    public function send_webhook_notification($url, $data) {
        if (empty($url)) {
            return false;
        }
        
        $webhook_settings = get_option('bjlg_webhook_settings', []);
        $secret = $webhook_settings['secret'] ?? '';
        
        // Préparer le payload
        $payload = [
            'event' => $data['event'] ?? 'backup_event',
            'timestamp' => current_time('c'),
            'site_url' => get_site_url(),
            'site_name' => get_bloginfo('name'),
            'data' => $data
        ];
        
        // Ajouter une signature si un secret est configuré
        $headers = ['Content-Type' => 'application/json'];
        if (!empty($secret)) {
            $signature = hash_hmac('sha256', json_encode($payload), $secret);
            $headers['X-BJLG-Signature'] = $signature;
        }
        
        // Envoyer la requête
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 30,
            'sslverify' => true
        ]);
        
        if (is_wp_error($response)) {
            BJLG_Debug::log("Erreur webhook : " . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code >= 200 && $response_code < 300) {
            BJLG_Debug::log("Webhook envoyé avec succès à : $url");
            return true;
        } else {
            BJLG_Debug::log("Webhook échoué. Code de réponse : $response_code");
            return false;
        }
    }
    
    /**
     * Notifie la fin d'une sauvegarde réussie
     */
    public function notify_backup_complete($filename, $details) {
        $webhook_settings = get_option('bjlg_webhook_settings', []);
        
        if (empty($webhook_settings['enabled']) || empty($webhook_settings['urls']['backup_complete'])) {
            return;
        }
        
        $data = [
            'event' => 'backup_complete',
            'filename' => $filename,
            'size' => $details['size'] ?? 0,
            'size_formatted' => size_format($details['size'] ?? 0),
            'components' => $details['components'] ?? [],
            'encrypted' => $details['encrypted'] ?? false,
            'incremental' => $details['incremental'] ?? false,
            'duration' => $details['duration'] ?? null
        ];
        
        $this->send_webhook_notification($webhook_settings['urls']['backup_complete'], $data);
    }
    
    /**
     * Notifie l'échec d'une sauvegarde
     */
    public function notify_backup_failed($error_message, $details) {
        $webhook_settings = get_option('bjlg_webhook_settings', []);
        
        if (empty($webhook_settings['enabled']) || empty($webhook_settings['urls']['backup_failed'])) {
            return;
        }
        
        $data = [
            'event' => 'backup_failed',
            'error' => $error_message,
            'components' => $details['components'] ?? [],
            'task_id' => $details['task_id'] ?? null
        ];
        
        $this->send_webhook_notification($webhook_settings['urls']['backup_failed'], $data);
    }
    
    /**
     * Teste un webhook
     */
    public function test_webhook($url) {
        $test_data = [
            'event' => 'test',
            'message' => 'Test webhook from Backup JLG',
            'test_time' => current_time('c')
        ];
        
        return $this->send_webhook_notification($url, $test_data);
    }
    
    /**
     * Obtient les statistiques des webhooks
     */
    public function get_webhook_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bjlg_history';
        
        $stats = [
            'total_triggered' => 0,
            'successful' => 0,
            'failed' => 0,
            'last_triggered' => null,
            'recent_ips' => []
        ];
        
        // Total des déclenchements
        $stats['total_triggered'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE action_type = 'webhook_triggered'"
        );
        
        // Succès
        $stats['successful'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name 
             WHERE action_type = 'webhook_triggered' 
             AND status = 'success'"
        );
        
        // Échecs
        $stats['failed'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name 
             WHERE action_type = 'webhook_failed'"
        );
        
        // Dernier déclenchement
        $last = $wpdb->get_row(
            "SELECT * FROM $table_name 
             WHERE action_type = 'webhook_triggered' 
             ORDER BY timestamp DESC 
             LIMIT 1",
            ARRAY_A
        );
        
        if ($last) {
            $stats['last_triggered'] = $last['timestamp'];
        }
        
        // IPs récentes
        $recent = $wpdb->get_results(
            "SELECT details FROM $table_name 
             WHERE action_type = 'webhook_triggered' 
             AND timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY) 
             ORDER BY timestamp DESC 
             LIMIT 10",
            ARRAY_A
        );
        
        foreach ($recent as $entry) {
            if (preg_match('/IP: ([^\s|]+)/', $entry['details'], $matches)) {
                $stats['recent_ips'][] = $matches[1];
            }
        }
        
        $stats['recent_ips'] = array_unique($stats['recent_ips']);
        
        return $stats;
    }
}