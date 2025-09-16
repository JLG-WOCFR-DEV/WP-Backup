<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Intégration Google Drive (future version).
 */
if (interface_exists(BJLG_Destination_Interface::class)) {

    class BJLG_Google_Drive implements BJLG_Destination_Interface {

        public function __construct() {
            // Pas de hooks pour l’instant
        }

        public function get_id() { return 'google_drive'; }

        public function get_name() { return 'Google Drive (Bientôt disponible)'; }

        public function is_connected() { return false; }

        public function disconnect() { /* noop */ }

        public function render_settings() {
            echo "<h4><span class='dashicons dashicons-google'></span> Google Drive</h4>";
            echo "<p class='description'>La connexion à Google Drive sera disponible dans une future mise à jour.</p>";
        }

        public function upload_file($filepath, $task_id) {
            // Stub temporaire
        }
    }
}
