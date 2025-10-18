<?php
if (!defined('ABSPATH')) exit;

/**
 * Page d’admin Debug & Modules (fonctionne même si d’autres modules sont KO).
 */
class BJLG_Debug_UI {

    private const NONCE_ACTION = 'bjlg_module_selfcheck';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu'], 9); // priorité haute pour garantir l’enregistrement
        add_action('admin_post_bjlg_save_modules', [$this, 'save_modules']);
        add_action('wp_ajax_bjlg_module_selfcheck', [$this, 'ajax_selfcheck']);
        add_action('wp_ajax_bjlg_log_module_selfcheck_error', [$this, 'ajax_log_module_failure']);
    }

    public function menu() {
        // Top level
        add_menu_page(
            'Backup JLG',
            'Backup JLG',
            'manage_options',
            'bjlg-backup',
            [$this, 'render_redirect'],
            'dashicons-shield-alt',
            56
        );

        // Sous-menu Debug & Modules (la page qui nous intéresse)
        add_submenu_page(
            'bjlg-backup',
            'Debug & Modules',
            'Debug & Modules',
            'manage_options',
            'bjlg-debug-modules',
            [$this, 'render']
        );

        // Fallback : si pour une raison X la sous-page n’existe pas, on la crée en page d’options
        if (!isset($GLOBALS['submenu']['bjlg-backup'])) {
            add_options_page(
                'Debug & Modules (Fallback)',
                'Backup JLG (Fallback)',
                'manage_options',
                'bjlg-debug-modules',
                [$this, 'render']
            );
        }
    }

    public function render_redirect() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        wp_safe_redirect(admin_url('admin.php?page=bjlg-debug-modules'));
        exit;
    }

    public function render() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        // Valeurs
        $safe_mode = (int) \bjlg_get_option('bjlg_safe_mode', 1);
        $enabled   = function_exists('BJLG_Module_Manager::enabled_slugs') ? BJLG_Module_Manager::enabled_slugs() : \bjlg_get_option('bjlg_enabled_modules', []);
        $enabled   = is_array($enabled) ? $enabled : [];
        $files     = function_exists('BJLG_Module_Manager::get_all_files') ? BJLG_Module_Manager::get_all_files() : [];

        // Si le manager n’est pas là (cas extrême), liste “class-bjlg-*.php”
        if (!$files) {
            $scan = glob(plugin_dir_path(BJLG_PLUGIN_FILE) . 'class-bjlg-*.php') ?: [];
            foreach ($scan as $p) { $files[] = basename($p); }
        }

        $saved = isset($_GET['saved']);
        $total_modules = is_array($files) ? count($files) : 0;

        ?>
        <div class="wrap">
            <h1>Backup JLG — Debug & Modules</h1>
            <p>Cette page est toujours présente (fallback). Si tu la vois, c’est que le noyau s’est chargé correctement.</p>

            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible" role="status" aria-live="polite">
                    <p>Les préférences ont été enregistrées.</p>
                </div>
            <?php endif; ?>

            <?php if ($safe_mode): ?>
                <div class="notice notice-warning" role="status" aria-live="polite">
                    <p>Le Safe Mode est actif : seuls les modules essentiels sont chargés tant que vous ne le désactivez pas.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="bjlg-debug-form">
                <?php wp_nonce_field('bjlg_save_modules'); ?>
                <input type="hidden" name="action" value="bjlg_save_modules" />

                <h2>Safe Mode</h2>
                <p id="bjlg-safe-mode-help" class="description">Ce mode charge uniquement le noyau du plugin pour permettre un diagnostic en environnement dégradé.</p>
                <label for="bjlg-safe-mode-toggle" class="bjlg-safe-mode-toggle">
                    <input type="checkbox" id="bjlg-safe-mode-toggle" name="safe_mode" value="1" <?php checked($safe_mode, 1); ?> aria-describedby="bjlg-safe-mode-help" />
                    <span>Activer le Safe Mode (ne charge que le noyau)</span>
                </label>

                <hr/>

                <h2>Modules optionnels</h2>
                <?php if (empty($files)): ?>
                    <p><em>Aucun fichier class-bjlg-*.php détecté.</em></p>
                <?php else: ?>
                    <div class="bjlg-module-toolbar">
                        <label for="bjlg-module-filter" class="screen-reader-text">Filtrer les modules optionnels</label>
                        <input type="search" id="bjlg-module-filter" class="regular-text" placeholder="Rechercher un module ou un fichier" aria-describedby="bjlg-module-filter-help" />
                        <p id="bjlg-module-filter-help" class="description">Tapez pour filtrer instantanément les <?php echo esc_html($total_modules); ?> modules détectés.</p>
                    </div>
                    <div id="bjlg-module-summary" class="bjlg-module-summary" role="status" aria-live="polite" aria-atomic="true"></div>
                    <table class="widefat striped bjlg-modules-table" aria-describedby="bjlg-module-summary">
                        <caption class="screen-reader-text">Liste des modules optionnels Backup JLG</caption>
                        <thead>
                            <tr>
                                <th scope="col" style="width:80px">Activer</th>
                                <th scope="col">Fichier</th>
                                <th scope="col">Classe attendue</th>
                                <th scope="col">Test</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($files as $file):
                            $slug  = preg_replace('/^class-bjlg-|-?\.php$/', '', $file);
                            $core  = preg_replace('/^class-bjlg-|\.php$/', '', $file);
                            $class = 'BJLG_' . implode('_', array_map('ucfirst', preg_split('/-+/', $core)));
                            $row_id = 'bjlg-module-' . sanitize_key($slug ?: $file);
                            $filter_text = strtolower($slug . ' ' . $file . ' ' . $class);
                        ?>
                            <tr data-filter-text="<?php echo esc_attr($filter_text); ?>">
                                <td>
                                    <input type="checkbox" id="<?php echo esc_attr($row_id); ?>" name="modules[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $enabled, true)); ?> aria-label="Activer le module <?php echo esc_attr($class); ?>" />
                                </td>
                                <td><label for="<?php echo esc_attr($row_id); ?>"><code><?php echo esc_html($file); ?></code></label></td>
                                <td><code><?php echo esc_html($class); ?></code></td>
                                <td>
                                    <button type="button" class="button bjlg-check" data-file="<?php echo esc_attr($file); ?>" aria-describedby="<?php echo esc_attr($row_id); ?>-result">Tester</button>
                                    <span id="<?php echo esc_attr($row_id); ?>-result" class="bjlg-check-result" role="status" aria-live="polite" aria-atomic="true"></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <p class="submit"><button type="submit" class="button button-primary">Enregistrer</button></p>
            </form>

            <style>
                .bjlg-module-toolbar {
                    margin: 1em 0 0.5em;
                    display: flex;
                    flex-wrap: wrap;
                    align-items: center;
                    gap: 0.5em;
                }
                .bjlg-module-summary {
                    margin: 0 0 1em;
                    font-weight: 600;
                }
                .bjlg-check[disabled] {
                    opacity: 0.6;
                    cursor: wait;
                }
                .bjlg-check-result[data-status="success"] {
                    color: #007017;
                }
                .bjlg-check-result[data-status="error"] {
                    color: #b32d2e;
                }
                .bjlg-check-result[data-status="loading"] {
                    color: #4b5563;
                }
            </style>

            <script>
            (function(){
                var ajaxURL = typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                var nonce = '<?php echo esc_js(wp_create_nonce(self::NONCE_ACTION)); ?>';
                var buttons = Array.prototype.slice.call(document.querySelectorAll('.bjlg-check'));
                var filterInput = document.getElementById('bjlg-module-filter');
                var summary = document.getElementById('bjlg-module-summary');
                var rows = Array.prototype.slice.call(document.querySelectorAll('.bjlg-modules-table tbody tr'));
                var total = rows.length;

                function updateSummary(visible) {
                    if (!summary) {
                        return;
                    }

                    if (!total) {
                        summary.textContent = 'Aucun module optionnel détecté.';
                        return;
                    }

                    if (visible === total) {
                        summary.textContent = visible + ' module' + (visible > 1 ? 's' : '') + ' affiché' + (visible > 1 ? 's' : '') + ' sur ' + total + '.';
                    } else {
                        summary.textContent = visible + ' module' + (visible > 1 ? 's' : '') + ' filtré' + (visible > 1 ? 's' : '') + ' sur ' + total + '.';
                    }
                }

                function applyFilter() {
                    if (!filterInput) {
                        updateSummary(total);
                        return;
                    }

                    var term = filterInput.value ? filterInput.value.toLowerCase().trim() : '';
                    var visible = 0;

                    rows.forEach(function(row){
                        var haystack = row.getAttribute('data-filter-text') || '';
                        var match = term === '' || haystack.indexOf(term) !== -1;
                        row.style.display = match ? '' : 'none';
                        if (match) {
                            visible++;
                        }
                    });

                    updateSummary(visible);
                }

                if (filterInput) {
                    filterInput.addEventListener('input', applyFilter);
                }

                applyFilter();

                function reportNetworkFailure(moduleFile, error) {
                    var reason = '';
                    if (error && error.message) {
                        reason = String(error.message);
                    }

                    if (window.URLSearchParams && navigator.sendBeacon) {
                        var params = new URLSearchParams();
                        params.append('action', 'bjlg_log_module_selfcheck_error');
                        params.append('nonce', nonce);
                        params.append('file', moduleFile || '');
                        params.append('message', reason);
                        params.append('status', 'network-error');
                        var blob = new Blob([params.toString()], { type: 'application/x-www-form-urlencoded; charset=UTF-8' });
                        navigator.sendBeacon(ajaxURL, blob);
                        return;
                    }

                    var payload = new FormData();
                    payload.append('action', 'bjlg_log_module_selfcheck_error');
                    payload.append('nonce', nonce);
                    payload.append('file', moduleFile || '');
                    payload.append('message', reason);
                    payload.append('status', 'network-error');

                    fetch(ajaxURL, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: payload,
                        keepalive: true
                    }).catch(function() {
                        // Ignorer silencieusement si le reporting échoue également.
                    });
                }

                buttons.forEach(function(btn){
                    btn.addEventListener('click', function(){
                        if (btn.disabled) {
                            return;
                        }

                        var row = btn.parentNode;
                        while (row && row.tagName && row.tagName.toLowerCase() !== 'tr') {
                            row = row.parentNode;
                        }
                        var result = row ? row.querySelector('.bjlg-check-result') : null;

                        if (result) {
                            result.textContent = 'Analyse...';
                            result.setAttribute('data-status', 'loading');
                        }

                        btn.disabled = true;
                        btn.setAttribute('aria-busy', 'true');

                        var data = new FormData();
                        data.append('action', 'bjlg_module_selfcheck');
                        data.append('nonce', nonce);
                        data.append('file', btn.getAttribute('data-file'));

                        fetch(ajaxURL, { method: 'POST', body: data })
                            .then(function(response){
                                return response.json();
                            })
                            .then(function(json){
                                if (!result) {
                                    return;
                                }

                                if (json && json.success) {
                                    var message = json.data && json.data.message ? json.data.message : 'Analyse terminée';
                                    result.textContent = 'OK : ' + message;
                                    result.setAttribute('data-status', 'success');
                                } else {
                                    var errorMessage = json && json.data && json.data.message ? json.data.message : 'inconnu';
                                    result.textContent = 'Problème : ' + errorMessage;
                                    result.setAttribute('data-status', 'error');
                                }
                            })
                            .catch(function(error){
                                if (!result) {
                                    return;
                                }
                                result.textContent = 'Erreur réseau';
                                result.setAttribute('data-status', 'error');
                                reportNetworkFailure(btn.getAttribute('data-file'), error);
                            })
                            .then(function(){
                                btn.disabled = false;
                                btn.removeAttribute('aria-busy');
                            });
                    });
                });
            })();
            </script>
        </div>
        <?php
    }

    public function save_modules() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('bjlg_save_modules');

        $safe_mode = !empty($_POST['safe_mode']) ? 1 : 0;
        \bjlg_update_option('bjlg_safe_mode', $safe_mode);

        $modules = isset($_POST['modules']) && is_array($_POST['modules']) ? array_values(array_unique(array_map('sanitize_text_field', $_POST['modules']))) : [];
        \bjlg_update_option('bjlg_enabled_modules', $modules);

        wp_safe_redirect(admin_url('admin.php?page=bjlg-debug-modules&saved=1'));
        exit;
    }

    public function ajax_selfcheck() {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'Nonce invalide']);
        }
        if (empty($_POST['file'])) wp_send_json_error(['message'=>'Fichier manquant']);

        $file = basename(sanitize_file_name(wp_unslash($_POST['file'])));
        $path = plugin_dir_path(BJLG_PLUGIN_FILE) . $file;
        if (!is_readable($path)) wp_send_json_error(['message'=>'Fichier illisible']);

        $src = @file_get_contents($path);
        if ($src === false) wp_send_json_error(['message'=>'Lecture impossible']);

        if (!preg_match('/^\s*<\?php/m', $src)) {
            wp_send_json_error(['message'=>'Tag <?php manquant']);
        }
        if (preg_match('/^\s*\.\.\.\s*$/m', $src)) {
            wp_send_json_error(['message'=>'Ligne "..." détectée']);
        }
        $open = substr_count($src,'{'); $close = substr_count($src,'}');
        if ($open !== $close) {
            wp_send_json_error(['message'=>"Accolades non équilibrées ($open/$close)"]);
        }
        wp_send_json_success(['message'=>'Lint léger OK']);
    }

    public function ajax_log_module_failure() {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'Nonce invalide'], 403);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Capacité insuffisante'], 403);
        }

        $file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'network-error';
        $message = isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : '';

        $label = $file !== '' ? $file : 'fichier non communiqué';
        $reason = $message !== '' ? $message : 'Erreur réseau inconnue';

        if (class_exists('\\BJLG\\BJLG_Debug')) {
            \BJLG\BJLG_Debug::warning(sprintf(
                'Selfcheck AJAX %1$s sur %2$s : %3$s (utilisateur #%4$d)',
                $status,
                $label,
                $reason,
                get_current_user_id()
            ));
        }

        wp_send_json_success(['logged' => true]);
    }
}
new BJLG_Debug_UI();
