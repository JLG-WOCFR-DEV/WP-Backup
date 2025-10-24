<?php
namespace BJLG;

use Exception;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

if (!interface_exists(BJLG_Destination_Interface::class)) {
    return;
}

/**
 * Destination réseau "stockage managé" orchestrée par l'infrastructure JLG.
 * Elle s'appuie sur un contrat managé pour répliquer chaque archive sur plusieurs régions
 * tout en surveillant le respect des quotas et des objectifs SLA.
 */
class BJLG_Managed_Storage implements BJLG_Destination_Interface
{
    private const OPTION_SETTINGS = 'bjlg_managed_storage_settings';
    private const OPTION_STATUS = 'bjlg_managed_storage_status';
    private const OPTION_QUOTA = 'bjlg_managed_storage_quota_snapshot';

    /** @var array<string, mixed>|null */
    private $cached_settings = null;

    /** @var array<string, mixed>|null */
    private $cached_status = null;

    /**
     * {@inheritdoc}
     */
    public function get_id()
    {
        return 'managed_storage';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name()
    {
        return __('Stockage managé réseau', 'backup-jlg');
    }

    /**
     * {@inheritdoc}
     */
    public function is_connected()
    {
        $settings = $this->get_settings();

        if (empty($settings['enabled'])) {
            return false;
        }

        if ($settings['contract_id'] === '' || $settings['primary_region'] === '') {
            return false;
        }

        $status = $this->get_status();
        if (!empty($status['last_synced']) && !empty($status['regions'])) {
            return true;
        }

        /**
         * Permet à l'infrastructure de valider dynamiquement l'état de connexion.
         *
         * @param bool  $connected État de connexion détecté localement.
         * @param array $settings  Réglages courants de la destination.
         */
        $filtered = apply_filters('bjlg_managed_storage_connection_status', true, $settings);

        return (bool) $filtered;
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect()
    {
        \bjlg_update_option(self::OPTION_SETTINGS, $this->get_default_settings());
        \bjlg_update_option(self::OPTION_STATUS, []);
        \bjlg_update_option(self::OPTION_QUOTA, []);
        $this->cached_settings = null;
        $this->cached_status = null;
    }

    /**
     * {@inheritdoc}
     */
    public function render_settings()
    {
        $settings = $this->get_settings();
        $status = $this->get_status();
        $quota = $this->get_remote_quota_snapshot();

        $regions = implode(", ", $settings['replica_regions']);
        $enabled_attr = $settings['enabled'] ? " checked='checked'" : '';
        $plan = $settings['plan'];
        $plans = [
            'standard' => __('Standard (mutualisé)', 'backup-jlg'),
            'enterprise' => __('Enterprise (haute disponibilité)', 'backup-jlg'),
            'dedicated' => __('Déployé dédié', 'backup-jlg'),
        ];

        ?>
        <div class="bjlg-destination bjlg-destination--managed-storage">
            <h4><span class="dashicons dashicons-cloud" aria-hidden="true"></span> <?php echo esc_html($this->get_name()); ?></h4>
            <form class="bjlg-settings-form bjlg-destination-form" method="post" novalidate>
                <?php if (function_exists('wp_nonce_field')): ?>
                    <?php wp_nonce_field('bjlg_save_managed_storage', 'bjlg_managed_storage_nonce'); ?>
                <?php endif; ?>
                <div class="bjlg-settings-feedback notice bjlg-hidden" role="status" aria-live="polite"></div>
                <input type="hidden" name="managed_storage_submitted" value="1">
                <p class="description"><?php esc_html_e('Réplique automatiquement chaque archive sur les zones définies par votre contrat managé et surveille les quotas réseau.', 'backup-jlg'); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Activer la destination', 'backup-jlg'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="managed_storage_enabled" value="true"<?php echo $enabled_attr; ?>>
                                <?php esc_html_e('Activer le stockage managé orchestré par JLG.', 'backup-jlg'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Identifiant de contrat', 'backup-jlg'); ?></th>
                        <td>
                            <input type="text" name="managed_storage_contract_id" value="<?php echo esc_attr($settings['contract_id']); ?>" class="regular-text" placeholder="ms-XXXX-0000">
                            <p class="description"><?php esc_html_e('Fourni par l’équipe infrastructure. Permet de récupérer les quotas et la facturation associés.', 'backup-jlg'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Offre', 'backup-jlg'); ?></th>
                        <td>
                            <select name="managed_storage_plan">
                                <?php foreach ($plans as $plan_key => $label): ?>
                                    <option value="<?php echo esc_attr($plan_key); ?>" <?php selected($plan, $plan_key); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Région primaire', 'backup-jlg'); ?></th>
                        <td>
                            <input type="text" name="managed_storage_primary_region" value="<?php echo esc_attr($settings['primary_region']); ?>" class="regular-text" placeholder="eu-west-3">
                            <p class="description"><?php esc_html_e('Point d’entrée privilégié pour l’écriture initiale.', 'backup-jlg'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Régions répliquées', 'backup-jlg'); ?></th>
                        <td>
                            <textarea name="managed_storage_replica_regions" rows="2" class="large-text" placeholder="us-east-1, ca-central-1"><?php echo esc_textarea($regions); ?></textarea>
                            <p class="description"><?php esc_html_e('Liste séparée par des virgules ou retours à la ligne.', 'backup-jlg'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Quota alloué (GiB)', 'backup-jlg'); ?></th>
                        <td>
                            <label>
                                <span class="screen-reader-text"><?php esc_html_e('Quota alloué (GiB)', 'backup-jlg'); ?></span>
                                <input type="number" min="1" name="managed_storage_hard_quota_gib" value="<?php echo esc_attr($settings['hard_quota_gib']); ?>" class="small-text">
                            </label>
                            <p class="description"><?php esc_html_e('Capacité contractuelle totale. Les alertes sont générées au-delà de 85% par défaut.', 'backup-jlg'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Seuil de confort (GiB)', 'backup-jlg'); ?></th>
                        <td>
                            <input type="number" min="1" name="managed_storage_soft_quota_gib" value="<?php echo esc_attr($settings['soft_quota_gib']); ?>" class="small-text">
                            <p class="description"><?php esc_html_e('Déclenche les projections proactives lorsque ce seuil est dépassé.', 'backup-jlg'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Tolérance de burst (%)', 'backup-jlg'); ?></th>
                        <td>
                            <input type="number" min="0" max="100" step="1" name="managed_storage_burst_percent" value="<?php echo esc_attr($settings['burst_percent']); ?>" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Objectifs SLA internes', 'backup-jlg'); ?></th>
                        <td>
                            <div class="bjlg-field-grid">
                                <label>
                                    <span class="bjlg-field-label"><?php esc_html_e('RTO (minutes)', 'backup-jlg'); ?></span>
                                    <input type="number" min="1" name="managed_storage_rto_minutes" value="<?php echo esc_attr($settings['rto_minutes']); ?>" class="small-text">
                                </label>
                                <label>
                                    <span class="bjlg-field-label"><?php esc_html_e('RPO (minutes)', 'backup-jlg'); ?></span>
                                    <input type="number" min="0" name="managed_storage_rpo_minutes" value="<?php echo esc_attr($settings['rpo_minutes']); ?>" class="small-text">
                                </label>
                            </div>
                            <p class="description"><?php esc_html_e('Les alertes SLA s’appuient sur ces objectifs en complément des validations sandbox.', 'backup-jlg'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer les réglages', 'backup-jlg'); ?></button></p>
            </form>
            <?php if (!empty($status['regions'])): ?>
                <div class="bjlg-managed-storage-status">
                    <h5><?php esc_html_e('Synchronisation récente', 'backup-jlg'); ?></h5>
                    <ul>
                        <?php foreach ($status['regions'] as $region => $region_status):
                            if (!is_array($region_status)) {
                                continue;
                            }
                            $label = sprintf('%s — %s', esc_html($region), esc_html($region_status['status'] ?? 'unknown'));
                            if (!empty($region_status['latency_ms'])) {
                                $label .= sprintf(' (%d ms)', (int) $region_status['latency_ms']);
                            }
                            ?>
                            <li><?php echo $label; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if (!empty($quota['status']) && $quota['status'] === 'ok'): ?>
                <p class="description">
                    <span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
                    <?php echo esc_html(sprintf(__('Utilisé : %1$s / %2$s • Libre : %3$s', 'backup-jlg'), $quota['used_human'] ?? size_format(0), $quota['quota_human'] ?? size_format(0), $quota['free_human'] ?? size_format(0))); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * {@inheritdoc}
     */
    public function upload_file($filepath, $task_id)
    {
        if (is_array($filepath)) {
            $errors = [];
            foreach ($filepath as $single_path) {
                try {
                    $this->upload_file($single_path, $task_id);
                } catch (Exception $exception) {
                    $errors[] = $exception->getMessage();
                }
            }
            if (!empty($errors)) {
                throw new Exception(implode(' | ', $errors));
            }
            return;
        }

        if (!is_readable($filepath)) {
            throw new Exception(sprintf(__('Fichier de sauvegarde introuvable : %s', 'backup-jlg'), $filepath));
        }

        $settings = $this->get_settings();
        if (empty($settings['enabled'])) {
            throw new Exception(__('Le stockage managé est désactivé.', 'backup-jlg'));
        }

        if ($settings['contract_id'] === '' || $settings['primary_region'] === '') {
            throw new Exception(__('Configuration incomplète pour le stockage managé.', 'backup-jlg'));
        }

        $regions = array_merge([$settings['primary_region']], $settings['replica_regions']);
        $regions = array_values(array_unique(array_filter(array_map('sanitize_text_field', $regions))));

        if (empty($regions)) {
            throw new Exception(__('Aucune région cible définie pour le stockage managé.', 'backup-jlg'));
        }

        /**
         * Permet à l’infrastructure d’ajouter/surcharger les régions cibles avant transfert.
         *
         * @param string[] $regions
         * @param array    $settings
         */
        $regions = apply_filters('bjlg_managed_storage_regions', $regions, $settings);

        $report = [
            'task_id' => $task_id,
            'file' => basename($filepath),
            'contract_id' => $settings['contract_id'],
            'plan' => $settings['plan'],
            'regions' => [],
            'available_copies' => 0,
            'expected_copies' => count($regions),
            'errors' => [],
            'latency_ms' => 0,
            'updated_at' => time(),
        ];

        $total_latency = 0;

        foreach ($regions as $region) {
            $start = microtime(true);
            $transfer_context = [
                'region' => $region,
                'filepath' => $filepath,
                'task_id' => $task_id,
                'settings' => $settings,
            ];

            /**
             * Permet au contrôleur d’infrastructure d’exécuter le transfert physique.
             *
             * @param array|null          $response
             * @param array<string,mixed> $context
             */
            $response = apply_filters('bjlg_managed_storage_transfer', null, $transfer_context);

            $latency = (int) round((microtime(true) - $start) * 1000);
            $total_latency += $latency;

            $status = 'success';
            $error_message = '';
            $copy_identifier = null;

            if ($response instanceof WP_Error) {
                $status = 'failed';
                $error_message = $response->get_error_message();
            } elseif (is_array($response)) {
                $status = isset($response['status']) ? (string) $response['status'] : 'success';
                $error_message = isset($response['error']) ? (string) $response['error'] : '';
                $latency = isset($response['latency_ms']) ? (int) $response['latency_ms'] : $latency;
                $copy_identifier = isset($response['copy_id']) ? (string) $response['copy_id'] : null;
            }

            if ($status === 'success') {
                $report['available_copies']++;
            } elseif ($error_message !== '') {
                $report['errors'][] = sprintf('[%s] %s', $region, $error_message);
            }

            $report['regions'][$region] = [
                'status' => $status,
                'latency_ms' => $latency,
                'copy_id' => $copy_identifier,
            ];
        }

        if (!empty($report['errors']) && $report['available_copies'] === 0) {
            $message = implode(' | ', $report['errors']);
            \bjlg_update_option(self::OPTION_STATUS, $report);
            throw new Exception($message);
        }

        $report['latency_ms'] = $report['expected_copies'] > 0
            ? (int) round($total_latency / max(1, $report['expected_copies']))
            : $total_latency;

        \bjlg_update_option(self::OPTION_STATUS, $report);
        $this->cached_status = $report;

        /**
         * Notification à l’infrastructure que la réplication managée est terminée.
         *
         * @param array<string,mixed> $report
         */
        do_action('bjlg_managed_storage_replicated', $report);
    }

    /**
     * {@inheritdoc}
     */
    public function list_remote_backups()
    {
        $settings = $this->get_settings();

        $defaults = [];
        $fetched = apply_filters('bjlg_managed_storage_list_backups', null, $settings);

        if (is_array($fetched)) {
            $defaults = $this->sanitize_remote_backups($fetched);
        }

        if (!empty($defaults)) {
            return $defaults;
        }

        $status = $this->get_status();
        if (!empty($status['recent_backups']) && is_array($status['recent_backups'])) {
            return $this->sanitize_remote_backups($status['recent_backups']);
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function prune_remote_backups($retain_by_number, $retain_by_age_days)
    {
        $settings = $this->get_settings();
        $args = [
            'retain_by_number' => max(0, (int) $retain_by_number),
            'retain_by_age_days' => max(0, (int) $retain_by_age_days),
        ];

        $result = apply_filters('bjlg_managed_storage_prune', null, $settings, $args);

        if ($result instanceof WP_Error) {
            return [
                'success' => false,
                'message' => $result->get_error_message(),
            ];
        }

        if (is_array($result)) {
            return [
                'success' => !empty($result['success']),
                'message' => isset($result['message']) ? (string) $result['message'] : '',
            ];
        }

        return [
            'success' => true,
            'message' => __('Purge distante déléguée à l’infrastructure.', 'backup-jlg'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function delete_remote_backup_by_name($filename)
    {
        $settings = $this->get_settings();
        $response = apply_filters('bjlg_managed_storage_delete_backup', null, $settings, $filename);

        if ($response instanceof WP_Error) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        if (is_array($response)) {
            return [
                'success' => !empty($response['success']),
                'message' => isset($response['message']) ? (string) $response['message'] : '',
            ];
        }

        return [
            'success' => true,
            'message' => __('Suppression distante déléguée à l’infrastructure.', 'backup-jlg'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function get_storage_usage()
    {
        $snapshot = $this->get_remote_quota_snapshot();
        $status = $this->get_status();

        $usage = [
            'used_bytes' => $snapshot['used_bytes'],
            'quota_bytes' => $snapshot['quota_bytes'],
            'free_bytes' => $snapshot['free_bytes'],
            'latency_ms' => isset($snapshot['latency_ms']) ? $snapshot['latency_ms'] : null,
            'errors' => [],
        ];

        if (!empty($snapshot['error'])) {
            $usage['errors'][] = (string) $snapshot['error'];
        }

        if (!empty($status['regions']) && is_array($status['regions'])) {
            $usage['replica_status'] = $status['regions'];
            $usage['latency_breakdown'] = [];
            foreach ($status['regions'] as $region => $data) {
                if (!is_array($data)) {
                    continue;
                }
                $usage['latency_breakdown'][$region] = isset($data['latency_ms']) ? (int) $data['latency_ms'] : null;
            }
        }

        return $usage;
    }

    /**
     * {@inheritdoc}
     */
    public function get_remote_quota_snapshot()
    {
        $stored = \bjlg_get_option(self::OPTION_QUOTA, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $settings = $this->get_settings();

        $refreshed = apply_filters('bjlg_managed_storage_quota_snapshot', null, $settings, $stored);

        if (is_array($refreshed)) {
            $snapshot = $this->sanitize_quota_snapshot($refreshed);
            \bjlg_update_option(self::OPTION_QUOTA, $snapshot);
            return $snapshot;
        }

        return $this->sanitize_quota_snapshot($stored);
    }

    /**
     * Retourne les réglages normalisés de la destination.
     *
     * @return array<string,mixed>
     */
    public function get_settings(): array
    {
        if ($this->cached_settings !== null) {
            return $this->cached_settings;
        }

        $stored = \bjlg_get_option(self::OPTION_SETTINGS, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $settings = wp_parse_args($stored, $this->get_default_settings());
        $settings['contract_id'] = sanitize_text_field((string) $settings['contract_id']);
        $settings['plan'] = $this->sanitize_plan($settings['plan']);
        $settings['primary_region'] = sanitize_text_field((string) $settings['primary_region']);
        $settings['replica_regions'] = $this->sanitize_regions($settings['replica_regions']);
        $settings['soft_quota_gib'] = max(1, (int) $settings['soft_quota_gib']);
        $settings['hard_quota_gib'] = max($settings['soft_quota_gib'], (int) $settings['hard_quota_gib']);
        $settings['burst_percent'] = max(0, min(100, (int) $settings['burst_percent']));
        $settings['rto_minutes'] = max(1, (int) $settings['rto_minutes']);
        $settings['rpo_minutes'] = max(0, (int) $settings['rpo_minutes']);
        $settings['enabled'] = !empty($settings['enabled']);

        $this->cached_settings = $settings;

        return $settings;
    }

    /**
     * Retourne le dernier statut connu de l’infrastructure.
     *
     * @return array<string,mixed>
     */
    public function get_status(): array
    {
        if ($this->cached_status !== null) {
            return $this->cached_status;
        }

        $stored = \bjlg_get_option(self::OPTION_STATUS, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $stored['regions'] = isset($stored['regions']) && is_array($stored['regions']) ? $stored['regions'] : [];
        $stored['last_synced'] = isset($stored['updated_at']) ? (int) $stored['updated_at'] : 0;

        $this->cached_status = $stored;

        return $stored;
    }

    /**
     * Détermine les réglages par défaut.
     */
    private function get_default_settings(): array
    {
        return [
            'enabled' => false,
            'contract_id' => '',
            'plan' => 'standard',
            'primary_region' => '',
            'replica_regions' => [],
            'soft_quota_gib' => 500,
            'hard_quota_gib' => 1024,
            'burst_percent' => 10,
            'rto_minutes' => 30,
            'rpo_minutes' => 60,
        ];
    }

    /**
     * Nettoie une liste de régions.
     *
     * @param mixed $regions
     * @return string[]
     */
    private function sanitize_regions($regions): array
    {
        if (is_string($regions)) {
            $regions = preg_split('/[\s,]+/', $regions);
        }

        if (!is_array($regions)) {
            return [];
        }

        $normalized = [];
        foreach ($regions as $region) {
            $region = trim((string) $region);
            if ($region === '') {
                continue;
            }
            $normalized[] = sanitize_text_field($region);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Garantit que l’offre sélectionnée fait partie du catalogue connu.
     */
    private function sanitize_plan($plan): string
    {
        $plan = sanitize_key((string) $plan);
        $allowed = ['standard', 'enterprise', 'dedicated'];
        if (!in_array($plan, $allowed, true)) {
            $plan = 'standard';
        }

        return $plan;
    }

    /**
     * Assainit un instantané de quota.
     *
     * @param array<string,mixed> $snapshot
     *
     * @return array<string,mixed>
     */
    private function sanitize_quota_snapshot(array $snapshot): array
    {
        $used = isset($snapshot['used_bytes']) ? $this->sanitize_bytes($snapshot['used_bytes']) : null;
        $quota = isset($snapshot['quota_bytes']) ? $this->sanitize_bytes($snapshot['quota_bytes']) : null;
        $free = isset($snapshot['free_bytes']) ? $this->sanitize_bytes($snapshot['free_bytes']) : null;
        if ($free === null && $quota !== null && $used !== null) {
            $free = max(0, $quota - $used);
        }

        $status = isset($snapshot['status']) ? sanitize_key((string) $snapshot['status']) : 'unavailable';
        if (!in_array($status, ['ok', 'unavailable'], true)) {
            $status = 'unavailable';
        }

        $error = isset($snapshot['error']) ? sanitize_text_field((string) $snapshot['error']) : '';
        $latency = isset($snapshot['latency_ms']) && $snapshot['latency_ms'] !== null ? (int) $snapshot['latency_ms'] : null;
        $fetched_at = isset($snapshot['fetched_at']) ? (int) $snapshot['fetched_at'] : 0;

        $result = [
            'status' => $status,
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'free_bytes' => $free,
            'used_human' => $used !== null ? size_format($used) : '',
            'quota_human' => $quota !== null ? size_format($quota) : '',
            'free_human' => $free !== null ? size_format($free) : '',
            'fetched_at' => $fetched_at,
            'error' => $error,
            'latency_ms' => $latency,
        ];

        return $result;
    }

    /**
     * @param mixed $value
     */
    private function sanitize_bytes($value): ?int
    {
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
     * @param array<int,mixed> $backups
     *
     * @return array<int,array<string,mixed>>
     */
    private function sanitize_remote_backups(array $backups): array
    {
        $normalized = [];
        foreach ($backups as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalized[] = [
                'file' => isset($entry['file']) ? (string) $entry['file'] : '',
                'size' => isset($entry['size']) ? (int) $entry['size'] : 0,
                'stored_at' => isset($entry['stored_at']) ? (int) $entry['stored_at'] : 0,
                'region' => isset($entry['region']) ? sanitize_text_field((string) $entry['region']) : '',
            ];
        }

        return $normalized;
    }
}
