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
    const WEBHOOK_HEADER = 'X-BJLG-Webhook-Key';
    const WEBHOOK_SECURE_MARKER = '1';

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
     * Récupère l'URL de déclenchement du webhook sans exposer la clé.
     *
     * @return string
     */
    public static function get_webhook_endpoint() {
        return add_query_arg(self::WEBHOOK_QUERY_VAR, self::WEBHOOK_SECURE_MARKER, home_url('/'));
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
        $webhook_url = self::get_webhook_endpoint();
        $example_request = sprintf(
            "curl -X POST %s \
  -H 'Content-Type: application/json' \
  -H '%s: %s'",
            esc_url_raw($webhook_url),
            self::WEBHOOK_HEADER,
            $new_key
        );

        wp_send_json_success([
            'message' => 'Clé régénérée avec succès',
            'webhook_url' => $webhook_url,
            'webhook_key' => $new_key,
            'example_request' => $example_request
        ]);
    }

    /**
     * Méthode qui écoute les requêtes entrantes pour détecter l'appel au webhook.
     */
    public function listen_for_webhook() {
        if (!isset($_GET[self::WEBHOOK_QUERY_VAR])) {
            return;
        }

        $stored_key = self::get_webhook_key();
        $request_method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $provided_key = '';
        $key_source = '';
        $legacy_mode = false;

        $header_key = $this->get_request_header(self::WEBHOOK_HEADER);
        if (!empty($header_key)) {
            $provided_key = sanitize_text_field($header_key);
            $key_source = 'header';
        }

        if ($provided_key === '') {
            $auth_header = $this->get_request_header('Authorization');
            if (!empty($auth_header)) {
                $auth_header = trim($auth_header);
                $matches = [];
                if (preg_match('/^(?:Bearer|BJLG)\s+(.+)$/i', $auth_header, $matches)) {
                    $auth_header = $matches[1];
                }
                if (!empty($auth_header)) {
                    $provided_key = sanitize_text_field($auth_header);
                    $key_source = 'authorization';
                }
            }
        }

        if ($provided_key === '' && $request_method === 'POST') {
            $post_key = isset($_POST['webhook_key']) ? wp_unslash($_POST['webhook_key']) : '';
            if (!empty($post_key) && is_string($post_key)) {
                $provided_key = sanitize_text_field($post_key);
                $key_source = 'post';
            } else {
                $raw_input = file_get_contents('php://input');
                if (!empty($raw_input)) {
                    $decoded = json_decode($raw_input, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($decoded['webhook_key']) && is_string($decoded['webhook_key'])) {
                        $provided_key = sanitize_text_field($decoded['webhook_key']);
                        $key_source = 'json';
                    }
                }
            }
        }

        if ($provided_key === '') {
            $raw_query_value = isset($_GET[self::WEBHOOK_QUERY_VAR]) ? wp_unslash($_GET[self::WEBHOOK_QUERY_VAR]) : '';
            $raw_query_value = is_string($raw_query_value) ? trim($raw_query_value) : '';
            if ($raw_query_value !== '' && $raw_query_value !== self::WEBHOOK_SECURE_MARKER) {
                $provided_key = sanitize_text_field($raw_query_value);
                $key_source = 'query-string';
                $legacy_mode = true;
            }
        } elseif ($key_source === 'query-string') {
            $legacy_mode = true;
        }

        if ($provided_key === '') {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            BJLG_Debug::log("Échec du déclenchement du webhook : aucune clé fournie.");
            BJLG_History::log('webhook_secure_missing_key', 'warning', "Tentative sans clé via mode sécurisé depuis IP: $ip");

            wp_send_json_error([
                'message' => __('Missing webhook key. Provide it via the X-BJLG-Webhook-Key header or POST body.', 'backup-jlg')
            ], 403);
            exit;
        }

        if ($legacy_mode) {
            BJLG_Debug::log("Webhook déclenché via schéma legacy (clé dans l'URL). Ce mode est déprécié.");
            BJLG_History::log('webhook_legacy_mode', 'warning', "Appel webhook avec clé dans l'URL (déprécié).");
        }

        // Comparaison sécurisée pour éviter les attaques par analyse temporelle (timing attacks)
        if (!hash_equals($stored_key, $provided_key)) {
            BJLG_Debug::log("Échec du déclenchement du webhook : clé invalide fournie.");

            // Log de sécurité
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            if ($legacy_mode) {
                BJLG_History::log('webhook_failed', 'failure', "Tentative legacy avec clé invalide depuis IP: $ip");
            } else {
                BJLG_History::log('webhook_secure_failed', 'failure', "Clé invalide via mode sécurisé ({$key_source}) depuis IP: $ip");
            }

            wp_send_json_error(['message' => 'Invalid or missing key.'], 403);
            exit;
        }

        if (!$legacy_mode) {
            BJLG_History::log('webhook_secure_mode', 'success', sprintf('Webhook déclenché via mode sécurisé (%s).', $key_source ?: 'unknown'));
        }

        if ($legacy_mode && !headers_sent()) {
            header('Warning: 299 BJLG "Legacy webhook scheme is deprecated and will be removed in a future release."');
        }

        // Paramètres optionnels du webhook
        $default_allowed_components = ['db', 'plugins', 'themes', 'uploads'];
        /**
         * Filtre les composants autorisés pour les sauvegardes déclenchées via webhook.
         *
         * @param array<int, string> $default_allowed_components Liste de composants autorisés par défaut.
         */
        $allowed_components = apply_filters('bjlg_webhook_allowed_components', $default_allowed_components);
        $allowed_components = array_values(array_unique(array_filter(
            array_map('sanitize_key', (array) $allowed_components)
        )));

        if (empty($allowed_components)) {
            $allowed_components = $default_allowed_components;
        }

        if (isset($_GET['components'])) {
            $raw_components = wp_unslash($_GET['components']);

            if (is_string($raw_components)) {
                $raw_components = array_map('trim', explode(',', $raw_components));
            }

            $requested_components = array_filter(array_map('sanitize_key', (array) $raw_components));
            $components = array_values(array_unique(array_intersect($requested_components, $allowed_components)));

            if (empty($components)) {
                BJLG_Debug::log('Webhook rejected: no valid components provided.');
                BJLG_History::log(
                    'webhook_invalid_components',
                    'failure',
                    'Composants invalides fournis via webhook.'
                );

                wp_send_json_error([
                    'message' => sprintf(
                        __('No valid components were requested. Allowed components are: %s.', 'backup-jlg'),
                        implode(', ', $allowed_components)
                    ),
                ], 400);
            }
        } else {
            $components = $allowed_components;
        }

        $encrypt = isset($_GET['encrypt']) && $_GET['encrypt'] === 'true';
        $incremental = isset($_GET['incremental']) && $_GET['incremental'] === 'true';

        $available_destinations = BJLG_Backup::get_connectable_destination_ids('webhook');
        $destinations = $available_destinations;

        if (isset($_GET['destinations'])) {
            $raw_destinations = wp_unslash($_GET['destinations']);

            if (is_string($raw_destinations)) {
                $raw_trimmed = trim($raw_destinations);
                if ($raw_trimmed === '' || strtolower($raw_trimmed) === 'none') {
                    $destinations = [];
                } else {
                    $raw_destinations = array_map('trim', explode(',', $raw_destinations));
                }
            }

            if ($destinations !== []) {
                $requested_destinations = array_filter(array_map('sanitize_key', (array) $raw_destinations));
                $filtered_destinations = array_values(array_unique(array_intersect($requested_destinations, $available_destinations)));

                if (!empty($filtered_destinations)) {
                    $destinations = $filtered_destinations;
                } elseif (!empty($requested_destinations) && empty($available_destinations)) {
                    $destinations = [];
                }
            }
        }

        BJLG_Debug::log("Webhook déclenché avec succès. Planification d'une sauvegarde en arrière-plan.");

        // Enregistrer l'appel du webhook
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $mode_details = $legacy_mode ? 'legacy (clé dans URL)' : sprintf('secure (%s)', $key_source ?: 'unknown');
        BJLG_History::log('webhook_triggered', 'success', "Mode: $mode_details | IP: $ip | User-Agent: $user_agent");

        // Créer la tâche de sauvegarde
        $task_id = 'bjlg_backup_' . md5(uniqid('webhook', true));

        if (!BJLG_Backup::reserve_task_slot($task_id)) {
            BJLG_Debug::log("Impossible de planifier une sauvegarde via webhook : une tâche est déjà en cours.");
            BJLG_History::log('webhook_backup_conflict', 'failure', 'Une sauvegarde est déjà en cours lors de l\'appel webhook.');

            wp_send_json_error([
                'message' => __('Une sauvegarde est déjà en cours. Réessayez ultérieurement.', 'backup-jlg')
            ], 409);
            exit;
        }

        $task_data = [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => 'Initialisation (webhook)...',
            'components' => $components,
            'encrypt' => $encrypt,
            'incremental' => $incremental,
            'source' => 'webhook',
            'start_time' => time(),
            'destinations' => $destinations,
        ];

        if (!BJLG_Backup::save_task_state($task_id, $task_data)) {
            BJLG_Debug::log("Échec de l'initialisation de la tâche de sauvegarde webhook pour {$task_id}.");
            BJLG_History::log('webhook_backup_failed', 'failure', "Initialisation impossible pour la tâche {$task_id}.");
            BJLG_Backup::release_task_slot($task_id);

            wp_send_json_error([
                'message' => __('Impossible d\'initialiser la sauvegarde. Veuillez réessayer.', 'backup-jlg')
            ], 500);
            exit;
        }

        // Planifier l'exécution immédiate
        $event_timestamp = time();
        $event_args = ['task_id' => $task_id];
        $scheduled = wp_schedule_single_event($event_timestamp, 'bjlg_run_backup_task', $event_args);

        if ($scheduled === false) {
            BJLG_Debug::log("Échec de la planification de la tâche de sauvegarde webhook pour {$task_id}.");
            BJLG_History::log('webhook_backup_failed', 'failure', "Planification impossible pour la tâche {$task_id}.");

            BJLG_Backup::delete_task_state($task_id);
            BJLG_Backup::release_task_slot($task_id);

            wp_send_json_error([
                'message' => __('Impossible de planifier la tâche de sauvegarde en arrière-plan.', 'backup-jlg')
            ], 500);
            exit;
        }

        // Renvoyer une réponse de succès
        $response_data = [
            'message' => 'Backup job scheduled successfully.',
            'task_id' => $task_id,
            'components' => $components,
            'encrypt' => $encrypt,
            'incremental' => $incremental,
            'mode' => $legacy_mode ? 'legacy' : 'secure'
        ];

        if ($legacy_mode) {
            $response_data['deprecated'] = true;
        }

        wp_send_json_success($response_data);
        exit;
    }

    /**
     * Retourne la valeur d'un en-tête HTTP de manière sécurisée.
     *
     * @param string $name
     * @return string
     */
    private function get_request_header($name) {
        $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$server_key])) {
            return is_string($_SERVER[$server_key]) ? trim(wp_unslash($_SERVER[$server_key])) : '';
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $header_name => $value) {
                if (strcasecmp($header_name, $name) === 0) {
                    return is_string($value) ? trim($value) : '';
                }
            }
        }

        return '';
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