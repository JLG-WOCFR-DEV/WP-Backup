<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gère la table d'historique (audit trail) pour toutes les actions du plugin.
 */
class BJLG_History {

    /**
     * Crée la table de base de données personnalisée à l'activation du plugin.
     * Utilise dbDelta pour être sûre et non destructive.
     *
     * @param int|null $blog_id Identifiant du site cible (multisite) ou null pour le site courant.
     */
    public static function create_table($blog_id = null) {
        global $wpdb;
        $table_name = self::get_table_name($blog_id);
        $charset_collate = method_exists($wpdb, 'get_charset_collate')
            ? $wpdb->get_charset_collate()
            : 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            action_type varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            details text NOT NULL,
            metadata longtext NULL,
            user_id bigint(20) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY action_type (action_type),
            KEY status (status),
            KEY timestamp (timestamp),
            KEY user_id (user_id)
        ) $charset_collate;";

        // Inclut le fichier nécessaire pour la fonction dbDelta
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Vérifier si la table a été créée
        $table_exists = method_exists($wpdb, 'get_var')
            ? $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name
            : false;
        
        if ($table_exists) {
            BJLG_Debug::log("Table d'historique créée ou mise à jour avec succès.");
        } else {
            BJLG_Debug::log("ERREUR : Impossible de créer la table d'historique.");
        }
    }
    
    /**
     * Enregistre une nouvelle action dans la table d'historique.
     *
     * @param string $action Le type d'action (ex: 'backup_created', 'restore_run').
     * @param string $status Le statut de l'action ('success', 'failure', 'info').
     * @param string $details Détails supplémentaires (nom de fichier, message d'erreur, etc.).
     * @param int|null $user_id ID de l'utilisateur (null pour utilisateur actuel).
     * @param int|null $blog_id Identifiant du site cible pour enregistrer l'entrée.
     * @param array<string,mixed> $metadata Métadonnées structurées associées à l'action.
     *
     * @return int|null Identifiant de l'entrée insérée ou null si indisponible.
     */
    public static function log($action, $status, $details = '', $user_id = null, $blog_id = null, array $metadata = []) {
        global $wpdb;
        $table_name = self::get_table_name($blog_id);

        // Obtenir l'ID de l'utilisateur actuel si non spécifié
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if (!is_numeric($user_id) || (int) $user_id === 0) {
            $user_id = null;
        } else {
            $user_id = (int) $user_id;
        }

        // Obtenir l'adresse IP
        $ip_address = self::get_client_ip();

        $data = [
            'timestamp'   => current_time('mysql'),
            'action_type' => $action,
            'status'      => $status,
            'details'     => $details,
        ];

        $formats = ['%s', '%s', '%s', '%s'];

        $encoded_metadata = self::encode_metadata($metadata);
        if ($encoded_metadata !== null) {
            $data['metadata'] = $encoded_metadata;
            $formats[] = '%s';
        }

        if ($user_id !== null) {
            $data['user_id'] = $user_id;
            $formats[] = '%d';
        }

        $data['ip_address'] = $ip_address;
        $formats[] = '%s';

        $insert_id = null;

        if (self::wpdb_supports(['insert'])) {
            $result = $wpdb->insert(
                $table_name,
                $data,
                $formats
            );

            if ($result === false) {
                $error_message = property_exists($wpdb, 'last_error')
                    ? (string) $wpdb->last_error
                    : 'unknown';

                BJLG_Debug::log("ERREUR lors de l'enregistrement dans l'historique : " . $error_message);
            } else {
                $insert_id = property_exists($wpdb, 'insert_id')
                    ? (int) $wpdb->insert_id
                    : null;
            }
        } else {
            BJLG_Debug::log('Historique non persistant : insert() indisponible sur $wpdb.');
        }

        // Déclencher une action pour permettre des extensions
        do_action('bjlg_history_logged', $action, $status, $details, $user_id, $blog_id);

        return $insert_id;
    }

    /**
     * Récupère les dernières entrées de l'historique pour affichage.
     *
     * @param int $limit Le nombre d'entrées à récupérer.
     * @param array $filters Filtres optionnels (action_type, status, date_from, date_to).
     * @param int|null $blog_id Identifiant du site cible lorsque l'on interroge un autre blog.
     *
     * @return array
     */
    public static function get_history($limit = 50, $filters = [], $blog_id = null) {
        global $wpdb;
        $table_name = self::get_table_name($blog_id);
        
        $where_clauses = ['1=1'];
        $values = [];
        
        // Appliquer les filtres
        if (!empty($filters['action_type'])) {
            $where_clauses[] = 'action_type = %s';
            $values[] = $filters['action_type'];
        }
        
        if (!empty($filters['status'])) {
            $where_clauses[] = 'status = %s';
            $values[] = $filters['status'];
        }
        
        if (!empty($filters['user_id'])) {
            $where_clauses[] = 'user_id = %d';
            $values[] = intval($filters['user_id']);
        }
        
        if (!empty($filters['date_from'])) {
            $where_clauses[] = 'timestamp >= %s';
            $values[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where_clauses[] = 'timestamp <= %s';
            $values[] = $filters['date_to'];
        }
        
        // Ajouter la limite
        $values[] = intval($limit);
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Préparer la requête
        $query = "SELECT * FROM $table_name WHERE $where_sql ORDER BY timestamp DESC LIMIT %d";
        
        if (!empty($values) && self::wpdb_supports(['prepare'])) {
            $query = $wpdb->prepare($query, ...$values);
        }

        if (!self::wpdb_supports(['get_results'])) {
            return [];
        }

        $results = $wpdb->get_results($query, ARRAY_A);

        foreach ($results as &$entry) {
            $entry['metadata'] = self::decode_metadata($entry['metadata'] ?? null);
        }

        // Enrichir les résultats avec les noms d'utilisateur
        foreach ($results as &$entry) {
            if (!empty($entry['user_id'])) {
                $user = get_user_by('id', $entry['user_id']);
                $entry['user_name'] = $user ? $user->display_name : 'Utilisateur supprimé';
            } else {
                $entry['user_name'] = 'Système';
            }

            if (
                isset($entry['action_type'])
                && $entry['action_type'] === 'sandbox_restore_validation'
                && isset($entry['metadata'])
                && is_array($entry['metadata'])
            ) {
                $entry['metadata_summary'] = self::format_restore_validation_metadata($entry['metadata']);
            }
        }

        return $results;
    }
    
    /**
     * Obtient des statistiques sur l'historique
     *
     * @param string   $period  Période d'analyse.
     * @param int|null $blog_id Identifiant du site cible.
     */
    public static function get_stats($period = 'week', $blog_id = null) {
        global $wpdb;
        $table_name = self::get_table_name($blog_id);
        
        $date_limit = date('Y-m-d H:i:s', strtotime('-1 ' . $period));
        
        $stats = [
            'total_actions' => 0,
            'successful' => 0,
            'failed' => 0,
            'info' => 0,
            'by_action' => [],
            'by_user' => [],
            'most_active_hour' => null
        ];
        
        // Total des actions
        if (self::wpdb_supports(['prepare', 'get_var'])) {
            $stats['total_actions'] = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE timestamp > %s",
                    $date_limit
                )
            );
        }
        
        // Par statut
        $status_counts = [];

        if (self::wpdb_supports(['prepare', 'get_results'])) {
            $status_counts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT status, COUNT(*) as count
                     FROM $table_name
                     WHERE timestamp > %s
                     GROUP BY status",
                    $date_limit
                ),
                ARRAY_A
            );
        }
        
        foreach ($status_counts as $row) {
            $stats[$row['status']] = intval($row['count']);
        }
        
        // Par type d'action
        $action_counts = [];

        if (self::wpdb_supports(['prepare', 'get_results'])) {
            $action_counts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT action_type, COUNT(*) as count
                     FROM $table_name
                     WHERE timestamp > %s
                     GROUP BY action_type
                     ORDER BY count DESC
                     LIMIT 10",
                    $date_limit
                ),
                ARRAY_A
            );
        }
        
        foreach ($action_counts as $row) {
            $stats['by_action'][$row['action_type']] = intval($row['count']);
        }
        
        // Par utilisateur
        $user_counts = [];

        if (self::wpdb_supports(['prepare', 'get_results'])) {
            $user_counts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT user_id, COUNT(*) as count
                     FROM $table_name
                     WHERE timestamp > %s AND user_id IS NOT NULL
                     GROUP BY user_id
                     ORDER BY count DESC
                     LIMIT 5",
                    $date_limit
                ),
                ARRAY_A
            );
        }
        
        foreach ($user_counts as $row) {
            $user = get_user_by('id', $row['user_id']);
            $user_name = $user ? $user->display_name : 'Utilisateur #' . $row['user_id'];
            $stats['by_user'][$user_name] = intval($row['count']);
        }
        
        // Heure la plus active
        $hour_stats = null;

        if (self::wpdb_supports(['prepare', 'get_row'])) {
            $hour_stats = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT HOUR(timestamp) as hour, COUNT(*) as count
                     FROM $table_name
                     WHERE timestamp > %s
                     GROUP BY HOUR(timestamp)
                     ORDER BY count DESC
                     LIMIT 1",
                    $date_limit
                ),
                ARRAY_A
            );
        }
        
        if ($hour_stats) {
            $stats['most_active_hour'] = sprintf('%02d:00', $hour_stats['hour']);
        }
        
        return $stats;
    }
    
    /**
     * Recherche dans l'historique
     *
     * @param string   $search_term Terme recherché.
     * @param int      $limit       Nombre maximum de résultats.
     * @param int|null $blog_id     Identifiant du site cible.
     */
    public static function search($search_term, $limit = 50, $blog_id = null) {
        global $wpdb;
        $table_name = self::get_table_name($blog_id);

        if (method_exists($wpdb, 'esc_like')) {
            $search_term = '%' . $wpdb->esc_like($search_term) . '%';
        } else {
            $search_term = '%' . str_replace('%', '%%', $search_term) . '%';
        }

        if (!self::wpdb_supports(['prepare', 'get_results'])) {
            return [];
        }

        $query = $wpdb->prepare(
            "SELECT * FROM $table_name
             WHERE action_type LIKE %s
                OR details LIKE %s
             ORDER BY timestamp DESC
             LIMIT %d",
            $search_term,
            $search_term,
            $limit
        );

        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Exporte l'historique en CSV
     */
    public static function export_csv($filters = [], $blog_id = null) {
        $history = self::get_history(9999, $filters, $blog_id);
        
        $csv_data = [];
        $csv_data[] = ['Date', 'Action', 'Statut', 'Détails', 'Utilisateur', 'IP'];
        
        foreach ($history as $entry) {
            $csv_data[] = [
                $entry['timestamp'],
                $entry['action_type'],
                $entry['status'],
                $entry['details'],
                $entry['user_name'] ?? '',
                $entry['ip_address'] ?? ''
            ];
        }
        
        return $csv_data;
    }
    
    /**
     * Nettoie les anciennes entrées
     */
    /**
     * Nettoie les anciennes entrées
     *
     * @param int      $days_to_keep Nombre de jours conservés.
     * @param int|null $blog_id      Identifiant du site cible.
     */
    public static function cleanup($days_to_keep = 30, $blog_id = null) {
        global $wpdb;
        $table_name = self::get_table_name($blog_id);

        $cutoff_date = date('Y-m-d H:i:s', strtotime('-' . $days_to_keep . ' days'));

        if (!self::wpdb_supports(['prepare', 'query'])) {
            return 0;
        }

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE timestamp < %s",
                $cutoff_date
            )
        );
        
        if ($deleted > 0) {
            BJLG_Debug::log("Historique nettoyé : $deleted entrées supprimées (plus de $days_to_keep jours).");
        }

        return $deleted;
    }

    /**
     * Retourne le nom complet de la table d'historique selon le préfixe actif.
     */
    private static function get_table_name($blog_id = null) {
        global $wpdb;

        $table_suffix = 'bjlg_history';

        if (!is_object($wpdb)) {
            return 'wp_' . $table_suffix;
        }

        $is_multisite = function_exists('is_multisite') && is_multisite();
        $desired_scope = BJLG_Site_Context::HISTORY_SCOPE_SITE;
        $resolved_blog_id = null;

        if ($blog_id === 0) {
            $desired_scope = BJLG_Site_Context::HISTORY_SCOPE_NETWORK;
        } elseif ($blog_id !== null) {
            $resolved_blog_id = max(0, (int) $blog_id);
        } elseif (BJLG_Site_Context::history_uses_network_storage() && BJLG_Site_Context::is_network_context()) {
            $desired_scope = BJLG_Site_Context::HISTORY_SCOPE_NETWORK;
        } elseif (function_exists('get_current_blog_id')) {
            $resolved_blog_id = max(0, (int) get_current_blog_id());
        }

        if ($desired_scope === BJLG_Site_Context::HISTORY_SCOPE_NETWORK && $is_multisite) {
            return BJLG_Site_Context::get_table_prefix(BJLG_Site_Context::HISTORY_SCOPE_NETWORK) . 'bjlg_history_network';
        }

        return BJLG_Site_Context::get_table_prefix(BJLG_Site_Context::HISTORY_SCOPE_SITE, $resolved_blog_id) . $table_suffix;
    }

    /**
     * Vérifie si $wpdb propose toutes les méthodes nécessaires.
     *
     * @param array<int, string> $methods
     */
    private static function wpdb_supports(array $methods) {
        global $wpdb;

        if (!is_object($wpdb)) {
            return false;
        }

        foreach ($methods as $method) {
            if (!method_exists($wpdb, $method)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Obtient l'adresse IP du client en respectant la configuration des proxies de confiance.
     */
    private static function get_client_ip() {
        return BJLG_Client_IP_Helper::get_client_ip([
            'bjlg_history_trusted_proxy_headers',
            'bjlg_rate_limiter_trusted_proxy_headers',
        ]);
    }
    
    /**
     * Obtient les types d'actions disponibles
     */
    public static function get_action_types() {
        return [
            'backup_created' => 'Sauvegarde créée',
            'backup_deleted' => 'Sauvegarde supprimée',
            'restore_run' => 'Restauration exécutée',
            'pre_restore_backup' => 'Sauvegarde pré-restauration',
            'scheduled_backup' => 'Sauvegarde planifiée',
            'cleanup_task_started' => 'Nettoyage démarré',
            'cleanup_task_finished' => 'Nettoyage terminé',
            'settings_updated' => 'Réglages mis à jour',
            'webhook_triggered' => 'Webhook déclenché',
            'api_key_created' => 'Clé API créée',
            'encryption_key_generated' => 'Clé de chiffrement générée',
            'support_package' => 'Pack de support créé',
            'event_trigger' => 'Sauvegarde événementielle',
            'event_trigger_settings' => 'Réglages des déclencheurs événementiels'
        ];
    }

    /**
     * Retourne la dernière entrée correspondant à une action donnée.
     *
     * @param string      $action
     * @param string|null $status
     * @param int|null    $blog_id
     * @return array<string,mixed>|null
     */
    public static function get_last_event_metadata($action, $status = null, $blog_id = null) {
        global $wpdb;

        if (!self::wpdb_supports(['prepare', 'get_row'])) {
            return null;
        }

        $table_name = self::get_table_name($blog_id);
        $where = ['action_type = %s'];
        $params = [$action];

        if ($status !== null) {
            $where[] = 'status = %s';
            $params[] = $status;
        }

        $sql = "SELECT * FROM $table_name WHERE " . implode(' AND ', $where) . " ORDER BY timestamp DESC, id DESC LIMIT 1";
        $query = $wpdb->prepare($sql, ...$params);
        $row = $wpdb->get_row($query, ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        $row['metadata'] = self::decode_metadata($row['metadata'] ?? null);

        return $row;
    }

    /**
     * Sérialise les métadonnées pour la base de données.
     *
     * @param array<string,mixed> $metadata
     * @return string|null
     */
    private static function encode_metadata(array $metadata) {
        if (empty($metadata)) {
            return null;
        }

        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($metadata)
            : json_encode($metadata);

        if (!is_string($encoded) || $encoded === '') {
            return null;
        }

        return $encoded;
    }

    /**
     * Désérialise une colonne de métadonnées.
     *
     * @param mixed $metadata
     * @return array<string,mixed>
     */
    private static function decode_metadata($metadata) {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (!is_string($metadata) || $metadata === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private static function format_restore_validation_metadata(array $metadata): string
    {
        if (empty($metadata['report']) || !is_array($metadata['report'])) {
            return '';
        }

        $report = $metadata['report'];
        $parts = [];

        if (!empty($report['backup_file']) && is_string($report['backup_file'])) {
            $parts[] = sprintf(__('Archive : %s', 'backup-jlg'), basename($report['backup_file']));
        }

        if (!empty($report['objectives']['rto_human']) && is_string($report['objectives']['rto_human'])) {
            $parts[] = sprintf(__('RTO ≈ %s', 'backup-jlg'), $report['objectives']['rto_human']);
        }

        if (!empty($report['objectives']['rpo_human']) && is_string($report['objectives']['rpo_human'])) {
            $parts[] = sprintf(__('RPO ≈ %s', 'backup-jlg'), $report['objectives']['rpo_human']);
        }

        if (!empty($report['health']['summary']) && is_string($report['health']['summary'])) {
            $parts[] = sprintf(__('Santé : %s', 'backup-jlg'), $report['health']['summary']);
        }

        if (!empty($report['issues']) && is_array($report['issues'])) {
            $error_count = 0;
            $warning_count = 0;

            foreach ($report['issues'] as $issue) {
                if (!is_array($issue)) {
                    continue;
                }

                $type = isset($issue['type']) ? (string) $issue['type'] : '';

                if (in_array($type, ['error', 'failure', 'exception', 'cleanup'], true)) {
                    $error_count++;
                } elseif ($type === 'warning') {
                    $warning_count++;
                }
            }

            if ($error_count > 0) {
                $parts[] = sprintf(
                    _n('%s incident critique', '%s incidents critiques', $error_count, 'backup-jlg'),
                    number_format_i18n($error_count)
                );
            }

            if ($warning_count > 0) {
                $parts[] = sprintf(
                    _n('%s avertissement', '%s avertissements', $warning_count, 'backup-jlg'),
                    number_format_i18n($warning_count)
                );
            }
        }

        return implode(' | ', array_filter($parts));
    }
}
