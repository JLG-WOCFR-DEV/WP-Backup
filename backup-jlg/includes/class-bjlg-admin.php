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
        $defaults = [
            'backup_restore' => 'Sauvegarde & Restauration',
            'history' => 'Historique',
            'health_check' => 'Bilan de Santé',
            'settings' => 'Réglages',
            'logs' => 'Logs & Outils',
            'api' => 'API & Intégrations',
        ];

        if (is_array($tabs) && !empty($tabs)) {
            return array_merge($defaults, $tabs);
        }

        return $defaults;
    }

    /**
     * Crée la page de menu dans l'administration.
     */
    public function create_admin_page() {
        $wl_settings = get_option('bjlg_whitelabel_settings', []);
        $plugin_name = !empty($wl_settings['plugin_name']) ? $wl_settings['plugin_name'] : 'Backup - JLG';
        
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
                    case 'api':
                        $this->render_api_section();
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
            <h2>Créer une sauvegarde</h2>
            <form id="bjlg-backup-creation-form">
                <p>Choisissez les composants à inclure dans votre sauvegarde.</p>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">Contenu de la sauvegarde</th>
                            <td>
                                <fieldset>
                                    <label><input type="checkbox" name="backup_components[]" value="db" checked> <strong>Base de données</strong> <span class="description">Toutes les tables WordPress</span></label><br>
                                    <label><input type="checkbox" name="backup_components[]" value="plugins" checked> Extensions (<code>/wp-content/plugins</code>)</label><br>
                                    <label><input type="checkbox" name="backup_components[]" value="themes" checked> Thèmes (<code>/wp-content/themes</code>)</label><br>
                                    <label><input type="checkbox" name="backup_components[]" value="uploads" checked> Médias (<code>/wp-content/uploads</code>)</label>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Options</th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="checkbox" name="encrypt_backup" value="1">
                                        Chiffrer la sauvegarde (AES-256)
                                    </label>
                                    <p class="description">
                                        Sécurise votre fichier de sauvegarde avec un chiffrement robuste. Indispensable si vous stockez vos sauvegardes sur un service cloud tiers.
                                    </p>
                                    <br>
                                    <label>
                                        <input type="checkbox" name="incremental_backup" value="1">
                                        Sauvegarde incrémentale
                                    </label>
                                    <p class="description">
                                        Ne sauvegarde que les fichiers modifiés depuis la dernière sauvegarde complète. Plus rapide et utilise moins d'espace disque.
                                    </p>
                                </fieldset>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <button id="bjlg-create-backup" type="submit" class="button button-primary button-hero">
                        <span class="dashicons dashicons-backup"></span> Lancer la création de la sauvegarde
                    </button>
                </p>
            </form>
            <div id="bjlg-backup-progress-area" style="display: none;">
                <h3>Progression</h3>
                <div class="bjlg-progress-bar"><div
                        class="bjlg-progress-bar-inner"
                        id="bjlg-backup-progress-bar"
                        role="progressbar"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="0"
                        aria-valuetext="0%"
                        aria-live="off"
                        aria-atomic="true"
                        aria-busy="false">0%</div></div>
                <p id="bjlg-backup-status-text"
                   role="status"
                   aria-live="polite"
                   aria-atomic="true"
                   aria-busy="false">Initialisation...</p>
            </div>
            <div id="bjlg-backup-debug-wrapper" style="display: none;">
                <h3><span class="dashicons dashicons-info"></span> Détails techniques</h3>
                <pre id="bjlg-backup-ajax-debug" class="bjlg-log-textarea"></pre>
            </div>
        </div>
        <?php
    }

    /**
     * Section : Liste des sauvegardes
     */
    private function render_backup_list_section() {
        ?>
        <div class="bjlg-section" id="bjlg-backup-list-section" data-default-page="1" data-default-per-page="10">
            <h2>Sauvegardes Disponibles</h2>
            <div class="bjlg-backup-toolbar">
                <div class="alignleft actions">
                    <label for="bjlg-backup-filter-type">
                        <span class="screen-reader-text">Filtrer par type</span>
                        <select id="bjlg-backup-filter-type" aria-label="Filtrer les sauvegardes par type">
                            <option value="all" selected>Toutes les sauvegardes</option>
                            <option value="full">Complètes</option>
                            <option value="incremental">Incrémentales</option>
                            <option value="database">Base de données</option>
                            <option value="files">Fichiers</option>
                        </select>
                    </label>
                    <label for="bjlg-backup-per-page">
                        <span class="screen-reader-text">Nombre de sauvegardes par page</span>
                        <select id="bjlg-backup-per-page" aria-label="Nombre de sauvegardes par page">
                            <option value="5">5</option>
                            <option value="10" selected>10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                    </label>
                    <button type="button" class="button" id="bjlg-backup-refresh">
                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                        Actualiser
                    </button>
                </div>
                <div class="alignright" id="bjlg-backup-summary" aria-live="polite"></div>
            </div>
            <div id="bjlg-backup-list-feedback" class="notice notice-error" role="alert" style="display:none;"></div>
            <table class="wp-list-table widefat striped bjlg-responsive-table bjlg-backup-table">
                <thead>
                    <tr>
                        <th scope="col">Nom du fichier</th>
                        <th scope="col">Composants</th>
                        <th scope="col">Taille</th>
                        <th scope="col">Date</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody id="bjlg-backup-table-body">
                    <tr class="bjlg-backup-loading-row">
                        <td colspan="5">
                            <span class="spinner is-active" aria-hidden="true"></span>
                            <span>Chargement des sauvegardes...</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="tablenav bottom">
                <div class="tablenav-pages" id="bjlg-backup-pagination" aria-live="polite"></div>
            </div>
            <noscript>
                <div class="notice notice-warning"><p>JavaScript est requis pour afficher la liste des sauvegardes.</p></div>
            </noscript>
        </div>
        <?php
    }

    /**
     * Section : Restauration
     */
    private function render_restore_section() {
        ?>
        <div class="bjlg-section">
            <h2>Restaurer depuis un fichier</h2>
            <p>Si vous avez un fichier de sauvegarde sur votre ordinateur, vous pouvez le téléverser ici pour lancer une restauration.</p>
            <form id="bjlg-restore-form" method="post" enctype="multipart/form-data">
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="bjlg-restore-file-input">Fichier de sauvegarde</label></th>
                            <td>
                                <input type="file" id="bjlg-restore-file-input" name="restore_file" accept=".zip,.zip.enc" required>
                                <p class="description">Formats acceptés : .zip, .zip.enc (chiffré)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bjlg-restore-password">Mot de passe</label></th>
                            <td>
                                <input type="password"
                                       id="bjlg-restore-password"
                                       name="password"
                                       class="regular-text"
                                       autocomplete="current-password"
                                       aria-describedby="bjlg-restore-password-help"
                                       placeholder="Requis pour les archives .zip.enc">
                                <p class="description"
                                   id="bjlg-restore-password-help"
                                   data-default-text="<?php echo esc_attr('Requis pour restaurer les sauvegardes chiffrées (.zip.enc). Laissez vide pour les archives non chiffrées.'); ?>"
                                   data-encrypted-text="<?php echo esc_attr('Mot de passe obligatoire : renseignez-le pour déchiffrer l\'archive (.zip.enc).'); ?>">
                                    Requis pour restaurer les sauvegardes chiffrées (<code>.zip.enc</code>). Laissez vide pour les archives non chiffrées.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Options</th>
                            <td><label><input type="checkbox" name="create_backup_before_restore" value="1" checked> Créer une sauvegarde de sécurité avant la restauration</label></td>
                        </tr>
                    </tbody>
                </table>
                <div id="bjlg-restore-errors" class="notice notice-error" style="display: none;" role="alert"></div>
                <p class="submit">
                    <button type="submit" class="button button-primary"><span class="dashicons dashicons-upload"></span> Téléverser et Restaurer</button>
                </p>
            </form>
            <div id="bjlg-restore-status" style="display: none;">
                <h3>Statut de la restauration</h3>
                <div class="bjlg-progress-bar"><div
                        class="bjlg-progress-bar-inner"
                        id="bjlg-restore-progress-bar"
                        role="progressbar"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="0"
                        aria-valuetext="0%"
                        aria-live="off"
                        aria-atomic="true"
                        aria-busy="false">0%</div></div>
                <p id="bjlg-restore-status-text"
                   role="status"
                   aria-live="polite"
                   aria-atomic="true"
                   aria-busy="false">Préparation...</p>
            </div>
            <div id="bjlg-restore-debug-wrapper" style="display: none;">
                <h3><span class="dashicons dashicons-info"></span> Détails techniques</h3>
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
            <h2>Historique des 50 dernières actions</h2>
            <?php if (!empty($history)): ?>
                <table class="wp-list-table widefat striped bjlg-responsive-table bjlg-history-table">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 180px;">Date</th>
                            <th scope="col">Action</th>
                            <th scope="col" style="width: 100px;">Statut</th>
                            <th scope="col">Détails</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry):
                            $status_class = ''; $status_icon = '';
                            switch ($entry['status']) {
                                case 'success': $status_class = 'success'; $status_icon = '✅'; break;
                                case 'failure': $status_class = 'error'; $status_icon = '❌'; break;
                                case 'info': $status_class = 'info'; $status_icon = 'ℹ️'; break;
                            } ?>
                            <tr class="bjlg-card-row">
                                <td class="bjlg-card-cell" data-label="Date"><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry['timestamp'])); ?></td>
                                <td class="bjlg-card-cell" data-label="Action"><strong><?php echo esc_html(str_replace('_', ' ', ucfirst($entry['action_type']))); ?></strong></td>
                                <td class="bjlg-card-cell" data-label="Statut"><span class="bjlg-status <?php echo esc_attr($status_class); ?>"><?php echo $status_icon . ' ' . esc_html(ucfirst($entry['status'])); ?></span></td>
                                <td class="bjlg-card-cell" data-label="Détails"><?php echo esc_html($entry['details']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="notice notice-info"><p>Aucun historique trouvé.</p></div>
            <?php endif; ?>
            <p class="description" style="margin-top: 20px;">L'historique est conservé pendant 30 jours. Les entrées plus anciennes sont automatiquement supprimées.</p>
        </div>
        <?php
    }

    /**
     * Section : Bilan de santé
     */
    private function render_health_check_section() {
        $health_checker = new BJLG_Health_Check();
        $results = $health_checker->get_all_checks();
        $plugin_checks = ['debug_mode' => 'Mode Débogage', 'cron_status' => 'Tâches planifiées (Cron)'];
        $server_checks = ['backup_dir' => 'Dossier de sauvegarde', 'disk_space' => 'Espace disque', 'php_memory_limit' => 'Limite Mémoire PHP', 'php_execution_time' => 'Temps d\'exécution PHP'];
        ?>
        <div class="bjlg-section">
            <h2>Bilan de Santé du Système</h2>
            <h3>État du Plugin</h3>
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
            <h3>Configuration Serveur</h3>
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
            <p style="margin-top: 20px;"><button class="button" onclick="window.location.reload();"><span class="dashicons dashicons-update"></span> Relancer les vérifications</button></p>
        </div>
        <?php
    }

    /**
     * Section : Réglages
     */
    private function render_settings_section() {
        $cleanup_settings = get_option('bjlg_cleanup_settings', ['by_number' => 3, 'by_age' => 0]);
        $schedule_default = [
            'recurrence' => 'weekly',
            'day' => 'sunday',
            'time' => '23:59',
            'components' => ['db', 'plugins', 'themes', 'uploads'],
            'encrypt' => false,
            'incremental' => false,
        ];

        if (class_exists(BJLG_Scheduler::class)) {
            $scheduler = BJLG_Scheduler::instance();
            if (method_exists($scheduler, 'get_schedule_settings')) {
                $schedule_settings = $scheduler->get_schedule_settings();
            } else {
                $schedule_settings = wp_parse_args(get_option('bjlg_schedule_settings', []), $schedule_default);
            }
        } else {
            $schedule_settings = wp_parse_args(get_option('bjlg_schedule_settings', []), $schedule_default);
        }

        $selected_components = isset($schedule_settings['components']) && is_array($schedule_settings['components'])
            ? $schedule_settings['components']
            : $schedule_default['components'];
        $components_labels = [
            'db' => 'Base de données',
            'plugins' => 'Extensions',
            'themes' => 'Thèmes',
            'uploads' => 'Médias',
        ];
        $encrypt_enabled = !empty($schedule_settings['encrypt']);
        $incremental_enabled = !empty($schedule_settings['incremental']);
        $wl_settings = get_option('bjlg_whitelabel_settings', ['plugin_name' => '', 'hide_from_non_admins' => false]);
        $webhook_key = class_exists(BJLG_Webhooks::class) ? BJLG_Webhooks::get_webhook_key() : '';
        ?>
        <div class="bjlg-section">
            <h2>Configuration du Plugin</h2>
            
            <h3><span class="dashicons dashicons-cloud"></span> Destinations Cloud</h3>
            <form class="bjlg-settings-form">
                <div class="bjlg-settings-feedback notice" role="status" aria-live="polite" style="display:none;"></div>
                <?php
                if (!empty($this->destinations)) {
                    foreach ($this->destinations as $destination) {
                        $destination->render_settings();
                    }
                } else {
                    echo '<p class="description">Aucune destination cloud configurée. Activez Google Drive ou Amazon S3 en complétant leurs réglages.</p>';
                }
                ?>
            </form>
            
            <h3><span class="dashicons dashicons-calendar-alt"></span> Planification des Sauvegardes</h3>
            <form id="bjlg-schedule-form">
                <table class="form-table">
                    <tr>
                        <th scope="row">Fréquence</th>
                        <td>
                            <select name="recurrence" id="bjlg-schedule-recurrence">
                                <option value="disabled" <?php selected($schedule_settings['recurrence'], 'disabled'); ?>>Désactivée</option>
                                <option value="hourly" <?php selected($schedule_settings['recurrence'], 'hourly'); ?>>Toutes les heures</option>
                                <option value="daily" <?php selected($schedule_settings['recurrence'], 'daily'); ?>>Journalière</option>
                                <option value="weekly" <?php selected($schedule_settings['recurrence'], 'weekly'); ?>>Hebdomadaire</option>
                                <option value="monthly" <?php selected($schedule_settings['recurrence'], 'monthly'); ?>>Mensuelle</option>
                            </select>
                        </td>
                    </tr>
                    <tr class="bjlg-schedule-weekly-options" <?php echo ($schedule_settings['recurrence'] !== 'weekly') ? 'style="display:none;"' : ''; ?>>
                        <th scope="row">Jour de la semaine</th>
                        <td>
                            <select name="day" id="bjlg-schedule-day">
                                <?php $days = ['monday' => 'Lundi', 'tuesday' => 'Mardi', 'wednesday' => 'Mercredi', 'thursday' => 'Jeudi', 'friday' => 'Vendredi', 'saturday' => 'Samedi', 'sunday' => 'Dimanche'];
                                foreach ($days as $day_key => $day_name): ?>
                                    <option value="<?php echo $day_key; ?>" <?php selected(isset($schedule_settings['day']) ? $schedule_settings['day'] : 'sunday', $day_key); ?>><?php echo $day_name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr class="bjlg-schedule-time-options" <?php echo ($schedule_settings['recurrence'] === 'disabled') ? 'style="display:none;"' : ''; ?>>
                        <th scope="row">Heure</th>
                        <td>
                            <input type="time" name="time" id="bjlg-schedule-time" value="<?php echo esc_attr(isset($schedule_settings['time']) ? $schedule_settings['time'] : '23:59'); ?>">
                            <p class="description">Heure locale du serveur</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Composants</th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text">Composants inclus dans la sauvegarde planifiée</legend>
                                <?php foreach ($components_labels as $component_key => $component_label): ?>
                                    <label style="display:block; margin-bottom:4px;">
                                        <input type="checkbox"
                                               name="components[]"
                                               value="<?php echo esc_attr($component_key); ?>"
                                               <?php checked(in_array($component_key, $selected_components, true)); ?>>
                                        <?php if ($component_key === 'db'): ?>
                                            <strong><?php echo esc_html($component_label); ?></strong>
                                            <span class="description">Toutes les tables WordPress</span>
                                        <?php else: ?>
                                            <?php
                                            switch ($component_key) {
                                                case 'plugins':
                                                    $path = '/wp-content/plugins';
                                                    break;
                                                case 'themes':
                                                    $path = '/wp-content/themes';
                                                    break;
                                                case 'uploads':
                                                    $path = '/wp-content/uploads';
                                                    break;
                                                default:
                                                    $path = '';
                                            }
                                            ?>
                                            <?php echo esc_html($component_label); ?>
                                            <?php if ($path !== ''): ?><span class="description">(<code><?php echo esc_html($path); ?></code>)</span><?php endif; ?>
                                        <?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Options</th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text">Options supplémentaires de la sauvegarde planifiée</legend>
                                <label class="bjlg-switch" style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                    <input type="checkbox"
                                           id="bjlg-schedule-encrypt"
                                           name="encrypt"
                                           value="1"
                                           role="switch"
                                           aria-checked="<?php echo $encrypt_enabled ? 'true' : 'false'; ?>"
                                           <?php checked($encrypt_enabled); ?>>
                                    <span class="bjlg-switch-label"><strong>Chiffrer la sauvegarde (AES-256)</strong></span>
                                </label>
                                <p class="description" style="margin-top:-4px; margin-bottom:10px;">
                                    Sécurise votre fichier de sauvegarde avec un chiffrement robuste. Indispensable si vous stockez vos sauvegardes sur un service cloud tiers.
                                </p>
                                <label class="bjlg-switch" style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                    <input type="checkbox"
                                           id="bjlg-schedule-incremental"
                                           name="incremental"
                                           value="1"
                                           role="switch"
                                           aria-checked="<?php echo $incremental_enabled ? 'true' : 'false'; ?>"
                                           <?php checked($incremental_enabled); ?>>
                                    <span class="bjlg-switch-label"><strong>Sauvegarde incrémentale</strong></span>
                                </label>
                                <p class="description" style="margin-top:-4px;">
                                    Ne sauvegarde que les fichiers modifiés depuis la dernière sauvegarde complète. Plus rapide et utilise moins d'espace disque.
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Résumé</th>
                        <td>
                            <div id="bjlg-schedule-summary" class="bjlg-schedule-summary" aria-live="polite">
                                <?php echo $this->get_schedule_summary_markup($selected_components, $encrypt_enabled, $incremental_enabled); ?>
                            </div>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button button-primary">Enregistrer la planification</button></p>
            </form>
            
            <h3><span class="dashicons dashicons-admin-links"></span> Webhook</h3>
            <p>Utilisez ce point de terminaison pour déclencher une sauvegarde à distance en toute sécurité :</p>
            <div class="bjlg-webhook-url" style="margin-bottom: 10px;">
                <label for="bjlg-webhook-endpoint" style="display:block; font-weight:600;">Point de terminaison</label>
                <div>
                    <input type="text" id="bjlg-webhook-endpoint" readonly value="<?php echo esc_url(BJLG_Webhooks::get_webhook_endpoint()); ?>" class="regular-text code" style="width: 70%;">
                    <button class="button bjlg-copy-field" data-copy-target="#bjlg-webhook-endpoint">Copier l'URL</button>
                </div>
            </div>
            <div class="bjlg-webhook-url" style="margin-bottom: 10px;">
                <label for="bjlg-webhook-key" style="display:block; font-weight:600;">Clé secrète</label>
                <div>
                    <input type="text" id="bjlg-webhook-key" readonly value="<?php echo esc_attr($webhook_key); ?>" class="regular-text code" style="width: 70%;">
                    <button class="button bjlg-copy-field" data-copy-target="#bjlg-webhook-key">Copier la clé</button>
                    <button class="button" id="bjlg-regenerate-webhook">Régénérer</button>
                </div>
            </div>
            <p class="description">Envoyez une requête <strong>POST</strong> à l'URL ci-dessus en ajoutant l'en-tête <code><?php echo esc_html(BJLG_Webhooks::WEBHOOK_HEADER); ?></code> (ou <code>Authorization: Bearer &lt;clé&gt;</code>) contenant votre clé.</p>
            <pre class="code"><code><?php echo esc_html(sprintf("curl -X POST %s \\n  -H 'Content-Type: application/json' \\n  -H '%s: %s'", BJLG_Webhooks::get_webhook_endpoint(), BJLG_Webhooks::WEBHOOK_HEADER, $webhook_key)); ?></code></pre>
            <p class="description"><strong>Compatibilité :</strong> L'ancien format <code><?php echo esc_html(add_query_arg(BJLG_Webhooks::WEBHOOK_QUERY_VAR, 'VOTRE_CLE', home_url('/'))); ?></code> reste supporté provisoirement mais sera retiré après la période de transition.</p>

            <form class="bjlg-settings-form">
                <div class="bjlg-settings-feedback notice" role="status" aria-live="polite" style="display:none;"></div>
                <h3><span class="dashicons dashicons-trash"></span> Rétention des Sauvegardes</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Conserver par nombre</th>
                        <td>
                            <input name="by_number" type="number" class="small-text" value="<?php echo esc_attr(isset($cleanup_settings['by_number']) ? $cleanup_settings['by_number'] : 3); ?>" min="0"> sauvegardes
                            <p class="description">0 = illimité</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Conserver par ancienneté</th>
                        <td>
                            <input name="by_age" type="number" class="small-text" value="<?php echo esc_attr(isset($cleanup_settings['by_age']) ? $cleanup_settings['by_age'] : 0); ?>" min="0"> jours
                            <p class="description">0 = illimité</p>
                        </td>
                    </tr>
                </table>
                
                <h3><span class="dashicons dashicons-admin-appearance"></span> Marque Blanche</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Nom du plugin</th>
                        <td>
                            <input type="text" name="plugin_name" value="<?php echo esc_attr(isset($wl_settings['plugin_name']) ? $wl_settings['plugin_name'] : ''); ?>" class="regular-text" placeholder="Backup - JLG">
                            <p class="description">Laissez vide pour utiliser le nom par défaut</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Visibilité</th>
                        <td><label><input type="checkbox" name="hide_from_non_admins" <?php checked(isset($wl_settings['hide_from_non_admins']) && $wl_settings['hide_from_non_admins']); ?>> Cacher le plugin pour les non-administrateurs</label></td>
                    </tr>
                </table>
                
                <p class="submit"><button type="submit" class="button button-primary">Enregistrer les Réglages</button></p>
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
            <h2>Journaux et Outils de Diagnostic</h2>
            
            <h3>Emplacements des Fichiers</h3>
            <p class="description">
                <strong>Sauvegardes :</strong> <code><?php echo esc_html($relative_backup_dir); ?></code><br>
                <strong>Journal du Plugin :</strong> <code>/wp-content/bjlg-debug.log</code> (si <code>BJLG_DEBUG</code> est activé)<br>
                <strong>Journal d'erreurs WP :</strong> <code>/wp-content/debug.log</code> (si <code>WP_DEBUG_LOG</code> est activé)
            </p>
            <hr>
            
            <h3>Journal d'activité du Plugin</h3>
            <p class="description">
                Pour activer : ajoutez <code>define('BJLG_DEBUG', true);</code> dans votre <code>wp-config.php</code>
            </p>
            <textarea class="bjlg-log-textarea" readonly><?php echo esc_textarea(class_exists(BJLG_Debug::class) ? BJLG_Debug::get_plugin_log_content() : 'Classe BJLG_Debug non trouvée.'); ?></textarea>

            <h3>Journal d'erreurs PHP de WordPress</h3>
            <p class="description">
                Pour activer : ajoutez <code>define('WP_DEBUG_LOG', true);</code> dans votre <code>wp-config.php</code>
            </p>
            <textarea class="bjlg-log-textarea" readonly><?php echo esc_textarea(class_exists(BJLG_Debug::class) ? BJLG_Debug::get_wp_error_log_content() : 'Classe BJLG_Debug non trouvée.'); ?></textarea>
            
            <h3>Outils de Support</h3>
            <p>Générez un pack de support contenant les journaux et les informations système pour faciliter le diagnostic.</p>
            <p>
                <button id="bjlg-generate-support-package" class="button button-primary">
                    <span class="dashicons dashicons-download"></span> Créer un pack de support
                </button>
            </p>
            <div id="bjlg-support-package-status" style="display: none;">
                <p class="description">Génération du pack de support en cours...</p>
            </div>
        </div>
        <?php
    }

    /**
     * Section : API & Intégrations
     */
    private function render_api_section() {
        $keys = BJLG_API_Keys::get_keys();
        $has_keys = !empty($keys);
        ?>
        <div class="bjlg-section" id="bjlg-api-keys-section">
            <h2>API &amp; Intégrations</h2>
            <p class="description">
                Gérez les clés d'accès utilisées par vos intégrations externes. Créez une nouvelle clé pour chaque service,
                puis régénérez-la ou révoquez-la si nécessaire.
            </p>

            <div id="bjlg-api-keys-feedback" class="notice" style="display:none;" aria-live="polite"></div>

            <form id="bjlg-create-api-key" class="bjlg-inline-form">
                <h3>Créer une nouvelle clé</h3>
                <p class="description">Donnez un nom à la clé pour identifier l'intégration correspondante.</p>
                <label for="bjlg-api-key-label" class="screen-reader-text">Nom de la clé API</label>
                <input type="text" id="bjlg-api-key-label" name="label" class="regular-text"
                       placeholder="Ex. : CRM Marketing" autocomplete="off" />
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-plus"></span> Générer une clé API
                </button>
            </form>

            <p class="description bjlg-api-keys-empty"<?php echo $has_keys ? ' style="display:none;"' : ''; ?>>
                Aucune clé API n'a été générée pour le moment.
            </p>

            <table id="bjlg-api-keys-table" class="wp-list-table widefat striped bjlg-responsive-table"<?php echo $has_keys ? '' : ' style="display:none;"'; ?>>
                <thead>
                    <tr>
                        <th scope="col">Nom</th>
                        <th scope="col">Clé</th>
                        <th scope="col">Créée le</th>
                        <th scope="col">Dernière rotation</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($keys as $key): ?>
                    <tr data-key-id="<?php echo esc_attr($key['id']); ?>" data-created-at="<?php echo esc_attr($key['created_at']); ?>" data-last-rotated-at="<?php echo esc_attr($key['last_rotated_at']); ?>">
                        <td>
                            <strong class="bjlg-api-key-label"><?php echo esc_html($key['label']); ?></strong>
                        </td>
                        <td>
                            <code class="bjlg-api-key-value" aria-label="Clé API"><?php echo esc_html($key['secret']); ?></code>
                        </td>
                        <td>
                            <time class="bjlg-api-key-created" datetime="<?php echo esc_attr($key['created_at_iso']); ?>">
                                <?php echo esc_html($key['created_at_human']); ?>
                            </time>
                        </td>
                        <td>
                            <time class="bjlg-api-key-rotated" datetime="<?php echo esc_attr($key['last_rotated_at_iso']); ?>">
                                <?php echo esc_html($key['last_rotated_at_human']); ?>
                            </time>
                        </td>
                        <td>
                            <div class="bjlg-api-key-actions">
                                <button type="button" class="button bjlg-rotate-api-key" data-key-id="<?php echo esc_attr($key['id']); ?>">
                                    <span class="dashicons dashicons-update"></span> Régénérer
                                </button>
                                <button type="button" class="button button-link-delete bjlg-revoke-api-key" data-key-id="<?php echo esc_attr($key['id']); ?>">
                                    <span class="dashicons dashicons-no"></span> Révoquer
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function get_schedule_summary_markup(array $components, $encrypt, $incremental) {
        $component_config = [
            'db' => ['label' => 'Base de données', 'color' => '#6366f1'],
            'plugins' => ['label' => 'Extensions', 'color' => '#f59e0b'],
            'themes' => ['label' => 'Thèmes', 'color' => '#10b981'],
            'uploads' => ['label' => 'Médias', 'color' => '#3b82f6'],
        ];

        $component_badges = [];
        foreach ($components as $component) {
            if (isset($component_config[$component])) {
                $component_badges[] = $this->format_schedule_badge(
                    $component_config[$component]['label'],
                    $component_config[$component]['color'],
                    'bjlg-badge-component'
                );
            }
        }

        if (empty($component_badges)) {
            $component_badges[] = '<span class="description">Aucun composant sélectionné</span>';
        }

        $option_badges = [];
        $option_badges[] = $this->format_schedule_badge(
            $encrypt ? 'Chiffrée' : 'Non chiffrée',
            $encrypt ? '#7c3aed' : '#4b5563',
            'bjlg-badge-encrypted'
        );
        $option_badges[] = $this->format_schedule_badge(
            $incremental ? 'Incrémentale' : 'Complète',
            $incremental ? '#2563eb' : '#6b7280',
            'bjlg-badge-incremental'
        );

        return $this->wrap_schedule_badge_group('Composants', $component_badges)
            . $this->wrap_schedule_badge_group('Options', $option_badges);
    }

    private function wrap_schedule_badge_group($title, array $badges) {
        $style = 'display:flex;flex-wrap:wrap;align-items:center;gap:4px;margin-bottom:6px;';
        $title_style = 'margin-right:4px;';

        return sprintf(
            '<div class="bjlg-badge-group" style="%s"><strong style="%s">%s :</strong>%s</div>',
            esc_attr($style),
            esc_attr($title_style),
            esc_html($title),
            implode('', $badges)
        );
    }

    private function format_schedule_badge($label, $color, $extra_class = '') {
        $color = $this->normalize_badge_color($color);
        $style = sprintf(
            'display:inline-flex;align-items:center;justify-content:center;border-radius:4px;padding:2px 6px;font-size:0.8em;font-weight:600;color:#ffffff;background-color:%s;margin-right:4px;margin-top:2px;',
            $color
        );

        $class_attr = 'bjlg-badge';
        if (!empty($extra_class)) {
            $class_attr .= ' ' . $extra_class;
        }

        return sprintf(
            '<span class="%s" style="%s">%s</span>',
            esc_attr($class_attr),
            esc_attr($style),
            esc_html($label)
        );
    }

    private function normalize_badge_color($color) {
        if (!is_string($color)) {
            return '#4b5563';
        }

        $color = trim($color);

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return '#4b5563';
        }

        return strtolower($color);
    }
}
