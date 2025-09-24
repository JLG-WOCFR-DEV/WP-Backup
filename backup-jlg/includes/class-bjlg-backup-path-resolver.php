<?php
namespace BJLG;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fournit des méthodes utilitaires pour résoudre les chemins des sauvegardes.
 */
class BJLG_Backup_Path_Resolver {

    /**
     * Résout et valide le chemin d'une sauvegarde à partir d'un identifiant brut.
     *
     * @param mixed $raw_id
     * @return string|WP_Error
     */
    public static function resolve($raw_id) {
        $sanitized_id = sanitize_file_name(basename((string) $raw_id));

        if ($sanitized_id === '') {
            return new WP_Error(
                'invalid_backup_id',
                'Invalid backup ID',
                ['status' => 400]
            );
        }

        $canonical_backup_dir = realpath(BJLG_BACKUP_DIR);

        if ($canonical_backup_dir === false) {
            return new WP_Error(
                'invalid_backup_id',
                'Invalid backup ID',
                ['status' => 400]
            );
        }

        $canonical_backup_dir = rtrim($canonical_backup_dir, "/\\") . DIRECTORY_SEPARATOR;

        $candidate_paths = [BJLG_BACKUP_DIR . $sanitized_id];

        if (strtolower(substr($sanitized_id, -4)) !== '.zip') {
            $candidate_paths[] = BJLG_BACKUP_DIR . $sanitized_id . '.zip';
        }

        $canonical_length = strlen($canonical_backup_dir);

        foreach ($candidate_paths as $candidate_path) {
            if (!file_exists($candidate_path)) {
                continue;
            }

            $resolved_path = realpath($candidate_path);

            if ($resolved_path === false) {
                return new WP_Error(
                    'invalid_backup_id',
                    'Invalid backup ID',
                    ['status' => 400]
                );
            }

            if (strlen($resolved_path) < $canonical_length || strncmp($resolved_path, $canonical_backup_dir, $canonical_length) !== 0) {
                return new WP_Error(
                    'invalid_backup_id',
                    'Invalid backup ID',
                    ['status' => 400]
                );
            }

            return $resolved_path;
        }

        return new WP_Error(
            'backup_not_found',
            'Backup not found',
            ['status' => 404]
        );
    }
}

