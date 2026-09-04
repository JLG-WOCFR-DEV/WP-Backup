<?php
/**
 * Restore admin section.
 *
 * @package BackupJLG
 *
 * @var string $restore_redirect
 * @var array  $self_test
 * @var array  $self_test_links
 * @var string $self_test_run_url
 */

if (!defined('ABSPATH')) {
    exit;
}

$self_test = is_array($self_test) ? $self_test : [];
$self_test_links = is_array($self_test_links) ? $self_test_links : [];
$self_test_status = isset($self_test['status']) ? (string) $self_test['status'] : '';
$self_test_message = isset($self_test['message']) ? (string) $self_test['message'] : '';
$self_test_last_run = !empty($self_test['last_run_at']) ? (int) $self_test['last_run_at'] : 0;
$self_test_next_run = !empty($self_test['next_run_at']) ? (int) $self_test['next_run_at'] : 0;

$notice_class = 'notice-info';
if ($self_test_status === 'success') {
    $notice_class = 'notice-success';
} elseif ($self_test_status === 'failure') {
    $notice_class = 'notice-error';
} elseif ($self_test_status === 'skipped') {
    $notice_class = 'notice-warning';
}

$format_time = static function ($timestamp) {
    if ($timestamp <= 0) {
        return '';
    }

    $format = get_option('date_format') . ' ' . get_option('time_format');

    return function_exists('wp_date') ? wp_date($format, $timestamp) : date_i18n($format, $timestamp);
};
?>
<div class="bjlg-section">
    <div class="notice <?php echo esc_attr($notice_class); ?> inline">
        <h2><?php esc_html_e('Test de restauration automatique', 'backup-jlg'); ?></h2>
        <p>
            <?php
            if ($self_test_message !== '') {
                echo esc_html($self_test_message);
            } else {
                esc_html_e('Un test sandbox hebdomadaire vérifie que la dernière archive peut être restaurée sans toucher la production.', 'backup-jlg');
            }
            ?>
        </p>
        <p class="description">
            <?php if ($self_test_last_run > 0) : ?>
                <?php echo esc_html(sprintf(__('Dernier run : %s', 'backup-jlg'), $format_time($self_test_last_run))); ?>
            <?php else : ?>
                <?php esc_html_e('Aucun test n’a encore été exécuté.', 'backup-jlg'); ?>
            <?php endif; ?>
            <?php if ($self_test_next_run > 0) : ?>
                — <?php echo esc_html(sprintf(__('Prochain run : %s', 'backup-jlg'), $format_time($self_test_next_run))); ?>
            <?php endif; ?>
        </p>
        <form method="post" action="<?php echo esc_url($self_test_run_url); ?>" style="margin: 12px 0;">
            <?php wp_nonce_field('bjlg_run_restore_self_test'); ?>
            <input type="hidden" name="action" value="bjlg_run_restore_self_test">
            <?php submit_button(__('Lancer un test maintenant', 'backup-jlg'), 'secondary', 'submit', false); ?>
        </form>
        <?php if (!empty($self_test_links)) : ?>
            <p>
                <?php foreach ($self_test_links as $link) :
                    if (!is_array($link) || empty($link['url']) || empty($link['label'])) {
                        continue;
                    }
                    ?>
                    <a class="button-link" href="<?php echo esc_url((string) $link['url']); ?>"><?php echo esc_html((string) $link['label']); ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </div>

    <h2><?php esc_html_e('Restaurer depuis un fichier', 'backup-jlg'); ?></h2>
    <p><?php esc_html_e('Si vous avez un fichier de sauvegarde sur votre ordinateur, vous pouvez le téléverser ici pour lancer une restauration.', 'backup-jlg'); ?></p>
    <form id="bjlg-restore-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('bjlg_restore_backup', 'bjlg_restore_backup_nonce'); ?>
        <input type="hidden" name="action" value="bjlg_restore_backup">
        <input type="hidden" name="redirect_to" value="<?php echo esc_url($restore_redirect); ?>">
        <input type="hidden" name="restore_environment" value="production" data-role="restore-environment-field">
        <div class="bjlg-restore-username-field bjlg-screen-reader-only">
            <label class="bjlg-screen-reader-only" for="bjlg-restore-username"><?php esc_html_e("Nom d'utilisateur", 'backup-jlg'); ?></label>
            <input type="text"
                   id="bjlg-restore-username"
                   name="username"
                   class="regular-text bjlg-screen-reader-only"
                   autocomplete="username"
                   aria-label="<?php echo esc_attr__("Nom d'utilisateur", 'backup-jlg'); ?>">
        </div>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><label for="bjlg-restore-file-input"><?php esc_html_e('Fichier de sauvegarde', 'backup-jlg'); ?></label></th>
                    <td>
                        <div class="bjlg-field-control">
                            <input type="file" id="bjlg-restore-file-input" name="restore_file" accept=".zip,.zip.enc" required>
                            <p class="description"><?php esc_html_e('Formats acceptés : .zip, .zip.enc (chiffré)', 'backup-jlg'); ?></p>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="bjlg-restore-password"><?php esc_html_e('Mot de passe', 'backup-jlg'); ?></label></th>
                    <td>
                        <div class="bjlg-field-control">
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
                                <?php esc_html_e('Requis pour restaurer les sauvegardes chiffrées (.zip.enc). Laissez vide pour les archives non chiffrées.', 'backup-jlg'); ?>
                            </p>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Options', 'backup-jlg'); ?></th>
                    <td>
                        <div class="bjlg-field-control">
                            <label><input type="checkbox" name="create_backup_before_restore" value="1" checked> <?php esc_html_e('Créer une sauvegarde de sécurité avant la restauration', 'backup-jlg'); ?></label>
                        </div>
                    </td>
                </tr>
                <?php if (class_exists(\BJLG\BJLG_Restore::class) && \BJLG\BJLG_Restore::user_can_use_sandbox()) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Environnement de test', 'backup-jlg'); ?></th>
                    <td>
                        <div class="bjlg-field-control">
                            <label>
                                <input type="checkbox" name="restore_to_sandbox" value="1">
                                <?php esc_html_e('Restaurer dans un environnement de test', 'backup-jlg'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Les fichiers seront restaurés dans un dossier isolé sans impacter la production.', 'backup-jlg'); ?></p>
                            <label for="bjlg-restore-sandbox-path" class="screen-reader-text"><?php esc_html_e('Chemin de la sandbox', 'backup-jlg'); ?></label>
                            <input type="text"
                                   id="bjlg-restore-sandbox-path"
                                   name="sandbox_path"
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr__('Laisser vide pour utiliser le dossier sandbox automatique', 'backup-jlg'); ?>"
                                   disabled>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div id="bjlg-restore-errors" class="notice notice-error" style="display: none;" role="alert"></div>
        <p class="submit">
            <button type="submit" class="button button-primary"><span class="dashicons dashicons-upload" aria-hidden="true"></span> <?php esc_html_e('Téléverser et Restaurer', 'backup-jlg'); ?></button>
        </p>
    </form>
    <div id="bjlg-restore-status" style="display: none;">
        <h3><?php esc_html_e('Statut de la restauration', 'backup-jlg'); ?></h3>
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
           aria-busy="false"><?php esc_html_e('Préparation...', 'backup-jlg'); ?></p>
    </div>
    <div id="bjlg-restore-debug-wrapper" style="display: none;">
        <h3><span class="dashicons dashicons-info" aria-hidden="true"></span> <?php esc_html_e('Détails techniques', 'backup-jlg'); ?></h3>
        <pre id="bjlg-restore-ajax-debug" class="bjlg-log-textarea"></pre>
    </div>
</div>
