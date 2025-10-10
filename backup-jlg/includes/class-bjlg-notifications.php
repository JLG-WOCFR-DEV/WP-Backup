<?php
namespace BJLG;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gère l'envoi des notifications multi-canales configurées dans le plugin.
 */
class BJLG_Notifications {

    /** @var self|null */
    private static $instance = null;

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
            'remote_purge_failed' => true,
        ],
        'channels' => [
            'email' => ['enabled' => false],
            'slack' => ['enabled' => false, 'webhook_url' => ''],
            'discord' => ['enabled' => false, 'webhook_url' => ''],
        ],
    ];

    public static function instance() {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct() {
        if (self::$instance instanceof self) {
            return;
        }

        self::$instance = $this;
        $this->reload_settings();

        add_action('bjlg_settings_saved', [$this, 'handle_settings_saved']);
        add_action('bjlg_backup_complete', [$this, 'handle_backup_complete'], 15, 2);
        add_action('bjlg_backup_failed', [$this, 'handle_backup_failed'], 15, 2);
        add_action('bjlg_cleanup_complete', [$this, 'handle_cleanup_complete'], 15, 1);
        add_action('bjlg_storage_warning', [$this, 'handle_storage_warning'], 15, 1);
        add_action('bjlg_remote_purge_permanent_failure', [$this, 'handle_remote_purge_failed'], 15, 3);
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
     * Prépare le contexte d'un échec définitif de purge distante.
     *
     * @param string               $file
     * @param array<string,mixed>  $entry
     * @param array<string,string> $errors
     */
    public function handle_remote_purge_failed($file, $entry, $errors) {
        $entry = is_array($entry) ? $entry : [];

        $context = [
            'file' => basename((string) $file),
            'destinations' => $this->sanitize_components($entry['destinations'] ?? []),
            'attempts' => isset($entry['attempts']) ? (int) $entry['attempts'] : 0,
            'errors' => $this->sanitize_error_messages($errors),
            'last_error' => isset($entry['last_error']) ? trim((string) $entry['last_error']) : '',
        ];

        $this->notify('remote_purge_failed', $context);
    }

    /**
     * Enfile une notification de test en utilisant les réglages fournis ou stockés.
     *
     * @param array<string,mixed>|null $override_settings
     *
     * @return array{success:bool,entry:array<string,mixed>,channels:string[]}|WP_Error
     */
    public function send_test_notification($override_settings = null) {
        $settings = $override_settings !== null
            ? $this->merge_settings($override_settings)
            : $this->settings;

        if (empty($settings['enabled'])) {
            return new WP_Error('bjlg_notifications_disabled', __('Les notifications sont désactivées.', 'backup-jlg'));
        }

        $site_name = function_exists('get_bloginfo') ? get_bloginfo('name') : '';
        $site_url = function_exists('home_url') ? home_url('/') : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.home_url_home_url

        $user_label = '';
        if (function_exists('wp_get_current_user')) {
            $current_user = wp_get_current_user();
            if ($current_user && isset($current_user->display_name) && $current_user->display_name !== '') {
                $user_label = $current_user->display_name;
            } elseif ($current_user && isset($current_user->user_login) && $current_user->user_login !== '') {
                $user_label = $current_user->user_login;
            }
        }

        $context = [
            'test' => true,
            'site_name' => is_string($site_name) ? $site_name : '',
            'site_url' => is_string($site_url) ? $site_url : '',
            'initiator' => is_string($user_label) ? $user_label : '',
            'timestamp' => current_time('mysql'),
        ];

        $title = __('Notification de test', 'backup-jlg');
        $lines = [
            __('Ce message confirme que vos canaux reçoivent correctement les alertes Backup JLG.', 'backup-jlg'),
        ];

        if ($context['site_name'] !== '') {
            $lines[] = sprintf(__('Site : %s', 'backup-jlg'), $context['site_name']);
        }

        if ($context['site_url'] !== '') {
            $lines[] = sprintf(__('URL : %s', 'backup-jlg'), $context['site_url']);
        }

        if ($context['initiator'] !== '') {
            $lines[] = sprintf(__('Déclenché par : %s', 'backup-jlg'), $context['initiator']);
        }

        $lines[] = sprintf(__('Horodatage : %s', 'backup-jlg'), $context['timestamp']);

        $lines = array_filter(array_map('trim', $lines));
        $lines = apply_filters('bjlg_notification_message_lines', $lines, 'test_notification', $context);

        $payload = [
            'event' => 'test_notification',
            'title' => $title,
            'lines' => $lines,
            'context' => $context,
        ];

        $payload = apply_filters('bjlg_notification_payload', $payload, 'test_notification', $context);

        if (!is_array($payload) || empty($payload['title']) || empty($payload['lines']) || !is_array($payload['lines'])) {
            return new WP_Error('bjlg_notification_payload_invalid', __('Impossible de préparer la notification de test.', 'backup-jlg'));
        }

        $title = (string) $payload['title'];
        $lines = array_map('strval', $payload['lines']);
        $channels = $this->prepare_channels_payload($title, $lines, $settings);

        if (empty($channels)) {
            return new WP_Error('bjlg_notifications_no_channels', __('Aucun canal de notification actif n’est disponible.', 'backup-jlg'));
        }

        $entry = [
            'id' => uniqid('bjlg_notif_test_', true),
            'event' => 'test_notification',
            'title' => $title,
            'subject' => '[Backup JLG] ' . $title,
            'lines' => $lines,
            'body' => implode("\n", $lines),
            'context' => is_array($payload['context']) ? $payload['context'] : $context,
            'channels' => $channels,
            'created_at' => time(),
        ];

        BJLG_Notification_Queue::enqueue($entry);

        return [
            'success' => true,
            'entry' => $entry,
            'channels' => array_keys($channels),
        ];
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
    private function is_event_enabled($event, ?array $settings = null) {
        $settings = $settings ?? $this->settings;

        if (empty($settings['enabled'])) {
            return false;
        }

        return !empty($settings['events'][$event]);
    }

    /**
     * Retourne vrai si le canal spécifié est activé.
     */
    private function is_channel_enabled($channel, ?array $settings = null) {
        $settings = $settings ?? $this->settings;

        return !empty($settings['channels'][$channel]['enabled']);
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
            case 'remote_purge_failed':
                return __('Purge distante en échec', 'backup-jlg');
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
            case 'remote_purge_failed':
                $lines[] = __('La purge distante a atteint la limite de tentatives.', 'backup-jlg');
                if (!empty($context['file'])) {
                    $lines[] = __('Archive : ', 'backup-jlg') . $context['file'];
                }
                if (!empty($context['destinations'])) {
                    $lines[] = __('Destinations restantes : ', 'backup-jlg') . implode(', ', $context['destinations']);
                }
                if (!empty($context['attempts'])) {
                    $lines[] = __('Tentatives : ', 'backup-jlg') . (int) $context['attempts'];
                }
                if (!empty($context['errors'])) {
                    foreach ($context['errors'] as $error_line) {
                        $lines[] = __('Erreur : ', 'backup-jlg') . $error_line;
                    }
                } elseif (!empty($context['last_error'])) {
                    $lines[] = __('Dernier message : ', 'backup-jlg') . $context['last_error'];
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
    private function prepare_channels_payload($title, $lines, ?array $settings = null) {
        $settings = $settings ?? $this->settings;
        $channels = [];

        if ($this->is_channel_enabled('email', $settings)) {
            $recipients = BJLG_Notification_Transport::normalize_email_recipients($settings['email_recipients']);
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

        if ($this->is_channel_enabled('slack', $settings)) {
            $url = $settings['channels']['slack']['webhook_url'] ?? '';
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

        if ($this->is_channel_enabled('discord', $settings)) {
            $url = $settings['channels']['discord']['webhook_url'] ?? '';
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

    /**
     * Nettoie et normalise les messages d'erreur distants.
     *
     * @param mixed $errors
     *
     * @return string[]
     */
    private function sanitize_error_messages($errors) {
        if (!is_array($errors)) {
            return [];
        }

        $messages = [];
        foreach ($errors as $error) {
            if (!is_string($error)) {
                continue;
            }

            $message = sanitize_text_field($error);
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return array_values(array_unique($messages));
    }

}
