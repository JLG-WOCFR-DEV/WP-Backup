<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( ! function_exists( 'bjlg_recursive_rmdir' ) ) {
    /**
     * Recursively delete a directory ensuring it resides within the provided base path.
     *
     * @param string $directory     Directory to remove.
     * @param string $base_directory Base directory that must contain the directory being removed.
     */
    function bjlg_recursive_rmdir( $directory, $base_directory ) {
        if ( empty( $directory ) || empty( $base_directory ) || ! is_dir( $directory ) ) {
            return;
        }

        $real_directory = realpath( $directory );
        $real_base      = realpath( $base_directory );

        if ( false === $real_directory || false === $real_base ) {
            return;
        }

        $real_base = rtrim( $real_base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

        if ( 0 !== strpos( $real_directory, $real_base ) ) {
            return;
        }

        $items = @scandir( $real_directory );

        if ( ! is_array( $items ) ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }

            $path = $real_directory . DIRECTORY_SEPARATOR . $item;

            if ( is_dir( $path ) ) {
                bjlg_recursive_rmdir( $path, $base_directory );
            } else {
                @unlink( $path );
            }
        }

        @rmdir( $real_directory );
    }
}

if ( ! function_exists( 'bjlg_uninstall_site' ) ) {
    /**
     * Run uninstall routine for a single site.
     */
    function bjlg_uninstall_site() {
        global $wpdb;

        $options = array(
            'bjlg_safe_mode',
            'bjlg_enabled_modules',
            'bjlg_api_keys',
            'bjlg_settings',
            'bjlg_performance_settings',
            'bjlg_performance_stats',
            'bjlg_whitelabel_settings',
            'bjlg_cleanup_settings',
            'bjlg_schedule_settings',
            'bjlg_notification_settings',
            'bjlg_encryption_settings',
            'bjlg_encryption_key',
            'bjlg_encryption_salt',
            'bjlg_gdrive_settings',
            'bjlg_webhook_settings',
            'bjlg_webhook_key',
            'bjlg_ajax_debug_enabled',
            'bjlg_required_capability',
        );

        foreach ( $options as $option_name ) {
            delete_option( $option_name );

            if ( function_exists( 'delete_site_option' ) ) {
                delete_site_option( $option_name );
            }
        }

        $option_like = $wpdb->esc_like( 'bjlg_' ) . '%';
        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $option_like
            )
        );

        foreach ( (array) $option_names as $option_name ) {
            delete_option( $option_name );

            if ( function_exists( 'delete_site_option' ) ) {
                delete_site_option( $option_name );
            }
        }

        $transient_likes = array(
            $wpdb->esc_like( '_transient_bjlg_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_bjlg_' ) . '%',
        );

        foreach ( $transient_likes as $like ) {
            $transient_names = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $like
                )
            );

            foreach ( (array) $transient_names as $transient_name ) {
                delete_option( $transient_name );
            }
        }

        $hooks = array(
            'bjlg_scheduled_backup_hook',
            'bjlg_daily_cleanup_hook',
            'bjlg_run_backup_task',
            'bjlg_run_restore_task',
        );

        foreach ( $hooks as $hook ) {
            if ( function_exists( 'wp_unschedule_hook' ) ) {
                wp_unschedule_hook( $hook );
            } elseif ( function_exists( 'wp_get_scheduled_event' ) && function_exists( 'wp_unschedule_event' ) ) {
                while ( $event = wp_get_scheduled_event( $hook ) ) {
                    $args = isset( $event->args ) ? $event->args : array();
                    wp_unschedule_event( $event->timestamp, $hook, $args );
                }
            }
        }

        $history_table = $wpdb->prefix . 'bjlg_history';
        $wpdb->query( "DROP TABLE IF EXISTS `{$history_table}`" );

        $upload_dir = wp_get_upload_dir();

        if ( ! empty( $upload_dir['basedir'] ) ) {
            $base_dir   = trailingslashit( $upload_dir['basedir'] );
            $target_dir = $base_dir . 'bjlg-backups';

            bjlg_recursive_rmdir( $target_dir, $base_dir );
        }
    }
}

if ( is_multisite() ) {
    $site_ids = get_sites( array( 'fields' => 'ids' ) );

    foreach ( $site_ids as $site_id ) {
        switch_to_blog( $site_id );
        bjlg_uninstall_site();
        restore_current_blog();
    }
} else {
    bjlg_uninstall_site();
}

$log_files = array(
    WP_CONTENT_DIR . '/bjlg-debug.log',
    WP_CONTENT_DIR . '/bjlg-debug.log.old',
);

$real_content_dir = realpath( WP_CONTENT_DIR );

if ( false !== $real_content_dir ) {
    $real_content_dir = rtrim( $real_content_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

    foreach ( $log_files as $log_file ) {
        if ( ! file_exists( $log_file ) ) {
            continue;
        }

        $real_log = realpath( $log_file );

        if ( false === $real_log ) {
            continue;
        }

        if ( 0 === strpos( $real_log, $real_content_dir ) ) {
            @unlink( $log_file );
        }
    }
}
