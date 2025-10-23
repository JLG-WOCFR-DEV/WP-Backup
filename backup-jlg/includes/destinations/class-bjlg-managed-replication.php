<?php
namespace BJLG;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

if (!interface_exists(BJLG_Destination_Interface::class)) {
    return;
}

/**
 * Destination managée orchestrant la réplication multi-régions et la rotation automatique.
 */
class BJLG_Managed_Replication implements BJLG_Destination_Interface {

    private const OPTION_SETTINGS = 'bjlg_managed_replication_settings';
    private const OPTION_STATUS = 'bjlg_managed_replication_status';

    /** @var array<string, mixed>|null */
    private $cached_settings = null;

    /** @var array<string, mixed>|null */
    private $last_report = null;

    public function get_id() {
        return 'managed_replication';
    }

    public function get_name() {
        return __('Stockage managé multi-régions', 'backup-jlg');
    }

    public function is_connected() {
        $settings = $this->get_settings();
        if (empty($settings['enabled'])) {
            return false;
        }

        $targets = $this->resolve_targets($settings);
        if (empty($targets)) {
            return false;
        }

        foreach ($targets as $target) {
            $destination = $this->instantiate_destination($target['destination_id']);
            if (!$destination instanceof BJLG_Destination_Interface) {
                continue;
            }

            if ($destination->is_connected()) {
                return true;
            }
        }

        return false;
    }

    public function disconnect() {
        \bjlg_update_option(self::OPTION_SETTINGS, $this->get_default_settings());
        \bjlg_update_option(self::OPTION_STATUS, []);
        $this->cached_settings = null;
        $this->last_report = null;
    }

    public function render_settings() {
        $settings = $this->get_settings();
        $providers = BJLG_Settings::get_managed_replication_providers();
        $primary_provider = $settings['primary']['provider'] ?? 'aws_glacier';
        $replica_provider = $settings['replica']['provider'] ?? 'azure_ra_grs';
        $primary_regions = BJLG_Settings::get_managed_replication_region_choices($primary_provider);
        $replica_regions = BJLG_Settings::get_managed_replication_region_choices($replica_provider);
        $enabled_attr = !empty($settings['enabled']) ? " checked='checked'" : '';
        $retain_number = (int) ($settings['retention']['retain_by_number'] ?? 3);
        $retain_days = (int) ($settings['retention']['retain_by_age_days'] ?? 0);
        $expect_copies = (int) ($settings['expected_copies'] ?? 2);
        ?>
        <div class="bjlg-destination bjlg-destination--managed-replication">
            <h4><span class="dashicons dashicons-admin-multisite" aria-hidden="true"></span> <?php esc_html_e('Stockage managé multi-régions', 'backup-jlg'); ?></h4>
            <form class="bjlg-settings-form bjlg-destination-form" method="post" novalidate>
                <div class="bjlg-settings-feedback notice bjlg-hidden" role="status" aria-live="polite"></div>
                <p class="description"><?php esc_html_e('Réplique automatiquement chaque archive vers plusieurs régions (Amazon S3 Glacier, Azure RA-GRS) avec rotation gérée.', 'backup-jlg'); ?></p>
                <input type="hidden" name="managed_replication_submitted" value="1">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Activer la destination managée', 'backup-jlg'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="managed_replication_enabled" value="true"<?php echo $enabled_attr; ?>>
                                <?php esc_html_e('Activer la réplication multi-régions et la rotation automatique.', 'backup-jlg'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Réplica primaire', 'backup-jlg'); ?></th>
                        <td>
                            <label class="screen-reader-text" for="bjlg-managed-primary-provider"><?php esc_html_e('Fournisseur principal', 'backup-jlg'); ?></label>
                            <select id="bjlg-managed-primary-provider" name="managed_replication_primary_provider">
                                <?php foreach ($providers as $provider_key => $provider): ?>
                                    <option value="<?php echo esc_attr($provider_key); ?>" <?php selected($primary_provider, $provider_key); ?>><?php echo esc_html($provider['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="screen-reader-text" for="bjlg-managed-primary-region"><?php esc_html_e('Région primaire', 'backup-jlg'); ?></label>
                            <select id="bjlg-managed-primary-region" name="managed_replication_primary_region">
                                <option value=""><?php esc_html_e('Sélectionner une région', 'backup-jlg'); ?></option>
                                <?php foreach ($primary_regions as $region_key => $region_label): ?>
                                    <option value="<?php echo esc_attr($region_key); ?>" <?php selected($settings['primary']['region'] ?? '', $region_key); ?>><?php echo esc_html($region_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Utilise vos identifiants existants pour Amazon S3 ou Azure selon le fournisseur choisi.', 'backup-jlg'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Réplica secondaire', 'backup-jlg'); ?></th>
                        <td>
                            <label class="screen-reader-text" for="bjlg-managed-replica-provider"><?php esc_html_e('Fournisseur secondaire', 'backup-jlg'); ?></label>
                            <select id="bjlg-managed-replica-provider" name="managed_replication_replica_provider">
                                <?php foreach ($providers as $provider_key => $provider): ?>
                                    <option value="<?php echo esc_attr($provider_key); ?>" <?php selected($replica_provider, $provider_key); ?>><?php echo esc_html($provider['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="screen-reader-text" for="bjlg-managed-replica-region"><?php esc_html_e('Région secondaire', 'backup-jlg'); ?></label>
                            <select id="bjlg-managed-replica-region" name="managed_replication_replica_region">
                                <option value=""><?php esc_html_e('Sélectionner une région', 'backup-jlg'); ?></option>
                                <?php foreach ($replica_regions as $region_key => $region_label): ?>
                                    <option value="<?php echo esc_attr($region_key); ?>" <?php selected($settings['replica']['region'] ?? '', $region_key); ?>><?php echo esc_html($region_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="managed_replication_replica_secondary" value="<?php echo esc_attr($settings['replica']['secondary_region'] ?? ''); ?>" class="regular-text" placeholder="<?php esc_attr_e('Région jumelle (Azure RA-GRS)', 'backup-jlg'); ?>">
                            <p class="description"><?php esc_html_e('Pour Azure RA-GRS, indiquez la paire de région secondaire (ex : northeurope).', 'backup-jlg'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Objectif de copies disponibles', 'backup-jlg'); ?></th>
                        <td>
                            <input type="number" min="1" max="5" step="1" name="managed_replication_expected_copies" value="<?php echo esc_attr($expect_copies); ?>" class="small-text">
                            <p class="description"><?php esc_html_e('Déclenche une alerte SLA si le nombre de copies réussies descend sous ce seuil.', 'backup-jlg'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Rotation automatique', 'backup-jlg'); ?></th>
                        <td>
                            <label for="bjlg-managed-retain-number"><?php esc_html_e('Conserver au maximum', 'backup-jlg'); ?></label>
                            <input type="number" id="bjlg-managed-retain-number" name="managed_replication_retain_number" min="1" max="50" step="1" value="<?php echo esc_attr($retain_number); ?>" class="small-text">
                            <span><?php esc_html_e('copies par région', 'backup-jlg'); ?></span>
                            <br>
                            <label for="bjlg-managed-retain-days"><?php esc_html_e('Purger au-delà de', 'backup-jlg'); ?></label>
                            <input type="number" id="bjlg-managed-retain-days" name="managed_replication_retain_days" min="0" max="3650" step="1" value="<?php echo esc_attr($retain_days); ?>" class="small-text">
                            <span><?php esc_html_e('jours (0 = désactivé)', 'backup-jlg'); ?></span>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Enregistrer les réglages', 'backup-jlg'); ?></button></p>
            </form>
        </div>
        <?php
    }

    public function upload_file($filepath, $task_id) {
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
            throw new Exception(__('La réplication managée est désactivée.', 'backup-jlg'));
        }

        $targets = $this->resolve_targets($settings);
        if (empty($targets)) {
            throw new Exception(__('Aucune cible de réplication configurée.', 'backup-jlg'));
        }

        $report = [
            'task_id' => $task_id,
            'expected_copies' => max(1, (int) ($settings['expected_copies'] ?? count($targets))),
            'replicas' => [],
            'available_copies' => 0,
            'failed_copies' => 0,
            'errors' => [],
            'started_at' => time(),
        ];

        foreach ($targets as $target) {
            $replica_entry = [
                'provider' => $target['provider'],
                'label' => $target['label'],
                'region' => $target['region'],
                'role' => $target['role'],
                'latency_ms' => null,
                'status' => 'pending',
                'message' => '',
            ];

            $destination = $this->instantiate_destination($target['destination_id']);
            if (!$destination instanceof BJLG_Destination_Interface) {
                $replica_entry['status'] = 'error';
                $replica_entry['message'] = __('Destination distante indisponible.', 'backup-jlg');
                $report['failed_copies']++;
                $report['errors'][] = sprintf('%s (%s)', $replica_entry['label'], $replica_entry['message']);
                $report['replicas'][] = $replica_entry;
                continue;
            }

            if (!$destination->is_connected()) {
                $replica_entry['status'] = 'error';
                $replica_entry['message'] = __('Connexion distante inactive.', 'backup-jlg');
                $report['failed_copies']++;
                $report['errors'][] = sprintf('%s (%s)', $replica_entry['label'], $replica_entry['message']);
                $report['replicas'][] = $replica_entry;
                continue;
            }

            $start = microtime(true);
            try {
                $destination->upload_file($filepath, $task_id);
                $replica_entry['status'] = 'success';
                $replica_entry['latency_ms'] = (int) round((microtime(true) - $start) * 1000);
                $report['available_copies']++;
                $this->apply_rotation($destination, $settings);
            } catch (Exception $exception) {
                $replica_entry['status'] = 'error';
                $replica_entry['message'] = $exception->getMessage();
                $report['failed_copies']++;
                $report['errors'][] = sprintf('%s (%s)', $replica_entry['label'], $replica_entry['message']);
            }

            $report['replicas'][] = $replica_entry;
        }

        $report['completed_at'] = time();
        $report['latency_ms'] = $this->compute_average_latency($report['replicas']);
        $report['status'] = $this->resolve_status_code($report);

        $this->last_report = $report;
        $this->update_status($report);

        if ($report['failed_copies'] >= $report['available_copies'] && $report['available_copies'] === 0) {
            throw new Exception(sprintf(__('La réplication a échoué (%s).', 'backup-jlg'), implode(' | ', $report['errors'])));
        }

        if ($report['status'] !== 'healthy') {
            /**
             * Déclenche une notification SLA lorsqu'une réplication est dégradée.
             */
            do_action('bjlg_managed_replication_sla_breached', $report);
        }
    }

    public function list_remote_backups() {
        $settings = $this->get_settings();
        $targets = $this->resolve_targets($settings);
        $all = [];

        foreach ($targets as $target) {
            $destination = $this->instantiate_destination($target['destination_id']);
            if (!$destination instanceof BJLG_Destination_Interface) {
                continue;
            }

            try {
                $entries = $destination->list_remote_backups();
            } catch (Exception $exception) {
                BJLG_Debug::log(sprintf('Listage impossible sur %s : %s', $target['label'], $exception->getMessage()));
                continue;
            }

            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $entry['provider'] = $target['provider'];
                $entry['region'] = $target['region'];
                $entry['role'] = $target['role'];
                $entry['replica_label'] = $target['label'];
                $all[] = $entry;
            }
        }

        return $all;
    }

    public function prune_remote_backups($retain_by_number, $retain_by_age_days) {
        $settings = $this->get_settings();
        $targets = $this->resolve_targets($settings);
        $results = [
            'success' => [],
            'errors' => [],
        ];

        foreach ($targets as $target) {
            $destination = $this->instantiate_destination($target['destination_id']);
            if (!$destination instanceof BJLG_Destination_Interface) {
                $results['errors'][$target['provider']] = __('Destination indisponible.', 'backup-jlg');
                continue;
            }

            try {
                $result = $destination->prune_remote_backups($retain_by_number, $retain_by_age_days);
                $results['success'][$target['provider']] = $result;
            } catch (Exception $exception) {
                $results['errors'][$target['provider']] = $exception->getMessage();
            }
        }

        return $results;
    }

    public function delete_remote_backup_by_name($filename) {
        $settings = $this->get_settings();
        $targets = $this->resolve_targets($settings);

        foreach ($targets as $target) {
            $destination = $this->instantiate_destination($target['destination_id']);
            if (!$destination instanceof BJLG_Destination_Interface) {
                continue;
            }

            try {
                $result = $destination->delete_remote_backup_by_name($filename);
                if (is_array($result) && !empty($result['success'])) {
                    return $result;
                }
            } catch (Exception $exception) {
                BJLG_Debug::log(sprintf('Suppression impossible sur %s : %s', $target['label'], $exception->getMessage()));
            }
        }

        return [
            'success' => false,
            'message' => __('Archive introuvable parmi les réplicas.', 'backup-jlg'),
        ];
    }

    public function get_storage_usage() {
        $settings = $this->get_settings();
        $targets = $this->resolve_targets($settings);
        $used_bytes = 0;
        $quota_bytes = 0;
        $free_bytes = 0;
        $latencies = [];
        $errors = [];

        foreach ($targets as $target) {
            $destination = $this->instantiate_destination($target['destination_id']);
            if (!$destination instanceof BJLG_Destination_Interface) {
                $errors[] = sprintf(__('Destination %s indisponible.', 'backup-jlg'), $target['label']);
                continue;
            }

            try {
                $usage = $destination->get_storage_usage();
            } catch (Exception $exception) {
                $errors[] = sprintf('%s: %s', $target['label'], $exception->getMessage());
                continue;
            }

            if (!is_array($usage)) {
                continue;
            }

            if (isset($usage['used_bytes']) && is_numeric($usage['used_bytes'])) {
                $used_bytes += (int) $usage['used_bytes'];
            }
            if (isset($usage['quota_bytes']) && is_numeric($usage['quota_bytes'])) {
                $quota_bytes += (int) $usage['quota_bytes'];
            }
            if (isset($usage['free_bytes']) && is_numeric($usage['free_bytes'])) {
                $free_bytes += (int) $usage['free_bytes'];
            }
            if (isset($usage['latency_ms']) && is_numeric($usage['latency_ms'])) {
                $latencies[] = (int) $usage['latency_ms'];
            }
            if (!empty($usage['errors']) && is_array($usage['errors'])) {
                $errors = array_merge($errors, array_map('strval', $usage['errors']));
            }
        }

        $status = self::get_status_snapshot();
        if (!empty($status['replicas'])) {
            foreach ($status['replicas'] as $replica) {
                if (isset($replica['latency_ms']) && $replica['latency_ms'] !== null) {
                    $latencies[] = (int) $replica['latency_ms'];
                }
            }
        }

        if (!empty($status['errors'])) {
            $errors = array_merge($errors, array_map('strval', (array) $status['errors']));
        }

        return [
            'used_bytes' => $used_bytes > 0 ? $used_bytes : null,
            'quota_bytes' => $quota_bytes > 0 ? $quota_bytes : null,
            'free_bytes' => $free_bytes > 0 ? $free_bytes : null,
            'latency_ms' => $this->compute_average($latencies),
            'errors' => array_values(array_unique(array_filter($errors))),
            'available_copies' => $status['available_copies'] ?? 0,
            'expected_copies' => $status['expected_copies'] ?? count($targets),
            'replicas' => $status['replicas'] ?? [],
            'status' => $status['status'] ?? 'inactive',
            'updated_at' => $status['updated_at'] ?? 0,
        ];
    }

    public function get_remote_quota_snapshot() {
        $status = $this->get_status_snapshot();
        $usage = $this->get_storage_usage();

        return [
            'status' => ($status['status'] ?? 'inactive') === 'healthy' ? 'ok' : 'unavailable',
            'used_bytes' => $usage['used_bytes'] ?? null,
            'quota_bytes' => $usage['quota_bytes'] ?? null,
            'free_bytes' => $usage['free_bytes'] ?? null,
            'fetched_at' => time(),
            'error' => empty($usage['errors']) ? null : implode(' | ', $usage['errors']),
            'source' => 'provider',
        ];
    }

    /**
     * Retourne le dernier rapport généré pendant l'upload courant.
     */
    public function get_last_report(): array {
        return is_array($this->last_report) ? $this->last_report : [];
    }

    /**
     * Retourne un instantané persistant des métriques de résilience.
     */
    public static function get_status_snapshot(): array {
        $status = \bjlg_get_option(self::OPTION_STATUS, []);
        if (!is_array($status)) {
            $status = [];
        }

        $status['available_copies'] = isset($status['available_copies']) ? max(0, (int) $status['available_copies']) : 0;
        $status['expected_copies'] = isset($status['expected_copies']) ? max(0, (int) $status['expected_copies']) : 0;
        $status['latency_ms'] = isset($status['latency_ms']) ? max(0, (int) $status['latency_ms']) : null;
        $status['updated_at'] = isset($status['updated_at']) ? (int) $status['updated_at'] : 0;
        if (!isset($status['replicas']) || !is_array($status['replicas'])) {
            $status['replicas'] = [];
        }
        if (!isset($status['errors']) || !is_array($status['errors'])) {
            $status['errors'] = [];
        }
        if (!isset($status['status']) || !is_string($status['status'])) {
            $status['status'] = 'inactive';
        }

        return $status;
    }

    private function get_settings(): array {
        if ($this->cached_settings !== null) {
            return $this->cached_settings;
        }

        $settings = \bjlg_get_option(self::OPTION_SETTINGS, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $defaults = $this->get_default_settings();
        $settings = BJLG_Settings::merge_settings_with_defaults($settings, $defaults);

        $settings['enabled'] = !empty($settings['enabled']);
        $settings['expected_copies'] = max(1, (int) ($settings['expected_copies'] ?? 2));

        $this->cached_settings = $settings;

        return $settings;
    }

    private function get_default_settings(): array {
        return BJLG_Settings::get_default_managed_replication_settings();
    }

    private function resolve_targets(array $settings): array {
        $targets = [];
        $providers = BJLG_Settings::get_managed_replication_providers();

        $primary = $settings['primary'] ?? [];
        $replica = $settings['replica'] ?? [];

        $primary_provider = isset($primary['provider']) ? sanitize_key((string) $primary['provider']) : 'aws_glacier';
        $replica_provider = isset($replica['provider']) ? sanitize_key((string) $replica['provider']) : 'azure_ra_grs';

        if (isset($providers[$primary_provider])) {
            $targets[] = [
                'provider' => $primary_provider,
                'label' => $providers[$primary_provider]['label'],
                'region' => isset($primary['region']) ? (string) $primary['region'] : '',
                'role' => 'primary',
                'destination_id' => $providers[$primary_provider]['destination_id'],
            ];
        }

        if (isset($providers[$replica_provider])) {
            $targets[] = [
                'provider' => $replica_provider,
                'label' => $providers[$replica_provider]['label'],
                'region' => isset($replica['region']) ? (string) $replica['region'] : '',
                'role' => 'replica',
                'destination_id' => $providers[$replica_provider]['destination_id'],
                'secondary_region' => isset($replica['secondary_region']) ? (string) $replica['secondary_region'] : '',
            ];
        }

        return $targets;
    }

    private function instantiate_destination(string $destination_id) {
        if ($destination_id === $this->get_id()) {
            return null;
        }

        return BJLG_Destination_Factory::create($destination_id);
    }

    private function apply_rotation(BJLG_Destination_Interface $destination, array $settings): void {
        $retain_number = isset($settings['retention']['retain_by_number']) ? (int) $settings['retention']['retain_by_number'] : 0;
        $retain_days = isset($settings['retention']['retain_by_age_days']) ? (int) $settings['retention']['retain_by_age_days'] : 0;

        if ($retain_number <= 0 && $retain_days <= 0) {
            return;
        }

        try {
            $destination->prune_remote_backups($retain_number, $retain_days);
        } catch (Exception $exception) {
            BJLG_Debug::log(sprintf('Rotation managée impossible : %s', $exception->getMessage()));
        }
    }

    private function compute_average_latency(array $replicas): ?int {
        $latencies = [];
        foreach ($replicas as $replica) {
            if (!is_array($replica)) {
                continue;
            }
            if (isset($replica['latency_ms']) && is_numeric($replica['latency_ms'])) {
                $latencies[] = (int) $replica['latency_ms'];
            }
        }

        return $this->compute_average($latencies);
    }

    private function compute_average(array $values): ?int {
        $values = array_filter(array_map('intval', $values), static function ($value) {
            return $value >= 0;
        });

        if (empty($values)) {
            return null;
        }

        return (int) round(array_sum($values) / max(1, count($values)));
    }

    private function resolve_status_code(array $report): string {
        if ($report['available_copies'] >= $report['expected_copies']) {
            return 'healthy';
        }

        if ($report['available_copies'] > 0) {
            return 'degraded';
        }

        return 'failed';
    }

    private function update_status(array $report): void {
        $payload = [
            'updated_at' => time(),
            'available_copies' => (int) $report['available_copies'],
            'expected_copies' => (int) $report['expected_copies'],
            'latency_ms' => $report['latency_ms'] ?? null,
            'replicas' => $report['replicas'],
            'errors' => $report['errors'],
            'status' => $report['status'],
        ];

        \bjlg_update_option(self::OPTION_STATUS, $payload);
    }

}
