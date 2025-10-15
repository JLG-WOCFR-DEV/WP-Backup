<?php
namespace BJLG;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe statique pour la gestion des logs de débogage.
 */
class BJLG_Debug {

    /**
     * Journal en mémoire utilisé principalement pour les tests automatisés.
     *
     * @var array<int, string>
     */
    public static $logs = [];

    /**
     * Enregistre un message dans le log du plugin si le mode débogage est activé.
     * @param string|array|object $message Le message à enregistrer.
     * @param string $level Niveau de log (info, warning, error, debug).
     */
    public static function log($message, $level = 'info') {
        // Conserver une trace en mémoire pour les environnements de test
        if (is_array($message) || is_object($message)) {
            $formatted_message = print_r($message, true);
        } else {
            $formatted_message = (string) $message;
        }

        self::$logs[] = $formatted_message;

        // Vérifier si le débogage est activé
        if (!defined('BJLG_DEBUG') || BJLG_DEBUG !== true) {
            return;
        }

        $log_file = WP_CONTENT_DIR . '/bjlg-debug.log';
        $timestamp = date('Y-m-d H:i:s');

        // Ajouter le contexte (fichier et ligne d'appel)
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = isset($backtrace[1]) ? $backtrace[1] : $backtrace[0];
        $file = isset($caller['file']) ? basename($caller['file']) : 'unknown';
        $line = isset($caller['line']) ? $caller['line'] : '0';
        $function = isset($caller['function']) ? $caller['function'] : 'unknown';
        
        // Construire l'entrée de log
        $level_str = strtoupper($level);
        $entry = "[$timestamp] [$level_str] [$file:$line in $function()] $formatted_message\n";

        // Écrire dans le fichier (avec verrou pour éviter les conflits)
        $result = @file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);

        // Si l'écriture échoue et qu'on est en mode debug WordPress, utiliser error_log
        if ($result === false && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("BJLG Debug: $formatted_message");
        }
    }
    
    /**
     * Log d'erreur
     */
    public static function error($message) {
        self::log($message, 'error');
    }
    
    /**
     * Log d'avertissement
     */
    public static function warning($message) {
        self::log($message, 'warning');
    }
    
    /**
     * Log d'information
     */
    public static function info($message) {
        self::log($message, 'info');
    }
    
    /**
     * Log de débogage détaillé
     */
    public static function debug($message) {
        self::log($message, 'debug');
    }
    
    /**
     * Récupère le contenu du log du plugin (bjlg-debug.log).
     * @param int $lines Nombre de lignes à récupérer (0 = tout).
     * @return string
     */
    public static function get_plugin_log_content($lines = 500) {
        $log_file = WP_CONTENT_DIR . '/bjlg-debug.log';
        
        if (!file_exists($log_file)) {
            return 'Le fichier de log du plugin est vide ou n\'existe pas. Pour l\'activer, ajoutez define(\'BJLG_DEBUG\', true); à votre wp-config.php.';
        }
        
        if ($lines === 0) {
            // Lire tout le fichier
            $content = @file_get_contents($log_file);
            return $content ?: 'Impossible de lire le fichier de log.';
        } else {
            // Lire les N dernières lignes
            return self::read_tail($log_file, $lines);
        }
    }

    /**
     * Récupère le contenu du log d'erreurs de WordPress (debug.log).
     * @param int $lines Nombre de lignes à récupérer (0 = tout).
     * @return string
     */
    public static function get_wp_error_log_content($lines = 500) {
        if (!defined('WP_DEBUG_LOG') || WP_DEBUG_LOG !== true) {
            return 'Le log d\'erreurs de WordPress est désactivé. Pour l\'activer, ajoutez define(\'WP_DEBUG_LOG\', true); à votre wp-config.php.';
        }

        $log_file = WP_CONTENT_DIR . '/debug.log';
        
        if (!file_exists($log_file)) {
            return 'Le fichier de log d\'erreurs WordPress est vide. Aucune erreur PHP n\'a été enregistrée récemment.';
        }
        
        if ($lines === 0) {
            $content = @file_get_contents($log_file);
            return $content ?: 'Impossible de lire le fichier de log.';
        } else {
            return self::read_tail($log_file, $lines);
        }
    }

    /**
     * Lit les N dernières lignes d'un fichier de manière efficace sans charger tout le fichier en mémoire.
     * @param string $filepath Chemin du fichier.
     * @param int $lines Nombre de lignes à lire depuis la fin.
     * @return string
     */
    private static function read_tail($filepath, $lines) {
        if (!is_file($filepath) || !is_readable($filepath)) {
            return 'Fichier non accessible.';
        }
        
        $handle = @fopen($filepath, "r");
        if (!$handle) {
            return 'Impossible d\'ouvrir le fichier.';
        }

        $linecount = 0;
        $pos = -2;
        $output = '';
        $buffer = '';

        // On cherche le début des N dernières lignes en partant de la fin du fichier
        fseek($handle, 0, SEEK_END);
        $file_size = ftell($handle);
        
        // Si le fichier est petit, on le lit entièrement
        if ($file_size < 10000) {
            rewind($handle);
            $content = fread($handle, $file_size);
            fclose($handle);
            
            $all_lines = explode("\n", $content);
            $selected_lines = array_slice($all_lines, -$lines);
            return implode("\n", $selected_lines);
        }
        
        // Pour les gros fichiers, on lit par blocs depuis la fin
        while ($linecount < $lines && -$pos < $file_size) {
            if (fseek($handle, $pos, SEEK_END) === -1) {
                break;
            }
            
            $char = fgetc($handle);
            if ($char === "\n") {
                $linecount++;
            }
            $buffer = $char . $buffer;
            $pos--;
        }
        
        fclose($handle);
        
        // Nettoyer le buffer (enlever la première ligne partielle si nécessaire)
        $lines_array = explode("\n", $buffer);
        if (count($lines_array) > $lines) {
            array_shift($lines_array);
        }
        
        return implode("\n", $lines_array);
    }
    
    /**
     * Vide le fichier de log du plugin
     */
    public static function clear_plugin_log() {
        $log_file = WP_CONTENT_DIR . '/bjlg-debug.log';
        
        if (file_exists($log_file)) {
            // Sauvegarder une copie avant de vider
            $backup_file = $log_file . '.old';
            @copy($log_file, $backup_file);
            
            // Vider le fichier
            $result = @file_put_contents($log_file, '');
            
            if ($result !== false) {
                self::log('Log vidé manuellement');
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Obtient la taille du fichier de log
     */
    public static function get_log_size() {
        $log_file = WP_CONTENT_DIR . '/bjlg-debug.log';
        
        if (file_exists($log_file)) {
            return filesize($log_file);
        }
        
        return 0;
    }
    
    /**
     * Recherche dans le log
     */
    public static function search_log($search_term, $limit = 100) {
        $log_file = WP_CONTENT_DIR . '/bjlg-debug.log';
        
        if (!file_exists($log_file)) {
            return [];
        }
        
        $matches = [];
        $handle = @fopen($log_file, 'r');
        
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (stripos($line, $search_term) !== false) {
                    $matches[] = $line;
                    if (count($matches) >= $limit) {
                        break;
                    }
                }
            }
            fclose($handle);
        }
        
        return $matches;
    }
    
    /**
     * Analyse le log pour obtenir des statistiques
     */
    public static function analyze_log() {
        $log_file = WP_CONTENT_DIR . '/bjlg-debug.log';
        
        if (!file_exists($log_file)) {
            return [
                'total_lines' => 0,
                'errors' => 0,
                'warnings' => 0,
                'info' => 0,
                'debug' => 0,
                'size' => 0
            ];
        }
        
        $stats = [
            'total_lines' => 0,
            'errors' => 0,
            'warnings' => 0,
            'info' => 0,
            'debug' => 0,
            'size' => filesize($log_file)
        ];
        
        $handle = @fopen($log_file, 'r');
        
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $stats['total_lines']++;
                
                if (strpos($line, '[ERROR]') !== false) {
                    $stats['errors']++;
                } elseif (strpos($line, '[WARNING]') !== false) {
                    $stats['warnings']++;
                } elseif (strpos($line, '[INFO]') !== false) {
                    $stats['info']++;
                } elseif (strpos($line, '[DEBUG]') !== false) {
                    $stats['debug']++;
                }
            }
            fclose($handle);
        }
        
        return $stats;
    }
    
    /**
     * Log une exception avec trace complète
     */
    public static function log_exception(Exception $e) {
        $message = sprintf(
            "Exception: %s\nFile: %s:%d\nTrace:\n%s",
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        
        self::error($message);
    }
    
    /**
     * Benchmark - début
     */
    public static function benchmark_start($label) {
        if (!defined('BJLG_DEBUG') || BJLG_DEBUG !== true) {
            return;
        }
        
        $GLOBALS['bjlg_benchmarks'][$label] = microtime(true);
        self::debug("Benchmark started: $label");
    }
    
    /**
     * Benchmark - fin
     */
    public static function benchmark_end($label) {
        if (!defined('BJLG_DEBUG') || BJLG_DEBUG !== true) {
            return;
        }
        
        if (!isset($GLOBALS['bjlg_benchmarks'][$label])) {
            self::warning("Benchmark end called without start: $label");
            return;
        }
        
        $duration = microtime(true) - $GLOBALS['bjlg_benchmarks'][$label];
        $memory = memory_get_peak_usage(true);
        
        self::debug(sprintf(
            "Benchmark completed: %s | Duration: %.4f seconds | Peak memory: %s",
            $label,
            $duration,
            size_format($memory)
        ));
        
        unset($GLOBALS['bjlg_benchmarks'][$label]);
    }
}

if (!class_exists('BJLG_Debug', false)) {
    class_alias(__NAMESPACE__ . '\\BJLG_Debug', 'BJLG_Debug');
}