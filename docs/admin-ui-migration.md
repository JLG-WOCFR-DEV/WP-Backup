# Console d’administration

Backup JLG expose **une seule** interface d’administration, alignée sur wp-admin : `div.wrap`, `h1.wp-heading-inline`, `nav-tab-wrapper` et notices WordPress.

Les écrans restent segmentés (Monitoring, Sauvegarde, Restauration, Réglages, Intégrations). La navigation se fait par onglets natifs, pas par un shell moderne/legacy.

## Compatibilité

Les anciens leviers (`bjlg_enable_modern_admin_shell`, `bjlg_enable_modern_admin`, `?bjlg_legacy=1`, `BJLG_ENABLE_LEGACY_ADMIN`) ne changent plus le rendu. `BJLG_Admin::render_legacy_admin_page()` appelle la page unique.

Les retours accessibles (`role="status"`, `wp.a11y.speak`) restent en place.

## Réglages

Les options métier sont déclarées via `register_setting()` (groupe `bjlg_plugin_settings`). La sauvegarde opérationnelle continue de passer par AJAX (`bjlg_save_settings`) pour ne pas casser le contexte multisite ni la planification.
