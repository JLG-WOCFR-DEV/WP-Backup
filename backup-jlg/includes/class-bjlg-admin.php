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
    }

    /**
     * Retourne les onglets par défaut
     */
    public function get_default_tabs($tabs) {
        return [
            'backup_restore' => 'Sauvegarde & Restauration',
            'history' => 'Historique',
            'health_check' => 'Bilan de Santé',
            'settings' => 'Réglages',
            'logs' => 'Logs & Outils'
        ];
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
                <div class="bjlg-progress-bar"><div class="bjlg-progress-bar-inner" id="bjlg-backup-progress-bar">0%</div></div>
                <p id="bjlg-backup-status-text">Initialisation...</p>
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
        $backups = glob(BJLG_BACKUP_DIR . '*.zip*');
        ?>
        <div class="bjlg-section">
            <h2>Sauvegardes Disponibles</h2>
            <?php if (!empty($backups)):
                usort($backups, function($a, $b) { return filemtime($b) - filemtime($a); });
                ?>
                <table class="wp-list-table widefat striped bjlg-responsive-table bjlg-backup-table">
                    <thead>
                        <tr>
                            <th scope="col">Nom du fichier</th>
                            <th scope="col">Type</th>
                            <th scope="col">Taille</th>
                            <th scope="col">Date</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup_file):
                            $filename = basename($backup_file);
                            $is_encrypted = (substr($filename, -4) === '.enc');
                            ?>
                            <tr class="bjlg-card-row">
                                <td class="bjlg-card-cell" data-label="Nom du fichier">
                                    <strong><?php echo esc_html($filename); ?></strong>
                                    <?php if ($is_encrypted): ?><span class="bjlg-badge encrypted" style="background: #a78bfa; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.8em; margin-left: 5px;">Chiffré</span><?php endif; ?>
                                </td>
                                <td class="bjlg-card-cell" data-label="Type">
                                    <?php
                                    if (strpos($filename, 'full') !== false) { echo '<span class="bjlg-badge full" style="background: #34d399; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.8em;">Complète</span>'; }
                                    elseif (strpos($filename, 'incremental') !== false) { echo '<span class="bjlg-badge incremental" style="background: #60a5fa; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.8em;">Incrémentale</span>'; }
                                    else { echo '<span class="bjlg-badge standard" style="background: #9ca3af; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.8em;">Standard</span>'; }
                                    ?>
                                </td>
                                <td class="bjlg-card-cell" data-label="Taille"><?php echo size_format(filesize($backup_file), 2); ?></td>
                                <td class="bjlg-card-cell" data-label="Date"><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), filemtime($backup_file)); ?></td>
                                <td class="bjlg-card-cell bjlg-card-actions-cell" data-label="Actions">
                                    <div class="bjlg-card-actions">
                                        <button class="button button-primary bjlg-restore-button" data-filename="<?php echo esc_attr($filename); ?>">Restaurer</button>
                                        <button type="button" class="button bjlg-download-button" data-filename="<?php echo esc_attr($filename); ?>">Télécharger</button>
                                        <button class="button button-link-delete bjlg-delete-button" data-filename="<?php echo esc_attr($filename); ?>">Supprimer</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="tablenav bottom">
                    <div class="alignleft">
                        <p class="description">
                            Total : <?php echo count($backups); ?> sauvegarde(s) | 
                            Espace utilisé : <?php echo size_format(function_exists('bjlg_get_backup_size') ? bjlg_get_backup_size() : 0); ?>
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <div class="notice notice-info"><p>Aucune sauvegarde locale trouvée. Créez votre première sauvegarde ci-dessus.</p></div>
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
                <div class="bjlg-progress-bar"><div class="bjlg-progress-bar-inner" id="bjlg-restore-progress-bar">0%</div></div>
                <p id="bjlg-restore-status-text">Préparation...</p>
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
        $schedule_settings = get_option('bjlg_schedule_settings', ['recurrence' => 'weekly', 'day' => 'sunday', 'time' => '23:59']);
        $wl_settings = get_option('bjlg_whitelabel_settings', ['plugin_name' => '', 'hide_from_non_admins' => false]);
        $webhook_key = class_exists(BJLG_Webhooks::class) ? BJLG_Webhooks::get_webhook_key() : '';
        ?>
        <div class="bjlg-section">
            <h2>Configuration du Plugin</h2>
            
            <h3><span class="dashicons dashicons-cloud"></span> Destinations Cloud</h3>
            <form class="bjlg-settings-form">
                <?php
                if (!empty($this->destinations)) {
                    foreach ($this->destinations as $destination) {
                        $destination->render_settings();
                    }
                } else {
                    echo '<p class="description">Aucune destination cloud configurée. Pour activer Google Drive, installez les dépendances via Composer.</p>';
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
}