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

    /**
     * Valeurs par défaut utilisées lorsque la configuration n'est pas encore initialisée.
     *
     * @var array<string,mixed>
     */
    private const DEFAULTS = [
        'enabled' => false,
        'email_recipients' => '',
        'events' => [
            'backup_complete' => true,
            'backup_failed' => true,
            'cleanup_complete' => false,
            'storage_warning' => true,
            'remote_purge_failed' => true,
            'remote_purge_delayed' => true,
            'restore_self_test_passed' => false,
            'restore_self_test_failed' => true,
        ],
        'channels' => [
            'email' => ['enabled' => false],
            'slack' => ['enabled' => false, 'webhook_url' => ''],
            'discord' => ['enabled' => false, 'webhook_url' => ''],
            'teams' => ['enabled' => false, 'webhook_url' => ''],
            'sms' => ['enabled' => false, 'webhook_url' => ''],
        ],
        'quiet_hours' => [
            'enabled' => false,
            'start' => '22:00',
            'end' => '07:00',
            'allow_critical' => true,
            'timezone' => '',
        ],
        'escalation' => [
            'enabled' => false,
            'delay_minutes' => 15,
            'only_critical' => true,
            'channels' => [
                'email' => false,
                'slack' => false,
                'discord' => false,
                'teams' => false,
                'sms' => true,
            ],
            'mode' => 'simple',
            'stages' => [
                'slack' => [
                    'enabled' => false,
                    'delay_minutes' => 15,
                ],
                'discord' => [
                    'enabled' => false,
                    'delay_minutes' => 15,
                ],
                'teams' => [
                    'enabled' => false,
                    'delay_minutes' => 15,
                ],
                'sms' => [
                    'enabled' => false,
                    'delay_minutes' => 30,
                ],
            ],
        ],
        'templates' => [],
    ];

    /**
     * Retourne les modèles par défaut utilisés pour chaque gravité.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function default_severity_templates() {
        return [
            'info' => [
                'label' => __('Information', 'backup-jlg'),
                'intro' => __('Mise à jour de routine pour votre visibilité.', 'backup-jlg'),
                'outro' => __('Aucune action immédiate n’est requise.', 'backup-jlg'),
                'intent' => 'info',
                'actions' => [
                    __('Ajoutez un commentaire dans l’historique si une vérification manuelle a été effectuée.', 'backup-jlg'),
                ],
                'resolution' => __('Archivez l’événement une fois les vérifications terminées.', 'backup-jlg'),
            ],
            'warning' => [
                'label' => __('Avertissement', 'backup-jlg'),
                'intro' => __('Surveillez l’incident : une intervention préventive peut être nécessaire.', 'backup-jlg'),
                'outro' => __('Planifiez une action de suivi si la situation persiste.', 'backup-jlg'),
                'intent' => 'warning',
                'actions' => [
                    __('Vérifiez la capacité de stockage et les dernières purges distantes.', 'backup-jlg'),
                    __('Planifiez un nouveau point de contrôle pour confirmer que l’alerte diminue.', 'backup-jlg'),
                ],
                'resolution' => __('Actualisez l’état dans le panneau Monitoring pour informer l’équipe.', 'backup-jlg'),
            ],
            'critical' => [
                'label' => __('Critique', 'backup-jlg'),
                'intro' => __('Action immédiate recommandée : l’incident est suivi et sera escaladé.', 'backup-jlg'),
                'outro' => __('Une escalade automatique sera déclenchée si le statut ne change pas.', 'backup-jlg'),
                'intent' => 'error',
                'actions' => [
                    __('Inspectez les journaux détaillés et identifiez la dernière action réussie.', 'backup-jlg'),
                    __('Contactez l’astreinte et préparez un plan de remédiation ou de restauration.', 'backup-jlg'),
                    __('Accusez réception de l’incident dans l’historique pour tracer la prise en charge.', 'backup-jlg'),
                ],
                'resolution' => __('Consignez la résolution dans le tableau de bord pour clôturer l’escalade.', 'backup-jlg'),
            ],
        ];
    }

    /**
     * Gravité par défaut associée à chaque événement connu.
     *
     * @var array<string,string>
     */
    private const EVENT_SEVERITIES = [
        'backup_complete' => 'info',
        'backup_failed' => 'critical',
        'cleanup_complete' => 'info',
        'storage_warning' => 'warning',
        'remote_purge_failed' => 'critical',
        'remote_purge_delayed' => 'critical',
        'restore_self_test_passed' => 'info',
        'restore_self_test_failed' => 'critical',
        'test_notification' => 'info',
    ];

    /**
     * Définit les étapes d'escalade séquentielle proposées par défaut.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function get_stage_blueprint() {
        return [
            'slack' => [
                'channels' => ['slack'],
                'label' => __('Escalade Slack', 'backup-jlg'),
                'description' => __('Diffuse l’alerte sur un canal Slack temps réel pour mobiliser l’équipe support.', 'backup-jlg'),
                'default_delay_minutes' => 15,
            ],
            'discord' => [
                'channels' => ['discord'],
                'label' => __('Escalade Discord', 'backup-jlg'),
                'description' => __('Préviens la communauté technique ou l’équipe on-call via Discord.', 'backup-jlg'),
                'default_delay_minutes' => 15,
            ],
            'teams' => [
                'channels' => ['teams'],
                'label' => __('Escalade Microsoft Teams', 'backup-jlg'),
                'description' => __('Transmets l’incident au helpdesk Microsoft Teams avec mention automatique.', 'backup-jlg'),
                'default_delay_minutes' => 20,
            ],
            'sms' => [
                'channels' => ['sms'],
                'label' => __('Escalade SMS', 'backup-jlg'),
                'description' => __('Envoie un SMS aux astreintes pour les incidents critiques prolongés.', 'backup-jlg'),
                'default_delay_minutes' => 30,
            ],
        ];
    }

    /**
     * Retourne le blueprint public pour les écrans d’administration.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function get_escalation_stage_blueprint() {
        return self::get_stage_blueprint();
    }

    /**
     * Retourne le blueprint interne.
     *
     * @return array<string,array<string,mixed>>
     */
    private function escalation_stage_blueprint() {
        return self::get_stage_blueprint();
    }

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
        add_action('bjlg_remote_purge_delayed', [$this, 'handle_remote_purge_delayed'], 15, 2);
        add_action('bjlg_restore_self_test_passed', [$this, 'handle_restore_self_test_passed'], 15, 1);
        add_action('bjlg_restore_self_test_failed', [$this, 'handle_restore_self_test_failed'], 15, 1);
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
     * Prépare le contexte d'un retard critique de purge distante.
     *
     * @param string              $file
     * @param array<string,mixed> $entry
     */
    public function handle_remote_purge_delayed($file, $entry) {
        $entry = is_array($entry) ? $entry : [];

        $context = [
            'file' => basename((string) $file),
            'destinations' => $this->sanitize_components($entry['destinations'] ?? []),
            'attempts' => isset($entry['attempts']) ? (int) $entry['attempts'] : 0,
            'max_delay' => isset($entry['max_delay']) ? (int) $entry['max_delay'] : null,
            'last_delay' => isset($entry['last_delay']) ? (int) $entry['last_delay'] : null,
            'last_error' => isset($entry['last_error']) ? trim((string) $entry['last_error']) : '',
        ];

        $this->notify('remote_purge_delayed', $context);
    }

    /**
     * Construit le contexte d'un test de restauration réussi.
     *
     * @param array<string,mixed> $report
     */
    public function handle_restore_self_test_passed($report) {
        $report = is_array($report) ? $report : [];

        $context = [
            'archive' => isset($report['archive']) ? (string) $report['archive'] : '',
            'duration' => isset($report['duration']) ? (float) $report['duration'] : null,
            'components' => isset($report['components']) && is_array($report['components']) ? $report['components'] : [],
            'started_at' => isset($report['started_at']) ? (int) $report['started_at'] : null,
            'completed_at' => isset($report['completed_at']) ? (int) $report['completed_at'] : null,
        ];

        $this->notify('restore_self_test_passed', $context);
    }

    /**
     * Construit le contexte d'un test de restauration en échec.
     *
     * @param array<string,mixed> $report
     */
    public function handle_restore_self_test_failed($report) {
        $report = is_array($report) ? $report : [];

        $context = [
            'archive' => isset($report['archive']) ? (string) $report['archive'] : '',
            'error' => isset($report['exception']) ? trim((string) $report['exception']) : ($report['message'] ?? ''),
            'started_at' => isset($report['started_at']) ? (int) $report['started_at'] : null,
            'completed_at' => isset($report['completed_at']) ? (int) $report['completed_at'] : null,
        ];

        $this->notify('restore_self_test_failed', $context);
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

        $body_lines = array_filter(array_map('trim', $lines));
        $lines = $this->build_severity_lines('info', $body_lines, [
            'event' => 'test_notification',
            'title' => $title,
            'context' => $context,
        ]);
        $lines = apply_filters('bjlg_notification_message_lines', $lines, 'test_notification', $context);
        $base_lines = is_array($lines) ? array_values($lines) : [];

        $payload = [
            'event' => 'test_notification',
            'title' => $title,
            'lines' => $lines,
            'context' => $context,
            'severity' => 'info',
        ];

        $payload = apply_filters('bjlg_notification_payload', $payload, 'test_notification', $context);

        if (!is_array($payload) || empty($payload['title']) || empty($payload['lines']) || !is_array($payload['lines'])) {
            return new WP_Error('bjlg_notification_payload_invalid', __('Impossible de préparer la notification de test.', 'backup-jlg'));
        }

        $meta = [
            'event' => 'test_notification',
            'title' => (string) $payload['title'],
            'context' => is_array($payload['context']) ? $payload['context'] : $context,
        ];

        $severity = $this->normalize_severity($payload['severity'] ?? 'info');
        $title = (string) $payload['title'];
        $lines = array_map('strval', $payload['lines']);
        if ($severity !== 'info' && $lines === $base_lines) {
            $lines = $this->build_severity_lines($severity, $body_lines, $meta);
        }

        if (empty($lines)) {
            return new WP_Error('bjlg_notification_payload_invalid', __('Impossible de préparer la notification de test.', 'backup-jlg'));
        }

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
            'context' => $meta['context'],
            'channels' => $channels,
            'created_at' => time(),
            'severity' => $severity,
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

        $merged['quiet_hours'] = isset($merged['quiet_hours']) && is_array($merged['quiet_hours'])
            ? wp_parse_args($merged['quiet_hours'], self::DEFAULTS['quiet_hours'])
            : self::DEFAULTS['quiet_hours'];

        $merged['quiet_hours']['start'] = self::normalize_time_string(
            $merged['quiet_hours']['start'],
            self::DEFAULTS['quiet_hours']['start']
        );
        $merged['quiet_hours']['end'] = self::normalize_time_string(
            $merged['quiet_hours']['end'],
            self::DEFAULTS['quiet_hours']['end']
        );
        $merged['quiet_hours']['allow_critical'] = self::to_bool($merged['quiet_hours']['allow_critical']);
        $merged['quiet_hours']['timezone'] = is_string($merged['quiet_hours']['timezone'])
            ? trim((string) $merged['quiet_hours']['timezone'])
            : '';

        $merged['escalation'] = isset($merged['escalation']) && is_array($merged['escalation'])
            ? wp_parse_args($merged['escalation'], self::DEFAULTS['escalation'])
            : self::DEFAULTS['escalation'];

        $merged['escalation']['enabled'] = self::to_bool($merged['escalation']['enabled']);
        $merged['escalation']['only_critical'] = self::to_bool($merged['escalation']['only_critical']);
        $merged['escalation']['delay_minutes'] = max(
            1,
            (int) $merged['escalation']['delay_minutes']
        );

        $channels = [];
        $channel_defaults = self::DEFAULTS['escalation']['channels'];
        foreach ($channel_defaults as $channel_key => $default_enabled) {
            $candidate = $merged['escalation']['channels'][$channel_key] ?? $default_enabled;
            $channels[$channel_key] = self::to_bool($candidate);
        }
        $merged['escalation']['channels'] = $channels;

        $allowed_modes = ['simple', 'staged'];
        $mode = isset($merged['escalation']['mode']) && is_string($merged['escalation']['mode'])
            ? strtolower($merged['escalation']['mode'])
            : self::DEFAULTS['escalation']['mode'];
        if (!in_array($mode, $allowed_modes, true)) {
            $mode = self::DEFAULTS['escalation']['mode'];
        }
        $merged['escalation']['mode'] = $mode;

        $stage_blueprint = $this->escalation_stage_blueprint();
        $raw_stages = isset($merged['escalation']['stages']) && is_array($merged['escalation']['stages'])
            ? $merged['escalation']['stages']
            : [];

        $stages = [];
        foreach ($stage_blueprint as $stage_key => $stage_defaults) {
            $current = isset($raw_stages[$stage_key]) && is_array($raw_stages[$stage_key])
                ? $raw_stages[$stage_key]
                : [];

            $delay_default = isset($stage_defaults['default_delay_minutes'])
                ? (int) $stage_defaults['default_delay_minutes']
                : 15;

            $stages[$stage_key] = [
                'enabled' => self::to_bool($current['enabled'] ?? false),
                'delay_minutes' => max(0, (int) ($current['delay_minutes'] ?? $delay_default)),
            ];
        }

        $merged['escalation']['stages'] = $stages;

        $template_defaults = self::default_severity_templates();
        $merged['templates'] = isset($merged['templates']) && is_array($merged['templates'])
            ? $merged['templates']
            : [];

        $templates = [];
        foreach ($template_defaults as $severity => $definition) {
            $current = isset($merged['templates'][$severity]) && is_array($merged['templates'][$severity])
                ? $merged['templates'][$severity]
                : [];

            $templates[$severity] = [
                'label' => $this->sanitize_template_label($current['label'] ?? $definition['label']),
                'intro' => $this->sanitize_template_text($current['intro'] ?? $definition['intro']),
                'outro' => $this->sanitize_template_text($current['outro'] ?? $definition['outro']),
                'resolution' => $this->sanitize_template_text($current['resolution'] ?? $definition['resolution']),
                'intent' => $this->sanitize_template_intent($current['intent'] ?? $definition['intent']),
                'actions' => $this->sanitize_template_actions($current['actions'] ?? $definition['actions']),
            ];
        }

        $merged['templates'] = $templates;

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
        $body_lines = $this->get_event_body_lines($event, $context);
        $severity = $this->get_event_severity($event);
        $lines = $this->build_severity_lines($severity, $body_lines, [
            'event' => $event,
            'title' => $title,
            'context' => $context,
        ]);
        $lines = apply_filters('bjlg_notification_message_lines', $lines, $event, $context);
        $base_lines = is_array($lines) ? array_values($lines) : [];

        $payload = [
            'event' => $event,
            'title' => $title,
            'lines' => $lines,
            'context' => $context,
            'severity' => $severity,
        ];

        /**
         * Permet de modifier le contenu de la notification avant envoi.
         */
        $payload = apply_filters('bjlg_notification_payload', $payload, $event, $context);

        if (!is_array($payload) || empty($payload['title']) || empty($payload['lines']) || !is_array($payload['lines'])) {
            BJLG_Debug::log('Notification ignorée car le payload est invalide.');
            return;
        }

        $meta = [
            'event' => $event,
            'title' => $payload['title'],
            'context' => $payload['context'],
        ];

        $final_severity = $this->normalize_severity($payload['severity'] ?? $severity);
        $lines = array_map('strval', $payload['lines']);
        if ($final_severity !== $severity && $lines === $base_lines) {
            $lines = $this->build_severity_lines($final_severity, $body_lines, $meta);
        }

        if (empty($lines)) {
            BJLG_Debug::log('Notification ignorée car aucune ligne valide n’a pu être générée.');
            return;
        }

        $subject = '[Backup JLG] ' . $payload['title'];
        $body = implode("\n", $lines);

        $escalation = $this->compute_escalation_overrides($event, $payload['context'] ?? []);
        $channels = $this->prepare_channels_payload($payload['title'], $lines, null, $escalation['overrides']);

        if (empty($channels)) {
            BJLG_Debug::log('Notification ignorée car aucun canal valide n\'est disponible.');
            return;
        }

        $entry = [
            'event' => $event,
            'title' => $payload['title'],
            'subject' => $subject,
            'lines' => $lines,
            'body' => $body,
            'context' => $payload['context'],
            'channels' => $channels,
            'created_at' => time(),
            'severity' => $final_severity,
        ];

        if (!empty($escalation['meta']['channels'])) {
            $entry['escalation'] = $escalation['meta'];
        }

        $entry = $this->apply_quiet_hours_constraints($event, $entry);

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
            case 'remote_purge_delayed':
                return __('Purge distante en retard', 'backup-jlg');
            case 'restore_self_test_passed':
                return __('Test de restauration réussi', 'backup-jlg');
            case 'restore_self_test_failed':
                return __('Test de restauration échoué', 'backup-jlg');
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
    private function get_event_body_lines($event, $context) {
        $lines = [];

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
            case 'remote_purge_delayed':
                $lines[] = __('Une purge distante accumule du retard.', 'backup-jlg');
                if (!empty($context['file'])) {
                    $lines[] = __('Archive : ', 'backup-jlg') . $context['file'];
                }
                if (!empty($context['destinations'])) {
                    $lines[] = __('Destinations concernées : ', 'backup-jlg') . implode(', ', $context['destinations']);
                }
                if (!empty($context['attempts'])) {
                    $lines[] = __('Tentatives effectuées : ', 'backup-jlg') . (int) $context['attempts'];
                }
                if (!empty($context['max_delay'])) {
                    $lines[] = __('Retard maximum : ', 'backup-jlg') . $this->format_delay((int) $context['max_delay']);
                }
                if (!empty($context['last_delay'])) {
                    $lines[] = __('Dernier délai : ', 'backup-jlg') . $this->format_delay((int) $context['last_delay']);
                }
                if (!empty($context['last_error'])) {
                    $lines[] = __('Dernier message : ', 'backup-jlg') . $context['last_error'];
                }
                break;
            case 'restore_self_test_passed':
                $lines[] = __('Le test de restauration sandbox a réussi.', 'backup-jlg');
                if (!empty($context['archive'])) {
                    $lines[] = __('Archive testée : ', 'backup-jlg') . $context['archive'];
                }
                if (!empty($context['duration'])) {
                    $lines[] = __('Durée : ', 'backup-jlg') . $this->format_duration_seconds((float) $context['duration']);
                }
                if (!empty($context['components']) && is_array($context['components'])) {
                    $component_lines = [];
                    foreach ($context['components'] as $component => $status) {
                        $component_lines[] = sprintf('%s (%s)', $component, $status ? __('ok', 'backup-jlg') : __('manquant', 'backup-jlg'));
                    }
                    if (!empty($component_lines)) {
                        $lines[] = __('Composants vérifiés : ', 'backup-jlg') . implode(', ', $component_lines);
                    }
                }
                if (!empty($context['completed_at'])) {
                    $lines[] = __('Terminé : ', 'backup-jlg') . $this->format_timestamp($context['completed_at']);
                }
                break;
            case 'restore_self_test_failed':
                $lines[] = __('Le test de restauration sandbox a échoué.', 'backup-jlg');
                if (!empty($context['archive'])) {
                    $lines[] = __('Archive testée : ', 'backup-jlg') . $context['archive'];
                }
                if (!empty($context['error'])) {
                    $lines[] = __('Erreur : ', 'backup-jlg') . $context['error'];
                }
                if (!empty($context['completed_at'])) {
                    $lines[] = __('Horodatage : ', 'backup-jlg') . $this->format_timestamp($context['completed_at']);
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

        return array_filter(array_map('trim', $lines));
    }

    private function get_event_lines($event, $context) {
        $severity = $this->get_event_severity($event);
        $body_lines = $this->get_event_body_lines($event, $context);

        return $this->build_severity_lines($severity, $body_lines, [
            'event' => $event,
            'title' => $this->get_event_title($event, $context),
            'context' => $context,
        ]);
    }

    /**
     * Applique le gabarit de gravité aux lignes de message.
     *
     * @param string   $severity
     * @param string[] $body_lines
     *
     * @return string[]
     */
    private function build_severity_lines($severity, array $body_lines, array $meta = []) {
        $timestamp = current_time('mysql');
        $meta['timestamp'] = $timestamp;
        $definition = $this->describe_severity($severity, $meta);

        $lines = [];

        if ($definition['label'] !== '') {
            $lines[] = sprintf(__('Gravité : %s', 'backup-jlg'), $definition['label']);
        }

        if ($definition['intro'] !== '') {
            $lines[] = $definition['intro'];
        }

        $lines = array_merge($lines, array_filter(array_map('trim', $body_lines)));

        if (!empty($definition['actions']) && is_array($definition['actions'])) {
            $lines[] = __('Actions recommandées :', 'backup-jlg');
            foreach ($definition['actions'] as $action_line) {
                $action_line = is_string($action_line) ? trim($action_line) : '';
                if ($action_line === '') {
                    continue;
                }

                $lines[] = sprintf('• %s', $action_line);
            }
        }

        if (!empty($definition['resolution'])) {
            $lines[] = $definition['resolution'];
        }

        if ($definition['outro'] !== '') {
            $lines[] = $definition['outro'];
        }

        $lines[] = sprintf(__('Horodatage : %s', 'backup-jlg'), $timestamp);

        return array_filter(array_map('trim', $lines));
    }

    private function get_event_severity($event) {
        $event = is_string($event) ? trim($event) : '';

        if ($event !== '' && isset(self::EVENT_SEVERITIES[$event])) {
            return self::EVENT_SEVERITIES[$event];
        }

        return 'info';
    }

    private function normalize_severity($value) {
        if (is_string($value)) {
            $candidate = strtolower(trim($value));
            if (in_array($candidate, ['info', 'warning', 'critical'], true)) {
                return $candidate;
            }
        }

        return 'info';
    }

    private function describe_severity($severity, array $meta = []) {
        $severity = $this->normalize_severity($severity);
        $templates = isset($this->settings['templates']) && is_array($this->settings['templates'])
            ? $this->settings['templates']
            : [];
        $defaults_all = self::default_severity_templates();
        $defaults = $defaults_all[$severity] ?? $defaults_all['info'];

        $template = isset($templates[$severity]) && is_array($templates[$severity])
            ? wp_parse_args($templates[$severity], $defaults)
            : $defaults;

        $context = $this->prepare_template_context($severity, $meta, $template['label'] ?? $defaults['label']);

        $label = $this->render_template_string($template['label'] ?? $defaults['label'], $context, $defaults['label']);
        $intro = $this->render_template_string($template['intro'] ?? $defaults['intro'], $context, '');
        $outro = $this->render_template_string($template['outro'] ?? $defaults['outro'], $context, '');
        $resolution = $this->render_template_string($template['resolution'] ?? $defaults['resolution'], $context, '');

        $actions = [];
        if (!empty($template['actions']) && is_array($template['actions'])) {
            foreach ($template['actions'] as $action_line) {
                $action_line = $this->render_template_string($action_line, $context, '');
                if ($action_line === '') {
                    continue;
                }

                $actions[] = $action_line;
            }
        }

        return [
            'label' => $label,
            'intro' => $intro,
            'outro' => $outro,
            'intent' => $this->sanitize_template_intent($template['intent'] ?? $defaults['intent']),
            'actions' => $actions,
            'resolution' => $resolution,
        ];
    }

    /**
     * Prépare le contexte utilisable dans les modèles personnalisés.
     *
     * @param string $severity
     * @param array<string,mixed> $meta
     * @param string $fallback_label
     *
     * @return array<string,string>
     */
    private function prepare_template_context($severity, array $meta, $fallback_label) {
        $event_key = isset($meta['event']) ? (string) $meta['event'] : '';
        $event_title = isset($meta['title']) ? (string) $meta['title'] : '';
        $event_context = isset($meta['context']) && is_array($meta['context']) ? $meta['context'] : [];
        $timestamp = isset($meta['timestamp']) ? (string) $meta['timestamp'] : current_time('mysql');

        $site_name = '';
        if (!empty($event_context['site_name']) && is_string($event_context['site_name'])) {
            $site_name = $event_context['site_name'];
        } elseif (function_exists('get_bloginfo')) {
            $site_name = (string) get_bloginfo('name');
        }

        $site_url = '';
        if (!empty($event_context['site_url']) && is_string($event_context['site_url'])) {
            $site_url = $event_context['site_url'];
        } elseif (function_exists('home_url')) {
            $site_url = (string) home_url('/'); // phpcs:ignore WordPress.WP.AlternativeFunctions.home_url_home_url
        }

        $initiator = '';
        if (!empty($event_context['initiator']) && is_string($event_context['initiator'])) {
            $initiator = $event_context['initiator'];
        }

        $severity_label = $fallback_label !== '' ? $fallback_label : ucfirst($severity);

        return array_filter([
            'event_key' => $event_key,
            'event_title' => $event_title,
            'site_name' => $site_name,
            'site_url' => $site_url,
            'initiator' => $initiator,
            'severity' => $severity,
            'severity_label' => $severity_label,
            'timestamp' => $timestamp,
        ], 'strlen');
    }

    /**
     * Remplace les tokens {{token}} dans une chaîne.
     *
     * @param string $template
     * @param array<string,string> $context
     * @param string $fallback
     */
    private function render_template_string($template, array $context, $fallback) {
        $template = is_string($template) ? trim($template) : '';
        if ($template === '') {
            return is_string($fallback) ? trim($fallback) : '';
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{{' . $key . '}}'] = $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * Sanitize a template label.
     *
     * @param string $value
     */
    private function sanitize_template_label($value) {
        $value = is_string($value) ? $value : '';

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        return trim($value);
    }

    /**
     * Sanitize template multi-line text.
     *
     * @param string $value
     */
    private function sanitize_template_text($value) {
        $value = is_string($value) ? $value : '';

        if (function_exists('sanitize_textarea_field')) {
            return sanitize_textarea_field($value);
        }

        return trim($value);
    }

    /**
     * Sanitize template intent value.
     *
     * @param string $intent
     */
    private function sanitize_template_intent($intent) {
        $intent = is_string($intent) ? strtolower(trim($intent)) : '';

        if (!in_array($intent, ['info', 'warning', 'error'], true)) {
            return 'info';
        }

        return $intent;
    }

    /**
     * Sanitize template actions.
     *
     * @param mixed $value
     *
     * @return string[]
     */
    private function sanitize_template_actions($value) {
        if (!is_array($value)) {
            if (is_string($value)) {
                $value = preg_split('/[\r\n]+/', $value);
            } else {
                $value = [];
            }
        }

        $actions = [];
        foreach ($value as $line) {
            $line = is_string($line) ? $line : '';
            if ($line === '') {
                continue;
            }

            $actions[] = $this->sanitize_template_text($line);
        }

        return $actions;
    }

    /**
     * Retourne le blueprint public pour les templates de gravité.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function get_severity_template_blueprint() {
        return self::default_severity_templates();
    }

    /**
     * Retourne la liste des tokens disponibles pour les modèles personnalisés.
     *
     * @return array<string,string>
     */
    public static function get_template_tokens() {
        return [
            'site_name' => __('Nom du site WordPress', 'backup-jlg'),
            'site_url' => __('URL du site', 'backup-jlg'),
            'event_title' => __('Titre lisible de l’événement', 'backup-jlg'),
            'event_key' => __('Identifiant technique de l’événement', 'backup-jlg'),
            'severity_label' => __('Libellé de gravité', 'backup-jlg'),
            'severity' => __('Code de gravité (info, warning, critical)', 'backup-jlg'),
            'initiator' => __('Utilisateur ou système à l’origine de l’action', 'backup-jlg'),
            'timestamp' => __('Horodatage courant', 'backup-jlg'),
        ];
    }

    /**
     * Formate un délai exprimé en secondes en libellé lisible.
     *
     * @param int $seconds
     */
    private function format_delay($seconds) {
        $seconds = max(0, (int) $seconds);
        $minute = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
        $hour = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
        $day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

        if ($seconds <= 0) {
            return __('immédiat', 'backup-jlg');
        }

        if ($seconds < $minute) {
            return sprintf(
                _n('%s seconde', '%s secondes', $seconds, 'backup-jlg'),
                number_format_i18n($seconds)
            );
        }

        $now = time();
        if (function_exists('human_time_diff')) {
            $reference = max($now - $seconds, 0);
            $human = human_time_diff($reference, $now);
            if (is_string($human) && $human !== '') {
                return $human;
            }
        }

        if ($seconds < $hour) {
            $minutes = (int) round($seconds / $minute);

            return sprintf(
                _n('%s minute', '%s minutes', $minutes, 'backup-jlg'),
                number_format_i18n($minutes)
            );
        }

        if ($seconds < $day) {
            $hours = (int) round($seconds / $hour);

            return sprintf(
                _n('%s heure', '%s heures', $hours, 'backup-jlg'),
                number_format_i18n($hours)
            );
        }

        $days = (int) round($seconds / $day);

        return sprintf(
            _n('%s jour', '%s jours', $days, 'backup-jlg'),
            number_format_i18n($days)
        );
    }

    /**
     * Formate une durée en secondes en conservant les unités les plus pertinentes.
     *
     * @param float $seconds
     */
    private function format_duration_seconds($seconds) {
        $seconds = max(0.0, (float) $seconds);
        $minute = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
        $hour = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;

        if ($seconds < 1) {
            return __('< 1 s', 'backup-jlg');
        }

        if ($seconds < $minute) {
            $precision = $seconds < 10 ? 2 : 1;

            return number_format_i18n($seconds, $precision) . ' ' . __('s', 'backup-jlg');
        }

        if ($seconds < $hour) {
            $minutes = (int) floor($seconds / $minute);
            $remaining = (int) round($seconds - ($minutes * $minute));

            $parts = [
                sprintf(_n('%s minute', '%s minutes', $minutes, 'backup-jlg'), number_format_i18n($minutes)),
            ];

            if ($remaining > 0) {
                $parts[] = sprintf(
                    _n('%s seconde', '%s secondes', $remaining, 'backup-jlg'),
                    number_format_i18n($remaining)
                );
            }

            return implode(' ', $parts);
        }

        $hours = (int) floor($seconds / $hour);
        $remaining_minutes = (int) floor(($seconds - ($hours * $hour)) / $minute);

        $parts = [
            sprintf(_n('%s heure', '%s heures', $hours, 'backup-jlg'), number_format_i18n($hours)),
        ];

        if ($remaining_minutes > 0) {
            $parts[] = sprintf(
                _n('%s minute', '%s minutes', $remaining_minutes, 'backup-jlg'),
                number_format_i18n($remaining_minutes)
            );
        }

        return implode(' ', $parts);
    }

    /**
     * Convertit un timestamp Unix en date localisée.
     *
     * @param int $timestamp
     */
    private function format_timestamp($timestamp) {
        $timestamp = (int) $timestamp;

        if ($timestamp <= 0) {
            return '';
        }

        $date_format = function_exists('get_option') ? get_option('date_format', 'Y-m-d') : 'Y-m-d';
        $time_format = function_exists('get_option') ? get_option('time_format', 'H:i') : 'H:i';
        $format = trim($date_format . ' ' . $time_format);

        if (function_exists('wp_date')) {
            return wp_date($format, $timestamp);
        }

        if (function_exists('date_i18n')) {
            return date_i18n($format, $timestamp);
        }

        return date($format, $timestamp);
    }

    /**
     * Prépare les canaux à utiliser pour une notification donnée.
     *
     * @param string   $title
     * @param string[] $lines
     *
     * @return array<string,array<string,mixed>>
     */
    private function prepare_channels_payload($title, $lines, ?array $settings = null, array $overrides = []) {
        $settings = $settings ?? $this->settings;
        $channels = [];
        $now = time();

        $default_channels = ['email', 'slack', 'discord', 'teams', 'sms'];
        foreach ($default_channels as $channel_key) {
            $payload = $this->build_channel_payload($channel_key, $settings, false);
            if ($payload !== null) {
                $channels[$channel_key] = $payload;
            }
        }

        foreach ($overrides as $channel_key => $override) {
            if (!is_string($channel_key)) {
                continue;
            }

            $channel_key = sanitize_key($channel_key);
            if ($channel_key === '') {
                continue;
            }

            if (isset($channels[$channel_key])) {
                continue;
            }

            $force = !empty($override['force']);
            $payload = $this->build_channel_payload($channel_key, $settings, $force);
            if ($payload === null) {
                continue;
            }

            if (isset($override['delay']) && is_numeric($override['delay'])) {
                $delay = max(0, (int) $override['delay']);
                if ($delay > 0) {
                    $payload['next_attempt_at'] = $now + $delay;
                }
            }

            if (!empty($override['escalation'])) {
                $payload['escalation'] = true;
            }

            $channels[$channel_key] = $payload;
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

    private function build_channel_payload($channel_key, array $settings, $force = false) {
        $channel_key = sanitize_key((string) $channel_key);
        if ($channel_key === '') {
            return null;
        }

        switch ($channel_key) {
            case 'email':
                if (!$force && !$this->is_channel_enabled('email', $settings)) {
                    return null;
                }

                $recipients = BJLG_Notification_Transport::normalize_email_recipients(
                    $settings['email_recipients'] ?? ''
                );

                if (empty($recipients)) {
                    BJLG_Debug::log($force
                        ? 'Canal email ignoré pour l\'escalade : aucun destinataire valide.'
                        : 'Canal email ignoré : aucun destinataire valide.'
                    );

                    return null;
                }

                return [
                    'enabled' => true,
                    'recipients' => $recipients,
                    'status' => 'pending',
                    'attempts' => 0,
                ];

            case 'slack':
            case 'discord':
            case 'teams':
            case 'sms':
                if (!$force && !$this->is_channel_enabled($channel_key, $settings)) {
                    return null;
                }

                $url = $settings['channels'][$channel_key]['webhook_url'] ?? '';
                if (!BJLG_Notification_Transport::is_valid_url($url)) {
                    BJLG_Debug::log(sprintf(
                        $force
                            ? 'Canal %s ignoré pour l\'escalade : URL invalide.'
                            : 'Canal %s ignoré : URL invalide.',
                        $channel_key
                    ));

                    return null;
                }

                return [
                    'enabled' => true,
                    'webhook_url' => $url,
                    'status' => 'pending',
                    'attempts' => 0,
                ];
        }

        return null;
    }

    /**
     * Nettoie la liste des composants envoyés dans les événements.
     *
     * @param mixed $components
     *
     * @return string[]
     */
    private function compute_escalation_overrides($event, $context) {
        $result = [
            'overrides' => [],
            'meta' => [
                'channels' => [],
                'delay' => 0,
            ],
        ];

        $event = is_string($event) ? trim($event) : '';
        if ($event === '') {
            return $result;
        }

        $settings = isset($this->settings['escalation']) && is_array($this->settings['escalation'])
            ? $this->settings['escalation']
            : self::DEFAULTS['escalation'];

        if (empty($settings['enabled'])) {
            return $result;
        }

        $critical_events = $this->get_critical_events();
        $only_critical = !empty($settings['only_critical']);
        if ($only_critical && !in_array($event, $critical_events, true)) {
            return $result;
        }

        $minute_in_seconds = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
        $mode = isset($settings['mode']) && is_string($settings['mode'])
            ? strtolower($settings['mode'])
            : 'simple';

        if ($mode === 'staged') {
            $blueprint = $this->escalation_stage_blueprint();
            $configured_stages = isset($settings['stages']) && is_array($settings['stages'])
                ? $settings['stages']
                : [];

            $overrides = [];
            $meta_channels = [];
            $meta_steps = [];
            $min_delay = null;

            foreach ($blueprint as $stage_key => $stage_definition) {
                $stage_settings = isset($configured_stages[$stage_key]) && is_array($configured_stages[$stage_key])
                    ? $configured_stages[$stage_key]
                    : [];

                if (!self::to_bool($stage_settings['enabled'] ?? false)) {
                    continue;
                }

                $delay_default = isset($stage_definition['default_delay_minutes'])
                    ? (int) $stage_definition['default_delay_minutes']
                    : 15;
                $delay_minutes = max(0, (int) ($stage_settings['delay_minutes'] ?? $delay_default));
                $delay_seconds = $delay_minutes * $minute_in_seconds;

                $stage_channels = isset($stage_definition['channels']) && is_array($stage_definition['channels'])
                    ? $stage_definition['channels']
                    : [$stage_key];

                $registered_channels = [];
                foreach ($stage_channels as $channel_key) {
                    $channel_key = sanitize_key((string) $channel_key);
                    if ($channel_key === '') {
                        continue;
                    }

                    $overrides[$channel_key] = [
                        'force' => true,
                        'delay' => $delay_seconds,
                        'escalation' => true,
                    ];
                    $meta_channels[] = $channel_key;
                    $registered_channels[] = $channel_key;
                }

                if (empty($registered_channels)) {
                    continue;
                }

                $meta_steps[] = [
                    'key' => $stage_key,
                    'label' => isset($stage_definition['label']) ? (string) $stage_definition['label'] : ucfirst($stage_key),
                    'channels' => array_values(array_unique($registered_channels)),
                    'delay' => $delay_seconds,
                    'description' => isset($stage_definition['description']) ? (string) $stage_definition['description'] : '',
                ];

                $min_delay = $min_delay === null ? $delay_seconds : min($min_delay, $delay_seconds);
            }

            if (empty($overrides)) {
                return $result;
            }

            $result['overrides'] = $overrides;
            $result['meta'] = [
                'channels' => array_values(array_unique($meta_channels)),
                'delay' => $min_delay ?? 0,
                'only_critical' => $only_critical,
                'strategy' => 'staged',
                'steps' => $meta_steps,
            ];

            return $result;
        }

        $delay_minutes = isset($settings['delay_minutes']) ? (int) $settings['delay_minutes'] : 15;
        $delay_minutes = max(1, $delay_minutes);
        $delay_seconds = $delay_minutes * $minute_in_seconds;

        $overrides = [];
        if (!empty($settings['channels']) && is_array($settings['channels'])) {
            foreach ($settings['channels'] as $channel_key => $enabled) {
                if (!self::to_bool($enabled)) {
                    continue;
                }

                $channel_key = sanitize_key((string) $channel_key);
                if ($channel_key === '') {
                    continue;
                }

                $overrides[$channel_key] = [
                    'force' => true,
                    'delay' => $delay_seconds,
                    'escalation' => true,
                ];
            }
        }

        if (empty($overrides)) {
            return $result;
        }

        $result['overrides'] = $overrides;
        $result['meta'] = [
            'channels' => array_keys($overrides),
            'delay' => $delay_seconds,
            'only_critical' => $only_critical,
            'strategy' => 'simple',
        ];

        return $result;
    }

    private function apply_quiet_hours_constraints($event, array $entry) {
        $resume_at = $this->get_quiet_hours_resume_timestamp($event);
        if ($resume_at === null) {
            return $entry;
        }

        $entry['quiet_until'] = $resume_at;
        $entry['next_attempt_at'] = isset($entry['next_attempt_at'])
            ? max((int) $entry['next_attempt_at'], $resume_at)
            : $resume_at;

        if (!empty($entry['channels']) && is_array($entry['channels'])) {
            foreach ($entry['channels'] as $channel_key => &$channel) {
                if (!is_array($channel)) {
                    continue;
                }

                $channel_next = isset($channel['next_attempt_at']) ? (int) $channel['next_attempt_at'] : 0;
                if ($channel_next <= 0 || $channel_next < $resume_at) {
                    $channel['next_attempt_at'] = $resume_at;
                }
            }
            unset($channel);
        }

        $entry['quiet_hours'] = [
            'resume_at' => $resume_at,
        ];

        return $entry;
    }

    private function get_quiet_hours_resume_timestamp($event) {
        $event = is_string($event) ? trim($event) : '';
        if ($event === '') {
            return null;
        }

        $quiet = isset($this->settings['quiet_hours']) && is_array($this->settings['quiet_hours'])
            ? $this->settings['quiet_hours']
            : self::DEFAULTS['quiet_hours'];

        if (empty($quiet['enabled'])) {
            return null;
        }

        $critical_events = $this->get_critical_events();
        if (!empty($quiet['allow_critical']) && in_array($event, $critical_events, true)) {
            return null;
        }

        $start = self::normalize_time_string($quiet['start'] ?? '', self::DEFAULTS['quiet_hours']['start']);
        $end = self::normalize_time_string($quiet['end'] ?? '', self::DEFAULTS['quiet_hours']['end']);

        if ($start === $end) {
            return null;
        }

        $timezone = $this->resolve_timezone($quiet['timezone'] ?? '');

        try {
            $now = new \DateTimeImmutable('now', $timezone);
        } catch (\Exception $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        [$start_hour, $start_minute] = array_map('intval', explode(':', $start));
        [$end_hour, $end_minute] = array_map('intval', explode(':', $end));

        $start_dt = $now->setTime($start_hour, $start_minute, 0);
        $end_dt = $now->setTime($end_hour, $end_minute, 0);

        if ($start_dt <= $end_dt) {
            if ($now >= $start_dt && $now < $end_dt) {
                return $end_dt->getTimestamp();
            }

            return null;
        }

        if ($now >= $start_dt) {
            $end_dt = $end_dt->modify('+1 day');
            return $end_dt->getTimestamp();
        }

        if ($now < $end_dt) {
            return $end_dt->getTimestamp();
        }

        return null;
    }

    private function resolve_timezone($timezone_string) {
        if (is_string($timezone_string) && $timezone_string !== '') {
            try {
                return new \DateTimeZone($timezone_string);
            } catch (\Exception $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            }
        }

        if (function_exists('wp_timezone')) {
            $wp_timezone = wp_timezone();
            if ($wp_timezone instanceof \DateTimeZone) {
                return $wp_timezone;
            }
        }

        if (function_exists('get_option')) {
            $tz_string = get_option('timezone_string');
            if (is_string($tz_string) && $tz_string !== '') {
                try {
                    return new \DateTimeZone($tz_string);
                } catch (\Exception $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                }
            }
        }

        return new \DateTimeZone('UTC');
    }

    private function get_critical_events() {
        $critical = [];

        foreach (self::EVENT_SEVERITIES as $event_key => $severity) {
            if ($severity === 'critical') {
                $critical[] = $event_key;
            }
        }

        return array_values(array_unique($critical));
    }

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

    private static function normalize_time_string($value, $fallback) {
        if (!is_string($value)) {
            $value = '';
        }

        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }

        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
            return $fallback;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour < 0 || $hour > 23) {
            $hour = (int) max(0, min(23, $hour));
        }

        if ($minute < 0 || $minute > 59) {
            $minute = (int) max(0, min(59, $minute));
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private static function to_bool($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return (bool) $value;
    }

}
