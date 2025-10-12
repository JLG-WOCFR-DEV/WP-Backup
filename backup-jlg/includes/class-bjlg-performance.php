<?php
namespace BJLG;

/**
 * Optimisation des performances avec multi-threading et traitement parallèle
 * Fichier : includes/class-bjlg-performance.php
 */

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

if (!defined('ABSPATH')) {
    exit;
}

class BJLG_Performance {

    private $max_workers = 4;
    private $chunk_size = 50; // MB
    private $use_background_processing = true;
    private $compression_level = 6; // 1-9, 6 est un bon compromis vitesse/taille
    private $parallel_capable = null;
    private $disabled_functions = [];
    
    public function __construct() {
        $this->load_settings();
        $this->detect_system_capabilities();
        
        // Hooks pour optimiser les processus
        add_filter('bjlg_backup_process', [$this, 'optimize_backup_process'], 10, 2);
        add_filter('bjlg_restore_process', [$this, 'optimize_restore_process'], 10, 2);
        
        // AJAX pour les benchmarks
        add_action('wp_ajax_bjlg_run_benchmark', [$this, 'ajax_run_benchmark']);
        add_action('wp_ajax_bjlg_get_performance_stats', [$this, 'ajax_get_stats']);
    }
    
    /**
     * Charge les paramètres
     */
    private function load_settings() {
        $settings = get_option('bjlg_performance_settings', []);
        $this->use_background_processing = isset($settings['multi_threading']) ? $settings['multi_threading'] : false;
        $this->max_workers = isset($settings['max_workers']) ? $settings['max_workers'] : 2;
        $this->chunk_size = isset($settings['chunk_size']) ? $settings['chunk_size'] : 50;
        $this->compression_level = isset($settings['compression_level']) ? $settings['compression_level'] : 6;
    }
    
    /**
     * Détecte les capacités du système
     */
    private function detect_system_capabilities() {
        // Détection du nombre de cœurs CPU
        $disable_functions = ini_get('disable_functions');
        $disabled_functions = [];

        if (is_string($disable_functions) && $disable_functions !== '') {
            $disabled_functions = array_filter(array_map('trim', explode(',', $disable_functions)));
        }

        $this->disabled_functions = $disabled_functions;

        $can_use_shell_exec = function_exists('shell_exec') && !in_array('shell_exec', $disabled_functions, true);

        if ($can_use_shell_exec) {
            if (PHP_OS_FAMILY === 'Windows') {
                $cores = shell_exec('wmic cpu get NumberOfCores');
                // CORRECTION: Vérifier que $cores n'est pas null avant de l'utiliser
                if (is_string($cores) && !empty($cores)) {
                    preg_match('/(\d+)/', $cores, $matches);
                    $this->max_workers = isset($matches[1]) ? min(intval($matches[1]), $this->max_workers) : 2;
                }
            } else {
                $cores = shell_exec('nproc');
                $this->max_workers = $cores ? min(intval($cores), $this->max_workers) : 2;
            }
        }
        
        // Ajuster selon la mémoire disponible
        $memory_limit = $this->get_memory_limit_bytes();
        if ($memory_limit < 134217728) { // Moins de 128MB
            $this->max_workers = min($this->max_workers, 2);
            $this->chunk_size = 25;
        } elseif ($memory_limit < 268435456) { // Moins de 256MB
            $this->max_workers = min($this->max_workers, 3);
            $this->chunk_size = 40;
        }
        
        $this->parallel_capable = null; // Recalculer sur la prochaine demande

        BJLG_Debug::log("Capacités système détectées : {$this->max_workers} workers max, chunks de {$this->chunk_size}MB");
    }
    
    /**
     * Optimise le processus de sauvegarde
     */
    public function optimize_backup_process($task_data, $task_id) {
        if (!$this->use_background_processing) {
            return $task_data; // Pas d'optimisation si désactivé
        }
        
        // Si on peut utiliser le multi-threading
        if ($this->can_use_parallel_processing()) {
            $task_data['use_parallel'] = true;
            $task_data['max_workers'] = $this->max_workers;
            $task_data['chunk_size'] = $this->chunk_size * 1024 * 1024;
        }
        
        // Optimisations générales
        $task_data['compression_level'] = $this->compression_level;
        $task_data['buffer_size'] = 1024 * 512; // 512KB buffer
        
        return $task_data;
    }
    
    /**
     * Crée une sauvegarde optimisée avec traitement parallèle
     */
    public function create_optimized_backup($components, $task_id, array $context = []) {
        $start_time = microtime(true);
        $memory_start = memory_get_usage(true);

        BJLG_Debug::log("Démarrage de la sauvegarde optimisée");

        try {
            // Préparer les tâches
            $tasks = $this->prepare_backup_tasks($components);
            $temp_files = [];

            $context = array_merge([
                'components' => $components,
                'type' => $context['type'] ?? 'full',
                'target_filepath' => $context['target_filepath'] ?? null,
                'parallel_used' => $this->can_use_parallel_processing(),
                'compression_level' => $this->compression_level,
            ], $context);

            // Exécuter selon les capacités
            if ($context['parallel_used'] && count($tasks) > 1) {
                $temp_files = $this->execute_parallel_tasks($tasks, $task_id);
            } else {
                $temp_files = $this->execute_sequential_optimized($tasks, $task_id);
            }

            // Combiner les résultats
            $combined = $this->combine_temp_files(
                $temp_files,
                $task_id,
                $context['target_filepath'] ?? null,
                $context
            );

            $final_backup = $combined['path'];
            $entries_added = $combined['entries'];

            $elapsed_time = microtime(true) - $start_time;
            $memory_used = memory_get_peak_usage(true) - $memory_start;

            // Enregistrer les stats de performance
            $this->record_performance_stats([
                'duration' => $elapsed_time,
                'memory_used' => $memory_used,
                'files_processed' => $entries_added,
                'parallel_used' => (bool) $context['parallel_used'],
                'workers_used' => $this->max_workers
            ]);

            BJLG_Debug::log(sprintf(
                "Sauvegarde optimisée terminée en %.2fs, mémoire utilisée: %s",
                $elapsed_time,
                size_format($memory_used)
            ));

            return [
                'path' => $final_backup,
                'entries' => $entries_added,
                'meta' => [
                    'duration' => $elapsed_time,
                    'memory_used' => $memory_used,
                    'parallel_used' => (bool) $context['parallel_used'],
                ],
            ];

        } catch (Exception $e) {
            BJLG_Debug::log("Erreur dans la sauvegarde optimisée : " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Prépare les tâches de sauvegarde
     */
    private function prepare_backup_tasks($components) {
        $tasks = [];
        
        // Base de données
        if (in_array('db', $components)) {
            $tasks[] = [
                'type' => 'database',
                'priority' => 1,
                'estimated_size' => $this->estimate_database_size(),
                'handler' => 'backup_database_optimized'
            ];
        }
        
        // Fichiers
        $directories = [];
        if (in_array('plugins', $components)) {
            $directories['plugins'] = WP_PLUGIN_DIR;
        }
        if (in_array('themes', $components)) {
            $directories['themes'] = get_theme_root();
        }
        if (in_array('uploads', $components)) {
            $upload_dir = wp_get_upload_dir();
            $directories['uploads'] = $upload_dir['basedir'];
        }
        
        // Créer les tâches de fichiers
        foreach ($directories as $type => $dir) {
            $files = $this->scan_directory_smart($dir);
            $chunks = $this->chunk_files_by_size($files, $this->chunk_size * 1024 * 1024);
            
            foreach ($chunks as $index => $chunk) {
                $tasks[] = [
                    'type' => 'files',
                    'subtype' => $type,
                    'chunk_id' => $index,
                    'files' => $chunk,
                    'priority' => 2,
                    'estimated_size' => array_sum(array_column($chunk, 'size')),
                    'handler' => 'backup_files_chunk'
                ];
            }
        }
        
        // Équilibrer la charge
        return $this->balance_workload($tasks);
    }
    
    /**
     * Scan intelligent du répertoire avec cache
     */
    private function scan_directory_smart($directory) {
        $cache_key = 'bjlg_dir_scan_' . md5($directory);
        $cached = get_transient($cache_key);
        
        if ($cached && isset($cached['timestamp']) && $cached['timestamp'] > (time() - 300)) {
            BJLG_Debug::log("Utilisation du cache pour le scan de : " . basename($directory));
            return $cached['files'];
        }
        
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && !$this->should_exclude($file->getPathname())) {
                $files[] = [
                    'path' => $file->getRealPath(),
                    'size' => $file->getSize(),
                    'mtime' => $file->getMTime()
                ];
            }
        }
        
        // Mettre en cache
        set_transient($cache_key, ['files' => $files, 'timestamp' => time()], 300);
        
        return $files;
    }
    
    /**
     * Vérifie si un fichier doit être exclu
     */
    private function should_exclude($filepath) {
        $exclude_patterns = [
            '/node_modules/',
            '/.git/',
            '/cache/',
            '/.tmp',
            '/backup-',
            '.log'
        ];
        
        foreach ($exclude_patterns as $pattern) {
            if (strpos($filepath, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Divise les fichiers en chunks
     */
    private function chunk_files_by_size($files, $max_size) {
        $chunks = [];
        $current_chunk = [];
        $current_size = 0;
        
        // Trier par taille décroissante pour optimiser
        usort($files, function($a, $b) {
            return $b['size'] - $a['size'];
        });
        
        foreach ($files as $file) {
            if ($current_size + $file['size'] > $max_size && !empty($current_chunk)) {
                $chunks[] = $current_chunk;
                $current_chunk = [];
                $current_size = 0;
            }
            
            $current_chunk[] = $file;
            $current_size += $file['size'];
        }
        
        if (!empty($current_chunk)) {
            $chunks[] = $current_chunk;
        }
        
        return $chunks;
    }
    
    /**
     * Équilibre la charge de travail
     */
    private function balance_workload($tasks) {
        // Trier par taille estimée (décroissant)
        usort($tasks, function($a, $b) {
            $size_a = isset($a['estimated_size']) ? $a['estimated_size'] : 0;
            $size_b = isset($b['estimated_size']) ? $b['estimated_size'] : 0;
            return $size_b - $size_a;
        });
        
        // Distribuer entre les workers
        $workers = array_fill(0, $this->max_workers, []);
        $worker_loads = array_fill(0, $this->max_workers, 0);
        
        foreach ($tasks as $task) {
            $min_load_worker = array_search(min($worker_loads), $worker_loads);
            $task['worker_id'] = $min_load_worker;
            $workers[$min_load_worker][] = $task;
            $worker_loads[$min_load_worker] += isset($task['estimated_size']) ? $task['estimated_size'] : 0;
        }
        
        // Aplatir
        $balanced = [];
        foreach ($workers as $worker_tasks) {
            $balanced = array_merge($balanced, $worker_tasks);
        }
        
        return $balanced;
    }
    
    /**
     * Exécution séquentielle optimisée
     */
    private function execute_sequential_optimized($tasks, $task_id) {
        $temp_files = [];
        $total_tasks = count($tasks);
        
        foreach ($tasks as $index => $task) {
            $progress = round((($index + 1) / $total_tasks) * 100);
            
            set_transient($task_id, [
                'progress' => $progress,
                'status' => 'running',
                'status_text' => "Traitement de {$task['type']} (" . (isset($task['subtype']) ? $task['subtype'] : '') . ")..."
            ], BJLG_Backup::get_task_ttl());
            
            try {
                $result = $this->execute_single_task($task);
                if ($result) {
                    $temp_files[] = $result;
                }
            } catch (Exception $e) {
                BJLG_Debug::log("Erreur dans la tâche : " . $e->getMessage());
            }
            
            // Libérer la mémoire
            if ($index % 10 == 0) {
                gc_collect_cycles();
            }
        }
        
        return $temp_files;
    }

    /**
     * Exécution "parallèle" des tâches.
     *
     * Dans certains environnements, les fonctions nécessaires au multi-processing
     * peuvent être désactivées. Afin d'éviter une erreur fatale (méthode
     * inexistante) lors de l'appel depuis create_optimized_backup(), nous
     * fournissons une implémentation qui regroupe les tâches par worker et les
     * traite séquentiellement lorsque le véritable parallélisme n'est pas
     * disponible.
     */
    private function execute_parallel_tasks($tasks, $task_id) {
        if (!$this->can_use_parallel_processing()) {
            BJLG_Debug::log('Traitement parallèle indisponible, repli en mode séquentiel.');

            return $this->execute_sequential_optimized($tasks, $task_id);
        }

        $grouped = [];
        foreach ($tasks as $task) {
            $worker_id = isset($task['worker_id']) ? (int) $task['worker_id'] : 0;
            if (!isset($grouped[$worker_id])) {
                $grouped[$worker_id] = [];
            }

            $grouped[$worker_id][] = $task;
        }

        $temp_files = [];
        $worker_count = count($grouped);
        $current_worker = 0;

        foreach ($grouped as $worker_id => $worker_tasks) {
            $current_worker++;
            BJLG_Debug::log(sprintf(
                'Traitement optimisé - worker %d/%d (%d tâches).',
                $worker_id,
                $worker_count,
                count($worker_tasks)
            ));

            try {
                $worker_results = $this->execute_sequential_optimized($worker_tasks, $task_id);
                if (!empty($worker_results)) {
                    $temp_files = array_merge($temp_files, $worker_results);
                }
            } catch (Exception $e) {
                BJLG_Debug::log('Erreur sur worker parallèle : ' . $e->getMessage());
            }
        }

        return $temp_files;
    }

    /**
     * Exécute une tâche unique
     */
    private function execute_single_task($task) {
        switch ($task['handler']) {
            case 'backup_database_optimized':
                return $this->backup_database_optimized($task);
            case 'backup_files_chunk':
                return $this->backup_files_chunk($task);
            default:
                throw new Exception("Handler inconnu : " . $task['handler']);
        }
    }
    
    /**
     * Sauvegarde optimisée de la base de données
     */
    private function backup_database_optimized($task) {
        global $wpdb;
        
        $temp_file = BJLG_BACKUP_DIR . 'db_' . uniqid() . '.sql';
        $handle = fopen($temp_file, 'w');
        
        if (!$handle) {
            throw new Exception("Impossible de créer le fichier SQL");
        }
        
        // Header
        fwrite($handle, "-- Backup JLG Optimized Database Dump\n");
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
            
            // Données par lots
            $row_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            
            if ($row_count > 0) {
                $batch_size = 1000;
                
                for ($offset = 0; $offset < $row_count; $offset += $batch_size) {
                    $rows = $wpdb->get_results(
                        "SELECT * FROM `{$table}` LIMIT {$offset}, {$batch_size}",
                        ARRAY_A
                    );
                    
                    if ($rows) {
                        $this->write_insert_batch($handle, $table, $rows);
                    }
                    
                    unset($rows);
                    
                    if ($offset % 10000 == 0 && $offset > 0) {
                        gc_collect_cycles();
                    }
                }
            }
        }
        
        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
        
        // Compresser si possible
        if ($this->compression_level > 0) {
            $compressed = $this->compress_file($temp_file);
            if ($compressed !== $temp_file) {
                @unlink($temp_file);
                return $compressed;
            }
        }
        
        return $temp_file;
    }
    
    /**
     * Écrit un batch d'INSERT
     */
    private function write_insert_batch($handle, $table, $rows) {
        if (empty($rows)) return;
        
        $columns = array_keys($rows[0]);
        $columns_str = '`' . implode('`, `', $columns) . '`';
        
        $values = [];
        foreach ($rows as $row) {
            $row_values = [];
            foreach ($row as $value) {
                $row_values[] = $this->format_sql_value($value);
            }
            $values[] = '(' . implode(', ', $row_values) . ')';
        }

        $insert = "INSERT INTO `{$table}` ({$columns_str}) VALUES\n";
        $insert .= implode(",\n", $values) . ";\n\n";

        fwrite($handle, $insert);
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
     * Sauvegarde un chunk de fichiers
     */
    private function backup_files_chunk($task) {
        $temp_file = BJLG_BACKUP_DIR . 'files_' . $task['subtype'] . '_' . $task['chunk_id'] . '_' . uniqid() . '.zip';
        
        $zip = new ZipArchive();
        if ($zip->open($temp_file, ZipArchive::CREATE) !== TRUE) {
            throw new Exception("Impossible de créer l'archive");
        }
        
        foreach ($task['files'] as $file_info) {
            if (file_exists($file_info['path'])) {
                $relative_path = $this->get_relative_path($file_info['path']);
                $zip->addFile($file_info['path'], $relative_path);
                
                // Définir la compression
                if ($this->compression_level > 0) {
                    $zip->setCompressionName($relative_path, ZipArchive::CM_DEFLATE);
                    $zip->setCompressionIndex($zip->count() - 1, ZipArchive::CM_DEFLATE);
                }
            }
        }
        
        $zip->close();
        
        return $temp_file;
    }
    
    /**
     * Obtient le chemin relatif
     */
    private function get_relative_path($filepath) {
        // Essayer différentes bases
        $bases = [
            ABSPATH,
            WP_CONTENT_DIR,
            WP_PLUGIN_DIR,
            get_theme_root()
        ];
        
        foreach ($bases as $base) {
            if (strpos($filepath, $base) === 0) {
                return 'wp-content' . substr($filepath, strlen($base));
            }
        }
        
        return basename($filepath);
    }
    
    /**
     * Compresse un fichier
     */
    private function compress_file($filepath) {
        if (!function_exists('gzopen')) {
            return $filepath;
        }
        
        $compressed_file = $filepath . '.gz';
        
        $source = fopen($filepath, 'rb');
        $dest = gzopen($compressed_file, 'wb' . $this->compression_level);
        
        if ($source && $dest) {
            while (!feof($source)) {
                gzwrite($dest, fread($source, 1024 * 512));
            }
            
            fclose($source);
            gzclose($dest);
            
            return $compressed_file;
        }
        
        return $filepath;
    }
    
    /**
     * Combine les fichiers temporaires
     */
    private function combine_temp_files($temp_files, $task_id, $target_filepath = null, array $context = []) {
        $filename = $context['filename'] ?? ('backup-' . date('Y-m-d-H-i-s') . '.zip');
        $final_backup = $target_filepath ?: BJLG_BACKUP_DIR . $filename;

        if ($target_filepath && file_exists($target_filepath)) {
            @unlink($target_filepath);
        }

        $zip = new ZipArchive();
        if ($zip->open($final_backup, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Impossible de créer l'archive finale");
        }

        $entries_added = 0;

        foreach ($temp_files as $temp_file) {
            if (!file_exists($temp_file)) {
                continue;
            }

            $archive_name = $this->get_archive_name($temp_file);

            if ($this->is_gzip_file($temp_file)) {
                $decompressed = $this->decompress_gzip_to_temp($temp_file);
                if ($decompressed) {
                    if ($zip->addFile($decompressed, $archive_name)) {
                        $entries_added++;
                    }
                    @unlink($decompressed);
                }
                @unlink($temp_file);
                continue;
            }

            if ($this->is_zip_file($temp_file)) {
                $entries_added += $this->merge_zip_into_archive($zip, $temp_file);
                @unlink($temp_file);
                continue;
            }

            if ($zip->addFile($temp_file, $archive_name)) {
                $entries_added++;
            }

            @unlink($temp_file);
        }

        $manifest = $this->build_manifest_payload($context, $entries_added, $task_id);
        $manifest_json = function_exists('wp_json_encode')
            ? wp_json_encode($manifest, JSON_PRETTY_PRINT)
            : json_encode($manifest, JSON_PRETTY_PRINT);

        $zip->addFromString('backup-manifest.json', $manifest_json);
        $zip->addFromString('manifest.json', $manifest_json);

        $zip->close();

        return [
            'path' => $final_backup,
            'entries' => $entries_added,
        ];
    }

    /**
     * Détermine le nom dans l'archive
     */
    private function get_archive_name($temp_file) {
        $basename = basename($temp_file);

        if (strpos($basename, 'db_') === 0) {
            return 'database.sql';
        } elseif (strpos($basename, 'files_') === 0) {
            if (preg_match('/files_(\w+)_/', $basename, $matches)) {
                return $matches[1] . '.zip';
            }
        }

        return $basename;
    }

    private function is_gzip_file($filepath) {
        return substr($filepath, -3) === '.gz';
    }

    private function is_zip_file($filepath) {
        return substr($filepath, -4) === '.zip';
    }

    private function decompress_gzip_to_temp($filepath) {
        if (!function_exists('gzopen')) {
            return null;
        }

        $source = gzopen($filepath, 'rb');
        if (!$source) {
            return null;
        }

        $temp = function_exists('wp_tempnam') ? wp_tempnam(basename($filepath, '.gz')) : tempnam(sys_get_temp_dir(), 'bjlg-unzip-');
        if (!$temp) {
            gzclose($source);

            return null;
        }

        $destination = fopen($temp, 'wb');
        if (!$destination) {
            gzclose($source);
            @unlink($temp);

            return null;
        }

        while (!gzeof($source)) {
            fwrite($destination, gzread($source, 1024 * 512));
        }

        gzclose($source);
        fclose($destination);

        return $temp;
    }

    private function merge_zip_into_archive(ZipArchive $destination, $zip_path) {
        $source = new ZipArchive();
        $entries_added = 0;

        if ($source->open($zip_path) !== true) {
            return $entries_added;
        }

        for ($i = 0; $i < $source->numFiles; $i++) {
            $entry_name = $source->getNameIndex($i);
            if ($entry_name === false) {
                continue;
            }

            if (substr($entry_name, -1) === '/') {
                $destination->addEmptyDir($entry_name);
                continue;
            }

            $stream = $source->getStream($entry_name);
            if (!$stream) {
                continue;
            }

            $temp = function_exists('wp_tempnam') ? wp_tempnam(basename($entry_name)) : tempnam(sys_get_temp_dir(), 'bjlg-zip-');
            if (!$temp) {
                fclose($stream);
                continue;
            }

            $destination_handle = fopen($temp, 'wb');
            if (!$destination_handle) {
                fclose($stream);
                @unlink($temp);
                continue;
            }

            while (!feof($stream)) {
                fwrite($destination_handle, fread($stream, 1024 * 512));
            }

            fclose($stream);
            fclose($destination_handle);

            if ($destination->addFile($temp, $entry_name)) {
                $entries_added++;
            }

            @unlink($temp);
        }

        $source->close();

        return $entries_added;
    }

    private function build_manifest_payload(array $context, $entries_added, $task_id) {
        global $wp_version;

        $components = array_values(array_unique(array_map('strval', (array) ($context['components'] ?? []))));

        return [
            'version' => defined('BJLG_VERSION') ? BJLG_VERSION : 'dev',
            'wp_version' => isset($wp_version) ? $wp_version : get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'type' => $context['type'] ?? 'full',
            'contains' => $components,
            'created_at' => function_exists('current_time') ? current_time('c') : date('c'),
            'site_url' => function_exists('get_site_url') ? get_site_url() : '',
            'site_name' => function_exists('get_bloginfo') ? get_bloginfo('name') : '',
            'db_prefix' => isset($GLOBALS['wpdb']->prefix) ? $GLOBALS['wpdb']->prefix : '',
            'theme_active' => function_exists('get_option') ? get_option('stylesheet') : '',
            'plugins_active' => function_exists('get_option') ? get_option('active_plugins') : [],
            'multisite' => function_exists('is_multisite') ? is_multisite() : false,
            'file_count' => $entries_added,
            'checksum' => '',
            'checksum_algorithm' => '',
            'optimized' => true,
            'parallel_used' => !empty($context['parallel_used']),
            'task_id' => $task_id,
            'compression_level' => $context['compression_level'] ?? $this->compression_level,
        ];
    }
    
    /**
     * Vérifie si on peut utiliser le traitement parallèle
     */
    private function can_use_parallel_processing() {
        return $this->evaluate_parallel_capability();
    }

    private function evaluate_parallel_capability() {
        if ($this->parallel_capable !== null) {
            return (bool) $this->parallel_capable;
        }

        if (!$this->use_background_processing) {
            $this->parallel_capable = false;

            return false;
        }

        $memory_limit = $this->get_memory_limit_bytes();
        if ($memory_limit < 134217728) { // Moins de 128 MB
            $this->parallel_capable = false;

            return false;
        }

        $pcntl_available = function_exists('pcntl_fork') && !in_array('pcntl_fork', $this->disabled_functions, true);
        $posix_available = function_exists('posix_kill') && !in_array('posix_kill', $this->disabled_functions, true);
        $proc_open_available = function_exists('proc_open') && !in_array('proc_open', $this->disabled_functions, true);
        $proc_get_status_available = function_exists('proc_get_status') && !in_array('proc_get_status', $this->disabled_functions, true);

        $can_spawn_process = ($pcntl_available && $posix_available) || ($proc_open_available && $proc_get_status_available);
        $has_workers = $this->max_workers > 1;

        $raw_capability = $can_spawn_process && $has_workers;

        $settings = get_option('bjlg_performance_settings', []);
        $this->parallel_capable = (bool) apply_filters(
            'bjlg_can_use_parallel_processing',
            $raw_capability,
            $settings,
            $this->max_workers,
            $this->chunk_size
        );

        return (bool) $this->parallel_capable;
    }
    
    /**
     * Obtient la limite mémoire en bytes
     */
    private function get_memory_limit_bytes() {
        $memory_limit = ini_get('memory_limit');
        
        if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
            $value = $matches[1];
            switch (strtoupper($matches[2])) {
                case 'G':
                    $value *= 1024;
                case 'M':
                    $value *= 1024;
                case 'K':
                    $value *= 1024;
            }
            return $value;
        }
        
        return 134217728; // 128MB par défaut
    }
    
    /**
     * Estime la taille de la base de données
     */
    private function estimate_database_size() {
        global $wpdb;
        
        $size = $wpdb->get_var("
            SELECT SUM(data_length + index_length) 
            FROM information_schema.TABLES 
            WHERE table_schema = '" . DB_NAME . "'
        ");
        
        return $size ?: 0;
    }
    
    /**
     * Enregistre les statistiques de performance
     */
    private function record_performance_stats($stats) {
        $all_stats = get_option('bjlg_performance_stats', []);
        
        $all_stats[] = array_merge($stats, [
            'timestamp' => time()
        ]);
        
        // Garder seulement les 100 dernières
        if (count($all_stats) > 100) {
            $all_stats = array_slice($all_stats, -100);
        }
        
        update_option('bjlg_performance_stats', $all_stats);
    }
    
    /**
     * AJAX: Lance un benchmark
     */
    public function ajax_run_benchmark() {
        if (!\bjlg_can_manage_settings()) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }
        check_ajax_referer('bjlg_nonce', 'nonce');
        
        $results = $this->run_benchmark();
        
        wp_send_json_success($results);
    }
    
    /**
     * Lance un benchmark de performance
     */
    public function run_benchmark() {
        $results = [];
        
        // Test 1: Vitesse d'écriture
        $start = microtime(true);
        $test_file = BJLG_BACKUP_DIR . 'benchmark_' . uniqid() . '.tmp';
        $data = str_repeat('A', 1024 * 1024); // 1MB
        
        for ($i = 0; $i < 10; $i++) {
            file_put_contents($test_file, $data, FILE_APPEND);
        }
        
        $write_time = microtime(true) - $start;
        $write_speed = (10 / $write_time); // MB/s
        
        @unlink($test_file);
        
        $results['write_speed'] = round($write_speed, 2);
        
        // Test 2: Compression
        $start = microtime(true);
        $compressed = gzcompress($data, $this->compression_level);
        $compress_time = microtime(true) - $start;
        $compression_ratio = (1 - (strlen($compressed) / strlen($data))) * 100;
        
        $results['compression_ratio'] = round($compression_ratio, 2);
        $results['compression_speed'] = round(1 / $compress_time, 2);
        
        // Test 3: Mémoire disponible
        $results['memory_available'] = size_format($this->get_memory_limit_bytes());
        $results['memory_used'] = size_format(memory_get_usage(true));
        
        return $results;
    }
    
    /**
     * AJAX: Obtient les statistiques
     */
    public function ajax_get_stats() {
        if (!\bjlg_can_manage_settings()) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }
        
        $stats = get_option('bjlg_performance_stats', []);
        
        // Calculer les moyennes
        $avg_duration = 0;
        $avg_memory = 0;
        
        if (!empty($stats)) {
            $durations = array_column($stats, 'duration');
            $memories = array_column($stats, 'memory_used');
            
            $avg_duration = array_sum($durations) / count($durations);
            $avg_memory = array_sum($memories) / count($memories);
        }
        
        wp_send_json_success([
            'total_backups' => count($stats),
            'average_duration' => round($avg_duration, 2),
            'average_memory' => size_format($avg_memory),
            'recent_stats' => array_slice($stats, -10)
        ]);
    }
}