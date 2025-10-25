<?php
namespace BJLG;

use Exception;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use UnexpectedValueException;
use ZipArchive;

if (!defined('ABSPATH')) {
    exit;
}

if (function_exists('add_action')) {
    add_action('init', [\BJLG\BJLG_Backup::class, 'bootstrap_realtime_capture'], 3);
}

/**
 * Exception dédiée au contrôle d'espace disque avant sauvegarde.
 */
class BJLG_DiskSpaceException extends Exception {
    /** @var array<string, int|null> */
    private $context = [];

    /**
     * @param string                     $message
     * @param array<string, int|null>    $context
     * @param int                        $code
     * @param Exception|null             $previous
     */
    public function __construct($message, array $context = [], $code = 0, ?Exception $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Retourne les métadonnées associées à l'erreur.
     *
     * @return array<string, int|null>
     */
    public function get_context() {
        return $this->context;
    }
}

/**
 * Gère le processus complet de création de sauvegardes
 */
class BJLG_Backup {

    /**
     * Ratio de marge de sécurité appliqué à l'estimation de la sauvegarde.
     */
    private const DISK_SPACE_MARGIN_RATIO = 0.2;

    /**
     * Marge minimale ajoutée à toute sauvegarde, en bytes.
     */
    private const DISK_SPACE_MIN_MARGIN_BYTES = 100 * 1024 * 1024;

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

    private const REALTIME_BUFFER_LIMIT = 10;
    private const REALTIME_DEFAULT_BATCH_WINDOW = 60;
    private const REALTIME_DEFAULT_MAX_BATCH = 10;

    /**
     * Tampon des événements quasi temps réel détectés durant la requête courante.
     *
     * @var array<string, array<string, mixed>>
     */
    private static $realtime_buffer = [];

    /**
     * Mémorisation du dernier flush par canal afin d'éviter les rejets en rafale.
     *
     * @var array<string, int>
     */
    private static $realtime_last_flush = [];

    /**
     * Cache des réglages de déclencheurs événementiels pour éviter les accès répétés.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private static $realtime_settings_cache = null;

    /**
     * Indicateur d'initialisation des hooks de capture quasi temps réel.
     *
     * @var bool
     */
    private static $realtime_initialized = false;

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

    public static function bootstrap_realtime_capture(): void
    {
        if (self::$realtime_initialized) {
            return;
        }

        self::$realtime_initialized = true;

        if (function_exists('add_action')) {
            add_action('shutdown', [__CLASS__, 'flush_realtime_events'], 1);
        }
    }

    public static function record_realtime_change(string $source, array $payload = [], array $context = []): void
    {
        self::bootstrap_realtime_capture();

        $source_key = self::normalize_realtime_source($source);
        if ($source_key === '') {
            return;
        }

        $now = function_exists('current_time') ? (int) current_time('timestamp') : time();

        $normalized_payload = self::normalize_realtime_payload($source_key, $payload, $context);

        if (!isset(self::$realtime_buffer[$source_key])) {
            self::$realtime_buffer[$source_key] = [
                'first_seen' => $now,
                'last_seen' => $now,
                'count' => 0,
                'payloads' => [],
            ];
        }

        self::$realtime_buffer[$source_key]['last_seen'] = $now;
        self::$realtime_buffer[$source_key]['count'] = (int) self::$realtime_buffer[$source_key]['count'] + 1;

        if (!empty($normalized_payload)) {
            $samples = isset(self::$realtime_buffer[$source_key]['payloads'])
                ? (array) self::$realtime_buffer[$source_key]['payloads']
                : [];
            $samples[] = $normalized_payload;

            if (count($samples) > self::REALTIME_BUFFER_LIMIT) {
                $samples = array_slice($samples, -self::REALTIME_BUFFER_LIMIT);
            }

            self::$realtime_buffer[$source_key]['payloads'] = $samples;
        }

        $settings = self::get_realtime_trigger_settings($source_key);
        if (self::should_flush_buffer($source_key, self::$realtime_buffer[$source_key], $settings, $now)) {
            self::flush_realtime_source($source_key);
        }
    }

    public static function flush_realtime_events(): void
    {
        if (empty(self::$realtime_buffer)) {
            return;
        }

        foreach (array_keys(self::$realtime_buffer) as $source_key) {
            self::flush_realtime_source($source_key, true);
        }
    }

    private static function normalize_realtime_source($source): string
    {
        if (is_array($source) && isset($source['key'])) {
            $source = $source['key'];
        }

        $source_key = is_string($source) ? $source : (string) $source;
        $source_key = trim(strtolower($source_key));

        if ($source_key === '') {
            return '';
        }

        switch ($source_key) {
            case 'fs':
            case 'watcher':
            case 'filesystem_inotify':
            case 'inotify':
                return 'inotify';
            case 'webhook':
            case 'application':
            case 'app':
            case 'application_webhook':
            case 'business_event':
                return 'application_webhook';
            case 'binlog':
            case 'mysql':
            case 'database_binlog':
            case 'db_binlog':
                return 'binlog';
            case 'db':
            case 'database':
                return 'database';
            case 'files':
            case 'filesystem':
                return 'filesystem';
            default:
                if (function_exists('sanitize_key')) {
                    return sanitize_key($source_key);
                }

                return preg_replace('/[^a-z0-9_\-]/', '', $source_key);
        }
    }

    private static function normalize_realtime_payload(string $source_key, array $payload, array $context = []): array
    {
        $normalized = [
            'channel' => $source_key,
        ];

        $added = 0;
        foreach ($payload as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $normalized_key = self::sanitize_realtime_key($key);
            if ($normalized_key === '') {
                continue;
            }

            $normalized[$normalized_key] = (string) $value;
            $added++;

            if ($added >= 5) {
                break;
            }
        }

        foreach ($context as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $context_key = self::sanitize_realtime_key('ctx_' . $key);
            if ($context_key === '') {
                continue;
            }

            $normalized[$context_key] = (string) $value;
        }

        return $normalized;
    }

    private static function sanitize_realtime_key($key): string
    {
        $key = is_string($key) ? $key : (string) $key;

        if (function_exists('sanitize_key')) {
            $key = sanitize_key($key);
        } else {
            $key = preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
        }

        return substr($key, 0, 40);
    }

    private static function get_realtime_trigger_settings(string $source_key): array
    {
        if (self::$realtime_settings_cache === null) {
            if (!class_exists(__NAMESPACE__ . '\\BJLG_Scheduler')) {
                self::$realtime_settings_cache = [];
            } else {
                try {
                    $scheduler = BJLG_Scheduler::instance();
                    $settings = $scheduler->get_event_trigger_settings();
                    self::$realtime_settings_cache = isset($settings['triggers']) && is_array($settings['triggers'])
                        ? $settings['triggers']
                        : [];
                } catch (Throwable $throwable) {
                    self::$realtime_settings_cache = [];
                    if (class_exists(__NAMESPACE__ . '\\BJLG_Debug')) {
                        BJLG_Debug::log('[Realtime capture] ' . $throwable->getMessage(), 'error');
                    }
                }
            }
        }

        return self::$realtime_settings_cache[$source_key] ?? [
            'max_batch' => self::REALTIME_DEFAULT_MAX_BATCH,
            'batch_window' => self::REALTIME_DEFAULT_BATCH_WINDOW,
            'cooldown' => 0,
        ];
    }

    private static function should_flush_buffer(string $source_key, array $bucket, array $settings, int $now): bool
    {
        $count = isset($bucket['count']) ? (int) $bucket['count'] : 0;
        $max_batch = max(1, (int) ($settings['max_batch'] ?? self::REALTIME_DEFAULT_MAX_BATCH));
        if ($count >= $max_batch) {
            return true;
        }

        $first_seen = isset($bucket['first_seen']) ? (int) $bucket['first_seen'] : $now;
        $window = max(0, (int) ($settings['batch_window'] ?? self::REALTIME_DEFAULT_BATCH_WINDOW));
        if ($window === 0) {
            return true;
        }

        if (($now - $first_seen) >= $window) {
            return true;
        }

        $last_flush = isset(self::$realtime_last_flush[$source_key]) ? (int) self::$realtime_last_flush[$source_key] : 0;
        $cooldown = max(0, (int) ($settings['cooldown'] ?? 0));

        return $cooldown > 0 && ($now - $last_flush) >= $cooldown;
    }

    private static function flush_realtime_source(string $source_key, bool $force = false): void
    {
        if (!isset(self::$realtime_buffer[$source_key])) {
            return;
        }

        $bucket = self::$realtime_buffer[$source_key];
        if (!$force) {
            $settings = self::get_realtime_trigger_settings($source_key);
            $now = function_exists('current_time') ? (int) current_time('timestamp') : time();
            if (!self::should_flush_buffer($source_key, $bucket, $settings, $now)) {
                return;
            }
        }

        unset(self::$realtime_buffer[$source_key]);
        self::$realtime_last_flush[$source_key] = function_exists('current_time') ? (int) current_time('timestamp') : time();

        if (!class_exists(__NAMESPACE__ . '\\BJLG_Scheduler')) {
            return;
        }

        $payload = self::summarize_bucket_for_scheduler($source_key, $bucket);

        try {
            BJLG_Scheduler::instance()->handle_event_trigger($source_key, $payload);
        } catch (Throwable $throwable) {
            if (class_exists(__NAMESPACE__ . '\\BJLG_Debug')) {
                BJLG_Debug::log('[Realtime capture] ' . $throwable->getMessage(), 'error');
            }
        }
    }

    private static function summarize_bucket_for_scheduler(string $source_key, array $bucket): array
    {
        $now = function_exists('current_time') ? (int) current_time('timestamp') : time();
        $first_seen = isset($bucket['first_seen']) ? (int) $bucket['first_seen'] : $now;
        $last_seen = isset($bucket['last_seen']) ? (int) $bucket['last_seen'] : $first_seen;
        $count = isset($bucket['count']) ? (int) $bucket['count'] : 0;

        $payload = [
            'channel' => $source_key,
            'batch_count' => $count,
            'first_seen' => $first_seen,
            'last_seen' => $last_seen,
        ];

        $samples = isset($bucket['payloads']) && is_array($bucket['payloads']) ? $bucket['payloads'] : [];
        if (!empty($samples)) {
            $sample = (array) $samples[count($samples) - 1];
            $added = 0;
            foreach ($sample as $key => $value) {
                if (!is_scalar($value)) {
                    continue;
                }

                $normalized_key = self::sanitize_realtime_key('sample_' . $key);
                if ($normalized_key === '') {
                    continue;
                }

                $payload[$normalized_key] = (string) $value;
                $added++;

                if ($added >= 4) {
                    break;
                }
            }
        }

        return $payload;
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

        if (function_exists('set_transient')) {
            BJLG_Debug::warning(
                "Impossible d'acquérir le verrou de sauvegarde : aucune API atomique n'est disponible pour garantir l'exclusivité."
            );
        }

        $existing_payload = self::get_lock_payload();

        if ($existing_payload === null || (int) $existing_payload['expires_at'] <= $now) {
            return false;
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

        try {
            $this->ensure_backup_directory_is_ready();
            $disk_assessment = $this->assert_sufficient_disk_space($task_data);
            $task_data['disk_space_check'] = $disk_assessment;
        } catch (BJLG_DiskSpaceException $disk_exception) {
            $context = $disk_exception->get_context();
            if (!is_array($context)) {
                $context = [];
            }

            $task_data['disk_space_check'] = array_merge($context, ['status' => 'insufficient']);

            BJLG_Debug::log("Tâche $task_id interrompue : " . $disk_exception->getMessage());
            BJLG_History::log('backup_created', 'failure', $disk_exception->getMessage());

            wp_send_json_error([
                'message' => $disk_exception->getMessage(),
                'code' => 'bjlg_disk_space_insufficient',
                'details' => $context,
            ], 507);
        } catch (Exception $exception) {
            BJLG_Debug::log('ERREUR : ' . $exception->getMessage());
            BJLG_History::log('backup_created', 'failure', $exception->getMessage());

            wp_send_json_error([
                'message' => $exception->getMessage(),
                'code' => 'bjlg_backup_initialization_failed',
            ], 500);
        }

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

            $this->ensure_backup_directory_is_ready();

            try {
                $disk_assessment = $this->assert_sufficient_disk_space($task_data);
                $task_data['disk_space_check'] = $disk_assessment;
                self::save_task_state($task_id, $task_data);
            } catch (BJLG_DiskSpaceException $disk_exception) {
                $context = $disk_exception->get_context();
                if (!is_array($context)) {
                    $context = [];
                }

                $task_data['disk_space_check'] = array_merge($context, ['status' => 'insufficient']);
                self::save_task_state($task_id, $task_data);

                BJLG_Debug::log('ERREUR dans la sauvegarde : ' . $disk_exception->getMessage());
                BJLG_History::log('backup_created', 'failure', $disk_exception->getMessage());

                do_action('bjlg_backup_failed', $disk_exception->getMessage(), [
                    'task_id' => $task_id,
                    'components' => $components,
                ]);

                $this->update_task_progress($task_id, 100, 'error', $disk_exception->getMessage());
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

            $backup_filename = $this->generate_backup_filename($backup_type, $components);
            $backup_filepath = bjlg_get_backup_directory() . $backup_filename;
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
                $task_data['secondary_destination_batches'] ?? [],
                $task_data
            );

            if (isset($destination_results['resume_state']['managed_vault'])) {
                $managed_resume = $destination_results['resume_state']['managed_vault'];
                if (empty($managed_resume)) {
                    if (isset($task_data['managed_vault_resume'])) {
                        unset($task_data['managed_vault_resume']);
                        self::save_task_state($task_id, $task_data);
                    }
                } elseif (is_array($managed_resume)) {
                    $task_data['managed_vault_resume'] = $managed_resume;
                    self::save_task_state($task_id, $task_data);
                }
            }

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
            $completion_timestamp = time();
            $start_time = isset($task_data['start_time']) ? (int) $task_data['start_time'] : $completion_timestamp;
            $duration = max(0, $completion_timestamp - $start_time);

            // Enregistrer le succès
            $post_check_history = $this->format_post_check_history_summary($check_results);
            $destination_history = $this->format_destination_history_summary($destination_results, $destination_queue);

            $history_message = sprintf(
                /* translators: 1: backup file name, 2: file size, 3: duration in seconds, 4: yes/no. */
                __('Fichier : %1$s | Taille : %2$s | Durée : %3$ds | Chiffrement : %4$s', 'backup-jlg'),
                $backup_filename,
                size_format($file_size),
                $duration,
                $effective_encryption ? __('oui', 'backup-jlg') : __('non', 'backup-jlg')
            );

            if ($post_check_history !== '') {
                $history_message .= ' | ' . sprintf(
                    /* translators: %s: post-backup verification summary. */
                    __('Vérifications : %s', 'backup-jlg'),
                    $post_check_history
                );
            }

            if ($destination_history !== '') {
                $history_message .= ' | ' . sprintf(
                    /* translators: %s: destination delivery summary. */
                    __('Destinations : %s', 'backup-jlg'),
                    $destination_history
                );
            }

            $metrics = $this->build_backup_metrics([
                'start_time' => $start_time,
                'completed_at' => $completion_timestamp,
                'file_size' => $file_size,
                'components' => $components,
                'destination_queue' => $destination_queue,
                'destination_results' => $destination_results,
                'check_results' => $check_results,
                'post_check_summary' => $post_check_history,
                'post_check_message' => $check_results['overall_message'] ?? '',
                'destination_notice' => $destination_failure_notice,
                'encryption' => $effective_encryption,
                'requested_encryption' => $requested_encryption,
                'incremental' => $task_data['incremental'],
                'backup_filename' => $backup_filename,
                'backup_path' => $backup_filepath,
            ]);

            $history_metadata = [
                'metrics' => $metrics,
                'task_id' => $task_id,
                'backup' => [
                    'filename' => $backup_filename,
                    'path' => $backup_filepath,
                ],
                'destinations' => [
                    'failures' => $destination_results['failures'],
                    'delivered' => $metrics['destinations']['delivered'],
                ],
                'post_checks' => [
                    'overall_status' => $check_results['overall_status'] ?? '',
                    'summary' => $post_check_history,
                ],
            ];

            if ($destination_failure_notice !== '') {
                $history_metadata['warnings'][] = $destination_failure_notice;
            }

            BJLG_History::log('backup_created', 'success', $history_message, null, null, $history_metadata);

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
            $manifest_details['post_checks_summary'] = $post_check_history;
            $manifest_details['destinations'] = $destination_queue;
            $manifest_details['destination_results'] = $destination_results;
            $manifest_details['metrics'] = $metrics;

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
        if (!is_dir(bjlg_get_backup_directory())) {
            if (!wp_mkdir_p(bjlg_get_backup_directory())) {
                throw new Exception("Le dossier de sauvegarde est introuvable et n'a pas pu être créé.");
            }
        }

        $is_writable = function_exists('wp_is_writable') ? wp_is_writable(bjlg_get_backup_directory()) : is_writable(bjlg_get_backup_directory());

        if (!$is_writable) {
            throw new Exception("Le dossier de sauvegarde n'est pas accessible en écriture.");
        }
    }

    /**
     * Vérifie que l'espace disque disponible est suffisant pour la sauvegarde à venir.
     *
     * @param array<string, mixed> $task_data
     * @return array<string, int|string|null>
     * @throws BJLG_DiskSpaceException
     */
    private function assert_sufficient_disk_space(array $task_data) {
        $components = isset($task_data['components']) ? (array) $task_data['components'] : [];
        $components = array_filter(array_map('sanitize_key', $components));

        if (empty($components)) {
            return [
                'estimated_bytes' => 0,
                'margin_bytes' => 0,
                'required_bytes' => 0,
                'free_bytes' => null,
                'checked_at' => time(),
                'status' => 'skipped',
                'target_path' => bjlg_get_backup_directory(),
            ];
        }

        $estimated_bytes = $this->estimate_backup_size_bytes($components, $task_data);

        $margin_ratio = self::DISK_SPACE_MARGIN_RATIO;
        if (function_exists('apply_filters')) {
            $filtered_ratio = apply_filters('bjlg_disk_space_margin_ratio', $margin_ratio, $task_data, $components, $estimated_bytes);
            if (is_numeric($filtered_ratio) && (float) $filtered_ratio >= 0) {
                $margin_ratio = (float) $filtered_ratio;
            }
        }

        $margin_bytes = (int) round($estimated_bytes * $margin_ratio);
        $minimum_margin = self::DISK_SPACE_MIN_MARGIN_BYTES;
        if (function_exists('apply_filters')) {
            $filtered_min_margin = apply_filters('bjlg_disk_space_margin_min_bytes', $minimum_margin, $task_data, $components, $estimated_bytes);
            if (is_numeric($filtered_min_margin) && (int) $filtered_min_margin >= 0) {
                $minimum_margin = (int) $filtered_min_margin;
            }
        }

        if ($margin_bytes < $minimum_margin) {
            $margin_bytes = $minimum_margin;
        }

        $required_bytes = $estimated_bytes + $margin_bytes;

        $disk_probe_path = bjlg_get_backup_directory();
        if (!is_dir($disk_probe_path)) {
            $probe_parent = dirname($disk_probe_path);
            if (is_dir($probe_parent)) {
                $disk_probe_path = $probe_parent;
            } elseif (defined('WP_CONTENT_DIR') && is_dir(WP_CONTENT_DIR)) {
                $disk_probe_path = WP_CONTENT_DIR;
            } else {
                $disk_probe_path = defined('ABSPATH') ? ABSPATH : sys_get_temp_dir();
            }
        }

        $free_bytes_raw = @disk_free_space($disk_probe_path);
        $free_bytes = ($free_bytes_raw === false) ? null : (int) $free_bytes_raw;

        $snapshot = [
            'estimated_bytes' => $estimated_bytes,
            'margin_bytes' => $margin_bytes,
            'required_bytes' => $required_bytes,
            'free_bytes' => $free_bytes,
            'checked_at' => time(),
            'status' => $free_bytes === null ? 'unknown' : 'ok',
            'target_path' => $disk_probe_path,
        ];

        if ($free_bytes !== null && $free_bytes < $required_bytes) {
            $message = sprintf(
                "Espace disque insuffisant : %s requis (estimation %s + marge %s), %s disponibles.",
                $this->format_bytes($required_bytes),
                $this->format_bytes($estimated_bytes),
                $this->format_bytes($margin_bytes),
                $this->format_bytes($free_bytes)
            );

            BJLG_Debug::log('ERREUR : ' . $message);
            BJLG_History::log('backup_disk_space', 'failure', $message);

            $snapshot['status'] = 'insufficient';

            throw new BJLG_DiskSpaceException($message, $snapshot);
        }

        if ($free_bytes === null) {
            BJLG_Debug::log("Impossible de déterminer l'espace disque libre pour {$disk_probe_path}. Contrôle préventif ignoré.");
        } else {
            BJLG_Debug::log(sprintf(
                'Contrôle espace disque OK : %s requis, %s disponibles (%s de marge).',
                $this->format_bytes($required_bytes),
                $this->format_bytes($free_bytes),
                $this->format_bytes($margin_bytes)
            ));
        }

        return $snapshot;
    }

    /**
     * Estime la taille totale de la sauvegarde selon les composants sélectionnés.
     *
     * @param array<int, string> $components
     * @param array<string, mixed> $task_data
     * @return int
     */
    private function estimate_backup_size_bytes(array $components, array $task_data) {
        $total = 0;
        $include_patterns = isset($task_data['include_patterns']) && is_array($task_data['include_patterns'])
            ? $task_data['include_patterns']
            : [];
        $exclude_overrides = isset($task_data['exclude_patterns']) && is_array($task_data['exclude_patterns'])
            ? $task_data['exclude_patterns']
            : [];
        $incremental = !empty($task_data['incremental']);

        $incremental_handler = null;
        if ($incremental && class_exists(BJLG_Incremental::class)) {
            $incremental_handler = BJLG_Incremental::get_latest_instance();
            if (!$incremental_handler) {
                $incremental_handler = new BJLG_Incremental();
            }
        }

        foreach ($components as $component) {
            switch ($component) {
                case 'db':
                    $total += $this->estimate_database_size_bytes();
                    break;
                case 'plugins':
                    if (defined('WP_PLUGIN_DIR')) {
                        $total += $this->estimate_directory_component_size(
                            WP_PLUGIN_DIR,
                            'wp-content/plugins/',
                            $include_patterns,
                            $exclude_overrides,
                            $incremental,
                            $incremental_handler
                        );
                    }
                    break;
                case 'themes':
                    $theme_root = function_exists('get_theme_root') ? get_theme_root() : null;
                    if (is_string($theme_root) && $theme_root !== '') {
                        $total += $this->estimate_directory_component_size(
                            $theme_root,
                            'wp-content/themes/',
                            $include_patterns,
                            $exclude_overrides,
                            $incremental,
                            $incremental_handler
                        );
                    }
                    break;
                case 'uploads':
                    $upload_dir = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : null;
                    if (is_array($upload_dir) && !empty($upload_dir['basedir'])) {
                        $total += $this->estimate_directory_component_size(
                            $upload_dir['basedir'],
                            'wp-content/uploads/',
                            $include_patterns,
                            $exclude_overrides,
                            $incremental,
                            $incremental_handler
                        );
                    }
                    break;
            }
        }

        if (function_exists('apply_filters')) {
            $filtered_total = apply_filters('bjlg_estimated_backup_size', $total, $components, $task_data);
            if (is_numeric($filtered_total) && (int) $filtered_total >= 0) {
                $total = (int) $filtered_total;
            }
        }

        return max(0, (int) $total);
    }

    /**
     * Estime la taille d'un composant de type répertoire.
     *
     * @param string $source_dir
     * @param string $zip_path
     * @param array<int, string> $include_patterns
     * @param array<int, string> $exclude_overrides
     * @param bool $incremental
     * @param BJLG_Incremental|null $incremental_handler
     * @return int
     */
    private function estimate_directory_component_size(
        $source_dir,
        $zip_path,
        array $include_patterns,
        array $exclude_overrides,
        $incremental,
        $incremental_handler
    ) {
        if (!is_string($source_dir) || $source_dir === '' || !is_dir($source_dir)) {
            return 0;
        }

        $exclude_patterns = $this->get_exclude_patterns($source_dir, $zip_path, $exclude_overrides);

        if ($incremental && $incremental_handler instanceof BJLG_Incremental) {
            try {
                $scan = $incremental_handler->get_modified_files($source_dir);
            } catch (Exception $exception) {
                BJLG_Debug::log('Impossible d\'estimer les changements incrémentaux : ' . $exception->getMessage());
                $scan = [];
            }

            $modified_files = is_array($scan['modified'] ?? null) ? $scan['modified'] : [];
            if (empty($modified_files)) {
                return 0;
            }

            $total = 0;
            foreach ($modified_files as $file) {
                if (!is_string($file) || $file === '' || !file_exists($file)) {
                    continue;
                }

                $normalized = $this->normalize_path($file);
                if ($normalized === '') {
                    continue;
                }

                if ($this->path_matches_any($normalized, $exclude_patterns)) {
                    continue;
                }

                if (!$this->should_include_file($normalized, $include_patterns)) {
                    continue;
                }

                $size = @filesize($file);
                if (is_numeric($size) && (int) $size > 0) {
                    $total += (int) $size;
                }
            }

            return $total;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source_dir, FilesystemIterator::SKIP_DOTS)
            );
        } catch (UnexpectedValueException $exception) {
            BJLG_Debug::log('Impossible de parcourir le répertoire ' . $source_dir . ' : ' . $exception->getMessage());
            return 0;
        }

        $total = 0;
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile() || $fileinfo->isLink()) {
                continue;
            }

            $file_path = $fileinfo->getPathname();
            $normalized = $this->normalize_path($file_path);

            if ($normalized === '') {
                continue;
            }

            if ($this->path_matches_any($normalized, $exclude_patterns)) {
                continue;
            }

            if (!$this->should_include_file($normalized, $include_patterns)) {
                continue;
            }

            $total += (int) $fileinfo->getSize();
        }

        return $total;
    }

    /**
     * Estime la taille de la base de données.
     *
     * @return int
     */
    private function estimate_database_size_bytes() {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return 0;
        }

        try {
            $size = $wpdb->get_var(
                "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "'"
            );
        } catch (Exception $exception) {
            BJLG_Debug::log('Impossible d\'estimer la taille de la base de données : ' . $exception->getMessage());
            $size = 0;
        }

        if (!is_numeric($size) || (float) $size < 0) {
            return 0;
        }

        return (int) $size;
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
            $filepath = bjlg_get_backup_directory() . $filename;
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
            $stored_patterns = \bjlg_get_option('bjlg_backup_exclude_patterns', []);
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
            } elseif (defined('WP_CONTENT_DIR')) {
                $content_dir = $this->normalize_path(WP_CONTENT_DIR);
                if ($content_dir !== '' && strpos($normalized, rtrim($content_dir, '/') . '/') === 0) {
                    $subpath = ltrim(substr($normalized, strlen($content_dir)), '/');
                    $relative = $subpath !== '' ? 'wp-content/' . $subpath : 'wp-content';
                }
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

    /**
     * Résout le mot de passe à utiliser pour les vérifications post-sauvegarde d'un fichier chiffré.
     *
     * @param string $filepath
     * @param array<string,mixed> $post_checks
     * @return string|null
     */
    private function resolve_post_backup_checks_password($filepath, array $post_checks) {
        $password = null;

        if (isset($post_checks['encryption'])) {
            $encryption_context = $post_checks['encryption'];

            if (is_array($encryption_context)) {
                if (isset($encryption_context['password']) && is_string($encryption_context['password']) && $encryption_context['password'] !== '') {
                    $password = $encryption_context['password'];
                } elseif (isset($encryption_context['password_callback']) && is_callable($encryption_context['password_callback'])) {
                    try {
                        $candidate = call_user_func($encryption_context['password_callback'], $filepath, $post_checks, $this);
                        if (is_string($candidate) && $candidate !== '') {
                            $password = $candidate;
                        }
                    } catch (\Throwable $exception) {
                        BJLG_Debug::log('Erreur lors de la récupération du mot de passe via le callback : ' . $exception->getMessage());
                    }
                }
            } elseif (is_string($encryption_context) && $encryption_context !== '') {
                $password = $encryption_context;
            }
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('bjlg_post_backup_checks_password', null, $filepath, $post_checks, $this);
            if (is_string($filtered) && $filtered !== '') {
                $password = $filtered;
            }
        }

        if ($password === null && function_exists('get_option')) {
            $settings = \bjlg_get_option('bjlg_encryption_settings', []);
            if (is_array($settings)) {
                if (isset($settings['password']) && is_string($settings['password']) && $settings['password'] !== '') {
                    $password = $settings['password'];
                } elseif (isset($settings['encryption_password']) && is_string($settings['encryption_password']) && $settings['encryption_password'] !== '') {
                    $password = $settings['encryption_password'];
                }
            }
        }

        if ($password === null && defined('BJLG_ENCRYPTION_PASSWORD')) {
            $constant_password = constant('BJLG_ENCRYPTION_PASSWORD');
            if (is_string($constant_password) && $constant_password !== '') {
                $password = $constant_password;
            }
        }

        if ($password !== null && !is_string($password)) {
            $password = (string) $password;
        }

        if ($password !== null && $password === '') {
            $password = null;
        }

        return $password;
    }

    /**
     * Supprime récursivement un répertoire temporaire.
     *
     * @param string $directory
     * @return void
     */
    private function remove_directory_tree($directory) {
        if (!is_string($directory) || $directory === '' || !is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->remove_directory_tree($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    private function resolve_include_patterns(array $task_data) {
        $raw_patterns = [];

        if (isset($task_data['include_patterns']) && is_array($task_data['include_patterns'])) {
            $raw_patterns = $task_data['include_patterns'];
        } elseif (function_exists('get_option')) {
            $raw_patterns = \bjlg_get_option('bjlg_backup_include_patterns', []);
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
            $raw_patterns = \bjlg_get_option('bjlg_backup_exclude_patterns', []);
        }

        return BJLG_Settings::sanitize_pattern_list($raw_patterns);
    }

    private function resolve_post_checks(array $task_data) {
        $raw = [];

        if (isset($task_data['post_checks']) && is_array($task_data['post_checks'])) {
            $raw = $task_data['post_checks'];
        } elseif (function_exists('get_option')) {
            $raw = \bjlg_get_option('bjlg_backup_post_checks', BJLG_Settings::get_default_backup_post_checks());
        }

        return BJLG_Settings::sanitize_post_checks($raw, BJLG_Settings::get_default_backup_post_checks());
    }

    private function resolve_destination_queue(array $task_data) {
        $raw = [];

        if (isset($task_data['secondary_destinations']) && is_array($task_data['secondary_destinations'])) {
            $raw = $task_data['secondary_destinations'];
        } elseif (function_exists('get_option')) {
            $raw = \bjlg_get_option('bjlg_backup_secondary_destinations', []);
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
            'decryption' => $encrypted ? 'pending' : 'not_applicable',
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

        $archive_for_checks = $filepath;
        $temporary_directory = null;
        $temporary_files = [];

        try {
            if ($encrypted) {
                if (!$this->encryption_handler instanceof BJLG_Encryption) {
                    throw new Exception('Impossible de vérifier une sauvegarde chiffrée : module de chiffrement indisponible.');
                }

                $password = $this->resolve_post_backup_checks_password($filepath, $post_checks);

                try {
                    $decryption = $this->encryption_handler->decrypt_to_temporary_copy($filepath, $password);
                } catch (Exception $exception) {
                    $failure_message = $exception->getMessage();

                    if (strpos($failure_message, 'Mot de passe requis') !== false) {
                        $failure_message .= ' Utilisez le filtre bjlg_post_backup_checks_password pour fournir le mot de passe avant la vérification.';
                        $results['decryption'] = 'failed';
                        $results['overall_status'] = 'failed';
                        $results['overall_message'] = $failure_message;

                        throw new Exception($failure_message);
                    }

                    $results['decryption'] = 'failed';
                    $results['overall_status'] = 'failed';
                    $results['overall_message'] = $failure_message;

                    throw $exception;
                }

                if (!is_array($decryption) || empty($decryption['path'])) {
                    throw new Exception('Impossible de préparer la vérification de la sauvegarde chiffrée.');
                }

                $archive_for_checks = (string) $decryption['path'];
                $temporary_directory = isset($decryption['directory']) && is_string($decryption['directory'])
                    ? $decryption['directory']
                    : null;

                if (!is_file($archive_for_checks)) {
                    $results['decryption'] = 'failed';
                    $results['overall_status'] = 'failed';
                    $results['overall_message'] = 'La copie déchiffrée de la sauvegarde est introuvable.';

                    throw new Exception('La copie déchiffrée de la sauvegarde est introuvable.');
                }

                $temporary_files[] = $archive_for_checks;
                BJLG_Debug::log('Vérification post-sauvegarde exécutée sur une copie déchiffrée temporaire.');
                $results['decryption'] = 'passed';
            }

            $zip = $this->create_zip_archive();
            $open_result = $zip->open($archive_for_checks);
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
        } finally {
            foreach ($temporary_files as $temporary_file) {
                if (is_string($temporary_file) && file_exists($temporary_file)) {
                    @unlink($temporary_file);
                }
            }

            if ($temporary_directory !== null) {
                $this->remove_directory_tree($temporary_directory);
            }
        }
    }

    private function format_post_check_history_summary(array $check_results): string {
        if (empty($check_results['files']) || !is_array($check_results['files'])) {
            return '';
        }

        $counters = [
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($check_results['files'] as $file_result) {
            if (!is_array($file_result)) {
                continue;
            }

            $status = isset($file_result['status']) ? (string) $file_result['status'] : '';
            if (isset($counters[$status])) {
                $counters[$status]++;
            }
        }

        $parts = [];

        if ($counters['passed'] > 0) {
            $parts[] = sprintf(
                $this->translate_plural('%s contrôle réussi', '%s contrôles réussis', $counters['passed'], 'backup-jlg'),
                number_format_i18n($counters['passed'])
            );
        }

        if ($counters['failed'] > 0) {
            $parts[] = sprintf(
                $this->translate_plural('%s échec détecté', '%s échecs détectés', $counters['failed'], 'backup-jlg'),
                number_format_i18n($counters['failed'])
            );
        }

        if ($counters['skipped'] > 0) {
            $parts[] = sprintf(
                $this->translate_plural('%s contrôle ignoré', '%s contrôles ignorés', $counters['skipped'], 'backup-jlg'),
                number_format_i18n($counters['skipped'])
            );
        }

        if (empty($parts)) {
            return '';
        }

        if (!empty($check_results['checksum'])) {
            $algorithm = isset($check_results['checksum_algorithm'])
                ? strtoupper((string) $check_results['checksum_algorithm'])
                : 'SHA-256';

            $hash = (string) $check_results['checksum'];
            if (strlen($hash) > 12) {
                $hash = substr($hash, 0, 12) . '…';
            }

            $parts[] = sprintf(
                __('Checksum %1$s (%2$s)', 'backup-jlg'),
                $hash,
                $algorithm
            );
        }

        return implode(' • ', $parts);
    }

    private function translate_plural($singular, $plural, $count, $domain = 'default') {
        if (function_exists('_n')) {
            return _n($singular, $plural, $count, $domain);
        }

        return $count === 1 ? (string) $singular : (string) $plural;
    }

    private function format_destination_history_summary(array $destination_results, array $expected_destinations): string {
        $success = isset($destination_results['success']) && is_array($destination_results['success'])
            ? $destination_results['success']
            : [];
        $failures = isset($destination_results['failures']) && is_array($destination_results['failures'])
            ? $destination_results['failures']
            : [];

        $success_labels = [];
        foreach ($success as $destination_id) {
            $label = $this->get_destination_label($destination_id);
            if ($label !== '') {
                $success_labels[] = $label;
            }
        }

        $failure_labels = [];
        foreach ($failures as $destination_id => $error_message) {
            $label = $this->get_destination_label($destination_id);
            if ($label === '') {
                $label = (string) $destination_id;
            }

            $message = is_string($error_message) && $error_message !== ''
                ? $error_message
                : __('erreur inconnue', 'backup-jlg');

            $failure_labels[] = sprintf('%1$s — %2$s', $label, $message);
        }

        $pending = [];
        foreach ($expected_destinations as $destination_id) {
            $destination_id = is_scalar($destination_id) ? (string) $destination_id : '';
            if ($destination_id === '') {
                continue;
            }

            if (in_array($destination_id, $success, true)) {
                continue;
            }

            if (array_key_exists($destination_id, $failures)) {
                continue;
            }

            $pending[] = $this->get_destination_label($destination_id) ?: $destination_id;
        }

        $parts = [];

        if (!empty($success_labels)) {
            $parts[] = sprintf(
                /* translators: 1: number of successful destinations, 2: destination labels. */
                __('Réussites (%1$s) : %2$s', 'backup-jlg'),
                number_format_i18n(count($success_labels)),
                implode(', ', $success_labels)
            );
        }

        if (!empty($failure_labels)) {
            $parts[] = sprintf(
                /* translators: 1: number of failed destinations, 2: labels with error messages. */
                __('Échecs (%1$s) : %2$s', 'backup-jlg'),
                number_format_i18n(count($failure_labels)),
                implode(' | ', $failure_labels)
            );
        }

        if (!empty($pending)) {
            $parts[] = sprintf(
                /* translators: 1: number of pending destinations, 2: destination labels. */
                __('En attente (%1$s) : %2$s', 'backup-jlg'),
                number_format_i18n(count($pending)),
                implode(', ', $pending)
            );
        }

        return implode(' • ', array_filter($parts));
    }

    private function get_destination_label($destination_id): string {
        if (!class_exists(BJLG_Settings::class)) {
            return is_scalar($destination_id) ? (string) $destination_id : '';
        }

        $label = BJLG_Settings::get_destination_label($destination_id);

        if ($label === '' && is_scalar($destination_id)) {
            return (string) $destination_id;
        }

        return $label;
    }

    private function dispatch_to_destinations($filepath, array $destinations, $task_id, array $batches = [], array $task_state = []) {
        $results = [
            'success' => [],
            'failures' => [],
            'details' => [],
        ];

        if (empty($destinations)) {
            return $results;
        }

        $resume_state = [];
        if (isset($task_state['managed_vault_resume']) && is_array($task_state['managed_vault_resume'])) {
            $resume_state = $task_state['managed_vault_resume'];
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
                if (method_exists($destination, 'upload_with_resilience')) {
                    $context = [];
                    if (isset($resume_state[$destination_id]) && is_array($resume_state[$destination_id])) {
                        $context = $resume_state[$destination_id];
                    }

                    $destination->upload_with_resilience($filepath, $task_id, $context);
                    $delivery = method_exists($destination, 'get_last_delivery_report')
                        ? $destination->get_last_delivery_report()
                        : [];

                    if (is_array($delivery) && !empty($delivery)) {
                        $results['details'][$destination_id] = $delivery;
                    }

                    unset($resume_state[$destination_id]);
                    $results['success'][] = $destination_id;
                    BJLG_Debug::log(sprintf('Sauvegarde envoyée vers %s (réplication multi-région).', $destination->get_name()));
                    BJLG_History::log('backup_upload', 'success', sprintf('Sauvegarde envoyée vers %s.', $destination->get_name()));
                } else {
                    $destination->upload_file($filepath, $task_id);
                    $results['success'][] = $destination_id;
                    BJLG_Debug::log(sprintf('Sauvegarde envoyée vers %s.', $destination->get_name()));
                    BJLG_History::log('backup_upload', 'success', sprintf('Sauvegarde envoyée vers %s.', $destination->get_name()));
                }
            } catch (Exception $exception) {
                $error_message = sprintf('Envoi vers %s échoué : %s', $destination->get_name(), $exception->getMessage());
                BJLG_Debug::log('ERREUR : ' . $error_message);
                BJLG_History::log('backup_upload', 'failure', $error_message);
                $results['failures'][$destination_id] = $exception->getMessage();

                if (method_exists($destination, 'get_last_delivery_report')) {
                    $delivery = $destination->get_last_delivery_report();
                    if (is_array($delivery) && !empty($delivery)) {
                        $results['details'][$destination_id] = $delivery;
                        if (!empty($delivery['resume']) && is_array($delivery['resume'])) {
                            $resume_state[$destination_id] = $delivery['resume'];
                        }
                    }
                }
            }

            if (class_exists(BJLG_Managed_Replication::class) && $destination instanceof BJLG_Managed_Replication) {
                $this->log_managed_replication_report($destination);
            }
        }

        if (!empty($resume_state)) {
            $results['resume_state']['managed_vault'] = $resume_state;
        } elseif (!empty($task_state['managed_vault_resume'])) {
            $results['resume_state']['managed_vault'] = [];
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

    private function log_managed_replication_report($destination): void {
        if (!class_exists(BJLG_Managed_Replication::class) || !$destination instanceof BJLG_Managed_Replication) {
            return;
        }

        if (!method_exists($destination, 'get_last_report')) {
            return;
        }

        $report = $destination->get_last_report();
        if (empty($report) || empty($report['replicas']) || !is_array($report['replicas'])) {
            return;
        }

        $available = isset($report['available_copies']) ? (int) $report['available_copies'] : 0;
        $expected = isset($report['expected_copies']) ? (int) $report['expected_copies'] : 0;

        $parts = [];
        foreach ($report['replicas'] as $replica) {
            if (!is_array($replica)) {
                continue;
            }

            $label = isset($replica['label']) ? (string) $replica['label'] : (isset($replica['provider']) ? (string) $replica['provider'] : '');
            $region = isset($replica['region']) && $replica['region'] !== '' ? sprintf(' (%s)', $replica['region']) : '';
            $status = isset($replica['status']) ? (string) $replica['status'] : 'unknown';
            $latency_value = isset($replica['latency_ms']) && $replica['latency_ms'] !== null
                ? (int) $replica['latency_ms']
                : null;
            $latency = $latency_value !== null
                ? sprintf('%sms', number_format_i18n(max(0, $latency_value)))
                : __('n/a', 'backup-jlg');
            $message = isset($replica['message']) && $replica['message'] !== '' ? ' — ' . $replica['message'] : '';

            $parts[] = trim(sprintf('%1$s%2$s : %3$s (%4$s)%5$s', $label, $region, $status, $latency, $message));
        }

        if (empty($parts)) {
            return;
        }

        $summary = sprintf(
            __('Réplication gérée : %1$s/%2$s copies • %3$s', 'backup-jlg'),
            number_format_i18n($available),
            number_format_i18n(max(1, $expected)),
            implode(' | ', $parts)
        );

        BJLG_Debug::log($summary);

        $history_status = (isset($report['status']) && $report['status'] === 'failed') ? 'failure' : 'success';
        BJLG_History::log('backup_replication', $history_status, $summary);
    }

    /**
     * Vérifie si un chemin correspond à un motif en utilisant fnmatch ou un mécanisme de repli.
     *
     * @param string $pattern
     * @param string $path
     * @return bool
     */
    private function path_matches_any($path, array $patterns) {
        if (empty($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if ($this->path_matches_pattern($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

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
     * Formate une taille lisible par l'humain sans dépendre de size_format().
     *
     * @param int|null $bytes
     * @return string
     */
    private function format_bytes($bytes) {
        if (!is_numeric($bytes) || (int) $bytes < 0) {
            return '0 B';
        }

        $bytes = (int) $bytes;

        if (function_exists('size_format')) {
            return size_format($bytes);
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $position = 0;

        while ($bytes >= 1024 && $position < count($units) - 1) {
            $bytes /= 1024;
            $position++;
        }

        return round($bytes, 2) . ' ' . $units[$position];
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
     * Construit les métriques détaillées d'une sauvegarde.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function build_backup_metrics(array $context): array {
        $generated_at = time();
        $completed_at = isset($context['completed_at']) ? (int) $context['completed_at'] : $generated_at;
        $start_time = isset($context['start_time']) ? (int) $context['start_time'] : $completed_at;

        if ($start_time > $completed_at) {
            $start_time = $completed_at;
        }

        $duration = max(0, $completed_at - $start_time);
        $size_bytes = isset($context['file_size']) ? (int) $context['file_size'] : 0;
        $components = array_values(array_filter(array_unique(array_map([
            $this,
            'normalize_identifier',
        ], (array) ($context['components'] ?? [])))));

        $destination_queue = isset($context['destination_queue']) && is_array($context['destination_queue'])
            ? $context['destination_queue']
            : [];
        $requested_destinations = array_values(array_filter(array_unique(array_map([
            $this,
            'normalize_identifier',
        ], $destination_queue))));

        $destination_results = isset($context['destination_results']) && is_array($context['destination_results'])
            ? $context['destination_results']
            : [];

        $delivered_destinations = [];
        if (isset($destination_results['success']) && is_array($destination_results['success'])) {
            $delivered_destinations = array_values(array_filter(array_unique(array_map([
                $this,
                'normalize_identifier',
            ], $destination_results['success']))));
        }

        $destination_failures = [];
        if (isset($destination_results['failures']) && is_array($destination_results['failures'])) {
            foreach ($destination_results['failures'] as $destination_id => $message) {
                $normalized_id = $this->normalize_identifier($destination_id);
                if ($normalized_id === '') {
                    continue;
                }

                $destination_failures[$normalized_id] = is_string($message) ? trim($message) : (string) $message;
            }
        }

        $failed_destinations = array_keys($destination_failures);
        $post_checks = isset($context['check_results']) && is_array($context['check_results'])
            ? $context['check_results']
            : [];
        $post_check_status = isset($post_checks['overall_status']) ? (string) $post_checks['overall_status'] : 'passed';
        $post_check_summary = isset($context['post_check_summary']) ? (string) $context['post_check_summary'] : '';
        $post_check_message = isset($context['post_check_message']) ? (string) $context['post_check_message'] : '';

        $state = 'success';
        if ($post_check_status === 'failed') {
            $state = 'failure';
        } elseif (!empty($destination_failures)) {
            $state = 'warning';
        }

        $previous_entry = BJLG_History::get_last_event_metadata('backup_created', 'success');
        $rpo_seconds = null;
        if (is_array($previous_entry)) {
            $previous_completed = null;
            if (!empty($previous_entry['metadata']['metrics']['timestamps']['completed_at'])) {
                $previous_completed = (int) $previous_entry['metadata']['metrics']['timestamps']['completed_at'];
            } elseif (!empty($previous_entry['metadata']['timestamps']['completed_at'])) {
                $previous_completed = (int) $previous_entry['metadata']['timestamps']['completed_at'];
            } else {
                $previous_completed = strtotime((string) $previous_entry['timestamp']);
            }

            if ($previous_completed) {
                $rpo_seconds = max(0, $completed_at - $previous_completed);
            }
        }

        $size_human = function_exists('size_format') ? size_format($size_bytes) : $this->format_bytes_fallback($size_bytes);
        $duration_human = $this->format_human_duration($duration, $start_time, $completed_at);
        $rpo_human = $rpo_seconds !== null ? $this->format_human_duration($rpo_seconds, $completed_at - $rpo_seconds, $completed_at) : null;
        $age_seconds = max(0, time() - $completed_at);

        $warnings = [];
        if ($post_check_message !== '') {
            $warnings[] = $post_check_message;
        }
        if (!empty($context['destination_notice'])) {
            $warnings[] = (string) $context['destination_notice'];
        }

        return [
            'version' => 1,
            'generated_at' => $generated_at,
            'generated_at_iso' => gmdate('c', $generated_at),
            'backup' => [
                'filename' => isset($context['backup_filename']) ? (string) $context['backup_filename'] : '',
                'path' => isset($context['backup_path']) ? (string) $context['backup_path'] : '',
            ],
            'components' => $components,
            'incremental' => !empty($context['incremental']),
            'encrypted' => !empty($context['encryption']),
            'encryption_requested' => !empty($context['requested_encryption']),
            'size' => [
                'bytes' => $size_bytes,
                'human' => $size_human,
            ],
            'duration' => [
                'seconds' => $duration,
                'human' => $duration_human,
            ],
            'timestamps' => [
                'started_at' => $start_time,
                'completed_at' => $completed_at,
                'started_at_iso' => gmdate('c', $start_time),
                'completed_at_iso' => gmdate('c', $completed_at),
            ],
            'age' => [
                'seconds' => $age_seconds,
                'human' => $this->format_human_duration($age_seconds, $completed_at, time()),
            ],
            'objectives' => [
                'rto_seconds' => $duration,
                'rto_human' => $duration_human,
                'rpo_seconds' => $rpo_seconds,
                'rpo_human' => $rpo_human,
            ],
            'destinations' => [
                'requested' => $requested_destinations,
                'delivered' => $delivered_destinations,
                'failed' => $failed_destinations,
                'failures' => $destination_failures,
                'summary' => [
                    'requested_total' => count($requested_destinations),
                    'delivered_total' => count($delivered_destinations),
                    'failed_total' => count($failed_destinations),
                ],
            ],
            'status' => [
                'state' => $state,
                'post_checks' => $post_check_status,
                'warnings' => $warnings,
            ],
            'post_checks' => [
                'summary' => $post_check_summary,
                'details' => $post_checks,
            ],
            'checksum' => [
                'value' => $post_checks['checksum'] ?? null,
                'algorithm' => $post_checks['checksum_algorithm'] ?? null,
            ],
        ];
    }

    /**
     * Normalise un identifiant simple (composant, destination, etc.).
     *
     * @param mixed $value
     * @return string
     */
    private function normalize_identifier($value): string {
        if (!is_scalar($value)) {
            return '';
        }

        $string = (string) $value;

        if ($string === '') {
            return '';
        }

        if (function_exists('sanitize_key')) {
            return sanitize_key($string);
        }

        $string = strtolower($string);
        $filtered = preg_replace('/[^a-z0-9_\-]/', '', $string);

        return is_string($filtered) ? $filtered : '';
    }

    /**
     * Formate une durée en notation humaine.
     */
    private function format_human_duration($seconds, ?int $from = null, ?int $to = null): string {
        $seconds = (int) $seconds;

        if ($seconds <= 0) {
            return '0s';
        }

        if (function_exists('human_time_diff')) {
            $from_time = $from ?? (($to !== null) ? $to - $seconds : (time() - $seconds));
            $to_time = $to ?? ($from_time + $seconds);

            return human_time_diff($from_time, $to_time);
        }

        if ($seconds >= HOUR_IN_SECONDS) {
            $hours = (int) floor($seconds / HOUR_IN_SECONDS);
            $minutes = (int) floor(($seconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);

            if ($minutes > 0) {
                return sprintf('%dh %dm', $hours, $minutes);
            }

            return sprintf('%dh', $hours);
        }

        if ($seconds >= MINUTE_IN_SECONDS) {
            $minutes = (int) floor($seconds / MINUTE_IN_SECONDS);
            $remaining_seconds = $seconds % MINUTE_IN_SECONDS;

            if ($remaining_seconds > 0) {
                return sprintf('%dm %ds', $minutes, $remaining_seconds);
            }

            return sprintf('%dm', $minutes);
        }

        return sprintf('%ds', $seconds);
    }

    /**
     * Formatage simplifié des octets lorsque size_format() est indisponible.
     */
    private function format_bytes_fallback($bytes): string {
        $bytes = (int) $bytes;

        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes;
        $unit = 'KB';

        foreach ($units as $candidate) {
            if ($value < 1024) {
                $unit = $candidate;
                break;
            }
            $value = $value / 1024;
            $unit = $candidate;
        }

        return sprintf('%.2f %s', $value, $unit);
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