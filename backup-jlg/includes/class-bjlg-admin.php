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
    private $advanced_admin;

    public function __construct() {
        $this->load_destinations();
        $this->advanced_admin = class_exists(BJLG_Admin_Advanced::class) ? new BJLG_Admin_Advanced() : null;
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
        if (class_exists(BJLG_Wasabi::class)) {
            $this->destinations['wasabi'] = new BJLG_Wasabi();
        }
        if (class_exists(BJLG_Dropbox::class)) {
            $this->destinations['dropbox'] = new BJLG_Dropbox();
        }
        if (class_exists(BJLG_OneDrive::class)) {
            $this->destinations['onedrive'] = new BJLG_OneDrive();
        }
        if (class_exists(BJLG_pCloud::class)) {
            $this->destinations['pcloud'] = new BJLG_pCloud();
        }
        if (class_exists(BJLG_SFTP::class)) {
            $this->destinations['sftp'] = new BJLG_SFTP();
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
            'bjlg_manage_plugin',
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
        $metrics = $this->advanced_admin ? $this->advanced_admin->get_dashboard_metrics() : [];

        ?>
        <div class="wrap bjlg-wrap">
            <h1>
                <span class="dashicons dashicons-database-export" aria-hidden="true"></span>
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
                <?php $this->render_dashboard_overview($metrics); ?>
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
     * Affiche l'encart de synthèse des métriques et l'onboarding.
     */
    private function render_dashboard_overview(array $metrics) {
        $summary = $metrics['summary'] ?? [];
        $alerts = $metrics['alerts'] ?? [];
        $onboarding = $metrics['onboarding'] ?? [];
        $data_attr = !empty($metrics) ? wp_json_encode($metrics) : '';

        $backup_tab_url = add_query_arg(
            [
                'page' => 'backup-jlg',
                'tab' => 'backup_restore',
            ],
            admin_url('admin.php')
        );

        $backup_cta_url = $backup_tab_url . '#bjlg-backup-creation-form';
        $restore_cta_url = $backup_tab_url . '#bjlg-restore-form';

        ?>
        <section class="bjlg-dashboard-overview" <?php echo $data_attr ? 'data-bjlg-dashboard="' . esc_attr($data_attr) . '"' : ''; ?>>
            <header class="bjlg-dashboard-overview__header">
                <h2><?php esc_html_e('Vue d’ensemble', 'backup-jlg'); ?></h2>
                <?php if (!empty($metrics['generated_at'])): ?>
                    <span class="bjlg-dashboard-overview__timestamp">
                        <?php echo esc_html(sprintf(__('Actualisé à %s', 'backup-jlg'), $metrics['generated_at'])); ?>
                    </span>
                <?php endif; ?>
            </header>

            <div class="bjlg-dashboard-actions" data-role="actions">
                <article class="bjlg-action-card" data-action="backup">
                    <div class="bjlg-action-card__content">
                        <h3 class="bjlg-action-card__title"><?php esc_html_e('Lancer une sauvegarde', 'backup-jlg'); ?></h3>
                        <p class="bjlg-action-card__meta" data-field="cta_backup_last_backup">
                            <?php echo esc_html($summary['history_last_backup_relative'] ?? __('Aucune sauvegarde récente.', 'backup-jlg')); ?>
                        </p>
                        <p class="bjlg-action-card__meta" data-field="cta_backup_next_run">
                            <?php echo esc_html($summary['scheduler_next_run_relative'] ?? __('Aucune planification active.', 'backup-jlg')); ?>
                        </p>
                    </div>
                    <a class="button button-primary button-hero bjlg-action-card__cta" href="<?php echo esc_url($backup_cta_url); ?>">
                        <span class="dashicons dashicons-backup" aria-hidden="true"></span>
                        <?php esc_html_e('Créer une sauvegarde', 'backup-jlg'); ?>
                    </a>
                </article>

                <article class="bjlg-action-card" data-action="restore">
                    <div class="bjlg-action-card__content">
                        <h3 class="bjlg-action-card__title"><?php esc_html_e('Restaurer une sauvegarde', 'backup-jlg'); ?></h3>
                        <p class="bjlg-action-card__meta" data-field="cta_restore_last_backup">
                            <?php echo esc_html($summary['history_last_backup'] ?? __('Aucune sauvegarde disponible.', 'backup-jlg')); ?>
                        </p>
                        <p class="bjlg-action-card__meta">
                            <?php esc_html_e('Archives stockées :', 'backup-jlg'); ?>
                            <span data-field="cta_restore_backup_count"><?php echo esc_html(number_format_i18n($summary['storage_backup_count'] ?? 0)); ?></span>
                        </p>
                    </div>
                    <a class="button button-secondary button-hero bjlg-action-card__cta" data-action-target="restore" href="<?php echo esc_url($restore_cta_url); ?>">
                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                        <?php esc_html_e('Ouvrir l’assistant de restauration', 'backup-jlg'); ?>
                    </a>
                </article>
            </div>

            <div class="bjlg-alerts" data-role="alerts">
                <?php foreach ($alerts as $alert): ?>
                    <div class="bjlg-alert bjlg-alert--<?php echo esc_attr($alert['type'] ?? 'info'); ?>">
                        <div class="bjlg-alert__content">
                            <strong class="bjlg-alert__title"><?php echo esc_html($alert['title'] ?? ''); ?></strong>
                            <?php if (!empty($alert['message'])): ?>
                                <p class="bjlg-alert__message"><?php echo esc_html($alert['message']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($alert['action']['label']) && !empty($alert['action']['url'])): ?>
                            <a class="bjlg-alert__action button button-secondary" href="<?php echo esc_url($alert['action']['url']); ?>">
                                <?php echo esc_html($alert['action']['label']); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="bjlg-cards-grid">
                <article class="bjlg-card bjlg-card--stat" data-metric="history">
                    <span class="bjlg-card__kicker"><?php esc_html_e('Activité 30 jours', 'backup-jlg'); ?></span>
                    <h3 class="bjlg-card__title"><?php esc_html_e('Actions enregistrées', 'backup-jlg'); ?></h3>
                    <div class="bjlg-card__value" data-field="history_total_actions"><?php echo esc_html(number_format_i18n($summary['history_total_actions'] ?? 0)); ?></div>
                    <p class="bjlg-card__meta">
                        <span data-field="history_successful_backups"><?php echo esc_html(number_format_i18n($summary['history_successful_backups'] ?? 0)); ?></span>
                        <?php esc_html_e(' sauvegardes réussies', 'backup-jlg'); ?>
                    </p>
                </article>

                <article class="bjlg-card bjlg-card--stat" data-metric="last-backup">
                    <span class="bjlg-card__kicker"><?php esc_html_e('Dernière sauvegarde', 'backup-jlg'); ?></span>
                    <h3 class="bjlg-card__title"><?php esc_html_e('Statut récent', 'backup-jlg'); ?></h3>
                    <div class="bjlg-card__value" data-field="history_last_backup"><?php echo esc_html($summary['history_last_backup'] ?? __('Aucune sauvegarde effectuée', 'backup-jlg')); ?></div>
                    <p class="bjlg-card__meta" data-field="history_last_backup_relative">
                        <?php echo esc_html($summary['history_last_backup_relative'] ?? ''); ?>
                    </p>
                </article>

                <article class="bjlg-card bjlg-card--stat" data-metric="scheduler">
                    <span class="bjlg-card__kicker"><?php esc_html_e('Planification', 'backup-jlg'); ?></span>
                    <h3 class="bjlg-card__title"><?php esc_html_e('Prochaine exécution', 'backup-jlg'); ?></h3>
                    <div class="bjlg-card__value" data-field="scheduler_next_run"><?php echo esc_html($summary['scheduler_next_run'] ?? __('Non planifié', 'backup-jlg')); ?></div>
                    <p class="bjlg-card__meta" data-field="scheduler_next_run_relative"><?php echo esc_html($summary['scheduler_next_run_relative'] ?? ''); ?></p>
                    <p class="bjlg-card__footnote">
                        <?php esc_html_e('Planifications actives :', 'backup-jlg'); ?>
                        <span data-field="scheduler_active_count"><?php echo esc_html(number_format_i18n($summary['scheduler_active_count'] ?? 0)); ?></span>
                        • <?php esc_html_e('Taux de succès :', 'backup-jlg'); ?>
                        <span data-field="scheduler_success_rate"><?php echo esc_html($summary['scheduler_success_rate'] ?? '0%'); ?></span>
                    </p>
                </article>

                <article class="bjlg-card bjlg-card--stat" data-metric="storage">
                    <span class="bjlg-card__kicker"><?php esc_html_e('Stockage', 'backup-jlg'); ?></span>
                    <h3 class="bjlg-card__title"><?php esc_html_e('Espace utilisé', 'backup-jlg'); ?></h3>
                    <div class="bjlg-card__value" data-field="storage_total_size_human"><?php echo esc_html($summary['storage_total_size_human'] ?? size_format(0)); ?></div>
                    <p class="bjlg-card__meta">
                        <?php esc_html_e('Fichiers archivés :', 'backup-jlg'); ?>
                        <span data-field="storage_backup_count"><?php echo esc_html(number_format_i18n($summary['storage_backup_count'] ?? 0)); ?></span>
                    </p>
                </article>
            </div>

            <div class="bjlg-onboarding" data-role="onboarding">
                <h3 class="bjlg-onboarding__title"><?php esc_html_e('Bien démarrer', 'backup-jlg'); ?></h3>
                <ul class="bjlg-onboarding__list">
                    <?php foreach ($onboarding as $resource): ?>
                        <li class="bjlg-onboarding__item">
                            <div class="bjlg-onboarding__content">
                                <strong class="bjlg-onboarding__label"><?php echo esc_html($resource['title']); ?></strong>
                                <?php if (!empty($resource['description'])): ?>
                                    <p class="bjlg-onboarding__description"><?php echo esc_html($resource['description']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($resource['command'])): ?>
                                    <code class="bjlg-onboarding__command" data-command="<?php echo esc_attr($resource['command']); ?>"><?php echo esc_html($resource['command']); ?></code>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($resource['url'])): ?>
                                <a class="bjlg-onboarding__action button button-secondary" href="<?php echo esc_url($resource['url']); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo esc_html($resource['action_label'] ?? __('Ouvrir', 'backup-jlg')); ?>
                                </a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="bjlg-dashboard-charts" data-role="charts">
                <article class="bjlg-chart-card" data-chart="history-trend">
                    <header class="bjlg-chart-card__header">
                        <h3 class="bjlg-chart-card__title"><?php esc_html_e('Tendance des sauvegardes', 'backup-jlg'); ?></h3>
                        <p class="bjlg-chart-card__subtitle" data-field="chart_history_subtitle">
                            <?php esc_html_e('Actions réussies et échouées sur 30 jours.', 'backup-jlg'); ?>
                        </p>
                    </header>
                    <canvas class="bjlg-chart-card__canvas" id="bjlg-history-trend" aria-hidden="true"></canvas>
                    <p class="bjlg-chart-card__empty" data-role="empty-message"><?php esc_html_e('Données de tendance indisponibles pour le moment.', 'backup-jlg'); ?></p>
                </article>

                <article class="bjlg-chart-card" data-chart="storage-trend">
                    <header class="bjlg-chart-card__header">
                        <h3 class="bjlg-chart-card__title"><?php esc_html_e('Evolution du stockage', 'backup-jlg'); ?></h3>
                        <p class="bjlg-chart-card__subtitle" data-field="chart_storage_subtitle">
                            <?php esc_html_e('Capacité utilisée par vos archives.', 'backup-jlg'); ?>
                        </p>
                    </header>
                    <canvas class="bjlg-chart-card__canvas" id="bjlg-storage-trend" aria-hidden="true"></canvas>
                    <p class="bjlg-chart-card__empty" data-role="empty-message"><?php esc_html_e('Aucune mesure d’utilisation disponible.', 'backup-jlg'); ?></p>
                </article>
            </div>
        </section>
        <?php
    }

    /**
     * Section : Création de sauvegarde
     */
    private function render_backup_creation_section() {
        $include_patterns = get_option('bjlg_backup_include_patterns', []);
        $exclude_patterns = get_option('bjlg_backup_exclude_patterns', []);
        $post_checks = get_option('bjlg_backup_post_checks', ['checksum' => true, 'dry_run' => false]);
        if (!is_array($post_checks)) {
            $post_checks = ['checksum' => true, 'dry_run' => false];
        }
        $secondary_destinations = get_option('bjlg_backup_secondary_destinations', []);
        if (!is_array($secondary_destinations)) {
            $secondary_destinations = [];
        }

        $include_text = esc_textarea(implode("\n", array_map('strval', (array) $include_patterns)));
        $exclude_text = esc_textarea(implode("\n", array_map('strval', (array) $exclude_patterns)));
        $destination_choices = $this->get_destination_choices();
        $presets = BJLG_Settings::get_backup_presets();
        $presets_json = !empty($presets) ? wp_json_encode(array_values($presets)) : '';
        ?>
        <div class="bjlg-section">
            <h2>Créer une sauvegarde</h2>
            <form id="bjlg-backup-creation-form">
                <p>Choisissez les composants à inclure dans votre sauvegarde.</p>
                <div class="bjlg-backup-presets"<?php echo $presets_json ? ' data-bjlg-presets=' . "'" . esc_attr($presets_json) . "'" : ''; ?>>
                    <h3>Modèles</h3>
                    <div class="bjlg-backup-presets__controls">
                        <label class="screen-reader-text" for="bjlg-backup-preset-select">Sélectionner un modèle de sauvegarde</label>
                        <select id="bjlg-backup-preset-select" class="bjlg-backup-presets__select">
                            <option value="">Sélectionnez un modèle…</option>
                            <?php foreach ($presets as $preset): ?>
                                <option value="<?php echo esc_attr($preset['id']); ?>"><?php echo esc_html($preset['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button bjlg-backup-presets__apply">Appliquer</button>
                        <button type="button" class="button button-secondary bjlg-backup-presets__save">Enregistrer la configuration</button>
                    </div>
                    <p class="description">Appliquez un modèle existant ou enregistrez votre configuration actuelle pour la réutiliser plus tard.</p>
                    <p class="bjlg-backup-presets__status" role="status" aria-live="polite"></p>
                </div>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">Contenu de la sauvegarde</th>
                            <td>
                                <div class="bjlg-field-control">
                                    <fieldset>
                                        <label><input type="checkbox" name="backup_components[]" value="db" checked> <strong>Base de données</strong> <span class="description">Toutes les tables WordPress</span></label><br>
                                        <label><input type="checkbox" name="backup_components[]" value="plugins" checked> Extensions (<code>/wp-content/plugins</code>)</label><br>
                                        <label><input type="checkbox" name="backup_components[]" value="themes" checked> Thèmes (<code>/wp-content/themes</code>)</label><br>
                                        <label><input type="checkbox" name="backup_components[]" value="uploads" checked> Médias (<code>/wp-content/uploads</code>)</label>
                                    </fieldset>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Options</th>
                            <td>
                                <div class="bjlg-field-control">
                                    <fieldset>
                                        <label for="bjlg-encrypt-backup">
                                            <input
                                                type="checkbox"
                                                id="bjlg-encrypt-backup"
                                                name="encrypt_backup"
                                                value="1"
                                                aria-describedby="bjlg-encrypt-backup-description"
                                            >
                                            Chiffrer la sauvegarde (AES-256)
                                        </label>
                                        <p id="bjlg-encrypt-backup-description" class="description">
                                            Sécurise votre fichier de sauvegarde avec un chiffrement robuste. Indispensable si vous stockez vos sauvegardes sur un service cloud tiers.
                                        </p>
                                        <br>
                                        <label for="bjlg-incremental-backup">
                                            <input
                                                type="checkbox"
                                                id="bjlg-incremental-backup"
                                                name="incremental_backup"
                                                value="1"
                                                aria-describedby="bjlg-incremental-backup-description"
                                            >
                                            Sauvegarde incrémentale
                                        </label>
                                        <p id="bjlg-incremental-backup-description" class="description">
                                            Ne sauvegarde que les fichiers modifiés depuis la dernière sauvegarde complète. Plus rapide et utilise moins d'espace disque.
                                        </p>
                                    </fieldset>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bjlg-include-patterns">Inclusions personnalisées</label></th>
                            <td>
                                <div class="bjlg-field-control">
                                    <textarea
                                        id="bjlg-include-patterns"
                                        name="include_patterns"
                                        rows="4"
                                        class="large-text code"
                                        placeholder="wp-content/uploads/2023/*&#10;wp-content/themes/mon-theme/*"
                                        aria-describedby="bjlg-include-patterns-description"
                                    ><?php echo $include_text; ?></textarea>
                                    <p id="bjlg-include-patterns-description" class="description">Un motif par ligne. Laissez vide pour inclure tous les fichiers autorisés.</p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bjlg-exclude-patterns">Exclusions</label></th>
                            <td>
                                <div class="bjlg-field-control">
                                    <textarea
                                        id="bjlg-exclude-patterns"
                                        name="exclude_patterns"
                                        rows="4"
                                        class="large-text code"
                                        placeholder="*/cache/*&#10;*.log"
                                        aria-describedby="bjlg-exclude-patterns-description"
                                    ><?php echo $exclude_text; ?></textarea>
                                    <p id="bjlg-exclude-patterns-description" class="description">Ajoutez des motifs pour ignorer certains fichiers ou répertoires. Les exclusions globales s'appliquent également.</p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Vérifications post-sauvegarde</th>
                            <td>
                                <div class="bjlg-field-control">
                                    <fieldset>
                                        <label for="bjlg-post-checks-checksum">
                                            <input type="checkbox"
                                                   id="bjlg-post-checks-checksum"
                                                   name="post_checks[]"
                                                   value="checksum"
                                                   <?php checked(!empty($post_checks['checksum'])); ?>
                                                   aria-describedby="bjlg-post-checks-checksum-description"
                                            > Vérifier l'intégrité (SHA-256)
                                        </label>
                                        <p id="bjlg-post-checks-checksum-description" class="description">Calcule un hachage du fichier pour détecter les corruptions.</p>
                                        <label for="bjlg-post-checks-dry-run">
                                            <input type="checkbox"
                                                   id="bjlg-post-checks-dry-run"
                                                   name="post_checks[]"
                                                   value="dry_run"
                                                   <?php checked(!empty($post_checks['dry_run'])); ?>
                                                   aria-describedby="bjlg-post-checks-dry-run-description"
                                            > Test de restauration à blanc
                                        </label>
                                        <p id="bjlg-post-checks-dry-run-description" class="description">Ouvre l'archive pour valider qu'elle est exploitable (non exécuté sur les fichiers chiffrés).</p>
                                    </fieldset>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Destinations secondaires</th>
                            <td>
                                <div class="bjlg-field-control">
                                    <fieldset>
                                        <?php if (!empty($destination_choices)): ?>
                                            <?php foreach ($destination_choices as $destination_id => $destination_label): ?>
                                                <label style="display:block; margin-bottom:4px;">
                                                    <input type="checkbox"
                                                           name="secondary_destinations[]"
                                                           value="<?php echo esc_attr($destination_id); ?>"
                                                           <?php checked(in_array($destination_id, $secondary_destinations, true)); ?>>
                                                <?php echo esc_html($destination_label); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="description">Aucune destination distante n'est encore configurée.</p>
                                    <?php endif; ?>
                                    <p class="description">Les destinations sélectionnées recevront la sauvegarde dans l'ordre indiqué. En cas d'échec, la suivante est tentée automatiquement.</p>
                                    </fieldset>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <button id="bjlg-create-backup" type="submit" class="button button-primary button-hero">
                        <span class="dashicons dashicons-backup" aria-hidden="true"></span> Lancer la création de la sauvegarde
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
                <h3><span class="dashicons dashicons-info" aria-hidden="true"></span> Détails techniques</h3>
                <pre id="bjlg-backup-ajax-debug" class="bjlg-log-textarea"></pre>
            </div>
        </div>
        <?php
    }

    /**
     * Section : Liste des sauvegardes
     */
    private function render_backup_list_section() {
        $schedule_collection = $this->get_schedule_settings_for_display();
        $schedules = is_array($schedule_collection['schedules']) ? $schedule_collection['schedules'] : [];
        $next_runs = is_array($schedule_collection['next_runs']) ? $schedule_collection['next_runs'] : [];
        $recurrence_labels = [
            'disabled' => 'Désactivée',
            'hourly' => 'Toutes les heures',
            'twice_daily' => 'Deux fois par jour',
            'daily' => 'Journalière',
            'weekly' => 'Hebdomadaire',
            'monthly' => 'Mensuelle',
        ];
        $schedules_json = esc_attr(wp_json_encode($schedules));
        $next_runs_json = esc_attr(wp_json_encode($next_runs));
        $timezone_string = function_exists('wp_timezone_string') ? wp_timezone_string() : '';
        if ($timezone_string === '' && function_exists('wp_timezone')) {
            $timezone_object = wp_timezone();
            if ($timezone_object instanceof \DateTimeZone) {
                $timezone_string = $timezone_object->getName();
            }
        }
        $timezone_offset = get_option('gmt_offset', 0);
        $current_timestamp = current_time('timestamp');
        ?>
        <div class="bjlg-section" id="bjlg-backup-list-section" data-default-page="1" data-default-per-page="10">
            <h2>Sauvegardes Disponibles</h2>
            <div class="bjlg-backup-toolbar">
                <div class="bjlg-toolbar-controls actions">
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
                <div class="bjlg-toolbar-summary">
                    <span class="bjlg-summary-label">Résumé :</span>
                    <div class="bjlg-summary-content" id="bjlg-backup-summary" aria-live="polite"></div>
                </div>
            </div>
            <div
                id="bjlg-schedule-overview"
                class="bjlg-schedule-overview"
                aria-live="polite"
                data-next-runs="<?php echo $next_runs_json; ?>"
                data-schedules="<?php echo $schedules_json; ?>"
            >
                <header class="bjlg-schedule-overview-header">
                    <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                    <strong>Sauvegardes planifiées</strong>
                </header>
                <div class="bjlg-schedule-overview-list">
                    <?php if (!empty($schedules)): ?>
                        <?php foreach ($schedules as $index => $schedule):
                            if (!is_array($schedule)) {
                                continue;
                            }

                            $schedule_id = isset($schedule['id']) && $schedule['id'] !== ''
                                ? (string) $schedule['id']
                                : 'schedule_' . ($index + 1);
                            $label = isset($schedule['label']) && $schedule['label'] !== ''
                                ? (string) $schedule['label']
                                : sprintf('Planification #%d', $index + 1);
                            $components = isset($schedule['components']) && is_array($schedule['components'])
                                ? $schedule['components']
                                : [];
                            $encrypt = !empty($schedule['encrypt']);
                            $incremental = !empty($schedule['incremental']);
                            $post_checks = isset($schedule['post_checks']) && is_array($schedule['post_checks'])
                                ? $schedule['post_checks']
                                : [];
                            $destinations = isset($schedule['secondary_destinations']) && is_array($schedule['secondary_destinations'])
                                ? $schedule['secondary_destinations']
                                : [];
                            $include_patterns = isset($schedule['include_patterns']) && is_array($schedule['include_patterns'])
                                ? $schedule['include_patterns']
                                : [];
                            $exclude_patterns = isset($schedule['exclude_patterns']) && is_array($schedule['exclude_patterns'])
                                ? $schedule['exclude_patterns']
                                : [];
                            $recurrence = isset($schedule['recurrence']) ? (string) $schedule['recurrence'] : 'disabled';
                            $recurrence_label = $recurrence_labels[$recurrence] ?? ucfirst($recurrence);
                            $next_run_summary = $next_runs[$schedule_id] ?? [];
                            $next_run_formatted = isset($next_run_summary['next_run_formatted']) && $next_run_summary['next_run_formatted'] !== ''
                                ? (string) $next_run_summary['next_run_formatted']
                                : 'Non planifié';
                            $next_run_relative = isset($next_run_summary['next_run_relative']) && $next_run_summary['next_run_relative'] !== ''
                                ? (string) $next_run_summary['next_run_relative']
                                : '';
                            $next_run_timestamp = isset($next_run_summary['next_run']) && $next_run_summary['next_run']
                                ? (int) $next_run_summary['next_run']
                                : null;
                            $is_active = $recurrence !== 'disabled';
                            $has_next_run = $is_active && !empty($next_run_timestamp);
                            $status_key = $is_active ? ($has_next_run ? 'active' : 'pending') : 'paused';
                            $status_labels = [
                                'active' => 'Active',
                                'pending' => 'En attente',
                                'paused' => 'En pause',
                            ];
                            $status_label = $status_labels[$status_key] ?? '—';
                            $status_class = 'bjlg-status-badge--' . $status_key;
                            $toggle_label = $is_active ? 'Mettre en pause' : 'Reprendre';
                            $summary_markup = $this->get_schedule_summary_markup(
                                $components,
                                $encrypt,
                                $incremental,
                                $post_checks,
                                $destinations,
                                $include_patterns,
                                $exclude_patterns
                            );
                            ?>
                            <article
                                class="bjlg-schedule-overview-card"
                                data-schedule-id="<?php echo esc_attr($schedule_id); ?>"
                                data-recurrence="<?php echo esc_attr($recurrence); ?>"
                                data-status="<?php echo esc_attr($status_key); ?>"
                            >
                                <header class="bjlg-schedule-overview-card__header">
                                    <h4 class="bjlg-schedule-overview-card__title"><?php echo esc_html($label); ?></h4>
                                    <p class="bjlg-schedule-overview-frequency" data-prefix="Fréquence : ">
                                        Fréquence : <?php echo esc_html($recurrence_label); ?>
                                    </p>
                                    <p class="bjlg-schedule-overview-next-run">
                                        <strong>Prochaine exécution :</strong>
                                        <span class="bjlg-next-run-value"><?php echo esc_html($next_run_formatted); ?></span>
                                        <span class="bjlg-next-run-relative"<?php echo $next_run_relative === '' ? ' style="display:none;"' : ''; ?>>
                                            <?php echo $next_run_relative !== '' ? '(' . esc_html($next_run_relative) . ')' : ''; ?>
                                        </span>
                                    </p>
                                    <p class="bjlg-schedule-overview-status">
                                        <span class="bjlg-status-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span>
                                    </p>
                                </header>
                                <div class="bjlg-schedule-overview-card__summary">
                                    <?php echo $summary_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </div>
                                <footer class="bjlg-schedule-overview-card__footer">
                                    <div class="bjlg-schedule-overview-card__actions" role="group" aria-label="Actions de planification">
                                        <button type="button"
                                                class="button button-primary button-small bjlg-schedule-action"
                                                data-action="run"
                                                data-schedule-id="<?php echo esc_attr($schedule_id); ?>">
                                            Exécuter
                                        </button>
                                        <button type="button"
                                                class="button button-secondary button-small bjlg-schedule-action"
                                                data-action="toggle"
                                                data-target-state="<?php echo $is_active ? 'pause' : 'resume'; ?>"
                                                data-schedule-id="<?php echo esc_attr($schedule_id); ?>">
                                            <?php echo esc_html($toggle_label); ?>
                                        </button>
                                        <button type="button"
                                                class="button button-secondary button-small bjlg-schedule-action"
                                                data-action="duplicate"
                                                data-schedule-id="<?php echo esc_attr($schedule_id); ?>">
                                            Dupliquer
                                        </button>
                                    </div>
                                </footer>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="description">Aucune planification active pour le moment.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div
                id="bjlg-schedule-timeline"
                class="bjlg-schedule-timeline"
                aria-live="polite"
                data-schedules="<?php echo $schedules_json; ?>"
                data-next-runs="<?php echo $next_runs_json; ?>"
                data-timezone="<?php echo esc_attr($timezone_string); ?>"
                data-offset="<?php echo esc_attr((string) $timezone_offset); ?>"
                data-now="<?php echo esc_attr((string) $current_timestamp); ?>"
            >
                <header class="bjlg-schedule-timeline__header">
                    <div class="bjlg-schedule-timeline__title">
                        <span class="dashicons dashicons-schedule" aria-hidden="true"></span>
                        <strong>Timeline des occurrences</strong>
                    </div>
                    <div class="bjlg-schedule-timeline__controls" role="group" aria-label="Changer la vue de la timeline">
                        <button type="button" class="button button-secondary is-active" data-role="timeline-view" data-view="week">Semaine</button>
                        <button type="button" class="button button-secondary" data-role="timeline-view" data-view="month">Mois</button>
                    </div>
                </header>
                <div class="bjlg-schedule-timeline__legend">
                    <span class="bjlg-status-badge bjlg-status-badge--active">Active</span>
                    <span class="bjlg-status-badge bjlg-status-badge--pending">En attente</span>
                    <span class="bjlg-status-badge bjlg-status-badge--paused">En pause</span>
                </div>
                <div class="bjlg-schedule-timeline__body">
                    <div class="bjlg-schedule-timeline__grid" data-role="timeline-grid"></div>
                    <ul class="bjlg-schedule-timeline__list" data-role="timeline-list"></ul>
                    <p class="bjlg-schedule-timeline__empty" data-role="timeline-empty" hidden>Enregistrez ou activez une planification pour afficher la timeline.</p>
                </div>
            </div>
            <div id="bjlg-backup-list-feedback" class="notice notice-error" role="alert" style="display:none;"></div>
            <table
                class="wp-list-table widefat striped bjlg-responsive-table bjlg-backup-table"
                aria-describedby="bjlg-backup-summary bjlg-backup-list-caption"
            >
                <caption id="bjlg-backup-list-caption" class="bjlg-table-caption">
                    Tableau listant les sauvegardes disponibles avec leurs composants, tailles,
                    dates et actions possibles.
                </caption>
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
                                <div class="bjlg-field-control">
                                    <input type="file" id="bjlg-restore-file-input" name="restore_file" accept=".zip,.zip.enc" required>
                                    <p class="description">Formats acceptés : .zip, .zip.enc (chiffré)</p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bjlg-restore-password">Mot de passe</label></th>
                            <td>
                                <div class="bjlg-field-control">
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
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Options</th>
                            <td>
                                <div class="bjlg-field-control">
                                    <label><input type="checkbox" name="create_backup_before_restore" value="1" checked> Créer une sauvegarde de sécurité avant la restauration</label>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div id="bjlg-restore-errors" class="notice notice-error" style="display: none;" role="alert"></div>
                <p class="submit">
                    <button type="submit" class="button button-primary"><span class="dashicons dashicons-upload" aria-hidden="true"></span> Téléverser et Restaurer</button>
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
                <h3><span class="dashicons dashicons-info" aria-hidden="true"></span> Détails techniques</h3>
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
                    <caption class="bjlg-table-caption">
                        Historique des 50 dernières actions liées aux sauvegardes et restaurations,
                        incluant leur date, statut et détails.
                    </caption>
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
            <p style="margin-top: 20px;"><button class="button" onclick="window.location.reload();"><span class="dashicons dashicons-update" aria-hidden="true"></span> Relancer les vérifications</button></p>
        </div>
        <?php
    }

    /**
     * Section : Réglages
     */
    private function render_settings_section() {
        $cleanup_settings = get_option('bjlg_cleanup_settings', ['by_number' => 3, 'by_age' => 0]);
        $incremental_defaults = [
            'max_incrementals' => 10,
            'max_full_age_days' => 30,
            'rotation_enabled' => true,
        ];
        $incremental_settings = get_option('bjlg_incremental_settings', []);
        if (!is_array($incremental_settings)) {
            $incremental_settings = [];
        }
        $incremental_settings = wp_parse_args($incremental_settings, $incremental_defaults);
        $schedule_collection = $this->get_schedule_settings_for_display();
        $schedules = isset($schedule_collection['schedules']) && is_array($schedule_collection['schedules'])
            ? array_values($schedule_collection['schedules'])
            : [];
        $next_runs = isset($schedule_collection['next_runs']) && is_array($schedule_collection['next_runs'])
            ? $schedule_collection['next_runs']
            : [];
        $default_schedule = isset($schedule_collection['default']) && is_array($schedule_collection['default'])
            ? $schedule_collection['default']
            : BJLG_Settings::get_default_schedule_entry();
        $schedules_json = esc_attr(wp_json_encode($schedules));
        $next_runs_json = esc_attr(wp_json_encode($next_runs));

        $components_labels = [
            'db' => 'Base de données',
            'plugins' => 'Extensions',
            'themes' => 'Thèmes',
            'uploads' => 'Médias',
        ];
        $default_next_run_summary = [
            'next_run_formatted' => 'Non planifié',
            'next_run_relative' => '',
        ];
        $destination_choices = $this->get_destination_choices();
        $wl_settings = get_option('bjlg_whitelabel_settings', ['plugin_name' => '', 'hide_from_non_admins' => false]);
        $required_permission = \bjlg_get_required_capability();
        $permission_choices = $this->get_permission_choices();
        $is_custom_permission = $required_permission !== ''
            && !isset($permission_choices['roles'][$required_permission])
            && !isset($permission_choices['capabilities'][$required_permission]);
        $webhook_key = class_exists(BJLG_Webhooks::class) ? BJLG_Webhooks::get_webhook_key() : '';

        $notification_defaults = [
            'enabled' => false,
            'email_recipients' => '',
            'events' => [
                'backup_complete' => true,
                'backup_failed' => true,
                'cleanup_complete' => false,
                'storage_warning' => true,
            ],
            'channels' => [
                'email' => ['enabled' => false],
                'slack' => ['enabled' => false, 'webhook_url' => ''],
                'discord' => ['enabled' => false, 'webhook_url' => ''],
            ],
        ];

        $notification_settings = get_option('bjlg_notification_settings', []);
        if (!is_array($notification_settings)) {
            $notification_settings = [];
        }
        $notification_settings = wp_parse_args($notification_settings, $notification_defaults);
        $notification_settings['events'] = isset($notification_settings['events']) && is_array($notification_settings['events'])
            ? wp_parse_args($notification_settings['events'], $notification_defaults['events'])
            : $notification_defaults['events'];
        $notification_settings['channels'] = isset($notification_settings['channels']) && is_array($notification_settings['channels'])
            ? wp_parse_args($notification_settings['channels'], $notification_defaults['channels'])
            : $notification_defaults['channels'];

        foreach ($notification_defaults['channels'] as $channel_key => $channel_defaults) {
            if (!isset($notification_settings['channels'][$channel_key]) || !is_array($notification_settings['channels'][$channel_key])) {
                $notification_settings['channels'][$channel_key] = $channel_defaults;
            } else {
                $notification_settings['channels'][$channel_key] = wp_parse_args($notification_settings['channels'][$channel_key], $channel_defaults);
            }
        }

        $notification_recipients_display = '';
        if (!empty($notification_settings['email_recipients'])) {
            $emails = preg_split('/[,;\r\n]+/', (string) $notification_settings['email_recipients']);
            if (is_array($emails)) {
                $emails = array_filter(array_map('trim', $emails));
                $notification_recipients_display = implode("\n", $emails);
            }
        }

        $performance_defaults = [
            'multi_threading' => false,
            'max_workers' => 2,
            'chunk_size' => 50,
            'compression_level' => 6,
        ];
        $performance_settings = get_option('bjlg_performance_settings', []);
        if (!is_array($performance_settings)) {
            $performance_settings = [];
        }
        $performance_settings = wp_parse_args($performance_settings, $performance_defaults);

        $webhook_defaults = [
            'enabled' => false,
            'urls' => [
                'backup_complete' => '',
                'backup_failed' => '',
                'cleanup_complete' => '',
            ],
            'secret' => '',
        ];
        $webhook_settings = get_option('bjlg_webhook_settings', []);
        if (!is_array($webhook_settings)) {
            $webhook_settings = [];
        }
        $webhook_settings = wp_parse_args($webhook_settings, $webhook_defaults);
        $webhook_settings['urls'] = isset($webhook_settings['urls']) && is_array($webhook_settings['urls'])
            ? wp_parse_args($webhook_settings['urls'], $webhook_defaults['urls'])
            : $webhook_defaults['urls'];
        ?>
        <div class="bjlg-section">
            <h2>Configuration du Plugin</h2>
            
            <h3><span class="dashicons dashicons-cloud" aria-hidden="true"></span> Destinations Cloud</h3>
            <form class="bjlg-settings-form">
                <div class="bjlg-settings-feedback notice bjlg-hidden" role="status" aria-live="polite"></div>
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
            
            <h3><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> Planification des Sauvegardes</h3>
            <form
                id="bjlg-schedule-form"
                data-default-schedule="<?php echo esc_attr(wp_json_encode($default_schedule)); ?>"
                data-next-runs="<?php echo $next_runs_json; ?>"
                data-schedules="<?php echo $schedules_json; ?>"
            >
                <div id="bjlg-schedule-feedback" class="notice" role="status" aria-live="polite" style="display:none;"></div>
                <div class="bjlg-schedule-list">
                    <?php if (!empty($schedules)): ?>
                        <?php foreach ($schedules as $index => $schedule):
                            if (!is_array($schedule)) {
                                continue;
                            }

                            $schedule_id = isset($schedule['id']) ? (string) $schedule['id'] : '';
                            $next_run_summary = $schedule_id !== '' && isset($next_runs[$schedule_id])
                                ? $next_runs[$schedule_id]
                                : $default_next_run_summary;

                            echo $this->render_schedule_item(
                                $schedule,
                                is_array($next_run_summary) ? $next_run_summary : $default_next_run_summary,
                                $components_labels,
                                $destination_choices,
                                $index,
                                false
                            );
                        endforeach; ?>
                    <?php else: ?>
                        <?php
                        echo $this->render_schedule_item(
                            $default_schedule,
                            $default_next_run_summary,
                            $components_labels,
                            $destination_choices,
                            0,
                            false
                        );
                        ?>
                    <?php endif; ?>
                    <?php
                    echo $this->render_schedule_item(
                        $default_schedule,
                        $default_next_run_summary,
                        $components_labels,
                        $destination_choices,
                        count($schedules),
                        true
                    );
                    ?>
                </div>
                <p class="bjlg-schedule-actions">
                    <button type="button" class="button button-secondary bjlg-add-schedule">
                        <span class="dashicons dashicons-plus" aria-hidden="true"></span>
                        Ajouter une planification
                    </button>
                </p>
                <p class="submit"><button type="submit" class="button button-primary">Enregistrer les planifications</button></p>
            </form>
            
            <h3><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> Webhook</h3>
            <p>Utilisez ce point de terminaison pour déclencher une sauvegarde à distance en toute sécurité :</p>
            <div class="bjlg-webhook-url bjlg-mb-10">
                <label for="bjlg-webhook-endpoint" class="bjlg-label-block bjlg-fw-600">Point de terminaison</label>
                <div class="bjlg-form-field-group">
                    <div class="bjlg-form-field-control">
                        <input type="text" id="bjlg-webhook-endpoint" readonly value="<?php echo esc_url(BJLG_Webhooks::get_webhook_endpoint()); ?>" class="regular-text code">
                    </div>
                    <div class="bjlg-form-field-actions">
                        <button class="button bjlg-copy-field" data-copy-target="#bjlg-webhook-endpoint">Copier l'URL</button>
                    </div>
                </div>
            </div>
            <div class="bjlg-webhook-url bjlg-mb-10">
                <label for="bjlg-webhook-key" class="bjlg-label-block bjlg-fw-600">Clé secrète</label>
                <div class="bjlg-form-field-group">
                    <div class="bjlg-form-field-control">
                        <input type="text" id="bjlg-webhook-key" readonly value="<?php echo esc_attr($webhook_key); ?>" class="regular-text code">
                    </div>
                    <div class="bjlg-form-field-actions">
                        <button class="button bjlg-copy-field" data-copy-target="#bjlg-webhook-key">Copier la clé</button>
                        <button class="button" id="bjlg-regenerate-webhook">Régénérer</button>
                    </div>
                </div>
            </div>
            <p class="description">Envoyez une requête <strong>POST</strong> à l'URL ci-dessus en ajoutant l'en-tête <code><?php echo esc_html(BJLG_Webhooks::WEBHOOK_HEADER); ?></code> (ou <code>Authorization: Bearer &lt;clé&gt;</code>) contenant votre clé.</p>
            <pre class="code"><code><?php echo esc_html(sprintf("curl -X POST %s \\n  -H 'Content-Type: application/json' \\n  -H '%s: %s'", BJLG_Webhooks::get_webhook_endpoint(), BJLG_Webhooks::WEBHOOK_HEADER, $webhook_key)); ?></code></pre>
            <p class="description"><strong>Compatibilité :</strong> L'ancien format <code><?php echo esc_html(add_query_arg(BJLG_Webhooks::WEBHOOK_QUERY_VAR, 'VOTRE_CLE', home_url('/'))); ?></code> reste supporté provisoirement mais sera retiré après la période de transition.</p>

            <form class="bjlg-settings-form">
                <div class="bjlg-settings-feedback notice bjlg-hidden" role="status" aria-live="polite"></div>
                <h3><span class="dashicons dashicons-trash" aria-hidden="true"></span> Rétention des Sauvegardes</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Conserver par nombre</th>
                        <td>
                            <div class="bjlg-field-control">
                                <div class="bjlg-form-field-group">
                                    <div class="bjlg-form-field-control">
                                        <input name="by_number" type="number" class="small-text" value="<?php echo esc_attr(isset($cleanup_settings['by_number']) ? $cleanup_settings['by_number'] : 3); ?>" min="0">
                                    </div>
                                    <div class="bjlg-form-field-actions">
                                        <span class="bjlg-form-field-unit">sauvegardes</span>
                                    </div>
                                </div>
                                <p class="description">0 = illimité</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bjlg-cleanup-by-age">Conserver par ancienneté</label></th>
                        <td>
                            <div class="bjlg-field-control">
                                <div class="bjlg-form-field-group">
                                    <div class="bjlg-form-field-control">
                                        <input
                                            id="bjlg-cleanup-by-age"
                                            name="by_age"
                                            type="number"
                                            class="small-text"
                                            value="<?php echo esc_attr(isset($cleanup_settings['by_age']) ? $cleanup_settings['by_age'] : 0); ?>"
                                            min="0"
                                            aria-describedby="bjlg-cleanup-by-age-description"
                                        >
                                    </div>
                                    <div class="bjlg-form-field-actions">
                                        <span class="bjlg-form-field-unit">jours</span>
                                    </div>
                                </div>
                                <p id="bjlg-cleanup-by-age-description" class="description">0 = illimité</p>
                            </div>
                        </td>
                    </tr>
                </table>

                <h3><span class="dashicons dashicons-update" aria-hidden="true"></span> Sauvegardes incrémentales</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="bjlg-incremental-max-age">Age maximal de la sauvegarde complète</label></th>
                        <td>
                            <div class="bjlg-field-control">
                                <div class="bjlg-form-field-group">
                                    <div class="bjlg-form-field-control">
                                        <input
                                            id="bjlg-incremental-max-age"
                                            name="incremental_max_age"
                                            type="number"
                                            class="small-text"
                                            value="<?php echo esc_attr($incremental_settings['max_full_age_days']); ?>"
                                            min="0"
                                            aria-describedby="bjlg-incremental-max-age-description"
                                        >
                                    </div>
                                    <div class="bjlg-form-field-actions">
                                        <span class="bjlg-form-field-unit">jours</span>
                                    </div>
                                </div>
                                <p id="bjlg-incremental-max-age-description" class="description">Au-delà de cette limite, une nouvelle sauvegarde complète est forcée. 0 = illimité.</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bjlg-incremental-max-count">Nombre d'incréments consécutifs</label></th>
                        <td>
                            <div class="bjlg-field-control">
                                <div class="bjlg-form-field-group">
                                    <div class="bjlg-form-field-control">
                                        <input
                                            id="bjlg-incremental-max-count"
                                            name="incremental_max_incrementals"
                                            type="number"
                                            class="small-text"
                                            value="<?php echo esc_attr($incremental_settings['max_incrementals']); ?>"
                                            min="0"
                                            aria-describedby="bjlg-incremental-max-count-description"
                                        >
                                    </div>
                                    <div class="bjlg-form-field-actions">
                                        <span class="bjlg-form-field-unit">incréments</span>
                                    </div>
                                </div>
                                <p id="bjlg-incremental-max-count-description" class="description">0 = illimité. Au-delà, les incréments les plus anciens sont fusionnés automatiquement.</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Rotation automatique</th>
                        <td>
                            <div class="bjlg-field-control">
                                <label>
                                    <input
                                        type="checkbox"
                                        name="incremental_rotation_enabled"
                                        value="1"
                                        <?php checked(!empty($incremental_settings['rotation_enabled'])); ?>
                                    >
                                    Activer la fusion automatique en sauvegarde synthétique («&nbsp;synth full&nbsp;»)
                                </label>
                                <p class="description">Lorsque la limite d'incréments est atteinte, les plus anciens sont fusionnés dans la dernière complète sans lancer un nouvel export complet.</p>
                            </div>
                        </td>
                    </tr>
                </table>

                <h3><span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span> Marque Blanche</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="bjlg-plugin-name">Nom du plugin</label></th>
                        <td>
                            <div class="bjlg-field-control">
                                <input
                                    type="text"
                                    id="bjlg-plugin-name"
                                    name="plugin_name"
                                    value="<?php echo esc_attr(isset($wl_settings['plugin_name']) ? $wl_settings['plugin_name'] : ''); ?>"
                                    class="regular-text"
                                    placeholder="Backup - JLG"
                                    aria-describedby="bjlg-plugin-name-description"
                                >
                                <p id="bjlg-plugin-name-description" class="description">Laissez vide pour utiliser le nom par défaut</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Visibilité</th>
                        <td>
                            <div class="bjlg-field-control">
                                <label><input type="checkbox" name="hide_from_non_admins" <?php checked(isset($wl_settings['hide_from_non_admins']) && $wl_settings['hide_from_non_admins']); ?>> Cacher le plugin pour les non-administrateurs</label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bjlg-required-capability"><?php esc_html_e('Permissions requises', 'backup-jlg'); ?></label></th>
                        <td>
                            <div class="bjlg-field-control">
                                <select id="bjlg-required-capability" name="required_capability" class="regular-text">
                                    <?php if ($is_custom_permission): ?>
                                        <option value="<?php echo esc_attr($required_permission); ?>" selected>
                                            <?php echo esc_html(sprintf(__('Personnalisé : %s', 'backup-jlg'), $required_permission)); ?>
                                        </option>
                                    <?php endif; ?>
                                    <?php if (!empty($permission_choices['roles'])): ?>
                                        <optgroup label="<?php esc_attr_e('Rôles', 'backup-jlg'); ?>">
                                            <?php foreach ($permission_choices['roles'] as $role_key => $role_label): ?>
                                                <option value="<?php echo esc_attr($role_key); ?>" <?php selected($required_permission, $role_key); ?>>
                                                    <?php echo esc_html($role_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                    <?php if (!empty($permission_choices['capabilities'])): ?>
                                        <optgroup label="<?php esc_attr_e('Capacités', 'backup-jlg'); ?>">
                                            <?php foreach ($permission_choices['capabilities'] as $capability_key => $capability_label): ?>
                                                <option value="<?php echo esc_attr($capability_key); ?>" <?php selected($required_permission, $capability_key); ?>>
                                                    <?php echo esc_html($capability_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Sélectionnez le rôle ou la capability requis pour accéder au plugin.', 'backup-jlg'); ?></p>
                            </div>
                        </td>
                    </tr>
                </table>

                <p class="submit"><button type="submit" class="button button-primary">Enregistrer les Réglages</button></p>
            </form>

            <h3><span class="dashicons dashicons-megaphone" aria-hidden="true"></span> Notifications</h3>
            <form class="bjlg-settings-form" data-success-message="Notifications mises à jour." data-error-message="Impossible de sauvegarder les notifications.">
                <table class="form-table">
                    <tr>
                        <th scope="row">Notifications automatiques</th>
                        <td>
                            <div class="bjlg-field-control">
                                <label for="bjlg-notifications-enabled">
                                    <input
                                        type="checkbox"
                                        id="bjlg-notifications-enabled"
                                        name="notifications_enabled"
                                        <?php checked(!empty($notification_settings['enabled'])); ?>
                                        aria-describedby="bjlg-notifications-enabled-description"
                                    >
                                    Activer l'envoi automatique des notifications
                                </label>
                                <p id="bjlg-notifications-enabled-description" class="description">Recevez des alertes lorsqu'une action importante est exécutée.</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bjlg-notification-recipients">Destinataires e-mail</label></th>
                        <td>
                            <div class="bjlg-field-control">
                                <textarea
                                    id="bjlg-notification-recipients"
                                    name="email_recipients"
                                    rows="3"
                                    class="large-text code"
                                    placeholder="admin@example.com&#10;contact@example.com"
                                    aria-describedby="bjlg-notification-recipients-description"
                                ><?php echo esc_textarea($notification_recipients_display); ?></textarea>
                                <p id="bjlg-notification-recipients-description" class="description">Une adresse par ligne ou séparée par une virgule. Obligatoire si le canal e-mail est activé.</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Événements surveillés</th>
                        <td>
                            <div class="bjlg-field-control">
                                <fieldset>
                                    <label><input type="checkbox" name="notify_backup_complete" <?php checked(!empty($notification_settings['events']['backup_complete'])); ?>> Sauvegarde terminée</label><br>
                                    <label><input type="checkbox" name="notify_backup_failed" <?php checked(!empty($notification_settings['events']['backup_failed'])); ?>> Échec de sauvegarde</label><br>
                                    <label><input type="checkbox" name="notify_cleanup_complete" <?php checked(!empty($notification_settings['events']['cleanup_complete'])); ?>> Nettoyage finalisé</label><br>
                                    <label><input type="checkbox" name="notify_storage_warning" <?php checked(!empty($notification_settings['events']['storage_warning'])); ?>> Alerte de stockage</label>
                                </fieldset>
                                <p class="description">Choisissez quels événements déclenchent un envoi de notification.</p>
                            </div>
                        </td>
                    </tr>
                </table>

                <p class="submit"><button type="submit" class="button button-primary">Enregistrer les notifications</button></p>
            </form>

            <h3><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span> Canaux</h3>
            <form class="bjlg-settings-form" data-success-message="Canaux mis à jour." data-error-message="Impossible de mettre à jour les canaux.">
                <table class="form-table">
                    <tr>
                        <th scope="row">Canaux disponibles</th>
                        <td>
                            <div class="bjlg-field-control">
                                <fieldset>
                                    <label><input type="checkbox" name="channel_email" <?php checked(!empty($notification_settings['channels']['email']['enabled'])); ?>> E-mail</label><br>
                                    <label><input type="checkbox" name="channel_slack" <?php checked(!empty($notification_settings['channels']['slack']['enabled'])); ?>> Slack</label><br>
                                    <label><input type="checkbox" name="channel_discord" <?php checked(!empty($notification_settings['channels']['discord']['enabled'])); ?>> Discord</label>
                                </fieldset>
                                <p class="description">Activez les canaux qui doivent recevoir vos notifications.</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Webhook Slack</th>
                        <td>
                            <div class="bjlg-field-control">
                                <input type="url" name="slack_webhook_url" class="regular-text" value="<?php echo esc_attr($notification_settings['channels']['slack']['webhook_url']); ?>" placeholder="https://hooks.slack.com/...">
                                <p class="description">URL du webhook entrant Slack. Obligatoire si le canal Slack est activé.</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Webhook Discord</th>
                        <td>
                            <div class="bjlg-field-control">
                                <input type="url" name="discord_webhook_url" class="regular-text" value="<?php echo esc_attr($notification_settings['channels']['discord']['webhook_url']); ?>" placeholder="https://discord.com/api/webhooks/...">
                                <p class="description">URL du webhook Discord. Obligatoire si le canal Discord est activé.</p>
                            </div>
                        </td>
                    </tr>
                </table>

                <h4>Webhooks personnalisés</h4>
                <table class="form-table">
                    <tr>
                        <th scope="row">Activation</th>
                        <td>
                            <div class="bjlg-field-control">
                                <label><input type="checkbox" name="webhook_enabled" <?php checked(!empty($webhook_settings['enabled'])); ?>> Activer les webhooks personnalisés</label>
                                <p class="description">Déclenche des requêtes HTTP sortantes vers vos intégrations.</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Sauvegarde terminée</th>
                        <td>
                            <div class="bjlg-field-control">
                                <input type="url" name="webhook_backup_complete" class="regular-text" value="<?php echo esc_attr($webhook_settings['urls']['backup_complete']); ?>" placeholder="https://exemple.com/webhooks/backup-success">
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Sauvegarde échouée</th>
                        <td>
                            <div class="bjlg-field-control">
                                <input type="url" name="webhook_backup_failed" class="regular-text" value="<?php echo esc_attr($webhook_settings['urls']['backup_failed']); ?>" placeholder="https://exemple.com/webhooks/backup-failed">
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Nettoyage terminé</th>
                        <td>
                            <div class="bjlg-field-control">
                                <input type="url" name="webhook_cleanup_complete" class="regular-text" value="<?php echo esc_attr($webhook_settings['urls']['cleanup_complete']); ?>" placeholder="https://exemple.com/webhooks/cleanup">
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Clé secrète</th>
                        <td>
                            <div class="bjlg-field-control">
                                <input type="text" name="webhook_secret" class="regular-text" value="<?php echo esc_attr($webhook_settings['secret']); ?>" placeholder="signature partagée">
                                <p class="description">Optionnel : transmis dans l'entête <code>X-BJLG-Webhook-Secret</code>.</p>
                            </div>
                        </td>
                    </tr>
                </table>

                <p class="submit"><button type="submit" class="button button-primary">Enregistrer les canaux</button></p>
            </form>

            <h3><span class="dashicons dashicons-performance" aria-hidden="true"></span> Performance</h3>
            <form class="bjlg-settings-form" data-success-message="Paramètres de performance sauvegardés." data-error-message="Impossible de sauvegarder la configuration de performance.">
                <table class="form-table">
                    <tr>
                        <th scope="row">Traitement parallèle</th>
                        <td>
                            <div class="bjlg-field-control">
                                <label><input type="checkbox" name="multi_threading" <?php checked(!empty($performance_settings['multi_threading'])); ?>> Activer le multi-threading</label>
                                <p class="description">Permet de répartir certaines opérations sur plusieurs travailleurs.</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Travailleurs maximum</th>
                        <td>
                            <div class="bjlg-form-field-group">
                                <div class="bjlg-form-field-control">
                                    <input type="number" name="max_workers" class="small-text" value="<?php echo esc_attr($performance_settings['max_workers']); ?>" min="1" max="20">
                                </div>
                                <div class="bjlg-form-field-actions">
                                    <span class="bjlg-form-field-unit">processus</span>
                                </div>
                            </div>
                            <p class="description">Limite la charge sur votre hébergement.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Taille des blocs</th>
                        <td>
                            <div class="bjlg-form-field-group">
                                <div class="bjlg-form-field-control">
                                    <input type="number" name="chunk_size" class="small-text" value="<?php echo esc_attr($performance_settings['chunk_size']); ?>" min="1" max="500">
                                </div>
                                <div class="bjlg-form-field-actions">
                                    <span class="bjlg-form-field-unit">Mo</span>
                                </div>
                            </div>
                            <p class="description">Ajustez la taille des blocs traités pour optimiser le débit.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Niveau de compression</th>
                        <td>
                            <div class="bjlg-form-field-group">
                                <div class="bjlg-form-field-control">
                                    <input type="number" name="compression_level" class="small-text" value="<?php echo esc_attr($performance_settings['compression_level']); ?>" min="0" max="9">
                                </div>
                                <div class="bjlg-form-field-actions">
                                    <span class="bjlg-form-field-unit">0-9</span>
                                </div>
                            </div>
                            <p class="description">0 = aucune compression, 9 = compression maximale (plus lent).</p>
                        </td>
                    </tr>
                </table>

                <p class="submit"><button type="submit" class="button button-primary">Enregistrer les performances</button></p>
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
                    <span class="dashicons dashicons-download" aria-hidden="true"></span> Créer un pack de support
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
                <div class="bjlg-form-field-group">
                    <div class="bjlg-form-field-control">
                        <label for="bjlg-api-key-label" class="screen-reader-text">Nom de la clé API</label>
                        <input type="text" id="bjlg-api-key-label" name="label" class="regular-text"
                               placeholder="Ex. : CRM Marketing" autocomplete="off" />
                    </div>
                    <div class="bjlg-form-field-actions">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-plus" aria-hidden="true"></span> Générer une clé API
                        </button>
                    </div>
                </div>
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
                    <?php
                    $is_hidden = !empty($key['is_secret_hidden']);
                    $secret_value = isset($key['display_secret']) ? (string) $key['display_secret'] : '';
                    $secret_classes = 'bjlg-api-key-value';

                    if ($is_hidden) {
                        $secret_classes .= ' bjlg-api-key-value--hidden';
                    }

                    $masked_value = isset($key['masked_secret']) ? (string) $key['masked_secret'] : __('Clé masquée', 'backup-jlg');
                    $secret_value = $secret_value !== '' ? $secret_value : $masked_value;
                    ?>
                    <tr data-key-id="<?php echo esc_attr($key['id']); ?>"
                        data-created-at="<?php echo esc_attr($key['created_at']); ?>"
                        data-last-rotated-at="<?php echo esc_attr($key['last_rotated_at']); ?>"
                        data-secret-hidden="<?php echo $is_hidden ? '1' : '0'; ?>">
                        <td>
                            <strong class="bjlg-api-key-label"><?php echo esc_html($key['label']); ?></strong>
                        </td>
                        <td>
                            <code class="<?php echo esc_attr($secret_classes); ?>" aria-label="<?php echo esc_attr($is_hidden ? __('Clé API masquée', 'backup-jlg') : __('Clé API', 'backup-jlg')); ?>">
                                <?php echo esc_html($secret_value); ?>
                            </code>
                            <?php if ($is_hidden): ?>
                                <span class="bjlg-api-key-hidden-note"><?php esc_html_e('Secret masqué. Régénérez la clé pour obtenir un nouveau secret.', 'backup-jlg'); ?></span>
                            <?php endif; ?>
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
                                    <span class="dashicons dashicons-update" aria-hidden="true"></span> Régénérer
                                </button>
                                <button type="button" class="button button-link-delete bjlg-revoke-api-key" data-key-id="<?php echo esc_attr($key['id']); ?>">
                                    <span class="dashicons dashicons-no" aria-hidden="true"></span> Révoquer
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

    private function get_permission_choices() {
        $roles = [];
        $capabilities = [];

        $wp_roles = function_exists('wp_roles') ? wp_roles() : null;

        if ($wp_roles && class_exists('WP_Roles') && $wp_roles instanceof \WP_Roles) {
            foreach ($wp_roles->roles as $role_key => $role_details) {
                $label = isset($role_details['name']) ? (string) $role_details['name'] : $role_key;
                if (function_exists('translate_user_role')) {
                    $label = translate_user_role($label);
                }
                $roles[$role_key] = $label;

                if (!empty($role_details['capabilities']) && is_array($role_details['capabilities'])) {
                    foreach ($role_details['capabilities'] as $capability => $granted) {
                        if ($granted) {
                            $capabilities[$capability] = $capability;
                        }
                    }
                }
            }
        }

        ksort($roles);
        ksort($capabilities);

        $sanitize = static function ($items) {
            $result = [];
            if (!is_array($items)) {
                return $result;
            }

            foreach ($items as $key => $label) {
                if (!is_string($key) || $key === '') {
                    continue;
                }

                $result[$key] = is_string($label) && $label !== '' ? $label : $key;
            }

            return $result;
        };

        $choices = [
            'roles' => $sanitize($roles),
            'capabilities' => $sanitize($capabilities),
        ];

        /** @var array<string, array<string, string>>|null $filtered */
        $filtered = apply_filters('bjlg_required_capability_choices', $choices);
        if (is_array($filtered)) {
            $roles_filtered = $sanitize($filtered['roles'] ?? $choices['roles']);
            $caps_filtered = $sanitize($filtered['capabilities'] ?? $choices['capabilities']);

            return [
                'roles' => $roles_filtered,
                'capabilities' => $caps_filtered,
            ];
        }

        return $choices;
    }

    private function get_destination_choices() {
        $choices = [];

        if (!empty($this->destinations)) {
            foreach ($this->destinations as $id => $destination) {
                if (is_object($destination) && method_exists($destination, 'get_name')) {
                    $choices[$id] = $destination->get_name();
                }
            }
        }

        if (empty($choices)) {
            $choices = [
                'google_drive' => 'Google Drive',
                'aws_s3' => 'Amazon S3',
                'dropbox' => 'Dropbox',
                'onedrive' => 'Microsoft OneDrive',
                'pcloud' => 'pCloud',
                'sftp' => 'Serveur SFTP',
                'wasabi' => 'Wasabi',
            ];
        }

        /** @var array<string, string>|false $filtered */
        $filtered = apply_filters('bjlg_admin_destination_choices', $choices, $this->destinations);
        if (is_array($filtered) && !empty($filtered)) {
            $normalized = [];
            foreach ($filtered as $key => $label) {
                if (!is_scalar($key)) {
                    continue;
                }
                $slug = sanitize_key((string) $key);
                if ($slug === '') {
                    continue;
                }
                $normalized[$slug] = (string) $label;
            }

            if (!empty($normalized)) {
                return $normalized;
            }
        }

        return $choices;
    }

    private function get_schedule_settings_for_display() {
        $default_schedule = BJLG_Settings::get_default_schedule_entry();
        $default_schedule['id'] = '';
        $default_schedule['label'] = 'Nouvelle planification';

        $schedules = [];
        $next_runs = [];

        if (class_exists(BJLG_Scheduler::class)) {
            $scheduler = BJLG_Scheduler::instance();
            if ($scheduler && method_exists($scheduler, 'get_schedule_settings')) {
                $collection = $scheduler->get_schedule_settings();
                if (is_array($collection) && isset($collection['schedules']) && is_array($collection['schedules'])) {
                    $schedules = $collection['schedules'];
                }

                if ($scheduler && method_exists($scheduler, 'get_next_runs_summary')) {
                    $next_runs = $scheduler->get_next_runs_summary($schedules);
                }
            }
        }

        if (empty($schedules)) {
            $stored = get_option('bjlg_schedule_settings', []);
            $collection = BJLG_Settings::sanitize_schedule_collection($stored);
            $schedules = $collection['schedules'];
        }

        if (empty($next_runs)) {
            foreach ($schedules as $schedule) {
                if (!is_array($schedule) || empty($schedule['id'])) {
                    continue;
                }
                $next_runs[$schedule['id']] = [
                    'id' => $schedule['id'],
                    'label' => $schedule['label'] ?? $schedule['id'],
                    'recurrence' => $schedule['recurrence'] ?? 'disabled',
                    'enabled' => ($schedule['recurrence'] ?? 'disabled') !== 'disabled',
                    'next_run' => null,
                    'next_run_formatted' => 'Non planifié',
                    'next_run_relative' => null,
                ];
            }
        }

        if (empty($schedules)) {
            $schedules = [$default_schedule];
        }

        return [
            'schedules' => $schedules,
            'next_runs' => $next_runs,
            'default' => $default_schedule,
        ];
    }

    private function get_schedule_summary_markup(
        array $components,
        $encrypt,
        $incremental,
        array $post_checks = [],
        array $destinations = [],
        array $include_patterns = [],
        array $exclude_patterns = []
    ) {
        $component_config = [
            'db' => ['label' => 'Base de données', 'color_class' => 'bjlg-badge-bg-indigo'],
            'plugins' => ['label' => 'Extensions', 'color_class' => 'bjlg-badge-bg-amber'],
            'themes' => ['label' => 'Thèmes', 'color_class' => 'bjlg-badge-bg-emerald'],
            'uploads' => ['label' => 'Médias', 'color_class' => 'bjlg-badge-bg-blue'],
        ];

        $component_badges = [];
        foreach ($components as $component) {
            if (isset($component_config[$component])) {
                $component_badges[] = $this->format_schedule_badge(
                    $component_config[$component]['label'],
                    $component_config[$component]['color_class'],
                    'bjlg-badge-component bjlg-badge-component-' . $component
                );
            }
        }

        if (empty($component_badges)) {
            $component_badges[] = '<span class="description">Aucun composant sélectionné</span>';
        }

        $option_badges = [];
        $option_badges[] = $this->format_schedule_badge(
            $encrypt ? 'Chiffrée' : 'Non chiffrée',
            $encrypt ? 'bjlg-badge-bg-purple' : 'bjlg-badge-bg-slate',
            'bjlg-badge-encrypted ' . ($encrypt ? 'bjlg-badge-state-on' : 'bjlg-badge-state-off')
        );
        $option_badges[] = $this->format_schedule_badge(
            $incremental ? 'Incrémentale' : 'Complète',
            $incremental ? 'bjlg-badge-bg-cobalt' : 'bjlg-badge-bg-gray',
            'bjlg-badge-incremental ' . ($incremental ? 'bjlg-badge-state-on' : 'bjlg-badge-state-off')
        );

        $include_badges = [];
        $include_count = count(array_filter($include_patterns, static function ($value) {
            return is_string($value) && trim($value) !== '';
        }));
        if ($include_count > 0) {
            $include_badges[] = $this->format_schedule_badge(
                sprintf('%d motif(s)', $include_count),
                'bjlg-badge-bg-sky',
                'bjlg-badge-include bjlg-badge-include-count'
            );
        } else {
            $include_badges[] = $this->format_schedule_badge('Tout le contenu', 'bjlg-badge-bg-emerald', 'bjlg-badge-include bjlg-badge-include-all');
        }

        $exclude_badges = [];
        $exclude_count = count(array_filter($exclude_patterns, static function ($value) {
            return is_string($value) && trim($value) !== '';
        }));
        if ($exclude_count > 0) {
            $exclude_badges[] = $this->format_schedule_badge(
                sprintf('%d exclusion(s)', $exclude_count),
                'bjlg-badge-bg-orange',
                'bjlg-badge-exclude bjlg-badge-exclude-count'
            );
        } else {
            $exclude_badges[] = $this->format_schedule_badge('Aucune', 'bjlg-badge-bg-slate', 'bjlg-badge-exclude bjlg-badge-exclude-none');
        }

        $control_badges = [];
        $checksum_enabled = !empty($post_checks['checksum']);
        $dry_run_enabled = !empty($post_checks['dry_run']);

        if ($checksum_enabled) {
            $control_badges[] = $this->format_schedule_badge('Checksum', 'bjlg-badge-bg-cobalt', 'bjlg-badge-checksum');
        }
        if ($dry_run_enabled) {
            $control_badges[] = $this->format_schedule_badge('Test restauration', 'bjlg-badge-bg-purple', 'bjlg-badge-restore');
        }
        if (empty($control_badges)) {
            $control_badges[] = $this->format_schedule_badge('Aucun contrôle', 'bjlg-badge-bg-slate', 'bjlg-badge-control');
        }

        $destination_badges = [];
        $available_destinations = $this->get_destination_choices();
        foreach ($destinations as $destination_id) {
            $label = $available_destinations[$destination_id] ?? ucfirst(str_replace('_', ' ', (string) $destination_id));
            $destination_badges[] = $this->format_schedule_badge($label, 'bjlg-badge-bg-sky', 'bjlg-badge-destination');
        }
        if (empty($destination_badges)) {
            $destination_badges[] = $this->format_schedule_badge('Stockage local', 'bjlg-badge-bg-slate', 'bjlg-badge-destination');
        }

        return $this->wrap_schedule_badge_group('Composants', $component_badges)
            . $this->wrap_schedule_badge_group('Options', $option_badges)
            . $this->wrap_schedule_badge_group('Inclusions', $include_badges)
            . $this->wrap_schedule_badge_group('Exclusions', $exclude_badges)
            . $this->wrap_schedule_badge_group('Contrôles', $control_badges)
            . $this->wrap_schedule_badge_group('Destinations', $destination_badges);
    }

    private function render_schedule_item(
        array $schedule,
        array $next_run_summary,
        array $components_labels,
        array $destination_choices,
        int $index,
        bool $is_template = false
    ) {
        $schedule_id = isset($schedule['id']) ? (string) $schedule['id'] : '';
        $label = isset($schedule['label']) ? (string) $schedule['label'] : '';
        if ($label === '' && !$is_template) {
            $label = sprintf('Planification #%d', $index + 1);
        }

        $recurrence = isset($schedule['recurrence']) ? (string) $schedule['recurrence'] : 'disabled';
        $day = isset($schedule['day']) ? (string) $schedule['day'] : 'sunday';
        $time = isset($schedule['time']) ? (string) $schedule['time'] : '23:59';
        $previous_recurrence = isset($schedule['previous_recurrence']) ? (string) $schedule['previous_recurrence'] : '';

        $schedule_components = isset($schedule['components']) && is_array($schedule['components']) ? $schedule['components'] : [];
        $include_patterns = isset($schedule['include_patterns']) && is_array($schedule['include_patterns']) ? $schedule['include_patterns'] : [];
        $exclude_patterns = isset($schedule['exclude_patterns']) && is_array($schedule['exclude_patterns']) ? $schedule['exclude_patterns'] : [];
        $post_checks = isset($schedule['post_checks']) && is_array($schedule['post_checks']) ? $schedule['post_checks'] : [];
        $secondary_destinations = isset($schedule['secondary_destinations']) && is_array($schedule['secondary_destinations'])
            ? $schedule['secondary_destinations']
            : [];

        $encrypt_enabled = !empty($schedule['encrypt']);
        $incremental_enabled = !empty($schedule['incremental']);

        $include_text = esc_textarea(implode("\n", array_map('strval', $include_patterns)));
        $exclude_text = esc_textarea(implode("\n", array_map('strval', $exclude_patterns)));

        $weekly_hidden = $recurrence !== 'weekly';
        $time_hidden = $recurrence === 'disabled';
        $weekly_classes = 'bjlg-schedule-weekly-options' . ($weekly_hidden ? ' bjlg-hidden' : '');
        $time_classes = 'bjlg-schedule-time-options' . ($time_hidden ? ' bjlg-hidden' : '');

        $next_run_text = isset($next_run_summary['next_run_formatted']) && $next_run_summary['next_run_formatted'] !== ''
            ? $next_run_summary['next_run_formatted']
            : 'Non planifié';
        $next_run_relative = isset($next_run_summary['next_run_relative']) && $next_run_summary['next_run_relative'] !== ''
            ? $next_run_summary['next_run_relative']
            : '';

        $field_prefix = $schedule_id !== '' ? $schedule_id : 'schedule_' . ($index + 1);
        if ($is_template) {
            $field_prefix = '__index__';
        }

        $label_id = 'bjlg-schedule-label-' . $field_prefix;
        $time_id_template = 'bjlg-schedule-time-%s';
        $time_description_id_template = 'bjlg-schedule-time-%s-description';
        $include_id_template = 'bjlg-schedule-include-%s';
        $exclude_id_template = 'bjlg-schedule-exclude-%s';
        $time_id = sprintf($time_id_template, $field_prefix);
        $time_description_id = sprintf($time_description_id_template, $field_prefix);
        $include_id = sprintf($include_id_template, $field_prefix);
        $exclude_id = sprintf($exclude_id_template, $field_prefix);

        $summary_html = $this->get_schedule_summary_markup(
            $schedule_components,
            $encrypt_enabled,
            $incremental_enabled,
            $post_checks,
            $secondary_destinations,
            $include_patterns,
            $exclude_patterns
        );

        $classes = ['bjlg-schedule-item'];
        if ($is_template) {
            $classes[] = 'bjlg-schedule-item--template';
        }

        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>"
             data-schedule-id="<?php echo esc_attr($schedule_id); ?>"
             <?php echo $is_template ? "data-template='true' style='display:none;'" : ''; ?>>
            <input type="hidden" data-field="id" value="<?php echo esc_attr($schedule_id); ?>">
            <input type="hidden"
                   data-field="previous_recurrence"
                   name="schedules[<?php echo esc_attr($field_prefix); ?>][previous_recurrence]"
                   value="<?php echo esc_attr($previous_recurrence); ?>">
            <header class="bjlg-schedule-item__header">
                <div class="bjlg-schedule-item__title">
                    <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                    <label class="screen-reader-text" for="<?php echo esc_attr($label_id); ?>" data-for-template="bjlg-schedule-label-%s">Nom de la planification</label>
                    <input type="text"
                           id="<?php echo esc_attr($label_id); ?>"
                           class="regular-text"
                           data-field="label"
                           value="<?php echo esc_attr($label); ?>"
                           data-id-template="bjlg-schedule-label-%s"
                           placeholder="Nom de la planification">
                </div>
                <div class="bjlg-schedule-item__meta">
                    <p class="description bjlg-schedule-next-run" data-field="next_run_display">
                        <strong>Prochaine exécution :</strong>
                        <span class="bjlg-next-run-value"><?php echo esc_html($next_run_text); ?></span>
                        <?php if ($next_run_relative !== ''): ?>
                            <span class="bjlg-next-run-relative">(<?php echo esc_html($next_run_relative); ?>)</span>
                        <?php endif; ?>
                    </p>
                    <button type="button" class="button-link-delete bjlg-remove-schedule"<?php echo $is_template ? ' disabled' : ''; ?>>Supprimer</button>
                </div>
            </header>
            <div class="bjlg-schedule-item__body">
                <table class="form-table">
                    <tr>
                        <th scope="row">Fréquence</th>
                        <td>
                            <select data-field="recurrence" name="schedules[<?php echo esc_attr($field_prefix); ?>][recurrence]">
                                <option value="disabled" <?php selected($recurrence, 'disabled'); ?>>Désactivée</option>
                                <option value="hourly" <?php selected($recurrence, 'hourly'); ?>>Toutes les heures</option>
                                <option value="twice_daily" <?php selected($recurrence, 'twice_daily'); ?>>Deux fois par jour</option>
                                <option value="daily" <?php selected($recurrence, 'daily'); ?>>Journalière</option>
                                <option value="weekly" <?php selected($recurrence, 'weekly'); ?>>Hebdomadaire</option>
                                <option value="monthly" <?php selected($recurrence, 'monthly'); ?>>Mensuelle</option>
                            </select>
                        </td>
                    </tr>
                    <tr class="<?php echo esc_attr($weekly_classes); ?>" aria-hidden="<?php echo esc_attr($weekly_hidden ? 'true' : 'false'); ?>">
                        <th scope="row">Jour de la semaine</th>
                        <td>
                            <select data-field="day" name="schedules[<?php echo esc_attr($field_prefix); ?>][day]">
                                <?php $days = ['monday' => 'Lundi', 'tuesday' => 'Mardi', 'wednesday' => 'Mercredi', 'thursday' => 'Jeudi', 'friday' => 'Vendredi', 'saturday' => 'Samedi', 'sunday' => 'Dimanche'];
                                foreach ($days as $day_key => $day_name): ?>
                                    <option value="<?php echo esc_attr($day_key); ?>" <?php selected($day, $day_key); ?>><?php echo esc_html($day_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr class="<?php echo esc_attr($time_classes); ?>" aria-hidden="<?php echo esc_attr($time_hidden ? 'true' : 'false'); ?>">
                        <th scope="row"><label for="<?php echo esc_attr($time_id); ?>" data-for-template="bjlg-schedule-time-%s">Heure</label></th>
                        <td>
                            <input type="time"
                                   id="<?php echo esc_attr($time_id); ?>"
                                   data-field="time"
                                   data-id-template="bjlg-schedule-time-%s"
                                   data-describedby-template="bjlg-schedule-time-%s-description"
                                   name="schedules[<?php echo esc_attr($field_prefix); ?>][time]"
                                   value="<?php echo esc_attr($time); ?>"
                                   aria-describedby="<?php echo esc_attr($time_description_id); ?>">
                            <p id="<?php echo esc_attr($time_description_id); ?>" class="description" data-id-template="bjlg-schedule-time-%s-description">Heure locale du site</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Composants</th>
                        <td>
                            <fieldset>
                                <?php foreach ($components_labels as $component_key => $component_label): ?>
                                    <label class="bjlg-label-block bjlg-mb-4">
                                        <input type="checkbox"
                                               data-field="components"
                                               name="schedules[<?php echo esc_attr($field_prefix); ?>][components][]"
                                               value="<?php echo esc_attr($component_key); ?>"
                                               <?php checked(in_array($component_key, $schedule_components, true)); ?>>
                                        <?php if ($component_key === 'db'): ?>
                                            <strong><?php echo esc_html($component_label); ?></strong>
                                            <span class="description">Toutes les tables WordPress</span>
                                        <?php else: ?>
                                            <?php echo esc_html($component_label); ?>
                                        <?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Options</th>
                        <td>
                            <label class="bjlg-label-block">
                                <input type="checkbox"
                                       data-field="encrypt"
                                       name="schedules[<?php echo esc_attr($field_prefix); ?>][encrypt]"
                                       value="1" <?php checked($encrypt_enabled); ?>>
                                Chiffrer la sauvegarde
                            </label>
                            <label class="bjlg-label-block">
                                <input type="checkbox"
                                       data-field="incremental"
                                       name="schedules[<?php echo esc_attr($field_prefix); ?>][incremental]"
                                       value="1" <?php checked($incremental_enabled); ?>>
                                Sauvegarde incrémentale
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($include_id); ?>" data-for-template="bjlg-schedule-include-%s">Inclusions</label></th>
                        <td>
                            <textarea rows="3"
                                      class="large-text code"
                                      data-field="include_patterns"
                                      id="<?php echo esc_attr($include_id); ?>"
                                      data-id-template="bjlg-schedule-include-%s"
                                      name="schedules[<?php echo esc_attr($field_prefix); ?>][include_patterns]"
                                      placeholder="wp-content/uploads/*&#10;wp-content/themes/mon-theme/*"><?php echo $include_text; ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($exclude_id); ?>" data-for-template="bjlg-schedule-exclude-%s">Exclusions</label></th>
                        <td>
                            <textarea rows="3"
                                      class="large-text code"
                                      data-field="exclude_patterns"
                                      id="<?php echo esc_attr($exclude_id); ?>"
                                      data-id-template="bjlg-schedule-exclude-%s"
                                      name="schedules[<?php echo esc_attr($field_prefix); ?>][exclude_patterns]"
                                      placeholder="*/cache/*&#10;*.tmp"><?php echo $exclude_text; ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Contrôles post-sauvegarde</th>
                        <td>
                            <label class="bjlg-label-block">
                                <input type="checkbox"
                                       data-field="post_checks"
                                       name="schedules[<?php echo esc_attr($field_prefix); ?>][post_checks][]"
                                       value="checksum" <?php checked(!empty($post_checks['checksum'])); ?>>
                                Vérification checksum
                            </label>
                            <label class="bjlg-label-block">
                                <input type="checkbox"
                                       data-field="post_checks"
                                       name="schedules[<?php echo esc_attr($field_prefix); ?>][post_checks][]"
                                       value="dry_run" <?php checked(!empty($post_checks['dry_run'])); ?>>
                                Test de restauration
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Destinations secondaires</th>
                        <td>
                            <?php if (!empty($destination_choices)): ?>
                                <?php foreach ($destination_choices as $destination_id => $destination_label): ?>
                                    <label class="bjlg-label-block">
                                        <input type="checkbox"
                                               data-field="secondary_destinations"
                                               name="schedules[<?php echo esc_attr($field_prefix); ?>][secondary_destinations][]"
                                               value="<?php echo esc_attr($destination_id); ?>"
                                               <?php checked(in_array($destination_id, $secondary_destinations, true)); ?>>
                                        <?php echo esc_html($destination_label); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description">En cas d'échec de la première destination, les suivantes seront tentées.</p>
                            <?php else: ?>
                                <p class="description">Aucune destination distante disponible.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Résumé</th>
                        <td>
                            <div class="bjlg-schedule-summary" data-field="summary" aria-live="polite">
                                <?php echo $summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                        </td>
                    </tr>
                </table>
                <p class="bjlg-schedule-inline-actions">
                    <button type="button" class="button button-secondary bjlg-run-schedule-now"<?php echo $is_template ? ' disabled' : ''; ?>>Exécuter maintenant</button>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function wrap_schedule_badge_group($title, array $badges) {
        return sprintf(
            '<div class="bjlg-badge-group"><strong class="bjlg-badge-group-title">%s :</strong>%s</div>',
            esc_html($title),
            implode('', $badges)
        );
    }

    private function format_schedule_badge($label, $color_class, $extra_class = '') {
        $classes = ['bjlg-badge'];

        if (is_string($color_class) && $color_class !== '') {
            $classes[] = $color_class;
        } else {
            $classes[] = 'bjlg-badge-bg-slate';
        }

        if (!empty($extra_class)) {
            $classes[] = $extra_class;
        }

        return sprintf(
            '<span class="%s">%s</span>',
            esc_attr(implode(' ', $classes)),
            esc_html($label)
        );
    }
}
