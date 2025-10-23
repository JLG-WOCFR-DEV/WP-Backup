<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Collecte et met en cache les métriques des destinations distantes.
 */
class BJLG_Remote_Storage_Metrics {
    public const OPTION_KEY = 'bjlg_remote_storage_metrics_snapshot';
    public const WARNING_DIGEST_OPTION = 'bjlg_storage_warning_digest';
    public const CRON_HOOK = 'bjlg_refresh_remote_storage_metrics';
    private const LOCK_TRANSIENT = 'bjlg_remote_storage_metrics_refresh_lock';

    /**
     * Initialise les hooks nécessaires.
     */
    public function __construct() {
        add_filter('cron_schedules', [__CLASS__, 'register_cron_schedule']);
        add_action('init', [$this, 'maybe_schedule_refresh'], 25);
        add_action(self::CRON_HOOK, [__CLASS__, 'refresh_snapshot']);
        add_action('bjlg_settings_saved', [$this, 'handle_settings_saved'], 10, 1);
    }

    /**
     * Ajoute une récurrence personnalisée pour rafraîchir les métriques.
     *
     * @param array<string, array<string, mixed>> $schedules
     *
     * @return array<string, array<string, mixed>>
     */
    public static function register_cron_schedule(array $schedules): array {
        $interval = self::get_refresh_interval();
        $interval = max(5 * MINUTE_IN_SECONDS, (int) $interval);

        $schedules['bjlg_remote_metrics'] = [
            'interval' => $interval,
            'display' => __('Rafraîchissement des métriques distantes', 'backup-jlg'),
        ];

        return $schedules;
    }

    /**
     * Planifie le cron si nécessaire.
     */
    public function maybe_schedule_refresh(): void {
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        $timestamp = time() + MINUTE_IN_SECONDS;
        wp_schedule_event($timestamp, 'bjlg_remote_metrics', self::CRON_HOOK);
    }

    /**
     * Resynchronise l'événement WP Cron après un changement de configuration.
     */
    private function reschedule_event(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        $this->maybe_schedule_refresh();
    }

    /**
     * Gère les sauvegardes des réglages pour invalider ou resynchroniser les métriques.
     *
     * @param array<string, mixed> $saved_sections
     */
    public function handle_settings_saved(array $saved_sections): void {
        if (isset($saved_sections['monitoring'])) {
            $this->reschedule_event();
        }

        if (isset($saved_sections['monitoring']) || isset($saved_sections['integrations']) || isset($saved_sections['destinations'])) {
            delete_transient(self::LOCK_TRANSIENT);
            self::refresh_snapshot();
        }
    }

    /**
     * Retourne l'instant courant selon WordPress.
     */
    private static function now(): int {
        return (int) current_time('timestamp');
    }

    /**
     * Retourne l'intervalle de rafraîchissement configuré en secondes.
     */
    public static function get_refresh_interval(): int {
        if (!class_exists(BJLG_Settings::class)) {
            return 15 * MINUTE_IN_SECONDS;
        }

        $settings = BJLG_Settings::get_monitoring_settings();
        $minutes = isset($settings['remote_metrics_ttl_minutes']) ? (int) $settings['remote_metrics_ttl_minutes'] : 15;
        $minutes = max(5, min(24 * 60, $minutes));

        /**
         * Filtre l'intervalle de rafraîchissement des métriques distantes (en secondes).
         *
         * @param int $interval Intervalle en secondes.
         */
        return (int) apply_filters('bjlg_remote_metrics_refresh_interval', $minutes * MINUTE_IN_SECONDS);
    }

    /**
     * Retourne le TTL de cache pour le snapshot courant.
     */
    public static function get_cache_ttl(): int {
        return self::get_refresh_interval();
    }

    /**
     * Récupère le snapshot actuel (rafraîchit si nécessaire).
     *
     * @param bool $force_refresh Forcer une mise à jour immédiate.
     *
     * @return array<string, mixed>
     */
    public static function get_snapshot(bool $force_refresh = false): array {
        $snapshot = \bjlg_get_option(self::OPTION_KEY, []);
        if (!is_array($snapshot)) {
            $snapshot = [];
        }

        $generated_at = isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : 0;
        $ttl = self::get_cache_ttl();
        $stale = ($generated_at + $ttl) < self::now();

        if ($force_refresh || $stale || empty($snapshot['destinations'])) {
            $snapshot = self::refresh_snapshot();
            $generated_at = isset($snapshot['generated_at']) ? (int) $snapshot['generated_at'] : 0;
            $stale = false;
        }

        $snapshot['generated_at'] = $generated_at;
        $snapshot['stale'] = $stale;
        if (!isset($snapshot['destinations']) || !is_array($snapshot['destinations'])) {
            $snapshot['destinations'] = [];
        }

        return $snapshot;
    }

    /**
     * Rafraîchit les métriques et met à jour le cache persistant.
     *
     * @return array<string, mixed>
     */
    public static function refresh_snapshot(): array {
        if (get_transient(self::LOCK_TRANSIENT)) {
            return \bjlg_get_option(self::OPTION_KEY, []);
        }

        set_transient(self::LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

        $results = [
            'generated_at' => self::now(),
            'destinations' => [],
            'errors' => [],
        ];

        if (!class_exists(BJLG_Destination_Factory::class) || !class_exists(BJLG_Settings::class)) {
            delete_transient(self::LOCK_TRANSIENT);
            \bjlg_update_option(self::OPTION_KEY, $results);

            return $results;
        }

        $known_ids = BJLG_Settings::get_known_destination_ids();
        foreach ($known_ids as $destination_id) {
            $destination = BJLG_Destination_Factory::create($destination_id);
            if (!$destination instanceof BJLG_Destination_Interface) {
                continue;
            }

            $results['destinations'][] = self::collect_destination_snapshot($destination_id, $destination);
        }

        \bjlg_update_option(self::OPTION_KEY, $results);
        delete_transient(self::LOCK_TRANSIENT);

        /**
         * Action déclenchée après la mise à jour des métriques distantes.
         *
         * @param array<string, mixed> $results
         */
        do_action('bjlg_remote_storage_metrics_refreshed', $results);

        return $results;
    }

    /**
     * Construit les métriques pour une destination donnée.
     *
     * @return array<string, mixed>
     */
    private static function collect_destination_snapshot(string $destination_id, BJLG_Destination_Interface $destination): array {
        $now = self::now();
        $entry = [
            'id' => $destination_id,
            'name' => method_exists($destination, 'get_name') ? $destination->get_name() : $destination_id,
            'connected' => $destination->is_connected(),
            'used_bytes' => null,
            'quota_bytes' => null,
            'free_bytes' => null,
            'used_human' => '',
            'quota_human' => '',
            'free_human' => '',
            'backups_count' => 0,
            'errors' => [],
            'refreshed_at' => $now,
            'latency_ms' => null,
        ];

        if (!$entry['connected']) {
            $entry['quota_samples'] = self::get_quota_sample_for_destination($destination_id);

            return $entry;
        }

        $start = microtime(true);
        try {
            $usage = $destination->get_storage_usage();
        } catch (\Throwable $exception) {
            $entry['errors'][] = $exception->getMessage();
            $usage = [];
        }
        $entry['latency_ms'] = (int) round((microtime(true) - $start) * 1000);

        if (is_array($usage)) {
            if (isset($usage['used_bytes'])) {
                $entry['used_bytes'] = self::sanitize_bytes($usage['used_bytes']);
            }
            if (isset($usage['quota_bytes'])) {
                $entry['quota_bytes'] = self::sanitize_bytes($usage['quota_bytes']);
            }
            if (isset($usage['free_bytes'])) {
                $entry['free_bytes'] = self::sanitize_bytes($usage['free_bytes']);
            }
        }

        $entry['quota_samples'] = self::get_quota_sample_for_destination($destination_id);

        if (!empty($entry['quota_samples'])) {
            if ($entry['used_bytes'] === null && $entry['quota_samples']['used_bytes'] !== null) {
                $entry['used_bytes'] = $entry['quota_samples']['used_bytes'];
            }
            if ($entry['quota_bytes'] === null && $entry['quota_samples']['quota_bytes'] !== null) {
                $entry['quota_bytes'] = $entry['quota_samples']['quota_bytes'];
            }
            if ($entry['free_bytes'] === null && $entry['quota_samples']['free_bytes'] !== null) {
                $entry['free_bytes'] = $entry['quota_samples']['free_bytes'];
            }
        }

        if ($entry['used_bytes'] !== null) {
            $entry['used_human'] = size_format((int) $entry['used_bytes']);
        }
        if ($entry['quota_bytes'] !== null) {
            $entry['quota_human'] = size_format((int) $entry['quota_bytes']);
        }
        if ($entry['free_bytes'] !== null) {
            $entry['free_human'] = size_format((int) $entry['free_bytes']);
        }

        if ($entry['quota_bytes'] === null && $entry['used_bytes'] !== null) {
            $entry['free_bytes'] = null;
        } elseif ($entry['free_bytes'] === null && $entry['quota_bytes'] !== null && $entry['used_bytes'] !== null) {
            $entry['free_bytes'] = max(0, (int) $entry['quota_bytes'] - (int) $entry['used_bytes']);
            $entry['free_human'] = size_format((int) $entry['free_bytes']);
        }

        if (!method_exists($destination, 'list_remote_backups')) {
            return $entry;
        }

        try {
            $backups = $destination->list_remote_backups();
        } catch (\Throwable $exception) {
            $entry['errors'][] = $exception->getMessage();
            $backups = [];
        }

        if (is_array($backups)) {
            $entry['backups_count'] = count($backups);

            if ($entry['used_bytes'] === null) {
                $total = 0;
                foreach ($backups as $backup) {
                    $total += isset($backup['size']) ? (int) $backup['size'] : 0;
                }
                $entry['used_bytes'] = $total;
                $entry['used_human'] = size_format($total);
            }
        }

        return $entry;
    }

    /**
     * Nettoie une valeur de taille.
     *
     * @param mixed $value
     */
    private static function sanitize_bytes($value): ?int {
        if (is_numeric($value)) {
            $numeric = (float) $value;
            if (!is_finite($numeric)) {
                return null;
            }

            return (int) max(0, $numeric);
        }

        return null;
    }

    /**
     * Retourne la dernière mesure de quota collectée via la purge distante.
     */
    private static function get_quota_sample_for_destination(string $destination_id): array
    {
        if (!function_exists('bjlg_get_option')) {
            return [];
        }

        $metrics = \bjlg_get_option('bjlg_remote_purge_sla_metrics', []);
        if (!is_array($metrics) || empty($metrics['quotas']) || !is_array($metrics['quotas'])) {
            return [];
        }

        $quotas = $metrics['quotas'];
        if (empty($quotas['destinations']) || !is_array($quotas['destinations'])) {
            return [];
        }

        $destination_metrics = $quotas['destinations'][$destination_id] ?? null;
        if (!is_array($destination_metrics)) {
            return [];
        }

        $used = isset($destination_metrics['used_bytes']) ? self::sanitize_bytes($destination_metrics['used_bytes']) : null;
        $quota = isset($destination_metrics['quota_bytes']) ? self::sanitize_bytes($destination_metrics['quota_bytes']) : null;
        $free = isset($destination_metrics['free_bytes']) ? self::sanitize_bytes($destination_metrics['free_bytes']) : null;

        if ($free === null && $used !== null && $quota !== null) {
            $free = max(0, $quota - $used);
        }

        if ($used === null && $quota === null && $free === null) {
            return [];
        }

        $ratio = isset($destination_metrics['usage_ratio']) ? (float) $destination_metrics['usage_ratio'] : null;
        if ($ratio !== null) {
            $ratio = max(0.0, min(1.0, $ratio));
        }

        return [
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'free_bytes' => $free,
            'usage_ratio' => $ratio,
            'average_usage_ratio' => isset($destination_metrics['average_usage_ratio']) ? (float) $destination_metrics['average_usage_ratio'] : null,
            'last_seen_at' => isset($destination_metrics['last_seen_at']) ? (int) $destination_metrics['last_seen_at'] : 0,
            'samples' => isset($destination_metrics['samples']) ? (int) $destination_metrics['samples'] : 0,
            'source' => 'remote_purge',
        ];
    }
}
