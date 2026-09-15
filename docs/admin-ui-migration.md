# Console d’administration

Backup JLG expose **une seule** interface d’administration, alignée sur wp-admin : `div.wrap`, `h1.wp-heading-inline`, `nav-tab-wrapper` et notices WordPress.

Les écrans restent segmentés (Monitoring, Sauvegarde, Restauration, Réglages, Intégrations). La navigation se fait par onglets natifs, pas par un shell moderne/legacy.

## Compatibilité

Les anciens leviers (`bjlg_enable_modern_admin_shell`, `bjlg_enable_modern_admin`, `?bjlg_legacy=1`, `BJLG_ENABLE_LEGACY_ADMIN`) ne changent plus le rendu. `BJLG_Admin::render_legacy_admin_page()` appelle la page unique.

Les retours accessibles (`role="status"`, `wp.a11y.speak`) restent en place.

## Réglages

Les options métier (rétention, notifications, chiffrement, performance, sandbox, etc.) sont déclarées via `register_setting()` (groupe `bjlg_plugin_settings`). Les identifiants cloud et la planification restent hors Settings API.

La sauvegarde opérationnelle continue de passer par AJAX (`bjlg_save_settings`) pour ne pas casser le contexte multisite ni les effets de bord (cron, chiffrement). Les formulaires de réglages n’envoient **pas** vers `options.php`. Un POST HTML sans JavaScript affiche une notice d’erreur au lieu d’échouer silencieusement.
