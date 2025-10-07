<?php
namespace BJLG;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the registration of the block editor features for Backup JLG.
 */
class BJLG_Blocks {
    private const BLOCK_METADATA_PATH = BJLG_PLUGIN_DIR;
    private const RECENT_BACKUPS_LIMIT = 3;

    private static $snapshot = null;

    public function __construct() {
        add_action('init', [$this, 'register_blocks']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
        add_action('enqueue_block_assets', [$this, 'enqueue_block_assets']);
    }

    public function register_blocks() {
        if (!function_exists('register_block_type_from_metadata')) {
            return;
        }

        $this->register_assets();

        register_block_type_from_metadata(
            self::BLOCK_METADATA_PATH,
            [
                'render_callback' => [$this, 'render_status_block'],
            ]
        );
    }

    public function register_rest_routes() {
        register_rest_route(
            'backup-jlg/v1',
            '/block-status',
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_get_status_snapshot'],
                'permission_callback' => function() {
                    return function_exists('bjlg_can_manage_plugin') ? bjlg_can_manage_plugin() : current_user_can('manage_options');
                },
            ]
        );
    }

    public function enqueue_block_editor_assets() {
        $this->register_assets();

        wp_enqueue_script('bjlg-block-status-editor');
        wp_enqueue_style('bjlg-block-status-style');

        wp_localize_script(
            'bjlg-block-status-editor',
            'BJLGBlockStatusSettings',
            [
                'endpoint' => '/backup-jlg/v1/block-status',
                'root' => esc_url_raw(rest_url()),
                'nonce' => wp_create_nonce('wp_rest'),
                'snapshot' => $this->get_status_snapshot(),
                'i18n' => [
                    'loading' => __('Chargement des métriques…', 'backup-jlg'),
                    'error' => __('Impossible de charger l’état des sauvegardes.', 'backup-jlg'),
                    'forbidden' => __('Vous n’avez pas les droits suffisants pour afficher ce bloc.', 'backup-jlg'),
                ],
            ]
        );
    }

    public function enqueue_block_assets() {
        $this->register_assets();
        wp_enqueue_style('bjlg-block-status-style');
    }

    public function render_status_block($attributes, $content, $block) {
        if (!function_exists('bjlg_can_manage_plugin') || !bjlg_can_manage_plugin()) {
            return $this->render_notice(__('Vous n’avez pas l’autorisation d’afficher ce bloc.', 'backup-jlg'));
        }

        $snapshot = $this->get_status_snapshot();

        if (!is_array($snapshot) || empty($snapshot['ok'])) {
            $message = isset($snapshot['error']['message']) ? $snapshot['error']['message'] : __('Aucune donnée de sauvegarde disponible pour le moment.', 'backup-jlg');
            return $this->render_notice($message);
        }

        $attributes = wp_parse_args(
            is_array($attributes) ? $attributes : [],
            [
                'showAlerts' => true,
                'showRecentBackups' => true,
                'showLaunchButton' => true,
            ]
        );

        $snapshot_attr = wp_json_encode($snapshot);

        $wrapper_attributes = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes([
                'class' => 'bjlg-block-status',
                'data-bjlg-status' => $snapshot_attr,
            ])
            : sprintf('class="bjlg-block-status" data-bjlg-status="%s"', esc_attr($snapshot_attr));

        ob_start();
        ?>
        <section <?php echo $wrapper_attributes; ?>>
            <header class="bjlg-block-status__header">
                <h3 class="bjlg-block-status__title"><?php esc_html_e('Vue d’ensemble des sauvegardes', 'backup-jlg'); ?></h3>
                <?php if (!empty($snapshot['generated_at'])): ?>
                    <span class="bjlg-block-status__timestamp"><?php echo esc_html(sprintf(__('Actualisé le %s', 'backup-jlg'), $snapshot['generated_at'])); ?></span>
                <?php endif; ?>
            </header>

            <div class="bjlg-block-status__summary" role="status" aria-live="polite" aria-atomic="true">
                <div class="bjlg-block-status__stat">
                    <span class="bjlg-block-status__stat-label"><?php esc_html_e('Dernière sauvegarde', 'backup-jlg'); ?></span>
                    <span class="bjlg-block-status__stat-value"><?php echo esc_html($snapshot['summary']['history_last_backup'] ?? __('N/A', 'backup-jlg')); ?></span>
                    <?php if (!empty($snapshot['summary']['history_last_backup_relative'])): ?>
                        <span class="bjlg-block-status__stat-meta"><?php echo esc_html($snapshot['summary']['history_last_backup_relative']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="bjlg-block-status__stat">
                    <span class="bjlg-block-status__stat-label"><?php esc_html_e('Prochaine sauvegarde planifiée', 'backup-jlg'); ?></span>
                    <span class="bjlg-block-status__stat-value"><?php echo esc_html($snapshot['summary']['scheduler_next_run'] ?? __('N/A', 'backup-jlg')); ?></span>
                    <?php if (!empty($snapshot['summary']['scheduler_next_run_relative'])): ?>
                        <span class="bjlg-block-status__stat-meta"><?php echo esc_html($snapshot['summary']['scheduler_next_run_relative']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="bjlg-block-status__stat">
                    <span class="bjlg-block-status__stat-label"><?php esc_html_e('Archives stockées', 'backup-jlg'); ?></span>
                    <span class="bjlg-block-status__stat-value"><?php echo esc_html(number_format_i18n($snapshot['summary']['storage_backup_count'] ?? 0)); ?></span>
                    <?php if (!empty($snapshot['summary']['storage_total_size_human'])): ?>
                        <span class="bjlg-block-status__stat-meta"><?php echo esc_html($snapshot['summary']['storage_total_size_human']); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($attributes['showLaunchButton']) && !empty($snapshot['actions']['backup']['url'])): ?>
                <div class="bjlg-block-status__actions">
                    <a class="bjlg-block-status__button" href="<?php echo esc_url($snapshot['actions']['backup']['url']); ?>">
                        <?php echo esc_html($snapshot['actions']['backup']['label']); ?>
                    </a>
                    <?php if (!empty($snapshot['actions']['restore']['url'])): ?>
                        <a class="bjlg-block-status__button bjlg-block-status__button--secondary" href="<?php echo esc_url($snapshot['actions']['restore']['url']); ?>">
                            <?php echo esc_html($snapshot['actions']['restore']['label']); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($attributes['showAlerts']) && !empty($snapshot['alerts'])): ?>
                <div class="bjlg-block-status__alerts" role="status" aria-live="polite" aria-atomic="true">
                    <?php foreach ($snapshot['alerts'] as $alert): ?>
                        <div class="bjlg-block-status__alert bjlg-block-status__alert--<?php echo esc_attr($alert['type'] ?? 'info'); ?>">
                            <div class="bjlg-block-status__alert-body">
                                <strong><?php echo esc_html($alert['title'] ?? ''); ?></strong>
                                <?php if (!empty($alert['message'])): ?>
                                    <p><?php echo esc_html($alert['message']); ?></p>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($alert['action']['url']) && !empty($alert['action']['label'])): ?>
                                <a class="bjlg-block-status__alert-link" href="<?php echo esc_url($alert['action']['url']); ?>"><?php echo esc_html($alert['action']['label']); ?></a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($attributes['showRecentBackups'])): ?>
                <div class="bjlg-block-status__recent">
                    <h4 class="bjlg-block-status__section-title"><?php esc_html_e('Dernières archives', 'backup-jlg'); ?></h4>
                    <?php if (!empty($snapshot['backups'])): ?>
                        <ul class="bjlg-block-status__backup-list">
                            <?php foreach ($snapshot['backups'] as $backup): ?>
                                <li class="bjlg-block-status__backup-item">
                                    <span class="bjlg-block-status__backup-name"><?php echo esc_html($backup['filename']); ?></span>
                                    <span class="bjlg-block-status__backup-meta">
                                        <?php echo esc_html($backup['created_at_relative'] ?? ''); ?>
                                        <?php if (!empty($backup['size'])): ?>
                                            · <?php echo esc_html($backup['size']); ?>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="bjlg-block-status__empty"><?php esc_html_e('Aucune sauvegarde récente disponible.', 'backup-jlg'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public function rest_get_status_snapshot(WP_REST_Request $request) {
        $snapshot = $this->get_status_snapshot();

        if (!is_array($snapshot)) {
            return new WP_Error('bjlg_block_snapshot_error', __('Impossible de récupérer les données du bloc.', 'backup-jlg'), ['status' => 500]);
        }

        if (empty($snapshot['ok'])) {
            return new WP_REST_Response($snapshot, 200);
        }

        return rest_ensure_response($snapshot);
    }

    private function register_assets() {
        if (!wp_script_is('bjlg-block-status-editor', 'registered')) {
            $deps = ['wp-blocks', 'wp-i18n', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data', 'wp-api-fetch'];
            $version = BJLG_VERSION;
            $asset_path = BJLG_PLUGIN_DIR . 'assets/js/block-status.asset.php';
            if (file_exists($asset_path)) {
                $asset = include $asset_path;
                if (is_array($asset)) {
                    $deps = $asset['dependencies'] ?? $deps;
                    $version = $asset['version'] ?? $version;
                }
            }

            wp_register_script(
                'bjlg-block-status-editor',
                BJLG_PLUGIN_URL . 'assets/js/block-status.js',
                $deps,
                $version,
                true
            );
            if (function_exists('wp_set_script_translations')) {
                wp_set_script_translations('bjlg-block-status-editor', 'backup-jlg', BJLG_PLUGIN_DIR . 'languages');
            }
        }

        if (!wp_style_is('bjlg-block-status-style', 'registered')) {
            $style_path = BJLG_PLUGIN_DIR . 'assets/css/block-status.css';
            $version = file_exists($style_path) ? filemtime($style_path) : BJLG_VERSION;
            wp_register_style(
                'bjlg-block-status-style',
                BJLG_PLUGIN_URL . 'assets/css/block-status.css',
                [],
                $version
            );
        }
    }

    private function get_status_snapshot() {
        if (self::$snapshot !== null) {
            return self::$snapshot;
        }

        if (!function_exists('bjlg_can_manage_plugin') || !bjlg_can_manage_plugin()) {
            self::$snapshot = [
                'ok' => false,
                'error' => [
                    'code' => 'forbidden',
                    'message' => __('Vous n’avez pas l’autorisation de consulter ces informations.', 'backup-jlg'),
                ],
            ];
            return self::$snapshot;
        }

        if (!class_exists(BJLG_Admin_Advanced::class)) {
            self::$snapshot = [
                'ok' => false,
                'error' => [
                    'code' => 'missing_metrics',
                    'message' => __('Les métriques du tableau de bord ne sont pas disponibles.', 'backup-jlg'),
                ],
            ];
            return self::$snapshot;
        }

        $advanced = new BJLG_Admin_Advanced();
        $metrics = $advanced->get_dashboard_metrics();

        $summary = $metrics['summary'] ?? [];
        $alerts = $metrics['alerts'] ?? [];
        $generated_at = $metrics['generated_at'] ?? '';
        $backups = $this->get_recent_backups();

        $actions = $this->get_actions_links();

        self::$snapshot = [
            'ok' => true,
            'generated_at' => $generated_at,
            'summary' => $summary,
            'alerts' => $alerts,
            'backups' => $backups,
            'actions' => $actions,
        ];

        return self::$snapshot;
    }

    private function get_actions_links(): array {
        $backup_tab_url = add_query_arg(
            [
                'page' => 'backup-jlg',
                'tab' => 'backup_restore',
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

    private function get_recent_backups(): array {
        if (!function_exists('rest_do_request')) {
            return [];
        }

        $request = new WP_REST_Request('GET', '/backup-jlg/v1/backups');
        $request->set_param('per_page', self::RECENT_BACKUPS_LIMIT);
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
                'id' => $backup['id'] ?? '',
                'filename' => $backup['filename'] ?? '',
                'type' => $backup['type'] ?? '',
                'size' => $backup['size_formatted'] ?? '',
                'created_at' => $created_at ? $this->format_datetime($created_at) : '',
                'created_at_relative' => $created_at ? sprintf(__('il y a %s', 'backup-jlg'), human_time_diff($created_at, $now)) : '',
            ];
        }

        return $prepared;
    }

    private function format_datetime(int $timestamp): string {
        if (function_exists('wp_date')) {
            return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    private function render_notice(string $message): string {
        return sprintf('<div class="bjlg-block-status__notice">%s</div>', esc_html($message));
    }
}
