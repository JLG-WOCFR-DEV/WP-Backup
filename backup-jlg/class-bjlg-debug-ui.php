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
        $safe_mode = (int) get_option('bjlg_safe_mode', 1);
        $enabled   = function_exists('BJLG_Module_Manager::enabled_slugs') ? BJLG_Module_Manager::enabled_slugs() : get_option('bjlg_enabled_modules', []);
        $enabled   = is_array($enabled) ? $enabled : [];
        $files     = function_exists('BJLG_Module_Manager::get_all_files') ? BJLG_Module_Manager::get_all_files() : [];

        // Si le manager n’est pas là (cas extrême), liste “class-bjlg-*.php”
        if (!$files) {
            $scan = glob(plugin_dir_path(BJLG_PLUGIN_FILE) . 'class-bjlg-*.php') ?: [];
            foreach ($scan as $p) { $files[] = basename($p); }
        }

        ?>
        <div class="wrap">
            <h1>Backup JLG — Debug & Modules</h1>
            <p>Cette page est toujours présente (fallback). Si tu la vois, c’est que le noyau s’est chargé correctement.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bjlg_save_modules'); ?>
                <input type="hidden" name="action" value="bjlg_save_modules" />

                <h2>Safe Mode</h2>
                <label>
                    <input type="checkbox" name="safe_mode" value="1" <?php checked($safe_mode, 1); ?>>
                    Activer le Safe Mode (ne charge que le noyau)
                </label>

                <hr/>

                <h2>Modules optionnels</h2>
                <?php if (empty($files)): ?>
                    <p><em>Aucun fichier class-bjlg-*.php détecté.</em></p>
                <?php else: ?>
                    <table class="widefat striped">
                        <thead><tr><th style="width:60px">Activer</th><th>Fichier</th><th>Classe attendue</th><th>Test</th></tr></thead>
                        <tbody>
                        <?php foreach ($files as $file):
                            $slug  = preg_replace('/^class-bjlg-|-?\.php$/', '', $file);
                            $core  = preg_replace('/^class-bjlg-|\.php$/', '', $file);
                            $class = 'BJLG_' . implode('_', array_map('ucfirst', preg_split('/-+/', $core)));
                        ?>
                            <tr>
                                <td><input type="checkbox" name="modules[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $enabled, true)); ?>></td>
                                <td><code><?php echo esc_html($file); ?></code></td>
                                <td><code><?php echo esc_html($class); ?></code></td>
                                <td><button type="button" class="button bjlg-check" data-file="<?php echo esc_attr($file); ?>">Tester</button> <span class="bjlg-check-result"></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <p class="submit"><button type="submit" class="button button-primary">Enregistrer</button></p>
            </form>

            <script>
            (function(){
                function md5(s){return btoa(unescape(encodeURIComponent(s))).replace(/=+$/,'');}
                document.querySelectorAll('.bjlg-check').forEach(btn=>{
                    btn.addEventListener('click', ()=>{
                        const cell = btn.parentElement.querySelector('.bjlg-check-result');
                        cell.textContent = 'Analyse...';
                        const d = new FormData();
                        d.append('action','bjlg_module_selfcheck');
                        d.append('nonce','<?php echo esc_js(wp_create_nonce(self::NONCE_ACTION)); ?>');
                        d.append('file', btn.getAttribute('data-file'));
                        fetch(ajaxurl, { method:'POST', body:d })
                           .then(r=>r.json())
                           .then(j=>{ cell.textContent = j.success ? ('OK : ' + j.data.message) : ('Problème : ' + (j.data && j.data.message ? j.data.message : 'inconnu')); })
                           .catch(()=>{ cell.textContent = 'Erreur réseau'; });
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
        update_option('bjlg_safe_mode', $safe_mode);

        $modules = isset($_POST['modules']) && is_array($_POST['modules']) ? array_values(array_unique(array_map('sanitize_text_field', $_POST['modules']))) : [];
        update_option('bjlg_enabled_modules', $modules);

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
}
new BJLG_Debug_UI();
