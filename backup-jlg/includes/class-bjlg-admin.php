<?php
namespace BJLG;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gère la création et l'affichage de l'interface d'administration du plugin.
 */
class BJLG_Admin {

    private const DASHBOARD_RECENT_BACKUPS_LIMIT = 3;

    private const ONBOARDING_PROGRESS_META_KEY = 'bjlg_onboarding_progress';

    private $destinations = [];
    private $advanced_admin;
    private $google_drive_notice;
    private $onboarding_progress = [];

    public function __construct() {
        $this->load_destinations();
        $this->advanced_admin = class_exists(BJLG_Admin_Advanced::class) ? new BJLG_Admin_Advanced() : null;
        $this->onboarding_progress = $this->get_user_onboarding_progress();
        add_action('admin_menu', [$this, 'create_admin_page']);
        add_filter('bjlg_admin_tabs', [$this, 'get_default_tabs']);
        add_filter('bjlg_admin_sections', [$this, 'get_default_sections']);
        add_action('wp_dashboard_setup', [$this, 'register_dashboard_widget']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_dashboard_widget_assets']);
        add_action('wp_ajax_bjlg_update_onboarding_progress', [$this, 'ajax_update_onboarding_progress']);
    }

    /**
     * Détermine si Google Drive est indisponible faute de SDK.
     */
    private function is_google_drive_unavailable() {
        $google_drive_destination = isset($this->destinations['google_drive'])
            ? $this->destinations['google_drive']
            : null;

        if (!is_object($google_drive_destination) || !method_exists($google_drive_destination, 'is_sdk_available')) {
            return false;
        }

        return !$google_drive_destination->is_sdk_available();
    }

    /**
     * Retourne le message à afficher quand le SDK Google Drive est manquant.
     */
    private function get_google_drive_unavailable_notice() {
        if ($this->google_drive_notice === null) {
            $this->google_drive_notice = esc_html__(
                "Le SDK Google n'est pas disponible. Installez les dépendances via Composer pour activer cette destination.",
                'backup-jlg'
            );
        }

        return $this->google_drive_notice;
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
        if (class_exists(BJLG_Azure_Blob::class)) {
            $this->destinations['azure_blob'] = new BJLG_Azure_Blob();
        }
        if (class_exists(BJLG_Backblaze_B2::class)) {
            $this->destinations['backblaze_b2'] = new BJLG_Backblaze_B2();
        }
        if (class_exists(BJLG_OneDrive::class)) {
            $this->destinations['onedrive'] = new BJLG_OneDrive();
        }
        if (class_exists(BJLG_PCloud::class)) {
            $this->destinations['pcloud'] = new BJLG_PCloud();
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
            'scheduling' => 'Planification',
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

    private function get_user_onboarding_progress(): array {
        if (!\function_exists('get_current_user_id') || !\function_exists('get_user_meta')) {
            return [];
        }

        $user_id = \get_current_user_id();
        if (!$user_id) {
            return [];
        }

        $progress = \get_user_meta($user_id, self::ONBOARDING_PROGRESS_META_KEY, true);

        if (!is_array($progress) || empty($progress)) {
            return [];
        }

        $completed = [];
        foreach ($progress as $step_id) {
            $key = sanitize_key((string) $step_id);
            if ($key !== '') {
                $completed[] = $key;
            }
        }

        return array_values(array_unique($completed));
    }

    private function save_user_onboarding_progress(array $completed) {
        if (!\function_exists('get_current_user_id') || !\function_exists('update_user_meta')) {
            return;
        }

        $user_id = \get_current_user_id();
        if (!$user_id) {
            return;
        }

        $normalized = [];
        foreach ($completed as $step_id) {
            $key = sanitize_key((string) $step_id);
            if ($key !== '') {
                $normalized[] = $key;
            }
        }

        \update_user_meta($user_id, self::ONBOARDING_PROGRESS_META_KEY, array_values(array_unique($normalized)));
        $this->onboarding_progress = $this->get_user_onboarding_progress();
    }

    /**
     * Retourne les sections par défaut affichées dans l'application React.
     */
    public function get_default_sections($sections) {
        $defaults = [
            'monitoring' => [
                'label' => __('Monitoring', 'backup-jlg'),
                'icon' => 'chart-line',
            ],
            'backup' => [
                'label' => __('Sauvegarde', 'backup-jlg'),
                'icon' => 'database-export',
            ],
            'restore' => [
                'label' => __('Restauration', 'backup-jlg'),
                'icon' => 'update-alt',
            ],
            'settings' => [
                'label' => __('Réglages', 'backup-jlg'),
                'icon' => 'admin-generic',
            ],
            'integrations' => [
                'label' => __('Intégrations', 'backup-jlg'),
                'icon' => 'admin-network',
            ],
        ];

        if (is_array($sections) && !empty($sections)) {
            return array_merge($defaults, $sections);
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
     * Register the dashboard widget shown on the main WordPress dashboard.
     */
    public function register_dashboard_widget() {
        if (!function_exists('bjlg_can_manage_plugin') || !bjlg_can_manage_plugin()) {
            return;
        }

        wp_add_dashboard_widget(
            'bjlg_dashboard_status',
            __('Sauvegardes - aperçu', 'backup-jlg'),
            [$this, 'render_dashboard_widget']
        );
    }

    /**
     * Enqueue assets required for the dashboard widget.
     */
    public function enqueue_dashboard_widget_assets($hook_suffix) {
        if ($hook_suffix !== 'index.php') {
            return;
        }

        if (!function_exists('bjlg_can_manage_plugin') || !bjlg_can_manage_plugin()) {
            return;
        }

        wp_enqueue_style(
            'bjlg-dashboard-widget',
            BJLG_PLUGIN_URL . 'assets/css/dashboard-widget.css',
            [],
            BJLG_VERSION
        );
    }

    public function ajax_update_onboarding_progress() {
        if (!function_exists('bjlg_can_manage_plugin') || !bjlg_can_manage_plugin()) {
            wp_send_json_error(['message' => __('Vous n’avez pas la permission de modifier cette checklist.', 'backup-jlg')], 403);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'bjlg_onboarding_progress')) {
            wp_send_json_error(['message' => __('Jeton de sécurité invalide.', 'backup-jlg')], 403);
        }

        $steps = isset($_POST['completed']) ? (array) wp_unslash($_POST['completed']) : [];
        $completed = [];

        foreach ($steps as $step_id) {
            $key = sanitize_key((string) $step_id);
            if ($key !== '') {
                $completed[] = $key;
            }
        }

        $this->save_user_onboarding_progress($completed);

        wp_send_json_success([
            'completed' => $this->onboarding_progress,
        ]);
    }
    
    private function map_legacy_tab_to_section(string $tab): string {
        switch ($tab) {
            case 'backup_restore':
            case 'scheduling':
                return 'backup';
            case 'history':
            case 'health_check':
            case 'logs':
                return 'monitoring';
            case 'settings':
                return 'settings';
            case 'api':
                return 'integrations';
            default:
                return $tab !== '' ? sanitize_key($tab) : 'monitoring';
        }
    }

    private function get_section_module_mapping(): array {
        return [
            'monitoring' => ['dashboard', 'logs'],
            'backup' => ['dashboard', 'backup', 'scheduling'],
            'restore' => ['backup'],
            'settings' => ['settings'],
            'integrations' => ['api'],
        ];
    }

    private function build_sidebar_summary_items(array $metrics): array {
        $summary = isset($metrics['summary']) && is_array($metrics['summary']) ? $metrics['summary'] : [];
        $reliability = isset($metrics['reliability']) && is_array($metrics['reliability']) ? $metrics['reliability'] : [];

        $items = [];

        $items[] = [
            'label' => __('Dernière sauvegarde', 'backup-jlg'),
            'value' => $summary['history_last_backup_relative'] ?? ($summary['history_last_backup'] ?? __('Aucune sauvegarde effectuée', 'backup-jlg')),
            'meta' => $summary['history_last_backup'] ?? '',
            'icon' => 'dashicons-backup',
        ];

        $items[] = [
            'label' => __('Prochaine planification', 'backup-jlg'),
            'value' => $summary['scheduler_next_run_relative'] ?? ($summary['scheduler_next_run'] ?? __('Non planifié', 'backup-jlg')),
            'meta' => $summary['scheduler_next_run'] ?? '',
            'icon' => 'dashicons-clock',
        ];

        $count_archives = (int) ($summary['storage_backup_count'] ?? 0);
        $count_formatted = \function_exists('number_format_i18n')
            ? \number_format_i18n($count_archives)
            : number_format($count_archives);

        if (\function_exists('_n')) {
            $meta_label = sprintf(
                \_n('%s archive', '%s archives', $count_archives, 'backup-jlg'),
                $count_formatted
            );
        } else {
            $meta_label = sprintf(
                $count_archives === 1 ? '%s archive' : '%s archives',
                $count_formatted
            );
        }

        $items[] = [
            'label' => __('Stockage local', 'backup-jlg'),
            'value' => $summary['storage_total_size_human'] ?? size_format(0),
            'meta' => $meta_label,
            'icon' => 'dashicons-database',
        ];

        if (!empty($reliability)) {
            $score = isset($reliability['score']) ? max(0, min(100, (int) $reliability['score'])) : null;
            $score_label = $reliability['score_label'] ?? '';
            $items[] = [
                'label' => __('Indice de fiabilité', 'backup-jlg'),
                'value' => $score !== null ? sprintf(__('%s /100', 'backup-jlg'), number_format_i18n($score)) : __('Non disponible', 'backup-jlg'),
                'meta' => $score_label,
                'icon' => 'dashicons-shield-alt',
            ];
        }

        return $items;
    }

    private function has_api_keys(): bool {
        if (!class_exists(BJLG_API_Keys::class)) {
            return false;
        }

        $keys = BJLG_API_Keys::get_keys();

        return is_array($keys) && !empty($keys);
    }

    private function build_onboarding_steps(array $metrics): array {
        $summary = isset($metrics['summary']) && is_array($metrics['summary']) ? $metrics['summary'] : [];
        $scheduler = isset($metrics['scheduler']) && is_array($metrics['scheduler']) ? $metrics['scheduler'] : [];
        $encryption = isset($metrics['encryption']) && is_array($metrics['encryption']) ? $metrics['encryption'] : [];
        $has_backup = (int) ($summary['history_successful_backups'] ?? 0) > 0;
        $has_schedule = (int) ($scheduler['active_count'] ?? 0) > 0;
        $encryption_enabled = !empty($encryption['encryption_enabled']);
        $has_api_key = $this->has_api_keys();

        $steps = [
            [
                'id' => 'create-first-backup',
                'title' => __('Créer une sauvegarde immédiate', 'backup-jlg'),
                'description' => __('Assurez-vous d’avoir une archive complète en lançant une sauvegarde à la demande.', 'backup-jlg'),
                'cta' => [
                    'label' => __('Lancer une sauvegarde', 'backup-jlg'),
                    'href' => add_query_arg(['page' => 'backup-jlg', 'section' => 'backup'], admin_url('admin.php')) . '#bjlg-backup-creation-form',
                ],
                'completed' => $has_backup,
                'locked' => false,
            ],
            [
                'id' => 'configure-schedule',
                'title' => __('Planifier des sauvegardes automatiques', 'backup-jlg'),
                'description' => __('Activez au moins une planification récurrente pour couvrir vos besoins métier.', 'backup-jlg'),
                'cta' => [
                    'label' => __('Configurer la planification', 'backup-jlg'),
                    'href' => add_query_arg(['page' => 'backup-jlg', 'section' => 'backup'], admin_url('admin.php')) . '#bjlg-scheduling',
                ],
                'completed' => $has_schedule,
                'locked' => false,
            ],
            [
                'id' => 'enable-encryption',
                'title' => __('Activer le chiffrement AES-256', 'backup-jlg'),
                'description' => __('Protégez vos archives en activant le chiffrement côté serveur.', 'backup-jlg'),
                'cta' => [
                    'label' => __('Activer le chiffrement', 'backup-jlg'),
                    'href' => add_query_arg(['page' => 'backup-jlg', 'section' => 'settings'], admin_url('admin.php')) . '#bjlg-encryption-settings',
                ],
                'completed' => $encryption_enabled,
                'locked' => true,
            ],
            [
                'id' => 'generate-api-key',
                'title' => __('Générer une clé API', 'backup-jlg'),
                'description' => __('Créez une clé API dédiée pour vos intégrations externes et automatisez vos workflows.', 'backup-jlg'),
                'cta' => [
                    'label' => __('Créer une clé API', 'backup-jlg'),
                    'href' => add_query_arg(['page' => 'backup-jlg', 'section' => 'integrations'], admin_url('admin.php')) . '#bjlg-create-api-key',
                    'action' => 'open-api-key',
                ],
                'completed' => $has_api_key,
                'locked' => true,
            ],
        ];

        return $steps;
    }

    /**
     * Affiche le contenu de la page principale et gère le routage des onglets.
     */
    public function render_admin_page() {
        $requested_section = isset($_GET['section']) ? sanitize_key($_GET['section']) : '';
        $legacy_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
        if ($requested_section === '' && $legacy_tab !== '') {
            $requested_section = $this->map_legacy_tab_to_section($legacy_tab);
        }

        $raw_sections = apply_filters('bjlg_admin_sections', []);
        if (!is_array($raw_sections) || empty($raw_sections)) {
            $raw_sections = $this->get_default_sections([]);
        }

        $sections = [];
        foreach ($raw_sections as $key => $data) {
            $slug = sanitize_key((string) $key);
            if ($slug === '') {
                $slug = 'section-' . substr(md5((string) $key), 0, 8);
            }

            $label = '';
            $icon_candidate = 'admin-generic';
            if (is_array($data)) {
                $label = isset($data['label']) ? (string) $data['label'] : '';
                $icon_candidate = isset($data['icon']) ? (string) $data['icon'] : 'admin-generic';
            } else {
                $label = (string) $data;
            }

            if (strpos($icon_candidate, 'dashicons-') !== 0) {
                $icon_candidate = 'dashicons-' . $icon_candidate;
            }

            if ($label === '') {
                $label = ucwords(str_replace(['_', '-'], ' ', $slug));
            }

            $sections[$slug] = [
                'key' => $slug,
                'label' => $label,
                'icon' => sanitize_html_class($icon_candidate),
                'url' => add_query_arg(
                    [
                        'page' => 'backup-jlg',
                        'section' => $slug,
                    ],
                    admin_url('admin.php')
                ),
            ];
        }

        if (empty($sections)) {
            return;
        }

        if ($requested_section === '' || !isset($sections[$requested_section])) {
            $active_section = (string) array_key_first($sections);
        } else {
            $active_section = $requested_section;
        }

        $metrics = $this->advanced_admin ? $this->advanced_admin->get_dashboard_metrics() : [];

        $notice_type = isset($_GET['bjlg_notice']) ? sanitize_key($_GET['bjlg_notice']) : '';
        $notice_message = '';

        if (isset($_GET['bjlg_notice_message'])) {
            $raw_notice = rawurldecode((string) $_GET['bjlg_notice_message']);
            $notice_message = sanitize_text_field(wp_unslash($raw_notice));
        }

        $notice_classes = [
            'success' => 'notice notice-success',
            'error' => 'notice notice-error',
            'warning' => 'notice notice-warning',
            'info' => 'notice notice-info',
        ];

        $section_modules_map = $this->get_section_module_mapping();
        $sections_for_js = array_values($sections);
        $sections_json = !empty($sections_for_js) ? wp_json_encode($sections_for_js) : '';
        $modules_json = !empty($section_modules_map) ? wp_json_encode($section_modules_map) : '';
        $onboarding_steps = $this->build_onboarding_steps($metrics);
        $onboarding_payload = [
            'steps' => $onboarding_steps,
            'completed' => $this->onboarding_progress,
        ];
        $onboarding_json = !empty($onboarding_steps) ? wp_json_encode($onboarding_payload) : '';

        $summary_items = $this->build_sidebar_summary_items($metrics);
        $reliability = isset($metrics['reliability']) && is_array($metrics['reliability']) ? $metrics['reliability'] : [];
        $reliability_level = $reliability['level'] ?? __('Indisponible', 'backup-jlg');
        $reliability_intent = isset($reliability['intent']) ? sanitize_html_class((string) $reliability['intent']) : 'info';
        $reliability_score = isset($reliability['score']) ? max(0, min(100, (int) $reliability['score'])) : null;

        $breadcrumb_items = [
            [
                'label' => __('Console Backup JLG', 'backup-jlg'),
                'url' => add_query_arg(['page' => 'backup-jlg'], admin_url('admin.php')),
            ],
            [
                'label' => $sections[$active_section]['label'],
                'url' => '',
            ],
        ];

        $app_sections_attr = $sections_json ? ' data-bjlg-sections="' . esc_attr($sections_json) . '"' : '';
        $app_modules_attr = $modules_json ? ' data-bjlg-modules="' . esc_attr($modules_json) . '"' : '';
        $app_onboarding_attr = $onboarding_json ? ' data-bjlg-onboarding="' . esc_attr($onboarding_json) . '"' : '';

        ?>
        <a class="bjlg-skip-link" href="#bjlg-main-content">
            <?php esc_html_e('Aller au contenu principal', 'backup-jlg'); ?>
        </a>
        <div id="bjlg-main-content" class="wrap bjlg-wrap is-light" data-bjlg-theme="light" role="main" tabindex="-1" data-active-section="<?php echo esc_attr($active_section); ?>">
            <header class="bjlg-page-header">
                <h1>
                    <span class="dashicons dashicons-database-export" aria-hidden="true"></span>
                    <?php echo esc_html(get_admin_page_title()); ?>
                    <span class="bjlg-version">v<?php echo esc_html(BJLG_VERSION); ?></span>
                </h1>
                <div class="bjlg-utility-bar">
                    <button
                        type="button"
                        class="button button-secondary bjlg-contrast-toggle"
                        id="bjlg-contrast-toggle"
                        data-dark-label="<?php echo esc_attr__('Activer le contraste renforcé', 'backup-jlg'); ?>"
                        data-light-label="<?php echo esc_attr__('Revenir au thème clair', 'backup-jlg'); ?>"
                        aria-pressed="false"
                    >
                        <?php echo esc_html__('Activer le contraste renforcé', 'backup-jlg'); ?>
                    </button>
                </div>
            </header>

            <?php if ($notice_type && $notice_message !== ''): ?>
                <?php $notice_class = isset($notice_classes[$notice_type]) ? $notice_classes[$notice_type] : $notice_classes['info']; ?>
                <div class="<?php echo esc_attr($notice_class); ?>">
                    <p><?php echo esc_html($notice_message); ?></p>
                </div>
            <?php endif; ?>

            <div class="bjlg-admin-shell" data-active-section="<?php echo esc_attr($active_section); ?>">
                <aside id="bjlg-shell-sidebar" class="bjlg-admin-shell__sidebar" data-collapsible="true">
                    <div class="bjlg-sidebar__header">
                        <h2><?php esc_html_e('Navigation', 'backup-jlg'); ?></h2>
                        <button type="button" class="bjlg-sidebar__close button button-link" id="bjlg-sidebar-close">
                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                            <span class="screen-reader-text"><?php esc_html_e('Fermer le menu', 'backup-jlg'); ?></span>
                        </button>
                    </div>
                    <div class="bjlg-sidebar__summary" role="region" aria-label="<?php esc_attr_e('Résumé d’état global', 'backup-jlg'); ?>">
                        <h3><?php esc_html_e('Résumé d’état', 'backup-jlg'); ?></h3>
                        <ul class="bjlg-sidebar-summary-list">
                            <?php foreach ($summary_items as $item): ?>
                                <li class="bjlg-sidebar-summary-list__item">
                                    <span class="bjlg-sidebar-summary-list__icon dashicons <?php echo esc_attr($item['icon']); ?>" aria-hidden="true"></span>
                                    <div class="bjlg-sidebar-summary-list__content">
                                        <span class="bjlg-sidebar-summary-list__label"><?php echo esc_html($item['label']); ?></span>
                                        <span class="bjlg-sidebar-summary-list__value"><?php echo esc_html($item['value']); ?></span>
                                        <?php if (!empty($item['meta'])): ?>
                                            <span class="bjlg-sidebar-summary-list__meta"><?php echo esc_html($item['meta']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <nav class="bjlg-sidebar__nav" aria-label="<?php esc_attr_e('Navigation principale', 'backup-jlg'); ?>">
                        <ul>
                            <?php foreach ($sections as $section_key => $section): ?>
                                <li>
                                    <a class="bjlg-sidebar__nav-link<?php echo $section_key === $active_section ? ' is-active' : ''; ?>"
                                       href="<?php echo esc_url($section['url']); ?>"
                                       data-section="<?php echo esc_attr($section_key); ?>">
                                        <span class="dashicons <?php echo esc_attr($section['icon']); ?>" aria-hidden="true"></span>
                                        <span><?php echo esc_html($section['label']); ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </nav>
                </aside>

                <div class="bjlg-admin-shell__main">
                    <div class="bjlg-shell-topbar">
                        <button type="button" class="button button-secondary bjlg-shell-topbar__toggle" id="bjlg-sidebar-toggle" aria-controls="bjlg-shell-sidebar" aria-expanded="false">
                            <span class="dashicons dashicons-menu" aria-hidden="true"></span>
                            <span class="screen-reader-text"><?php esc_html_e('Afficher le menu latéral', 'backup-jlg'); ?></span>
                        </button>
                        <nav class="bjlg-breadcrumbs" aria-label="<?php esc_attr_e('Fil d’Ariane', 'backup-jlg'); ?>">
                            <ol>
                                <?php $crumb_count = count($breadcrumb_items); ?>
                                <?php foreach ($breadcrumb_items as $index => $crumb): ?>
                                    <li<?php echo $index === $crumb_count - 1 ? ' aria-current="page"' : ''; ?>>
                                        <?php if (!empty($crumb['url']) && $index !== $crumb_count - 1): ?>
                                            <a href="<?php echo esc_url($crumb['url']); ?>"><?php echo esc_html($crumb['label']); ?></a>
                                        <?php else: ?>
                                            <span><?php echo esc_html($crumb['label']); ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </nav>
                        <div class="bjlg-shell-topbar__status" data-intent="<?php echo esc_attr($reliability_intent); ?>">
                            <span class="bjlg-shell-topbar__label"><?php esc_html_e('Indice de fiabilité', 'backup-jlg'); ?></span>
                            <strong class="bjlg-shell-topbar__value">
                                <?php echo $reliability_score !== null ? esc_html(number_format_i18n($reliability_score)) : '—'; ?>
                            </strong>
                            <span class="bjlg-shell-topbar__meta"><?php echo esc_html($reliability_level); ?></span>
                        </div>
                    </div>

                    <div id="bjlg-admin-app" class="bjlg-admin-app" data-active-section="<?php echo esc_attr($active_section); ?>"<?php echo $app_sections_attr . $app_modules_attr . $app_onboarding_attr; ?>>
                        <div id="bjlg-admin-app-nav" class="bjlg-admin-app__nav" aria-hidden="true"></div>
                        <div class="bjlg-admin-app__panels">
                            <?php foreach ($sections as $section_key => $section):
                                $panel_id = 'bjlg-section-' . $section_key;
                                $is_active = ($section_key === $active_section);
                                $panel_modules = isset($section_modules_map[$section_key]) ? array_filter(array_map('sanitize_key', (array) $section_modules_map[$section_key])) : [];
                                $panel_modules_attr = $panel_modules ? ' data-bjlg-modules="' . esc_attr(implode(' ', array_unique($panel_modules))) . '"' : '';
                                ?>
                                <section
                                    id="<?php echo esc_attr($panel_id); ?>"
                                    class="bjlg-shell-section"
                                    data-section="<?php echo esc_attr($section_key); ?>"
                                    role="tabpanel"
                                    aria-hidden="<?php echo $is_active ? 'false' : 'true'; ?>"
                                    aria-labelledby="bjlg-tab-<?php echo esc_attr($section_key); ?>"
                                    tabindex="0"<?php echo $is_active ? '' : ' hidden'; ?><?php echo $panel_modules_attr; ?>>
                                    <?php $this->render_section_content($section_key, $active_section, $metrics, $onboarding_payload); ?>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_section_content($section_key, $active_section, array $metrics, array $onboarding_payload) {
        $handled = true;

        switch ($section_key) {
            case 'monitoring':
                $this->render_dashboard_overview($metrics, $onboarding_payload);
                $this->render_history_section();
                $this->render_health_check_section();
                $this->render_logs_section();
                break;
            case 'backup':
                $this->render_backup_creation_section();
                $this->render_backup_list_section();
                $this->render_schedule_section();
                break;
            case 'restore':
                $this->render_restore_section();
                break;
            case 'settings':
                $this->render_settings_section();
                break;
            case 'integrations':
                $this->render_api_section();
                break;
            default:
                $handled = false;
                break;
        }

        /**
         * Permet aux extensions d'ajouter du contenu après le rendu d'une section.
         *
         * @param string $section_key     Clé de la section affichée.
         * @param string $active_section  Section actuellement active.
         * @param array  $metrics         Dernières métriques calculées.
         * @param bool   $handled         Indique si la section a été gérée par le cœur du plugin.
         */
        do_action('bjlg_render_admin_section', $section_key, $active_section, $metrics, $handled);

        if (!$handled) {
            do_action('bjlg_render_admin_tab', $section_key, $active_section);
        }
    }

    private function get_tab_module_mapping() {
        return $this->get_section_module_mapping();
    }

    /**
     * Affiche l'encart de synthèse des métriques et l'onboarding.
     */
    private function render_dashboard_overview(array $metrics, array $onboarding_payload = []) {
        $summary = $metrics['summary'] ?? [];
        $alerts = $metrics['alerts'] ?? [];
        $queues = isset($metrics['queues']) && is_array($metrics['queues']) ? $metrics['queues'] : [];
        $reliability = isset($metrics['reliability']) && is_array($metrics['reliability']) ? $metrics['reliability'] : [];
        $data_attr = !empty($metrics) ? wp_json_encode($metrics) : '';

        $backup_tab_url = add_query_arg(
            [
                'page' => 'backup-jlg',
                'section' => 'backup',
            ],
            admin_url('admin.php')
        );

        $backup_cta_url = $backup_tab_url . '#bjlg-backup-creation-form';
        $restore_cta_url = $backup_tab_url . '#bjlg-restore-form';
        $checklist_json = !empty($onboarding_payload) ? wp_json_encode($onboarding_payload) : '';
        $checklist_attr = $checklist_json ? ' data-bjlg-checklist="' . esc_attr($checklist_json) . '"' : '';

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

            <div id="bjlg-dashboard-live-region" class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true"></div>

            <div class="bjlg-dashboard-actions" data-role="actions" role="region" aria-live="polite" aria-atomic="true">
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

            <?php $this->render_reliability_section($reliability); ?>

            <div class="bjlg-alerts" data-role="alerts" role="status" aria-live="polite" aria-atomic="true">
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

                <?php
                $remote_destinations = isset($metrics['storage']['remote_destinations']) && is_array($metrics['storage']['remote_destinations'])
                    ? $metrics['storage']['remote_destinations']
                    : [];
                $remote_total = count($remote_destinations);
                $remote_connected = 0;
                $remote_summary = __('Aucune destination distante configurée.', 'backup-jlg');
                $remote_caption = __('Connectez une destination distante pour suivre les quotas.', 'backup-jlg');
                $capacity_watch = [];
                $offline_destinations = [];

                if ($remote_total > 0) {
                    foreach ($remote_destinations as $destination) {
                        if (!is_array($destination)) {
                            continue;
                        }

                        $name = isset($destination['name']) && $destination['name'] !== ''
                            ? sanitize_text_field((string) $destination['name'])
                            : sanitize_text_field((string) ($destination['id'] ?? __('Destination inconnue', 'backup-jlg')));

                        $connected = !empty($destination['connected']);
                        if ($connected) {
                            $remote_connected++;
                        } else {
                            $offline_destinations[] = $name;
                        }

                        $errors = isset($destination['errors']) && is_array($destination['errors'])
                            ? array_filter(array_map('sanitize_text_field', $destination['errors']))
                            : [];

                        if (!empty($errors)) {
                            $offline_destinations[] = $name;
                        }

                        $used_bytes = isset($destination['used_bytes']) ? (int) $destination['used_bytes'] : null;
                        $quota_bytes = isset($destination['quota_bytes']) ? (int) $destination['quota_bytes'] : null;

                        if ($connected && $quota_bytes && $quota_bytes > 0 && $used_bytes !== null) {
                            $ratio = $quota_bytes > 0 ? ($used_bytes / $quota_bytes) : null;
                            if ($ratio !== null && $ratio >= 0.85) {
                                $capacity_watch[] = $name;
                            }
                        }
                    }

                    $remote_summary = sprintf(
                        _n('%1$s destination distante active sur %2$s', '%1$s destinations distantes actives sur %2$s', $remote_connected, 'backup-jlg'),
                        number_format_i18n($remote_connected),
                        number_format_i18n($remote_total)
                    );

                    $unique_offline = array_values(array_unique($offline_destinations));
                    $unique_watch = array_values(array_unique($capacity_watch));

                    if (!empty($unique_offline)) {
                        $remote_caption = sprintf(
                            __('Attention : vérifier %s', 'backup-jlg'),
                            implode(', ', $unique_offline)
                        );
                    } elseif (!empty($unique_watch)) {
                        $remote_caption = sprintf(
                            __('Capacité > 85%% pour %s', 'backup-jlg'),
                            implode(', ', $unique_watch)
                        );
                    } else {
                        $remote_caption = __('Capacité hors-site nominale.', 'backup-jlg');
                    }
                }
                ?>
                <article class="bjlg-card bjlg-card--stat" data-metric="remote-storage">
                    <span class="bjlg-card__kicker"><?php esc_html_e('Stockage distant', 'backup-jlg'); ?></span>
                    <h3 class="bjlg-card__title"><?php esc_html_e('Capacité hors-site', 'backup-jlg'); ?></h3>
                    <div class="bjlg-card__value" data-field="remote_storage_connected"><?php echo esc_html($remote_summary); ?></div>
                    <p class="bjlg-card__meta" data-field="remote_storage_caption"><?php echo esc_html($remote_caption); ?></p>
                    <ul class="bjlg-card__list" data-field="remote_storage_list">
                        <?php if (empty($remote_destinations)): ?>
                            <li class="bjlg-card__list-item" data-empty="true"><?php esc_html_e('Aucune donnée distante disponible.', 'backup-jlg'); ?></li>
                        <?php else: ?>
                            <?php foreach ($remote_destinations as $destination):
                                if (!is_array($destination)) {
                                    continue;
                                }

                                $name = isset($destination['name']) && $destination['name'] !== ''
                                    ? sanitize_text_field((string) $destination['name'])
                                    : sanitize_text_field((string) ($destination['id'] ?? __('Destination inconnue', 'backup-jlg')));

                                $used_human = isset($destination['used_human']) ? (string) $destination['used_human'] : '';
                                $quota_human = isset($destination['quota_human']) ? (string) $destination['quota_human'] : '';
                                $free_human = isset($destination['free_human']) ? (string) $destination['free_human'] : '';
                                $backups_count = isset($destination['backups_count']) ? (int) $destination['backups_count'] : 0;
                                $used_bytes = isset($destination['used_bytes']) ? (int) $destination['used_bytes'] : null;
                                $quota_bytes = isset($destination['quota_bytes']) ? (int) $destination['quota_bytes'] : null;
                                $connected = !empty($destination['connected']);
                                $errors = isset($destination['errors']) && is_array($destination['errors'])
                                    ? array_filter(array_map('sanitize_text_field', $destination['errors']))
                                    : [];

                                $detail_parts = [];
                                if ($used_human !== '' && $quota_human !== '') {
                                    $detail_parts[] = sprintf(__('Utilisé : %1$s / %2$s', 'backup-jlg'), $used_human, $quota_human);
                                } elseif ($used_human !== '') {
                                    $detail_parts[] = sprintf(__('Utilisé : %s', 'backup-jlg'), $used_human);
                                }

                                if ($free_human !== '') {
                                    $detail_parts[] = sprintf(__('Libre : %s', 'backup-jlg'), $free_human);
                                }

                                if ($backups_count > 0) {
                                    $detail_parts[] = sprintf(
                                        _n('%s archive stockée', '%s archives stockées', $backups_count, 'backup-jlg'),
                                        number_format_i18n($backups_count)
                                    );
                                }

                                $ratio = null;
                                if ($quota_bytes && $quota_bytes > 0 && $used_bytes !== null) {
                                    $ratio = max(0, min(1, $used_bytes / $quota_bytes));
                                    $detail_parts[] = sprintf(__('Utilisation : %s%%', 'backup-jlg'), number_format_i18n((int) round($ratio * 100)));
                                }

                                $intent = 'info';
                                if (!$connected || !empty($errors)) {
                                    $intent = 'error';
                                } elseif ($ratio !== null && $ratio >= 0.85) {
                                    $intent = 'warning';
                                }
                                ?>
                                <li class="bjlg-card__list-item bjlg-card__list-item--<?php echo esc_attr($intent); ?>" data-intent="<?php echo esc_attr($intent); ?>">
                                    <strong><?php echo esc_html($name); ?></strong>
                                    <?php if (!empty($detail_parts)): ?>
                                        <span class="bjlg-card__list-meta"><?php echo esc_html(implode(' • ', $detail_parts)); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($errors)): ?>
                                        <span class="bjlg-card__list-error"><?php echo esc_html(implode(' • ', $errors)); ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </article>
            </div>

            <div class="bjlg-onboarding-checklist" id="bjlg-onboarding-checklist" role="region" aria-live="polite" aria-atomic="true"<?php echo $checklist_attr; ?>>
                <div class="bjlg-onboarding-checklist__placeholder">
                    <span class="spinner is-active" aria-hidden="true"></span>
                    <p><?php esc_html_e('Chargement de votre checklist personnalisée…', 'backup-jlg'); ?></p>
                </div>
                <noscript>
                    <p class="bjlg-onboarding-checklist__noscript"><?php esc_html_e('Activez JavaScript pour utiliser la checklist interactive.', 'backup-jlg'); ?></p>
                </noscript>
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

            <?php if (!empty($queues)): ?>
                <section class="bjlg-queues" aria-labelledby="bjlg-queues-title">
                    <header class="bjlg-queues__header">
                        <h2 id="bjlg-queues-title"><?php esc_html_e('Files d’attente', 'backup-jlg'); ?></h2>
                        <p class="bjlg-queues__description">
                            <?php esc_html_e('Suivez les notifications et purges distantes en attente directement depuis le tableau de bord.', 'backup-jlg'); ?>
                        </p>
                    </header>

                    <div class="bjlg-queues__grid">
                        <?php foreach ($queues as $queue_key => $queue):
                            if (!is_array($queue)) {
                                continue;
                            }

                            $total = isset($queue['total']) ? (int) $queue['total'] : 0;
                            $status_counts = isset($queue['status_counts']) && is_array($queue['status_counts']) ? $queue['status_counts'] : [];
                            $pending_count = isset($status_counts['pending']) ? (int) $status_counts['pending'] : 0;
                            $retry_count = isset($status_counts['retry']) ? (int) $status_counts['retry'] : 0;
                            $failed_count = isset($status_counts['failed']) ? (int) $status_counts['failed'] : 0;
                            $delayed_count = isset($queue['delayed_count']) ? (int) $queue['delayed_count'] : 0;
                            $next_relative = isset($queue['next_attempt_relative']) ? (string) $queue['next_attempt_relative'] : '';
                            $oldest_relative = isset($queue['oldest_entry_relative']) ? (string) $queue['oldest_entry_relative'] : '';
                            $entries = isset($queue['entries']) && is_array($queue['entries']) ? $queue['entries'] : [];
                            ?>
                            <article class="bjlg-queue-card" data-queue="<?php echo esc_attr($queue_key); ?>">
                                <header class="bjlg-queue-card__header">
                                    <h3 class="bjlg-queue-card__title"><?php echo esc_html($queue['label'] ?? ucfirst((string) $queue_key)); ?></h3>
                                    <span class="bjlg-queue-card__count" data-field="total">
                                        <?php
                                        echo esc_html(
                                            sprintf(
                                                _n('%s entrée', '%s entrées', $total, 'backup-jlg'),
                                                number_format_i18n($total)
                                            )
                                        );
                                        ?>
                                    </span>
                                </header>

                                <p class="bjlg-queue-card__meta" data-field="status-counts">
                                    <?php
                                    printf(
                                        /* translators: 1: number of pending entries, 2: number of retry entries, 3: number of failed entries. */
                                        esc_html__('En attente : %1$s • Nouvel essai : %2$s • Échecs : %3$s', 'backup-jlg'),
                                        esc_html(number_format_i18n($pending_count)),
                                        esc_html(number_format_i18n($retry_count)),
                                        esc_html(number_format_i18n($failed_count))
                                    );
                                    ?>
                                </p>

                                <p class="bjlg-queue-card__meta" data-field="next">
                                    <?php if ($next_relative !== ''): ?>
                                        <?php printf(esc_html__('Prochain passage %s', 'backup-jlg'), esc_html($next_relative)); ?>
                                    <?php else: ?>
                                        <?php esc_html_e('Aucun traitement planifié.', 'backup-jlg'); ?>
                                    <?php endif; ?>
                                </p>

                                <p class="bjlg-queue-card__meta" data-field="oldest">
                                    <?php if ($oldest_relative !== ''): ?>
                                        <?php printf(esc_html__('Entrée la plus ancienne %s', 'backup-jlg'), esc_html($oldest_relative)); ?>
                                    <?php endif; ?>
                                </p>

                                <?php if (!empty($delayed_count)): ?>
                                    <p class="bjlg-queue-card__meta bjlg-queue-card__meta--alert" data-field="delayed">
                                        <?php printf(esc_html__('%s purge(s) en retard', 'backup-jlg'), esc_html(number_format_i18n($delayed_count))); ?>
                                    </p>
                                <?php endif; ?>

                                <?php if ($queue_key === 'remote_purge' && !empty($queue['sla']) && is_array($queue['sla'])):
                                    $sla = $queue['sla'];
                                ?>
                                    <div class="bjlg-queue-card__metrics" data-field="sla">
                                        <?php if (!empty($sla['updated_relative'])): ?>
                                            <p class="bjlg-queue-card__metrics-caption"><?php printf(esc_html__('Mise à jour %s', 'backup-jlg'), esc_html($sla['updated_relative'])); ?></p>
                                        <?php endif; ?>
                                        <ul class="bjlg-queue-card__metrics-list">
                                            <?php if (!empty($sla['pending_average'])): ?>
                                                <li><?php printf(esc_html__('Âge moyen en file : %s', 'backup-jlg'), esc_html($sla['pending_average'])); ?></li>
                                            <?php endif; ?>
                                            <?php if (!empty($sla['pending_oldest'])): ?>
                                                <li><?php printf(esc_html__('Plus ancien : %s', 'backup-jlg'), esc_html($sla['pending_oldest'])); ?></li>
                                            <?php endif; ?>
                                            <?php if (!empty($sla['pending_over_threshold'])): ?>
                                                <li><?php printf(esc_html__('%s entrée(s) au-delà du seuil', 'backup-jlg'), esc_html(number_format_i18n((int) $sla['pending_over_threshold']))); ?></li>
                                            <?php endif; ?>
                                            <?php if (!empty($sla['pending_destinations'])): ?>
                                                <li><?php printf(esc_html__('Destinations impactées : %s', 'backup-jlg'), esc_html($sla['pending_destinations'])); ?></li>
                                            <?php endif; ?>
                                            <?php if (!empty($sla['throughput_average'])): ?>
                                                <li><?php printf(esc_html__('Durée moyenne de purge : %s', 'backup-jlg'), esc_html($sla['throughput_average'])); ?></li>
                                            <?php endif; ?>
                                            <?php if (!empty($sla['throughput_last_completion_relative'])): ?>
                                                <li><?php printf(esc_html__('Dernière purge réussie %s', 'backup-jlg'), esc_html($sla['throughput_last_completion_relative'])); ?></li>
                                            <?php endif; ?>
                                            <?php if (!empty($sla['failures_total'])): ?>
                                                <li><?php printf(esc_html__('Échecs cumulés : %s', 'backup-jlg'), esc_html(number_format_i18n((int) $sla['failures_total']))); ?></li>
                                            <?php endif; ?>
                                            <?php if (!empty($sla['last_failure_relative']) && !empty($sla['last_failure_message'])): ?>
                                                <li><?php printf(esc_html__('Dernier échec %1$s : %2$s', 'backup-jlg'), esc_html($sla['last_failure_relative']), esc_html($sla['last_failure_message'])); ?></li>
                                            <?php elseif (!empty($sla['last_failure_relative'])): ?>
                                                <li><?php printf(esc_html__('Dernier échec %s', 'backup-jlg'), esc_html($sla['last_failure_relative'])); ?></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <?php if ($queue_key === 'remote_purge'): ?>
                                    <p class="bjlg-queue-card__note"><?php esc_html_e('Prochaine étape : générer des prédictions de saturation et automatiser les corrections.', 'backup-jlg'); ?></p>
                                <?php endif; ?>

                                <ul class="bjlg-queue-card__entries" data-role="entries">
                                    <?php if (!empty($entries)): ?>
                                        <?php foreach ($entries as $entry):
                                            if (!is_array($entry)) {
                                                continue;
                                            }

                                            $status_intent = isset($entry['status_intent']) ? (string) $entry['status_intent'] : 'info';
                                            $status_label = isset($entry['status_label']) ? (string) $entry['status_label'] : '';
                                            $attempt_label = isset($entry['attempt_label']) ? (string) $entry['attempt_label'] : '';
                                            $next_attempt_relative = isset($entry['next_attempt_relative']) ? (string) $entry['next_attempt_relative'] : '';
                                            $created_relative = isset($entry['created_relative']) ? (string) $entry['created_relative'] : '';
                                            $details = isset($entry['details']) && is_array($entry['details']) ? $entry['details'] : [];
                                            $entry_id = isset($entry['id']) ? (string) $entry['id'] : '';
                                            $entry_file = isset($entry['file']) ? (string) $entry['file'] : '';
                                            $entry_delay_flag = !empty($entry['delayed']);
                                            $delay_label = isset($entry['delay_label']) ? (string) $entry['delay_label'] : '';
                                            $severity_label = isset($entry['severity_label']) ? (string) $entry['severity_label'] : '';
                                            $severity_intent = isset($entry['severity_intent']) ? (string) $entry['severity_intent'] : 'info';
                                            $severity_value = isset($entry['severity']) ? (string) $entry['severity'] : '';
                                            ?>
                                            <li class="bjlg-queue-card__entry"
                                                data-status="<?php echo esc_attr($status_intent); ?>"
                                                data-severity="<?php echo esc_attr($severity_value); ?>"
                                                data-entry-id="<?php echo esc_attr($entry_id); ?>"
                                                data-entry-file="<?php echo esc_attr($entry_file); ?>">
                                                <header class="bjlg-queue-card__entry-header">
                                                    <span class="bjlg-queue-card__entry-title"><?php echo esc_html($entry['title'] ?? ''); ?></span>
                                                    <?php if ($status_label !== ''): ?>
                                                        <span class="bjlg-queue-card__entry-status bjlg-queue-card__entry-status--<?php echo esc_attr($status_intent); ?>"><?php echo esc_html($status_label); ?></span>
                                                    <?php endif; ?>
                                                </header>

                                                <p class="bjlg-queue-card__entry-meta">
                                                    <?php if ($severity_label !== ''): ?>
                                                        <span class="bjlg-queue-card__entry-severity bjlg-queue-card__entry-severity--<?php echo esc_attr($severity_intent); ?>">
                                                            <?php printf(esc_html__('Gravité : %s', 'backup-jlg'), esc_html($severity_label)); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($attempt_label !== ''): ?>
                                                        <span><?php echo esc_html($attempt_label); ?></span>
                                                    <?php endif; ?>
                                                </p>

                                                <p class="bjlg-queue-card__entry-meta" data-field="timestamps">
                                                    <?php if ($created_relative !== ''): ?>
                                                        <span><?php printf(esc_html__('Créée %s', 'backup-jlg'), esc_html($created_relative)); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($next_attempt_relative !== ''): ?>
                                                        <span><?php printf(esc_html__('Rejouée %s', 'backup-jlg'), esc_html($next_attempt_relative)); ?></span>
                                                    <?php endif; ?>
                                                </p>

                                                <?php if (!empty($details['destinations'])): ?>
                                                    <p class="bjlg-queue-card__entry-meta">
                                                        <?php printf(esc_html__('Destinations : %s', 'backup-jlg'), esc_html($details['destinations'])); ?>
                                                    </p>
                                                <?php endif; ?>

                                                <?php if ($entry_delay_flag): ?>
                                                    <p class="bjlg-queue-card__entry-flag" data-field="delay">
                                                        <?php if ($delay_label !== ''): ?>
                                                            <?php printf(esc_html__('Retard max : %s', 'backup-jlg'), esc_html($delay_label)); ?>
                                                        <?php else: ?>
                                                            <?php esc_html_e('Retard détecté', 'backup-jlg'); ?>
                                                        <?php endif; ?>
                                                    </p>
                                                <?php endif; ?>

                                                <?php if (!empty($details['quiet_until_relative'])): ?>
                                                    <p class="bjlg-queue-card__entry-flag" data-field="quiet-until">
                                                        <?php printf(
                                                            esc_html__('Silence actif jusqu’à %s', 'backup-jlg'),
                                                            esc_html($details['quiet_until_relative'])
                                                        ); ?>
                                                    </p>
                                                <?php endif; ?>

                                                <?php if (!empty($details['escalation_channels'])): ?>
                                                    <p class="bjlg-queue-card__entry-flag" data-field="escalation">
                                                        <?php
                                                        $escalation_parts = [];
                                                        $escalation_parts[] = sprintf(
                                                            esc_html__('Escalade vers %s', 'backup-jlg'),
                                                            esc_html($details['escalation_channels'])
                                                        );
                                                        if (!empty($details['escalation_delay'])) {
                                                            $escalation_parts[] = sprintf(
                                                                esc_html__('délai : %s', 'backup-jlg'),
                                                                esc_html($details['escalation_delay'])
                                                            );
                                                        }
                                                        if (!empty($details['escalation_next_relative'])) {
                                                            $escalation_parts[] = sprintf(
                                                                esc_html__('prochaine tentative %s', 'backup-jlg'),
                                                                esc_html($details['escalation_next_relative'])
                                                            );
                                                        }

                                                        echo esc_html(implode(' • ', $escalation_parts));
                                                        ?>
                                                    </p>
                                                <?php endif; ?>

                                                <?php if (!empty($entry['message'])): ?>
                                                    <p class="bjlg-queue-card__entry-message"><?php echo esc_html($entry['message']); ?></p>
                                                <?php endif; ?>

                                                <div class="bjlg-queue-card__entry-actions">
                                                    <?php if ($queue_key === 'notifications' && $entry_id !== ''): ?>
                                                        <button type="button" class="button button-secondary button-small" data-queue-action="retry-notification" data-entry-id="<?php echo esc_attr($entry_id); ?>">
                                                            <?php esc_html_e('Relancer', 'backup-jlg'); ?>
                                                        </button>
                                                        <button type="button" class="button button-link-delete" data-queue-action="clear-notification" data-entry-id="<?php echo esc_attr($entry_id); ?>">
                                                            <?php esc_html_e('Ignorer', 'backup-jlg'); ?>
                                                        </button>
                                                    <?php elseif ($queue_key === 'remote_purge' && $entry_file !== ''): ?>
                                                        <button type="button" class="button button-secondary button-small" data-queue-action="retry-remote-purge" data-file="<?php echo esc_attr($entry_file); ?>">
                                                            <?php esc_html_e('Relancer la purge', 'backup-jlg'); ?>
                                                        </button>
                                                        <button type="button" class="button button-link-delete" data-queue-action="clear-remote-purge" data-file="<?php echo esc_attr($entry_file); ?>">
                                                            <?php esc_html_e('Retirer de la file', 'backup-jlg'); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <li class="bjlg-queue-card__entry bjlg-queue-card__entry--empty">
                                            <?php esc_html_e('Aucune entrée en attente.', 'backup-jlg'); ?>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </section>
        <?php
    }

    private function render_reliability_section(array $reliability) {
        $score = isset($reliability['score']) ? (int) $reliability['score'] : null;
        $score_value = $score !== null ? number_format_i18n(max(0, min(100, $score))) : '—';
        $score_label = isset($reliability['score_label']) ? (string) $reliability['score_label'] : '';
        $level = isset($reliability['level']) ? (string) $reliability['level'] : __('Indisponible', 'backup-jlg');
        $description = isset($reliability['description']) ? (string) $reliability['description'] : __('Les données de fiabilité apparaîtront après vos premières sauvegardes.', 'backup-jlg');
        $caption = isset($reliability['caption']) ? (string) $reliability['caption'] : __('Comparaison avec les standards professionnels : planification, chiffrement et redondance.', 'backup-jlg');
        $intent = isset($reliability['intent']) ? sanitize_key((string) $reliability['intent']) : 'info';
        $pillars = isset($reliability['pillars']) && is_array($reliability['pillars']) ? $reliability['pillars'] : [];
        $recommendations = isset($reliability['recommendations']) && is_array($reliability['recommendations']) ? $reliability['recommendations'] : [];

        $section_intent = sanitize_html_class($intent !== '' ? $intent : 'info');

        ?>
        <section class="bjlg-reliability" data-role="reliability" data-intent="<?php echo esc_attr($section_intent); ?>" aria-labelledby="bjlg-reliability-title" role="region">
            <header class="bjlg-reliability__header">
                <h2 id="bjlg-reliability-title"><?php esc_html_e('Indice de fiabilité', 'backup-jlg'); ?></h2>
                <p class="bjlg-reliability__caption" data-field="reliability_caption"><?php echo esc_html($caption); ?></p>
            </header>

            <div class="bjlg-reliability__grid">
                <div class="bjlg-reliability__score" data-role="reliability-score">
                    <div class="bjlg-reliability__score-main" aria-live="polite" aria-atomic="true">
                        <span class="bjlg-reliability__value" data-field="reliability_score_value" aria-label="<?php echo esc_attr(sprintf(__('Indice de fiabilité sur 100 : %s', 'backup-jlg'), $score_label !== '' ? $score_label : $score_value)); ?>"><?php echo esc_html($score_value); ?></span>
                        <span class="bjlg-reliability__unit" aria-hidden="true">/100</span>
                    </div>
                    <p class="screen-reader-text" data-field="reliability_score_label"><?php echo esc_html($score_label !== '' ? $score_label : $score_value . ' / 100'); ?></p>
                    <span class="bjlg-reliability__level" data-field="reliability_level"><?php echo esc_html($level); ?></span>
                    <p class="bjlg-reliability__description" data-field="reliability_description"><?php echo esc_html($description); ?></p>
                </div>

                <ul class="bjlg-reliability__pillars" data-role="reliability-pillars">
                    <?php if (empty($pillars)): ?>
                        <li class="bjlg-reliability-pillar bjlg-reliability-pillar--empty">
                            <?php esc_html_e('Les signaux clés apparaîtront après vos premières sauvegardes.', 'backup-jlg'); ?>
                        </li>
                    <?php else: ?>
                        <?php foreach ($pillars as $pillar):
                            if (!is_array($pillar)) {
                                continue;
                            }

                            $pillar_intent = isset($pillar['intent']) ? sanitize_key((string) $pillar['intent']) : (isset($pillar['status']) ? sanitize_key((string) $pillar['status']) : 'info');
                            $pillar_icon = isset($pillar['icon']) ? sanitize_html_class((string) $pillar['icon']) : 'dashicons-shield-alt';
                            $pillar_label = isset($pillar['label']) ? (string) $pillar['label'] : '';
                            $pillar_message = isset($pillar['message']) ? (string) $pillar['message'] : '';
                            ?>
                            <li class="bjlg-reliability-pillar" data-intent="<?php echo esc_attr($pillar_intent ?: 'info'); ?>">
                                <span class="bjlg-reliability-pillar__icon dashicons <?php echo esc_attr($pillar_icon); ?>" aria-hidden="true"></span>
                                <div class="bjlg-reliability-pillar__content">
                                    <?php if ($pillar_label !== ''): ?>
                                        <span class="bjlg-reliability-pillar__label"><?php echo esc_html($pillar_label); ?></span>
                                    <?php endif; ?>
                                    <?php if ($pillar_message !== ''): ?>
                                        <span class="bjlg-reliability-pillar__message"><?php echo esc_html($pillar_message); ?></span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="bjlg-reliability__actions" data-role="reliability-actions"<?php echo empty($recommendations) ? ' hidden' : ''; ?>>
                <?php foreach ($recommendations as $recommendation):
                    if (!is_array($recommendation) || empty($recommendation['label'])) {
                        continue;
                    }

                    $action_label = (string) $recommendation['label'];
                    $action_url = isset($recommendation['url']) ? esc_url($recommendation['url']) : '#';
                    $action_intent = isset($recommendation['intent']) ? sanitize_key((string) $recommendation['intent']) : 'secondary';
                    $action_class = $action_intent === 'primary' ? 'button button-primary' : 'button button-secondary';
                    ?>
                    <a class="<?php echo esc_attr($action_class); ?>" href="<?php echo $action_url; ?>">
                        <?php echo esc_html($action_label); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    /**
     * Render the dashboard widget content displayed on the WordPress dashboard.
     */
    public function render_dashboard_widget() {
        $snapshot = $this->get_dashboard_widget_snapshot();

        if (empty($snapshot['ok'])) {
            $message = !empty($snapshot['error']) ? $snapshot['error'] : __('Impossible de charger les informations de sauvegarde pour le moment.', 'backup-jlg');
            echo '<p>' . esc_html($message) . '</p>';
            return;
        }

        $summary = $snapshot['summary'];
        $alerts = $snapshot['alerts'];
        $backups = $snapshot['backups'];
        $actions = $snapshot['actions'];
        $generated_at = $snapshot['generated_at'];

        ?>
        <div class="bjlg-dashboard-widget" role="region" aria-live="polite" aria-atomic="true">
            <header class="bjlg-dashboard-widget__header">
                <h3 class="bjlg-dashboard-widget__title"><?php esc_html_e('Vue d’ensemble des sauvegardes', 'backup-jlg'); ?></h3>
                <?php if (!empty($generated_at)): ?>
                    <span class="bjlg-dashboard-widget__timestamp"><?php echo esc_html(sprintf(__('Actualisé le %s', 'backup-jlg'), $generated_at)); ?></span>
                <?php endif; ?>
            </header>

            <div class="bjlg-dashboard-widget__summary" role="status" aria-live="polite" aria-atomic="true">
                <div class="bjlg-dashboard-widget__stat">
                    <span class="bjlg-dashboard-widget__stat-label"><?php esc_html_e('Dernière sauvegarde', 'backup-jlg'); ?></span>
                    <span class="bjlg-dashboard-widget__stat-value"><?php echo esc_html($summary['history_last_backup'] ?? __('Aucune sauvegarde effectuée', 'backup-jlg')); ?></span>
                    <?php if (!empty($summary['history_last_backup_relative'])): ?>
                        <span class="bjlg-dashboard-widget__stat-meta"><?php echo esc_html($summary['history_last_backup_relative']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="bjlg-dashboard-widget__stat">
                    <span class="bjlg-dashboard-widget__stat-label"><?php esc_html_e('Prochaine sauvegarde planifiée', 'backup-jlg'); ?></span>
                    <span class="bjlg-dashboard-widget__stat-value"><?php echo esc_html($summary['scheduler_next_run'] ?? __('Non planifié', 'backup-jlg')); ?></span>
                    <?php if (!empty($summary['scheduler_next_run_relative'])): ?>
                        <span class="bjlg-dashboard-widget__stat-meta"><?php echo esc_html($summary['scheduler_next_run_relative']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="bjlg-dashboard-widget__stat">
                    <span class="bjlg-dashboard-widget__stat-label"><?php esc_html_e('Archives stockées', 'backup-jlg'); ?></span>
                    <span class="bjlg-dashboard-widget__stat-value"><?php echo esc_html(number_format_i18n($summary['storage_backup_count'] ?? 0)); ?></span>
                    <?php if (!empty($summary['storage_total_size_human'])): ?>
                        <span class="bjlg-dashboard-widget__stat-meta"><?php echo esc_html($summary['storage_total_size_human']); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($actions['backup']['url'])): ?>
                <div class="bjlg-dashboard-widget__actions">
                    <a class="bjlg-dashboard-widget__button" href="<?php echo esc_url($actions['backup']['url']); ?>">
                        <?php echo esc_html($actions['backup']['label']); ?>
                    </a>
                    <?php if (!empty($actions['restore']['url'])): ?>
                        <a class="bjlg-dashboard-widget__button bjlg-dashboard-widget__button--secondary" href="<?php echo esc_url($actions['restore']['url']); ?>">
                            <?php echo esc_html($actions['restore']['label']); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($alerts)): ?>
                <div class="bjlg-dashboard-widget__alerts">
                    <?php foreach (array_slice($alerts, 0, 3) as $alert): ?>
                        <?php
                        $type = isset($alert['type']) && in_array($alert['type'], ['info', 'success', 'warning', 'error'], true)
                            ? $alert['type']
                            : 'info';
                        ?>
                        <div class="bjlg-dashboard-widget__alert bjlg-dashboard-widget__alert--<?php echo esc_attr($type); ?>">
                            <div class="bjlg-dashboard-widget__alert-body">
                                <?php if (!empty($alert['title'])): ?>
                                    <strong><?php echo esc_html($alert['title']); ?></strong>
                                <?php endif; ?>
                                <?php if (!empty($alert['message'])): ?>
                                    <p><?php echo esc_html($alert['message']); ?></p>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($alert['action']['label']) && !empty($alert['action']['url'])): ?>
                                <a class="bjlg-dashboard-widget__alert-link" href="<?php echo esc_url($alert['action']['url']); ?>">
                                    <?php echo esc_html($alert['action']['label']); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="bjlg-dashboard-widget__recent">
                <h4 class="bjlg-dashboard-widget__section-title"><?php esc_html_e('Dernières archives', 'backup-jlg'); ?></h4>
                <?php if (!empty($backups)): ?>
                    <ul class="bjlg-dashboard-widget__backup-list">
                        <?php foreach ($backups as $backup): ?>
                            <li class="bjlg-dashboard-widget__backup-item">
                                <span class="bjlg-dashboard-widget__backup-name"><?php echo esc_html($backup['filename']); ?></span>
                                <span class="bjlg-dashboard-widget__backup-meta">
                                    <?php echo esc_html($backup['created_at_relative']); ?>
                                    <?php if (!empty($backup['size'])): ?>
                                        · <?php echo esc_html($backup['size']); ?>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="bjlg-dashboard-widget__empty"><?php esc_html_e('Aucune sauvegarde récente disponible.', 'backup-jlg'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Build the data displayed inside the dashboard widget.
     */
    private function get_dashboard_widget_snapshot(): array {
        if (!function_exists('bjlg_can_manage_plugin') || !bjlg_can_manage_plugin()) {
            return [
                'ok' => false,
                'error' => __('Vous n’avez pas l’autorisation de consulter ces informations.', 'backup-jlg'),
            ];
        }

        if (!$this->advanced_admin) {
            return [
                'ok' => false,
                'error' => __('Les métriques du tableau de bord ne sont pas disponibles.', 'backup-jlg'),
            ];
        }

        $metrics = $this->advanced_admin->get_dashboard_metrics();
        $summary = $metrics['summary'] ?? [];
        $alerts = $metrics['alerts'] ?? [];
        $generated_at = $metrics['generated_at'] ?? '';

        return [
            'ok' => true,
            'summary' => $summary,
            'alerts' => $alerts,
            'generated_at' => $generated_at,
            'backups' => $this->get_recent_backups(self::DASHBOARD_RECENT_BACKUPS_LIMIT),
            'actions' => $this->get_dashboard_widget_actions(),
        ];
    }

    /**
     * Provide action links used in the dashboard widget.
     */
    private function get_dashboard_widget_actions(): array {
        $backup_tab_url = add_query_arg(
            [
                'page' => 'backup-jlg',
                'section' => 'backup',
            ],
            admin_url('admin.php')
        );

        return [
            'backup' => [
                'label' => __('Lancer une sauvegarde', 'backup-jlg'),
                'url' => $backup_tab_url . '#bjlg-backup-creation-form',
            ],
            'restore' => [
                'label' => __('Restaurer une archive', 'backup-jlg'),
                'url' => $backup_tab_url . '#bjlg-restore-form',
            ],
        ];
    }

    /**
     * Retrieve the most recent backups to display in the dashboard widget.
     */
    private function get_recent_backups(int $limit): array {
        if (!function_exists('rest_do_request')) {
            return [];
        }

        $limit = max(1, $limit);
        $request = new WP_REST_Request('GET', '/backup-jlg/v1/backups');
        $request->set_param('per_page', $limit);

        $response = rest_do_request($request);

        if (!($response instanceof WP_REST_Response)) {
            return [];
        }

        $data = $response->get_data();

        if (!is_array($data) || empty($data['backups']) || !is_array($data['backups'])) {
            return [];
        }

        $now = current_time('timestamp');
        $prepared = [];

        foreach ($data['backups'] as $backup) {
            if (!is_array($backup)) {
                continue;
            }

            $created_at = isset($backup['created_at']) ? strtotime((string) $backup['created_at']) : false;
            $prepared[] = [
                'filename' => $backup['filename'] ?? '',
                'size' => $backup['size_formatted'] ?? '',
                'created_at_relative' => $created_at ? sprintf(__('il y a %s', 'backup-jlg'), human_time_diff($created_at, $now)) : '',
            ];
        }

        return $prepared;
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
        $google_drive_unavailable = $this->is_google_drive_unavailable();
        $presets = BJLG_Settings::get_backup_presets();
        $presets_json = !empty($presets) ? wp_json_encode(array_values($presets)) : '';

        $include_suggestions = [
            [
                'value' => 'wp-content/uploads/**/*.webp',
                'label' => __('Médias WebP', 'backup-jlg'),
            ],
            [
                'value' => 'wp-content/mu-plugins/',
                'label' => __('MU-plugins', 'backup-jlg'),
            ],
            [
                'value' => 'wp-content/languages/*.mo',
                'label' => __('Fichiers de langue (.mo)', 'backup-jlg'),
            ],
        ];

        $exclude_suggestions = [
            [
                'value' => 'wp-content/cache/**/*',
                'label' => __('Caches WordPress', 'backup-jlg'),
            ],
            [
                'value' => 'node_modules/**/*',
                'label' => __('Dépendances node_modules', 'backup-jlg'),
            ],
            [
                'value' => 'vendor/**/*',
                'label' => __('Dossiers vendor compilés', 'backup-jlg'),
            ],
        ];

        $include_placeholder = isset($include_suggestions[0]['value']) ? $include_suggestions[0]['value'] : 'wp-content/uploads/**/*';
        $exclude_placeholder = isset($exclude_suggestions[0]['value']) ? $exclude_suggestions[0]['value'] : 'wp-content/cache/**/*';

        $backup_redirect = add_query_arg(
            [
                'page' => 'backup-jlg',
                'section' => 'backup',
            ],
            admin_url('admin.php')
        );
        $backup_redirect .= '#bjlg-backup-step-3';
        ?>
        <div class="bjlg-section">
            <h2>Créer une sauvegarde</h2>
            <form id="bjlg-backup-creation-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bjlg_create_backup', 'bjlg_create_backup_nonce'); ?>
                <input type="hidden" name="action" value="bjlg_create_backup">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url($backup_redirect); ?>">
                <p>Choisissez les composants à inclure dans votre sauvegarde.</p>
                <div class="bjlg-backup-steps" data-current-step="1">
                    <ol class="bjlg-backup-steps__nav" role="list">
                        <li class="bjlg-backup-steps__item is-active">
                            <button type="button"
                                    class="bjlg-backup-steps__button"
                                    data-step-target="1"
                                    aria-controls="bjlg-backup-step-1"
                                    aria-current="step">
                                1. Choix rapides
                            </button>
                        </li>
                        <li class="bjlg-backup-steps__item">
                            <button type="button"
                                    class="bjlg-backup-steps__button"
                                    data-step-target="2"
                                    aria-controls="bjlg-backup-step-2">
                                2. Options avancées
                            </button>
                        </li>
                        <li class="bjlg-backup-steps__item">
                            <button type="button"
                                    class="bjlg-backup-steps__button"
                                    data-step-target="3"
                                    aria-controls="bjlg-backup-step-3">
                                3. Confirmation
                            </button>
                        </li>
                    </ol>

                    <section class="bjlg-backup-step"
                             id="bjlg-backup-step-1"
                             data-step-index="1"
                             aria-labelledby="bjlg-backup-step-1-title">
                        <h3 id="bjlg-backup-step-1-title">Étape 1 — Choix rapides</h3>
                        <p>Appliquez un modèle existant ou configurez en quelques clics le contenu principal de la sauvegarde.</p>
                        <div class="bjlg-backup-presets"<?php echo $presets_json ? ' data-bjlg-presets=' . "'" . esc_attr($presets_json) . "'" : ''; ?>>
                            <h4>Modèles</h4>
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
                            <p class="description">Choisissez un modèle enregistré ou créez-en un nouveau pour accélérer vos futurs lancements.</p>
                            <p class="bjlg-backup-presets__status" role="status" aria-live="polite"></p>
                        </div>
                        <table class="form-table">
                            <tbody>
                                <tr>
                                    <th scope="row">Contenu de la sauvegarde</th>
                                    <td>
                                        <div class="bjlg-field-control">
                                            <fieldset aria-describedby="bjlg-backup-components-description">
                                                <legend class="bjlg-fieldset-title">Sélection des composants</legend>
                                                <label><input type="checkbox" name="backup_components[]" value="db" checked> <strong>Base de données</strong> <span class="description">Toutes les tables WordPress</span></label><br>
                                                <label><input type="checkbox" name="backup_components[]" value="plugins" checked> Extensions (<code>/wp-content/plugins</code>)</label><br>
                                                <label><input type="checkbox" name="backup_components[]" value="themes" checked> Thèmes (<code>/wp-content/themes</code>)</label><br>
                                                <label><input type="checkbox" name="backup_components[]" value="uploads" checked> Médias (<code>/wp-content/uploads</code>)</label>
                                            </fieldset>
                                            <p id="bjlg-backup-components-description" class="description">Décochez les éléments que vous ne souhaitez pas inclure dans le fichier final.</p>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Options essentielles</th>
                                    <td>
                                        <div class="bjlg-field-control">
                                            <fieldset aria-describedby="bjlg-backup-options-description">
                                                <legend class="bjlg-fieldset-title">Options principales</legend>
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
                                            <p id="bjlg-backup-options-description" class="description">Activez les optimisations essentielles avant de lancer la sauvegarde.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="bjlg-backup-step__actions">
                            <button type="button" class="button button-primary" data-step-action="next">Continuer vers les options avancées</button>
                        </div>
                    </section>

                    <section class="bjlg-backup-step"
                             id="bjlg-backup-step-2"
                             data-step-index="2"
                             aria-labelledby="bjlg-backup-step-2-title">
                        <h3 id="bjlg-backup-step-2-title">Étape 2 — Options avancées</h3>
                        <p>Ajustez précisément les dossiers inclus, les vérifications post-sauvegarde et les destinations secondaires.</p>
                        <table class="form-table bjlg-advanced-table">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Affinage avancé', 'backup-jlg'); ?></th>
                                    <td>
                                        <div class="bjlg-field-control bjlg-advanced-accordion" data-role="advanced-panels">
                                            <details class="bjlg-advanced-panel" data-panel="filters" open>
                                                <summary>
                                                    <span><?php esc_html_e('Motifs personnalisés', 'backup-jlg'); ?></span>
                                                </summary>
                                                <div class="bjlg-advanced-panel__content">
                                                    <p class="description"><?php echo wp_kses_post(__('Ajoutez des inclusions ou exclusions ciblées. Les jokers glob (<code>*</code>) sont pris en charge.', 'backup-jlg')); ?></p>
                                                    <div class="bjlg-pattern-columns">
                                                        <div class="bjlg-pattern-editor" data-pattern-type="include">
                                                            <label class="bjlg-pattern-editor__label" for="bjlg-include-patterns"><?php esc_html_e('Inclusions personnalisées', 'backup-jlg'); ?></label>
                                                            <textarea
                                                                id="bjlg-include-patterns"
                                                                name="include_patterns"
                                                                rows="6"
                                                                placeholder="<?php echo esc_attr($include_placeholder); ?>"
                                                                data-role="pattern-input"
                                                                data-pattern-type="include"
                                                            ><?php echo $include_text; ?></textarea>
                                                            <div class="bjlg-pattern-helper" data-role="pattern-helper" data-pattern-type="include">
                                                                <label class="bjlg-pattern-helper__label" for="bjlg-include-pattern-input"><?php esc_html_e('Ajouter un motif suggéré', 'backup-jlg'); ?></label>
                                                                <div class="bjlg-pattern-helper__controls">
                                                                    <input
                                                                        type="text"
                                                                        id="bjlg-include-pattern-input"
                                                                        class="regular-text"
                                                                        list="bjlg-include-patterns-datalist"
                                                                        data-role="pattern-autocomplete"
                                                                        data-pattern-type="include"
                                                                        placeholder="<?php echo esc_attr($include_placeholder); ?>"
                                                                    >
                                                                    <button type="button" class="button button-secondary" data-role="pattern-add" data-pattern-type="include"><?php esc_html_e('Ajouter', 'backup-jlg'); ?></button>
                                                                </div>
                                                                <datalist id="bjlg-include-patterns-datalist">
                                                                    <?php foreach ($include_suggestions as $suggestion): ?>
                                                                        <option value="<?php echo esc_attr($suggestion['value']); ?>"><?php echo esc_html($suggestion['label']); ?></option>
                                                                    <?php endforeach; ?>
                                                                </datalist>
                                                            </div>
                                                            <ul class="bjlg-pattern-suggestions" data-role="pattern-suggestions" aria-label="<?php esc_attr_e('Exemples rapides d\'inclusion', 'backup-jlg'); ?>">
                                                                <?php foreach ($include_suggestions as $suggestion): ?>
                                                                    <li>
                                                                        <button type="button" class="button-link bjlg-pattern-suggestions__item" data-pattern-value="<?php echo esc_attr($suggestion['value']); ?>"><?php echo esc_html($suggestion['label']); ?></button>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                            <p class="bjlg-pattern-feedback" data-role="pattern-feedback" data-pattern-type="include" data-success-message="<?php echo esc_attr__('Motifs valides.', 'backup-jlg'); ?>" data-error-message="<?php echo esc_attr__('Motifs non reconnus : ', 'backup-jlg'); ?>" aria-live="polite"></p>
                                                        </div>
                                                        <div class="bjlg-pattern-editor" data-pattern-type="exclude">
                                                            <label class="bjlg-pattern-editor__label" for="bjlg-exclude-patterns"><?php esc_html_e('Exclusions personnalisées', 'backup-jlg'); ?></label>
                                                            <textarea
                                                                id="bjlg-exclude-patterns"
                                                                name="exclude_patterns"
                                                                rows="6"
                                                                placeholder="<?php echo esc_attr($exclude_placeholder); ?>"
                                                                data-role="pattern-input"
                                                                data-pattern-type="exclude"
                                                            ><?php echo $exclude_text; ?></textarea>
                                                            <div class="bjlg-pattern-helper" data-role="pattern-helper" data-pattern-type="exclude">
                                                                <label class="bjlg-pattern-helper__label" for="bjlg-exclude-pattern-input"><?php esc_html_e('Ajouter un motif d\'exclusion', 'backup-jlg'); ?></label>
                                                                <div class="bjlg-pattern-helper__controls">
                                                                    <input
                                                                        type="text"
                                                                        id="bjlg-exclude-pattern-input"
                                                                        class="regular-text"
                                                                        list="bjlg-exclude-patterns-datalist"
                                                                        data-role="pattern-autocomplete"
                                                                        data-pattern-type="exclude"
                                                                        placeholder="<?php echo esc_attr($exclude_placeholder); ?>"
                                                                    >
                                                                    <button type="button" class="button button-secondary" data-role="pattern-add" data-pattern-type="exclude"><?php esc_html_e('Ajouter', 'backup-jlg'); ?></button>
                                                                </div>
                                                                <datalist id="bjlg-exclude-patterns-datalist">
                                                                    <?php foreach ($exclude_suggestions as $suggestion): ?>
                                                                        <option value="<?php echo esc_attr($suggestion['value']); ?>"><?php echo esc_html($suggestion['label']); ?></option>
                                                                    <?php endforeach; ?>
                                                                </datalist>
                                                            </div>
                                                            <ul class="bjlg-pattern-suggestions" data-role="pattern-suggestions" aria-label="<?php esc_attr_e('Exemples rapides d\'exclusion', 'backup-jlg'); ?>">
                                                                <?php foreach ($exclude_suggestions as $suggestion): ?>
                                                                    <li>
                                                                        <button type="button" class="button-link bjlg-pattern-suggestions__item" data-pattern-value="<?php echo esc_attr($suggestion['value']); ?>"><?php echo esc_html($suggestion['label']); ?></button>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                            <p class="bjlg-pattern-feedback" data-role="pattern-feedback" data-pattern-type="exclude" data-success-message="<?php echo esc_attr__('Motifs valides.', 'backup-jlg'); ?>" data-error-message="<?php echo esc_attr__('Motifs non reconnus : ', 'backup-jlg'); ?>" aria-live="polite"></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </details>

                                            <details class="bjlg-advanced-panel" data-panel="post-checks">
                                                <summary>
                                                    <span><?php esc_html_e('Vérifications post-sauvegarde', 'backup-jlg'); ?></span>
                                                </summary>
                                                <div class="bjlg-advanced-panel__content" aria-describedby="bjlg-post-checks-description">
                                                    <p class="description"><?php esc_html_e('Ajoutez des contrôles automatiques pour valider l’archive une fois générée.', 'backup-jlg'); ?></p>
                                                    <div class="bjlg-advanced-fieldset">
                                                        <label for="bjlg-post-checks-checksum">
                                                            <input type="checkbox"
                                                                   id="bjlg-post-checks-checksum"
                                                                   name="post_checks[]"
                                                                   value="checksum"
                                                                   <?php checked(!empty($post_checks['checksum'])); ?>>
                                                            <?php esc_html_e('Vérifier l’intégrité (SHA-256)', 'backup-jlg'); ?>
                                                        </label>
                                                        <p class="description"><?php esc_html_e('Calcule un hachage de l’archive pour détecter toute corruption.', 'backup-jlg'); ?></p>
                                                        <label for="bjlg-post-checks-dry-run">
                                                            <input type="checkbox"
                                                                   id="bjlg-post-checks-dry-run"
                                                                   name="post_checks[]"
                                                                   value="dry_run"
                                                                   <?php checked(!empty($post_checks['dry_run'])); ?>>
                                                            <?php esc_html_e('Test de restauration à blanc', 'backup-jlg'); ?>
                                                        </label>
                                                        <p class="description" id="bjlg-post-checks-description"><?php esc_html_e('Ouvre l’archive pour vérifier sa structure (hors archives chiffrées).', 'backup-jlg'); ?></p>
                                                    </div>
                                                </div>
                                            </details>

                                            <details class="bjlg-advanced-panel" data-panel="destinations">
                                                <summary>
                                                    <span><?php esc_html_e('Destinations secondaires', 'backup-jlg'); ?></span>
                                                </summary>
                                                <div class="bjlg-advanced-panel__content" aria-describedby="bjlg-secondary-destinations-description">
                                                    <p class="description"><?php esc_html_e('Diffusez la sauvegarde vers des services distants en complément du stockage local.', 'backup-jlg'); ?></p>
                                                    <div class="bjlg-advanced-fieldset">
                                                        <?php if (!empty($destination_choices)): ?>
                                                            <?php foreach ($destination_choices as $destination_id => $destination_label):
                                                                $is_google_drive = $destination_id === 'google_drive';
                                                                $is_unavailable = $is_google_drive && $google_drive_unavailable;
                                                                ?>
                                                                <div class="bjlg-destination-option-group">
                                                                    <label class="bjlg-destination-option">
                                                                        <input type="checkbox"
                                                                               name="secondary_destinations[]"
                                                                               value="<?php echo esc_attr($destination_id); ?>"
                                                                               <?php checked(in_array($destination_id, $secondary_destinations, true)); ?>
                                                                               <?php disabled($is_unavailable); ?>>
                                                                        <?php echo esc_html($destination_label); ?>
                                                                    </label>
                                                                    <?php if ($is_unavailable): ?>
                                                                        <p class="description bjlg-destination-unavailable"><?php echo esc_html($this->get_google_drive_unavailable_notice()); ?></p>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <p class="description"><?php esc_html_e('Aucune destination distante n’est configurée pour le moment.', 'backup-jlg'); ?></p>
                                                        <?php endif; ?>
                                                        <p class="description" id="bjlg-secondary-destinations-description"><?php esc_html_e('Les destinations sélectionnées seront tentées l’une après l’autre en cas d’échec.', 'backup-jlg'); ?></p>
                                                    </div>
                                                </div>
                                            </details>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="bjlg-backup-step__actions">
                            <button type="button" class="button button-secondary" data-step-action="prev">Retour aux choix rapides</button>
                            <button type="button" class="button button-primary" data-step-action="next">Accéder à la confirmation</button>
                        </div>
                    </section>

                    <section class="bjlg-backup-step"
                             id="bjlg-backup-step-3"
                             data-step-index="3"
                             aria-labelledby="bjlg-backup-step-3-title">
                        <h3 id="bjlg-backup-step-3-title">Étape 3 — Confirmation</h3>
                        <p>Revérifiez votre configuration avant de lancer la sauvegarde.</p>
                        <div class="bjlg-backup-summary" data-role="backup-summary">
                            <p class="description">Votre récapitulatif apparaîtra ici lorsque vous atteignez cette étape.</p>
                        </div>
                        <div class="notice notice-error" data-role="backup-summary-warning" style="display:none;">
                            <p>Veuillez sélectionner au moins un composant à sauvegarder avant de lancer l'opération.</p>
                        </div>
                        <div class="bjlg-backup-step__actions">
                            <button type="button" class="button button-secondary" data-step-action="prev">Modifier les options</button>
                            <button id="bjlg-create-backup" type="submit" class="button button-primary button-hero">
                                <span class="dashicons dashicons-backup" aria-hidden="true"></span> Lancer la création de la sauvegarde
                            </button>
                        </div>
                    </section>
                </div>
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
        ?>
        <div class="bjlg-section" id="bjlg-backup-list-section" data-default-page="1" data-default-per-page="10">
            <h2>Sauvegardes disponibles</h2>
            <p class="description">Consultez vos archives, filtrez-les et téléchargez-les en un clic.</p>
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
     * Section : Planification
     */
    private function render_schedule_section() {
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
        <div class="bjlg-section bjlg-schedule-section">
            <h2>Planification des sauvegardes</h2>
            <p class="description">Activez, suspendez ou inspectez vos tâches planifiées en un coup d’œil.</p>
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
                            $time = isset($schedule['time']) ? (string) $schedule['time'] : '';
                            $custom_cron = isset($schedule['custom_cron']) ? (string) $schedule['custom_cron'] : '';
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
                                $exclude_patterns,
                                $recurrence,
                                $schedule['custom_cron'] ?? ''
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
        </div>
        <?php
    }
    /**
     * Section : Restauration
     */
    private function render_restore_section() {
        $restore_redirect = add_query_arg(
            [
                'page' => 'backup-jlg',
                'section' => 'restore',
            ],
            admin_url('admin.php')
        );
        $restore_redirect .= '#bjlg-restore-form';

        ?>
        <div class="bjlg-section">
            <h2>Restaurer depuis un fichier</h2>
            <p>Si vous avez un fichier de sauvegarde sur votre ordinateur, vous pouvez le téléverser ici pour lancer une restauration.</p>
            <form id="bjlg-restore-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bjlg_restore_backup', 'bjlg_restore_backup_nonce'); ?>
                <input type="hidden" name="action" value="bjlg_restore_backup">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url($restore_redirect); ?>">
                <input type="hidden" name="restore_environment" value="production" data-role="restore-environment-field">
                <div class="bjlg-restore-username-field bjlg-screen-reader-only">
                    <label class="bjlg-screen-reader-only" for="bjlg-restore-username">Nom d'utilisateur</label>
                    <input type="text"
                           id="bjlg-restore-username"
                           name="username"
                           class="regular-text bjlg-screen-reader-only"
                           autocomplete="username"
                           aria-label="Nom d'utilisateur">
                </div>
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
                        <?php if (BJLG_Restore::user_can_use_sandbox()) : ?>
                        <tr>
                            <th scope="row">Environnement de test</th>
                            <td>
                                <div class="bjlg-field-control">
                                    <label>
                                        <input type="checkbox" name="restore_to_sandbox" value="1">
                                        Restaurer dans un environnement de test
                                    </label>
                                    <p class="description">Les fichiers seront restaurés dans un dossier isolé sans impacter la production.</p>
                                    <label for="bjlg-restore-sandbox-path" class="screen-reader-text">Chemin de la sandbox</label>
                                    <input type="text"
                                           id="bjlg-restore-sandbox-path"
                                           name="sandbox_path"
                                           class="regular-text"
                                           placeholder="Laisser vide pour utiliser le dossier sandbox automatique"
                                           disabled>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
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
                'remote_purge_failed' => true,
                'remote_purge_delayed' => true,
                'restore_self_test_passed' => false,
                'restore_self_test_failed' => true,
            ],
            'channels' => [
                'email' => ['enabled' => false],
                'slack' => ['enabled' => false, 'webhook_url' => ''],
                'discord' => ['enabled' => false, 'webhook_url' => ''],
                'teams' => ['enabled' => false, 'webhook_url' => ''],
                'sms' => ['enabled' => false, 'webhook_url' => ''],
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

        $notification_settings['quiet_hours'] = isset($notification_settings['quiet_hours']) && is_array($notification_settings['quiet_hours'])
            ? wp_parse_args($notification_settings['quiet_hours'], $notification_defaults['quiet_hours'])
            : $notification_defaults['quiet_hours'];

        $notification_settings['escalation'] = isset($notification_settings['escalation']) && is_array($notification_settings['escalation'])
            ? wp_parse_args($notification_settings['escalation'], $notification_defaults['escalation'])
            : $notification_defaults['escalation'];

        if (!isset($notification_settings['escalation']['channels']) || !is_array($notification_settings['escalation']['channels'])) {
            $notification_settings['escalation']['channels'] = $notification_defaults['escalation']['channels'];
        } else {
            $notification_settings['escalation']['channels'] = wp_parse_args($notification_settings['escalation']['channels'], $notification_defaults['escalation']['channels']);
        }

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

        $quiet_settings = $notification_settings['quiet_hours'];
        $escalation_settings = $notification_settings['escalation'];
        $quiet_timezone_label = $quiet_settings['timezone'] !== ''
            ? $quiet_settings['timezone']
            : (function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC');

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
            <div class="bjlg-settings-destinations">
                <?php
                if (!empty($this->destinations)) {
                    foreach ($this->destinations as $destination) {
                        $destination->render_settings();
                    }
                } else {
                    echo '<p class="description">Aucune destination cloud configurée. Activez Google Drive ou Amazon S3 en complétant leurs réglages.</p>';
                }
                ?>
            </div>
            
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
            <form id="bjlg-webhook-tools" class="bjlg-webhook-form" aria-labelledby="bjlg-webhook-tools-title">
                <p id="bjlg-webhook-tools-title">Utilisez ce point de terminaison pour déclencher une sauvegarde à distance en toute sécurité :</p>
                <div class="bjlg-webhook-url bjlg-mb-10">
                    <label for="bjlg-webhook-endpoint" class="bjlg-label-block bjlg-fw-600">Point de terminaison</label>
                    <div class="bjlg-form-field-group">
                        <div class="bjlg-form-field-control">
                            <input type="text" id="bjlg-webhook-endpoint" readonly value="<?php echo esc_url(BJLG_Webhooks::get_webhook_endpoint()); ?>" class="regular-text code">
                        </div>
                        <div class="bjlg-form-field-actions">
                            <button type="button" class="button bjlg-copy-field" data-copy-target="#bjlg-webhook-endpoint">Copier l'URL</button>
                        </div>
                    </div>
                </div>
                <div class="bjlg-webhook-url bjlg-mb-10">
                    <label for="bjlg-webhook-key" class="bjlg-label-block bjlg-fw-600">Clé secrète</label>
                    <div class="bjlg-form-field-group bjlg-secret-field">
                        <div class="bjlg-form-field-control">
                            <input type="password"
                                   id="bjlg-webhook-key"
                                   readonly
                                   value="<?php echo esc_attr($webhook_key); ?>"
                                   class="regular-text code"
                                   data-lpignore="true">
                        </div>
                        <div class="bjlg-form-field-actions">
                            <button type="button"
                                    class="button bjlg-toggle-secret"
                                    data-target="#bjlg-webhook-key"
                                    data-label-show="Afficher la clé secrète"
                                    data-label-hide="Masquer la clé secrète"
                                    aria-label="Afficher la clé secrète"
                                    aria-pressed="false">
                                <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                <span class="screen-reader-text">Afficher la clé secrète</span>
                            </button>
                            <button type="button" class="button bjlg-copy-field" data-copy-target="#bjlg-webhook-key">Copier la clé</button>
                            <button type="button" class="button" id="bjlg-regenerate-webhook">Régénérer</button>
                        </div>
                    </div>
                </div>
                <p class="description">Envoyez une requête <strong>POST</strong> à l'URL ci-dessus en ajoutant l'en-tête <code><?php echo esc_html(BJLG_Webhooks::WEBHOOK_HEADER); ?></code> (ou <code>Authorization: Bearer &lt;clé&gt;</code>) contenant votre clé.</p>
                <pre class="code"><code><?php echo esc_html(sprintf("curl -X POST %s \\n  -H 'Content-Type: application/json' \\n  -H '%s: %s'", BJLG_Webhooks::get_webhook_endpoint(), BJLG_Webhooks::WEBHOOK_HEADER, $webhook_key)); ?></code></pre>
                <p class="description"><strong>Compatibilité :</strong> L'ancien format <code><?php echo esc_html(add_query_arg(BJLG_Webhooks::WEBHOOK_QUERY_VAR, 'VOTRE_CLE', home_url('/'))); ?></code> reste supporté provisoirement mais sera retiré après la période de transition.</p>
            </form>

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
            <form class="bjlg-settings-form bjlg-notification-preferences-form" data-success-message="Notifications mises à jour." data-error-message="Impossible de sauvegarder les notifications.">
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
                                <fieldset aria-describedby="bjlg-notifications-events-description">
                                    <legend class="screen-reader-text"><?php esc_html_e('Événements surveillés', 'backup-jlg'); ?></legend>
                                    <ul class="bjlg-checkbox-list" role="list">
                                        <li>
                                            <label for="bjlg-notify-backup-complete">
                                                <input type="checkbox" id="bjlg-notify-backup-complete" name="notify_backup_complete" <?php checked(!empty($notification_settings['events']['backup_complete'])); ?>>
                                                <span><?php esc_html_e('Sauvegarde terminée', 'backup-jlg'); ?></span>
                                            </label>
                                        </li>
                                        <li>
                                            <label for="bjlg-notify-backup-failed">
                                                <input type="checkbox" id="bjlg-notify-backup-failed" name="notify_backup_failed" <?php checked(!empty($notification_settings['events']['backup_failed'])); ?>>
                                                <span><?php esc_html_e('Échec de sauvegarde', 'backup-jlg'); ?></span>
                                            </label>
                                        </li>
                                        <li>
                                            <label for="bjlg-notify-cleanup-complete">
                                                <input type="checkbox" id="bjlg-notify-cleanup-complete" name="notify_cleanup_complete" <?php checked(!empty($notification_settings['events']['cleanup_complete'])); ?>>
                                                <span><?php esc_html_e('Nettoyage finalisé', 'backup-jlg'); ?></span>
                                            </label>
                                        </li>
                                        <li>
                                            <label for="bjlg-notify-storage-warning">
                                                <input type="checkbox" id="bjlg-notify-storage-warning" name="notify_storage_warning" <?php checked(!empty($notification_settings['events']['storage_warning'])); ?>>
                                                <span><?php esc_html_e('Alerte de stockage', 'backup-jlg'); ?></span>
                                            </label>
                                        </li>
                                        <li>
                                            <label for="bjlg-notify-remote-purge-failed">
                                                <input type="checkbox" id="bjlg-notify-remote-purge-failed" name="notify_remote_purge_failed" <?php checked(!empty($notification_settings['events']['remote_purge_failed'])); ?>>
                                                <span><?php esc_html_e('Purge distante en échec', 'backup-jlg'); ?></span>
                                            </label>
                                        </li>
                                        <li>
                                            <label for="bjlg-notify-remote-purge-delayed">
                                                <input type="checkbox" id="bjlg-notify-remote-purge-delayed" name="notify_remote_purge_delayed" <?php checked(!empty($notification_settings['events']['remote_purge_delayed'])); ?>>
                                                <span><?php esc_html_e('Purge distante en retard critique', 'backup-jlg'); ?></span>
                                            </label>
                                        </li>
                                        <li>
                                            <label for="bjlg-notify-restore-self-test-passed">
                                                <input type="checkbox" id="bjlg-notify-restore-self-test-passed" name="notify_restore_self_test_passed" <?php checked(!empty($notification_settings['events']['restore_self_test_passed'])); ?>>
                                                <span><?php esc_html_e('Test de restauration réussi', 'backup-jlg'); ?></span>
                                            </label>
                                        </li>
                                        <li>
                                            <label for="bjlg-notify-restore-self-test-failed">
                                                <input type="checkbox" id="bjlg-notify-restore-self-test-failed" name="notify_restore_self_test_failed" <?php checked(!empty($notification_settings['events']['restore_self_test_failed'])); ?>>
                                                <span><?php esc_html_e('Test de restauration en échec', 'backup-jlg'); ?></span>
                                            </label>
                                        </li>
                                    </ul>
                                </fieldset>
                                <p id="bjlg-notifications-events-description" class="description"><?php esc_html_e('Choisissez quels événements déclenchent un envoi de notification.', 'backup-jlg'); ?></p>
                            </div>
                        </td>
                    </tr>
                </table>

                <p class="submit"><button type="submit" class="button button-primary">Enregistrer les notifications</button></p>
            </form>

            <div class="bjlg-notification-test">
                <button
                    type="button"
                    class="button button-secondary bjlg-notification-test-button"
                    data-loading-label="<?php esc_attr_e('Envoi…', 'backup-jlg'); ?>"
                >
                    <?php esc_html_e('Envoyer une notification de test', 'backup-jlg'); ?>
                </button>
                <span class="spinner" aria-hidden="true"></span>
                <p class="description"><?php esc_html_e('La notification de test utilise les canaux et destinataires renseignés ci-dessus, même si les modifications ne sont pas encore enregistrées.', 'backup-jlg'); ?></p>
                <div class="notice bjlg-notification-test-feedback bjlg-hidden" role="status" aria-live="polite"></div>
            </div>

            <h3><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span> Canaux</h3>
            <form class="bjlg-settings-form bjlg-notification-channels-form" data-success-message="Canaux mis à jour." data-error-message="Impossible de mettre à jour les canaux.">
                <table class="form-table">
                    <tr>
                        <th scope="row">Canaux disponibles</th>
                        <td>
                            <div class="bjlg-field-control">
                                <fieldset aria-describedby="bjlg-notifications-channels-description">
                                    <legend class="screen-reader-text"><?php esc_html_e('Canaux de notification actifs', 'backup-jlg'); ?></legend>
                                    <ul class="bjlg-checkbox-list" role="list">
                                        <li>
                                            <label for="bjlg-channel-email">
                                                <input type="checkbox" id="bjlg-channel-email" name="channel_email" <?php checked(!empty($notification_settings['channels']['email']['enabled'])); ?>>
                                                <span><?php esc_html_e('E-mail', 'backup-jlg'); ?></span>
                                            </label>
                                        </li>
                                        <li>
                                            <label for="bjlg-channel-slack">
                                                <input type="checkbox" id="bjlg-channel-slack" name="channel_slack" <?php checked(!empty($notification_settings['channels']['slack']['enabled'])); ?>>
                                                <span><?php esc_html_e('Slack', 'backup-jlg'); ?></span>
                                            </label>
                                        </li>
                                        <li>
                                            <label for="bjlg-channel-discord">
                                                <input type="checkbox" id="bjlg-channel-discord" name="channel_discord" <?php checked(!empty($notification_settings['channels']['discord']['enabled'])); ?>>
                                                <span><?php esc_html_e('Discord', 'backup-jlg'); ?></span>
                                            </label>
                                        </li>
                                        <li>
                                            <label for="bjlg-channel-teams">
                                                <input type="checkbox" id="bjlg-channel-teams" name="channel_teams" <?php checked(!empty($notification_settings['channels']['teams']['enabled'])); ?>>
                                                <span><?php esc_html_e('Microsoft Teams', 'backup-jlg'); ?></span>
                                            </label>
                                        </li>
                                        <li>
                                            <label for="bjlg-channel-sms">
                                                <input type="checkbox" id="bjlg-channel-sms" name="channel_sms" <?php checked(!empty($notification_settings['channels']['sms']['enabled'])); ?>>
                                                <span><?php esc_html_e('SMS / webhook mobile', 'backup-jlg'); ?></span>
                                            </label>
                                        </li>
                                    </ul>
                                </fieldset>
                                <p id="bjlg-notifications-channels-description" class="description"><?php esc_html_e('Activez les canaux qui doivent recevoir vos notifications.', 'backup-jlg'); ?></p>
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
                    <tr>
                        <th scope="row">Webhook Microsoft Teams</th>
                        <td>
                            <div class="bjlg-field-control">
                                <input type="url" name="teams_webhook_url" class="regular-text" value="<?php echo esc_attr($notification_settings['channels']['teams']['webhook_url']); ?>" placeholder="https://outlook.office.com/webhook/...">
                                <p class="description">URL du webhook entrant Teams. Obligatoire si le canal Teams est activé.</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Webhook SMS</th>
                        <td>
                            <div class="bjlg-field-control">
                                <input type="url" name="sms_webhook_url" class="regular-text" value="<?php echo esc_attr($notification_settings['channels']['sms']['webhook_url']); ?>" placeholder="https://sms.example.com/hooks/...">
                                <p class="description">URL du webhook de votre passerelle SMS (Twilio, etc.). Obligatoire si le canal SMS est activé.</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Fenêtre de silence', 'backup-jlg'); ?></th>
                        <td>
                            <div class="bjlg-field-control bjlg-field-control--inline">
                                <label>
                                    <input type="checkbox" name="quiet_hours_enabled" <?php checked(!empty($quiet_settings['enabled'])); ?>>
                                    <?php esc_html_e('Activer une fenêtre de silence quotidienne', 'backup-jlg'); ?>
                                </label>
                                <div class="bjlg-field-grid bjlg-field-grid--compact">
                                    <label>
                                        <span class="bjlg-field-label"><?php esc_html_e('Début', 'backup-jlg'); ?></span>
                                        <input type="time" name="quiet_hours_start" value="<?php echo esc_attr($quiet_settings['start']); ?>" class="small-text">
                                    </label>
                                    <label>
                                        <span class="bjlg-field-label"><?php esc_html_e('Fin', 'backup-jlg'); ?></span>
                                        <input type="time" name="quiet_hours_end" value="<?php echo esc_attr($quiet_settings['end']); ?>" class="small-text">
                                    </label>
                                    <label>
                                        <span class="bjlg-field-label"><?php esc_html_e('Fuseau horaire', 'backup-jlg'); ?></span>
                                        <input type="text" name="quiet_hours_timezone" value="<?php echo esc_attr($quiet_settings['timezone']); ?>" class="regular-text" placeholder="<?php echo esc_attr($quiet_timezone_label); ?>">
                                    </label>
                                </div>
                                <label>
                                    <input type="checkbox" name="quiet_hours_allow_critical" <?php checked(!empty($quiet_settings['allow_critical'])); ?>>
                                    <?php esc_html_e('Laisser passer les événements critiques (échecs, retards).', 'backup-jlg'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Les alertes non critiques seront différées jusqu’à la fin de la fenêtre de silence.', 'backup-jlg'); ?></p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Escalade des alertes', 'backup-jlg'); ?></th>
                        <td>
                            <div class="bjlg-field-control">
                                <label>
                                    <input type="checkbox" name="escalation_enabled" <?php checked(!empty($escalation_settings['enabled'])); ?>>
                                    <?php esc_html_e('Relancer automatiquement les événements critiques sur d’autres canaux', 'backup-jlg'); ?>
                                </label>
                                <div class="bjlg-field-grid bjlg-field-grid--compact">
                                    <label>
                                        <span class="bjlg-field-label"><?php esc_html_e('Délai (minutes)', 'backup-jlg'); ?></span>
                                        <input type="number" name="escalation_delay" class="small-text" min="1" value="<?php echo esc_attr((int) $escalation_settings['delay_minutes']); ?>">
                                    </label>
                                    <label>
                                        <input type="checkbox" name="escalation_only_critical" <?php checked(!empty($escalation_settings['only_critical'])); ?>>
                                        <span><?php esc_html_e('Limiter aux événements critiques', 'backup-jlg'); ?></span>
                                    </label>
                                </div>
                                <fieldset>
                                    <legend class="screen-reader-text"><?php esc_html_e('Canaux d’escalade', 'backup-jlg'); ?></legend>
                                    <ul class="bjlg-checkbox-list" role="list">
                                        <li>
                                            <label><input type="checkbox" name="escalation_channel_email" <?php checked(!empty($escalation_settings['channels']['email'])); ?>> <?php esc_html_e('E-mail', 'backup-jlg'); ?></label>
                                        </li>
                                        <li>
                                            <label><input type="checkbox" name="escalation_channel_slack" <?php checked(!empty($escalation_settings['channels']['slack'])); ?>> Slack</label>
                                        </li>
                                        <li>
                                            <label><input type="checkbox" name="escalation_channel_discord" <?php checked(!empty($escalation_settings['channels']['discord'])); ?>> Discord</label>
                                        </li>
                                        <li>
                                            <label><input type="checkbox" name="escalation_channel_teams" <?php checked(!empty($escalation_settings['channels']['teams'])); ?>> Microsoft Teams</label>
                                        </li>
                                        <li>
                                            <label><input type="checkbox" name="escalation_channel_sms" <?php checked(!empty($escalation_settings['channels']['sms'])); ?>> <?php esc_html_e('SMS / webhook mobile', 'backup-jlg'); ?></label>
                                        </li>
                                    </ul>
                                </fieldset>
                                <p class="description"><?php esc_html_e('Sélectionnez les canaux supplémentaires qui seront sollicités après le délai configuré lorsque les alertes critiques ne sont pas résolues.', 'backup-jlg'); ?></p>
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

            <h3 id="bjlg-diagnostics-tests">Vérifier l'installation</h3>
            <p class="description">
                <?php esc_html_e('Lancez la suite de tests automatisés pour vérifier que l’environnement du plugin est opérationnel.', 'backup-jlg'); ?>
            </p>
            <ol>
                <li><?php esc_html_e('Ouvrez un terminal à la racine du plugin.', 'backup-jlg'); ?></li>
                <li><?php esc_html_e('Exécutez les dépendances Composer si nécessaire :', 'backup-jlg'); ?></li>
            </ol>
            <pre class="code"><code>composer install</code></pre>
            <ol start="3">
                <li><?php esc_html_e('Lancez ensuite les tests PHPUnit :', 'backup-jlg'); ?></li>
            </ol>
            <pre class="code"><code>composer test</code></pre>
            <p class="description">
                <?php esc_html_e('Tous les tests doivent être verts avant de mettre le plugin en production.', 'backup-jlg'); ?>
            </p>

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
                'azure_blob' => 'Azure Blob Storage',
                'backblaze_b2' => 'Backblaze B2',
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
        array $exclude_patterns = [],
        string $recurrence = 'disabled',
        string $custom_cron = ''
    ) {
        $recurrence_labels = [
            'disabled' => 'Désactivée',
            'every_five_minutes' => 'Toutes les 5 minutes',
            'every_fifteen_minutes' => 'Toutes les 15 minutes',
            'hourly' => 'Toutes les heures',
            'twice_daily' => 'Deux fois par jour',
            'daily' => 'Journalière',
            'weekly' => 'Hebdomadaire',
            'monthly' => 'Mensuelle',
            'custom' => 'Expression Cron personnalisée',
        ];

        $normalized_recurrence = trim(strtolower($recurrence));
        $frequency_label = $recurrence_labels[$normalized_recurrence] ?? ucfirst(str_replace('_', ' ', $recurrence));

        if ($normalized_recurrence === 'custom') {
            $custom_cron = trim($custom_cron);
            if ($custom_cron !== '') {
                $frequency_label = sprintf('%s (%s)', $frequency_label, $custom_cron);
            }
        }

        if ($frequency_label === '') {
            $frequency_label = '—';
        }

        $frequency_badges = [
            $this->format_schedule_badge($frequency_label, 'bjlg-badge-bg-indigo', 'bjlg-badge-frequency')
        ];

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

        return $this->wrap_schedule_badge_group('Fréquence', $frequency_badges)
            . $this->wrap_schedule_badge_group('Composants', $component_badges)
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
        $custom_cron = isset($schedule['custom_cron']) ? (string) $schedule['custom_cron'] : '';
        $day_of_month = isset($schedule['day_of_month']) ? (int) $schedule['day_of_month'] : 1;
        if ($day_of_month < 1 || $day_of_month > 31) {
            $day_of_month = 1;
        }
        $previous_recurrence = isset($schedule['previous_recurrence']) ? (string) $schedule['previous_recurrence'] : '';

        $schedule_components = isset($schedule['components']) && is_array($schedule['components']) ? $schedule['components'] : [];
        $include_patterns = isset($schedule['include_patterns']) && is_array($schedule['include_patterns']) ? $schedule['include_patterns'] : [];
        $exclude_patterns = isset($schedule['exclude_patterns']) && is_array($schedule['exclude_patterns']) ? $schedule['exclude_patterns'] : [];
        $post_checks = isset($schedule['post_checks']) && is_array($schedule['post_checks']) ? $schedule['post_checks'] : [];
        $secondary_destinations = isset($schedule['secondary_destinations']) && is_array($schedule['secondary_destinations'])
            ? $schedule['secondary_destinations']
            : [];

        $google_drive_unavailable = $this->is_google_drive_unavailable();

        $encrypt_enabled = !empty($schedule['encrypt']);
        $incremental_enabled = !empty($schedule['incremental']);

        $include_text = esc_textarea(implode("\n", array_map('strval', $include_patterns)));
        $exclude_text = esc_textarea(implode("\n", array_map('strval', $exclude_patterns)));

        $weekly_hidden = $recurrence !== 'weekly';
        $monthly_hidden = $recurrence !== 'monthly';
        $time_hidden = in_array($recurrence, ['disabled', 'custom'], true);
        $custom_hidden = $recurrence !== 'custom';
        $weekly_classes = 'bjlg-schedule-weekly-options' . ($weekly_hidden ? ' bjlg-hidden' : '');
        $monthly_classes = 'bjlg-schedule-monthly-options' . ($monthly_hidden ? ' bjlg-hidden' : '');
        $time_classes = 'bjlg-schedule-time-options' . ($time_hidden ? ' bjlg-hidden' : '');
        $custom_classes = 'bjlg-schedule-custom-options' . ($custom_hidden ? ' bjlg-hidden' : '');

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
        $custom_id_template = 'bjlg-schedule-custom-%s';
        $custom_description_id_template = 'bjlg-schedule-custom-%s-description';
        $day_of_month_id_template = 'bjlg-schedule-day-of-month-%s';
        $day_of_month_description_id_template = 'bjlg-schedule-day-of-month-%s-description';
        $include_id_template = 'bjlg-schedule-include-%s';
        $exclude_id_template = 'bjlg-schedule-exclude-%s';
        $custom_id_template = 'bjlg-schedule-custom-%s';
        $custom_description_id_template = 'bjlg-schedule-custom-%s-description';
        $time_id = sprintf($time_id_template, $field_prefix);
        $time_description_id = sprintf($time_description_id_template, $field_prefix);
        $custom_id = sprintf($custom_id_template, $field_prefix);
        $custom_description_id = sprintf($custom_description_id_template, $field_prefix);
        $day_of_month_id = sprintf($day_of_month_id_template, $field_prefix);
        $day_of_month_description_id = sprintf($day_of_month_description_id_template, $field_prefix);
        $include_id = sprintf($include_id_template, $field_prefix);
        $exclude_id = sprintf($exclude_id_template, $field_prefix);
        $custom_id = sprintf($custom_id_template, $field_prefix);
        $custom_description_id = sprintf($custom_description_id_template, $field_prefix);

        $summary_html = $this->get_schedule_summary_markup(
            $schedule_components,
            $encrypt_enabled,
            $incremental_enabled,
            $post_checks,
            $secondary_destinations,
            $include_patterns,
            $exclude_patterns,
            $recurrence,
            $schedule['custom_cron'] ?? ''
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
                                <option value="every_five_minutes" <?php selected($recurrence, 'every_five_minutes'); ?>>Toutes les 5 minutes</option>
                                <option value="every_fifteen_minutes" <?php selected($recurrence, 'every_fifteen_minutes'); ?>>Toutes les 15 minutes</option>
                                <option value="hourly" <?php selected($recurrence, 'hourly'); ?>>Toutes les heures</option>
                                <option value="twice_daily" <?php selected($recurrence, 'twice_daily'); ?>>Deux fois par jour</option>
                                <option value="daily" <?php selected($recurrence, 'daily'); ?>>Journalière</option>
                                <option value="weekly" <?php selected($recurrence, 'weekly'); ?>>Hebdomadaire</option>
                                <option value="monthly" <?php selected($recurrence, 'monthly'); ?>>Mensuelle</option>
                                <option value="custom" <?php selected($recurrence, 'custom'); ?>>Expression Cron</option>
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
                    <tr class="<?php echo esc_attr($monthly_classes); ?>" aria-hidden="<?php echo esc_attr($monthly_hidden ? 'true' : 'false'); ?>">
                        <th scope="row"><label for="<?php echo esc_attr($day_of_month_id); ?>" data-for-template="bjlg-schedule-day-of-month-%s">Jour du mois</label></th>
                        <td>
                            <input type="number"
                                   id="<?php echo esc_attr($day_of_month_id); ?>"
                                   class="small-text"
                                   data-field="day_of_month"
                                   data-id-template="bjlg-schedule-day-of-month-%s"
                                   data-describedby-template="bjlg-schedule-day-of-month-%s-description"
                                   name="schedules[<?php echo esc_attr($field_prefix); ?>][day_of_month]"
                                   value="<?php echo esc_attr((string) $day_of_month); ?>"
                                   min="1"
                                   max="31"
                                   aria-describedby="<?php echo esc_attr($day_of_month_description_id); ?>">
                            <p id="<?php echo esc_attr($day_of_month_description_id); ?>"
                               class="description"
                               data-id-template="bjlg-schedule-day-of-month-%s-description">
                                Choisissez un jour entre 1 et 31. Le dernier jour sera ajusté selon le mois.
                            </p>
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
                    <tr class="<?php echo esc_attr($custom_classes); ?>" aria-hidden="<?php echo esc_attr($custom_hidden ? 'true' : 'false'); ?>">
                        <th scope="row"><label for="<?php echo esc_attr($custom_id); ?>" data-for-template="bjlg-schedule-custom-%s">Expression Cron</label></th>
                        <td>
                            <input type="text"
                                   id="<?php echo esc_attr($custom_id); ?>"
                                   class="regular-text code"
                                   data-field="custom_cron"
                                   data-id-template="bjlg-schedule-custom-%s"
                                   data-describedby-template="bjlg-schedule-custom-%s-description"
                                   name="schedules[<?php echo esc_attr($field_prefix); ?>][custom_cron]"
                                   value="<?php echo esc_attr($schedule['custom_cron'] ?? ''); ?>"
                                   placeholder="0 3 * * mon-fri"
                                   aria-describedby="<?php echo esc_attr($custom_description_id); ?>">
                            <p id="<?php echo esc_attr($custom_description_id); ?>" class="description" data-id-template="bjlg-schedule-custom-%s-description">
                                Utilisez une expression Cron standard à cinq champs (minute, heure, jour du mois, mois, jour de semaine).
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Composants</th>
                        <td>
                            <fieldset aria-describedby="<?php echo esc_attr($field_prefix); ?>-components-help">
                                <legend class="bjlg-fieldset-title">Choisir les éléments à inclure</legend>
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
                                <p id="<?php echo esc_attr($field_prefix); ?>-components-help" class="description">Ces composants seront sauvegardés pour chaque occurrence de cette planification.</p>
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
                                <?php foreach ($destination_choices as $destination_id => $destination_label):
                                    $is_google_drive = $destination_id === 'google_drive';
                                    $is_unavailable = $is_google_drive && $google_drive_unavailable;
                                    ?>
                                    <div class="bjlg-destination-option-group">
                                        <label class="bjlg-label-block bjlg-destination-option">
                                            <input type="checkbox"
                                                   data-field="secondary_destinations"
                                                   name="schedules[<?php echo esc_attr($field_prefix); ?>][secondary_destinations][]"
                                                   value="<?php echo esc_attr($destination_id); ?>"
                                                   <?php checked(in_array($destination_id, $secondary_destinations, true)); ?>
                                                   <?php disabled($is_unavailable); ?>>
                                            <?php echo esc_html($destination_label); ?>
                                        </label>
                                        <?php if ($is_unavailable): ?>
                                            <p class="description bjlg-destination-unavailable"><?php echo esc_html($this->get_google_drive_unavailable_notice()); ?></p>
                                        <?php endif; ?>
                                    </div>
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
