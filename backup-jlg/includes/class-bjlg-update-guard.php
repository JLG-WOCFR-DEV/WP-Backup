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

        $enabled = \apply_filters('bjlg_pre_update_backup_enabled', true, $context, $hook_extra);
        if (!$enabled) {
            return null;
        }

        $signature = $context['signature'];
        if (isset($this->processed_signatures[$signature])) {
            return null;
        }
        $this->processed_signatures[$signature] = true;

        if (BJLG_Backup::is_task_locked()) {
            BJLG_Debug::log('Sauvegarde pré-update ignorée : une autre sauvegarde est déjà en cours.');
            return null;
        }

        $blueprint = $this->resolve_blueprint($context, $hook_extra);
        if (empty($blueprint['components'])) {
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
        $collection = \get_option('bjlg_schedule_settings', []);
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
