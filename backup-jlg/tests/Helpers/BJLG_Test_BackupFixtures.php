<?php
declare(strict_types=1);

use BJLG\BJLG_Encryption;

require_once __DIR__ . '/../../includes/class-bjlg-encryption.php';

/**
 * Utilities to build backup archives for tests.
 */
final class BJLG_Test_BackupFixtures
{
    /**
     * Creates a temporary directory, executes the callback and cleans it afterwards.
     *
     * @template T
     * @param callable(string):T $callback
     * @return T
     */
    public static function withTemporaryDirectory(callable $callback)
    {
        $base = sys_get_temp_dir() . '/bjlg-fixture-' . uniqid('', true);
        if (!@mkdir($base, 0777, true) && !is_dir($base)) {
            throw new RuntimeException('Impossible de créer le répertoire temporaire de test.');
        }

        try {
            return $callback($base);
        } finally {
            bjlg_tests_recursive_delete($base);
        }
    }

    /**
     * Builds a backup archive and optionally encrypts it.
     *
     * @param array{
     *     filename?: string,
     *     manifest?: array<string, mixed>,
     *     database?: string|null,
     *     files?: array<string, string>,
     *     encrypt?: bool,
     *     password?: string|null
     * } $options
     * @return array{path: string, manifest: array<string, mixed>, password: string|null}
     */
    public static function createBackupArchive(array $options = []): array
    {
        $defaults = [
            'filename' => 'backup-' . uniqid('', true) . '.zip',
            'manifest' => [],
            'database' => null,
            'files' => [],
            'encrypt' => false,
            'password' => null,
        ];

        $config = array_merge($defaults, $options);

        $manifest = $config['manifest'];
        if (!isset($manifest['type'])) {
            $manifest['type'] = $config['manifest']['type'] ?? ($config['encrypt'] ? 'incremental' : 'full');
        }

        if (!isset($manifest['contains']) || !is_array($manifest['contains'])) {
            $contains = [];
            if ($config['database'] !== null) {
                $contains[] = 'db';
            }

            if (!empty($config['files'])) {
                foreach (array_keys($config['files']) as $path) {
                    if (strpos($path, 'wp-content/plugins/') === 0) {
                        $contains[] = 'plugins';
                    } elseif (strpos($path, 'wp-content/themes/') === 0) {
                        $contains[] = 'themes';
                    } elseif (strpos($path, 'wp-content/uploads/') === 0) {
                        $contains[] = 'uploads';
                    }
                }
            }

            $manifest['contains'] = array_values(array_unique($contains));
        }

        $manifest['created_at'] = $manifest['created_at'] ?? gmdate('c');

        $databaseContents = $config['database'];
        $archiveFilename = $config['filename'];
        $files = is_array($config['files']) ? $config['files'] : [];

        $result = self::withTemporaryDirectory(static function (string $directory) use ($manifest, $databaseContents, $files, $archiveFilename) {
            $zipPath = $directory . '/' . $archiveFilename;

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Impossible de créer l\'archive temporaire.');
            }

            $zip->addFromString('backup-manifest.json', json_encode($manifest));

            if ($databaseContents !== null) {
                $zip->addFromString('database.sql', $databaseContents);
            }

            foreach ($files as $path => $contents) {
                $zip->addFromString($path, $contents);
            }

            $zip->close();

            $destination = BJLG_BACKUP_DIR . $archiveFilename;
            if (!@copy($zipPath, $destination)) {
                throw new RuntimeException('Impossible de copier l\'archive générée vers le répertoire de sauvegarde.');
            }

            return $destination;
        });

        if (!$config['encrypt']) {
            return [
                'path' => $result,
                'manifest' => $manifest,
                'password' => null,
            ];
        }

        $password = $config['password'];
        $previousSettings = bjlg_get_option('bjlg_encryption_settings', null);
        bjlg_update_option('bjlg_encryption_settings', ['enabled' => true]);

        if (!defined('BJLG_ENCRYPTION_KEY')) {
            $rawKey = str_repeat("\0", BJLG_Encryption::KEY_LENGTH);
            define('BJLG_ENCRYPTION_KEY', 'base64:' . base64_encode($rawKey));
        }

        $encryption = new BJLG_Encryption();
        $encryptedPath = $encryption->encrypt_backup_file($result, $password);

        if ($previousSettings === null) {
            unset($GLOBALS['bjlg_test_options']['bjlg_encryption_settings']);
        } else {
            bjlg_update_option('bjlg_encryption_settings', $previousSettings);
        }

        return [
            'path' => $encryptedPath,
            'manifest' => $manifest,
            'password' => $password,
        ];
    }

    /**
     * Corrupts the HMAC of an encrypted archive to simulate integrity failures.
     */
    public static function corruptEncryptedArchiveHmac(string $path): void
    {
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('Impossible d\'ouvrir le fichier chiffré pour modification.');
        }

        try {
            $magic = fread($handle, strlen(BJLG_Encryption::FILE_MAGIC));
            if ($magic !== BJLG_Encryption::FILE_MAGIC) {
                throw new RuntimeException('Fichier chiffré invalide.');
            }

            $version = ord(fread($handle, 1));
            $flags = 0;

            if ($version >= 2) {
                $flags = ord(fread($handle, 1));
            }

            fread($handle, BJLG_Encryption::IV_LENGTH);

            if ($version >= 2 && ($flags & BJLG_Encryption::FILE_FLAG_PASSWORD)) {
                $saltLength = ord(fread($handle, 1));
                if ($saltLength > 0) {
                    fread($handle, $saltLength);
                }
            }

            $hmacOffset = ftell($handle);
            $hmac = fread($handle, BJLG_Encryption::HMAC_LENGTH);

            if ($hmac === false || strlen($hmac) !== BJLG_Encryption::HMAC_LENGTH) {
                throw new RuntimeException('Impossible de lire le HMAC de l\'archive.');
            }

            $modified = chr((ord($hmac[0]) + 1) % 256) . substr($hmac, 1);
            fseek($handle, $hmacOffset);
            fwrite($handle, $modified);
        } finally {
            fclose($handle);
        }
    }
}
