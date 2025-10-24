<?php
namespace BJLG;

/**
 * Classe pour gérer les sauvegardes incrémentales
 * Fichier : includes/class-bjlg-incremental.php
 */

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

if (!defined('ABSPATH')) {
    exit;
}

class BJLG_Incremental {

    private const MANIFEST_VERSION = '2.1';

    private const DEFAULT_INCREMENTAL_SETTINGS = [
        'max_incrementals' => 10,
        'max_full_age_days' => 30,
        'rotation_enabled' => true,
    ];

    private $manifest_file;
    private $last_backup_data;
    private $file_hash_cache = [];

    /**
     * @var array<string, bool> Liste des chemins relatifs supprimés détectés lors du scan courant.
     */
    private $deleted_files_cache = [];

    /**
     * @var string|null Empreinte de la dernière mise à jour pour éviter les doublons.
     */
    private $last_manifest_signature = null;

    /**
     * @var self|null Référence vers la dernière instance initialisée.
     */
    private static $latest_instance = null;

    /**
     * @var bool Indique si les hooks de capture temps réel sont enregistrés.
     */
    private static $realtime_hooks_registered = false;

    /**
     * @var array<string, int> Mémoire de débounce pour éviter les doublons très rapprochés.
     */
    private static $realtime_debounce = [];

    public function __construct() {
        $this->manifest_file = bjlg_get_backup_directory() . '.incremental-manifest.json';
        $this->load_manifest();

        self::$latest_instance = $this;

        // Hooks
        add_filter('bjlg_backup_type', [$this, 'determine_backup_type'], 10, 2);
        add_action('bjlg_backup_complete', [$this, 'update_manifest'], 10, 2);

        // AJAX
        add_action('wp_ajax_bjlg_get_incremental_info', [$this, 'ajax_get_info']);
        add_action('wp_ajax_bjlg_reset_incremental', [$this, 'ajax_reset']);
        add_action('wp_ajax_bjlg_analyze_changes', [$this, 'ajax_analyze_changes']);

        self::bootstrap_realtime_integrations();
    }
    
    /**
     * Charge le manifeste des dernières sauvegardes
     */
    private function load_manifest() {
        if (file_exists($this->manifest_file)) {
            $content = file_get_contents($this->manifest_file);
            $this->last_backup_data = json_decode($content, true);

            if (is_array($this->last_backup_data)) {
                $this->upgrade_manifest_structure();
            }

            // Validation de la structure
            if (!$this->validate_manifest()) {
                $this->reset_manifest();
            }
        } else {
            $this->reset_manifest();
        }
    }
    
    /**
     * Valide la structure du manifeste
     */
    private function validate_manifest() {
        if (!is_array($this->last_backup_data)) {
            return false;
        }

        $required_keys = ['full_backup', 'incremental_backups', 'synthetic_full', 'file_hashes', 'database_checksums', 'remote_purge_queue', 'deleted_files'];
        foreach ($required_keys as $key) {
            if (!is_array($this->last_backup_data) || !array_key_exists($key, $this->last_backup_data)) {
                return false;
            }
        }

        if (!is_array($this->last_backup_data['incremental_backups']) || !is_array($this->last_backup_data['synthetic_full'])) {
            return false;
        }

        if (!is_array($this->last_backup_data['remote_purge_queue'])) {
            return false;
        }

        return true;
    }

    private function upgrade_manifest_structure() {
        if (!is_array($this->last_backup_data)) {
            return;
        }

        if (!isset($this->last_backup_data['incremental_backups']) || !is_array($this->last_backup_data['incremental_backups'])) {
            $this->last_backup_data['incremental_backups'] = [];
        }

        if (!isset($this->last_backup_data['synthetic_full']) || !is_array($this->last_backup_data['synthetic_full'])) {
            $this->last_backup_data['synthetic_full'] = [];
        }

        if (!isset($this->last_backup_data['remote_purge_queue']) || !is_array($this->last_backup_data['remote_purge_queue'])) {
            $this->last_backup_data['remote_purge_queue'] = [];
        }

        if (!isset($this->last_backup_data['deleted_files']) || !is_array($this->last_backup_data['deleted_files'])) {
            $this->last_backup_data['deleted_files'] = [];
        }

        if (!isset($this->last_backup_data['version']) || version_compare((string) $this->last_backup_data['version'], self::MANIFEST_VERSION, '<')) {
            $this->last_backup_data['version'] = self::MANIFEST_VERSION;
        }

        if (!empty($this->last_backup_data['full_backup']) && is_array($this->last_backup_data['full_backup'])) {
            if (!isset($this->last_backup_data['full_backup']['destinations']) || !is_array($this->last_backup_data['full_backup']['destinations'])) {
                $this->last_backup_data['full_backup']['destinations'] = [];
            }

            $this->last_backup_data['full_backup']['destinations'] = $this->normalize_destinations($this->last_backup_data['full_backup']['destinations']);
        }

        foreach ($this->last_backup_data['incremental_backups'] as &$incremental) {
            if (!is_array($incremental)) {
                $incremental = [];
            }

            if (!isset($incremental['destinations']) || !is_array($incremental['destinations'])) {
                $incremental['destinations'] = [];
            }

            $incremental['destinations'] = $this->normalize_destinations($incremental['destinations']);
        }
        unset($incremental);

        foreach ($this->last_backup_data['synthetic_full'] as &$segment) {
            if (!is_array($segment)) {
                $segment = [];
            }

            if (!isset($segment['destinations']) || !is_array($segment['destinations'])) {
                $segment['destinations'] = [];
            }

            $segment['destinations'] = $this->normalize_destinations($segment['destinations']);
        }
        unset($segment);

        foreach ($this->last_backup_data['remote_purge_queue'] as &$pending) {
            if (!is_array($pending)) {
                $pending = [];
            }

            $pending['destinations'] = $this->normalize_destinations($pending['destinations'] ?? []);
            $pending['registered_at'] = isset($pending['registered_at']) ? (int) $pending['registered_at'] : time();
            if (!isset($pending['status']) || !is_string($pending['status']) || $pending['status'] === '') {
                $pending['status'] = 'pending';
            }
            $pending['attempts'] = isset($pending['attempts']) ? max(0, (int) $pending['attempts']) : 0;
            $pending['last_attempt_at'] = isset($pending['last_attempt_at']) ? (int) $pending['last_attempt_at'] : 0;
            $pending['last_error'] = isset($pending['last_error']) ? (string) $pending['last_error'] : '';
            if (!isset($pending['errors']) || !is_array($pending['errors'])) {
                $pending['errors'] = [];
            }

            $normalized_errors = [];
            foreach ($pending['errors'] as $destination => $message) {
                if (!is_string($destination)) {
                    $destination = (string) $destination;
                }
                $normalized_errors[$destination] = is_string($message) ? $message : (string) $message;
            }

            $pending['errors'] = $normalized_errors;
        }
        unset($pending);
    }

    private function get_incremental_settings() {
        $settings = \bjlg_get_option('bjlg_incremental_settings', self::DEFAULT_INCREMENTAL_SETTINGS);

        if (!is_array($settings)) {
            $settings = [];
        }

        $settings = wp_parse_args($settings, self::DEFAULT_INCREMENTAL_SETTINGS);

        $settings['max_incrementals'] = max(0, (int) ($settings['max_incrementals'] ?? 0));
        $settings['max_full_age_days'] = max(0, (int) ($settings['max_full_age_days'] ?? 0));
        $settings['rotation_enabled'] = !empty($settings['rotation_enabled']);

        return $settings;
    }

    private function normalize_destinations($destinations) {
        if (!is_array($destinations)) {
            if ($destinations === null || $destinations === '') {
                return [];
            }

            $destinations = [$destinations];
        }

        $normalized = [];

        foreach ($destinations as $destination) {
            if (!is_scalar($destination)) {
                continue;
            }

            $slug = sanitize_key((string) $destination);
            if ($slug === '') {
                continue;
            }

            $normalized[$slug] = $slug;
        }

        return array_values($normalized);
    }

    private function merge_destinations(array $primary, array $secondary) {
        $primary = $this->normalize_destinations($primary);
        $secondary = $this->normalize_destinations($secondary);

        return array_values(array_unique(array_merge($primary, $secondary)));
    }

    /**
     * @param mixed $errors
     * @return array<string,string>
     */
    private function normalize_error_list($errors) {
        if (!is_array($errors)) {
            return [];
        }

        $normalized = [];

        foreach ($errors as $destination => $message) {
            if (!is_string($destination)) {
                $destination = (string) $destination;
            }

            if ($destination === '') {
                continue;
            }

            $normalized[$destination] = is_string($message) ? $message : (string) $message;
        }

        return $normalized;
    }

    private function enforce_rotation() {
        $settings = $this->get_incremental_settings();
        $limit = isset($settings['max_incrementals']) ? (int) $settings['max_incrementals'] : 0;

        if ($limit <= 0) {
            return;
        }

        $rotation_enabled = !empty($settings['rotation_enabled']);

        while (count($this->last_backup_data['incremental_backups']) > $limit) {
            $oldest = array_shift($this->last_backup_data['incremental_backups']);

            if (!$rotation_enabled) {
                array_unshift($this->last_backup_data['incremental_backups'], $oldest);
                break;
            }

            if (!is_array($oldest)) {
                $oldest = [];
            }

            $this->merge_incremental_into_synthetic($oldest);
        }
    }

    private function merge_incremental_into_synthetic(array $incremental) {
        if (empty($this->last_backup_data['full_backup']) || !is_array($this->last_backup_data['full_backup'])) {
            return;
        }

        $incremental['destinations'] = $this->normalize_destinations($incremental['destinations'] ?? []);
        $incremental['merged_at'] = time();
        $incremental['type'] = 'incremental';

        $this->last_backup_data['synthetic_full'][] = $incremental;

        if (!isset($this->last_backup_data['full_backup']['destinations']) || !is_array($this->last_backup_data['full_backup']['destinations'])) {
            $this->last_backup_data['full_backup']['destinations'] = [];
        }

        $this->last_backup_data['full_backup']['destinations'] = $this->merge_destinations(
            $this->last_backup_data['full_backup']['destinations'],
            $incremental['destinations']
        );

        if (isset($incremental['size'])) {
            $this->last_backup_data['full_backup']['size'] = (int) ($this->last_backup_data['full_backup']['size'] ?? 0) + (int) $incremental['size'];
        }

        if (isset($incremental['timestamp'])) {
            $this->last_backup_data['full_backup']['timestamp'] = max(
                (int) ($this->last_backup_data['full_backup']['timestamp'] ?? 0),
                (int) $incremental['timestamp']
            );
        }

        $this->register_remote_purge($incremental);
    }

    private function register_remote_purge(array $incremental) {
        $destinations = $this->normalize_destinations($incremental['destinations'] ?? []);
        $file = isset($incremental['file']) ? (string) $incremental['file'] : '';

        if ($file === '' || empty($destinations)) {
            return;
        }

        $entry = [
            'file' => $file,
            'destinations' => $destinations,
            'registered_at' => time(),
            'status' => 'pending',
            'attempts' => 0,
            'last_attempt_at' => 0,
            'next_attempt_at' => time(),
            'last_error' => '',
            'errors' => [],
            'failed_at' => 0,
            'last_delay' => 0,
            'max_delay' => 0,
            'delay_alerted' => false,
        ];

        $queue_entry = null;

        foreach ($this->last_backup_data['remote_purge_queue'] as &$existing) {
            if (!is_array($existing)) {
                continue;
            }

            if (!isset($existing['file']) || $existing['file'] !== $file) {
                continue;
            }

            $existing['destinations'] = $this->merge_destinations($existing['destinations'] ?? [], $destinations);
            $existing['registered_at'] = $entry['registered_at'];
            $existing['status'] = 'pending';
            $existing['last_error'] = '';
            $existing['errors'] = [];
            $existing['attempts'] = 0;
            $existing['last_attempt_at'] = 0;
            $existing['next_attempt_at'] = $entry['registered_at'];
            $existing['failed_at'] = 0;
            $existing['last_delay'] = 0;
            $existing['max_delay'] = 0;
            $existing['delay_alerted'] = false;
            $queue_entry = $existing;
            break;
        }
        unset($existing);

        if ($queue_entry === null) {
            $this->last_backup_data['remote_purge_queue'][] = $entry;
            $queue_entry = end($this->last_backup_data['remote_purge_queue']);
        }

        if (function_exists('do_action')) {
            do_action('bjlg_incremental_remote_purge', $queue_entry, $this->last_backup_data);
        }
    }

    public function mark_remote_purge_completed($file, array $destinations = []) {
        $file = basename((string) $file);
        if ($file === '') {
            return false;
        }

        $destinations = $this->normalize_destinations($destinations);
        $modified = false;

        foreach ($this->last_backup_data['remote_purge_queue'] as $index => &$entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (!isset($entry['file']) || $entry['file'] !== $file) {
                continue;
            }

            $entry_destinations = $this->normalize_destinations($entry['destinations'] ?? []);

            if (empty($destinations)) {
                unset($this->last_backup_data['remote_purge_queue'][$index]);
                $modified = true;
                continue;
            }

            $remaining = array_values(array_diff($entry_destinations, $destinations));

            if (count($remaining) === count($entry_destinations)) {
                continue;
            }

            if (empty($remaining)) {
                unset($this->last_backup_data['remote_purge_queue'][$index]);
            } else {
                $entry['destinations'] = $remaining;
                $entry['status'] = 'pending';
                $entry['next_attempt_at'] = time();
                $entry['failed_at'] = 0;
                $entry['errors'] = [];
                $entry['last_error'] = '';
                $entry['last_delay'] = 0;
                $entry['max_delay'] = 0;
                $entry['delay_alerted'] = false;
            }

            $modified = true;
        }
        unset($entry);

        if ($modified) {
            $this->last_backup_data['remote_purge_queue'] = array_values($this->last_backup_data['remote_purge_queue']);
            $this->save_manifest();

            BJLG_Debug::log(sprintf(
                'Purge distante synchronisée pour %s (%s).',
                $file,
                empty($destinations) ? 'toutes destinations' : implode(', ', $destinations)
            ));
        }

        return $modified;
    }

    /**
     * Retourne la file de purge distante.
     *
     * @return array<int, array<string,mixed>>
     */
    public function get_remote_purge_queue() {
        $queue = [];

        foreach ($this->last_backup_data['remote_purge_queue'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $queue[] = [
                'file' => isset($entry['file']) ? (string) $entry['file'] : '',
                'destinations' => $this->normalize_destinations($entry['destinations'] ?? []),
                'status' => isset($entry['status']) ? (string) $entry['status'] : 'pending',
                'registered_at' => isset($entry['registered_at']) ? (int) $entry['registered_at'] : 0,
                'attempts' => isset($entry['attempts']) ? (int) $entry['attempts'] : 0,
                'last_attempt_at' => isset($entry['last_attempt_at']) ? (int) $entry['last_attempt_at'] : 0,
                'next_attempt_at' => isset($entry['next_attempt_at']) ? (int) $entry['next_attempt_at'] : 0,
                'last_error' => isset($entry['last_error']) ? (string) $entry['last_error'] : '',
                'errors' => $this->normalize_error_list($entry['errors'] ?? []),
                'failed_at' => isset($entry['failed_at']) ? (int) $entry['failed_at'] : 0,
                'last_delay' => isset($entry['last_delay']) ? max(0, (int) $entry['last_delay']) : 0,
                'max_delay' => isset($entry['max_delay']) ? max(0, (int) $entry['max_delay']) : 0,
                'delay_alerted' => !empty($entry['delay_alerted']),
            ];
        }

        return $queue;
    }

    /**
     * Met à jour une entrée de purge distante.
     *
     * @param string              $file
     * @param array<string,mixed> $data
     */
    public function update_remote_purge_entry($file, array $data) {
        $file = basename((string) $file);
        if ($file === '') {
            return false;
        }

        $modified = false;

        foreach ($this->last_backup_data['remote_purge_queue'] as &$entry) {
            if (!is_array($entry) || !isset($entry['file']) || $entry['file'] !== $file) {
                continue;
            }

            if (isset($data['status'])) {
                $status = sanitize_key((string) $data['status']);
                $entry['status'] = $status !== '' ? $status : 'pending';
            }

            if (array_key_exists('last_error', $data)) {
                $entry['last_error'] = (string) $data['last_error'];
            }

            if (array_key_exists('errors', $data)) {
                $entry['errors'] = $this->normalize_error_list($data['errors']);
            }

            if (isset($data['attempts'])) {
                $entry['attempts'] = max(0, (int) $data['attempts']);
            }

            if (isset($data['last_attempt_at'])) {
                $entry['last_attempt_at'] = max(0, (int) $data['last_attempt_at']);
            }

            if (isset($data['next_attempt_at'])) {
                $entry['next_attempt_at'] = max(0, (int) $data['next_attempt_at']);
            }

            if (isset($data['failed_at'])) {
                $entry['failed_at'] = max(0, (int) $data['failed_at']);
            }

            if (isset($data['last_delay'])) {
                $entry['last_delay'] = max(0, (int) $data['last_delay']);
            }

            if (isset($data['max_delay'])) {
                $entry['max_delay'] = max(
                    isset($entry['last_delay']) ? (int) $entry['last_delay'] : 0,
                    max(0, (int) $data['max_delay'])
                );
            } elseif (!isset($entry['max_delay'])) {
                $entry['max_delay'] = isset($entry['last_delay']) ? (int) $entry['last_delay'] : 0;
            }

            if (array_key_exists('delay_alerted', $data)) {
                $entry['delay_alerted'] = (bool) $data['delay_alerted'];
            } elseif (!isset($entry['delay_alerted'])) {
                $entry['delay_alerted'] = false;
            }

            $modified = true;
            break;
        }
        unset($entry);

        if ($modified) {
            $this->save_manifest();
        }

        return $modified;
    }

    /**
     * Relance immédiatement une entrée de purge distante en échec.
     */
    public function retry_remote_purge_entry($file) {
        $file = basename((string) $file);
        if ($file === '') {
            return false;
        }

        $modified = false;
        $now = time();

        foreach ($this->last_backup_data['remote_purge_queue'] as &$entry) {
            if (!is_array($entry) || !isset($entry['file']) || $entry['file'] !== $file) {
                continue;
            }

            $entry['status'] = 'pending';
            $entry['attempts'] = 0;
            $entry['last_attempt_at'] = 0;
            $entry['next_attempt_at'] = $now;
            $entry['failed_at'] = 0;
            $entry['errors'] = [];
            $entry['last_error'] = '';
            $entry['last_delay'] = 0;
            $entry['max_delay'] = 0;
            $entry['delay_alerted'] = false;

            $modified = true;
            break;
        }
        unset($entry);

        if (!$modified) {
            return false;
        }

        if ($this->save_manifest() && class_exists(BJLG_Debug::class)) {
            BJLG_Debug::log(sprintf('Relance manuelle de la purge distante pour %s.', $file));
        }

        if (function_exists('do_action')) {
            do_action('bjlg_remote_purge_entry_retried', $file);
        }

        return true;
    }

    /**
     * Supprime une entrée de purge distante.
     */
    public function delete_remote_purge_entry($file) {
        $file = basename((string) $file);
        if ($file === '') {
            return false;
        }

        $modified = false;

        foreach ($this->last_backup_data['remote_purge_queue'] as $index => $entry) {
            if (!is_array($entry) || !isset($entry['file']) || $entry['file'] !== $file) {
                continue;
            }

            unset($this->last_backup_data['remote_purge_queue'][$index]);
            $modified = true;
            break;
        }

        if (!$modified) {
            return false;
        }

        $this->last_backup_data['remote_purge_queue'] = array_values($this->last_backup_data['remote_purge_queue']);

        if ($this->save_manifest() && class_exists(BJLG_Debug::class)) {
            BJLG_Debug::log(sprintf('Entrée de purge distante supprimée pour %s.', $file));
        }

        if (function_exists('do_action')) {
            do_action('bjlg_remote_purge_entry_deleted', $file);
        }

        return true;
    }
    
    /**
     * Réinitialise le manifeste
     */
    private function reset_manifest() {
        $this->last_backup_data = [
            'full_backup' => null,
            'incremental_backups' => [],
            'synthetic_full' => [],
            'file_hashes' => [],
            'database_checksums' => [],
            'last_scan' => null,
            'remote_purge_queue' => [],
            'deleted_files' => [],
            'version' => self::MANIFEST_VERSION
        ];
        $this->last_manifest_signature = null;
        if (!$this->save_manifest()) {
            BJLG_Debug::log("ERREUR: La réinitialisation du manifeste incrémental n'a pas pu être enregistrée.");
        }
    }
    
    /**
     * Sauvegarde le manifeste
     */
    private function save_manifest() {
        $this->last_backup_data['version'] = self::MANIFEST_VERSION;
        $json = json_encode($this->last_backup_data, JSON_PRETTY_PRINT);

        if ($json === false) {
            BJLG_Debug::log("ERREUR: Impossible d'encoder le manifeste incrémental.");

            return false;
        }

        $bytes_written = @file_put_contents($this->manifest_file, $json, LOCK_EX);

        if ($bytes_written === false) {
            BJLG_Debug::log("ERREUR: Échec de l'écriture du manifeste incrémental dans {$this->manifest_file}.");

            return false;
        }

        // Protéger le fichier
        @chmod($this->manifest_file, 0600);

        return true;
    }
    
    /**
     * Détermine si une sauvegarde incrémentale est possible
     */
    public function can_do_incremental() {
        $settings = $this->get_incremental_settings();
        // Vérifier qu'une sauvegarde complète existe
        if (empty($this->last_backup_data['full_backup'])) {
            BJLG_Debug::log("Pas de sauvegarde complète de référence.");
            return false;
        }

        // Vérifier que la sauvegarde complète existe toujours
        $full_backup_entry = $this->last_backup_data['full_backup'];
        $full_backup_path = '';

        if (is_array($full_backup_entry)) {
            if (!empty($full_backup_entry['path']) && is_string($full_backup_entry['path'])) {
                $full_backup_path = $full_backup_entry['path'];
            } elseif (!empty($full_backup_entry['file']) && is_string($full_backup_entry['file'])) {
                $full_backup_path = bjlg_get_backup_directory() . ltrim($full_backup_entry['file'], '\\/');
            }
        }

        if ($full_backup_path === '') {
            BJLG_Debug::log("Chemin de la sauvegarde complète introuvable dans le manifeste.");
            return false;
        }

        if (!file_exists($full_backup_path)) {
            BJLG_Debug::log("La sauvegarde complète de référence n'existe plus.");
            $this->reset_manifest();
            return false;
        }

        // Vérifier l'âge de la dernière sauvegarde complète
        $full_backup_time = isset($this->last_backup_data['full_backup']['timestamp'])
            ? (int) $this->last_backup_data['full_backup']['timestamp']
            : 0;
        $days_old = $full_backup_time > 0 ? (time() - $full_backup_time) / DAY_IN_SECONDS : 0;

        $max_age = isset($settings['max_full_age_days']) ? (int) $settings['max_full_age_days'] : 0;
        if ($max_age > 0 && $days_old > $max_age) {
            BJLG_Debug::log(sprintf("La sauvegarde complète a plus de %d jours, une nouvelle est recommandée.", $max_age));
            return false;
        }

        // Vérifier le nombre de sauvegardes incrémentales
        $max_incrementals = isset($settings['max_incrementals']) ? (int) $settings['max_incrementals'] : 0;
        $incremental_count = count($this->last_backup_data['incremental_backups']);

        if ($max_incrementals > 0 && $incremental_count >= $max_incrementals) {
            if (!empty($settings['rotation_enabled'])) {
                BJLG_Debug::log(sprintf(
                    'Limite de %d sauvegardes incrémentales atteinte : une rotation synthétique sera appliquée.',
                    $max_incrementals
                ));
            } else {
                BJLG_Debug::log(sprintf('Limite de %d sauvegardes incrémentales atteinte.', $max_incrementals));
                return false;
            }
        }

        return true;
    }
    
    /**
     * Obtient les fichiers modifiés depuis la dernière sauvegarde
     */
    public function get_modified_files($directory) {
        $changes = [
            'modified' => [],
            'deleted' => [],
        ];

        $last_scan_time = $this->last_backup_data['last_scan'] ?? 0;

        // Scanner récursivement le répertoire
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $normalized_abspath = $this->normalize_path(ABSPATH);
        $normalized_directory = $this->normalize_path($directory);
        $directory_prefix = $normalized_directory !== '' ? rtrim($normalized_directory, '/') . '/' : '';

        $unique_modified = [];
        $seen_relative_paths = [];

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $filepath = $file->getRealPath();
            if ($filepath === false) {
                $filepath = $file->getPathname();
            }

            $normalized_filepath = $this->normalize_path($filepath);
            $relative_path = $normalized_filepath;
            if ($normalized_abspath !== '' && strpos($normalized_filepath, $normalized_abspath) === 0) {
                $relative_path = ltrim(substr($normalized_filepath, strlen($normalized_abspath)), '/');
            }

            $seen_relative_paths[$relative_path] = true;

            // Vérifier si le fichier a été modifié
            $mtime = $file->getMTime();
            $current_hash = $this->get_file_hash($filepath);
            $stored_hash = $this->last_backup_data['file_hashes'][$relative_path] ?? null;

            if ($mtime > $last_scan_time || $current_hash !== $stored_hash) {
                $unique_modified[$normalized_filepath] = true;
            }

            if ($current_hash !== null) {
                $this->file_hash_cache[$relative_path] = $current_hash;
            }
        }

        $deleted_relatives = [];
        if (!empty($this->last_backup_data['file_hashes']) && is_array($this->last_backup_data['file_hashes'])) {
            foreach ($this->last_backup_data['file_hashes'] as $stored_relative => $hash) {
                if (!is_string($stored_relative) || $stored_relative === '') {
                    continue;
                }

                if (isset($seen_relative_paths[$stored_relative])) {
                    continue;
                }

                $absolute_candidate = $stored_relative;
                if (!$this->is_absolute_path($stored_relative) && $normalized_abspath !== '') {
                    $absolute_candidate = rtrim($normalized_abspath, '/') . '/' . ltrim($stored_relative, '/');
                }

                $absolute_candidate = $this->normalize_path($absolute_candidate);

                if ($directory_prefix !== '' && strpos(rtrim($absolute_candidate, '/') . '/', $directory_prefix) !== 0) {
                    continue;
                }

                if ($absolute_candidate === '' || file_exists($absolute_candidate)) {
                    continue;
                }

                $deleted_relatives[$stored_relative] = true;
                $this->deleted_files_cache[$stored_relative] = true;
            }
        }

        $changes['modified'] = array_keys($unique_modified);
        $changes['deleted'] = array_keys($deleted_relatives);

        BJLG_Debug::log(sprintf(
            'Fichiers modifiés : %d | Fichiers supprimés : %d',
            count($changes['modified']),
            count($changes['deleted'])
        ));

        return $changes;
    }

    /**
     * Normalise un chemin de fichier en utilisant les conventions WordPress si disponibles.
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
     * Calcule le hash d'un fichier
     */
    private function get_file_hash($filepath) {
        // Pour les petits fichiers, utiliser md5 du contenu complet
        if (filesize($filepath) < 1024 * 1024) { // Moins de 1MB
            return md5_file($filepath);
        }
        
        // Pour les gros fichiers, hash partiel pour économiser les ressources
        $handle = fopen($filepath, 'rb');
        if (!$handle) return null;
        
        $hash_context = hash_init('md5');
        
        // Hash du début (1MB)
        $data = fread($handle, 1024 * 1024);
        hash_update($hash_context, $data);
        
        // Hash du milieu (1MB)
        fseek($handle, filesize($filepath) / 2);
        $data = fread($handle, 1024 * 1024);
        hash_update($hash_context, $data);
        
        // Hash de la fin (1MB)
        fseek($handle, -1024 * 1024, SEEK_END);
        $data = fread($handle, 1024 * 1024);
        hash_update($hash_context, $data);
        
        // Ajouter la taille et la date de modification
        hash_update($hash_context, filesize($filepath) . filemtime($filepath));
        
        fclose($handle);
        
        return hash_final($hash_context);
    }
    
    /**
     * Vérifie si une table a changé
     */
    public function table_has_changed($table_name) {
        global $wpdb;
        
        // Calculer le checksum de la table
        $checksum_result = $wpdb->get_row("CHECKSUM TABLE `{$table_name}`", ARRAY_A);
        
        if (!$checksum_result || !isset($checksum_result['Checksum'])) {
            // Si pas de checksum disponible, considérer comme modifié
            return true;
        }
        
        $current_checksum = $checksum_result['Checksum'];
        $stored_checksum = $this->last_backup_data['database_checksums'][$table_name] ?? null;
        
        if ($current_checksum !== $stored_checksum) {
            BJLG_Debug::log("Table $table_name modifiée (checksum: $current_checksum)");
            return true;
        }
        
        return false;
    }
    
    /**
     * Met à jour le manifeste après une sauvegarde
     */
    public function update_manifest($backup_reference, $details = []) {
        $details = is_array($details) ? $details : [];

        $backup_filepath = $this->resolve_backup_path($backup_reference, $details);
        $backup_filename = '';

        if ($backup_filepath !== '') {
            $backup_filename = basename($backup_filepath);
        } elseif (isset($details['file']) && is_string($details['file'])) {
            $backup_filename = basename($details['file']);
        } elseif (is_string($backup_reference) && $backup_reference !== '') {
            $backup_filename = basename($backup_reference);
        }

        if ($backup_filepath === '' && $backup_filename !== '') {
            $backup_filepath = bjlg_get_backup_directory() . ltrim($backup_filename, '\\/');
        }

        $components = [];
        if (isset($details['components']) && is_array($details['components'])) {
            $components = array_values(array_map('strval', $details['components']));
        }

        $size = isset($details['size']) ? (int) $details['size'] : 0;
        if ($size <= 0 && $backup_filepath !== '' && file_exists($backup_filepath)) {
            $size = filesize($backup_filepath);
        }

        $timestamp = isset($details['timestamp']) ? (int) $details['timestamp'] : 0;
        if ($timestamp <= 0) {
            $timestamp = time();
        }

        $backup_info = [
            'file' => $backup_filename,
            'path' => $backup_filepath,
            'timestamp' => $timestamp,
            'components' => $components,
            'size' => $size,
            'destinations' => $this->normalize_destinations($details['destinations'] ?? [])
        ];

        $is_incremental = false;
        if (isset($details['incremental'])) {
            $is_incremental = (bool) $details['incremental'];
        } elseif (strpos($backup_filename, 'incremental') !== false) {
            $is_incremental = true;
        }

        $signature_data = [
            'file' => $backup_info['file'],
            'path' => $backup_info['path'],
            'timestamp' => $backup_info['timestamp'],
            'size' => $backup_info['size'],
            'components' => $backup_info['components'],
            'incremental' => $is_incremental ? '1' : '0'
        ];
        $signature = md5(json_encode($signature_data));

        if ($this->last_manifest_signature === $signature) {
            BJLG_Debug::log("Aucune mise à jour du manifeste nécessaire (données identiques).");
            return;
        }

        $this->last_manifest_signature = $signature;

        if ($is_incremental) {
            $this->last_backup_data['incremental_backups'][] = $backup_info;

            $this->enforce_rotation();
        } else {
            $this->last_backup_data['full_backup'] = $backup_info;
            $this->last_backup_data['incremental_backups'] = [];
            $this->last_backup_data['synthetic_full'] = [];
            $this->update_all_checksums();
            $this->last_backup_data['deleted_files'] = [];
        }

        foreach ($this->file_hash_cache as $path => $hash) {
            $this->last_backup_data['file_hashes'][$path] = $hash;
        }

        $deleted_relatives = array_keys($this->deleted_files_cache);

        if (!empty($deleted_relatives)) {
            foreach ($deleted_relatives as $relative_path) {
                unset($this->last_backup_data['file_hashes'][$relative_path]);
            }
        }

        if ($is_incremental) {
            $normalized_deleted = [];
            foreach ($deleted_relatives as $relative_path) {
                $normalized = $this->normalize_deleted_relative_path($relative_path);
                if ($normalized !== '') {
                    $normalized_deleted[] = $normalized;
                }
            }

            $this->last_backup_data['deleted_files'] = $normalized_deleted;
        }

        $this->deleted_files_cache = [];
        $this->file_hash_cache = [];

        $this->last_backup_data['last_scan'] = time();

        if ($this->save_manifest()) {
            BJLG_Debug::log("Manifeste incrémental mis à jour");
        }
    }

    private function normalize_deleted_relative_path($path) {
        $normalized = $this->normalize_path($path);
        if ($normalized === '') {
            return '';
        }

        $normalized_abspath = $this->normalize_path(ABSPATH);
        if ($normalized_abspath !== '' && strpos($normalized, $normalized_abspath) === 0) {
            return ltrim(substr($normalized, strlen($normalized_abspath)), '/');
        }

        if (defined('WP_CONTENT_DIR')) {
            $content_dir = $this->normalize_path(WP_CONTENT_DIR);
            if ($content_dir !== '' && strpos($normalized, rtrim($content_dir, '/') . '/') === 0) {
                $subpath = ltrim(substr($normalized, strlen($content_dir)), '/');

                return $subpath !== '' ? 'wp-content/' . $subpath : 'wp-content';
            }
        }

        return ltrim($normalized, '/');
    }

    /**
     * Récupère la dernière instance initialisée.
     *
     * @return self|null
     */
    public static function get_latest_instance() {
        return self::$latest_instance;
    }

    /**
     * Détermine le chemin absolu du fichier de sauvegarde à partir des détails fournis.
     *
     * @param mixed $backup_reference
     * @param array<string, mixed> $details
     * @return string
     */
    private function resolve_backup_path($backup_reference, array $details) {
        $candidates = [];

        if (isset($details['path']) && is_string($details['path']) && $details['path'] !== '') {
            $candidates[] = $details['path'];
        }

        if (isset($details['file']) && is_string($details['file']) && $details['file'] !== '') {
            $candidates[] = $details['file'];
        }

        if (is_string($backup_reference) && $backup_reference !== '') {
            $candidates[] = $backup_reference;
        }

        $fallback = '';

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            if ($this->is_absolute_path($candidate)) {
                if (file_exists($candidate)) {
                    return $candidate;
                }

                if ($fallback === '') {
                    $fallback = $candidate;
                }
                continue;
            }

            $absolute = bjlg_get_backup_directory() . ltrim($candidate, '\\/');
            if (file_exists($absolute)) {
                return $absolute;
            }

            if ($fallback === '') {
                $fallback = $absolute;
            }
        }

        return $fallback;
    }

    /**
     * Vérifie si un chemin est absolu.
     */
    private function is_absolute_path($path) {
        if ($path === '') {
            return false;
        }

        return $path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
    }

    /**
     * Met à jour tous les checksums de la base de données
     */
    private function update_all_checksums() {
        global $wpdb;
        
        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
        $this->last_backup_data['database_checksums'] = [];
        
        foreach ($tables as $table_array) {
            $table = $table_array[0];
            $checksum_result = $wpdb->get_row("CHECKSUM TABLE `{$table}`", ARRAY_A);
            
            if ($checksum_result && isset($checksum_result['Checksum'])) {
                $this->last_backup_data['database_checksums'][$table] = $checksum_result['Checksum'];
            }
        }
        
        BJLG_Debug::log("Checksums de " . count($tables) . " tables mis à jour");
    }
    
    /**
     * AJAX : Obtient les informations sur l'état incrémental
     */
    public function ajax_get_info() {
        if (!\bjlg_can_manage_backups()) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }

        $settings = $this->get_incremental_settings();
        $info = [
            'can_do_incremental' => $this->can_do_incremental(),
            'full_backup' => null,
            'incremental_count' => 0,
            'total_size' => 0,
            'last_backup' => null,
            'next_full_recommended' => null,
            'space_saved' => 0
        ];
        
        if ($this->last_backup_data['full_backup']) {
            $info['full_backup'] = [
                'file' => $this->last_backup_data['full_backup']['file'],
                'date' => date('d/m/Y H:i', $this->last_backup_data['full_backup']['timestamp']),
                'size' => size_format($this->last_backup_data['full_backup']['size']),
                'age_days' => round((time() - $this->last_backup_data['full_backup']['timestamp']) / DAY_IN_SECONDS)
            ];

            $synthetic_count = isset($this->last_backup_data['synthetic_full']) && is_array($this->last_backup_data['synthetic_full'])
                ? count($this->last_backup_data['synthetic_full'])
                : 0;

            $info['incremental_count'] = count($this->last_backup_data['incremental_backups']) + $synthetic_count;

            // Calculer la taille totale
            $total_size = $this->last_backup_data['full_backup']['size'];
            if (!empty($this->last_backup_data['synthetic_full'])) {
                foreach ($this->last_backup_data['synthetic_full'] as $segment) {
                    $total_size += isset($segment['size']) ? (int) $segment['size'] : 0;
                }
            }
            foreach ($this->last_backup_data['incremental_backups'] as $inc) {
                $total_size += $inc['size'];
            }
            $info['total_size'] = size_format($total_size);

            // Dernière sauvegarde
            if (!empty($this->last_backup_data['incremental_backups'])) {
                $last = end($this->last_backup_data['incremental_backups']);
                $info['last_backup'] = date('d/m/Y H:i', $last['timestamp']);
            } elseif (!empty($this->last_backup_data['synthetic_full'])) {
                $last = end($this->last_backup_data['synthetic_full']);
                if (isset($last['timestamp'])) {
                    $info['last_backup'] = date('d/m/Y H:i', $last['timestamp']);
                }
            } else {
                $info['last_backup'] = $info['full_backup']['date'];
            }

            // Recommandation pour la prochaine complète
            $max_age_days = isset($settings['max_full_age_days']) ? (int) $settings['max_full_age_days'] : 0;
            if ($max_age_days > 0) {
                $days_until_next = $max_age_days - $info['full_backup']['age_days'];
                if ($days_until_next > 0) {
                    $info['next_full_recommended'] = "Dans $days_until_next jour(s)";
                } else {
                    $info['next_full_recommended'] = sprintf('Maintenant (plus de %d jours)', $max_age_days);
                }
            } else {
                $info['next_full_recommended'] = 'Selon votre stratégie';
            }

            // Espace économisé (estimation)
            $estimated_full_size = $this->last_backup_data['full_backup']['size'] * ($info['incremental_count'] + 1);
            $info['space_saved'] = size_format($estimated_full_size - $total_size);
        }
        
        wp_send_json_success($info);
    }
    
    /**
     * AJAX : Réinitialise l'état incrémental
     */
    public function ajax_reset() {
        if (!\bjlg_can_manage_backups()) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        $this->reset_manifest();
        
        BJLG_History::log('incremental_reset', 'info', 'Manifeste incrémental réinitialisé');
        
        wp_send_json_success(['message' => 'État incrémental réinitialisé. La prochaine sauvegarde sera complète.']);
    }
    
    /**
     * AJAX : Analyse les changements depuis la dernière sauvegarde
     */
    public function ajax_analyze_changes() {
        if (!\bjlg_can_manage_backups()) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        $changes = [
            'files' => [
                'modified' => 0,
                'added' => 0,
                'deleted' => 0,
                'list' => []
            ],
            'database' => [
                'tables_modified' => 0,
                'list' => []
            ],
            'estimated_size' => 0,
            'recommendation' => ''
        ];
        
        // Analyser les fichiers
        $directories = [
            'plugins' => WP_PLUGIN_DIR,
            'themes' => get_theme_root(),
            'uploads' => wp_get_upload_dir()['basedir']
        ];
        
        foreach ($directories as $type => $dir) {
            $scan = $this->get_modified_files($dir);
            $modified = is_array($scan['modified'] ?? null) ? $scan['modified'] : [];
            $deleted = is_array($scan['deleted'] ?? null) ? $scan['deleted'] : [];

            $changes['files']['modified'] += count($modified);
            $changes['files']['deleted'] += count($deleted);

            // Limiter la liste à 20 fichiers
            if (count($changes['files']['list']) < 20) {
                foreach ($modified as $file) {
                    $changes['files']['list'][] = [
                        'path' => str_replace(ABSPATH, '', $file),
                        'type' => $type,
                        'size' => size_format(filesize($file)),
                        'modified' => date('d/m/Y H:i', filemtime($file))
                    ];
                    
                    if (count($changes['files']['list']) >= 20) break;
                }
            }
            
            // Estimer la taille
            foreach ($modified as $file) {
                $changes['estimated_size'] += filesize($file);
            }
        }
        
        // Analyser la base de données
        global $wpdb;
        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
        
        foreach ($tables as $table_array) {
            $table = $table_array[0];
            if ($this->table_has_changed($table)) {
                $changes['database']['tables_modified']++;
                
                // Obtenir la taille de la table
                $size_result = $wpdb->get_row(
                    "SELECT 
                        (data_length + index_length) as size 
                     FROM information_schema.TABLES 
                     WHERE table_schema = '" . DB_NAME . "' 
                     AND table_name = '{$table}'",
                    ARRAY_A
                );
                
                $changes['database']['list'][] = [
                    'table' => $table,
                    'size' => size_format($size_result['size'] ?? 0)
                ];
                
                $changes['estimated_size'] += $size_result['size'] ?? 0;
            }
        }
        
        // Recommandation
        if ($changes['files']['modified'] == 0 && $changes['database']['tables_modified'] == 0) {
            $changes['recommendation'] = "Aucun changement détecté. Sauvegarde non nécessaire.";
        } elseif ($changes['estimated_size'] < 10 * 1024 * 1024) { // Moins de 10MB
            $changes['recommendation'] = "Changements mineurs détectés. Sauvegarde incrémentale recommandée.";
        } elseif ($changes['estimated_size'] < 100 * 1024 * 1024) { // Moins de 100MB
            $changes['recommendation'] = "Changements modérés détectés. Sauvegarde incrémentale appropriée.";
        } else {
            $changes['recommendation'] = "Changements importants détectés. Considérez une sauvegarde complète.";
        }
        
        $changes['estimated_size'] = size_format($changes['estimated_size']);
        
        wp_send_json_success($changes);
    }
    
    /**
     * Obtient la chaîne de restauration complète
     */
    public function get_restore_chain() {
        $chain = [];
        
        // Ajouter la sauvegarde complète
        if ($this->last_backup_data['full_backup']) {
            $chain[] = $this->last_backup_data['full_backup'];
        }

        if (!empty($this->last_backup_data['synthetic_full']) && is_array($this->last_backup_data['synthetic_full'])) {
            foreach ($this->last_backup_data['synthetic_full'] as $segment) {
                if (is_array($segment)) {
                    $chain[] = $segment;
                }
            }
        }

        // Ajouter toutes les sauvegardes incrémentales dans l'ordre
        foreach ($this->last_backup_data['incremental_backups'] as $inc) {
            $chain[] = $inc;
        }

        return $chain;
    }
    
    /**
     * Vérifie l'intégrité de la chaîne de restauration
     */
    public function verify_restore_chain() {
        $chain = $this->get_restore_chain();
        $missing = [];

        foreach ($chain as $backup) {
            $filepath = bjlg_get_backup_directory() . $backup['file'];
            if (!file_exists($filepath)) {
                $missing[] = $backup['file'];
            }
        }

        return [
            'valid' => empty($missing),
            'missing' => $missing,
            'chain_length' => count($chain)
        ];
    }

    public static function bootstrap_realtime_integrations(): void
    {
        if (self::$realtime_hooks_registered) {
            return;
        }

        self::$realtime_hooks_registered = true;

        if (function_exists('add_action')) {
            add_action('bjlg_inotify_event', [self::class, 'handle_inotify_event'], 10, 2);
            add_action('bjlg_application_webhook_event', [self::class, 'handle_application_webhook_event'], 10, 2);
            add_action('bjlg_database_binlog_event', [self::class, 'handle_binlog_event'], 10, 2);
        }
    }

    public static function notify_realtime_event(string $channel, array $payload = [], array $context = []): void
    {
        self::bootstrap_realtime_integrations();
        self::record_realtime_signal($channel, $payload, $context);
    }

    public static function handle_inotify_event($event, $context = []): void
    {
        if (!is_array($event)) {
            return;
        }

        $payload = [
            'action' => isset($event['action']) ? (string) $event['action'] : '',
            'path' => isset($event['path']) ? (string) $event['path'] : '',
            'watch' => isset($event['watch']) ? (string) $event['watch'] : '',
        ];

        self::record_realtime_signal('inotify', $payload, is_array($context) ? $context : []);
    }

    public static function handle_application_webhook_event($event, $context = []): void
    {
        if (!is_array($event)) {
            return;
        }

        $payload = [
            'event' => isset($event['event']) ? (string) $event['event'] : '',
            'source' => isset($event['source']) ? (string) $event['source'] : '',
            'object' => isset($event['object']) ? (string) $event['object'] : '',
        ];

        self::record_realtime_signal('application_webhook', $payload, is_array($context) ? $context : []);
    }

    public static function handle_binlog_event($event, $context = []): void
    {
        if (!is_array($event)) {
            return;
        }

        $payload = [
            'table' => isset($event['table']) ? (string) $event['table'] : '',
            'type' => isset($event['type']) ? (string) $event['type'] : '',
        ];

        self::record_realtime_signal('binlog', $payload, is_array($context) ? $context : []);
    }

    private static function record_realtime_signal(string $channel, array $payload, array $context = []): void
    {
        if (self::should_debounce_payload($channel, $payload)) {
            return;
        }

        $context['source'] = isset($context['source']) ? $context['source'] : $channel;
        BJLG_Backup::record_realtime_change($channel, $payload, $context);
    }

    private static function should_debounce_payload(string $channel, array $payload): bool
    {
        $hash = $channel . ':' . md5(self::hash_payload($payload));
        $now = function_exists('current_time') ? (int) current_time('timestamp') : time();

        foreach (self::$realtime_debounce as $stored_hash => $timestamp) {
            if (($now - $timestamp) > 30) {
                unset(self::$realtime_debounce[$stored_hash]);
            }
        }

        if (isset(self::$realtime_debounce[$hash]) && ($now - self::$realtime_debounce[$hash]) < 2) {
            return true;
        }

        self::$realtime_debounce[$hash] = $now;

        return false;
    }

    private static function hash_payload(array $payload): string
    {
        if (function_exists('wp_json_encode')) {
            $encoded = wp_json_encode($payload);
        } else {
            $encoded = json_encode($payload);
        }

        return is_string($encoded) ? $encoded : '';
    }
}

BJLG_Incremental::bootstrap_realtime_integrations();
