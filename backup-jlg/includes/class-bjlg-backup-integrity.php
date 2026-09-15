<?php
namespace BJLG;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checksums SHA-256 and archive identification for backup files.
 */
class BJLG_Backup_Integrity {

    public const ALGORITHM = 'sha256';
    public const SIDECAR_SUFFIX = '.sha256';

    /**
     * Whether a path is a backup archive (zip or encrypted zip), not a sidecar.
     *
     * @param string $path
     * @return bool
     */
    public static function is_backup_archive($path) {
        $basename = basename((string) $path);

        if ($basename === '' || substr($basename, -strlen(self::SIDECAR_SUFFIX)) === self::SIDECAR_SUFFIX) {
            return false;
        }

        return (bool) preg_match('/\.zip(\.enc)?$/i', $basename);
    }

    /**
     * Filter a list of paths to actual backup archives.
     *
     * @param array<int, string> $paths
     * @return array<int, string>
     */
    public static function filter_archive_paths(array $paths) {
        $filtered = [];

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            if (self::is_backup_archive($path)) {
                $filtered[] = $path;
            }
        }

        return $filtered;
    }

    /**
     * @param string $filepath
     * @return string
     */
    public static function sidecar_path($filepath) {
        return (string) $filepath . self::SIDECAR_SUFFIX;
    }

    /**
     * Compute SHA-256 of a file.
     *
     * @param string $filepath
     * @return string
     * @throws Exception
     */
    public static function hash_file($filepath) {
        if (!is_string($filepath) || $filepath === '' || !is_readable($filepath)) {
            throw new Exception('Impossible de calculer le checksum : fichier illisible.');
        }

        $hash = hash_file(self::ALGORITHM, $filepath);

        if (!is_string($hash) || $hash === '') {
            throw new Exception('Impossible de calculer le hash SHA-256 de la sauvegarde.');
        }

        return $hash;
    }

    /**
     * Write a GNU coreutils-style SHA-256 sidecar next to the archive.
     *
     * @param string      $filepath
     * @param string|null $hash
     * @return string The hash that was written.
     * @throws Exception
     */
    public static function write_sidecar($filepath, $hash = null) {
        $hash = is_string($hash) && $hash !== '' ? strtolower($hash) : self::hash_file($filepath);
        $sidecar = self::sidecar_path($filepath);
        $line = $hash . '  ' . basename((string) $filepath) . "\n";

        if (file_put_contents($sidecar, $line) === false) {
            throw new Exception('Impossible d\'écrire le fichier de checksum SHA-256.');
        }

        return $hash;
    }

    /**
     * Read the expected hash from a sidecar file.
     *
     * @param string $filepath Archive path (not the sidecar).
     * @return string|null
     */
    public static function read_sidecar($filepath) {
        $sidecar = self::sidecar_path($filepath);

        if (!is_readable($sidecar)) {
            return null;
        }

        $contents = file_get_contents($sidecar);

        if (!is_string($contents) || trim($contents) === '') {
            return null;
        }

        if (preg_match('/^([a-f0-9]{64})\b/i', trim($contents), $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Verify an archive against its sidecar when present.
     *
     * @param string $filepath
     * @param bool   $require_sidecar When true, a missing sidecar is an error.
     * @return array{status: string, checksum: string, algorithm: string, sidecar: bool, message: string}
     * @throws Exception
     */
    public static function verify_file($filepath, $require_sidecar = false) {
        $actual = self::hash_file($filepath);
        $expected = self::read_sidecar($filepath);
        $sidecar_exists = is_readable(self::sidecar_path($filepath));

        if ($sidecar_exists && $expected === null) {
            throw new Exception('Fichier de checksum SHA-256 illisible ou invalide.');
        }

        if ($expected === null) {
            if ($require_sidecar) {
                throw new Exception('Checksum SHA-256 introuvable pour cette sauvegarde.');
            }

            return [
                'status' => 'skipped',
                'checksum' => $actual,
                'algorithm' => self::ALGORITHM,
                'sidecar' => false,
                'message' => 'Aucun fichier de checksum SHA-256 : vérification d\'intégrité ignorée pour cette archive.',
            ];
        }

        if (!hash_equals($expected, $actual)) {
            throw new Exception(
                'Checksum SHA-256 invalide : l\'archive ne correspond pas au fichier de contrôle. Le fichier est corrompu ou a été modifié.'
            );
        }

        return [
            'status' => 'passed',
            'checksum' => $actual,
            'algorithm' => self::ALGORITHM,
            'sidecar' => true,
            'message' => 'Checksum SHA-256 validé.',
        ];
    }

    /**
     * Delete the sidecar that belongs to an archive.
     *
     * @param string $filepath
     * @return bool
     */
    public static function delete_sidecar($filepath) {
        $sidecar = self::sidecar_path($filepath);

        if (!file_exists($sidecar)) {
            return true;
        }

        return @unlink($sidecar);
    }
}
