<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contrat pour toutes les destinations de sauvegarde.
 */
interface BJLG_Destination_Interface {

    /**
     * Identifiant unique (slug), ex: 'google_drive'.
     * @return string
     */
    public function get_id();

    /**
     * Nom lisible, ex: 'Google Drive'.
     * @return string
     */
    public function get_name();

    /**
     * La destination est-elle connectée ?
     * @return bool
     */
    public function is_connected();

    /**
     * Déconnecter / réinitialiser la destination.
     * @return void
     */
    public function disconnect();

    /**
     * Afficher les réglages de la destination (HTML).
     * @return void
     */
    public function render_settings();

    /**
     * Envoyer un fichier de sauvegarde vers la destination.
     *
     * @param string $filepath Chemin complet du fichier à envoyer.
     * @param string $task_id  ID de la tâche pour suivi.
     * @return void
     * @throws Exception
     */
    public function upload_file($filepath, $task_id);

    /**
     * Liste les sauvegardes distantes connues de la destination.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list_remote_backups();

    /**
     * Supprime les sauvegardes distantes qui dépassent les règles de rétention.
     *
     * @param int $retain_by_number Nombre de sauvegardes à conserver (0 = illimité).
     * @param int $retain_by_age_days Ancienneté maximale en jours (0 = illimité).
     * @return array<string, mixed>
     */
    public function prune_remote_backups($retain_by_number, $retain_by_age_days);
}
