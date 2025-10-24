<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Usine centralisée pour instancier les destinations distantes supportées.
 */
class BJLG_Destination_Factory {

    /**
     * Instancie une destination en fonction de son identifiant.
     *
     * @param string $destination_id
     * @return BJLG_Destination_Interface|null
     */
    public static function create($destination_id) {
        $destination_id = sanitize_key((string) $destination_id);
        if ($destination_id === '') {
            return null;
        }

        /**
         * Permet de fournir une implémentation personnalisée pour une destination.
         */
        $provided = apply_filters('bjlg_destination_factory', null, $destination_id);
        if ($provided instanceof BJLG_Destination_Interface) {
            return $provided;
        }

        switch ($destination_id) {
            case 'managed_vault':
                if (class_exists(BJLG_Managed_Vault::class)) {
                    return new BJLG_Managed_Vault();
                }
                break;
            case 'google_drive':
                if (class_exists(BJLG_Google_Drive::class)) {
                    return new BJLG_Google_Drive();
                }
                break;
            case 'aws_s3':
                if (class_exists(BJLG_AWS_S3::class)) {
                    return new BJLG_AWS_S3();
                }
                break;
            case 'wasabi':
                if (class_exists(BJLG_Wasabi::class)) {
                    return new BJLG_Wasabi();
                }
                break;
            case 'dropbox':
                if (class_exists(BJLG_Dropbox::class)) {
                    return new BJLG_Dropbox();
                }
                break;
            case 'onedrive':
                if (class_exists(BJLG_OneDrive::class)) {
                    return new BJLG_OneDrive();
                }
                break;
            case 'pcloud':
                if (class_exists(BJLG_PCloud::class)) {
                    return new BJLG_PCloud();
                }
                break;
            case 'sftp':
                if (class_exists(BJLG_SFTP::class)) {
                    return new BJLG_SFTP();
                }
                break;
            case 'azure_blob':
                if (class_exists(BJLG_Azure_Blob::class)) {
                    return new BJLG_Azure_Blob();
                }
                break;
            case 'backblaze_b2':
                if (class_exists(BJLG_Backblaze_B2::class)) {
                    return new BJLG_Backblaze_B2();
                }
                break;
            case 'managed_replication':
                if (class_exists(BJLG_Managed_Replication::class)) {
                    return new BJLG_Managed_Replication();
                }
                break;
            case 'managed_storage':
                if (class_exists(BJLG_Managed_Storage::class)) {
                    return new BJLG_Managed_Storage();
                }
                break;
        }

        return null;
    }
}
