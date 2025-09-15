<?php
if (!defined('ABSPATH')) exit;

/**
 * Actions AJAX (suppression de sauvegarde, etc.)
 */
class BJLG_Actions {

    public function __construct() {
        add_action('wp_ajax_bjlg_delete_backup', [$this, 'handle_delete_backup']);
    }

    /**
     * Supprime un fichier de sauvegarde via AJAX.
     */
    public function handle_delete_backup() {
        if (!current_user_can(BJLG_CAPABILITY)) {
            wp_send_json_error(['message' => 'Permission refusée.'], 403);
        }

        check_ajax_referer('bjlg_nonce', 'nonce');

        if (empty($_POST['filename'])) {
            wp_send_json_error(['message' => 'Nom de fichier manquant.'], 400);
        }

        $filename = '';
        try {
            // Valider et nettoyer le nom du fichier pour la sécurité
            $filename = basename(sanitize_file_name(wp_unslash($_POST['filename'])));
            if (empty($filename) || strpos($filename, '..') !== false) {
                throw new Exception("Nom de fichier invalide.");
            }

            // Construire le chemin initial vers le fichier
            $filepath = BJLG_BACKUP_DIR . $filename;

            // Obtenir les chemins absolus et canoniques pour la sécurité
            $real_backup_dir = realpath(BJLG_BACKUP_DIR);
            $real_filepath = realpath($filepath);

            // Si realpath échoue (par ex. fichier inexistant), on vérifie quand même le chemin de base
            if ($real_filepath === false) {
                 if (strpos(realpath(dirname($filepath)), $real_backup_dir) !== 0) {
                    throw new Exception("Chemin de fichier non valide.");
                 }
                 // Le fichier n'existe pas, donc on envoie l'erreur appropriée
                 throw new Exception("Fichier introuvable.");
            }
            
            // Normaliser les séparateurs de répertoire (barres obliques) pour une comparaison fiable
            $normalized_backup_dir = str_replace('\\', '/', $real_backup_dir);
            $normalized_filepath = str_replace('\\', '/', $real_filepath);

            // S'assurer que le chemin du répertoire se termine par un slash pour la comparaison
            $normalized_backup_dir = rtrim($normalized_backup_dir, '/') . '/';

            // Contrôle de sécurité final : le chemin du fichier doit commencer par le chemin du répertoire de sauvegarde
            if (strpos($normalized_filepath, $normalized_backup_dir) !== 0) {
                 throw new Exception("Accès au fichier non autorisé.");
            }
            
            if (!is_writable($real_filepath)) {
                throw new Exception("Fichier non supprimable (permissions).");
            }

            if (!@unlink($real_filepath)) {
                throw new Exception("Impossible de supprimer le fichier.");
            }

            if (class_exists('BJLG_History')) {
                BJLG_History::log('backup_deleted', 'success', 'Fichier : ' . $filename);
            }

            wp_send_json_success(['message' => 'Fichier supprimé avec succès.']);

        } catch (Exception $e) {
            if (class_exists('BJLG_History')) {
                BJLG_History::log('backup_deleted', 'failure', 'Fichier : ' . $filename . ' - Erreur : ' . $e->getMessage());
            }
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}