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

        $previous_snapshot = \bjlg_get_option(self::OPTION_KEY, []);
        $previous_destinations = [];
        if (is_array($previous_snapshot) && !empty($previous_snapshot['destinations']) && is_array($previous_snapshot['destinations'])) {
            foreach ($previous_snapshot['destinations'] as $previous_entry) {
                if (!is_array($previous_entry) || empty($previous_entry['id'])) {
                    continue;
                }

                $previous_destinations[(string) $previous_entry['id']] = $previous_entry;
            }
        }

        $threshold_percent = class_exists(BJLG_Settings::class) ? (float) BJLG_Settings::get_storage_warning_threshold() : 85.0;
        $threshold_percent = max(1.0, min(100.0, $threshold_percent));
        $threshold_ratio = $threshold_percent / 100;

        $results = [
            'generated_at' => self::now(),
            'destinations' => [],
            'errors' => [],
            'threshold_percent' => $threshold_percent,
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

            $previous_entry = $previous_destinations[$destination_id] ?? null;
            $results['destinations'][] = self::collect_destination_snapshot(
                $destination_id,
                $destination,
                is_array($previous_entry) ? $previous_entry : null,
                $threshold_ratio
            );
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
    private static function collect_destination_snapshot(string $destination_id, BJLG_Destination_Interface $destination, ?array $previous_entry = null, float $threshold_ratio = 0.85): array {
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
            'daily_delta_bytes' => null,
            'daily_delta_label' => '',
            'forecast_label' => '',
            'days_to_threshold' => null,
            'days_to_threshold_label' => '',
            'projection_intent' => 'neutral',
        ];

        if (!$entry['connected']) {
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

        if ($entry['used_bytes'] !== null && is_array($previous_entry)) {
            $previous_used = isset($previous_entry['used_bytes']) ? self::sanitize_bytes($previous_entry['used_bytes']) : null;
            $previous_refreshed = isset($previous_entry['refreshed_at']) ? (int) $previous_entry['refreshed_at'] : 0;

            if ($previous_used !== null && $previous_refreshed > 0 && $previous_refreshed < $now) {
                $delta_bytes = (int) $entry['used_bytes'] - (int) $previous_used;
                $elapsed = max(1, $now - $previous_refreshed);
                $daily_delta = $delta_bytes / ($elapsed / DAY_IN_SECONDS);
                $entry['daily_delta_bytes'] = $daily_delta;
                $entry['daily_delta_label'] = self::format_daily_delta_label($daily_delta);
                $entry['forecast_label'] = $entry['daily_delta_label'];

                if ($entry['quota_bytes'] !== null && $entry['quota_bytes'] > 0) {
                    $threshold_bytes = (int) floor($entry['quota_bytes'] * $threshold_ratio);
                    if ($entry['used_bytes'] >= $threshold_bytes) {
                        $entry['days_to_threshold'] = 0.0;
                        $entry['days_to_threshold_label'] = __('Seuil de saturation atteint', 'backup-jlg');
                        $entry['projection_intent'] = 'critical';
                    } elseif ($daily_delta > 0) {
                        $remaining = max(0, $threshold_bytes - (int) $entry['used_bytes']);
                        $days = $remaining / $daily_delta;
                        if ($days < 0) {
                            $days = 0.0;
                        }
                        $entry['days_to_threshold'] = $days;
                        $entry['days_to_threshold_label'] = sprintf(
                            __('Saturation estimée dans %s', 'backup-jlg'),
                            self::format_days_label($days)
                        );
                        if ($days <= 1) {
                            $entry['projection_intent'] = 'critical';
                        } elseif ($days <= 3) {
                            $entry['projection_intent'] = 'warning';
                        } else {
                            $entry['projection_intent'] = 'watch';
                        }
                    } elseif ($daily_delta < 0) {
                        $entry['projection_intent'] = 'success';
                        $entry['days_to_threshold_label'] = __('Consommation en baisse', 'backup-jlg');
                    } else {
                        $entry['days_to_threshold_label'] = __('Consommation stable', 'backup-jlg');
                    }
                }
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

    private static function format_daily_delta_label(?float $bytes_per_day): string {
        if ($bytes_per_day === null) {
            return '';
        }

        if (abs($bytes_per_day) < 1) {
            return __('Variation négligeable', 'backup-jlg');
        }

        $label = size_format((int) max(1, round(abs($bytes_per_day))));

        if ($bytes_per_day > 0) {
            return sprintf(__('Croissance de %s par jour', 'backup-jlg'), $label);
        }

        return sprintf(__('Réduction de %s par jour', 'backup-jlg'), $label);
    }

    private static function format_days_label(float $days): string {
        if ($days <= 0) {
            return __('moins d\'un jour', 'backup-jlg');
        }

        if ($days < 1) {
            $hours = (int) max(1, ceil($days * 24));

            return sprintf(_n('%s heure', '%s heures', $hours, 'backup-jlg'), number_format_i18n($hours));
        }

        $rounded = (int) ceil($days);

        return sprintf(_n('%s jour', '%s jours', $rounded, 'backup-jlg'), number_format_i18n($rounded));
    }
}
