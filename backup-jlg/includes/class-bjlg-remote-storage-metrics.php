<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Service chargé de mettre en cache les métriques de stockage distantes.
 */
class BJLG_Remote_Storage_Metrics {

    public const TRANSIENT_KEY = 'bjlg_remote_storage_metrics_snapshot';
    public const OPTION_KEY = 'bjlg_remote_storage_metrics_snapshot';
    public const LOCK_KEY = 'bjlg_remote_storage_metrics_lock';
    public const CRON_HOOK = 'bjlg_refresh_remote_storage_metrics';

    private const CACHE_TTL = 900; // 15 minutes
    private const LOCK_TTL = 300;  // 5 minutes

    /**
     * Retourne le snapshot courant (rafraîchi si nécessaire).
     */
    public static function get_snapshot(bool $allow_refresh = true): array {
        $snapshot = get_transient(self::TRANSIENT_KEY);

        if (!self::is_valid_snapshot($snapshot)) {
            $snapshot = get_option(self::OPTION_KEY, []);

            if (self::is_valid_snapshot($snapshot)) {
                set_transient(self::TRANSIENT_KEY, $snapshot, self::CACHE_TTL);
            } elseif ($allow_refresh) {
                return self::refresh_snapshot(true);
            } else {
                return self::get_default_snapshot();
            }
        }

        return self::normalize_snapshot($snapshot);
    }

    /**
     * Rafraîchit le snapshot en interrogeant toutes les destinations distantes.
     */
    public static function refresh_snapshot(bool $force = false): array {
        if (!$force && get_transient(self::LOCK_KEY)) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (self::is_valid_snapshot($cached)) {
                return self::normalize_snapshot($cached);
            }
        }

        set_transient(self::LOCK_KEY, 1, self::LOCK_TTL);

        try {
            $timestamp = self::now();
            $destinations = [];

            if (class_exists(BJLG_Settings::class)) {
                $ids = BJLG_Settings::get_known_destination_ids();
            } else {
                $ids = [];
            }

            foreach ($ids as $destination_id) {
                $destinations[$destination_id] = self::build_destination_snapshot($destination_id, $timestamp);
            }

            $snapshot = [
                'generated_at' => $timestamp,
                'destinations' => $destinations,
            ];

            self::store_snapshot($snapshot);

            return $snapshot;
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    /**
     * Invalide le cache courant.
     */
    public static function invalidate_cache(): void {
        delete_transient(self::TRANSIENT_KEY);
        delete_transient(self::LOCK_KEY);
        if (function_exists('delete_option')) {
            delete_option(self::OPTION_KEY);
        } else {
            update_option(self::OPTION_KEY, [], false);
        }
    }

    private static function build_destination_snapshot(string $destination_id, int $timestamp): array {
        $entry = [
            'id' => $destination_id,
            'name' => BJLG_Settings::get_destination_label($destination_id),
            'connected' => false,
            'used_bytes' => null,
            'quota_bytes' => null,
            'free_bytes' => null,
            'refreshed_at' => $timestamp,
            'error' => null,
            'source' => 'unknown',
        ];

        if (!class_exists(BJLG_Destination_Factory::class)) {
            return $entry;
        }

        try {
            $destination = BJLG_Destination_Factory::create($destination_id);
        } catch (\Throwable $exception) {
            $destination = null;
            self::log(sprintf('Impossible d’instancier la destination "%s" : %s', $destination_id, $exception->getMessage()));
        }

        if (!$destination instanceof BJLG_Destination_Interface) {
            return $entry;
        }

        $entry['name'] = $destination->get_name();
        $entry['connected'] = $destination->is_connected();

        if (!$entry['connected']) {
            return $entry;
        }

        try {
            $usage = $destination->get_storage_usage();
            if (is_array($usage)) {
                if (isset($usage['used_bytes'])) {
                    $entry['used_bytes'] = self::sanitize_numeric($usage['used_bytes']);
                }
                if (isset($usage['quota_bytes'])) {
                    $entry['quota_bytes'] = self::sanitize_numeric($usage['quota_bytes']);
                }
                if (isset($usage['free_bytes'])) {
                    $entry['free_bytes'] = self::sanitize_numeric($usage['free_bytes']);
                }
                if (isset($usage['source']) && is_string($usage['source'])) {
                    $entry['source'] = trim($usage['source']);
                } else {
                    $entry['source'] = 'provider';
                }
                if (isset($usage['refreshed_at']) && is_numeric($usage['refreshed_at'])) {
                    $entry['refreshed_at'] = (int) $usage['refreshed_at'];
                }
            }
        } catch (\Throwable $exception) {
            $entry['error'] = $exception->getMessage();
            self::log(sprintf('Erreur lors de la récupération des métriques pour "%s" : %s', $entry['name'], $exception->getMessage()));
        }

        if ($entry['free_bytes'] === null && $entry['quota_bytes'] !== null && $entry['used_bytes'] !== null) {
            $entry['free_bytes'] = max(0, (int) $entry['quota_bytes'] - (int) $entry['used_bytes']);
        }

        return $entry;
    }

    private static function store_snapshot(array $snapshot): void {
        set_transient(self::TRANSIENT_KEY, $snapshot, self::CACHE_TTL);
        update_option(self::OPTION_KEY, $snapshot, false);
    }

    private static function is_valid_snapshot($snapshot): bool {
        if (!is_array($snapshot)) {
            return false;
        }

        if (!isset($snapshot['destinations']) || !is_array($snapshot['destinations'])) {
            return false;
        }

        return true;
    }

    private static function normalize_snapshot($snapshot): array {
        if (!is_array($snapshot)) {
            return self::get_default_snapshot();
        }

        $generated_at = isset($snapshot['generated_at']) && is_numeric($snapshot['generated_at'])
            ? (int) $snapshot['generated_at']
            : 0;

        $destinations = [];
        if (isset($snapshot['destinations']) && is_array($snapshot['destinations'])) {
            foreach ($snapshot['destinations'] as $key => $value) {
                if (!is_array($value)) {
                    continue;
                }
                $id = is_string($key) && $key !== '' ? $key : (string) ($value['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $value['id'] = $id;
                $destinations[$id] = [
                    'id' => $id,
                    'name' => isset($value['name']) ? (string) $value['name'] : BJLG_Settings::get_destination_label($id),
                    'connected' => !empty($value['connected']),
                    'used_bytes' => self::sanitize_numeric($value['used_bytes'] ?? null),
                    'quota_bytes' => self::sanitize_numeric($value['quota_bytes'] ?? null),
                    'free_bytes' => self::sanitize_numeric($value['free_bytes'] ?? null),
                    'refreshed_at' => isset($value['refreshed_at']) && is_numeric($value['refreshed_at'])
                        ? (int) $value['refreshed_at']
                        : $generated_at,
                    'error' => isset($value['error']) ? (string) $value['error'] : null,
                    'source' => isset($value['source']) ? (string) $value['source'] : 'provider',
                ];

                if ($destinations[$id]['free_bytes'] === null
                    && $destinations[$id]['quota_bytes'] !== null
                    && $destinations[$id]['used_bytes'] !== null
                ) {
                    $destinations[$id]['free_bytes'] = max(
                        0,
                        (int) $destinations[$id]['quota_bytes'] - (int) $destinations[$id]['used_bytes']
                    );
                }
            }
        }

        return [
            'generated_at' => $generated_at,
            'destinations' => $destinations,
        ];
    }

    private static function sanitize_numeric($value): ?int {
        if (is_numeric($value)) {
            $int_value = (int) $value;
            if ($int_value < 0) {
                $int_value = 0;
            }

            return $int_value;
        }

        return null;
    }

    private static function get_default_snapshot(): array {
        return [
            'generated_at' => 0,
            'destinations' => [],
        ];
    }

    private static function now(): int {
        if (function_exists('current_time')) {
            return (int) current_time('timestamp');
        }

        return time();
    }

    private static function log(string $message): void {
        if (class_exists(BJLG_Debug::class)) {
            BJLG_Debug::log('[RemoteStorageMetrics] ' . $message);
        }
    }
}

