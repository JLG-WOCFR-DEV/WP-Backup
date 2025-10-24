<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Déclenche automatiquement une sauvegarde avant toute mise à jour de plugin/thème.
 */
class BJLG_Update_Guard {
    /** @var array<string, bool> */
    private $processed_signatures = [];

    /** @var BJLG_Backup|null */
    private $backup_service = null;

    /** @var array<string,mixed>|null */
    private $settings = null;

    public function __construct($backup_service = null) {
        if ($backup_service instanceof BJLG_Backup) {
            $this->backup_service = $backup_service;
        }

        \add_filter('upgrader_pre_install', [$this, 'handle_pre_install'], 9, 3);
    }

    /**
     * Intercepte les mises à jour pour lancer une sauvegarde préventive.
     *
     * @param mixed                $response  Réponse actuelle du hook.
     * @param array<string,mixed>  $hook_extra Contexte de la mise à jour.
     * @param mixed                $upgrader  Instance de l'upgrader courant.
     *
     * @return mixed
     */
    public function handle_pre_install($response, $hook_extra, $upgrader = null) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        try {
            $this->maybe_trigger_pre_update_backup($hook_extra);
        } catch (\Throwable $exception) {
            BJLG_Debug::log('Sauvegarde pré-update non déclenchée : ' . $exception->getMessage());
        }

        return $response;
    }

    /**
     * Lance une sauvegarde avant mise à jour si le contexte le justifie.
     *
     * @param mixed $hook_extra
     *
     * @return string|null Identifiant de la tâche créée ou null si aucune sauvegarde n'a été lancée.
     */
    public function maybe_trigger_pre_update_backup($hook_extra) {
        $context = $this->normalize_context($hook_extra);
        if (!$context) {
            return null;
        }

        $settings = $this->get_settings();

        $enabled = \apply_filters('bjlg_pre_update_backup_enabled', !empty($settings['enabled']), $context, $hook_extra);
        if (!$enabled) {
            if (empty($settings['enabled'])) {
                $this->maybe_trigger_reminder($settings, $context, $hook_extra, 'disabled');
            }
            return null;
        }

        if (!$this->is_target_enabled($context['type'] ?? '', $settings)) {
            $this->maybe_trigger_reminder($settings, $context, $hook_extra, 'ignored_type');
            BJLG_Debug::log('Sauvegarde pré-update ignorée : type ' . ($context['type'] ?? 'inconnu') . ' désactivé dans les réglages.');
            return null;
        }

        $signature = $context['signature'];
        if (isset($this->processed_signatures[$signature])) {
            return null;
        }

        if (BJLG_Backup::is_task_locked()) {
            BJLG_Debug::log('Sauvegarde pré-update ignorée : une autre sauvegarde est déjà en cours.');
            return null;
        }

        $blueprint = $this->resolve_blueprint($context, $hook_extra);
        $blueprint['components'] = $this->filter_components($blueprint['components'], $settings);

        if (empty($blueprint['components'])) {
            $this->maybe_trigger_reminder($settings, $context, $hook_extra, 'no_components');
            BJLG_Debug::log('Sauvegarde pré-update ignorée : aucun composant à sauvegarder.');
            return null;
        }

        $task_id = 'bjlg_backup_' . md5(uniqid('preupdate', true));
        $task_data = [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => sprintf('Snapshot avant %s…', $context['label']),
            'components' => $blueprint['components'],
            'encrypt' => (bool) $blueprint['encrypt'],
            'incremental' => (bool) $blueprint['incremental'],
            'source' => 'pre_update',
            'start_time' => time(),
            'include_patterns' => $blueprint['include_patterns'],
            'exclude_patterns' => $blueprint['exclude_patterns'],
            'post_checks' => $blueprint['post_checks'],
            'secondary_destinations' => $blueprint['secondary_destinations'],
            'secondary_destination_batches' => $blueprint['secondary_destination_batches'],
            'update_context' => $context,
        ];

        $task_data = \apply_filters('bjlg_pre_update_backup_task', $task_data, $context, $hook_extra);
        if (!is_array($task_data)) {
            BJLG_Debug::log('Sauvegarde pré-update annulée par filtre : payload invalide.');
            return null;
        }

        $saved = \set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl());
        if (!$saved) {
            BJLG_Debug::log("Impossible d'initialiser la tâche de sauvegarde pré-update $task_id.");
            return null;
        }

        $this->processed_signatures[$signature] = true;

        BJLG_History::log(
            'pre_update_backup',
            'info',
            sprintf(
                'Sauvegarde pré-update lancée avant %s : %s.',
                $context['label'],
                $context['items_label']
            )
        );

        \do_action('bjlg_pre_update_backup_launched', $task_id, $task_data, $context, $hook_extra);

        try {
            $this->get_backup_service()->run_backup_task($task_id);
        } catch (\Throwable $exception) {
            BJLG_Debug::log('Erreur lors de la sauvegarde pré-update : ' . $exception->getMessage());
        }

        return $task_id;
    }

    /**
     * Normalise le contexte reçu depuis WordPress.
     *
     * @param mixed $hook_extra
     *
     * @return array<string,mixed>|null
     */
    private function normalize_context($hook_extra) {
        if (!is_array($hook_extra)) {
            return null;
        }

        $action = isset($hook_extra['action']) ? sanitize_key((string) $hook_extra['action']) : '';
        if ($action !== 'update') {
            return null;
        }

        $type = isset($hook_extra['type']) ? sanitize_key((string) $hook_extra['type']) : '';
        if (!in_array($type, ['plugin', 'theme', 'core'], true)) {
            return null;
        }

        $items = $this->extract_items_from_context($hook_extra, $type);
        $items_label = !empty($items) ? implode(', ', $items) : __('site complet', 'backup-jlg');

        $label = $this->describe_target($type, count($items));
        $context = [
            'type' => $type,
            'action' => $action,
            'items' => $items,
            'items_label' => $items_label,
            'label' => $label,
            'signature' => $type . '|' . md5($items_label . '|' . $label),
        ];

        $context = \apply_filters('bjlg_pre_update_backup_context', $context, $hook_extra);
        if (!is_array($context) || empty($context['signature'])) {
            return null;
        }

        return $context;
    }

    /**
     * Sélectionne les composants à sauvegarder en se basant sur la planification existante.
     *
     * @param array<string,mixed> $context
     * @param mixed               $hook_extra
     *
     * @return array<string,mixed>
     */
    private function resolve_blueprint(array $context, $hook_extra) {
        $collection = \bjlg_get_option('bjlg_schedule_settings', []);
        $sanitized_collection = BJLG_Settings::sanitize_schedule_collection($collection);
        $schedules = isset($sanitized_collection['schedules']) && is_array($sanitized_collection['schedules'])
            ? $sanitized_collection['schedules']
            : [];

        if (!empty($schedules)) {
            $primary = $this->select_primary_schedule($schedules);
        } else {
            $primary = BJLG_Settings::get_default_schedule_entry();
        }

        $blueprint = [
            'components' => $primary['components'],
            'encrypt' => $primary['encrypt'],
            'incremental' => $primary['incremental'],
            'include_patterns' => $primary['include_patterns'],
            'exclude_patterns' => $primary['exclude_patterns'],
            'post_checks' => $primary['post_checks'],
            'secondary_destinations' => $primary['secondary_destinations'],
            'secondary_destination_batches' => $primary['secondary_destination_batches'] ?? [],
        ];

        return \apply_filters('bjlg_pre_update_backup_blueprint', $blueprint, $context, $hook_extra);
    }

    /**
     * Retourne les réglages du snapshot pré-mise à jour.
     */
    private function get_settings(): array {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $defaults = [
            'enabled' => true,
            'components' => BJLG_Settings::get_default_backup_components(),
            'targets' => [
                'core' => true,
                'plugin' => true,
                'theme' => true,
            ],
            'reminder' => [
                'enabled' => false,
                'message' => 'Pensez à déclencher une sauvegarde manuelle avant d\'appliquer vos mises à jour.',
                'channels' => [
                    'notification' => ['enabled' => false],
                    'email' => ['enabled' => false, 'recipients' => ''],
                ],
            ],
        ];

        $stored = \bjlg_get_option('bjlg_update_guard_settings', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $settings = BJLG_Settings::merge_settings_with_defaults($stored, $defaults);
        $components = [];

        if (array_key_exists('components', $stored) && is_array($stored['components'])) {
            $allowed = BJLG_Settings::get_default_backup_components();
            foreach ($stored['components'] as $component) {
                $key = sanitize_key((string) $component);
                if ($key !== '' && in_array($key, $allowed, true) && !in_array($key, $components, true)) {
                    $components[] = $key;
                }
            }
        } else {
            $components = $settings['components'];
        }

        $settings['components'] = $components;
        $settings['enabled'] = !empty($settings['enabled']);

        $target_defaults = $defaults['targets'];
        $targets = [];
        if (isset($settings['targets']) && is_array($settings['targets'])) {
            foreach ($settings['targets'] as $target_key => $target_value) {
                $key = sanitize_key((string) $target_key);
                if ($key === '' || !array_key_exists($key, $target_defaults)) {
                    continue;
                }
                $targets[$key] = !empty($target_value);
            }
        }
        $settings['targets'] = array_merge($target_defaults, $targets);

        if (!isset($settings['reminder']) || !is_array($settings['reminder'])) {
            $settings['reminder'] = $defaults['reminder'];
        }

        $settings['reminder']['enabled'] = !empty($settings['reminder']['enabled']);
        $settings['reminder']['message'] = isset($settings['reminder']['message'])
            ? (string) $settings['reminder']['message']
            : $defaults['reminder']['message'];

        $channels = isset($settings['reminder']['channels']) && is_array($settings['reminder']['channels'])
            ? $settings['reminder']['channels']
            : [];
        foreach ($defaults['reminder']['channels'] as $channel_key => $channel_defaults) {
            if (!isset($channels[$channel_key]) || !is_array($channels[$channel_key])) {
                $channels[$channel_key] = $channel_defaults;
            } else {
                $channels[$channel_key] = array_merge($channel_defaults, $channels[$channel_key]);
            }
            $channels[$channel_key]['enabled'] = !empty($channels[$channel_key]['enabled']);
            if ($channel_key === 'email') {
                $channels[$channel_key]['recipients'] = isset($channels[$channel_key]['recipients'])
                    ? (string) $channels[$channel_key]['recipients']
                    : '';
            }
        }
        $settings['reminder']['channels'] = $channels;

        $this->settings = $settings;

        return $this->settings;
    }

    /**
     * Filtre les composants en fonction des réglages utilisateur.
     *
     * @param array<int,string> $components
     * @param array<string,mixed> $settings
     *
     * @return array<int,string>
     */
    private function filter_components(array $components, array $settings): array {
        $allowed = isset($settings['components']) && is_array($settings['components'])
            ? $settings['components']
            : [];

        if (empty($allowed)) {
            return [];
        }

        $filtered = [];
        foreach ($components as $component) {
            $component = sanitize_key((string) $component);
            if ($component === '' || !in_array($component, $allowed, true) || in_array($component, $filtered, true)) {
                continue;
            }

            $filtered[] = $component;
        }

        return $filtered;
    }

    /**
     * Déclenche un rappel si configuré.
     *
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $context
     * @param mixed               $hook_extra
     * @param string              $reason
     */
    private function maybe_trigger_reminder(array $settings, array $context, $hook_extra, $reason) {
        if (empty($settings['reminder']['enabled'])) {
            return;
        }

        $message = isset($settings['reminder']['message']) && $settings['reminder']['message'] !== ''
            ? (string) $settings['reminder']['message']
            : 'Pensez à déclencher une sauvegarde manuelle avant d\'appliquer vos mises à jour.';

        BJLG_Debug::log('Rappel pré-update déclenché : ' . $message . ' (raison : ' . $reason . ')');

        if (class_exists(BJLG_History::class)) {
            BJLG_History::log(
                'pre_update_backup_reminder',
                'info',
                sprintf('%s (%s) – %s', $message, $context['items_label'] ?? 'mise à jour', $this->format_reminder_reason($reason))
            );
        }

        $reminder_settings = isset($settings['reminder']) && is_array($settings['reminder'])
            ? $settings['reminder']
            : ['channels' => []];

        $this->dispatch_reminder_notification($reminder_settings, $context, $message, $reason);
        $this->dispatch_reminder_email($reminder_settings, $context, $message, $reason);

        \do_action('bjlg_pre_update_backup_reminder', $context, $reason, $settings['reminder'], $hook_extra);
    }

    private function is_target_enabled($type, array $settings): bool {
        $type = sanitize_key((string) $type);
        if ($type === '') {
            return true;
        }

        $targets = isset($settings['targets']) && is_array($settings['targets'])
            ? $settings['targets']
            : [];

        if (!isset($targets[$type])) {
            return true;
        }

        return !empty($targets[$type]);
    }

    private function dispatch_reminder_email(array $reminder_settings, array $context, string $message, string $reason) {
        if (empty($reminder_settings['channels']['email']['enabled'])) {
            return;
        }

        $recipients = $this->parse_email_recipients($reminder_settings['channels']['email']['recipients'] ?? '');
        if (empty($recipients)) {
            $admin_email = function_exists('get_option') ? get_option('admin_email') : '';
            if (is_string($admin_email) && $admin_email !== '') {
                $recipients[] = $admin_email;
            }
        }

        if (empty($recipients) || !function_exists('wp_mail')) {
            return;
        }

        $subject = sprintf('[Backup JLG] %s', sprintf(__('Snapshot pré-update requis pour %s', 'backup-jlg'), $context['label'] ?? __('la mise à jour', 'backup-jlg')));
        $lines = [
            $message,
            '',
            sprintf(__('Type : %s', 'backup-jlg'), $this->translate_context_type($context['type'] ?? 'update')),
            sprintf(__('Éléments : %s', 'backup-jlg'), $context['items_label'] ?? __('site complet', 'backup-jlg')),
            sprintf(__('Raison : %s', 'backup-jlg'), $this->format_reminder_reason($reason)),
        ];

        $body = implode("\n", array_filter(array_map('strval', $lines)));
        wp_mail($recipients, $subject, $body);
    }

    private function dispatch_reminder_notification(array $reminder_settings, array $context, string $message, string $reason) {
        if (empty($reminder_settings['channels']['notification']['enabled']) || !class_exists(__NAMESPACE__ . '\\BJLG_Notification_Queue')) {
            return;
        }

        $title = sprintf(__('Snapshot pré-update requis pour %s', 'backup-jlg'), $context['label'] ?? __('la mise à jour', 'backup-jlg'));
        $lines = [
            $message,
            sprintf(__('Type : %s', 'backup-jlg'), $this->translate_context_type($context['type'] ?? 'update')),
            sprintf(__('Éléments : %s', 'backup-jlg'), $context['items_label'] ?? __('site complet', 'backup-jlg')),
            sprintf(__('Raison : %s', 'backup-jlg'), $this->format_reminder_reason($reason)),
        ];

        BJLG_Notification_Queue::enqueue([
            'event' => 'pre_update_snapshot_reminder',
            'title' => $title,
            'subject' => $title,
            'lines' => array_map('strval', $lines),
            'body' => implode("\n", array_map('strval', $lines)),
            'context' => [
                'type' => $context['type'] ?? '',
                'items' => $context['items'] ?? [],
                'reason' => $reason,
                'message' => $message,
            ],
            'severity' => $reason === 'disabled' ? 'info' : 'warning',
            'channels' => [
                'internal' => [
                    'enabled' => true,
                    'status' => 'pending',
                    'attempts' => 0,
                ],
            ],
        ]);
    }

    private function parse_email_recipients($value): array {
        if (!is_string($value)) {
            return [];
        }

        $parts = preg_split('/[,;\r\n]+/', $value) ?: [];
        $emails = [];
        foreach ($parts as $email) {
            $email = trim((string) $email);
            if ($email === '') {
                continue;
            }
            $is_valid = function_exists('is_email') ? is_email($email) : (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
            if (!$is_valid) {
                continue;
            }
            if (!in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    private function translate_context_type($type): string {
        switch ($type) {
            case 'plugin':
                return __('extension', 'backup-jlg');
            case 'theme':
                return __('thème', 'backup-jlg');
            case 'core':
                return __('cœur WordPress', 'backup-jlg');
            default:
                return __('mise à jour', 'backup-jlg');
        }
    }

    private function format_reminder_reason($reason): string {
        switch ($reason) {
            case 'disabled':
                return __('Snapshots automatiques désactivés', 'backup-jlg');
            case 'no_components':
                return __('Aucun composant sélectionné', 'backup-jlg');
            case 'ignored_type':
                return __('Type de mise à jour exclu par la configuration', 'backup-jlg');
            default:
                return __('Rappel manuel requis', 'backup-jlg');
        }
    }

    /**
     * Retourne le planning prioritaire (actif si disponible, sinon le premier).
     *
     * @param array<int,array<string,mixed>> $schedules
     *
     * @return array<string,mixed>
     */
    private function select_primary_schedule(array $schedules) {
        foreach ($schedules as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }

            if (($schedule['recurrence'] ?? 'disabled') !== 'disabled') {
                return $schedule;
            }
        }

        return $schedules[0];
    }

    /**
     * Extrait la liste des éléments concernés par la mise à jour.
     *
     * @param array<string,mixed> $hook_extra
     * @param string              $type
     *
     * @return string[]
     */
    private function extract_items_from_context(array $hook_extra, $type) {
        $items = [];
        $keys = ['plugin', 'plugins', 'theme', 'themes', 'item', 'items'];

        foreach ($keys as $key) {
            if (!isset($hook_extra[$key])) {
                continue;
            }

            $value = $hook_extra[$key];
            if (is_array($value)) {
                foreach ($value as $entry) {
                    if (is_scalar($entry)) {
                        $items[] = $this->format_item((string) $entry);
                    }
                }
            } elseif (is_scalar($value)) {
                $items[] = $this->format_item((string) $value);
            }
        }

        if ($type === 'core' && empty($items)) {
            $items[] = 'wordpress-core';
        }

        $items = array_values(array_unique(array_filter($items)));

        return $items;
    }

    /**
     * Formate proprement l'identifiant d'un élément.
     */
    private function format_item($item) {
        $trimmed = trim($item);

        if ($trimmed === '') {
            return '';
        }

        return sanitize_text_field($trimmed);
    }

    /**
     * Fournit un libellé humain selon le type d'élément.
     */
    private function describe_target($type, $count) {
        switch ($type) {
            case 'plugin':
                return $count > 1
                    ? __('la mise à jour des extensions', 'backup-jlg')
                    : __('la mise à jour de l\'extension', 'backup-jlg');
            case 'theme':
                return $count > 1
                    ? __('la mise à jour des thèmes', 'backup-jlg')
                    : __('la mise à jour du thème', 'backup-jlg');
            case 'core':
                return __('la mise à jour du cœur WordPress', 'backup-jlg');
            default:
                return __('la mise à jour', 'backup-jlg');
        }
    }

    /**
     * Retourne (ou crée) le service de sauvegarde.
     */
    private function get_backup_service() {
        if (!$this->backup_service instanceof BJLG_Backup) {
            $this->backup_service = new BJLG_Backup();
        }

        return $this->backup_service;
    }
}
