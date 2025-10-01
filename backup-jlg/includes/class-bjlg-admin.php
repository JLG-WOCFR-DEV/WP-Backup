<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gère la création et l'affichage de l'interface d'administration du plugin.
 */
class BJLG_Admin {

    private $destinations = [];

    public function __construct() {
        $this->load_destinations();
        add_action('admin_menu', [$this, 'create_admin_page']);
        add_filter('bjlg_admin_tabs', [$this, 'get_default_tabs']);
    }

    /**
     * Charge les classes de destination disponibles.
     */
    private function load_destinations() {
        if (class_exists(BJLG_Google_Drive::class)) {
            $this->destinations['google_drive'] = new BJLG_Google_Drive();
        }
        if (class_exists(BJLG_AWS_S3::class)) {
            $this->destinations['aws_s3'] = new BJLG_AWS_S3();
        }
    }

    /**
     * Retourne les onglets par défaut
     */
    public function get_default_tabs($tabs) {
        return [
            'backup_restore' => __('Sauvegarde & Restauration', 'backup-jlg'),
            'history' => __('Historique', 'backup-jlg'),
            'health_check' => __('Bilan de Santé', 'backup-jlg'),
            'settings' => __('Réglages', 'backup-jlg'),
            'logs' => __('Logs & Outils', 'backup-jlg')
        ];
    }

    /**
     * Crée la page de menu dans l'administration.
     */
    public function create_admin_page() {
        $wl_settings = get_option('bjlg_whitelabel_settings', []);
        $plugin_name = !empty($wl_settings['plugin_name']) ? $wl_settings['plugin_name'] : __('Backup - JLG', 'backup-jlg');
        
        add_menu_page(
            $plugin_name,
            $plugin_name,
            BJLG_CAPABILITY,
            'backup-jlg',
            [$this, 'render_admin_page'],
            'dashicons-database-export',
            81
        );
    }
    
    /**
     * Affiche le contenu de la page principale et gère le routage des onglets.
     */
    public function render_admin_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'backup_restore';
        $page_url = admin_url('admin.php?page=backup-jlg');
        
        $tabs = apply_filters('bjlg_admin_tabs', []);
        ?>
        <div class="wrap bjlg-wrap">
            <h1>
                <span class="dashicons dashicons-database-export"></span> 
                <?php echo esc_html(get_admin_page_title()); ?>
                <span class="bjlg-version">v<?php echo esc_html(BJLG_VERSION); ?></span>
            </h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab_key => $tab_label): ?>
                    <a href="<?php echo esc_url(add_query_arg('tab', $tab_key, $page_url)); ?>" 
                       class="nav-tab <?php echo $active_tab == $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="bjlg-tab-content">
                <?php
                switch ($active_tab) {
                    case 'history':
                        $this->render_history_section();
                        break;
                    case 'health_check':
                        $this->render_health_check_section();
                        break;
                    case 'settings':
                        $this->render_settings_section();
                        break;
                    case 'logs':
                        $this->render_logs_section();
                        break;
                    case 'backup_restore':
                    default:
                        $this->render_backup_creation_section();
                        $this->render_backup_list_section();
                        $this->render_restore_section();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Section : Création de sauvegarde
     */
    private function render_backup_creation_section() {
        ?>
        <div class="bjlg-section">
            <h2><?php echo esc_html__('Créer une sauvegarde', 'backup-jlg'); ?></h2>
            <form id="bjlg-backup-creation-form">
                <p><?php echo esc_html__('Choisissez les composants à inclure dans votre sauvegarde.', 'backup-jlg'); ?></p>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Contenu de la sauvegarde', 'backup-jlg'); ?></th>
                            <td>
                                <fieldset>
                                    <label><input type="checkbox" name="backup_components[]" value="db" checked> <strong><?php echo esc_html__('Base de données', 'backup-jlg'); ?></strong> <span class="description"><?php echo esc_html__('Toutes les tables WordPress', 'backup-jlg'); ?></span></label><br>
                                    <label><input type="checkbox" name="backup_components[]" value="plugins" checked> <?php echo esc_html__('Extensions', 'backup-jlg'); ?> (<code>/wp-content/plugins</code>)</label><br>
                                    <label><input type="checkbox" name="backup_components[]" value="themes" checked> <?php echo esc_html__('Thèmes', 'backup-jlg'); ?> (<code>/wp-content/themes</code>)</label><br>
                                    <label><input type="checkbox" name="backup_components[]" value="uploads" checked> <?php echo esc_html__('Médias', 'backup-jlg'); ?> (<code>/wp-content/uploads</code>)</label>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Options', 'backup-jlg'); ?></th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="checkbox" name="encrypt_backup" value="1">
                                        <?php echo esc_html__('Chiffrer la sauvegarde (AES-256)', 'backup-jlg'); ?>
                                    </label>
                                    <p class="description">
                                        <?php echo esc_html__('Sécurise votre fichier de sauvegarde avec un chiffrement robuste. Indispensable si vous stockez vos sauvegardes sur un service cloud tiers.', 'backup-jlg'); ?>
                                    </p>
                                    <br>
                                    <label>
                                        <input type="checkbox" name="incremental_backup" value="1">
                                        <?php echo esc_html__('Sauvegarde incrémentale', 'backup-jlg'); ?>
                                    </label>
                                    <p class="description">
                                        <?php echo esc_html__('Ne sauvegarde que les fichiers modifiés depuis la dernière sauvegarde complète. Plus rapide et utilise moins d\'espace disque.', 'backup-jlg'); ?>
                                    </p>
                                </fieldset>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <button id="bjlg-create-backup" type="submit" class="button button-primary button-hero">
                        <span class="dashicons dashicons-backup"></span> <?php echo esc_html__('Lancer la création de la sauvegarde', 'backup-jlg'); ?>
                    </button>
                </p>
            </form>
            <div id="bjlg-backup-progress-area" style="display: none;">
                <h3><?php echo esc_html__('Progression', 'backup-jlg'); ?></h3>
                <div class="bjlg-progress-bar"><div class="bjlg-progress-bar-inner" id="bjlg-backup-progress-bar">0%</div></div>
                <p id="bjlg-backup-status-text"><?php echo esc_html__('Initialisation...', 'backup-jlg'); ?></p>
            </div>
            <div id="bjlg-backup-debug-wrapper" style="display: none;">
                <h3><span class="dashicons dashicons-info"></span> <?php echo esc_html__('Détails techniques', 'backup-jlg'); ?></h3>
                <pre id="bjlg-backup-ajax-debug" class="bjlg-log-textarea"></pre>
            </div>
        </div>
        <?php
    }

    /**
     * Section : Liste des sauvegardes
     */
    private function render_backup_list_section() {
        $backups = glob(BJLG_BACKUP_DIR . '*.zip*');
        ?>
        <div class="bjlg-section">
            <h2><?php echo esc_html__('Sauvegardes disponibles', 'backup-jlg'); ?></h2>
            <?php if (!empty($backups)):
                usort($backups, function($a, $b) { return filemtime($b) - filemtime($a); });
                ?>
                <table class="wp-list-table widefat striped bjlg-responsive-table bjlg-backup-table">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html__('Nom du fichier', 'backup-jlg'); ?></th>
                            <th scope="col"><?php echo esc_html__('Type', 'backup-jlg'); ?></th>
                            <th scope="col"><?php echo esc_html__('Taille', 'backup-jlg'); ?></th>
                            <th scope="col"><?php echo esc_html__('Date', 'backup-jlg'); ?></th>
                            <th scope="col"><?php echo esc_html__('Actions', 'backup-jlg'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup_file):
                            $filename = basename($backup_file);
                            $is_encrypted = (substr($filename, -4) === '.enc');
                            ?>
                            <tr class="bjlg-card-row">
                                <td class="bjlg-card-cell" data-label="<?php echo esc_attr__('Nom du fichier', 'backup-jlg'); ?>">
                                    <strong><?php echo esc_html($filename); ?></strong>
                                    <?php if ($is_encrypted): ?><span class="bjlg-badge encrypted" style="background: #a78bfa; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.8em; margin-left: 5px;"><?php echo esc_html__('Chiffré', 'backup-jlg'); ?></span><?php endif; ?>
                                </td>
                                <td class="bjlg-card-cell" data-label="<?php echo esc_attr__('Type', 'backup-jlg'); ?>">
                                    <?php
                                    if (strpos($filename, 'full') !== false) {
                                        printf(
                                            '<span class="bjlg-badge full" style="background: #34d399; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.8em;">%s</span>',
                                            esc_html__('Complète', 'backup-jlg')
                                        );
                                    } elseif (strpos($filename, 'incremental') !== false) {
                                        printf(
                                            '<span class="bjlg-badge incremental" style="background: #60a5fa; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.8em;">%s</span>',
                                            esc_html__('Incrémentale', 'backup-jlg')
                                        );
                                    } else {
                                        printf(
                                            '<span class="bjlg-badge standard" style="background: #9ca3af; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.8em;">%s</span>',
                                            esc_html__('Standard', 'backup-jlg')
                                        );
                                    }
                                    ?>
                                </td>
                                <td class="bjlg-card-cell" data-label="<?php echo esc_attr__('Taille', 'backup-jlg'); ?>"><?php echo size_format(filesize($backup_file), 2); ?></td>
                                <td class="bjlg-card-cell" data-label="<?php echo esc_attr__('Date', 'backup-jlg'); ?>"><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), filemtime($backup_file)); ?></td>
                                <td class="bjlg-card-cell bjlg-card-actions-cell" data-label="<?php echo esc_attr__('Actions', 'backup-jlg'); ?>">
                                    <div class="bjlg-card-actions">
                                        <button class="button button-primary bjlg-restore-button" data-filename="<?php echo esc_attr($filename); ?>"><?php echo esc_html__('Restaurer', 'backup-jlg'); ?></button>
                                        <button type="button" class="button bjlg-download-button" data-filename="<?php echo esc_attr($filename); ?>"><?php echo esc_html__('Télécharger', 'backup-jlg'); ?></button>
                                        <button class="button button-link-delete bjlg-delete-button" data-filename="<?php echo esc_attr($filename); ?>"><?php echo esc_html__('Supprimer', 'backup-jlg'); ?></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="tablenav bottom">
                    <div class="alignleft">
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %d: number of backups */
                                esc_html__('Total : %d sauvegarde(s)', 'backup-jlg'),
                                count($backups)
                            );
                            echo ' | ';
                            printf(
                                /* translators: %s: total backup size */
                                esc_html__('Espace utilisé : %s', 'backup-jlg'),
                                size_format(function_exists('bjlg_get_backup_size') ? bjlg_get_backup_size() : 0)
                            );
                            ?>
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <div class="notice notice-info"><p><?php echo esc_html__('Aucune sauvegarde locale trouvée. Créez votre première sauvegarde ci-dessus.', 'backup-jlg'); ?></p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Section : Restauration
     */
    private function render_restore_section() {
        ?>
        <div class="bjlg-section">
            <h2><?php echo esc_html__('Restaurer depuis un fichier', 'backup-jlg'); ?></h2>
            <p><?php echo esc_html__('Si vous avez un fichier de sauvegarde sur votre ordinateur, vous pouvez le téléverser ici pour lancer une restauration.', 'backup-jlg'); ?></p>
            <form id="bjlg-restore-form" method="post" enctype="multipart/form-data">
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="bjlg-restore-file-input"><?php echo esc_html__('Fichier de sauvegarde', 'backup-jlg'); ?></label></th>
                            <td>
                                <input type="file" id="bjlg-restore-file-input" name="restore_file" accept=".zip,.zip.enc" required>
                                <p class="description"><?php echo esc_html__('Formats acceptés : .zip, .zip.enc (chiffré)', 'backup-jlg'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bjlg-restore-password"><?php echo esc_html__('Mot de passe', 'backup-jlg'); ?></label></th>
                            <td>
                                <input type="password"
                                       id="bjlg-restore-password"
                                       name="password"
                                       class="regular-text"
                                       autocomplete="current-password"
                                       aria-describedby="bjlg-restore-password-help"
                                       placeholder="<?php echo esc_attr__('Requis pour les archives .zip.enc', 'backup-jlg'); ?>">
                                <p class="description"
                                   id="bjlg-restore-password-help"
                                   data-default-text="<?php echo esc_attr__('Requis pour restaurer les sauvegardes chiffrées (.zip.enc). Laissez vide pour les archives non chiffrées.', 'backup-jlg'); ?>"
                                   data-encrypted-text="<?php echo esc_attr__('Mot de passe obligatoire : renseignez-le pour déchiffrer l\'archive (.zip.enc).', 'backup-jlg'); ?>">
                                    <?php printf(
                                        /* translators: %s: file extension */
                                        esc_html__('Requis pour restaurer les sauvegardes chiffrées (%s). Laissez vide pour les archives non chiffrées.', 'backup-jlg'),
                                        '<code>.zip.enc</code>'
                                    ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Options', 'backup-jlg'); ?></th>
                            <td><label><input type="checkbox" name="create_backup_before_restore" value="1" checked> <?php echo esc_html__('Créer une sauvegarde de sécurité avant la restauration', 'backup-jlg'); ?></label></td>
                        </tr>
                    </tbody>
                </table>
                <div id="bjlg-restore-errors" class="notice notice-error" style="display: none;" role="alert"></div>
                <p class="submit">
                    <button type="submit" class="button button-primary"><span class="dashicons dashicons-upload"></span> <?php echo esc_html__('Téléverser et restaurer', 'backup-jlg'); ?></button>
                </p>
            </form>
            <div id="bjlg-restore-status" style="display: none;">
                <h3><?php echo esc_html__('Statut de la restauration', 'backup-jlg'); ?></h3>
                <div class="bjlg-progress-bar"><div class="bjlg-progress-bar-inner" id="bjlg-restore-progress-bar">0%</div></div>
                <p id="bjlg-restore-status-text"><?php echo esc_html__('Préparation...', 'backup-jlg'); ?></p>
            </div>
            <div id="bjlg-restore-debug-wrapper" style="display: none;">
                <h3><span class="dashicons dashicons-info"></span> <?php echo esc_html__('Détails techniques', 'backup-jlg'); ?></h3>
                <pre id="bjlg-restore-ajax-debug" class="bjlg-log-textarea"></pre>
            </div>
        </div>
        <?php
    }

    /**
     * Section : Historique
     */
    private function render_history_section() {
        $history = class_exists(BJLG_History::class) ? BJLG_History::get_history(50) : [];
        ?>
        <div class="bjlg-section">
            <h2><?php echo esc_html__('Historique des 50 dernières actions', 'backup-jlg'); ?></h2>
            <?php if (!empty($history)): ?>
                <table class="wp-list-table widefat striped bjlg-responsive-table bjlg-history-table">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 180px;"><?php echo esc_html__('Date', 'backup-jlg'); ?></th>
                            <th scope="col"><?php echo esc_html__('Action', 'backup-jlg'); ?></th>
                            <th scope="col" style="width: 100px;"><?php echo esc_html__('Statut', 'backup-jlg'); ?></th>
                            <th scope="col"><?php echo esc_html__('Détails', 'backup-jlg'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry):
                            $status_class = ''; $status_icon = ''; $status_text = '';
                            switch ($entry['status']) {
                                case 'success':
                                    $status_class = 'success';
                                    $status_icon = '✅';
                                    $status_text = esc_html__('Succès', 'backup-jlg');
                                    break;
                                case 'failure':
                                    $status_class = 'error';
                                    $status_icon = '❌';
                                    $status_text = esc_html__('Échec', 'backup-jlg');
                                    break;
                                case 'info':
                                default:
                                    $status_class = 'info';
                                    $status_icon = 'ℹ️';
                                    $status_text = esc_html__('Information', 'backup-jlg');
                                    break;
                            } ?>
                            <tr class="bjlg-card-row">
                                <td class="bjlg-card-cell" data-label="<?php echo esc_attr__('Date', 'backup-jlg'); ?>"><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry['timestamp'])); ?></td>
                                <td class="bjlg-card-cell" data-label="<?php echo esc_attr__('Action', 'backup-jlg'); ?>"><strong><?php echo esc_html(str_replace('_', ' ', ucfirst($entry['action_type']))); ?></strong></td>
                                <td class="bjlg-card-cell" data-label="<?php echo esc_attr__('Statut', 'backup-jlg'); ?>"><span class="bjlg-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_icon . ' ' . $status_text); ?></span></td>
                                <td class="bjlg-card-cell" data-label="<?php echo esc_attr__('Détails', 'backup-jlg'); ?>"><?php echo esc_html($entry['details']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="notice notice-info"><p><?php echo esc_html__('Aucun historique trouvé.', 'backup-jlg'); ?></p></div>
            <?php endif; ?>
            <p class="description" style="margin-top: 20px;"><?php echo esc_html__('L\'historique est conservé pendant 30 jours. Les entrées plus anciennes sont automatiquement supprimées.', 'backup-jlg'); ?></p>
        </div>
        <?php
    }

    /**
     * Section : Bilan de santé
     */
    private function render_health_check_section() {
        $health_checker = new BJLG_Health_Check();
        $results = $health_checker->get_all_checks();
        $plugin_checks = [
            'debug_mode' => __('Mode Débogage', 'backup-jlg'),
            'cron_status' => __('Tâches planifiées (Cron)', 'backup-jlg'),
        ];
        $server_checks = [
            'backup_dir' => __('Dossier de sauvegarde', 'backup-jlg'),
            'disk_space' => __('Espace disque', 'backup-jlg'),
            'php_memory_limit' => __('Limite Mémoire PHP', 'backup-jlg'),
            'php_execution_time' => __('Temps d\'exécution PHP', 'backup-jlg'),
        ];
        ?>
        <div class="bjlg-section">
            <h2><?php echo esc_html__('Bilan de santé du système', 'backup-jlg'); ?></h2>
            <h3><?php echo esc_html__('État du plugin', 'backup-jlg'); ?></h3>
            <table class="wp-list-table widefat striped bjlg-health-check-table">
                <tbody>
                    <?php foreach ($plugin_checks as $key => $title): $result = $results[$key]; ?>
                        <tr>
                            <td style="width: 30px; text-align: center; font-size: 1.5em;"><?php echo ($result['status'] === 'success') ? '✅' : (($result['status'] === 'info') ? 'ℹ️' : '⚠️'); ?></td>
                            <td style="width: 250px;"><strong><?php echo esc_html($title); ?></strong></td>
                            <td><?php echo wp_kses_post($result['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <h3><?php echo esc_html__('Configuration serveur', 'backup-jlg'); ?></h3>
            <table class="wp-list-table widefat striped bjlg-health-check-table">
                <tbody>
                    <?php foreach ($server_checks as $key => $title): $result = $results[$key]; ?>
                        <tr>
                            <td style="width: 30px; text-align: center; font-size: 1.5em;"><?php echo ($result['status'] === 'success') ? '✅' : (($result['status'] === 'warning') ? '⚠️' : '❌'); ?></td>
                            <td style="width: 250px;"><strong><?php echo esc_html($title); ?></strong></td>
                            <td><?php echo wp_kses_post($result['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top: 20px;"><button class="button" onclick="window.location.reload();"><span class="dashicons dashicons-update"></span> <?php echo esc_html__('Relancer les vérifications', 'backup-jlg'); ?></button></p>
        </div>
        <?php
    }

    /**
     * Section : Réglages
     */
    private function render_settings_section() {
        $cleanup_settings = get_option('bjlg_cleanup_settings', ['by_number' => 3, 'by_age' => 0]);
        $schedule_settings = get_option('bjlg_schedule_settings', ['recurrence' => 'weekly', 'day' => 'sunday', 'time' => '23:59']);
        $wl_settings = get_option('bjlg_whitelabel_settings', ['plugin_name' => '', 'hide_from_non_admins' => false]);
        $webhook_key = class_exists(BJLG_Webhooks::class) ? BJLG_Webhooks::get_webhook_key() : '';
        ?>
        <div class="bjlg-section">
            <h2><?php echo esc_html__('Configuration du plugin', 'backup-jlg'); ?></h2>

            <h3><span class="dashicons dashicons-cloud"></span> <?php echo esc_html__('Destinations cloud', 'backup-jlg'); ?></h3>
            <form class="bjlg-settings-form">
                <div class="bjlg-settings-feedback notice" role="status" aria-live="polite" style="display:none;"></div>
                <?php
                if (!empty($this->destinations)) {
                    foreach ($this->destinations as $destination) {
                        $destination->render_settings();
                    }
                } else {
                    echo '<p class="description">' . esc_html__('Aucune destination cloud configurée. Activez Google Drive ou Amazon S3 en complétant leurs réglages.', 'backup-jlg') . '</p>';
                }
                ?>
            </form>
            
            <h3><span class="dashicons dashicons-calendar-alt"></span> <?php echo esc_html__('Planification des sauvegardes', 'backup-jlg'); ?></h3>
            <form id="bjlg-schedule-form">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Fréquence', 'backup-jlg'); ?></th>
                        <td>
                            <select name="recurrence" id="bjlg-schedule-recurrence">
                                <option value="disabled" <?php selected($schedule_settings['recurrence'], 'disabled'); ?>><?php echo esc_html__('Désactivée', 'backup-jlg'); ?></option>
                                <option value="hourly" <?php selected($schedule_settings['recurrence'], 'hourly'); ?>><?php echo esc_html__('Toutes les heures', 'backup-jlg'); ?></option>
                                <option value="daily" <?php selected($schedule_settings['recurrence'], 'daily'); ?>><?php echo esc_html__('Journalière', 'backup-jlg'); ?></option>
                                <option value="weekly" <?php selected($schedule_settings['recurrence'], 'weekly'); ?>><?php echo esc_html__('Hebdomadaire', 'backup-jlg'); ?></option>
                                <option value="monthly" <?php selected($schedule_settings['recurrence'], 'monthly'); ?>><?php echo esc_html__('Mensuelle', 'backup-jlg'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr class="bjlg-schedule-weekly-options" <?php echo ($schedule_settings['recurrence'] !== 'weekly') ? 'style="display:none;"' : ''; ?>>
                        <th scope="row"><?php echo esc_html__('Jour de la semaine', 'backup-jlg'); ?></th>
                        <td>
                            <select name="day" id="bjlg-schedule-day">
                                <?php $days = [
                                    'monday' => __('Lundi', 'backup-jlg'),
                                    'tuesday' => __('Mardi', 'backup-jlg'),
                                    'wednesday' => __('Mercredi', 'backup-jlg'),
                                    'thursday' => __('Jeudi', 'backup-jlg'),
                                    'friday' => __('Vendredi', 'backup-jlg'),
                                    'saturday' => __('Samedi', 'backup-jlg'),
                                    'sunday' => __('Dimanche', 'backup-jlg'),
                                ];
                                foreach ($days as $day_key => $day_name): ?>
                                    <option value="<?php echo $day_key; ?>" <?php selected(isset($schedule_settings['day']) ? $schedule_settings['day'] : 'sunday', $day_key); ?>><?php echo esc_html($day_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr class="bjlg-schedule-time-options" <?php echo ($schedule_settings['recurrence'] === 'disabled') ? 'style="display:none;"' : ''; ?>>
                        <th scope="row"><?php echo esc_html__('Heure', 'backup-jlg'); ?></th>
                        <td>
                            <input type="time" name="time" id="bjlg-schedule-time" value="<?php echo esc_attr(isset($schedule_settings['time']) ? $schedule_settings['time'] : '23:59'); ?>">
                            <p class="description"><?php echo esc_html__('Heure locale du serveur', 'backup-jlg'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button button-primary"><?php echo esc_html__('Enregistrer la planification', 'backup-jlg'); ?></button></p>
            </form>
            
            <h3><span class="dashicons dashicons-admin-links"></span> <?php echo esc_html__('Webhook', 'backup-jlg'); ?></h3>
            <p><?php echo esc_html__('Utilisez ce point de terminaison pour déclencher une sauvegarde à distance en toute sécurité :', 'backup-jlg'); ?></p>
            <div class="bjlg-webhook-url" style="margin-bottom: 10px;">
                <label for="bjlg-webhook-endpoint" style="display:block; font-weight:600;"><?php echo esc_html__('Point de terminaison', 'backup-jlg'); ?></label>
                <div>
                    <input type="text" id="bjlg-webhook-endpoint" readonly value="<?php echo esc_url(BJLG_Webhooks::get_webhook_endpoint()); ?>" class="regular-text code" style="width: 70%;">
                    <button class="button bjlg-copy-field" data-copy-target="#bjlg-webhook-endpoint"><?php echo esc_html__('Copier l\'URL', 'backup-jlg'); ?></button>
                </div>
            </div>
            <div class="bjlg-webhook-url" style="margin-bottom: 10px;">
                <label for="bjlg-webhook-key" style="display:block; font-weight:600;"><?php echo esc_html__('Clé secrète', 'backup-jlg'); ?></label>
                <div>
                    <input type="text" id="bjlg-webhook-key" readonly value="<?php echo esc_attr($webhook_key); ?>" class="regular-text code" style="width: 70%;">
                    <button class="button bjlg-copy-field" data-copy-target="#bjlg-webhook-key"><?php echo esc_html__('Copier la clé', 'backup-jlg'); ?></button>
                    <button class="button" id="bjlg-regenerate-webhook"><?php echo esc_html__('Régénérer', 'backup-jlg'); ?></button>
                </div>
            </div>
            <p class="description"><?php
                printf(
                    /* translators: %s: HTTP header name */
                    esc_html__('Envoyez une requête %1$sPOST%2$s à l\'URL ci-dessus en ajoutant l\'en-tête %3$s (ou %4$sAuthorization: Bearer &lt;clé&gt;%5$s) contenant votre clé.', 'backup-jlg'),
                    '<strong>',
                    '</strong>',
                    '<code>' . esc_html(BJLG_Webhooks::WEBHOOK_HEADER) . '</code>',
                    '<code>',
                    '</code>'
                );
            ?></p>
            <pre class="code"><code><?php echo esc_html(sprintf("curl -X POST %s \\n  -H 'Content-Type: application/json' \\n  -H '%s: %s'", BJLG_Webhooks::get_webhook_endpoint(), BJLG_Webhooks::WEBHOOK_HEADER, $webhook_key)); ?></code></pre>
            <p class="description"><strong><?php echo esc_html__('Compatibilité :', 'backup-jlg'); ?></strong> <?php printf(
                /* translators: %s: legacy webhook URL */
                esc_html__('L\'ancien format %s reste supporté provisoirement mais sera retiré après la période de transition.', 'backup-jlg'),
                '<code>' . esc_html(add_query_arg(BJLG_Webhooks::WEBHOOK_QUERY_VAR, 'VOTRE_CLE', home_url('/'))) . '</code>'
            ); ?></p>

            <form class="bjlg-settings-form">
                <div class="bjlg-settings-feedback notice" role="status" aria-live="polite" style="display:none;"></div>
                <h3><span class="dashicons dashicons-trash"></span> <?php echo esc_html__('Rétention des sauvegardes', 'backup-jlg'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Conserver par nombre', 'backup-jlg'); ?></th>
                        <td>
                            <input name="by_number" type="number" class="small-text" value="<?php echo esc_attr(isset($cleanup_settings['by_number']) ? $cleanup_settings['by_number'] : 3); ?>" min="0"> <?php echo esc_html__('sauvegardes', 'backup-jlg'); ?>
                            <p class="description"><?php echo esc_html__('0 = illimité', 'backup-jlg'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Conserver par ancienneté', 'backup-jlg'); ?></th>
                        <td>
                            <input name="by_age" type="number" class="small-text" value="<?php echo esc_attr(isset($cleanup_settings['by_age']) ? $cleanup_settings['by_age'] : 0); ?>" min="0"> <?php echo esc_html__('jours', 'backup-jlg'); ?>
                            <p class="description"><?php echo esc_html__('0 = illimité', 'backup-jlg'); ?></p>
                        </td>
                    </tr>
                </table>

                <h3><span class="dashicons dashicons-admin-appearance"></span> <?php echo esc_html__('Marque blanche', 'backup-jlg'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Nom du plugin', 'backup-jlg'); ?></th>
                        <td>
                            <input type="text" name="plugin_name" value="<?php echo esc_attr(isset($wl_settings['plugin_name']) ? $wl_settings['plugin_name'] : ''); ?>" class="regular-text" placeholder="<?php echo esc_attr__('Backup - JLG', 'backup-jlg'); ?>">
                            <p class="description"><?php echo esc_html__('Laissez vide pour utiliser le nom par défaut', 'backup-jlg'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Visibilité', 'backup-jlg'); ?></th>
                        <td><label><input type="checkbox" name="hide_from_non_admins" <?php checked(isset($wl_settings['hide_from_non_admins']) && $wl_settings['hide_from_non_admins']); ?>> <?php echo esc_html__('Cacher le plugin pour les non-administrateurs', 'backup-jlg'); ?></label></td>
                    </tr>
                </table>

                <p class="submit"><button type="submit" class="button button-primary"><?php echo esc_html__('Enregistrer les réglages', 'backup-jlg'); ?></button></p>
            </form>
        </div>
        <?php
    }

    /**
     * Section : Logs et outils
     */
    private function render_logs_section() {
        $relative_backup_dir = str_replace(untrailingslashit(ABSPATH), '', BJLG_BACKUP_DIR);
        ?>
        <div class="bjlg-section">
            <h2><?php echo esc_html__('Journaux et outils de diagnostic', 'backup-jlg'); ?></h2>

            <h3><?php echo esc_html__('Emplacements des fichiers', 'backup-jlg'); ?></h3>
            <p class="description">
                <strong><?php echo esc_html__('Sauvegardes :', 'backup-jlg'); ?></strong> <code><?php echo esc_html($relative_backup_dir); ?></code><br>
                <strong><?php echo esc_html__('Journal du plugin :', 'backup-jlg'); ?></strong> <code>/wp-content/bjlg-debug.log</code> (<?php
                    printf(
                        /* translators: %s: constant name. */
                        esc_html__('si %s est activé', 'backup-jlg'),
                        '<code>BJLG_DEBUG</code>'
                    );
                ?>)<br>
                <strong><?php echo esc_html__('Journal d\'erreurs WP :', 'backup-jlg'); ?></strong> <code>/wp-content/debug.log</code> (<?php
                    printf(
                        /* translators: %s: constant name. */
                        esc_html__('si %s est activé', 'backup-jlg'),
                        '<code>WP_DEBUG_LOG</code>'
                    );
                ?>)
            </p>
            <hr>

            <h3><?php echo esc_html__('Journal d\'activité du plugin', 'backup-jlg'); ?></h3>
            <p class="description">
                <?php
                printf(
                    /* translators: 1: opening code tag, 2: closing code tag, 3: opening wp-config code tag, 4: closing wp-config code tag. */
                    esc_html__('Pour activer : ajoutez %1$sdefine(\'BJLG_DEBUG\', true);%2$s dans votre %3$swp-config.php%4$s', 'backup-jlg'),
                    '<code>',
                    '</code>',
                    '<code>',
                    '</code>'
                );
                ?>
            </p>
            <textarea class="bjlg-log-textarea" readonly><?php echo esc_textarea(class_exists(BJLG_Debug::class) ? BJLG_Debug::get_plugin_log_content() : __('Classe BJLG_Debug non trouvée.', 'backup-jlg')); ?></textarea>

            <h3><?php echo esc_html__('Journal d\'erreurs PHP de WordPress', 'backup-jlg'); ?></h3>
            <p class="description">
                <?php
                printf(
                    /* translators: 1: opening code tag, 2: closing code tag, 3: opening wp-config code tag, 4: closing wp-config code tag. */
                    esc_html__('Pour activer : ajoutez %1$sdefine(\'WP_DEBUG_LOG\', true);%2$s dans votre %3$swp-config.php%4$s', 'backup-jlg'),
                    '<code>',
                    '</code>',
                    '<code>',
                    '</code>'
                );
                ?>
            </p>
            <textarea class="bjlg-log-textarea" readonly><?php echo esc_textarea(class_exists(BJLG_Debug::class) ? BJLG_Debug::get_wp_error_log_content() : __('Classe BJLG_Debug non trouvée.', 'backup-jlg')); ?></textarea>

            <h3><?php echo esc_html__('Outils de support', 'backup-jlg'); ?></h3>
            <p><?php echo esc_html__('Générez un pack de support contenant les journaux et les informations système pour faciliter le diagnostic.', 'backup-jlg'); ?></p>
            <p>
                <button id="bjlg-generate-support-package" class="button button-primary">
                    <span class="dashicons dashicons-download"></span> <?php echo esc_html__('Créer un pack de support', 'backup-jlg'); ?>
                </button>
            </p>
            <div id="bjlg-support-package-status" style="display: none;">
                <p class="description"><?php echo esc_html__('Génération du pack de support en cours...', 'backup-jlg'); ?></p>
            </div>
        </div>
        <?php
    }
}