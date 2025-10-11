<?php
namespace BJLG;

use Exception;
use ZipArchive;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gère le processus complet de création de sauvegardes
 */
class BJLG_Backup {

    /**
     * Durée de vie par défaut d'une tâche stockée dans un transient.
     */
    public const TASK_TTL = DAY_IN_SECONDS;

    /**
     * Clé du transient servant de verrou d'exécution.
     */
    private const TASK_LOCK_KEY = 'bjlg_backup_task_lock';

    /**
     * Durée de vie du verrou d'exécution (30 minutes).
     */
    private const TASK_LOCK_TTL = 1800;

    /**
     * Délai de grâce accordé pour initialiser l'état d'une tâche après l'acquisition du verrou.
     */
    private const TASK_LOCK_INITIALIZATION_GRACE = 30;

    /**
     * Stocke le verrou en mémoire pour les environnements sans API de cache/options.
     *
     * @var array{owner: string, acquired_at: int, initialized: bool, expires_at: int}|null
     */
    private static $in_memory_lock = null;

    /**
     * Récupère la durée de vie maximale d'une tâche stockée dans un transient.
     *
     * @return int
     */
    public static function get_task_ttl() {
        $ttl = apply_filters('bjlg_task_ttl', self::TASK_TTL);

        if (!is_numeric($ttl)) {
            $ttl = self::TASK_TTL;
        }

        $ttl = (int) $ttl;

        if ($ttl < 0) {
            $ttl = self::TASK_TTL;
        }

        return $ttl;
    }

    /**
     * Enregistre ou rafraîchit l'état d'une tâche dans un transient.
     *
     * @param string $task_id
     * @param array  $task_data
     * @return bool
     */
    public static function save_task_state($task_id, array $task_data) {
        $saved = set_transient($task_id, $task_data, self::get_task_ttl());

        if ($saved) {
            self::mark_lock_initialized($task_id);
            self::refresh_task_lock($task_id, self::get_task_lock_ttl());
        }

        return $saved;
    }

    /**
     * Supprime l'état stocké d'une tâche.
     *
     * @param string $task_id
     * @return void
     */
    public static function delete_task_state($task_id) {
        if (function_exists('delete_transient')) {
            delete_transient($task_id);
        }
    }

    /**
     * Indique si un verrou d'exécution est actuellement actif.
     *
     * @return bool
     */
    public static function is_task_locked() {
        return self::get_task_lock_owner() !== null;
    }

    /**
     * Réserve le verrou d'exécution pour une tâche donnée.
     *
     * @param string $task_id
     * @return bool
     */
    public static function reserve_task_slot($task_id) {
        $current_owner = self::get_task_lock_owner();
        $lock_ttl = self::get_task_lock_ttl();

        if ($current_owner !== null && $current_owner !== $task_id) {
            return false;
        }

        if ($current_owner === $task_id) {
            self::refresh_task_lock($task_id, $lock_ttl);

            return true;
        }

        if (self::try_acquire_task_lock($task_id, $lock_ttl)) {
            return true;
        }

        return self::get_task_lock_owner() === $task_id;
    }

    /**
     * Libère le verrou d'exécution lorsqu'une tâche est terminée.
     *
     * @param string $task_id
     * @return void
     */
    public static function release_task_slot($task_id) {
        $current_owner = self::get_task_lock_owner();

        if ($current_owner === $task_id) {
            self::delete_lock_payload();
        }
    }

    /**
     * Retourne l'identifiant de la tâche qui possède actuellement le verrou.
     *
     * @return string|null
     */
    private static function get_task_lock_owner() {
        $payload = self::get_lock_payload();

        if ($payload === null) {
            return null;
        }

        $now = time();

        if (!is_string($payload['owner']) || $payload['owner'] === '') {
            self::delete_lock_payload();

            return null;
        }

        if ((int) $payload['expires_at'] <= $now) {
            self::delete_lock_payload();

            return null;
        }

        $initialization_grace = self::get_task_lock_initialization_grace();

        if (!$payload['initialized'] && ($now - (int) $payload['acquired_at']) > $initialization_grace) {
            self::delete_lock_payload();

            return null;
        }

        self::$in_memory_lock = $payload;

        return $payload['owner'];
    }

    /**
     * Retourne la durée de vie du verrou d'exécution, filtrable.
     *
     * @return int
     */
    private static function get_task_lock_ttl() {
        $default_ttl = self::TASK_LOCK_TTL;
        $filtered_ttl = apply_filters('bjlg_task_lock_ttl', $default_ttl);

        if (!is_numeric($filtered_ttl) || (int) $filtered_ttl <= 0) {
            return $default_ttl;
        }

        return (int) $filtered_ttl;
    }

    /**
     * Retourne le délai de grâce accordé pour initialiser la tâche verrouillée.
     *
     * @return int
     */
    private static function get_task_lock_initialization_grace() {
        $default_grace = self::TASK_LOCK_INITIALIZATION_GRACE;
        $filtered_grace = apply_filters('bjlg_task_lock_initialization_grace', $default_grace);

        if (!is_numeric($filtered_grace) || (int) $filtered_grace < 0) {
            return $default_grace;
        }

        return (int) $filtered_grace;
    }

    /**
     * Récupère le payload du verrou, quelle que soit la couche de stockage utilisée.
     *
     * @return array{owner: string, acquired_at: int, initialized: bool, expires_at: int}|null
     */
    private static function get_lock_payload() {
        if (function_exists('wp_using_ext_object_cache')
            && wp_using_ext_object_cache()
            && function_exists('wp_cache_get')
        ) {
            $raw_payload = wp_cache_get(self::TASK_LOCK_KEY, 'transient');

            if ($raw_payload !== false) {
                $payload = self::normalize_lock_payload($raw_payload);

                if ($payload !== null) {
                    self::$in_memory_lock = $payload;

                    return $payload;
                }
            }
        }

        if (function_exists('get_transient')) {
            $raw_payload = get_transient(self::TASK_LOCK_KEY);

            if ($raw_payload !== false) {
                $payload = self::normalize_lock_payload($raw_payload);

                if ($payload !== null) {
                    self::$in_memory_lock = $payload;

                    return $payload;
                }
            }
        }

        if (function_exists('get_option')) {
            $option_name = '_transient_' . self::TASK_LOCK_KEY;
            $raw_payload = get_option($option_name, null);

            if ($raw_payload !== null) {
                $payload = self::normalize_lock_payload($raw_payload);

                if ($payload !== null) {
                    self::$in_memory_lock = $payload;

                    return $payload;
                }
            }
        }

        if (self::$in_memory_lock !== null) {
            $payload = self::normalize_lock_payload(self::$in_memory_lock);

            if ($payload !== null) {
                if ((int) $payload['expires_at'] <= time()) {
                    self::$in_memory_lock = null;

                    return null;
                }

                return $payload;
            }
        }

        return null;
    }

    /**
     * Convertit un payload quelconque en représentation structurée.
     *
     * @param mixed $raw_payload
     * @return array{owner: string, acquired_at: int, initialized: bool, expires_at: int}|null
     */
    private static function normalize_lock_payload($raw_payload) {
        if (is_array($raw_payload)) {
            if (!isset($raw_payload['owner']) || !isset($raw_payload['expires_at'])) {
                return null;
            }

            $now = time();

            $owner = (string) $raw_payload['owner'];
            $acquired_at = isset($raw_payload['acquired_at'])
                ? (int) $raw_payload['acquired_at']
                : $now;
            $initialized = isset($raw_payload['initialized'])
                ? (bool) $raw_payload['initialized']
                : true;
            $expires_at = (int) $raw_payload['expires_at'];

            if ($expires_at <= 0) {
                $expires_at = $now + self::get_task_lock_ttl();
            }

            return [
                'owner' => $owner,
                'acquired_at' => $acquired_at,
                'initialized' => $initialized,
                'expires_at' => $expires_at,
            ];
        }

        if (is_string($raw_payload) && $raw_payload !== '') {
            $now = time();

            return [
                'owner' => $raw_payload,
                'acquired_at' => $now,
                'initialized' => true,
                'expires_at' => $now + self::get_task_lock_ttl(),
            ];
        }

        return null;
    }

    /**
     * Enregistre la représentation structurée du verrou dans toutes les couches disponibles.
     *
     * @param array{owner: string, acquired_at: int, initialized: bool, expires_at: int} $payload
     * @return void
     */
    private static function persist_lock_payload(array $payload) {
        $ttl = self::calculate_remaining_ttl($payload);

        if (function_exists('wp_using_ext_object_cache')
            && wp_using_ext_object_cache()
            && function_exists('wp_cache_set')
        ) {
            wp_cache_set(self::TASK_LOCK_KEY, $payload, 'transient', $ttl);
        }

        if (function_exists('set_transient')) {
            set_transient(self::TASK_LOCK_KEY, $payload, $ttl);
        } elseif (function_exists('update_option')) {
            $option_name = '_transient_' . self::TASK_LOCK_KEY;
            $timeout_name = '_transient_timeout_' . self::TASK_LOCK_KEY;

            update_option($option_name, $payload);
            update_option($timeout_name, $payload['expires_at']);
        }

        self::$in_memory_lock = $payload;
    }

    /**
     * Supprime le verrou quelle que soit la couche de stockage.
     *
     * @return void
     */
    private static function delete_lock_payload() {
        if (function_exists('wp_using_ext_object_cache')
            && wp_using_ext_object_cache()
            && function_exists('wp_cache_delete')
        ) {
            wp_cache_delete(self::TASK_LOCK_KEY, 'transient');
        }

        if (function_exists('delete_transient')) {
            delete_transient(self::TASK_LOCK_KEY);
        } elseif (function_exists('delete_option')) {
            $option_name = '_transient_' . self::TASK_LOCK_KEY;
            $timeout_name = '_transient_timeout_' . self::TASK_LOCK_KEY;

            delete_option($option_name);
            delete_option($timeout_name);
        }

        self::$in_memory_lock = null;
    }

    /**
     * Calcule le TTL restant pour le payload du verrou.
     *
     * @param array{owner: string, acquired_at: int, initialized: bool, expires_at: int} $payload
     * @return int
     */
    private static function calculate_remaining_ttl(array $payload) {
        $remaining = (int) $payload['expires_at'] - time();

        if ($remaining < 1) {
            return 1;
        }

        return $remaining;
    }

    /**
     * Marque le verrou comme initialisé pour la tâche donnée.
     *
     * @param string $task_id
     * @return void
     */
    private static function mark_lock_initialized($task_id) {
        $payload = self::get_lock_payload();

        if ($payload === null || $payload['owner'] !== $task_id) {
            return;
        }

        if ($payload['initialized']) {
            return;
        }

        $payload['initialized'] = true;

        self::persist_lock_payload($payload);
    }

    /**
     * Tente d'acquérir le verrou de manière atomique selon les APIs disponibles.
     *
     * @param string $task_id
     * @param int    $ttl
     *
     * @return bool
     */
    private static function try_acquire_task_lock($task_id, $ttl) {
        $now = time();
        $ttl = (int) $ttl;
        $payload = [
            'owner' => (string) $task_id,
            'acquired_at' => $now,
            'initialized' => false,
            'expires_at' => $now + $ttl,
        ];

        if (function_exists('wp_using_ext_object_cache')
            && wp_using_ext_object_cache()
            && function_exists('wp_cache_add')
        ) {
            if (wp_cache_add(self::TASK_LOCK_KEY, $payload, 'transient', $ttl)) {
                self::$in_memory_lock = $payload;

                return true;
            }
        }

        if (function_exists('add_option')) {
            $option_name = '_transient_' . self::TASK_LOCK_KEY;
            $timeout_name = '_transient_timeout_' . self::TASK_LOCK_KEY;
            $added = add_option($option_name, $payload, '', 'no');

            if ($added) {
                $expires_at = $payload['expires_at'];

                if (!add_option($timeout_name, $expires_at, '', 'no')) {
                    if (function_exists('update_option')) {
                        update_option($timeout_name, $expires_at);
                    }
                }

                self::$in_memory_lock = $payload;

                return true;
            }
        }

        if (function_exists('set_transient') && set_transient(self::TASK_LOCK_KEY, $payload, $ttl)) {
            self::$in_memory_lock = $payload;

            return true;
        }

        $existing_payload = self::$in_memory_lock !== null
            ? self::normalize_lock_payload(self::$in_memory_lock)
            : null;

        if ($existing_payload === null || (int) $existing_payload['expires_at'] <= $now) {
            self::$in_memory_lock = $payload;

            return true;
        }

        return $existing_payload['owner'] === $task_id;
    }

    /**
     * Rafraîchit la durée de vie du verrou pour le propriétaire actuel.
     *
     * @param string $task_id
     * @param int    $ttl
     *
     * @return void
     */
    private static function refresh_task_lock($task_id, $ttl) {
        $existing_payload = self::get_lock_payload();
        $now = time();
        $ttl = (int) $ttl;

        if ($existing_payload === null || $existing_payload['owner'] !== $task_id) {
            $payload = [
                'owner' => (string) $task_id,
                'acquired_at' => $now,
                'initialized' => false,
                'expires_at' => $now + $ttl,
            ];
        } else {
            $payload = $existing_payload;
            $payload['expires_at'] = $now + $ttl;
        }

        self::persist_lock_payload($payload);
    }

    private $performance_optimizer;
    private $encryption_handler;

    /** @var array<int, string> */
    private $temporary_files = [];
    
    public function __construct($performance_optimizer = null, $encryption_handler = null) {
        if ($performance_optimizer instanceof BJLG_Performance) {
            $this->performance_optimizer = $performance_optimizer;
        }

        if ($encryption_handler instanceof BJLG_Encryption) {
            $this->encryption_handler = $encryption_handler;
        }

        // Hooks AJAX
        add_action('wp_ajax_bjlg_start_backup_task', [$this, 'handle_start_backup_task']);
        add_action('wp_ajax_bjlg_check_backup_progress', [$this, 'handle_check_backup_progress']);

        // Hook pour l'exécution en arrière-plan
        add_action('bjlg_run_backup_task', [$this, 'run_backup_task']);

        // Initialiser les handlers
        add_action('init', [$this, 'init_handlers']);
    }
    
    /**
     * Initialise les handlers
     */
    public function init_handlers() {
        if (!$this->performance_optimizer && class_exists(BJLG_Performance::class)) {
            $this->performance_optimizer = new BJLG_Performance();
        }
        if (!$this->encryption_handler && class_exists(BJLG_Encryption::class)) {
            $this->encryption_handler = new BJLG_Encryption();
        }
    }

    /**
     * Gère la requête AJAX pour démarrer une tâche de sauvegarde
     */
    public function handle_start_backup_task() {
        if (!\bjlg_can_manage_backups()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        $raw = isset($_POST['components']) ? (array) $_POST['components'] : [];
        $components = array_map('sanitize_text_field', $raw);

        $encrypt = $this->get_boolean_request_value('encrypt', 'encrypt_backup');
        $incremental = $this->get_boolean_request_value('incremental', 'incremental_backup');
        $include_patterns = BJLG_Settings::sanitize_pattern_list($_POST['include_patterns'] ?? []);
        $exclude_patterns = BJLG_Settings::sanitize_pattern_list($_POST['exclude_patterns'] ?? []);
        $post_checks = BJLG_Settings::sanitize_post_checks(
            $_POST['post_checks'] ?? [],
            BJLG_Settings::get_default_backup_post_checks()
        );
        $secondary_destinations = BJLG_Settings::sanitize_destination_list(
            $_POST['secondary_destinations'] ?? [],
            BJLG_Settings::get_known_destination_ids()
        );
        BJLG_Settings::get_instance()->update_backup_filters(
            $include_patterns,
            $exclude_patterns,
            $secondary_destinations,
            $post_checks
        );

        if (empty($components)) {
            wp_send_json_error(['message' => 'Aucun composant sélectionné.']);
        }

        // Créer un ID unique pour cette tâche
        $task_id = 'bjlg_backup_' . md5(uniqid('manual', true));

        // Initialiser les données de la tâche
        $task_data = [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => 'Initialisation de la sauvegarde...',
            'components' => $components,
            'encrypt' => $encrypt,
            'incremental' => $incremental,
            'source' => 'manual',
            'start_time' => time(),
            'include_patterns' => $include_patterns,
            'exclude_patterns' => $exclude_patterns,
            'post_checks' => $post_checks,
            'secondary_destinations' => $secondary_destinations,
        ];

        if (!self::reserve_task_slot($task_id)) {
            BJLG_Debug::log("Impossible de démarrer la tâche $task_id : une sauvegarde est déjà en cours.");
            wp_send_json_error([
                'message' => 'Une autre sauvegarde est déjà en cours d\'exécution.'
            ], 409);
        }

        $event_timestamp = time();
        $event_args = ['task_id' => $task_id];

        // Sauvegarder l'état avant de planifier l'exécution en arrière-plan
        $state_saved = self::save_task_state($task_id, $task_data);

        if ($state_saved === false) {
            BJLG_Debug::log("Échec de l'enregistrement de l'état pour la tâche de sauvegarde : $task_id");

            if (function_exists('wp_unschedule_event')) {
                wp_unschedule_event($event_timestamp, 'bjlg_run_backup_task', $event_args);
            }

            self::release_task_slot($task_id);

            wp_send_json_error(['message' => "Impossible d'initialiser la tâche de sauvegarde."], 500);
        }

        // Planifier l'exécution immédiate en arrière-plan
        $event_scheduled = wp_schedule_single_event($event_timestamp, 'bjlg_run_backup_task', $event_args);

        if ($event_scheduled === false) {
            BJLG_Debug::log("Échec de la planification de la tâche de sauvegarde : $task_id");

            if (function_exists('delete_transient')) {
                delete_transient($task_id);
            }

            self::release_task_slot($task_id);

            wp_send_json_error(['message' => "Impossible de planifier la tâche de sauvegarde en arrière-plan."], 500);
        }

        BJLG_Debug::log("Nouvelle tâche de sauvegarde créée : $task_id");
        BJLG_History::log('backup_started', 'info', 'Composants : ' . implode(', ', $components));

        wp_send_json_success([
            'task_id' => $task_id,
            'message' => 'Sauvegarde lancée en arrière-plan.'
        ]);
    }

    /**
     * Récupère une valeur booléenne depuis la requête en acceptant plusieurs clés.
     *
     * @param string $primary_key
     * @param string $fallback_key
     * @return bool
     */
    private function get_boolean_request_value($primary_key, $fallback_key) {
        $value = null;

        if (isset($_POST[$primary_key])) {
            $value = $_POST[$primary_key];
        } elseif (isset($_POST[$fallback_key])) {
            $value = $_POST[$fallback_key];
        }

        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if ($value === '1' || $value === 1) {
            return true;
        }

        if ($value === '0' || $value === 0) {
            return false;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $filtered === null ? false : $filtered;
    }

    /**
     * Vérifie la progression d'une tâche
     */
    public function handle_check_backup_progress() {
        if (!\bjlg_can_manage_backups()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        $task_id = sanitize_key($_POST['task_id']);
        $progress_data = get_transient($task_id);

        if ($progress_data === false) {
            wp_send_json_error(['message' => 'Tâche non trouvée ou expirée.']);
        }

        wp_send_json_success($progress_data);
    }

    /**
     * Crée une nouvelle instance de ZipArchive.
     *
     * @return ZipArchive
     */
    protected function create_zip_archive() {
        return new ZipArchive();
    }

    /**
     * Exécute la tâche de sauvegarde en arrière-plan
     */
    public function run_backup_task($task_id) {
        if (!self::reserve_task_slot($task_id)) {
            $lock_owner = self::get_task_lock_owner();

            if ($lock_owner !== null && $lock_owner !== $task_id) {
                BJLG_Debug::log("Tâche $task_id retardée : une autre sauvegarde ($lock_owner) est en cours.");
            } else {
                BJLG_Debug::log("Impossible d'acquérir le verrou d'exécution pour la tâche $task_id. Nouvelle tentative programmée.");
            }

            $rescheduled = wp_schedule_single_event(time() + 30, 'bjlg_run_backup_task', ['task_id' => $task_id]);

            if ($rescheduled === false) {
                BJLG_Debug::log("Échec de la replanification de la tâche $task_id.");
            }

            return;
        }

        $components = [];
        $backup_filepath = null;

        try {
            $this->cleanup_temporary_files();

            $task_data = get_transient($task_id);
            if (!$task_data) {
                BJLG_Debug::log("ERREUR: Tâche $task_id introuvable.");
                return;
            }

            // Configuration initiale
            set_time_limit(0);
            @ini_set('memory_limit', '512M');

            BJLG_Debug::log("Début de la sauvegarde - Task ID: $task_id");

            $encrypt = filter_var($task_data['encrypt'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $task_data['encrypt'] = ($encrypt === null) ? false : $encrypt;

            $incremental = filter_var($task_data['incremental'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $task_data['incremental'] = ($incremental === null) ? false : $incremental;

            $allowed_components = ['db', 'plugins', 'themes', 'uploads'];
            $components = isset($task_data['components']) ? (array) $task_data['components'] : [];
            $components = array_map('sanitize_key', $components);
            $components = array_values(array_unique(array_intersect($components, $allowed_components)));

            $task_data['components'] = $components;
            self::save_task_state($task_id, $task_data);

            $include_patterns = $this->resolve_include_patterns($task_data);
            $exclude_overrides = $this->resolve_exclude_patterns($task_data);
            $post_checks = $this->resolve_post_checks($task_data);
            $destination_queue = $this->resolve_destination_queue($task_data);

            $task_data['include_patterns'] = $include_patterns;
            $task_data['exclude_patterns'] = $exclude_overrides;
            $task_data['post_checks'] = $post_checks;
            $task_data['secondary_destinations'] = $destination_queue;
            self::save_task_state($task_id, $task_data);

            if (function_exists('apply_filters')) {
                $filtered_task_data = apply_filters('bjlg_backup_process', $task_data, $task_id);
                if (is_array($filtered_task_data)) {
                    $task_data = array_merge($task_data, $filtered_task_data);
                    self::save_task_state($task_id, $task_data);
                }
            }

            if (empty($components)) {
                BJLG_Debug::log("ERREUR: Aucun composant valide pour la tâche $task_id.");
                BJLG_History::log('backup_created', 'failure', 'Aucun composant valide pour la sauvegarde.');
                $this->update_task_progress($task_id, 100, 'error', 'Aucun composant valide pour la sauvegarde.');
                return;
            }

            // Mise à jour : Début
            $this->update_task_progress($task_id, 10, 'running', 'Préparation de la sauvegarde...');

            // Déterminer le type de sauvegarde
            $backup_type = $task_data['incremental'] ? 'incremental' : 'full';

            // Si incrémentale, vérifier qu'une sauvegarde complète existe
            $incremental_handler = null;
            if ($task_data['incremental']) {
                $incremental_handler = BJLG_Incremental::get_latest_instance();
                if (!$incremental_handler) {
                    $incremental_handler = new BJLG_Incremental();
                }

                if (!$incremental_handler->can_do_incremental()) {
                    BJLG_Debug::log("Pas de sauvegarde complète trouvée, bascule en mode complet.");
                    $backup_type = 'full';
                    $task_data['incremental'] = false;
                    self::save_task_state($task_id, $task_data);
                    $incremental_handler = null;
                }
            }

            $this->ensure_backup_directory_is_ready();

            $backup_filename = $this->generate_backup_filename($backup_type, $components);
            $backup_filepath = BJLG_BACKUP_DIR . $backup_filename;
            $use_parallel_flow = !empty($task_data['use_parallel'])
                && !$task_data['incremental']
                && empty($include_patterns)
                && empty($exclude_overrides)
                && $this->performance_optimizer instanceof BJLG_Performance;

            $close_result = true;

            if ($use_parallel_flow) {
                BJLG_Debug::log('Mode optimisé activé : génération parallèle du paquet.');
                $this->update_task_progress($task_id, 35, 'running', 'Traitement optimisé des fichiers…');

                $optimized = $this->performance_optimizer->create_optimized_backup(
                    $components,
                    $task_id,
                    [
                        'type' => $backup_type,
                        'target_filepath' => $backup_filepath,
                        'filename' => $backup_filename,
                    ]
                );

                if (is_array($optimized) && isset($optimized['path'])) {
                    $backup_filepath = (string) $optimized['path'];
                    $backup_filename = basename($backup_filepath);
                    $task_data['performance_summary'] = $optimized['meta'] ?? [];
                    self::save_task_state($task_id, $task_data);
                }

                $this->update_task_progress($task_id, 70, 'running', 'Assemblage final de la sauvegarde optimisée…');
            } else {
                BJLG_Debug::log("Création du fichier : $backup_filename");

                $zip = $this->create_zip_archive();
                $zip_open_result = $zip->open($backup_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

                if ($zip_open_result !== true) {
                    $error_message = $this->describe_zip_error($zip_open_result);

                    BJLG_Debug::log(
                        sprintf(
                            "ERREUR: ZipArchive::open a échoué pour %s : %s",
                            $backup_filepath,
                            $error_message
                        )
                    );

                    throw new Exception("Impossible de créer l'archive ZIP : " . $error_message . '.');
                }

                $manifest = $this->create_manifest($components, $backup_type);
                $zip->addFromString('backup-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

                $progress = 20;
                $components_count = count($components);
                $progress_per_component = 70 / max(1, $components_count);
                $deleted_paths_registry = [];

                foreach ($components as $component) {
                    $this->update_task_progress($task_id, $progress, 'running', "Sauvegarde : $component");

                    $directory_changes = null;

                    switch ($component) {
                        case 'db':
                            $this->backup_database($zip, $task_data['incremental']);
                            break;
                        case 'plugins':
                            $directory_changes = $this->backup_directory($zip, WP_PLUGIN_DIR, 'wp-content/plugins/', $task_data['incremental'], $incremental_handler, $include_patterns, $exclude_overrides);
                            break;
                        case 'themes':
                            $directory_changes = $this->backup_directory($zip, get_theme_root(), 'wp-content/themes/', $task_data['incremental'], $incremental_handler, $include_patterns, $exclude_overrides);
                            break;
                        case 'uploads':
                            $upload_dir = wp_get_upload_dir();
                            $directory_changes = $this->backup_directory($zip, $upload_dir['basedir'], 'wp-content/uploads/', $task_data['incremental'], $incremental_handler, $include_patterns, $exclude_overrides);
                            break;
                    }

                    if (is_array($directory_changes) && !empty($directory_changes['deleted'])) {
                        $this->collect_deleted_paths($deleted_paths_registry, $directory_changes['deleted']);
                    }

                    $progress += $progress_per_component;
                    $this->update_task_progress($task_id, round($progress, 1), 'running', "Composant $component terminé");
                }

                if ($task_data['incremental'] && !empty($deleted_paths_registry)) {
                    $deleted_metadata = [
                        'generated_at' => function_exists('current_time') ? current_time('c') : date('c'),
                        'count' => count($deleted_paths_registry),
                        'paths' => array_values(array_keys($deleted_paths_registry)),
                    ];

                    $encoded_deleted = json_encode($deleted_metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                    if ($encoded_deleted === false) {
                        BJLG_Debug::log("Impossible d'encoder la liste des fichiers supprimés pour l'archive incrémentale.", 'error');
                    } else {
                        $zip->addFromString('deleted-files.json', $encoded_deleted);
                    }
                }

                $close_result = @$zip->close();

                if ($close_result !== true) {
                    throw new Exception("Impossible de finaliser l'archive ZIP.");
                }
            }

            // Chiffrement si demandé
            $requested_encryption = (bool) $task_data['encrypt'];
            if ($requested_encryption) {
                if ($this->encryption_handler) {
                    $this->update_task_progress($task_id, 95, 'running', 'Chiffrement de la sauvegarde...');
                    $encrypted_file = $this->encryption_handler->encrypt_backup_file($backup_filepath);

                    if (is_string($encrypted_file) && $encrypted_file !== $backup_filepath) {
                        $backup_filepath = $encrypted_file;
                        $backup_filename = basename($encrypted_file);
                    } else {
                        $task_data['encrypt'] = false;
                        self::save_task_state($task_id, $task_data);

                        BJLG_Debug::log("Chiffrement non appliqué pour la sauvegarde {$backup_filename}.");
                        BJLG_History::log(
                            'backup_encryption_failed',
                            'warning',
                            'Le fichier de sauvegarde n\'a pas été chiffré comme prévu.'
                        );

                        $this->update_task_progress(
                            $task_id,
                            95,
                            'running',
                            'Chiffrement indisponible, sauvegarde conservée sans chiffrement.'
                        );
                    }
                } else {
                    $task_data['encrypt'] = false;
                    self::save_task_state($task_id, $task_data);

                    BJLG_Debug::log('Chiffrement demandé mais module indisponible.');
                    BJLG_History::log(
                        'backup_encryption_failed',
                        'warning',
                        'Chiffrement demandé mais module indisponible : sauvegarde non chiffrée.'
                    );

                    $this->update_task_progress(
                        $task_id,
                        95,
                        'running',
                        'Chiffrement indisponible, sauvegarde conservée sans chiffrement.'
                    );
                }
            }

            $effective_encryption = (bool) $task_data['encrypt'];

            $check_results = $this->perform_post_backup_checks($backup_filepath, $post_checks, $effective_encryption);

            if (($check_results['overall_status'] ?? 'passed') === 'failed') {
                $failure_message = $check_results['overall_message'] ?? 'Les vérifications post-sauvegarde ont échoué.';
                throw new Exception($failure_message);
            }
            $destination_results = $this->dispatch_to_destinations(
                $backup_filepath,
                $destination_queue,
                $task_id,
                $task_data['secondary_destination_batches'] ?? []
            );

            $destination_failure_notice = '';
            if (!empty($destination_results['failures'])) {
                $failure_messages = [];

                foreach ($destination_results['failures'] as $destination_id => $error_message) {
                    $label = BJLG_Settings::get_destination_label($destination_id);
                    if ($label === '') {
                        $label = is_scalar($destination_id) ? (string) $destination_id : 'destination inconnue';
                    }

                    $clean_message = is_string($error_message) && $error_message !== ''
                        ? $error_message
                        : 'Service non connecté.';

                    $failure_messages[] = sprintf('%s : %s', $label, $clean_message);
                }

                if (!empty($failure_messages)) {
                    $summary = 'Envoi vers services externes non réalisé - ' . implode(' | ', $failure_messages);
                    $hint = 'Connectez les services nécessaires dans le menu Destinations.';
                    $destination_failure_notice = $summary . '. ' . $hint;

                    BJLG_Debug::warning($destination_failure_notice);
                    $this->update_task_progress($task_id, 99, 'warning', $destination_failure_notice);
                }
            }

            // Calculer les statistiques
            $file_size = filesize($backup_filepath);
            $duration = time() - $task_data['start_time'];

            // Enregistrer le succès
            BJLG_History::log('backup_created', 'success', sprintf(
                'Fichier : %s | Taille : %s | Durée : %ds | Chiffrement : %s',
                $backup_filename,
                size_format($file_size),
                $duration,
                $effective_encryption ? 'oui' : 'non'
            ));

            $completion_timestamp = time();
            $manifest_details = [
                'file' => $backup_filename,
                'path' => $backup_filepath,
                'size' => $file_size,
                'components' => $components,
                'encrypted' => $effective_encryption,
                'incremental' => $task_data['incremental'],
                'duration' => $duration,
                'timestamp' => $completion_timestamp,
            ];

            if (!empty($check_results['checksum'])) {
                $manifest_details['checksum'] = $check_results['checksum'];
                $manifest_details['checksum_algorithm'] = $check_results['checksum_algorithm'];
            }
            $manifest_details['post_checks'] = $check_results;
            $manifest_details['destinations'] = $destination_queue;

            // Notification de succès
            do_action('bjlg_backup_complete', $backup_filename, $manifest_details);

            if (class_exists(BJLG_Incremental::class)) {
                $incremental_handler = BJLG_Incremental::get_latest_instance();
                if (!$incremental_handler) {
                    $incremental_handler = new BJLG_Incremental();
                }

                if ($incremental_handler) {
                    $incremental_handler->update_manifest($backup_filename, $manifest_details);
                }
            }

            // Mise à jour finale
            $success_message = 'Sauvegarde terminée avec succès !';
            if ($requested_encryption && !$effective_encryption) {
                $success_message .= ' (Chiffrement non appliqué.)';
            }
            if (!empty($destination_results['failures'])) {
                $success_message .= ' (Envois distants partiels)';
            }
            if ($destination_failure_notice !== '') {
                $success_message .= ' ' . $destination_failure_notice;
            }

            $this->update_task_progress($task_id, 100, 'complete', $success_message);

            BJLG_Debug::log("Sauvegarde terminée : $backup_filename (" . size_format($file_size) . ")");

        } catch (Exception $e) {
            BJLG_Debug::log("ERREUR dans la sauvegarde : " . $e->getMessage());
            BJLG_History::log('backup_created', 'failure', 'Erreur : ' . $e->getMessage());

            // Notification d'échec
            do_action('bjlg_backup_failed', $e->getMessage(), [
                'task_id' => $task_id,
                'components' => $components
            ]);

            $this->update_task_progress($task_id, 100, 'error', 'Erreur : ' . $e->getMessage());

            // Nettoyer les fichiers partiels
            if (isset($backup_filepath) && file_exists($backup_filepath)) {
                @unlink($backup_filepath);
            }
        } finally {
            $this->cleanup_temporary_files();
            self::release_task_slot($task_id);
        }
    }

    /**
     * S'assure que le dossier de sauvegarde est prêt à l'emploi.
     *
     * @throws Exception Quand le dossier ne peut pas être créé ou n'est pas accessible en écriture.
     */
    private function ensure_backup_directory_is_ready() {
        if (!is_dir(BJLG_BACKUP_DIR)) {
            if (!wp_mkdir_p(BJLG_BACKUP_DIR)) {
                throw new Exception("Le dossier de sauvegarde est introuvable et n'a pas pu être créé.");
            }
        }

        $is_writable = function_exists('wp_is_writable') ? wp_is_writable(BJLG_BACKUP_DIR) : is_writable(BJLG_BACKUP_DIR);

        if (!$is_writable) {
            throw new Exception("Le dossier de sauvegarde n'est pas accessible en écriture.");
        }
    }

    /**
     * Retourne une description lisible pour les erreurs ZipArchive::open().
     *
     * @param int|string $error_code
     * @return string
     */
    private function describe_zip_error($error_code) {
        $errors = [
            ZipArchive::ER_EXISTS => "L'archive existe déjà et ne peut pas être écrasée.",
            ZipArchive::ER_INCONS => "Archive ZIP incohérente ou corrompue.",
            ZipArchive::ER_INVAL => "Arguments fournis invalides.",
            ZipArchive::ER_MEMORY => 'Mémoire insuffisante pour ouvrir l\'archive.',
            ZipArchive::ER_NOENT => 'Fichier ou dossier manquant pour créer l\'archive.',
            ZipArchive::ER_NOZIP => "Le fichier n'est pas une archive ZIP valide.",
            ZipArchive::ER_OPEN => "Impossible d'ouvrir le fichier de sauvegarde.",
            ZipArchive::ER_READ => "Impossible de lire le fichier de sauvegarde.",
            ZipArchive::ER_SEEK => 'Erreur lors de la recherche dans le fichier de sauvegarde.',
        ];

        if (is_int($error_code) && isset($errors[$error_code])) {
            return $errors[$error_code];
        }

        return sprintf('Code erreur %s renvoyé par ZipArchive::open()', (string) $error_code);
    }

    /**
     * Génère un nom de fichier pour la sauvegarde
     */
    private function generate_backup_filename($type, $components) {
        $date = date('Y-m-d-H-i-s');
        $prefix = ($type === 'incremental') ? 'incremental' : 'backup';

        // Ajouter un identifiant des composants si ce n'est pas tout
        $all_components = ['db', 'plugins', 'themes', 'uploads'];
        $components_str = 'full';

        if (count(array_diff($all_components, $components)) > 0) {
            $components_str = implode('-', $components);
        }

        $base = "{$prefix}-{$components_str}-{$date}";

        do {
            $unique_suffix = str_replace('.', '', uniqid('', true));
            $filename = "{$base}-{$unique_suffix}.zip";
            $filepath = BJLG_BACKUP_DIR . $filename;
        } while (file_exists($filepath) || file_exists($filepath . '.enc'));

        return $filename;
    }

    /**
     * Crée le manifeste de la sauvegarde
     */
    private function create_manifest($components, $type) {
        global $wp_version;

        return [
            'version' => BJLG_VERSION,
            'wp_version' => $wp_version,
            'php_version' => PHP_VERSION,
            'type' => $type,
            'contains' => $components,
            'created_at' => current_time('c'),
            'site_url' => get_site_url(),
            'site_name' => get_bloginfo('name'),
            'db_prefix' => $GLOBALS['wpdb']->prefix,
            'theme_active' => get_option('stylesheet'),
            'plugins_active' => get_option('active_plugins'),
            'multisite' => is_multisite(),
            'file_count' => 0,
            'checksum' => '',
            'checksum_algorithm' => '',
        ];
    }

    /**
     * Vérifie qu'une opération ZipArchive s'est correctement déroulée.
     *
     * @param bool   $result
     * @param string $message
     *
     * @throws Exception
     */
    private function assert_zip_operation_success($result, $message) {
        if ($result !== true) {
            BJLG_Debug::log($message);

            throw new Exception($message);
        }
    }

    /**
     * Sauvegarde la base de données en écrivant le dump SQL dans un fichier temporaire
     * pour limiter la consommation mémoire avant de l'ajouter à l'archive.
     *
     * @throws Exception Si le fichier temporaire ne peut pas être créé ou ajouté à l'archive.
     */
    private function backup_database(&$zip, $incremental = false) {
        global $wpdb;

        BJLG_Debug::log("Export de la base de données...");

        $sql_filename = 'database.sql';
        $temp_file = function_exists('wp_tempnam') ? wp_tempnam('bjlg-db-export.sql') : tempnam(sys_get_temp_dir(), 'bjlg-db-');

        if (!$temp_file) {
            throw new Exception("Impossible de créer le fichier temporaire pour l'export SQL.");
        }

        $handle = fopen($temp_file, 'w');

        if (!$handle) {
            @unlink($temp_file);
            throw new Exception("Impossible d'ouvrir le fichier temporaire pour l'export SQL.");
        }

        $this->register_temporary_file($temp_file);

        try {
            // Header SQL
            fwrite($handle, "-- Backup JLG Database Export\n");
            fwrite($handle, "-- Version: " . BJLG_VERSION . "\n");
            fwrite($handle, "-- Date: " . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, "-- Site: " . get_site_url() . "\n\n");
            fwrite($handle, "SET NAMES utf8mb4;\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            // Obtenir toutes les tables
            $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);

            $incremental_handler = null;
            if ($incremental && class_exists(BJLG_Incremental::class)) {
                $incremental_handler = BJLG_Incremental::get_latest_instance();

                if (!$incremental_handler) {
                    $incremental_handler = new BJLG_Incremental();
                }
            }

            foreach ($tables as $table_array) {
                $table = $table_array[0];

                // Pour l'incrémental, vérifier si la table a changé
                if ($incremental && $incremental_handler) {
                    if (!$incremental_handler->table_has_changed($table)) {
                        BJLG_Debug::log("Table $table ignorée (pas de changement)");
                        continue;
                    }
                }

                // Structure de la table
                $create_table = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
                fwrite($handle, "\n-- Table: {$table}\n");
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, $create_table[1] . ";\n\n");

                // Données de la table
                $row_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");

                if ($row_count > 0) {
                    fwrite($handle, "-- Data for table: {$table}\n");

                    $batch_size = 1000;
                    $primary_key = $this->get_table_primary_key($table);

                    if ($primary_key !== null) {
                        $this->export_table_by_primary_key($handle, $table, $primary_key, $batch_size);
                    } else {
                        $this->export_table_with_streaming($handle, $table, $batch_size);
                    }
                }
            }

            fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }

        // Ajouter au ZIP via un fichier temporaire puis le supprimer
        $added = $zip->addFile($temp_file, $sql_filename);
        $this->assert_zip_operation_success(
            $added,
            sprintf(
                "Impossible d'ajouter l'export SQL temporaire %s à l'archive sous %s.",
                $this->normalize_path($temp_file),
                $sql_filename
            )
        );

        BJLG_Debug::log("Export de la base de données terminé.");
    }

    /**
     * Crée les instructions INSERT
     */
    private function create_insert_statement($table, $rows) {
        if (empty($rows)) return '';

        $buffer = '';

        $this->stream_insert_statement(
            $table,
            $rows,
            static function (string $chunk) use (&$buffer) {
                $buffer .= $chunk;
            }
        );

        return $buffer;
    }

    /**
     * Écrit une instruction INSERT directement dans une ressource.
     *
     * @param resource $handle
     * @param string   $table
     * @param array<int, array<string, mixed>> $rows
     * @return void
     */
    private function write_insert_statement($handle, $table, $rows)
    {
        if (!is_resource($handle) || empty($rows)) {
            return;
        }

        $this->stream_insert_statement(
            $table,
            $rows,
            static function (string $chunk) use ($handle) {
                fwrite($handle, $chunk);
            }
        );
    }

    /**
     * Génère une instruction INSERT en streaming pour limiter l'utilisation mémoire.
     *
     * @param string   $table
     * @param array<int, array<string, mixed>> $rows
     * @param callable $writer
     * @return void
     */
    private function stream_insert_statement($table, $rows, callable $writer)
    {
        if (empty($rows)) {
            return;
        }

        $columns = array_keys($rows[0]);
        $columns_str = '`' . implode('`, `', $columns) . '`';

        $writer("INSERT INTO `{$table}` ({$columns_str}) VALUES\n");

        $is_first = true;

        foreach ($rows as $row) {
            $row_values = [];
            foreach ($row as $value) {
                $row_values[] = $this->format_sql_value($value);
            }

            $prefix = $is_first ? '' : ",\n";
            $writer($prefix . '(' . implode(', ', $row_values) . ')');
            $is_first = false;
        }

        $writer(";\n\n");
    }

    /**
     * Détermine la colonne de clé primaire pour une table donnée si elle est exploitable.
     *
     * @param string $table
     * @return array{column: string, numeric: bool}|null
     */
    private function get_table_primary_key($table)
    {
        global $wpdb;

        if (!$this->is_safe_identifier($table)) {
            return null;
        }

        $quoted_table = $this->quote_identifier($table);

        $indexes = $wpdb->get_results("SHOW INDEX FROM {$quoted_table}", ARRAY_A);

        if (empty($indexes)) {
            return null;
        }

        $primary_columns = array_values(array_filter($indexes, static function ($index) {
            return isset($index['Key_name']) && $index['Key_name'] === 'PRIMARY';
        }));

        if (count($primary_columns) !== 1 || !isset($primary_columns[0]['Column_name'])) {
            return null;
        }

        $primary_column = (string) $primary_columns[0]['Column_name'];

        if (!$this->is_safe_identifier($primary_column)) {
            return null;
        }

        $column_query = $wpdb->prepare(
            "SHOW COLUMNS FROM {$quoted_table} LIKE %s",
            $primary_column
        );

        $column_details = $wpdb->get_row($column_query, ARRAY_A);

        $is_numeric = false;

        if (is_array($column_details) && isset($column_details['Type'])) {
            $type = strtolower((string) $column_details['Type']);
            $is_numeric = (bool) preg_match('/^(?:tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double)/', $type);
        }

        return [
            'column' => $primary_column,
            'numeric' => $is_numeric,
        ];
    }

    /**
     * Exporte les lignes d'une table en se basant sur des intervalles de clé primaire.
     *
     * @param resource $handle
     * @param string   $table
     * @param array{column: string, numeric: bool} $primary_key
     * @param int      $batch_size
     * @return void
     */
    private function export_table_by_primary_key($handle, $table, array $primary_key, $batch_size)
    {
        global $wpdb;

        $batch_size = max(1, (int) $batch_size);
        $quoted_table = $this->quote_identifier($table);
        $quoted_column = $this->quote_identifier($primary_key['column']);

        $last_value = null;
        $placeholder = $primary_key['numeric'] ? '%d' : '%s';

        do {
            if ($last_value === null) {
                $query = sprintf(
                    'SELECT * FROM %s ORDER BY %s ASC LIMIT %d',
                    $quoted_table,
                    $quoted_column,
                    $batch_size
                );
            } else {
                $prepared_sql = sprintf(
                    'SELECT * FROM %s WHERE %s > %s ORDER BY %s ASC LIMIT %d',
                    $quoted_table,
                    $quoted_column,
                    $placeholder,
                    $quoted_column,
                    $batch_size
                );

                $query = $wpdb->prepare($prepared_sql, $last_value);
            }

            $rows = $wpdb->get_results($query, ARRAY_A);

            if (empty($rows)) {
                break;
            }

            $this->write_insert_statement($handle, $table, $rows);

            $last_row = end($rows);
            $current_value = $last_row[$primary_key['column']] ?? null;
            if ($current_value === null) {
                break;
            }

            if ($primary_key['numeric']) {
                $current_numeric = (int) $current_value;

                if ($last_value !== null && $current_numeric <= (int) $last_value) {
                    break;
                }

                $last_value = $current_numeric;
            } else {
                $current_string = (string) $current_value;

                if ($last_value !== null && strcmp($current_string, (string) $last_value) <= 0) {
                    break;
                }

                $last_value = $current_string;
            }
        } while (true);
    }

    /**
     * Exporte les données d'une table en mode streaming lorsque la clé primaire n'est pas exploitable.
     *
     * @param resource $handle
     * @param string   $table
     * @param int      $batch_size
     * @return void
     */
    private function export_table_with_streaming($handle, $table, $batch_size)
    {
        global $wpdb;

        if (!$this->is_safe_identifier($table)) {
            return;
        }

        $batch_size = max(1, (int) $batch_size);
        $quoted_table = $this->quote_identifier($table);

        $dbh = $wpdb->dbh ?? null;

        if ($dbh instanceof \mysqli) {
            $result = $dbh->query("SELECT * FROM {$quoted_table}", MYSQLI_USE_RESULT);

            if ($result instanceof \mysqli_result) {
                try {
                    $buffer = [];

                    while ($row = $result->fetch_assoc()) {
                        $buffer[] = $row;

                        if (count($buffer) >= $batch_size) {
                            $this->write_insert_statement($handle, $table, $buffer);
                            $buffer = [];
                        }
                    }

                    if (!empty($buffer)) {
                        $this->write_insert_statement($handle, $table, $buffer);
                    }
                } finally {
                    $result->free();
                }

                return;
            }
        }

        $rows = $wpdb->get_results("SELECT * FROM {$quoted_table}", ARRAY_A);

        if (empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, $batch_size) as $chunk) {
            $this->write_insert_statement($handle, $table, $chunk);
        }
    }

    /**
     * Vérifie qu'un identifiant SQL (table, colonne) ne contient pas de caractères dangereux.
     *
     * @param string $identifier
     * @return bool
     */
    private function is_safe_identifier($identifier)
    {
        return is_string($identifier) && $identifier !== '' && preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }

    /**
     * Entoure un identifiant de backticks en échappant ceux déjà présents.
     *
     * @param string $identifier
     * @return string
     */
    private function quote_identifier($identifier)
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Prépare une valeur pour une instruction SQL INSERT.
     *
     * @param mixed $value
     * @return string
     */
    private function format_sql_value($value) {
        if (is_null($value)) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            if ($this->is_binary_string($value)) {
                return '0x' . bin2hex($value);
            }

            return "'" . esc_sql($value) . "'";
        }

        $serialized = function_exists('maybe_serialize') ? maybe_serialize($value) : serialize($value);

        return "'" . esc_sql($serialized) . "'";
    }

    /**
     * Détermine si une chaîne contient des données binaires.
     *
     * @param mixed $value
     * @return bool
     */
    private function is_binary_string($value) {
        if (!is_string($value)) {
            return false;
        }

        if (strpos($value, "\0") !== false) {
            return true;
        }

        return @preg_match('//u', $value) !== 1;
    }

    /**
     * Sauvegarde un répertoire
     */
    private function backup_directory(
        &$zip,
        $source_dir,
        $zip_path,
        $incremental = false,
        $incremental_handler = null,
        array $include_patterns = [],
        array $exclude_overrides = []
    ) {
        if (!is_dir($source_dir)) {
            BJLG_Debug::log("Répertoire introuvable : $source_dir");
            return [
                'modified' => [],
                'deleted' => [],
            ];
        }

        BJLG_Debug::log("Sauvegarde du répertoire : " . basename($source_dir));

        $exclude_patterns = $this->get_exclude_patterns($source_dir, $zip_path, $exclude_overrides);

        // Pour l'incrémental, obtenir la liste des fichiers modifiés
        $modified_files = [];
        $deleted_files = [];
        if ($incremental) {
            if (!$incremental_handler instanceof BJLG_Incremental) {
                $incremental_handler = BJLG_Incremental::get_latest_instance();
            }

            if (!$incremental_handler) {
                $incremental_handler = new BJLG_Incremental();
            }

            $scan = $incremental_handler->get_modified_files($source_dir);
            $modified_files = is_array($scan['modified'] ?? null) ? $scan['modified'] : [];
            $deleted_files = is_array($scan['deleted'] ?? null) ? $scan['deleted'] : [];

            if (empty($modified_files)) {
                if (!empty($deleted_files)) {
                    BJLG_Debug::log(
                        sprintf(
                            "Aucun fichier modifié dans : %s (suppression détectée : %d)",
                            basename($source_dir),
                            count($deleted_files)
                        )
                    );
                } else {
                    BJLG_Debug::log("Aucun fichier modifié dans : " . basename($source_dir));
                }

                return [
                    'modified' => [],
                    'deleted' => $deleted_files,
                ];
            }

            if (!empty($include_patterns)) {
                $modified_files = array_values(array_filter($modified_files, function ($file) use ($include_patterns) {
                    return $this->should_include_file($file, $include_patterns);
                }));

                if (empty($modified_files)) {
                    BJLG_Debug::log("Aucun fichier modifié correspondant aux inclusions dans : " . basename($source_dir));
                    return [
                        'modified' => [],
                        'deleted' => $deleted_files,
                    ];
                }
            }
        }

        $modified_files = array_values($modified_files);

        try {
            $this->add_folder_to_zip($zip, $source_dir, $zip_path, $exclude_patterns, $incremental, $modified_files, $include_patterns);
        } catch (Exception $exception) {
            $message = sprintf(
                "Impossible d'ajouter le répertoire \"%s\" à l'archive : %s",
                $source_dir,
                $exception->getMessage()
            );
            BJLG_Debug::log($message);

            throw new Exception($message, 0, $exception);
        }

        return [
            'modified' => $modified_files,
            'deleted' => $deleted_files,
        ];
    }

    /**
     * Retourne la liste des motifs d'exclusion pour la sauvegarde d'un répertoire.
     *
     * @param string $source_dir
     * @param string $zip_path
     * @return array<int, string>
     */
    private function get_exclude_patterns($source_dir, $zip_path, array $additional_patterns = []) {
        $default_patterns = [
            '*/cache/*',
            '*/node_modules/*',
            '*/.git/*',
            '*.log',
            '*/bjlg-backups/*',
        ];

        $option_patterns = [];
        if (function_exists('get_option')) {
            $stored_patterns = get_option('bjlg_backup_exclude_patterns', []);
            $option_patterns = $this->normalize_exclude_patterns($stored_patterns);
        }

        $additional = BJLG_Settings::sanitize_pattern_list($additional_patterns);
        $exclude_patterns = array_merge($default_patterns, $option_patterns, $additional);
        $exclude_patterns = array_values(array_filter(array_unique($exclude_patterns), 'strlen'));

        if (function_exists('apply_filters')) {
            /** @var array<int, string> $filtered_patterns */
            $filtered_patterns = apply_filters('bjlg_backup_exclude_patterns', $exclude_patterns, $source_dir, $zip_path);
            if (is_array($filtered_patterns)) {
                $exclude_patterns = array_values(array_filter(array_unique($filtered_patterns), 'strlen'));
            }
        }

        return $exclude_patterns;
    }

    /**
     * Normalise les motifs d'exclusion configurés via une option.
     *
     * @param mixed $patterns
     * @return array<int, string>
     */
    private function normalize_exclude_patterns($patterns) {
        return BJLG_Settings::sanitize_pattern_list($patterns);
    }

    /**
     * Ajoute récursivement un dossier au ZIP
     *
     * @param array<int, string> $include Motifs d'inclusion supplémentaires
     * @throws Exception Si le dossier ne peut pas être ouvert.
     */
    public function add_folder_to_zip(
        &$zip,
        $folder,
        $zip_path,
        $exclude = [],
        $incremental = false,
        $modified_files = [],
        array $include = []
    ) {
        if (!is_dir($folder)) {
            $message = "Impossible d'ouvrir le répertoire : $folder";
            BJLG_Debug::log($message);

            throw new Exception($message);
        }

        $handle = opendir($folder);
        if ($handle === false) {
            $message = "Impossible d'ouvrir le répertoire : $folder";
            BJLG_Debug::log($message);

            throw new Exception($message);
        }

        if ($incremental && !empty($modified_files)) {
            $normalized_modified = [];
            foreach ($modified_files as $modified_file) {
                if (!is_string($modified_file) || $modified_file === '') {
                    continue;
                }

                $normalized = $this->normalize_path($modified_file);
                if ($normalized !== '') {
                    $normalized_modified[$normalized] = true;
                }
            }

            $modified_files = array_keys($normalized_modified);
        }

        try {
            while (($file = readdir($handle)) !== false) {
                if ($file == '.' || $file == '..') continue;

                $file_path = $folder . '/' . $file;
                $relative_path = $zip_path . $file;
                $normalized_file_path = $this->normalize_path($file_path);

                // Vérifier les exclusions
                $skip = false;
                foreach ($exclude as $pattern) {
                    if ($this->path_matches_pattern($pattern, $normalized_file_path)) {
                        $skip = true;
                        break;
                    }
                }

                if ($skip) continue;

                if (is_link($file_path)) {
                    BJLG_Debug::log("Lien symbolique ignoré : {$normalized_file_path}");
                    continue;
                }

                if (is_dir($file_path)) {
                    // Récursion pour les sous-dossiers
                    $this->add_folder_to_zip($zip, $file_path, $relative_path . '/', $exclude, $incremental, $modified_files, $include);
                } else {
                    // Pour l'incrémental, vérifier si le fichier est dans la liste des modifiés
                    if ($incremental && !empty($modified_files)) {
                        if (!in_array($normalized_file_path, $modified_files, true)) {
                            continue;
                        }
                    }

                    if (!$this->should_include_file($normalized_file_path, $include)) {
                        continue;
                    }

                    // Ajouter le fichier
                    $file_size = @filesize($file_path);
                    $normalized_path_for_log = $normalized_file_path !== '' ? $normalized_file_path : $file_path;

                    if ($file_size === false || $file_size < 50 * 1024 * 1024) { // Moins de 50MB
                        $added = $zip->addFile($file_path, $relative_path);
                        $this->assert_zip_operation_success(
                            $added,
                            sprintf(
                                "Impossible d'ajouter le fichier %s à l'archive (%s).",
                                $normalized_path_for_log,
                                $relative_path
                            )
                        );
                    } else {
                        // Pour les gros fichiers, utiliser le streaming
                        $added = $zip->addFile($file_path, $relative_path);
                        $this->assert_zip_operation_success(
                            $added,
                            sprintf(
                                "Impossible d'ajouter le fichier volumineux %s à l'archive (%s).",
                                $normalized_path_for_log,
                                $relative_path
                            )
                        );

                        $compression_set = $zip->setCompressionName($relative_path, ZipArchive::CM_STORE);
                        $this->assert_zip_operation_success(
                            $compression_set,
                            sprintf(
                                "Impossible de définir la compression pour le fichier %s dans l'archive.",
                                $relative_path
                            )
                        );
                    }
                }
            }
        } finally {
            closedir($handle);
        }
    }

    /**
     * Agrège les chemins supprimés détectés lors d'une sauvegarde incrémentale.
     *
     * @param array<string, bool> $collector
     * @param array<int, string>  $paths
     * @return void
     */
    private function collect_deleted_paths(array &$collector, array $paths): void {
        if (empty($paths)) {
            return;
        }

        $normalized_abspath = $this->normalize_path(ABSPATH);

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            $normalized = $this->normalize_path($path);

            if ($normalized === '') {
                continue;
            }

            $relative = $normalized;

            if ($normalized_abspath !== '' && strpos($normalized, $normalized_abspath) === 0) {
                $relative = ltrim(substr($normalized, strlen($normalized_abspath)), '/');
            } else {
                $relative = ltrim($normalized, '/');
            }

            if ($relative === '') {
                continue;
            }

            $collector[$relative] = true;
        }
    }

    /**
     * Enregistre un fichier temporaire à nettoyer une fois la sauvegarde terminée.
     *
     * @param string $path
     */
    private function register_temporary_file($path) {
        if (!is_string($path) || $path === '') {
            return;
        }

        $this->temporary_files[] = $path;
    }

    /**
     * Supprime les fichiers temporaires enregistrés.
     */
    private function cleanup_temporary_files() {
        if (empty($this->temporary_files)) {
            return;
        }

        foreach ($this->temporary_files as $index => $path) {
            if (!is_string($path) || $path === '') {
                unset($this->temporary_files[$index]);
                continue;
            }

            if (file_exists($path)) {
                @unlink($path);
            }

            unset($this->temporary_files[$index]);
        }

        $this->temporary_files = [];
    }

    private function resolve_include_patterns(array $task_data) {
        $raw_patterns = [];

        if (isset($task_data['include_patterns']) && is_array($task_data['include_patterns'])) {
            $raw_patterns = $task_data['include_patterns'];
        } elseif (function_exists('get_option')) {
            $raw_patterns = get_option('bjlg_backup_include_patterns', []);
        }

        $sanitized = BJLG_Settings::sanitize_pattern_list($raw_patterns);
        $normalized = [];

        foreach ($sanitized as $pattern) {
            $clean_pattern = $this->normalize_path($pattern);
            if (strpos($clean_pattern, '*') === false && strpos($clean_pattern, '?') === false) {
                $trimmed = trim($clean_pattern, '*');
                $clean_pattern = '*' . ltrim($trimmed, '/');
                if (substr($clean_pattern, -1) !== '*') {
                    $clean_pattern .= '*';
                }
            }

            $normalized[$clean_pattern] = true;
        }

        return array_keys($normalized);
    }

    private function resolve_exclude_patterns(array $task_data) {
        $raw_patterns = [];

        if (isset($task_data['exclude_patterns']) && is_array($task_data['exclude_patterns'])) {
            $raw_patterns = $task_data['exclude_patterns'];
        } elseif (function_exists('get_option')) {
            $raw_patterns = get_option('bjlg_backup_exclude_patterns', []);
        }

        return BJLG_Settings::sanitize_pattern_list($raw_patterns);
    }

    private function resolve_post_checks(array $task_data) {
        $raw = [];

        if (isset($task_data['post_checks']) && is_array($task_data['post_checks'])) {
            $raw = $task_data['post_checks'];
        } elseif (function_exists('get_option')) {
            $raw = get_option('bjlg_backup_post_checks', BJLG_Settings::get_default_backup_post_checks());
        }

        return BJLG_Settings::sanitize_post_checks($raw, BJLG_Settings::get_default_backup_post_checks());
    }

    private function resolve_destination_queue(array $task_data) {
        $raw = [];

        if (isset($task_data['secondary_destinations']) && is_array($task_data['secondary_destinations'])) {
            $raw = $task_data['secondary_destinations'];
        } elseif (function_exists('get_option')) {
            $raw = get_option('bjlg_backup_secondary_destinations', []);
        }

        return BJLG_Settings::sanitize_destination_list($raw, BJLG_Settings::get_known_destination_ids());
    }

    private function should_include_file($path, array $include_patterns) {
        if (empty($include_patterns)) {
            return true;
        }

        $normalized_path = $this->normalize_path($path);
        if ($normalized_path === '') {
            return false;
        }

        $candidates = [$normalized_path];

        if (defined('ABSPATH')) {
            $root = $this->normalize_path(ABSPATH);
            if ($root !== '' && strpos($normalized_path, $root) === 0) {
                $relative = ltrim(substr($normalized_path, strlen($root)), '/');
                if ($relative !== '') {
                    $candidates[] = $relative;
                }
            }
        }

        if (defined('WP_CONTENT_DIR')) {
            $content_dir = $this->normalize_path(WP_CONTENT_DIR);
            if ($content_dir !== '' && strpos($normalized_path, $content_dir) === 0) {
                $relative_content = ltrim(substr($normalized_path, strlen($content_dir)), '/');
                if ($relative_content !== '') {
                    $candidates[] = 'wp-content/' . $relative_content;
                    $candidates[] = $relative_content;
                }
            }
        }

        foreach ($include_patterns as $pattern) {
            $pattern = $this->normalize_path($pattern);
            foreach ($candidates as $candidate) {
                if ($this->path_matches_pattern($pattern, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function perform_post_backup_checks($filepath, array $post_checks, $encrypted) {
        $results = [
            'checksum' => '',
            'checksum_algorithm' => '',
            'dry_run' => 'disabled',
            'files' => [],
            'overall_status' => 'passed',
            'overall_message' => 'Toutes les vérifications ont réussi.',
        ];

        if (!is_readable($filepath)) {
            throw new Exception('Le fichier de sauvegarde est introuvable pour les vérifications.');
        }

        if (!empty($post_checks['checksum'])) {
            $hash = @hash_file('sha256', $filepath);
            if ($hash === false) {
                throw new Exception('Impossible de calculer le hash SHA-256 de la sauvegarde.');
            }

            BJLG_Debug::log('Checksum de la sauvegarde : ' . $hash);
            $results['checksum'] = $hash;
            $results['checksum_algorithm'] = 'sha256';
        }

        $log_file_check = function($filename, $status, $message = '', array $context = []) use (&$results) {
            $entry = array_merge([
                'status' => $status,
                'message' => $message,
            ], $context);

            $results['files'][$filename] = $entry;

            $history_status = 'info';
            if ($status === 'passed') {
                $history_status = 'success';
            } elseif ($status === 'failed') {
                $history_status = 'failure';
            }

            $log_prefix = 'Vérification post-sauvegarde';
            $log_message = sprintf('%s [%s] : %s', $log_prefix, $filename, $status);
            if ($message !== '') {
                $log_message .= ' - ' . $message;
            }

            if ($status === 'failed') {
                BJLG_Debug::log('ERREUR : ' . $log_message);
            } else {
                BJLG_Debug::log($log_message);
            }

            BJLG_History::log('backup_post_check', $history_status, $log_message);

            if ($status === 'failed') {
                $results['overall_status'] = 'failed';
                if ($message !== '') {
                    $results['overall_message'] = $message;
                } else {
                    $results['overall_message'] = sprintf('La vérification du fichier %s a échoué.', $filename);
                }
            }
        };

        if ($encrypted) {
            if (!empty($post_checks['dry_run'])) {
                BJLG_Debug::log('Vérification de restauration ignorée pour une archive chiffrée.');
                $results['dry_run'] = 'skipped';
            }

            $results['overall_status'] = 'skipped';
            $results['overall_message'] = 'Archive chiffrée : vérifications impossibles.';

            $skip_message = 'Archive chiffrée : vérification impossible.';
            foreach (['backup-manifest.json', 'database.sql'] as $filename) {
                $log_file_check($filename, 'skipped', $skip_message);
            }

            return $results;
        }

        $zip = $this->create_zip_archive();
        $open_result = $zip->open($filepath);
        if ($open_result !== true) {
            throw new Exception('La vérification post-sauvegarde a échoué : ' . $this->describe_zip_error($open_result));
        }

        try {
            if (!empty($post_checks['dry_run'])) {
                if ($zip->numFiles < 1) {
                    throw new Exception('La vérification de restauration a échoué : archive vide.');
                }

                BJLG_Debug::log('Vérification de restauration réussie pour ' . basename($filepath));
                $results['dry_run'] = 'passed';
            }

            $manifest_data = null;
            $manifest_stat = $zip->statName('backup-manifest.json', ZipArchive::FL_UNCHANGED);

            if ($manifest_stat === false) {
                $log_file_check('backup-manifest.json', 'failed', "Fichier introuvable dans l'archive.");
            } else {
                $manifest_content = $zip->getFromName('backup-manifest.json');
                $expected_size = isset($manifest_stat['size']) ? (int) $manifest_stat['size'] : null;
                $read_size = is_string($manifest_content) ? strlen($manifest_content) : 0;

                if ($manifest_content === false || $read_size === 0) {
                    $log_file_check('backup-manifest.json', 'failed', 'Impossible de lire le manifeste de la sauvegarde.', [
                        'expected_size' => $expected_size,
                        'read_size' => $read_size,
                    ]);
                } elseif ($expected_size !== null && $expected_size !== $read_size) {
                    $log_file_check('backup-manifest.json', 'failed', sprintf(
                        'Taille lue (%d) différente de la taille attendue (%d).',
                        $read_size,
                        $expected_size
                    ), [
                        'expected_size' => $expected_size,
                        'read_size' => $read_size,
                    ]);
                } else {
                    $crc_valid = true;
                    if (isset($manifest_stat['crc'])) {
                        $expected_crc = sprintf('%u', $manifest_stat['crc']);
                        $calculated_crc = sprintf('%u', crc32($manifest_content));
                        $crc_valid = $expected_crc === $calculated_crc;
                    }

                    $decoded_manifest = json_decode($manifest_content, true);

                    if (!$crc_valid) {
                        $log_file_check('backup-manifest.json', 'failed', 'Somme de contrôle invalide pour le manifeste.', [
                            'expected_size' => $expected_size,
                            'read_size' => $read_size,
                        ]);
                    } elseif (!is_array($decoded_manifest)) {
                        $log_file_check('backup-manifest.json', 'failed', 'JSON du manifeste invalide.', [
                            'expected_size' => $expected_size,
                            'read_size' => $read_size,
                        ]);
                    } else {
                        $manifest_data = $decoded_manifest;
                        $log_file_check('backup-manifest.json', 'passed', 'Manifeste valide.', [
                            'expected_size' => $expected_size,
                            'read_size' => $read_size,
                        ]);
                    }
                }
            }

            $manifest_contains = [];
            if (is_array($manifest_data) && isset($manifest_data['contains']) && is_array($manifest_data['contains'])) {
                $manifest_contains = $manifest_data['contains'];
            }

            $expects_database = in_array('db', $manifest_contains, true);
            $sql_stat = $zip->statName('database.sql', ZipArchive::FL_UNCHANGED);

            if ($sql_stat === false) {
                if ($expects_database) {
                    $log_file_check('database.sql', 'failed', "Fichier introuvable alors que le manifeste annonce la base de données.");
                } else {
                    $log_file_check('database.sql', 'skipped', 'Aucun export de base de données attendu.');
                }
            } else {
                $sql_content = $zip->getFromName('database.sql');
                $expected_size = isset($sql_stat['size']) ? (int) $sql_stat['size'] : null;
                $read_size = is_string($sql_content) ? strlen($sql_content) : 0;

                if ($sql_content === false || $read_size === 0) {
                    $log_file_check('database.sql', 'failed', 'Impossible de lire le dump SQL.', [
                        'expected_size' => $expected_size,
                        'read_size' => $read_size,
                    ]);
                } elseif ($expected_size !== null && $expected_size !== $read_size) {
                    $log_file_check('database.sql', 'failed', sprintf(
                        'Taille lue (%d) différente de la taille attendue (%d).',
                        $read_size,
                        $expected_size
                    ), [
                        'expected_size' => $expected_size,
                        'read_size' => $read_size,
                    ]);
                } else {
                    $crc_valid = true;
                    if (isset($sql_stat['crc'])) {
                        $expected_crc = sprintf('%u', $sql_stat['crc']);
                        $calculated_crc = sprintf('%u', crc32($sql_content));
                        $crc_valid = $expected_crc === $calculated_crc;
                    }

                    if (!$crc_valid) {
                        $log_file_check('database.sql', 'failed', 'Somme de contrôle invalide pour le dump SQL.', [
                            'expected_size' => $expected_size,
                            'read_size' => $read_size,
                        ]);
                    } elseif (strpos($sql_content, "\0") !== false) {
                        $log_file_check('database.sql', 'failed', 'Contenu SQL invalide (caractères binaires détectés).', [
                            'expected_size' => $expected_size,
                            'read_size' => $read_size,
                        ]);
                    } else {
                        $log_file_check('database.sql', 'passed', 'Dump SQL valide.', [
                            'expected_size' => $expected_size,
                            'read_size' => $read_size,
                        ]);
                    }
                }
            }
        } finally {
            $zip->close();
        }

        return $results;
    }

    private function dispatch_to_destinations($filepath, array $destinations, $task_id, array $batches = []) {
        $results = [
            'success' => [],
            'failures' => [],
        ];

        if (empty($destinations)) {
            return $results;
        }

        if (!empty($batches)) {
            $sanitized_batches = BJLG_Settings::sanitize_destination_batches(
                $batches,
                BJLG_Settings::get_known_destination_ids()
            );
            if (!empty($sanitized_batches)) {
                $ordered = BJLG_Settings::flatten_destination_batches($sanitized_batches);
                if (!empty($ordered)) {
                    $destinations = $ordered;
                }
            }
        }

        foreach ($destinations as $destination_id) {
            $destination = $this->instantiate_destination($destination_id);

            if (!$destination instanceof BJLG_Destination_Interface) {
                $message = sprintf('Destination "%s" indisponible.', $destination_id);
                BJLG_Debug::log($message);
                BJLG_History::log('backup_upload', 'failure', $message);
                $results['failures'][$destination_id] = $message;
                continue;
            }

            try {
                $destination->upload_file($filepath, $task_id);
                $results['success'][] = $destination_id;
                BJLG_Debug::log(sprintf('Sauvegarde envoyée vers %s.', $destination->get_name()));
                BJLG_History::log('backup_upload', 'success', sprintf('Sauvegarde envoyée vers %s.', $destination->get_name()));
            } catch (Exception $exception) {
                $error_message = sprintf('Envoi vers %s échoué : %s', $destination->get_name(), $exception->getMessage());
                BJLG_Debug::log('ERREUR : ' . $error_message);
                BJLG_History::log('backup_upload', 'failure', $error_message);
                $results['failures'][$destination_id] = $exception->getMessage();
            }
        }

        return $results;
    }

    private function instantiate_destination($destination_id) {
        $provided = apply_filters('bjlg_backup_instantiate_destination', null, $destination_id);
        if ($provided instanceof BJLG_Destination_Interface) {
            return $provided;
        }

        return BJLG_Destination_Factory::create($destination_id);
    }

    /**
     * Vérifie si un chemin correspond à un motif en utilisant fnmatch ou un mécanisme de repli.
     *
     * @param string $pattern
     * @param string $path
     * @return bool
     */
    private function path_matches_pattern($pattern, $path) {
        if (!is_string($pattern) || $pattern === '' || !is_string($path) || $path === '') {
            return false;
        }

        $namespaced_fnmatch = __NAMESPACE__ . '\\fnmatch';

        if (function_exists($namespaced_fnmatch)) {
            return (bool) call_user_func($namespaced_fnmatch, $pattern, $path);
        }

        if (function_exists('fnmatch')) {
            return (bool) fnmatch($pattern, $path);
        }

        $regex = $this->convert_glob_to_regex($pattern);

        if ($regex === null) {
            return false;
        }

        return (bool) preg_match($regex, $path);
    }

    /**
     * Convertit un motif de type glob en expression régulière.
     *
     * @param string $pattern
     * @return string|null
     */
    private function convert_glob_to_regex($pattern) {
        if (!is_string($pattern) || $pattern === '') {
            return null;
        }

        $quoted = preg_quote($pattern, '/');

        $replacements = [
            '\\*' => '.*',
            '\\?' => '.',
            '\\[!' => '[^',
            '\\[' => '[',
            '\\]' => ']',
        ];

        $regex = strtr($quoted, $replacements);

        return '/^' . $regex . '$/';
    }

    /**
     * Normalise un chemin de fichier pour les comparaisons.
     *
     * @param string $path
     * @return string
     */
    private function normalize_path($path) {
        if (!is_string($path) || $path === '') {
            return '';
        }

        if (function_exists('wp_normalize_path')) {
            return wp_normalize_path($path);
        }

        return str_replace('\\', '/', $path);
    }

    /**
     * Met à jour la progression de la tâche
     */
    private function update_task_progress($task_id, $progress, $status, $status_text) {
        $task_data = get_transient($task_id);
        if ($task_data) {
            if (is_numeric($progress)) {
                $progress = round((float) $progress, 1);

                if (abs($progress - round($progress)) < 0.0001) {
                    $progress = (int) round($progress);
                }
            }

            $task_data['progress'] = $progress;
            $task_data['status'] = $status;
            $task_data['status_text'] = $status_text;
            self::save_task_state($task_id, $task_data);

            $display_progress = is_numeric($progress) ? $progress . '%' : (string) $progress;
            $clean_text = is_string($status_text) ? $status_text : '';
            if (function_exists('wp_strip_all_tags')) {
                $clean_text = wp_strip_all_tags($clean_text);
            }
            if ($clean_text === '') {
                $clean_text = '(aucun message)';
            }

            BJLG_Debug::debug(sprintf(
                'Tâche %s -> progression %s | statut %s | message : %s',
                $task_id,
                $display_progress,
                $status,
                $clean_text
            ));
        }
    }

    /**
     * Exporte la base de données (méthode publique pour la sauvegarde pré-restauration)
     */
    public function dump_database($filepath) {
        global $wpdb;
        
        $handle = fopen($filepath, 'w');
        if (!$handle) {
            throw new Exception("Impossible de créer le fichier SQL");
        }
        
        // Header
        fwrite($handle, "-- Backup JLG Database Dump\n");
        fwrite($handle, "-- Date: " . date('Y-m-d H:i:s') . "\n\n");
        fwrite($handle, "SET NAMES utf8mb4;\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");
        
        // Tables
        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
        
        foreach ($tables as $table_array) {
            $table = $table_array[0];
            
            // Structure
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($handle, $create[1] . ";\n\n");
            
            // Données
            $row_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            
            if ($row_count > 0) {
                $batch_size = 1000;
                
                for ($offset = 0; $offset < $row_count; $offset += $batch_size) {
                    $rows = $wpdb->get_results(
                        "SELECT * FROM `{$table}` LIMIT {$offset}, {$batch_size}",
                        ARRAY_A
                    );
                    
                    if ($rows) {
                        $this->write_insert_statement($handle, $table, $rows);
                    }
                }
            }
        }
        
        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }
}