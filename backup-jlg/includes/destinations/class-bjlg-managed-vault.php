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
 * Destination "Managed Vault" offrant réplication multi-région et rétention immuable.
 */
class BJLG_Managed_Vault extends BJLG_S3_Compatible_Destination
{
    private const OPTION_SETTINGS = 'bjlg_managed_vault_settings';
    private const OPTION_STATUS = 'bjlg_managed_vault_status';
    private const OPTION_METRICS = 'bjlg_managed_vault_metrics';
    private const OPTION_VERSIONS = 'bjlg_managed_vault_versions';

    private const DEFAULT_LATENCY_BUDGET_MS = 4000;
    private const MAX_TRACKED_VERSIONS = 50;

    /** @var array<string,mixed> */
    private $last_delivery = [];

    /**
     * Identifiant unique de la destination.
     */
    protected function get_service_id()
    {
        return 'managed_vault';
    }

    /**
     * Libellé public.
     */
    protected function get_service_name()
    {
        return __('Managed Vault', 'backup-jlg');
    }

    protected function get_settings_option_name()
    {
        return self::OPTION_SETTINGS;
    }

    protected function get_status_option_name()
    {
        return self::OPTION_STATUS;
    }

    protected function build_host(array $settings)
    {
        $region = isset($settings['region']) ? (string) $settings['region'] : '';
        $region = $region !== '' ? $region : 'global';

        return sprintf('vault-%s.api.managed-vault.example', $region);
    }

    protected function get_default_settings()
    {
        $defaults = parent::get_default_settings();

        $defaults['primary_region'] = '';
        $defaults['replica_regions'] = [];
        $defaults['immutability_days'] = 0;
        $defaults['retention_max_versions'] = 20;
        $defaults['credential_strategy'] = 'manual';
        $defaults['credential_rotation_interval'] = 90;
        $defaults['last_credential_rotation'] = 0;
        $defaults['latency_budget_ms'] = self::DEFAULT_LATENCY_BUDGET_MS;
        $defaults['object_lock_mode'] = 'GOVERNANCE';
        $defaults['versioning'] = true;

        return $defaults;
    }

    protected function merge_settings(array $settings)
    {
        $merged = parent::merge_settings($settings);

        $primary = isset($merged['primary_region']) ? (string) $merged['primary_region'] : '';
        $merged['primary_region'] = $primary;
        $merged['region'] = $primary !== '' ? $primary : (string) ($merged['region'] ?? '');

        if (!isset($merged['replica_regions']) || !is_array($merged['replica_regions'])) {
            $merged['replica_regions'] = [];
        }

        $merged['replica_regions'] = $this->sanitize_region_list($merged['replica_regions']);
        $merged['immutability_days'] = max(0, (int) ($merged['immutability_days'] ?? 0));
        $merged['retention_max_versions'] = max(1, (int) ($merged['retention_max_versions'] ?? 20));
        $merged['credential_strategy'] = in_array($merged['credential_strategy'] ?? 'manual', ['manual', 'vault_managed'], true)
            ? $merged['credential_strategy']
            : 'manual';
        $merged['credential_rotation_interval'] = max(1, (int) ($merged['credential_rotation_interval'] ?? 90));
        $merged['last_credential_rotation'] = max(0, (int) ($merged['last_credential_rotation'] ?? 0));
        $merged['latency_budget_ms'] = max(100, (int) ($merged['latency_budget_ms'] ?? self::DEFAULT_LATENCY_BUDGET_MS));
        $merged['object_lock_mode'] = in_array($merged['object_lock_mode'] ?? 'GOVERNANCE', ['GOVERNANCE', 'COMPLIANCE'], true)
            ? $merged['object_lock_mode']
            : 'GOVERNANCE';
        $merged['versioning'] = !empty($merged['versioning']);

        return $merged;
    }

    public function render_settings()
    {
        $settings = $this->get_settings();
        $status = $this->get_status();
        $metrics = $this->get_metrics();

        $replica_regions = implode(", ", $settings['replica_regions']);
        $latency_budget = (int) $settings['latency_budget_ms'];
        $immutability = (int) $settings['immutability_days'];

        echo "<div class='bjlg-destination bjlg-destination--managed-vault'>";
        echo "<h4><span class='dashicons dashicons-shield' aria-hidden='true'></span> " . esc_html($this->get_service_name()) . "</h4>";
        echo "<form class='bjlg-settings-form bjlg-destination-form' method='post'>";
        echo "<div class='bjlg-settings-feedback notice bjlg-hidden' role='status' aria-live='polite'></div>";
        echo "<p class='description'>" . esc_html__('Répliquez vos sauvegardes sur plusieurs régions avec verrouillage immuable.', 'backup-jlg') . "</p>";

        if (function_exists('wp_nonce_field')) {
            wp_nonce_field('bjlg_save_managed_vault', 'bjlg_managed_vault_nonce');
        }

        echo "<table class='form-table'>";
        echo "<tr><th scope='row'>" . esc_html__('Access Key ID', 'backup-jlg') . "</th><td><input type='text' name='managed_vault_access_key' value='" . esc_attr($settings['access_key']) . "' class='regular-text' autocomplete='off'></td></tr>";
        echo "<tr><th scope='row'>" . esc_html__('Secret Access Key', 'backup-jlg') . "</th><td><input type='password' name='managed_vault_secret_key' value='" . esc_attr($settings['secret_key']) . "' class='regular-text' autocomplete='off'></td></tr>";
        echo "<tr><th scope='row'>" . esc_html__('Bucket', 'backup-jlg') . "</th><td><input type='text' name='managed_vault_bucket' value='" . esc_attr($settings['bucket']) . "' class='regular-text' placeholder='wp-backups'></td></tr>";
        echo "<tr><th scope='row'>" . esc_html__('Préfixe d\'objet', 'backup-jlg') . "</th><td><input type='text' name='managed_vault_object_prefix' value='" . esc_attr($settings['object_prefix']) . "' class='regular-text' placeholder='backups/'><p class='description'>" . esc_html__('Optionnel. Permet de classer les sauvegardes dans un sous-dossier.', 'backup-jlg') . "</p></td></tr>";

        echo "<tr><th scope='row'>" . esc_html__('Région primaire', 'backup-jlg') . "</th><td><input type='text' name='managed_vault_primary_region' value='" . esc_attr($settings['primary_region']) . "' class='regular-text' placeholder='eu-west-3'><p class='description'>" . esc_html__('Point d\'entrée utilisé pour la première écriture.', 'backup-jlg') . "</p></td></tr>";
        echo "<tr><th scope='row'>" . esc_html__('Régions répliquées', 'backup-jlg') . "</th><td><textarea name='managed_vault_replica_regions' rows='2' class='large-text' placeholder='us-east-1, ap-southeast-2'>" . esc_textarea($replica_regions) . "</textarea><p class='description'>" . esc_html__('Indiquez une liste séparée par des virgules ou retours à la ligne.', 'backup-jlg') . "</p></td></tr>";

        echo "<tr><th scope='row'>" . esc_html__('Durée de rétention immuable (jours)', 'backup-jlg') . "</th><td><input type='number' min='0' name='managed_vault_immutability_days' value='" . esc_attr($immutability) . "' class='small-text'><p class='description'>" . esc_html__('Pendant cette période, aucune purge ne sera appliquée.', 'backup-jlg') . "</p></td></tr>";

        echo "<tr><th scope='row'>" . esc_html__('Nombre maximal de versions', 'backup-jlg') . "</th><td><input type='number' min='1' name='managed_vault_retention_versions' value='" . esc_attr((int) $settings['retention_max_versions']) . "' class='small-text'><p class='description'>" . esc_html__('Les versions les plus anciennes seront purgées une fois la limite dépassée (hors période immuable).', 'backup-jlg') . "</p></td></tr>";

        echo "<tr><th scope='row'>" . esc_html__('Budget de latence (ms)', 'backup-jlg') . "</th><td><input type='number' min='100' step='50' name='managed_vault_latency_budget' value='" . esc_attr($latency_budget) . "' class='small-text'><p class='description'>" . esc_html__('Déclenche des alertes si une région dépasse ce délai.', 'backup-jlg') . "</p></td></tr>";

        $enabled_attr = $settings['enabled'] ? " checked='checked'" : '';
        echo "<tr><th scope='row'>" . esc_html__('Activer Managed Vault', 'backup-jlg') . "</th><td><label><input type='checkbox' name='managed_vault_enabled' value='true'{$enabled_attr}> " . esc_html__('Envoyer les sauvegardes vers Managed Vault.', 'backup-jlg') . "</label></td></tr>";

        echo "</table>";

        echo "<p class='submit'><button type='submit' class='button button-primary'>" . esc_html__('Enregistrer les réglages', 'backup-jlg') . "</button></p>";
        echo "</form>";

        $rotation_label = esc_html__('Dernière rotation des identifiants', 'backup-jlg');
        $rotation_date = $settings['last_credential_rotation'] > 0
            ? esc_html(gmdate('d/m/Y H:i', (int) $settings['last_credential_rotation']))
            : esc_html__('Jamais', 'backup-jlg');

        echo "<p class='description'><span class='dashicons dashicons-update' aria-hidden='true'></span> {$rotation_label} : {$rotation_date}</p>";

        if (!empty($metrics['replica_status'])) {
            echo "<div class='bjlg-managed-vault-metrics'><h5>" . esc_html__('État des réplicas', 'backup-jlg') . "</h5><ul>";
            foreach ($metrics['replica_status'] as $region => $data) {
                $status = isset($data['status']) ? (string) $data['status'] : 'unknown';
                $latency = isset($data['latency_ms']) ? (int) $data['latency_ms'] : null;
                $label = sprintf('%s — %s', esc_html($region), esc_html(ucfirst($status)));
                if ($latency !== null) {
                    $label .= sprintf(' (%d ms)', $latency);
                }
                echo '<li>' . $label . '</li>';
            }
            echo '</ul></div>';
        }

        if ($status['last_result'] === 'error') {
            echo "<p class='description' style='color:#b32d2e;'><span class='dashicons dashicons-warning' aria-hidden='true'></span> " . esc_html($status['message']) . "</p>";
        } elseif ($status['last_result'] === 'success') {
            echo "<p class='description'><span class='dashicons dashicons-yes' aria-hidden='true'></span> " . esc_html__('Connexion vérifiée.', 'backup-jlg') . "</p>";
        }

        echo '</div>';
    }

    public function upload_file($filepath, $task_id)
    {
        $this->upload_with_resilience($filepath, $task_id, []);
    }

    /**
     * Upload multi-région avec suivi des tentatives.
     *
     * @param string               $filepath
     * @param string               $task_id
     * @param array<string,mixed>  $context
     *
     * @return array<string,mixed>
     * @throws Exception
     */
    public function upload_with_resilience($filepath, $task_id, array $context)
    {
        if (!is_readable($filepath)) {
            throw new Exception('Fichier de sauvegarde introuvable : ' . $filepath);
        }

        $settings = $this->get_settings();
        if (!$this->is_connected()) {
            throw new Exception($this->get_service_name() . ' n\'est pas configuré.');
        }

        $regions = $this->get_all_regions($settings);
        if (empty($regions)) {
            throw new Exception('Aucune région configurée pour Managed Vault.');
        }

        $object_key = $this->build_object_key(basename($filepath), $settings['object_prefix']);
        $contents = file_get_contents($filepath);
        if ($contents === false) {
            throw new Exception('Impossible de lire le fichier de sauvegarde à envoyer.');
        }

        $pending = $context['pending_regions'] ?? $regions;
        if (!is_array($pending) || empty($pending)) {
            $pending = $regions;
        }

        $pending = $this->sanitize_region_list($pending);

        $version_id = isset($context['version_id']) && is_string($context['version_id']) && $context['version_id'] !== ''
            ? $context['version_id']
            : $this->generate_version_id($object_key);

        $results = [];
        $errors = [];

        foreach ($regions as $region) {
            $already_replicated = !in_array($region, $pending, true);
            if ($already_replicated) {
                $results[$region] = [
                    'status' => 'skipped',
                    'latency_ms' => null,
                ];
                continue;
            }

            $start = microtime(true);
            try {
                $regional_settings = $settings;
                $regional_settings['region'] = $region;

                $headers = [
                    'Content-Type' => 'application/zip',
                    'Content-Length' => (string) filesize($filepath),
                    'x-amz-meta-bjlg-task' => (string) $task_id,
                    'x-amz-meta-bjlg-version' => $version_id,
                ];

                if ($settings['server_side_encryption'] !== '') {
                    $headers['x-amz-server-side-encryption'] = $settings['server_side_encryption'];
                    if ($settings['server_side_encryption'] === 'aws:kms' && $settings['kms_key_id'] !== '') {
                        $headers['x-amz-server-side-encryption-aws-kms-key-id'] = $settings['kms_key_id'];
                    }
                }

                if ($settings['immutability_days'] > 0) {
                    $headers['x-amz-object-lock-mode'] = $settings['object_lock_mode'];
                    $headers['x-amz-object-lock-retain-until-date'] = gmdate('Y-m-d\TH:i:s\Z', time() + ($settings['immutability_days'] * DAY_IN_SECONDS));
                }

                $headers = $this->filter_headers($headers, $regional_settings);

                $this->perform_request('PUT', $object_key, $contents, $headers, $regional_settings);

                $latency = (int) round((microtime(true) - $start) * 1000);
                $results[$region] = [
                    'status' => 'ok',
                    'latency_ms' => $latency,
                ];

                $this->log(sprintf('Réplique Managed Vault %s : OK (%d ms).', $region, $latency));

                if ($latency > (int) $settings['latency_budget_ms']) {
                    $this->emit_alert('latency_budget_exceeded', 'warning', sprintf('Latence %d ms dans %s', $latency, $region), [
                        'region' => $region,
                        'latency_ms' => $latency,
                        'budget_ms' => (int) $settings['latency_budget_ms'],
                        'version_id' => $version_id,
                    ]);
                }
            } catch (Exception $exception) {
                $results[$region] = [
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ];
                $errors[$region] = $exception->getMessage();
                $this->log(sprintf('ERREUR réplique Managed Vault %s : %s', $region, $exception->getMessage()));
            }
        }

        $this->record_replication_results($object_key, $version_id, $results);

        if (!empty($errors)) {
            $resume_context = [
                'version_id' => $version_id,
                'object_key' => $object_key,
                'pending_regions' => array_keys($errors),
            ];

            $this->store_resume_context($resume_context);

            $this->emit_alert('replica_degraded', 'critical', 'Répliques Managed Vault incomplètes.', [
                'regions' => array_keys($errors),
                'version_id' => $version_id,
            ]);

            $this->last_delivery = [
                'version_id' => $version_id,
                'regions' => $results,
                'object_key' => $object_key,
                'resume' => $resume_context,
            ];

            throw new Exception('Replication partielle sur Managed Vault : ' . implode(' | ', $errors));
        }

        $this->clear_resume_context($version_id);

        $this->last_delivery = [
            'version_id' => $version_id,
            'regions' => $results,
            'object_key' => $object_key,
            'resume' => [],
        ];

        return $this->last_delivery;
    }

    public function list_remote_backups()
    {
        $backups = parent::list_remote_backups();
        $versions = $this->get_versions();

        foreach ($backups as &$backup) {
            if (!is_array($backup)) {
                continue;
            }
            $name = isset($backup['name']) ? (string) $backup['name'] : '';
            if ($name === '') {
                continue;
            }
            $version = $versions[$name] ?? null;
            if (!$version) {
                continue;
            }
            $backup['replica_status'] = $version['regions'] ?? [];
            $backup['version_id'] = $version['version_id'] ?? '';
        }

        return $backups;
    }

    public function prune_remote_backups($retain_by_number, $retain_by_age_days)
    {
        $result = parent::prune_remote_backups($retain_by_number, $retain_by_age_days);
        $versions = $this->get_versions();
        $immutability_end = time() - ($this->get_settings()['immutability_days'] * DAY_IN_SECONDS);

        $protected = [];
        foreach ($versions as $filename => $version) {
            $timestamp = isset($version['timestamp']) ? (int) $version['timestamp'] : 0;
            if ($timestamp > $immutability_end) {
                $protected[] = $filename;
            }
        }

        if (!empty($protected)) {
            $result['immutable_protected'] = $protected;
        }

        if (!empty($result['deleted_items'])) {
            foreach ($result['deleted_items'] as $deleted) {
                if (isset($versions[$deleted])) {
                    unset($versions[$deleted]);
                }
            }
            $this->store_versions($versions);
        }

        return $result;
    }

    public function get_storage_usage()
    {
        $usage = parent::get_storage_usage();
        $metrics = $this->get_metrics();

        if (!isset($usage['replica_status'])) {
            $usage['replica_status'] = $metrics['replica_status'] ?? [];
        }
        if (!isset($usage['latency_breakdown'])) {
            $usage['latency_breakdown'] = $metrics['latency_breakdown'] ?? [];
        }

        return $usage;
    }

    public function get_remote_quota_snapshot()
    {
        $snapshot = parent::get_remote_quota_snapshot();
        $metrics = $this->get_metrics();

        $snapshot['replica_status'] = $metrics['replica_status'] ?? [];
        $snapshot['latency_breakdown'] = $metrics['latency_breakdown'] ?? [];
        $snapshot['last_version_id'] = $metrics['last_version_id'] ?? '';
        $snapshot['immutability_days'] = isset($metrics['immutability_days']) ? (int) $metrics['immutability_days'] : $this->get_settings()['immutability_days'];

        return $snapshot;
    }

    private function sanitize_region_list($regions)
    {
        if (!is_array($regions)) {
            if (is_string($regions)) {
                $regions = preg_split('/[\s,]+/', $regions);
            } else {
                return [];
            }
        }

        $sanitized = [];
        foreach ($regions as $region) {
            if (!is_scalar($region)) {
                continue;
            }
            $slug = strtolower(trim((string) $region));
            if ($slug === '') {
                continue;
            }
            $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
            if ($slug === '') {
                continue;
            }
            $sanitized[$slug] = true;
        }

        return array_keys($sanitized);
    }

    private function get_all_regions(array $settings)
    {
        $primary = $settings['primary_region'] ?? '';
        $regions = $this->sanitize_region_list($settings['replica_regions'] ?? []);
        if ($primary !== '') {
            array_unshift($regions, $primary);
        }

        return array_values(array_unique($regions));
    }

    private function generate_version_id($object_key)
    {
        return gmdate('YmdHis') . '-' . substr(md5($object_key . microtime(true)), 0, 8);
    }

    private function get_metrics()
    {
        $metrics = \bjlg_get_option(self::OPTION_METRICS, []);
        if (!is_array($metrics)) {
            $metrics = [];
        }

        $metrics += [
            'replica_status' => [],
            'latency_breakdown' => [],
            'last_version_id' => '',
            'immutability_days' => $this->get_settings()['immutability_days'],
        ];

        return $metrics;
    }

    private function store_metrics(array $metrics)
    {
        \bjlg_update_option(self::OPTION_METRICS, $metrics);
    }

    private function record_replication_results($object_key, $version_id, array $results)
    {
        $metrics = $this->get_metrics();
        $metrics['last_version_id'] = $version_id;
        $metrics['latency_breakdown'] = [];
        $metrics['replica_status'] = [];
        $metrics['immutability_days'] = $this->get_settings()['immutability_days'];

        $version_entry = [
            'object_key' => $object_key,
            'version_id' => $version_id,
            'timestamp' => time(),
            'regions' => [],
        ];

        foreach ($results as $region => $data) {
            $status = is_array($data) && isset($data['status']) ? (string) $data['status'] : 'unknown';
            $latency = is_array($data) && isset($data['latency_ms']) ? (int) $data['latency_ms'] : null;
            $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : '';

            $metrics['replica_status'][$region] = [
                'status' => $status,
                'latency_ms' => $latency,
                'message' => $message,
            ];

            if ($latency !== null) {
                $metrics['latency_breakdown'][$region] = $latency;
            }

            $version_entry['regions'][$region] = $metrics['replica_status'][$region];
        }

        $versions = $this->get_versions();
        $versions[$object_key] = $version_entry;
        if (count($versions) > self::MAX_TRACKED_VERSIONS) {
            $versions = array_slice($versions, -self::MAX_TRACKED_VERSIONS, null, true);
        }

        $this->store_metrics($metrics);
        $this->store_versions($versions);
    }

    private function get_versions()
    {
        $versions = \bjlg_get_option(self::OPTION_VERSIONS, []);
        if (!is_array($versions)) {
            $versions = [];
        }

        return $versions;
    }

    private function store_versions(array $versions)
    {
        \bjlg_update_option(self::OPTION_VERSIONS, $versions);
    }

    private function store_resume_context(array $context)
    {
        $pending = \bjlg_get_option('bjlg_managed_vault_resume', []);
        if (!is_array($pending)) {
            $pending = [];
        }

        $version_id = isset($context['version_id']) ? (string) $context['version_id'] : '';
        if ($version_id === '') {
            return;
        }

        $pending[$version_id] = $context;
        \bjlg_update_option('bjlg_managed_vault_resume', $pending);
    }

    private function clear_resume_context($version_id)
    {
        $pending = \bjlg_get_option('bjlg_managed_vault_resume', []);
        if (!is_array($pending) || empty($pending[$version_id])) {
            return;
        }

        unset($pending[$version_id]);
        \bjlg_update_option('bjlg_managed_vault_resume', $pending);
    }

    private function emit_alert($type, $severity, $message, array $context = [])
    {
        $payload = [
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
            'destination' => $this->get_service_id(),
        ];

        do_action('bjlg_managed_vault_alert', $payload);
    }

    /**
     * Fournit le dernier rapport d'envoi/réplication.
     *
     * @return array<string,mixed>
     */
    public function get_last_delivery_report(): array
    {
        return $this->last_delivery;
    }
}
