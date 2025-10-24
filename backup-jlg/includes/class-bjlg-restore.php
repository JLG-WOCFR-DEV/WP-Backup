<?php
namespace BJLG;

use Exception;
use RuntimeException;
use Throwable;
use ZipArchive;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-bjlg-backup.php';
require_once __DIR__ . '/class-bjlg-backup-path-resolver.php';

/**
 * Gère tout le processus de restauration, y compris la pré-sauvegarde de sécurité.
 */
class BJLG_Restore {

    public const ENV_PRODUCTION = 'production';
    public const ENV_SANDBOX = 'sandbox';

    private const SANDBOX_REPORT_REGISTRY_OPTION = 'bjlg_sandbox_validation_reports';

    /**
     * Instance du gestionnaire de sauvegarde.
     *
     * @var BJLG_Backup|null
     */
    private $backup_manager;

    private $encryption_handler;

    public function __construct($backup_manager = null, $encryption_handler = null) {
        if ($backup_manager === null && class_exists(BJLG_Backup::class)) {
            $backup_manager = new BJLG_Backup();
        }

        $this->backup_manager = $backup_manager;

        if ($encryption_handler instanceof BJLG_Encryption) {
            $this->encryption_handler = $encryption_handler;
        }

        add_action('wp_ajax_bjlg_create_pre_restore_backup', [$this, 'handle_create_pre_restore_backup']);
        add_action('wp_ajax_bjlg_run_restore', [$this, 'handle_run_restore']);
        add_action('wp_ajax_bjlg_upload_restore_file', [$this, 'handle_upload_restore_file']);
        add_action('wp_ajax_bjlg_check_restore_progress', [$this, 'handle_check_restore_progress']);
        add_action('bjlg_run_restore_task', [$this, 'run_restore_task'], 10, 1);
        add_action('wp_ajax_bjlg_cleanup_sandbox_restore', [$this, 'handle_cleanup_sandbox']);
        add_action('wp_ajax_bjlg_publish_sandbox_restore', [$this, 'handle_publish_sandbox']);
    }

    /**
     * Exécute une validation automatisée de restauration dans une sandbox.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function run_sandbox_validation(array $args = []): array {
        $started_at = time();
        $timer_start = microtime(true);
        $task_id = '';
        $task_state = [];
        $sandbox_context = null;
        $cleanup_result = ['performed' => false, 'error' => null];
        $backup_file = '';
        $status = 'failure';
        $message = '';

        $components = [];
        $execution_log = [];

        $this->append_sandbox_log($execution_log, 'Initialisation de la validation sandbox.');

        try {
            if (!self::user_can_use_sandbox()) {
                throw new RuntimeException(__('La sandbox de restauration n’est pas disponible pour cette installation.', 'backup-jlg'));
            }

            $this->append_sandbox_log($execution_log, 'Sandbox disponible pour la validation.');

            $backup_file = $this->resolve_validation_backup($args);
            $this->append_sandbox_log($execution_log, 'Archive sélectionnée pour la validation.', [
                'backup_file' => basename($backup_file),
            ]);

            $password = null;
            if (isset($args['password']) && is_string($args['password'])) {
                $password = $args['password'] !== '' ? $args['password'] : null;
            }

            if (substr($backup_file, -4) === '.enc' && $password === null) {
                throw new RuntimeException(__('La sauvegarde la plus récente est chiffrée. Fournissez un mot de passe pour lancer la validation sandbox.', 'backup-jlg'));
            }

            $components = self::normalize_requested_components($args['components'] ?? null);
            $this->append_sandbox_log($execution_log, 'Composants demandés pour la restauration.', [
                'components' => $components,
            ]);

            $environment = self::prepare_environment(self::ENV_SANDBOX, [
                'sandbox_path' => isset($args['sandbox_path']) && is_string($args['sandbox_path'])
                    ? $args['sandbox_path']
                    : '',
            ]);

            $sandbox_context = $environment['sandbox'];
            $this->append_sandbox_log($execution_log, 'Environnement sandbox préparé.', [
                'base_path' => isset($sandbox_context['base_path']) ? (string) $sandbox_context['base_path'] : '',
            ]);

            $task_id = 'bjlg_sandbox_validation_' . md5(uniqid('sandbox', true));

            $password_encrypted = null;
            if ($password !== null) {
                $password_encrypted = self::encrypt_password_for_transient($password);
            }

            $task_data = [
                'progress' => 0,
                'status' => 'pending',
                'status_text' => 'Initialisation de la restauration sandbox...',
                'filename' => basename($backup_file),
                'filepath' => $backup_file,
                'password_encrypted' => $password_encrypted,
                'create_restore_point' => false,
                'components' => $components,
                'environment' => $environment['environment'],
                'routing_table' => $environment['routing_table'],
            ];

            if (!empty($environment['sandbox'])) {
                $task_data['sandbox'] = $environment['sandbox'];
            }

            if (!BJLG_Backup::reserve_task_slot($task_id)) {
                throw new RuntimeException(__('Une autre restauration est déjà en cours. La validation sandbox sera reprogrammée.', 'backup-jlg'));
            }

            if (!set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl())) {
                BJLG_Backup::release_task_slot($task_id);
                throw new RuntimeException(__('Impossible d’initialiser la tâche de validation sandbox.', 'backup-jlg'));
            }

            BJLG_Backup::release_task_slot($task_id);

            $this->append_sandbox_log($execution_log, 'Tâche sandbox initialisée.', [
                'task_id' => $task_id,
            ]);

            $this->run_restore_task($task_id);

            $task_state = get_transient($task_id);
            $task_status = is_array($task_state) && isset($task_state['status'])
                ? (string) $task_state['status']
                : 'unknown';
            $this->append_sandbox_log($execution_log, 'Résultat de la tâche sandbox.', [
                'task_status' => $task_status,
                'progress' => isset($task_state['progress']) ? $task_state['progress'] : null,
            ]);

            if ($task_status === 'complete') {
                $status = 'success';
                $message = __('Validation sandbox terminée avec succès.', 'backup-jlg');
            } else {
                $status = 'failure';
                $message = __('La validation sandbox ne s’est pas terminée correctement.', 'backup-jlg');
            }
        } catch (Throwable $throwable) {
            $status = 'failure';
            $message = sprintf(__('Validation sandbox échouée : %s', 'backup-jlg'), $throwable->getMessage());

            $this->append_sandbox_log($execution_log, 'Erreur durant la validation sandbox.', [
                'error' => $throwable->getMessage(),
            ]);

            if (class_exists(BJLG_Debug::class)) {
                BJLG_Debug::log('[Sandbox validation] ' . $throwable->getMessage(), 'error');
            }
        } finally {
            if ($task_id !== '') {
                $cleanup_result = $this->cleanup_validation_sandbox(
                    is_array($sandbox_context) && isset($sandbox_context['base_path'])
                        ? $sandbox_context['base_path']
                        : ''
                );

                $this->append_sandbox_log($execution_log, 'Nettoyage sandbox effectué.', [
                    'performed' => !empty($cleanup_result['performed']),
                    'error' => $cleanup_result['error'],
                ]);

                delete_transient($task_id);
            }
        }

        $completed_at = time();
        $duration_seconds = (int) max(0, round(microtime(true) - $timer_start));
        $duration_human = $this->format_duration_for_report($duration_seconds);

        $backup_mtime = ($backup_file !== '' && file_exists($backup_file)) ? @filemtime($backup_file) : false;
        $rpo_seconds = ($backup_mtime !== false) ? max(0, $started_at - (int) $backup_mtime) : null;
        $rpo_human = $rpo_seconds !== null ? $this->format_duration_for_report($rpo_seconds) : null;

        $this->append_sandbox_log($execution_log, 'Durées calculées pour la validation sandbox.', [
            'duration_seconds' => $duration_seconds,
            'rpo_seconds' => $rpo_seconds,
        ]);

        $result = [
            'status' => $status,
            'message' => $message,
            'backup_file' => $backup_file,
            'started_at' => $started_at,
            'completed_at' => $completed_at,
            'timings' => [
                'duration_seconds' => $duration_seconds,
                'duration_human' => $duration_human,
            ],
            'objectives' => [
                'rto_seconds' => $duration_seconds,
                'rto_human' => $duration_human,
                'rpo_seconds' => $rpo_seconds,
                'rpo_human' => $rpo_human,
            ],
            'components' => $components,
            'sandbox' => [
                'base_path' => is_array($sandbox_context) && isset($sandbox_context['base_path'])
                    ? (string) $sandbox_context['base_path']
                    : '',
                'cleanup' => $cleanup_result,
            ],
            'task' => [
                'id' => $task_id,
                'state' => is_array($task_state) ? $task_state : [],
            ],
        ];

        $result['log'] = $execution_log;
        $result['log_excerpt'] = array_slice($execution_log, -10);

        $report_meta = $this->persist_sandbox_report($result, $execution_log);
        if (!empty($report_meta)) {
            $result['report'] = $report_meta;
            $this->append_sandbox_log($execution_log, 'Rapport sandbox enregistré.', [
                'report_id' => $report_meta['id'] ?? '',
            ]);
            $result['log'] = $execution_log;
            $result['log_excerpt'] = array_slice($execution_log, -10);
        }

        return $result;
    }

    /**
     * Résout le fichier de sauvegarde à utiliser pour la validation sandbox.
     *
     * @param array<string,mixed> $args
     * @return string
     */
    private function resolve_validation_backup(array $args): string {
        $requested = '';

        if (isset($args['backup']) && is_string($args['backup']) && $args['backup'] !== '') {
            $requested = $args['backup'];
        } elseif (isset($args['backup_file']) && is_string($args['backup_file']) && $args['backup_file'] !== '') {
            $requested = $args['backup_file'];
        }

        if ($requested !== '') {
            $resolved = BJLG_Backup_Path_Resolver::resolve($requested);
            if (!is_wp_error($resolved)) {
                return $resolved;
            }
        }

        $candidates = $this->list_validation_backup_candidates();

        if (empty($candidates)) {
            throw new RuntimeException(__('Aucune sauvegarde n’est disponible pour la validation sandbox.', 'backup-jlg'));
        }

        return $candidates[0]['path'];
    }

    /**
     * Liste les fichiers de sauvegarde disponibles, triés du plus récent au plus ancien.
     *
     * @return array<int,array{path:string,modified:int}>
     */
    private function list_validation_backup_candidates(): array {
        if (!defined('BJLG_BACKUP_DIR')) {
            return [];
        }

        $base_dir = BJLG_BACKUP_DIR;
        if (!is_string($base_dir) || $base_dir === '') {
            return [];
        }

        if (function_exists('trailingslashit')) {
            $base_dir = trailingslashit($base_dir);
        } else {
            $base_dir = rtrim($base_dir, '/\\') . '/';
        }

        $patterns = [
            $base_dir . '*.zip',
            $base_dir . '*.zip.enc',
        ];

        $candidates = [];

        foreach ($patterns as $pattern) {
            $matches = glob($pattern);
            if (!is_array($matches)) {
                continue;
            }

            foreach ($matches as $match) {
                if (!is_string($match) || !is_file($match)) {
                    continue;
                }

                $candidates[] = [
                    'path' => $match,
                    'modified' => (int) @filemtime($match),
                ];
            }
        }

        usort($candidates, function ($a, $b) {
            return $b['modified'] <=> $a['modified'];
        });

        return $candidates;
    }

    /**
     * Nettoie la sandbox créée pour la validation automatique.
     *
     * @param string $base_path
     * @return array{performed:bool,error:?string}
     */
    private function cleanup_validation_sandbox($base_path): array {
        $result = [
            'performed' => false,
            'error' => null,
        ];

        if (!is_string($base_path) || $base_path === '' || !file_exists($base_path)) {
            return $result;
        }

        try {
            $this->recursive_delete($base_path);
            $result['performed'] = true;
        } catch (Throwable $throwable) {
            $result['error'] = $throwable->getMessage();

            if (class_exists(BJLG_Debug::class)) {
                BJLG_Debug::log('[Sandbox validation] ' . $throwable->getMessage(), 'error');
            }
        }

        return $result;
    }

    /**
     * Formate une durée en chaîne lisible.
     */
    private function format_duration_for_report($seconds): string {
        $seconds = (int) $seconds;

        if ($seconds <= 0) {
            return '0s';
        }

        if (function_exists('human_time_diff')) {
            $reference = time();

            return human_time_diff($reference - $seconds, $reference);
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
            $remaining = $seconds % MINUTE_IN_SECONDS;

            if ($remaining > 0) {
                return sprintf('%dm %ds', $minutes, $remaining);
            }

            return sprintf('%dm', $minutes);
        }

        return sprintf('%ds', $seconds);
    }

    private function append_sandbox_log(array &$log, string $message, array $context = []): void
    {
        $entry = [
            'timestamp' => time(),
            'message' => $this->sanitize_log_message($message),
        ];

        if (!empty($context)) {
            $entry['context'] = $this->sanitize_log_context($context);
        }

        $log[] = $entry;

        if (count($log) > 200) {
            $log = array_slice($log, -200);
        }
    }

    private function sanitize_log_message(string $message): string
    {
        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($message);
        }

        return trim(strip_tags($message));
    }

    private function sanitize_log_context($context, int $depth = 0)
    {
        if (!is_array($context) || $depth > 2) {
            return [];
        }

        $clean = [];

        foreach ($context as $key => $value) {
            $normalized_key = is_string($key) ? sanitize_key($key) : ('item_' . (string) $key);
            if ($normalized_key === '') {
                $normalized_key = 'context_' . $depth;
            }

            if (is_scalar($value) || $value === null) {
                $clean[$normalized_key] = $this->sanitize_log_value($value);
                continue;
            }

            if (is_array($value)) {
                $clean[$normalized_key] = $this->sanitize_log_context($value, $depth + 1);
                continue;
            }

            if (is_object($value) && method_exists($value, '__toString')) {
                $clean[$normalized_key] = $this->sanitize_log_value((string) $value);
            }
        }

        return $clean;
    }

    private function sanitize_log_value($value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        $value = (string) $value;

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        return trim(strip_tags($value));
    }

    private function persist_sandbox_report(array $report, array $execution_log): array
    {
        if (!function_exists('wp_upload_dir')) {
            return [];
        }

        $uploads = wp_upload_dir(null, false);
        if (!is_array($uploads) || !empty($uploads['error'])) {
            return [];
        }

        $base_dir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        $base_url = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';

        if ($base_dir === '' || $base_url === '') {
            return [];
        }

        $target_dir = trailingslashit($base_dir) . 'bjlg/sandbox-reports';
        $target_url = trailingslashit($base_url) . 'bjlg/sandbox-reports';

        if (!wp_mkdir_p($target_dir)) {
            return [];
        }

        $report_id = 'sandbox-' . gmdate('YmdHis') . '-' . (
            function_exists('wp_generate_uuid4')
                ? wp_generate_uuid4()
                : md5(uniqid('sandbox-report', true))
        );

        $json_filename = $report_id . '.json';
        $log_filename = $report_id . '-log.ndjson';
        $json_path = trailingslashit($target_dir) . $json_filename;
        $log_path = trailingslashit($target_dir) . $log_filename;

        $payload = [
            'report_id' => $report_id,
            'generated_at' => gmdate('c'),
            'status' => $report['status'] ?? 'unknown',
            'message' => $report['message'] ?? '',
            'backup_file' => $report['backup_file'] ?? '',
            'started_at' => $report['started_at'] ?? null,
            'completed_at' => $report['completed_at'] ?? null,
            'timings' => $report['timings'] ?? [],
            'objectives' => $report['objectives'] ?? [],
            'components' => $report['components'] ?? [],
            'sandbox' => $report['sandbox'] ?? [],
            'task' => $report['task'] ?? [],
            'log' => $execution_log,
        ];

        $encoded_payload = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded_payload) || file_put_contents($json_path, $encoded_payload) === false) {
            return [];
        }

        $log_lines = [];
        foreach ($execution_log as $entry) {
            $encoded_entry = wp_json_encode($entry, JSON_UNESCAPED_SLASHES);
            if (is_string($encoded_entry)) {
                $log_lines[] = $encoded_entry;
            }
        }

        file_put_contents($log_path, implode("\n", $log_lines));

        $files = [
            'json' => [
                'path' => $json_path,
                'url' => trailingslashit($target_url) . $json_filename,
                'filename' => $json_filename,
                'mime_type' => 'application/json',
                'size' => @filesize($json_path) ?: null,
            ],
            'log' => [
                'path' => $log_path,
                'url' => trailingslashit($target_url) . $log_filename,
                'filename' => $log_filename,
                'mime_type' => 'application/x-ndjson',
                'size' => @filesize($log_path) ?: null,
            ],
        ];

        $sanitized_files = self::sanitize_report_files($files);

        $snapshot = [
            'id' => $report_id,
            'created_at' => $report['completed_at'] ?? time(),
            'status' => $report['status'] ?? 'unknown',
            'message' => $report['message'] ?? '',
            'objectives' => $report['objectives'] ?? [],
            'timings' => $report['timings'] ?? [],
            'files' => $sanitized_files,
            'base_path' => $target_dir,
            'log_excerpt' => array_slice($execution_log, -10),
            'backup_file' => $report['backup_file'] ?? '',
        ];

        self::store_sandbox_report_snapshot($snapshot);

        return [
            'id' => $report_id,
            'created_at' => $snapshot['created_at'],
            'status' => $snapshot['status'],
            'message' => $snapshot['message'],
            'files' => $sanitized_files,
            'base_path' => $target_dir,
            'log_excerpt' => $snapshot['log_excerpt'],
            'backup_file' => $snapshot['backup_file'],
        ];
    }

    private static function store_sandbox_report_snapshot(array $snapshot): void
    {
        $registry = self::get_sandbox_report_registry();
        $filtered = [];
        foreach ($registry as $entry) {
            if (!is_array($entry) || !isset($entry['id'])) {
                continue;
            }
            if ($entry['id'] === ($snapshot['id'] ?? null)) {
                continue;
            }
            $filtered[] = $entry;
        }

        $filtered[] = self::sanitize_report_snapshot($snapshot);

        usort($filtered, static function ($a, $b) {
            return (($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));
        });

        if (count($filtered) > 10) {
            $filtered = array_slice($filtered, 0, 10);
        }

        self::persist_sandbox_report_registry($filtered);
    }

    private static function persist_sandbox_report_registry(array $registry): void
    {
        update_option(self::SANDBOX_REPORT_REGISTRY_OPTION, ['reports' => array_values($registry)], false);
    }

    private static function sanitize_report_snapshot(array $snapshot): array
    {
        $sanitized = [
            'id' => self::sanitize_report_text($snapshot['id'] ?? ''),
            'created_at' => isset($snapshot['created_at']) ? (int) $snapshot['created_at'] : time(),
            'status' => sanitize_key($snapshot['status'] ?? 'unknown'),
            'message' => self::sanitize_report_text($snapshot['message'] ?? ''),
            'objectives' => is_array($snapshot['objectives'] ?? null) ? $snapshot['objectives'] : [],
            'timings' => is_array($snapshot['timings'] ?? null) ? $snapshot['timings'] : [],
            'files' => self::sanitize_report_files($snapshot['files'] ?? []),
            'base_path' => self::sanitize_report_path($snapshot['base_path'] ?? ''),
            'log_excerpt' => is_array($snapshot['log_excerpt'] ?? null) ? $snapshot['log_excerpt'] : [],
            'history_entry_id' => isset($snapshot['history_entry_id']) ? (int) $snapshot['history_entry_id'] : null,
            'backup_file' => self::sanitize_report_text($snapshot['backup_file'] ?? ''),
        ];

        return $sanitized;
    }

    private static function sanitize_report_files($files): array
    {
        if (!is_array($files)) {
            return [];
        }

        $sanitized = [];

        foreach ($files as $type => $file_info) {
            if (!is_array($file_info)) {
                continue;
            }

            $sanitized[$type] = [
                'path' => isset($file_info['path']) ? self::sanitize_report_path($file_info['path']) : '',
                'url' => isset($file_info['url']) ? esc_url_raw($file_info['url']) : '',
                'filename' => self::sanitize_report_text($file_info['filename'] ?? ''),
                'mime_type' => self::sanitize_report_text($file_info['mime_type'] ?? ''),
                'size' => isset($file_info['size']) ? (int) $file_info['size'] : null,
            ];
        }

        return $sanitized;
    }

    private static function sanitize_report_path($path): string
    {
        if (!is_string($path) || $path === '') {
            return '';
        }

        $real = realpath($path);
        if ($real !== false) {
            $path = $real;
        }

        $normalized = str_replace('\\', '/', $path);

        return rtrim($normalized, '/');
    }

    private static function sanitize_report_text($text): string
    {
        $text = (string) $text;

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($text);
        }

        return trim(strip_tags($text));
    }

    public static function get_sandbox_report_registry(): array
    {
        $stored = get_option(self::SANDBOX_REPORT_REGISTRY_OPTION, []);
        $records = [];

        if (is_array($stored)) {
            if (isset($stored['reports']) && is_array($stored['reports'])) {
                $records = $stored['reports'];
            } elseif (!empty($stored)) {
                $records = $stored;
            }
        }

        $sanitized = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $sanitized[] = self::sanitize_report_snapshot($record);
        }

        usort($sanitized, static function ($a, $b) {
            return (($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));
        });

        return $sanitized;
    }

    public static function get_latest_sandbox_report(): ?array
    {
        $registry = self::get_sandbox_report_registry();

        return $registry[0] ?? null;
    }

    public static function find_sandbox_report(string $report_id): ?array
    {
        $registry = self::get_sandbox_report_registry();

        foreach ($registry as $entry) {
            if (isset($entry['id']) && $entry['id'] === $report_id) {
                return $entry;
            }
        }

        return null;
    }

    public static function attach_sandbox_report_to_history(string $report_id, int $entry_id): void
    {
        if ($report_id === '' || $entry_id <= 0) {
            return;
        }

        $registry = self::get_sandbox_report_registry();
        $updated = false;

        foreach ($registry as &$entry) {
            if (!is_array($entry) || ($entry['id'] ?? '') !== $report_id) {
                continue;
            }

            $entry['history_entry_id'] = $entry_id;
            $updated = true;
            break;
        }

        if ($updated) {
            self::persist_sandbox_report_registry($registry);
        }
    }

    /**
     * Nettoie un environnement sandbox existant.
     */
    public function handle_cleanup_sandbox() {
        if (!self::user_can_use_sandbox()) {
            wp_send_json_error(['message' => 'Permission refusée pour nettoyer la sandbox.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $task_id = isset($_POST['task_id']) ? sanitize_text_field(wp_unslash($_POST['task_id'])) : '';

        if ($task_id === '') {
            wp_send_json_error(['message' => 'Identifiant de tâche manquant.']);
        }

        $task_data = get_transient($task_id);
        if (!is_array($task_data)) {
            wp_send_json_error(['message' => 'Tâche introuvable.']);
        }

        $environment = isset($task_data['environment']) ? sanitize_key((string) $task_data['environment']) : self::ENV_PRODUCTION;
        if ($environment !== self::ENV_SANDBOX) {
            wp_send_json_error(['message' => 'Cette tâche ne correspond pas à un environnement de test.']);
        }

        $sandbox_context = isset($task_data['sandbox']) && is_array($task_data['sandbox']) ? $task_data['sandbox'] : [];
        $base_path = isset($sandbox_context['base_path']) && is_string($sandbox_context['base_path'])
            ? $sandbox_context['base_path']
            : '';

        if ($base_path === '' || !file_exists($base_path)) {
            wp_send_json_success(['message' => 'Aucun répertoire sandbox à supprimer.']);
        }

        try {
            $this->recursive_delete($base_path);
            BJLG_History::log('restore_sandbox_cleanup', 'success', 'Sandbox supprimée : ' . $base_path);

            $sandbox_context['cleaned_at'] = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
            $task_data['sandbox'] = $sandbox_context;
            set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl());

            wp_send_json_success([
                'message' => 'Environnement de test nettoyé.',
                'sandbox' => $sandbox_context,
            ]);
        } catch (Throwable $throwable) {
            $error_message = 'Impossible de supprimer la sandbox : ' . $throwable->getMessage();
            BJLG_History::log('restore_sandbox_cleanup', 'failure', $error_message);

            wp_send_json_error([
                'message' => $error_message,
            ]);
        }
    }

    /**
     * Publie le contenu d'une sandbox vers la production.
     */
    public function handle_publish_sandbox() {
        if (!self::user_can_use_sandbox()) {
            wp_send_json_error(['message' => 'Permission refusée pour promouvoir la sandbox.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        $task_id = isset($_POST['task_id']) ? sanitize_text_field(wp_unslash($_POST['task_id'])) : '';

        if ($task_id === '') {
            wp_send_json_error(['message' => 'Identifiant de tâche manquant.']);
        }

        $task_data = get_transient($task_id);
        if (!is_array($task_data)) {
            wp_send_json_error(['message' => 'Tâche introuvable.']);
        }

        $environment = isset($task_data['environment']) ? sanitize_key((string) $task_data['environment']) : self::ENV_PRODUCTION;
        if ($environment !== self::ENV_SANDBOX) {
            wp_send_json_error(['message' => 'Cette tâche ne correspond pas à un environnement de test.']);
        }

        $routing_table = isset($task_data['routing_table']) && is_array($task_data['routing_table'])
            ? $task_data['routing_table']
            : [];

        $sandbox_context = isset($task_data['sandbox']) && is_array($task_data['sandbox']) ? $task_data['sandbox'] : [];
        $base_path = isset($sandbox_context['base_path']) && is_string($sandbox_context['base_path'])
            ? $sandbox_context['base_path']
            : '';

        if ($base_path === '' || !is_dir($base_path)) {
            wp_send_json_error(['message' => 'Le répertoire sandbox est introuvable.']);
        }

        $components = self::normalize_requested_components($task_data['components'] ?? null);
        $summary = [];

        try {
            $this->perform_pre_restore_backup();

            if (in_array('db', $components, true)) {
                $db_file = $this->resolve_database_destination($routing_table, $sandbox_context);
                if ($db_file && file_exists($db_file)) {
                    $this->import_database($db_file);
                    $summary[] = 'base de données';
                }
            }

            foreach (['plugins', 'themes', 'uploads'] as $component) {
                if (!in_array($component, $components, true)) {
                    continue;
                }

                $source = $this->resolve_component_destination($component, $routing_table, self::ENV_SANDBOX);

                if ($source === null && isset($sandbox_context['routing_table'][$component])) {
                    $candidate = $sandbox_context['routing_table'][$component];
                    if (is_string($candidate) && $candidate !== '') {
                        $source = $candidate;
                    }
                }

                if ($source === null || !is_dir($source)) {
                    continue;
                }

                $destination = $this->get_production_destination($component);

                if ($destination === null) {
                    continue;
                }

                $this->publish_component_directory($source, $destination);
                $summary[] = $component;
            }

            $this->clear_all_caches();

            $sandbox_context['published_at'] = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
            $sandbox_context['published_components'] = $summary;
            $task_data['sandbox'] = $sandbox_context;
            set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl());

            BJLG_History::log('restore_sandbox_publish', 'success', 'Sandbox promue : ' . $base_path);

            wp_send_json_success([
                'message' => 'Sandbox promue en production.',
                'components' => $summary,
                'sandbox' => $sandbox_context,
            ]);
        } catch (Throwable $throwable) {
            if ($throwable instanceof \RuntimeException && $throwable->getMessage() === 'JSON response') {
                throw $throwable;
            }

            if (class_exists('WPDieException') && $throwable instanceof \WPDieException) {
                throw $throwable;
            }

            $error_message = 'Promotion sandbox échouée : ' . $throwable->getMessage();
            BJLG_History::log('restore_sandbox_publish', 'failure', $error_message);

            wp_send_json_error([
                'message' => $error_message,
            ]);
        }
    }

    /**
     * Détermine la destination finale pour la base de données selon l'environnement.
     *
     * @param array<string, string> $routing_table
     * @param array<string, mixed>|null $sandbox_context
     * @return string|null
     */
    private function resolve_database_destination(array $routing_table, $sandbox_context) {
        if (isset($routing_table['db']) && is_string($routing_table['db']) && $routing_table['db'] !== '') {
            return $routing_table['db'];
        }

        if (is_array($sandbox_context)) {
            if (isset($sandbox_context['routing_table']['db']) && is_string($sandbox_context['routing_table']['db'])) {
                return $sandbox_context['routing_table']['db'];
            }

            if (isset($sandbox_context['base_path']) && is_string($sandbox_context['base_path']) && $sandbox_context['base_path'] !== '') {
                return rtrim($sandbox_context['base_path'], '/\\') . '/database.sql';
            }
        }

        return null;
    }

    /**
     * Résout le répertoire cible pour un composant donné.
     *
     * @param string $component
     * @param array<string, string> $routing_table
     * @param string $environment
     * @return string|null
     */
    private function resolve_component_destination($component, array $routing_table, $environment) {
        if (isset($routing_table[$component]) && is_string($routing_table[$component]) && $routing_table[$component] !== '') {
            return $routing_table[$component];
        }

        if ($environment === self::ENV_SANDBOX) {
            return null;
        }

        return $this->get_production_destination($component);
    }

    /**
     * Retourne le chemin de destination pour la production.
     *
     * @param string $component
     * @return string|null
     */
    private function get_production_destination($component) {
        switch ($component) {
            case 'plugins':
                return WP_PLUGIN_DIR;
            case 'themes':
                return get_theme_root();
            case 'uploads':
                $upload_dir = wp_get_upload_dir();
                return isset($upload_dir['basedir']) ? $upload_dir['basedir'] : null;
        }

        return null;
    }

    /**
     * Retourne le chemin de base de la sandbox.
     *
     * @param array<string, mixed>|null $sandbox_context
     * @return string|null
     */
    private function get_sandbox_base_path($sandbox_context) {
        if (is_array($sandbox_context) && isset($sandbox_context['base_path']) && is_string($sandbox_context['base_path'])) {
            return $sandbox_context['base_path'];
        }

        return null;
    }

    /**
     * Crée une sauvegarde de sécurité complète avant de lancer une restauration.
     */
    public function handle_create_pre_restore_backup() {
        if (!\bjlg_can_restore_backups()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        try {
            $result = $this->perform_pre_restore_backup();

            wp_send_json_success([
                'message' => 'Sauvegarde de sécurité créée avec succès.',
                'backup_file' => $result['filename']
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => 'La sauvegarde de sécurité a échoué : ' . $e->getMessage()]);
        }
    }

    /**
     * Exécute la logique de sauvegarde préalable à la restauration.
     *
     * @return array{filename: string, filepath: string}
     * @throws Exception
     */
    protected function perform_pre_restore_backup(): array {
        BJLG_Debug::log("Lancement de la sauvegarde de sécurité pré-restauration.");

        if (!($this->backup_manager instanceof BJLG_Backup)) {
            throw new Exception('Gestionnaire de sauvegarde indisponible.');
        }

        $backup_manager = $this->backup_manager;

        $timestamp = date('Y-m-d-H-i-s');
        $base_filename = 'pre-restore-backup-' . $timestamp;
        $backup_filename = $base_filename . '.zip';

        if (function_exists('wp_unique_filename')) {
            $backup_filename = wp_unique_filename(bjlg_get_backup_directory(), $backup_filename);
        }

        $backup_filepath = bjlg_get_backup_directory() . $backup_filename;

        while (file_exists($backup_filepath)) {
            $unique_suffix = str_replace('.', '-', uniqid('', true));
            $backup_filename = sprintf('%s-%s.zip', $base_filename, $unique_suffix);
            $backup_filepath = bjlg_get_backup_directory() . $backup_filename;
        }
        $sql_filepath = bjlg_get_backup_directory() . 'database_temp_prerestore.sql';

        $zip = new ZipArchive();

        $cleanup_sql_file = static function () use ($sql_filepath) {
            if (file_exists($sql_filepath)) {
                unlink($sql_filepath);
            }
        };

        try {
            if ($zip->open($backup_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception("Impossible de créer l'archive de pré-restauration.");
            }

            $components = ['db', 'plugins', 'themes', 'uploads'];
            $manifest = [
                'type' => 'pre-restore-backup',
                'contains' => $components,
                'version' => BJLG_VERSION,
                'created_at' => current_time('mysql'),
                'reason' => 'Sauvegarde automatique avant restauration'
            ];
            $zip->addFromString('backup-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

            // Export de la base de données
            $backup_manager->dump_database($sql_filepath);
            $added_to_zip = $zip->addFile($sql_filepath, 'database.sql');

            if ($added_to_zip !== true) {
                $cleanup_sql_file();
                throw new Exception(
                    "Impossible d'ajouter l'export de la base de données (database.sql) à l'archive de pré-restauration."
                );
            }

            // Ajout des dossiers
            $upload_dir_info = wp_get_upload_dir();
            $directories = [
                [
                    'path' => WP_PLUGIN_DIR,
                    'zip' => 'wp-content/plugins/',
                    'label' => 'plugins',
                ],
                [
                    'path' => get_theme_root(),
                    'zip' => 'wp-content/themes/',
                    'label' => 'thèmes',
                ],
                [
                    'path' => $upload_dir_info['basedir'],
                    'zip' => 'wp-content/uploads/',
                    'label' => 'uploads',
                ],
            ];

            $backup_dir_path = bjlg_get_backup_directory();
            if (function_exists('wp_normalize_path')) {
                $backup_dir_path = wp_normalize_path($backup_dir_path);
            }
            $backup_dir_path = rtrim($backup_dir_path, '/');

            $normalized_backup_filepath = $backup_filepath;
            if (function_exists('wp_normalize_path')) {
                $normalized_backup_filepath = wp_normalize_path($backup_filepath);
            }

            $exclusions = array_values(array_unique(array_filter([
                '*/bjlg-backups',
                '*/bjlg-backups/*',
                $backup_dir_path,
                $backup_dir_path . '/*',
                $normalized_backup_filepath,
            ])));

            if (class_exists('BJLG_Debug')) {
                BJLG_Debug::log('Exclusions appliquées à la sauvegarde pré-restauration : ' . implode(', ', $exclusions));
            }

            foreach ($directories as $directory) {
                try {
                    $backup_manager->add_folder_to_zip($zip, $directory['path'], $directory['zip'], $exclusions);
                } catch (Exception $exception) {
                    $message = sprintf(
                        "Impossible d'ajouter le répertoire %s (%s) à la sauvegarde de sécurité : %s",
                        $directory['label'],
                        $directory['path'],
                        $exception->getMessage()
                    );

                    if (class_exists('BJLG_Debug')) {
                        BJLG_Debug::log($message);
                    }

                    throw new Exception($message, 0, $exception);
                }
            }

            if (class_exists('BJLG_Debug')) {
                $has_backup_dir = (
                    $zip->locateName('wp-content/uploads/bjlg-backups/', ZipArchive::FL_NOCASE) !== false
                    || $zip->locateName('wp-content/uploads/bjlg-backups', ZipArchive::FL_NOCASE) !== false
                );

                if ($has_backup_dir) {
                    BJLG_Debug::log("Contrôle de sécurité : le dossier bjlg-backups est encore présent dans l'archive pré-restauration.");
                } else {
                    BJLG_Debug::log("Contrôle de sécurité : le dossier bjlg-backups est exclu de l'archive pré-restauration.");
                }
            }

            $zip->close();

            BJLG_History::log('pre_restore_backup', 'success', 'Fichier : ' . $backup_filename);
            BJLG_Debug::log("Sauvegarde de sécurité terminée : " . $backup_filename);

            return [
                'filename' => $backup_filename,
                'filepath' => $backup_filepath,
            ];
        } catch (Exception $exception) {
            BJLG_History::log('pre_restore_backup', 'failure', 'Erreur : ' . $exception->getMessage());

            if (class_exists('BJLG_Debug')) {
                BJLG_Debug::log('Sauvegarde de sécurité pré-restauration échouée : ' . $exception->getMessage());
            }

            throw $exception;
        } finally {
            try {
                if ($zip instanceof ZipArchive) {
                    $zip->close();
                }
            } catch (Throwable $close_exception) {
                if (class_exists('BJLG_Debug')) {
                    BJLG_Debug::log('Impossible de fermer l\'archive de pré-restauration : ' . $close_exception->getMessage());
                }
            }

            $cleanup_sql_file();
        }
    }

    /**
     * Prépare la configuration d'environnement pour une restauration.
     *
     * @param string|null $environment
     * @param array<string, mixed> $context
     * @return array{environment: string, routing_table: array<string, string>, sandbox: array<string, mixed>|null}
     */
    public static function prepare_environment($environment, array $context = []) {
        $normalized_environment = is_string($environment) ? sanitize_key($environment) : '';

        if ($normalized_environment === '' || $normalized_environment === self::ENV_PRODUCTION) {
            return [
                'environment' => self::ENV_PRODUCTION,
                'routing_table' => [],
                'sandbox' => null,
            ];
        }

        if ($normalized_environment !== self::ENV_SANDBOX) {
            return [
                'environment' => self::ENV_PRODUCTION,
                'routing_table' => [],
                'sandbox' => null,
            ];
        }

        $sandbox = self::prepare_sandbox_environment($context);

        return [
            'environment' => self::ENV_SANDBOX,
            'routing_table' => $sandbox['routing_table'],
            'sandbox' => $sandbox,
        ];
    }

    /**
     * Indique si l'utilisateur courant peut utiliser les restaurations sandbox.
     *
     * @param mixed $user Utilisateur optionnel.
     * @return bool
     */
    public static function user_can_use_sandbox($user = null) {
        $default_permission = true;

        if (function_exists('bjlg_can_restore_backups')) {
            $default_permission = (bool) \bjlg_can_restore_backups($user);
        } elseif (function_exists('current_user_can')) {
            $default_permission = current_user_can('manage_options');
        }

        if (function_exists('apply_filters')) {
            return (bool) apply_filters('bjlg_user_can_restore_to_sandbox', $default_permission, $user);
        }

        return (bool) $default_permission;
    }

    /**
     * Prépare le dossier sandbox et la table de routage associée.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function prepare_sandbox_environment(array $context) {
        $requested_path = '';
        if (isset($context['sandbox_path']) && is_string($context['sandbox_path'])) {
            $requested_path = trim($context['sandbox_path']);
        }

        $allowed_bases = self::get_allowed_sandbox_bases();
        $base_path = self::resolve_sandbox_path($requested_path, $allowed_bases);

        self::ensure_directory_exists_static($base_path);

        $wp_content = rtrim($base_path, '/\\') . '/wp-content';
        self::ensure_directory_exists_static($wp_content);

        $routing_table = [
            'plugins' => $wp_content . '/plugins',
            'themes' => $wp_content . '/themes',
            'uploads' => $wp_content . '/uploads',
            'db' => rtrim($base_path, '/\\') . '/database.sql',
        ];

        foreach (['plugins', 'themes', 'uploads'] as $key) {
            self::ensure_directory_exists_static($routing_table[$key]);
        }

        $database_directory = dirname($routing_table['db']);
        if ($database_directory !== '' && $database_directory !== '.') {
            self::ensure_directory_exists_static($database_directory);
        }

        $sandbox_data = [
            'base_path' => $base_path,
            'requested_path' => $requested_path,
            'created_at' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
            'routing_table' => $routing_table,
        ];

        if (function_exists('apply_filters')) {
            $sandbox_data = apply_filters('bjlg_restore_prepare_sandbox', $sandbox_data, $context);
            if (!is_array($sandbox_data)) {
                $sandbox_data = [
                    'base_path' => $base_path,
                    'requested_path' => $requested_path,
                    'created_at' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
                    'routing_table' => $routing_table,
                ];
            }
        }

        if (!isset($sandbox_data['routing_table']) || !is_array($sandbox_data['routing_table'])) {
            $sandbox_data['routing_table'] = $routing_table;
        }

        return $sandbox_data;
    }

    /**
     * Retourne la liste des répertoires autorisés pour les sandboxes.
     *
     * @return array<int, string>
     */
    private static function get_allowed_sandbox_bases() {
        $defaults = [
            self::normalize_path(self::get_default_sandbox_base_dir()),
        ];

        if (defined('BJLG_BACKUP_DIR')) {
            $defaults[] = self::normalize_path(bjlg_get_backup_directory());
        }

        if (defined('WP_CONTENT_DIR')) {
            $defaults[] = self::normalize_path(WP_CONTENT_DIR);
        }

        $defaults = array_values(array_filter(array_unique($defaults)));

        if (function_exists('apply_filters')) {
            $allowed = apply_filters('bjlg_restore_sandbox_allowed_paths', $defaults);
            if (is_array($allowed)) {
                $sanitized = [];
                foreach ($allowed as $path) {
                    if (!is_string($path) || $path === '') {
                        continue;
                    }
                    $sanitized[] = self::normalize_path($path);
                }
                if (!empty($sanitized)) {
                    return array_values(array_unique(array_filter($sanitized)));
                }
            }
        }

        return $defaults;
    }

    /**
     * Résout le chemin final de la sandbox.
     *
     * @param string $requested_path
     * @param array<int, string> $allowed_bases
     * @return string
     */
    private static function resolve_sandbox_path($requested_path, array $allowed_bases) {
        $requested_path = self::normalize_path($requested_path);

        if ($requested_path !== '') {
            if (!self::is_absolute_path($requested_path)) {
                $requested_path = rtrim(self::get_default_sandbox_base_dir(), '/\\') . '/' . ltrim($requested_path, '/\\');
            }

            foreach ($allowed_bases as $base) {
                if ($base !== '' && self::path_is_within($requested_path, $base)) {
                    return $requested_path;
                }
            }

            throw new RuntimeException('Le chemin de sandbox fourni est interdit.');
        }

        $base_dir = rtrim(self::get_default_sandbox_base_dir(), '/\\');
        self::ensure_directory_exists_static($base_dir);

        return $base_dir . '/sandbox-' . uniqid('', true);
    }

    /**
     * Retourne le répertoire sandbox par défaut.
     */
    private static function get_default_sandbox_base_dir() {
        $base = defined('BJLG_BACKUP_DIR') ? bjlg_get_backup_directory() : sys_get_temp_dir() . '/bjlg-backups/';

        return rtrim($base, '/\\') . '/sandboxes';
    }

    /**
     * Normalise un chemin.
     */
    private static function normalize_path($path) {
        if (!is_string($path) || $path === '') {
            return '';
        }

        if (function_exists('wp_normalize_path')) {
            $normalized = wp_normalize_path($path);
        } else {
            $normalized = str_replace('\\', '/', $path);
        }

        return rtrim($normalized, '/');
    }

    /**
     * Vérifie si un chemin est absolu.
     */
    private static function is_absolute_path($path) {
        if ($path === '') {
            return false;
        }

        return $path[0] === '/' || preg_match('/^[A-Za-z]:\//', $path) === 1;
    }

    /**
     * Vérifie si un chemin est contenu dans un répertoire de base.
     */
    private static function path_is_within($path, $base) {
        $normalized_path = self::normalize_path($path);
        $normalized_base = self::normalize_path($base);

        if ($normalized_path === '' || $normalized_base === '') {
            return false;
        }

        if ($normalized_path === $normalized_base) {
            return true;
        }

        return strpos($normalized_path . '/', rtrim($normalized_base, '/') . '/') === 0;
    }

    /**
     * S'assure qu'un répertoire existe (version statique).
     */
    private static function ensure_directory_exists_static($directory) {
        if ($directory === '' || is_dir($directory)) {
            return;
        }

        if (function_exists('wp_mkdir_p') && wp_mkdir_p($directory)) {
            return;
        }

        if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de préparer le répertoire de sandbox.');
        }
    }

    /**
     * Gère l'upload d'un fichier de restauration
     */
    public function handle_upload_restore_file() {
        if (!\bjlg_can_restore_backups()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        if (empty($_FILES['restore_file'])) {
            wp_send_json_error([
                'message' => 'Aucun fichier téléversé.',
                'details' => [
                    'reason' => 'missing_file_payload',
                ],
            ]);
        }

        $uploaded_file = $_FILES['restore_file'];

        $error_code = isset($uploaded_file['error']) ? (int) $uploaded_file['error'] : UPLOAD_ERR_OK;
        if ($error_code !== UPLOAD_ERR_OK) {
            $error_info = self::describe_upload_error($error_code);

            wp_send_json_error([
                'message' => $error_info['message'],
                'details' => [
                    'upload_error_code' => $error_code,
                    'upload_error_key' => $error_info['key'],
                ],
            ]);
        }

        $original_filename = isset($uploaded_file['name']) ? $uploaded_file['name'] : '';
        $sanitized_filename = sanitize_file_name(wp_unslash($original_filename));
        if ($sanitized_filename === '') {
            wp_send_json_error([
                'message' => 'Nom de fichier invalide.',
                'details' => [
                    'original_filename' => $original_filename,
                ],
            ]);
        }

        // Vérifications de sécurité
        $allowed_mimes = [
            'zip' => 'application/zip',
            'enc' => 'application/octet-stream',
        ];
        $checked_file = wp_check_filetype_and_ext(
            $uploaded_file['tmp_name'],
            $sanitized_filename,
            $allowed_mimes
        );

        if (empty($checked_file['ext']) || empty($checked_file['type']) || !array_key_exists($checked_file['ext'], $allowed_mimes)) {
            wp_send_json_error([
                'message' => 'Type ou extension de fichier non autorisé.',
                'details' => [
                    'allowed_extensions' => array_keys($allowed_mimes),
                    'detected_extension' => $checked_file['ext'],
                    'detected_type' => $checked_file['type'],
                ],
            ]);
        }

        if (!wp_mkdir_p(bjlg_get_backup_directory())) {
            wp_send_json_error([
                'message' => 'Répertoire de sauvegarde inaccessible.',
                'details' => [
                    'backup_directory' => bjlg_get_backup_directory(),
                ],
            ]);
        }

        $is_writable = function_exists('wp_is_writable') ? wp_is_writable(bjlg_get_backup_directory()) : is_writable(bjlg_get_backup_directory());
        if (!$is_writable) {
            wp_send_json_error([
                'message' => 'Répertoire de sauvegarde non accessible en écriture.',
                'details' => [
                    'backup_directory' => bjlg_get_backup_directory(),
                ],
            ]);
        }

        if (!empty($uploaded_file['tmp_name'])) {
            $is_uploaded = true;

            if (function_exists('is_uploaded_file')) {
                $is_uploaded = is_uploaded_file($uploaded_file['tmp_name']);
            }

            $is_uploaded = apply_filters(
                'bjlg_restore_validate_is_uploaded_file',
                $is_uploaded,
                $uploaded_file
            );

            if (!$is_uploaded) {
                wp_send_json_error([
                    'message' => 'Le fichier fourni n\'est pas un téléversement valide.',
                    'details' => [
                        'tmp_name' => $uploaded_file['tmp_name'],
                    ],
                ]);
            }
        }

        if (!function_exists('wp_handle_upload')) {
            $maybe_admin_file = rtrim(ABSPATH, '/\\') . '/wp-admin/includes/file.php';
            if (is_readable($maybe_admin_file)) {
                require_once $maybe_admin_file;
            }
        }

        if (!function_exists('wp_handle_upload')) {
            wp_send_json_error([
                'message' => 'La fonction de gestion des téléversements est indisponible.',
                'details' => [
                    'function' => 'wp_handle_upload',
                ],
            ]);
        }

        $handled_upload = wp_handle_upload($uploaded_file, ['test_form' => false]);

        if (is_wp_error($handled_upload)) {
            wp_send_json_error([
                'message' => 'Impossible de traiter le fichier téléversé : ' . $handled_upload->get_error_message(),
                'details' => [
                    'wp_error_code' => $handled_upload->get_error_code(),
                    'wp_error_message' => $handled_upload->get_error_message(),
                ],
            ]);
        }

        if (isset($handled_upload['error'])) {
            $error_message = is_string($handled_upload['error'])
                ? $handled_upload['error']
                : 'Erreur inconnue lors du traitement du fichier téléversé.';

            wp_send_json_error([
                'message' => 'Impossible de traiter le fichier téléversé : ' . $error_message,
                'details' => [
                    'wp_handle_upload_error' => $error_message,
                ],
            ]);
        }

        if (empty($handled_upload['file']) || !file_exists($handled_upload['file'])) {
            wp_send_json_error([
                'message' => 'Le fichier téléversé est introuvable après traitement.',
                'details' => [
                    'handled_upload' => $handled_upload,
                ],
            ]);
        }

        // Déplacer le fichier uploadé vers le répertoire des sauvegardes
        $destination = bjlg_get_backup_directory() . 'restore_' . uniqid('', true) . '_' . $sanitized_filename;
        $moved = @rename($handled_upload['file'], $destination);

        if (!$moved) {
            $moved = @copy($handled_upload['file'], $destination);

            if ($moved) {
                @unlink($handled_upload['file']);
            }
        }

        if (!$moved) {
            wp_send_json_error([
                'message' => 'Impossible de déplacer le fichier téléversé vers le répertoire des sauvegardes.',
                'details' => [
                    'source' => $handled_upload['file'],
                    'destination' => $destination,
                ],
            ]);
        }

        wp_send_json_success([
            'message' => 'Fichier téléversé avec succès.',
            'filename' => basename($destination),
            'filepath' => $destination,
            'details' => [
                'original_filename' => $original_filename,
                'sanitized_filename' => $sanitized_filename,
            ],
        ]);
    }

    /**
     * Retourne un message détaillé pour un code d'erreur d'upload.
     *
     * @param int $error_code
     * @return array{key: string, message: string}
     */
    private static function describe_upload_error(int $error_code): array
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE => [
                'key' => 'ini_size_limit_exceeded',
                'message' => 'Le fichier dépasse la taille maximale autorisée par la configuration PHP.',
            ],
            UPLOAD_ERR_FORM_SIZE => [
                'key' => 'form_size_limit_exceeded',
                'message' => 'Le fichier dépasse la taille maximale autorisée par le formulaire.',
            ],
            UPLOAD_ERR_PARTIAL => [
                'key' => 'partial_upload',
                'message' => "Le fichier n'a été que partiellement téléversé.",
            ],
            UPLOAD_ERR_NO_FILE => [
                'key' => 'no_file',
                'message' => 'Aucun fichier téléversé.',
            ],
            UPLOAD_ERR_NO_TMP_DIR => [
                'key' => 'missing_tmp_dir',
                'message' => 'Le dossier temporaire est manquant sur le serveur.',
            ],
            UPLOAD_ERR_CANT_WRITE => [
                'key' => 'cannot_write',
                'message' => 'Impossible d\'écrire le fichier sur le disque.',
            ],
            UPLOAD_ERR_EXTENSION => [
                'key' => 'extension_stopped_upload',
                'message' => 'Une extension PHP a interrompu le téléversement.',
            ],
        ];

        if (isset($messages[$error_code])) {
            return $messages[$error_code];
        }

        return [
            'key' => 'unknown_error',
            'message' => sprintf(
                'Erreur lors du téléversement du fichier (code %d).',
                $error_code
            ),
        ];
    }

    /**
     * Exécute la restauration granulaire à partir d'un fichier de sauvegarde.
     */
    public function handle_run_restore() {
        if (!\bjlg_can_restore_backups()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        if (empty($_POST['filename'])) {
            wp_send_json_error(['message' => 'Nom de fichier manquant.']);
        }

        $filename = basename(sanitize_file_name($_POST['filename']));

        $resolved_path = BJLG_Backup_Path_Resolver::resolve($filename);

        if (is_wp_error($resolved_path)) {
            $error_code = $resolved_path->get_error_code();
            $status = 400;
            $data = $resolved_path->get_error_data();

            if (is_array($data) && isset($data['status'])) {
                $status = (int) $data['status'];
            }

            if ($error_code === 'backup_not_found') {
                wp_send_json_error(['message' => 'Fichier de sauvegarde introuvable.'], $status);
            }

            wp_send_json_error(['message' => 'La sauvegarde demandée est invalide.'], $status);
        }

        $filepath = $resolved_path;
        $filename = basename($filepath);
        $is_encrypted_backup = substr($filename, -4) === '.enc';

        $create_backup_before_restore = false;
        if (array_key_exists('create_backup_before_restore', $_POST)) {
            $raw_create_backup_flag = $_POST['create_backup_before_restore'];

            if (is_string($raw_create_backup_flag)) {
                $raw_create_backup_flag = wp_unslash($raw_create_backup_flag);
                $filtered_value = filter_var($raw_create_backup_flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $create_backup_before_restore = ($filtered_value !== null) ? $filtered_value : !empty($raw_create_backup_flag);
            } else {
                $create_backup_before_restore = !empty($raw_create_backup_flag);
            }
        }

        $raw_components = $_POST['components'] ?? null;
        if ($raw_components !== null) {
            $raw_components = wp_unslash($raw_components);
        }

        $requested_components = self::normalize_requested_components($raw_components);

        $restore_environment = self::ENV_PRODUCTION;
        if (array_key_exists('restore_environment', $_POST)) {
            $raw_environment = wp_unslash($_POST['restore_environment']);
            if (is_string($raw_environment)) {
                $restore_environment = sanitize_key($raw_environment);
            }
        }

        if ($restore_environment === self::ENV_SANDBOX && !self::user_can_use_sandbox()) {
            wp_send_json_error([
                'message' => 'Permission insuffisante pour utiliser la sandbox.',
            ], 403);
        }

        $sandbox_path = '';
        if (array_key_exists('sandbox_path', $_POST)) {
            $raw_sandbox_path = wp_unslash($_POST['sandbox_path']);
            if (is_string($raw_sandbox_path)) {
                $sandbox_path = trim($raw_sandbox_path);
            }
        }

        try {
            $environment_config = self::prepare_environment($restore_environment, [
                'sandbox_path' => $sandbox_path,
            ]);
        } catch (Exception $exception) {
            $error_message = $exception->getMessage();
            $response = [
                'message' => 'Impossible de préparer la cible de restauration : ' . $error_message,
            ];

            if ($restore_environment === self::ENV_SANDBOX) {
                $response['validation_errors'] = [
                    'sandbox_path' => [$error_message],
                ];
            }

            wp_send_json_error($response, 400);
        }

        $password = null;
        if (array_key_exists('password', $_POST)) {
            $maybe_password = wp_unslash($_POST['password']);
            if (is_string($maybe_password)) {
                $password = $maybe_password;
            }
        }

        if ($password !== null) {
            if ($password === '') {
                $password = null;
            } elseif (strlen($password) < 4) {
                $message = 'Le mot de passe doit contenir au moins 4 caractères.';
                wp_send_json_error([
                    'message' => $message,
                    'validation_errors' => [
                        'password' => [$message],
                    ],
                ]);
            }
        }

        if ($is_encrypted_backup && $password === null) {
            $message = 'Un mot de passe est requis pour restaurer une sauvegarde chiffrée.';
            wp_send_json_error([
                'message' => $message,
                'validation_errors' => [
                    'password' => [$message],
                ],
            ]);
        }

        try {
            $encrypted_password = self::encrypt_password_for_transient($password);
        } catch (Exception $exception) {
            if (class_exists(BJLG_Debug::class)) {
                BJLG_Debug::log('Échec du chiffrement du mot de passe de restauration : ' . $exception->getMessage(), 'error');
            }
            wp_send_json_error(['message' => 'Impossible de sécuriser le mot de passe fourni.']);
        }

        // Créer une tâche de restauration
        $task_id = 'bjlg_restore_' . md5(uniqid('restore', true));
        $task_data = [
            'progress' => 0,
            'status' => 'pending',
            'status_text' => 'Initialisation de la restauration...',
            'filename' => $filename,
            'filepath' => $filepath,
            'password_encrypted' => $encrypted_password,
            'create_restore_point' => $create_backup_before_restore,
            'components' => $requested_components,
            'environment' => $environment_config['environment'],
            'routing_table' => $environment_config['routing_table'],
        ];

        if (!empty($environment_config['sandbox'])) {
            $task_data['sandbox'] = $environment_config['sandbox'];
        }

        if (!BJLG_Backup::reserve_task_slot($task_id)) {
            if (class_exists('BJLG_Debug')) {
                BJLG_Debug::log("Impossible de démarrer la restauration {$task_id} : une autre tâche est en cours.");
            }

            wp_send_json_error([
                'message' => "Une autre restauration est déjà en cours d'exécution.",
            ], 409);

            return;
        }

        $transient_set = set_transient($task_id, $task_data, BJLG_Backup::get_task_ttl());

        if ($transient_set === false) {
            if (class_exists(BJLG_Debug::class)) {
                BJLG_Debug::log("ERREUR : Impossible d'initialiser la tâche de restauration {$task_id}.");
            }

            BJLG_Backup::release_task_slot($task_id);

            wp_send_json_error(['message' => "Impossible d'initialiser la tâche de restauration."], 500);

            return;
        }

        // Planifier l'exécution
        $scheduled = wp_schedule_single_event(time(), 'bjlg_run_restore_task', ['task_id' => $task_id]);

        if ($scheduled === false || is_wp_error($scheduled)) {
            delete_transient($task_id);

            BJLG_Backup::release_task_slot($task_id);

            $error_details = is_wp_error($scheduled) ? $scheduled->get_error_message() : null;

            if (class_exists(BJLG_Debug::class)) {
                $log_message = "ERREUR : Impossible de planifier la tâche de restauration {$task_id}.";

                if (!empty($error_details)) {
                    $log_message .= ' Détails : ' . $error_details;
                }

                BJLG_Debug::log($log_message);
            }

            $response = ['message' => "Impossible de planifier la tâche de restauration en arrière-plan."];

            if (!empty($error_details)) {
                $response['details'] = $error_details;
            }

            wp_send_json_error($response, 500);

            return;
        }

        BJLG_Backup::release_task_slot($task_id);

        wp_send_json_success(['task_id' => $task_id]);
    }

    /**
     * Vérifie la progression de la restauration
     */
    public function handle_check_restore_progress() {
        if (!\bjlg_can_restore_backups()) {
            wp_send_json_error(['message' => 'Permission refusée.']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');

        $task_id = sanitize_key($_POST['task_id']);
        $progress_data = get_transient($task_id);

        if ($progress_data === false) {
            wp_send_json_error(['message' => 'Tâche non trouvée.']);
        }

        wp_send_json_success($progress_data);
    }

    /**
     * Exécute la tâche de restauration en arrière-plan
     */
    public function run_restore_task($task_id) {
        if (!BJLG_Backup::reserve_task_slot($task_id)) {
            if (class_exists('BJLG_Debug')) {
                BJLG_Debug::log("Tâche de restauration {$task_id} retardée : un autre processus utilise le verrou.");
            }

            $rescheduled = wp_schedule_single_event(time() + 30, 'bjlg_run_restore_task', ['task_id' => $task_id]);

            if ($rescheduled === false && class_exists('BJLG_Debug')) {
                BJLG_Debug::log("Échec de la replanification de la tâche de restauration {$task_id}.");
            }

            return;
        }

        $task_data = get_transient($task_id);
        if (!$task_data) {
            BJLG_Debug::log("ERREUR: Tâche de restauration $task_id introuvable.");
            BJLG_Backup::release_task_slot($task_id);
            return;
        }

        $environment = self::ENV_PRODUCTION;
        if (is_array($task_data) && isset($task_data['environment']) && is_string($task_data['environment'])) {
            $maybe_environment = sanitize_key($task_data['environment']);
            if ($maybe_environment === self::ENV_SANDBOX) {
                $environment = self::ENV_SANDBOX;
            }
        }

        $routing_table = [];
        if (isset($task_data['routing_table']) && is_array($task_data['routing_table'])) {
            $routing_table = $task_data['routing_table'];
        }

        $sandbox_context = null;
        if (isset($task_data['sandbox']) && is_array($task_data['sandbox'])) {
            $sandbox_context = $task_data['sandbox'];
        }

        $filepath = $task_data['filepath'];
        $encrypted_password = $task_data['password_encrypted'] ?? null;
        $password = null;
        $original_archive_path = $filepath;
        $decrypted_archive_path = null;
        $create_restore_point = !empty($task_data['create_restore_point']);
        $requested_components = self::normalize_requested_components($task_data['components'] ?? null);
        $current_status = is_array($task_data) ? $task_data : [];
        $current_status['environment'] = $environment;

        if (!empty($encrypted_password)) {
            try {
                $password = $this->decrypt_password_from_transient($encrypted_password);
            } catch (Exception $exception) {
                if (class_exists(BJLG_Debug::class)) {
                    BJLG_Debug::log(
                        "ERREUR: Échec du déchiffrement du mot de passe pour la tâche {$task_id} : " . $exception->getMessage(),
                        'error'
                    );
                }
            }
        }

        $temp_extract_dir = bjlg_get_backup_directory() . 'temp_restore_' . uniqid();
        $final_error_status = null;
        $error_status_recorded = false;

        try {
            set_time_limit(0);
            @ini_set('memory_limit', '256M');

            BJLG_Debug::log("Début de la restauration pour le fichier : " . basename($filepath));

            if ($create_restore_point) {
                $current_status = array_merge($current_status, [
                    'progress' => 5,
                    'status' => 'running',
                    'status_text' => 'Création d\'un point de restauration...'
                ]);
                set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());

                $this->perform_pre_restore_backup();
            }

            $current_status = array_merge($current_status, [
                'progress' => 10,
                'status' => 'running',
                'status_text' => 'Vérification du fichier de sauvegarde...'
            ]);
            set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());

            if (!file_exists($filepath)) {
                throw new Exception("Le fichier de sauvegarde n'a pas été trouvé.");
            }

            if (substr($filepath, -4) === '.enc') {
                $current_status = array_merge($current_status, [
                    'progress' => 20,
                    'status' => 'running',
                    'status_text' => 'Déchiffrement de l\'archive...'
                ]);
                set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());

                $encryption = $this->get_encryption_handler();

                if (!($encryption instanceof BJLG_Encryption)) {
                    throw new Exception('Module de chiffrement indisponible.');
                }

                $decrypted_archive_path = $encryption->decrypt_backup_file($filepath, $password);
                $filepath = $decrypted_archive_path;
            }

            if (!mkdir($temp_extract_dir, 0755, true)) {
                throw new Exception("Impossible de créer le répertoire temporaire.");
            }

            $zip = new ZipArchive();
            if ($zip->open($filepath) !== true) {
                throw new Exception("Impossible d'ouvrir l'archive. Fichier corrompu ?");
            }

            $manifest_json = $zip->getFromName('backup-manifest.json');
            if ($manifest_json === false) {
                throw new Exception("Manifeste de sauvegarde manquant.");
            }

            $manifest = json_decode($manifest_json, true);
            $allowed_components = ['db', 'plugins', 'themes', 'uploads'];
            $manifest_components = [];

            if (is_array($manifest) && !empty($manifest['contains']) && is_array($manifest['contains'])) {
                foreach ($manifest['contains'] as $component) {
                    if (!is_string($component)) {
                        continue;
                    }

                    $component_key = sanitize_key($component);

                    if (
                        in_array($component_key, $allowed_components, true)
                        && !in_array($component_key, $manifest_components, true)
                    ) {
                        $manifest_components[] = $component_key;
                    }
                }
            }

            $components_to_restore = array_values(array_intersect($manifest_components, $requested_components));

            $is_incremental_backup = false;
            if (isset($manifest['type']) && is_string($manifest['type'])) {
                $is_incremental_backup = sanitize_key($manifest['type']) === 'incremental';
            }

            $deleted_paths_by_component = [
                'plugins' => [],
                'themes' => [],
                'uploads' => [],
            ];

            if ($is_incremental_backup) {
                try {
                    $deleted_paths_by_component = $this->extract_deleted_paths_from_archive($zip);
                } catch (Throwable $deleted_exception) {
                    BJLG_Debug::log('Impossible de charger la liste des fichiers supprimés : ' . $deleted_exception->getMessage(), 'error');
                }
            }

            if (class_exists('BJLG_Debug')) {
                BJLG_Debug::log(
                    sprintf(
                        'Composants demandés : %s | Présents dans le manifeste : %s | Retenus : %s',
                        empty($requested_components) ? 'aucun' : implode(', ', $requested_components),
                        empty($manifest_components) ? 'aucun' : implode(', ', $manifest_components),
                        empty($components_to_restore) ? 'aucun' : implode(', ', $components_to_restore)
                    )
                );
            }

            if (empty($components_to_restore)) {
                if (empty($manifest_components)) {
                    $error_message = "Aucun composant utile n'a été trouvé dans le manifeste de sauvegarde.";
                } else {
                    $error_message = "Les composants demandés ne sont pas disponibles dans l'archive de sauvegarde.";
                }

                if (class_exists('BJLG_Debug')) {
                    BJLG_Debug::log('ERREUR: ' . $error_message, 'error');
                }

                $current_status = array_merge($current_status, [
                    'progress' => 100,
                    'status' => 'error',
                    'status_text' => $error_message,
                ]);

                try {
                    set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());
                    $error_status_recorded = true;
                } catch (Throwable $transient_exception) {
                    BJLG_Debug::log(
                        "ERREUR: Impossible de mettre à jour le statut de la tâche {$task_id} : " . $transient_exception->getMessage(),
                        'error'
                    );
                }

                $history_message = $error_message;

                if ($environment === self::ENV_SANDBOX) {
                    $sandbox_base_path = $this->get_sandbox_base_path($sandbox_context);
                    if (!empty($sandbox_base_path)) {
                        $history_message .= ' (sandbox : ' . $sandbox_base_path . ')';
                    }
                }

                try {
                    BJLG_History::log('restore_run', 'failure', $history_message);
                } catch (Throwable $history_exception) {
                    BJLG_Debug::log(
                        "ERREUR: Impossible d'enregistrer l'échec de la restauration : " . $history_exception->getMessage(),
                        'error'
                    );
                }

                $final_error_status = $current_status;

                $zip->close();

                if (is_dir($temp_extract_dir)) {
                    $this->recursive_delete($temp_extract_dir);
                }

                return;
            }

            if (in_array('db', $components_to_restore, true)) {
                $current_status = array_merge($current_status, [
                    'progress' => 30,
                    'status' => 'running',
                    'status_text' => $environment === self::ENV_SANDBOX
                        ? 'Préparation de la base de données sandbox...'
                        : 'Restauration de la base de données...'
                ]);
                set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());

                if ($zip->locateName('database.sql') !== false) {
                    $allowed_entries = $this->build_allowed_zip_entries($zip, $temp_extract_dir);

                    if (!array_key_exists('database.sql', $allowed_entries)) {
                        throw new Exception("Entrée d'archive invalide détectée : database.sql");
                    }

                    $zip->extractTo($temp_extract_dir, 'database.sql');
                    $sql_filepath = $temp_extract_dir . '/database.sql';

                    if ($environment === self::ENV_SANDBOX) {
                        $db_destination = $this->resolve_database_destination($routing_table, $sandbox_context);

                        if ($db_destination === null) {
                            BJLG_Debug::log('Sandbox : aucun emplacement de base de données défini, export ignoré.');
                        } else {
                            $database_directory = dirname($db_destination);
                            if ($database_directory !== '' && $database_directory !== '.') {
                                self::ensure_directory_exists_static($database_directory);
                            }

                            if (!@copy($sql_filepath, $db_destination)) {
                                throw new Exception("Impossible de copier le dump SQL vers la sandbox.");
                            }

                            BJLG_Debug::log('Dump SQL copié dans la sandbox : ' . $db_destination);
                        }

                        $status_text = 'Base de données exportée vers la sandbox.';
                    } else {
                        BJLG_Debug::log("Import de la base de données...");
                        $this->import_database($sql_filepath);
                        $status_text = 'Base de données restaurée.';
                    }

                    $current_status = array_merge($current_status, [
                        'progress' => 50,
                        'status' => 'running',
                        'status_text' => $status_text,
                    ]);
                    set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());
                }
            }

            $folders_to_restore = [];

            foreach (['plugins', 'themes', 'uploads'] as $component_type) {
                if (!in_array($component_type, $components_to_restore, true)) {
                    continue;
                }

                $destination = $this->resolve_component_destination($component_type, $routing_table, $environment);

                if ($destination === null) {
                    if (class_exists('BJLG_Debug')) {
                        BJLG_Debug::log("Destination introuvable pour {$component_type} dans l'environnement {$environment}, composant ignoré.");
                    }

                    continue;
                }

                $folders_to_restore[$component_type] = [
                    'path' => $destination,
                    'deleted' => $deleted_paths_by_component[$component_type] ?? [],
                ];
            }

            $progress = $current_status['progress'] ?? 50;

            if (!empty($folders_to_restore)) {
                $progress_step = 40 / count($folders_to_restore);

                foreach ($folders_to_restore as $type => $restore_payload) {
                    $destination = is_array($restore_payload) && array_key_exists('path', $restore_payload)
                        ? $restore_payload['path']
                        : $restore_payload;

                    $progress += $progress_step;

                    $current_status = array_merge($current_status, [
                        'progress' => (int) round($progress),
                        'status' => 'running',
                        'status_text' => "Restauration des {$type}..."
                    ]);
                    set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());

                    $source_folder = "wp-content/{$type}";
                    $component_deleted = [];
                    if (is_array($restore_payload) && array_key_exists('deleted', $restore_payload)) {
                        $component_deleted = is_array($restore_payload['deleted']) ? $restore_payload['deleted'] : [];
                    }

                    if (!empty($component_deleted)) {
                        $this->apply_deleted_paths($type, $component_deleted, $destination, $environment);
                    }

                    $files_to_extract = [];

                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $file = $zip->getNameIndex($i);
                        if ($file !== false && strpos($file, $source_folder) === 0) {
                            $files_to_extract[] = $file;
                        }
                    }

                    if (!empty($files_to_extract)) {
                        $allowed_entries = $this->build_allowed_zip_entries($zip, $temp_extract_dir);

                        foreach ($files_to_extract as $file_to_extract) {
                            if (!array_key_exists($file_to_extract, $allowed_entries)) {
                                throw new Exception("Entrée d'archive invalide détectée : {$file_to_extract}");
                            }
                        }

                        $zip->extractTo($temp_extract_dir, $files_to_extract);

                        $this->recursive_copy(
                            $temp_extract_dir . '/' . $source_folder,
                            $destination
                        );

                        BJLG_Debug::log("Restauration de {$type} terminée.");
                    }
                }
            }

            $zip->close();

            $current_status = array_merge($current_status, [
                'progress' => 95,
                'status' => 'running',
                'status_text' => 'Nettoyage...'
            ]);
            set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());

            $this->recursive_delete($temp_extract_dir);
            $this->clear_all_caches();

            $log_details = "Fichier : " . basename($original_archive_path);

            if ($environment === self::ENV_SANDBOX) {
                $sandbox_base_path = $this->get_sandbox_base_path($sandbox_context);
                if (!empty($sandbox_base_path)) {
                    $log_details .= ' | Sandbox : ' . $sandbox_base_path;
                }
            }

            BJLG_History::log('restore_run', 'success', $log_details);

            $current_status = array_merge($current_status, [
                'progress' => 100,
                'status' => 'complete',
                'status_text' => 'Restauration terminée avec succès !'
            ]);
            set_transient($task_id, $current_status, BJLG_Backup::get_task_ttl());

        } catch (Throwable $throwable) {
            $error_message = 'Erreur : ' . $throwable->getMessage();
            $current_status = array_merge($current_status, [
                'progress' => 100,
                'status' => 'error',
                'status_text' => $error_message,
            ]);
            $final_error_status = $current_status;

            $history_message = $error_message;

            if ($environment === self::ENV_SANDBOX) {
                $sandbox_base_path = $this->get_sandbox_base_path($sandbox_context);
                if (!empty($sandbox_base_path)) {
                    $history_message .= ' (sandbox : ' . $sandbox_base_path . ')';
                }
            }

            try {
                BJLG_History::log('restore_run', 'failure', $history_message);
            } catch (Throwable $history_exception) {
                BJLG_Debug::log(
                    "ERREUR: Impossible d'enregistrer l'échec de la restauration : " . $history_exception->getMessage(),
                    'error'
                );
            }

            if (is_dir($temp_extract_dir)) {
                $this->recursive_delete($temp_extract_dir);
            }

            try {
                set_transient($task_id, $final_error_status, BJLG_Backup::get_task_ttl());
                $error_status_recorded = true;
            } catch (Throwable $transient_exception) {
                BJLG_Debug::log(
                    "ERREUR: Impossible de mettre à jour le statut de la tâche {$task_id} : " . $transient_exception->getMessage(),
                    'error'
                );
            }
        } finally {
            if ($decrypted_archive_path && $decrypted_archive_path !== $original_archive_path) {
                if (file_exists($decrypted_archive_path)) {
                    if (@unlink($decrypted_archive_path)) {
                        BJLG_Debug::log('Suppression du fichier déchiffré temporaire : ' . basename($decrypted_archive_path));
                    } else {
                        $cleanup_message = 'Impossible de supprimer le fichier déchiffré temporaire : ' . $decrypted_archive_path;
                        BJLG_Debug::log($cleanup_message, 'error');
                        BJLG_History::log('restore_cleanup', 'failure', $cleanup_message);
                    }
                }
            }

            if ($final_error_status !== null && !$error_status_recorded) {
                try {
                    set_transient($task_id, $final_error_status, BJLG_Backup::get_task_ttl());
                    $error_status_recorded = true;
                } catch (Throwable $transient_exception) {
                    BJLG_Debug::log(
                        "ERREUR: Impossible de mettre à jour le statut final de la tâche {$task_id} : " . $transient_exception->getMessage(),
                        'error'
                    );
                }
            }

            BJLG_Backup::release_task_slot($task_id);
        }
    }

    /**
     * Extrait les chemins marqués comme supprimés dans une archive incrémentale.
     *
     * @param ZipArchive $zip
     * @return array<string, array<int, string>>
     */
    private function extract_deleted_paths_from_archive(ZipArchive $zip): array {
        $result = [
            'plugins' => [],
            'themes' => [],
            'uploads' => [],
        ];

        $deleted_index = $zip->locateName('deleted-files.json');

        if ($deleted_index === false) {
            return $result;
        }

        $deleted_json = $zip->getFromIndex($deleted_index);

        if ($deleted_json === false) {
            return $result;
        }

        $decoded = json_decode($deleted_json, true);

        if (!is_array($decoded)) {
            return $result;
        }

        $paths = [];

        if (isset($decoded['paths']) && is_array($decoded['paths'])) {
            $paths = $decoded['paths'];
        } elseif (isset($decoded['deleted_paths']) && is_array($decoded['deleted_paths'])) {
            $paths = $decoded['deleted_paths'];
        }

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            $normalized = self::normalize_path($path);

            if ($normalized === '') {
                continue;
            }

            if (strpos($normalized, 'wp-content/plugins/') === 0) {
                $result['plugins'][] = ltrim(substr($normalized, strlen('wp-content/plugins/')), '/');
                continue;
            }

            if (strpos($normalized, 'wp-content/themes/') === 0) {
                $result['themes'][] = ltrim(substr($normalized, strlen('wp-content/themes/')), '/');
                continue;
            }

            if (strpos($normalized, 'wp-content/uploads/') === 0) {
                $result['uploads'][] = ltrim(substr($normalized, strlen('wp-content/uploads/')), '/');
            }
        }

        return $result;
    }

    /**
     * Applique la suppression des fichiers marqués comme retirés pour un composant donné.
     *
     * @param string $component
     * @param array<int, string> $relative_paths
     * @param string $destination
     * @param string $environment
     * @return void
     */
    private function apply_deleted_paths($component, array $relative_paths, $destination, $environment): void {
        if (empty($relative_paths) || !is_string($destination) || $destination === '') {
            return;
        }

        $normalized_destination = $this->normalize_path_for_validation($destination);

        if ($normalized_destination === '') {
            return;
        }

        foreach ($relative_paths as $relative_path) {
            if (!is_string($relative_path) || $relative_path === '') {
                continue;
            }

            $sanitized_relative = ltrim(self::normalize_path($relative_path), '/');

            if ($sanitized_relative === '') {
                continue;
            }

            $target_path = $this->join_paths($destination, $sanitized_relative);
            $normalized_target = $this->normalize_path_for_validation($target_path);

            if ($normalized_target === '') {
                continue;
            }

            if ($normalized_target !== $normalized_destination
                && strpos($normalized_target, $normalized_destination . '/') !== 0
            ) {
                BJLG_Debug::log(sprintf('Chemin de suppression ignoré (%s) : %s', $component, $normalized_target));
                continue;
            }

            $this->delete_path_atomically($target_path, $component, $environment);
        }
    }

    /**
     * Supprime un chemin en deux étapes afin de limiter les états intermédiaires.
     *
     * @param string $target_path
     * @param string $component
     * @param string $environment
     * @return void
     */
    private function delete_path_atomically($target_path, $component, $environment): void {
        $normalized_target = self::normalize_path($target_path);

        $context = $environment === self::ENV_SANDBOX ? 'SANDBOX' : 'PROD';

        if ($normalized_target === '') {
            return;
        }

        if (!file_exists($target_path) && !is_dir($target_path) && !is_link($target_path)) {
            BJLG_Debug::log(sprintf('[%s] Suppression ignorée (%s) : %s (absent)', $context, $component, $normalized_target));

            return;
        }

        if (is_link($target_path)) {
            throw new RuntimeException('Lien symbolique détecté lors de la suppression : ' . $target_path);
        }

        $parent_directory = dirname($target_path);
        if ($parent_directory === '' || $parent_directory === '.' || $parent_directory === DIRECTORY_SEPARATOR) {
            throw new RuntimeException('Chemin de suppression invalide : ' . $target_path);
        }

        $temporary_path = rtrim($parent_directory, '/\\') . '/.bjlg-restore-delete-' . uniqid('', true);

        if (@rename($target_path, $temporary_path)) {
            BJLG_Debug::log(sprintf('[%s] Suppression atomique (%s) : %s', $context, $component, $normalized_target));
            $this->safe_delete_path($temporary_path);

            return;
        }

        BJLG_Debug::log(sprintf('[%s] Suppression directe (%s) : %s', $context, $component, $normalized_target));
        $this->safe_delete_path($target_path);
    }

    /**
     * Construit la liste des entrées d'archive autorisées pour une extraction sécurisée.
     *
     * @param ZipArchive $zip
     * @param string     $temp_extract_dir
     * @return array<string, string>
     * @throws Exception
     */
    private function build_allowed_zip_entries(ZipArchive $zip, $temp_extract_dir) {
        $allowed_entries = [];

        $base_realpath = realpath($temp_extract_dir);
        if ($base_realpath === false) {
            throw new Exception('Impossible de valider le répertoire temporaire.');
        }

        $base_realpath = $this->normalize_path_for_validation($base_realpath);

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry_name = $zip->getNameIndex($index);

            if ($entry_name === false) {
                continue;
            }

            $entry_stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if ($this->is_zip_entry_symlink($zip, $index, $entry_stat)) {
                throw new Exception("Entrée d'archive non supportée (lien symbolique) détectée : {$entry_name}");
            }

            $normalized_entry = $this->normalize_zip_entry_name($entry_name);

            if ($normalized_entry === '') {
                $allowed_entries[$entry_name] = '';
                continue;
            }

            if (strpos($normalized_entry, '..') !== false) {
                throw new Exception("Entrée d'archive invalide détectée : {$entry_name}");
            }

            if ($normalized_entry[0] === '/' || preg_match('/^[A-Za-z]:/', $normalized_entry) === 1) {
                throw new Exception("Entrée d'archive invalide détectée : {$entry_name}");
            }

            $relative_entry = ltrim($normalized_entry, '/');
            $target_path = $this->join_paths($temp_extract_dir, $relative_entry);

            if (substr($normalized_entry, -1) === '/') {
                $relative_directory = rtrim($relative_entry, '/');
                $intended_directory = $relative_directory === ''
                    ? $base_realpath
                    : $this->normalize_path_for_validation($this->join_paths($base_realpath, $relative_directory));

                $this->assert_path_within_base($base_realpath, $intended_directory, $entry_name);

                $directory_path = $relative_directory === ''
                    ? $temp_extract_dir
                    : $this->join_paths($temp_extract_dir, $relative_directory);

                $this->ensure_directory_exists($directory_path);

                $real_target = realpath($directory_path);
                if ($real_target === false) {
                    throw new Exception("Entrée d'archive invalide détectée : {$entry_name}");
                }

                $real_target = $this->normalize_path_for_validation($real_target);
                $this->assert_path_within_base($base_realpath, $real_target, $entry_name);

                $allowed_entries[$entry_name] = $relative_entry;
                continue;
            }

            $relative_parent = ltrim(dirname($relative_entry), '/');
            $normalized_parent = $relative_parent === '' || $relative_parent === '.'
                ? $base_realpath
                : $this->normalize_path_for_validation($this->join_paths($base_realpath, $relative_parent));

            $this->assert_path_within_base($base_realpath, $normalized_parent, $entry_name);

            $final_intended_path = $this->normalize_path_for_validation($this->join_paths($base_realpath, $relative_entry));
            $this->assert_path_within_base($base_realpath, $final_intended_path, $entry_name);

            $parent_directory = dirname($target_path);
            if ($parent_directory !== '' && $parent_directory !== '.' && $parent_directory !== DIRECTORY_SEPARATOR) {
                $this->ensure_directory_exists($parent_directory);
            }

            $real_parent = realpath($parent_directory ?: $temp_extract_dir);
            if ($real_parent === false) {
                throw new Exception("Entrée d'archive invalide détectée : {$entry_name}");
            }

            $real_parent = $this->normalize_path_for_validation($real_parent);
            $this->assert_path_within_base($base_realpath, $real_parent, $entry_name);

            $final_candidate = $this->normalize_path_for_validation($real_parent . '/' . basename($target_path));
            $this->assert_path_within_base($base_realpath, $final_candidate, $entry_name);

            $allowed_entries[$entry_name] = $relative_entry;
        }

        return $allowed_entries;
    }

    /**
     * Normalise la liste des composants demandés pour la restauration.
     *
     * @param mixed $components
     * @param bool  $default_to_all Si vrai, retourne tous les composants lorsque l'entrée est vide.
     * @return array<int, string>
     */
    public static function normalize_requested_components($components, $default_to_all = true) {
        $allowed_components = ['db', 'plugins', 'themes', 'uploads'];

        if ($components === null) {
            return $default_to_all ? $allowed_components : [];
        }

        $components = (array) $components;
        $normalized = [];
        $has_all = $default_to_all && empty($components);

        foreach ($components as $component) {
            if (!is_string($component)) {
                continue;
            }

            $component_key = sanitize_key($component);

            if ($component_key === 'all') {
                $has_all = true;
                continue;
            }

            if (in_array($component_key, $allowed_components, true) && !in_array($component_key, $normalized, true)) {
                $normalized[] = $component_key;
            }
        }

        if ($has_all) {
            return $allowed_components;
        }

        if (empty($normalized)) {
            return $default_to_all ? $allowed_components : $normalized;
        }

        return $normalized;
    }

    /**
     * Normalise un nom d'entrée d'archive pour validation.
     *
     * @param string $entry_name
     * @return string
     */
    private function normalize_zip_entry_name($entry_name) {
        if (function_exists('wp_normalize_path')) {
            $normalized = wp_normalize_path($entry_name);
        } else {
            $normalized = str_replace('\\', '/', (string) $entry_name);
        }

        while (strpos($normalized, './') === 0) {
            $normalized = substr($normalized, 2);
        }

        return $normalized;
    }

    /**
     * Normalise un chemin pour la comparaison des préfixes.
     *
     * @param string $path
     * @return string
     */
    private function normalize_path_for_validation($path) {
        if (function_exists('wp_normalize_path')) {
            $normalized = wp_normalize_path($path);
        } else {
            $normalized = str_replace('\\', '/', (string) $path);
        }

        if ($normalized === '') {
            return '';
        }

        if ($normalized !== '/') {
            $normalized = rtrim($normalized, '/');
        }

        return $normalized;
    }

    /**
     * Concatène un chemin de base avec un chemin relatif.
     *
     * @param string $base
     * @param string $path
     * @return string
     */
    private function join_paths($base, $path) {
        $trimmed_base = rtrim($base, '/\\');

        if ($path === '') {
            return $trimmed_base;
        }

        return $trimmed_base . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Vérifie qu'un chemin est contenu dans un répertoire de base.
     *
     * @param string $base
     * @param string $path
     * @param string $entry_name
     * @return void
     * @throws Exception
     */
    private function assert_path_within_base($base, $path, $entry_name) {
        if ($path === $base) {
            return;
        }

        if (strpos($path, $base . '/') !== 0) {
            throw new Exception("Entrée d'archive invalide détectée : {$entry_name}");
        }
    }

    /**
     * S'assure qu'un répertoire existe pour la validation des chemins.
     *
     * @param string $directory
     * @return void
     * @throws Exception
     */
    private function ensure_directory_exists($directory) {
        if ($directory === '' || is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new Exception('Impossible de préparer le répertoire temporaire pour la validation.');
        }
    }

    /**
     * Chiffre un mot de passe avant stockage dans un transient.
     *
     * Utilise AES-256-CBC avec un IV aléatoire et ajoute un HMAC-SHA256 pour
     * garantir l'intégrité, le tout basé sur une clé dérivée des salts
     * WordPress. Cela évite de conserver le secret en clair tout en restant
     * déchiffrable par le site qui a créé la tâche de restauration.
     *
     * @param string|null $password
     * @return string|null
     */
    public static function encrypt_password_for_transient($password) {
        if ($password === null) {
            return null;
        }

        $key = self::get_password_encryption_key();
        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        if ($iv_length === false) {
            throw new RuntimeException('Méthode de chiffrement indisponible.');
        }

        $iv = random_bytes($iv_length);
        $ciphertext = openssl_encrypt($password, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Impossible de chiffrer le mot de passe.');
        }

        $hmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);

        return base64_encode($iv . $hmac . $ciphertext);
    }

    /**
     * Déchiffre un mot de passe stocké dans un transient.
     *
     * L'algorithme applique AES-256-CBC avec un vecteur d'initialisation aléatoire,
     * complété par un HMAC-SHA256 pour vérifier l'intégrité. La clé symétrique est
     * dérivée des différentes clés et salts WordPress disponibles, ce qui évite de
     * conserver le secret en clair tout en restant déchiffrable par cette instance.
     *
     * @param string $encrypted_password
     * @return string|null
     */
    private function decrypt_password_from_transient($encrypted_password) {
        if ($encrypted_password === null || $encrypted_password === '') {
            return null;
        }

        $key = self::get_password_encryption_key();
        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        if ($iv_length === false) {
            throw new RuntimeException('Méthode de déchiffrement indisponible.');
        }

        $decoded = base64_decode($encrypted_password, true);
        if ($decoded === false || strlen($decoded) <= ($iv_length + 32)) {
            throw new RuntimeException('Données chiffrées invalides.');
        }

        $iv = substr($decoded, 0, $iv_length);
        $hmac = substr($decoded, $iv_length, 32);
        $ciphertext = substr($decoded, $iv_length + 32);

        $calculated_hmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        if (!hash_equals($hmac, $calculated_hmac)) {
            throw new RuntimeException('Vérification d\'intégrité du mot de passe échouée.');
        }

        $password = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($password === false) {
            throw new RuntimeException('Impossible de déchiffrer le mot de passe.');
        }

        return $password;
    }

    /**
     * Dérive une clé symétrique à partir des salts WordPress.
     *
     * @return string
     */
    private static function get_password_encryption_key() {
        $salts = [];

        if (function_exists('wp_salt')) {
            $salts[] = wp_salt('auth');
            $salts[] = wp_salt('secure_auth');
            $salts[] = wp_salt('logged_in');
            $salts[] = wp_salt('nonce');
        }

        $constants = [
            'AUTH_KEY',
            'SECURE_AUTH_KEY',
            'LOGGED_IN_KEY',
            'NONCE_KEY',
            'AUTH_SALT',
            'SECURE_AUTH_SALT',
            'LOGGED_IN_SALT',
            'NONCE_SALT'
        ];

        foreach ($constants as $constant) {
            if (defined($constant)) {
                $salts[] = constant($constant);
            }
        }

        $key_material = implode('|', array_filter($salts));

        if ($key_material === '') {
            $key_material = 'bjlg-transient-password-fallback';
        }

        return hash('sha256', $key_material, true);
    }

    /**
     * Importe un fichier SQL dans la base de données
     */
    private function import_database($sql_filepath) {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'query')) {
            BJLG_Debug::log('Import SQL ignoré : instance $wpdb indisponible.', 'warning');

            return;
        }

        if (!file_exists($sql_filepath)) {
            throw new Exception("Fichier SQL introuvable.");
        }
        
        $handle = @fopen($sql_filepath, 'r');
        if (!$handle) {
            throw new Exception("Impossible de lire le fichier SQL.");
        }

        $query = '';
        $queries_executed = 0;
        $transaction_started = false;

        try {
            // Désactiver temporairement les contraintes
            $wpdb->query('SET foreign_key_checks = 0');
            $wpdb->query('SET autocommit = 0');

            if ($wpdb->query('START TRANSACTION') !== false) {
                $transaction_started = true;
            }

            while (($line = fgets($handle)) !== false) {
                // Ignorer les commentaires et les lignes vides
                if (substr($line, 0, 2) == '--' || trim($line) == '') {
                    continue;
                }

                $query .= $line;

                // Exécuter la requête quand on atteint un point-virgule à la fin d'une ligne
                if (substr(trim($line), -1, 1) == ';') {
                    $result = $wpdb->query($query);

                    if ($result === false) {
                        $error_message = 'Erreur SQL : ' . $wpdb->last_error;
                        BJLG_Debug::log($error_message);

                        throw new Exception($error_message);
                    }

                    $queries_executed++;
                    $query = ''; // Réinitialiser pour la prochaine requête
                }
            }

            BJLG_Debug::log("Import SQL terminé : {$queries_executed} requêtes exécutées.");

            if ($transaction_started) {
                $wpdb->query('COMMIT');
            }
        } catch (Throwable $throwable) {
            if ($transaction_started) {
                $wpdb->query('ROLLBACK');
            }

            throw $throwable;
        } finally {
            $wpdb->query('SET autocommit = 1');
            $wpdb->query('SET foreign_key_checks = 1');

            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Prépare et remplace atomiquement un composant de sandbox vers la production.
     *
     * @param string $source
     * @param string $destination
     * @return void
     */
    private function publish_component_directory($source, $destination): void {
        if (!is_dir($source)) {
            return;
        }

        if (file_exists($destination) && !is_dir($destination)) {
            $this->safe_delete_path($destination);
        }

        $staging_directory = $this->create_staging_directory($destination);

        try {
            $this->recursive_copy($source, $staging_directory, true);

            if ($this->swap_component_directories($destination, $staging_directory)) {
                return;
            }

            $this->recursive_delete($staging_directory);
            $this->recursive_copy($source, $destination, true);
        } catch (Throwable $throwable) {
            $this->recursive_delete($staging_directory);
            throw $throwable;
        }
    }

    /**
     * Crée un répertoire de mise en scène pour une promotion sandbox.
     *
     * @param string $destination
     * @return string
     */
    private function create_staging_directory($destination) {
        $normalized_destination = rtrim((string) $destination, '/\\');
        $parent_directory = $normalized_destination !== '' ? dirname($normalized_destination) : '';

        if ($parent_directory !== '' && $parent_directory !== '.' && $parent_directory !== $normalized_destination) {
            $this->ensure_directory_exists($parent_directory);
        }

        $staging_directory = $normalized_destination . '-bjlg-staging-' . str_replace('.', '-', uniqid('', true));

        if (!@mkdir($staging_directory, 0755, true) && !is_dir($staging_directory)) {
            throw new RuntimeException('Impossible de préparer le répertoire temporaire pour la publication de la sandbox.');
        }

        return $staging_directory;
    }

    /**
     * Échange le répertoire de destination avec son équivalent en mise en scène.
     *
     * @param string $destination
     * @param string $staging_directory
     * @return bool
     */
    private function swap_component_directories($destination, $staging_directory): bool {
        if (is_link($destination)) {
            throw new RuntimeException("Lien symbolique détecté lors de la publication : {$destination}");
        }

        $backup_directory = null;

        if (is_dir($destination)) {
            $backup_directory = rtrim($destination, '/\\') . '-bjlg-backup-' . str_replace('.', '-', uniqid('', true));

            if (!@rename($destination, $backup_directory)) {
                return false;
            }
        }

        if (!@rename($staging_directory, $destination)) {
            if ($backup_directory !== null && is_dir($backup_directory)) {
                @rename($backup_directory, $destination);
            }

            throw new RuntimeException("Impossible de remplacer le répertoire de destination : {$destination}");
        }

        if ($backup_directory !== null && is_dir($backup_directory)) {
            $this->recursive_delete($backup_directory);
        }

        return true;
    }

    /**
     * Copie récursive de fichiers.
     *
     * @param string $source
     * @param string $destination
     * @param bool   $prune_missing
     * @return bool
     */
    private function recursive_copy($source, $destination, $prune_missing = false) {
        if (!is_dir($source)) {
            return false;
        }

        $this->ensure_directory_exists($destination);

        $dir = opendir($source);
        if ($dir === false) {
            throw new RuntimeException('Impossible d\'ouvrir le répertoire source pour la copie.');
        }

        $copied_entries = [];

        try {
            while (($file = readdir($dir)) !== false) {
                if ($file == '.' || $file == '..') {
                    continue;
                }

                $src_path = $source . '/' . $file;
                $dst_path = $destination . '/' . $file;

                $copied_entries[] = $file;

                if (is_link($src_path)) {
                    throw new RuntimeException("Lien symbolique détecté lors de la restauration : {$src_path}");
                }

                if (is_dir($src_path)) {
                    if (file_exists($dst_path) && !is_dir($dst_path)) {
                        $this->safe_delete_path($dst_path);
                    }

                    $this->recursive_copy($src_path, $dst_path, $prune_missing);
                } else {
                    if (is_dir($dst_path)) {
                        $this->safe_delete_path($dst_path);
                    }

                    if (is_link($dst_path)) {
                        throw new RuntimeException("Lien symbolique détecté lors de l'écriture : {$dst_path}");
                    }

                    if (!@copy($src_path, $dst_path)) {
                        throw new RuntimeException("Impossible de copier le fichier : {$src_path}");
                    }
                }
            }
        } finally {
            closedir($dir);
        }

        if ($prune_missing) {
            $destination_entries = scandir($destination);

            if ($destination_entries === false) {
                throw new RuntimeException("Impossible de parcourir le répertoire de destination : {$destination}");
            }

            foreach ($destination_entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                if (!in_array($entry, $copied_entries, true)) {
                    $this->safe_delete_path($destination . '/' . $entry);
                }
            }
        }

        return true;
    }

    /**
     * Supprime en toute sécurité un chemin fichier ou dossier.
     *
     * @param string $path
     * @return void
     */
    private function safe_delete_path($path): void {
        if (is_link($path)) {
            throw new RuntimeException("Lien symbolique détecté lors de la suppression : {$path}");
        }

        if (is_dir($path)) {
            $this->recursive_delete($path);

            return;
        }

        if (file_exists($path) && !@unlink($path)) {
            throw new RuntimeException("Impossible de supprimer le fichier : {$path}");
        }
    }

    /**
     * Détermine si une entrée d'archive représente un lien symbolique.
     *
     * @param ZipArchive   $zip
     * @param int          $index
     * @param array|false  $entry_stat
     * @return bool
     */
    private function is_zip_entry_symlink(ZipArchive $zip, $index, $entry_stat) {
        $mode = null;

        if (is_array($entry_stat) && array_key_exists('external_attributes', $entry_stat)) {
            $mode = ((int) $entry_stat['external_attributes']) >> 16;
        }

        if ($mode === null && method_exists($zip, 'getExternalAttributesIndex')) {
            $opsys = 0;
            $attributes = 0;

            if (@$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                $mode = ((int) $attributes) >> 16;
            }
        }

        if ($mode === null) {
            return false;
        }

        $file_type = $mode & 0xF000;

        return $file_type === 0xA000;
    }

    /**
     * Suppression récursive de dossier
     */
    private function recursive_delete($dir) {
        if (is_link($dir)) {
            @unlink($dir);

            return;
        }

        if (is_file($dir)) {
            @unlink($dir);

            return;
        }

        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;

            if (is_link($path)) {
                @unlink($path);

                continue;
            }

            if (is_dir($path)) {
                $this->recursive_delete($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Vide tous les caches connus
     */
    private function clear_all_caches() {
        // Cache WordPress
        wp_cache_flush();
        
        // Cache des options
        wp_cache_delete('alloptions', 'options');
        
        // Cache des transients spécifiques au plugin
        global $wpdb;

        if (isset($wpdb)) {
            $options_table = $wpdb->options ?? ($wpdb->prefix ?? 'wp_') . 'options';

            if (method_exists($wpdb, 'get_col')) {
                $transient_option_names = (array) $wpdb->get_col("SELECT option_name FROM {$options_table} WHERE option_name LIKE '\\_transient\\_bjlg\\_%'");
                $this->delete_plugin_transients($transient_option_names, false);

                if (function_exists('delete_site_transient')) {
                    $site_transient_option_names = (array) $wpdb->get_col("SELECT option_name FROM {$options_table} WHERE option_name LIKE '\\_site\\_transient\\_bjlg\\_%'");
                    $this->delete_plugin_transients($site_transient_option_names, true);

                    if (isset($wpdb->sitemeta)) {
                        $site_meta_table = $wpdb->sitemeta;
                        $network_transient_keys = (array) $wpdb->get_col("SELECT meta_key FROM {$site_meta_table} WHERE meta_key LIKE '\\_site\\_transient\\_bjlg\\_%'");
                        $this->delete_plugin_transients($network_transient_keys, true);
                    }
                }
            } elseif (method_exists($wpdb, 'query')) {
                $wpdb->query("DELETE FROM {$options_table} WHERE option_name LIKE '\\_transient\\_bjlg\\_%'");
                $wpdb->query("DELETE FROM {$options_table} WHERE option_name LIKE '\\_site\\_transient\\_bjlg\\_%'");

                if (isset($wpdb->sitemeta)) {
                    $site_meta_table = $wpdb->sitemeta;
                    $wpdb->query("DELETE FROM {$site_meta_table} WHERE meta_key LIKE '\\_site\\_transient\\_bjlg\\_%'");
                }
            }
        }
        
        // Cache des objets
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Plugins de cache populaires
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain(); // WP Rocket
        }
        
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all(); // W3 Total Cache
        }
        
        if (function_exists('wp_super_cache_clear_cache')) {
            wp_super_cache_clear_cache(); // WP Super Cache
        }
        
        BJLG_Debug::log("Tous les caches ont été vidés.");
    }

    /**
     * Retourne l'instance du gestionnaire de chiffrement (et l'initialise si nécessaire).
     *
     * @return BJLG_Encryption|null
     */
    private function get_encryption_handler() {
        if (!$this->encryption_handler && class_exists(BJLG_Encryption::class)) {
            $this->encryption_handler = new BJLG_Encryption();
        }

        return $this->encryption_handler;
    }

    /**
     * Supprime les transients du plugin en utilisant les API WordPress.
     *
     * @param array<int, string> $option_names Liste des noms d'options ou métas contenant les transients.
     * @param bool $site_scope Indique si l'on supprime des transients de site.
     */
    private function delete_plugin_transients(array $option_names, bool $site_scope): void {
        $prefix = $site_scope ? '_site_transient_' : '_transient_';

        foreach ($option_names as $option_name) {
            $option_name = (string) $option_name;

            if (strpos($option_name, $prefix) !== 0) {
                continue;
            }

            $transient = substr($option_name, strlen($prefix));

            if ($transient === '') {
                continue;
            }

            if ($site_scope) {
                if (function_exists('delete_site_transient')) {
                    delete_site_transient($transient);
                }

                continue;
            }

            if (function_exists('delete_transient')) {
                delete_transient($transient);
            }
        }
    }
}