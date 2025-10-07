<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gère l'envoi des notifications multi-canales configurées dans le plugin.
 */
class BJLG_Notifications {

    /** @var array<string,mixed> */
    private $settings = [];

    /** @var array<string,mixed> */
    private const DEFAULTS = [
        'enabled' => false,
        'email_recipients' => '',
        'events' => [
            'backup_complete' => true,
            'backup_failed' => true,
            'cleanup_complete' => false,
            'storage_warning' => true,
        ],
        'channels' => [
            'email' => ['enabled' => false],
            'slack' => ['enabled' => false, 'webhook_url' => ''],
            'discord' => ['enabled' => false, 'webhook_url' => ''],
        ],
    ];

    public function __construct() {
        $this->reload_settings();

        add_action('bjlg_settings_saved', [$this, 'handle_settings_saved']);
        add_action('bjlg_backup_complete', [$this, 'handle_backup_complete'], 15, 2);
        add_action('bjlg_backup_failed', [$this, 'handle_backup_failed'], 15, 2);
        add_action('bjlg_cleanup_complete', [$this, 'handle_cleanup_complete'], 15, 1);
        add_action('bjlg_storage_warning', [$this, 'handle_storage_warning'], 15, 1);
    }

    /**
     * Recharge les réglages après une sauvegarde.
     *
     * @param array<string,mixed> $settings
     */
    public function handle_settings_saved($settings) {
        if (isset($settings['notifications'])) {
            $this->settings = $this->merge_settings($settings['notifications']);
        } else {
            $this->reload_settings();
        }
    }

    /**
     * Prépare le contexte d'une sauvegarde réussie.
     *
     * @param string              $filename
     * @param array<string,mixed> $details
     */
    public function handle_backup_complete($filename, $details) {
        $details = is_array($details) ? $details : [];

        $context = [
            'filename' => (string) $filename,
            'size' => isset($details['size']) ? (int) $details['size'] : null,
            'components' => $this->sanitize_components($details['components'] ?? []),
            'encrypted' => !empty($details['encrypted']),
            'incremental' => !empty($details['incremental']),
            'duration' => isset($details['duration']) ? (float) $details['duration'] : null,
        ];

        $this->notify('backup_complete', $context);
    }

    /**
     * Prépare le contexte d'une sauvegarde en échec.
     *
     * @param string              $error
     * @param array<string,mixed> $details
     */
    public function handle_backup_failed($error, $details) {
        $details = is_array($details) ? $details : [];

        $context = [
            'error' => trim((string) $error),
            'components' => $this->sanitize_components($details['components'] ?? []),
            'task_id' => isset($details['task_id']) ? (string) $details['task_id'] : '',
        ];

        $this->notify('backup_failed', $context);
    }

    /**
     * Prépare le contexte d'un nettoyage terminé.
     *
     * @param array<string,int> $stats
     */
    public function handle_cleanup_complete($stats) {
        $stats = is_array($stats) ? $stats : [];

        $context = [
            'backups_deleted' => isset($stats['backups_deleted']) ? (int) $stats['backups_deleted'] : 0,
            'remote_backups_deleted' => isset($stats['remote_backups_deleted']) ? (int) $stats['remote_backups_deleted'] : 0,
            'temp_files_deleted' => isset($stats['temp_files_deleted']) ? (int) $stats['temp_files_deleted'] : 0,
            'history_entries_deleted' => isset($stats['history_entries_deleted']) ? (int) $stats['history_entries_deleted'] : 0,
        ];

        $this->notify('cleanup_complete', $context);
    }

    /**
     * Prépare le contexte d'une alerte de stockage.
     *
     * @param array<string,mixed> $data
     */
    public function handle_storage_warning($data) {
        $data = is_array($data) ? $data : [];

        $context = [
            'free_space' => isset($data['free_space']) ? (int) $data['free_space'] : null,
            'threshold' => isset($data['threshold']) ? (int) $data['threshold'] : null,
            'path' => isset($data['path']) ? (string) $data['path'] : '',
        ];

        $this->notify('storage_warning', $context);
    }

    /**
     * Recharge les réglages depuis la base de données.
     */
    private function reload_settings() {
        $stored = get_option('bjlg_notification_settings', []);
        $this->settings = $this->merge_settings($stored);
    }

    /**
     * Fusionne les réglages sauvegardés avec les valeurs par défaut.
     *
     * @param array<string,mixed> $settings
     *
     * @return array<string,mixed>
     */
    private function merge_settings($settings) {
        if (!is_array($settings)) {
            $settings = [];
        }

        $merged = wp_parse_args($settings, self::DEFAULTS);
        $merged['events'] = isset($merged['events']) && is_array($merged['events'])
            ? wp_parse_args($merged['events'], self::DEFAULTS['events'])
            : self::DEFAULTS['events'];
        $merged['channels'] = isset($merged['channels']) && is_array($merged['channels'])
            ? wp_parse_args($merged['channels'], self::DEFAULTS['channels'])
            : self::DEFAULTS['channels'];

        return $merged;
    }

    /**
     * Vérifie si un événement doit générer une notification.
     */
    private function is_event_enabled($event) {
        if (empty($this->settings['enabled'])) {
            return false;
        }

        return !empty($this->settings['events'][$event]);
    }

    /**
     * Retourne vrai si le canal spécifié est activé.
     */
    private function is_channel_enabled($channel) {
        return !empty($this->settings['channels'][$channel]['enabled']);
    }

    /**
     * Envoie la notification sur les canaux configurés.
     *
     * @param string              $event
     * @param array<string,mixed> $context
     */
    private function notify($event, $context) {
        if (!$this->is_event_enabled($event)) {
            return;
        }

        $title = $this->get_event_title($event, $context);
        $lines = $this->get_event_lines($event, $context);
        $lines = apply_filters('bjlg_notification_message_lines', $lines, $event, $context);

        $payload = [
            'event' => $event,
            'title' => $title,
            'lines' => $lines,
            'context' => $context,
        ];

        /**
         * Permet de modifier le contenu de la notification avant envoi.
         */
        $payload = apply_filters('bjlg_notification_payload', $payload, $event, $context);

        if (!is_array($payload) || empty($payload['title']) || empty($payload['lines']) || !is_array($payload['lines'])) {
            BJLG_Debug::log('Notification ignorée car le payload est invalide.');
            return;
        }

        $subject = '[Backup JLG] ' . $payload['title'];
        $body = implode("\n", $payload['lines']);

        $channels = $this->prepare_channels_payload($payload['title'], $payload['lines']);

        if (empty($channels)) {
            BJLG_Debug::log('Notification ignorée car aucun canal valide n\'est disponible.');
            return;
        }

        $entry = [
            'event' => $event,
            'title' => $payload['title'],
            'subject' => $subject,
            'lines' => $payload['lines'],
            'body' => $body,
            'context' => $payload['context'],
            'channels' => $channels,
            'created_at' => time(),
        ];

        BJLG_Notification_Queue::enqueue($entry);

        BJLG_Debug::log(sprintf('Notification "%s" mise en file d\'attente.', $event));
    }

    /**
     * Retourne le titre humain de l'événement.
     */
    private function get_event_title($event, $context) {
        switch ($event) {
            case 'backup_complete':
                return __('Sauvegarde terminée', 'backup-jlg');
            case 'backup_failed':
                return __('Échec de sauvegarde', 'backup-jlg');
            case 'cleanup_complete':
                return __('Nettoyage terminé', 'backup-jlg');
            case 'storage_warning':
                return __('Alerte de stockage', 'backup-jlg');
            default:
                return ucfirst(str_replace('_', ' ', $event));
        }
    }

    /**
     * Construit les lignes du message selon l'événement.
     *
     * @param string              $event
     * @param array<string,mixed> $context
     *
     * @return string[]
     */
    private function get_event_lines($event, $context) {
        $lines = [];
        $timestamp = current_time('mysql');

        switch ($event) {
            case 'backup_complete':
                $lines[] = __('Une sauvegarde vient de se terminer avec succès.', 'backup-jlg');
                if (!empty($context['filename'])) {
                    $lines[] = __('Fichier : ', 'backup-jlg') . $context['filename'];
                }
                if (isset($context['size'])) {
                    $lines[] = __('Taille : ', 'backup-jlg') . size_format((int) $context['size']);
                }
                if (!empty($context['components'])) {
                    $lines[] = __('Composants : ', 'backup-jlg') . implode(', ', $context['components']);
                }
                $lines[] = __('Chiffrée : ', 'backup-jlg') . ($context['encrypted'] ? __('Oui', 'backup-jlg') : __('Non', 'backup-jlg'));
                $lines[] = __('Incrémentale : ', 'backup-jlg') . ($context['incremental'] ? __('Oui', 'backup-jlg') : __('Non', 'backup-jlg'));
                if (!empty($context['duration'])) {
                    $lines[] = __('Durée : ', 'backup-jlg') . number_format_i18n((float) $context['duration'], 2) . ' s';
                }
                break;
            case 'backup_failed':
                $lines[] = __('Une sauvegarde a échoué.', 'backup-jlg');
                if (!empty($context['error'])) {
                    $lines[] = __('Erreur : ', 'backup-jlg') . $context['error'];
                }
                if (!empty($context['components'])) {
                    $lines[] = __('Composants : ', 'backup-jlg') . implode(', ', $context['components']);
                }
                if (!empty($context['task_id'])) {
                    $lines[] = __('Tâche : ', 'backup-jlg') . $context['task_id'];
                }
                break;
            case 'cleanup_complete':
                $lines[] = __("La tâche de nettoyage s'est terminée.", 'backup-jlg');
                $lines[] = __('Sauvegardes supprimées : ', 'backup-jlg') . (int) $context['backups_deleted'];
                $lines[] = __('Sauvegardes distantes supprimées : ', 'backup-jlg') . (int) $context['remote_backups_deleted'];
                $lines[] = __('Fichiers temporaires supprimés : ', 'backup-jlg') . (int) $context['temp_files_deleted'];
                $lines[] = __("Entrées d'historique supprimées : ", 'backup-jlg') . (int) $context['history_entries_deleted'];
                break;
            case 'storage_warning':
                $lines[] = __("L'espace disque disponible devient critique.", 'backup-jlg');
                if (!empty($context['path'])) {
                    $lines[] = __('Chemin surveillé : ', 'backup-jlg') . $context['path'];
                }
                if (isset($context['free_space'])) {
                    $lines[] = __('Espace libre : ', 'backup-jlg') . size_format((int) $context['free_space']);
                }
                if (isset($context['threshold'])) {
                    $lines[] = __('Seuil configuré : ', 'backup-jlg') . size_format((int) $context['threshold']);
                }
                break;
            default:
                foreach ($context as $key => $value) {
                    if (is_scalar($value)) {
                        $lines[] = ucfirst($key) . ' : ' . $value;
                    }
                }
                break;
        }

        $lines[] = __('Horodatage : ', 'backup-jlg') . $timestamp;

        return array_filter(array_map('trim', $lines));
    }

    /**
     * Prépare les canaux à utiliser pour une notification donnée.
     *
     * @param string   $title
     * @param string[] $lines
     *
     * @return array<string,array<string,mixed>>
     */
    private function prepare_channels_payload($title, $lines) {
        $channels = [];

        if ($this->is_channel_enabled('email')) {
            $recipients = BJLG_Notification_Transport::normalize_email_recipients($this->settings['email_recipients']);
            if (!empty($recipients)) {
                $channels['email'] = [
                    'enabled' => true,
                    'recipients' => $recipients,
                    'status' => 'pending',
                    'attempts' => 0,
                ];
            } else {
                BJLG_Debug::log('Canal email ignoré : aucun destinataire valide.');
            }
        }

        if ($this->is_channel_enabled('slack')) {
            $url = $this->settings['channels']['slack']['webhook_url'] ?? '';
            if (BJLG_Notification_Transport::is_valid_url($url)) {
                $channels['slack'] = [
                    'enabled' => true,
                    'webhook_url' => $url,
                    'status' => 'pending',
                    'attempts' => 0,
                ];
            } else {
                BJLG_Debug::log('Canal Slack ignoré : URL invalide.');
            }
        }

        if ($this->is_channel_enabled('discord')) {
            $url = $this->settings['channels']['discord']['webhook_url'] ?? '';
            if (BJLG_Notification_Transport::is_valid_url($url)) {
                $channels['discord'] = [
                    'enabled' => true,
                    'webhook_url' => $url,
                    'status' => 'pending',
                    'attempts' => 0,
                ];
            } else {
                BJLG_Debug::log('Canal Discord ignoré : URL invalide.');
            }
        }

        /**
         * Permet d'ajouter ou de modifier les canaux en file d'attente.
         */
        $channels = apply_filters('bjlg_notification_channels_payload', $channels, $title, $lines, $this->settings);

        if (!is_array($channels)) {
            return [];
        }

        return array_filter($channels, static function ($channel) {
            return is_array($channel) && !empty($channel['enabled']);
        });
    }

    /**
     * Nettoie la liste des composants envoyés dans les événements.
     *
     * @param mixed $components
     *
     * @return string[]
     */
    private function sanitize_components($components) {
        if (!is_array($components)) {
            return [];
        }

        $sanitized = [];
        foreach ($components as $component) {
            if (is_string($component) && $component !== '') {
                $sanitized[] = sanitize_text_field($component);
            }
        }

        return array_values(array_unique($sanitized));
    }

}
