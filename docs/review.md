# Audit UX / UI / Performance / Accessibilité / Fiabilité

Ce document résume les principaux constats relevés lors de l'analyse du plugin « Backup - JLG ».

## Points saillants

- L'assistant de création de sauvegarde propose de nombreux réglages techniques (modèles de motifs d'inclusion/exclusion, destinations secondaires, tests post-sauvegarde) directement dans l'écran principal, sans contextualisation progressive ni aide interactive, ce qui peut dérouter des profils non experts.
- L'interface admin s'appuie sur un unique bundle JavaScript de plus de 5 000 lignes chargé systématiquement avec Chart.js, même quand certains onglets n'en ont pas l'usage, ce qui impacte le temps d'interaction initial.
- Les contrastes colorimétriques et les états focus reposent sur des teintes pastels pouvant descendre sous les seuils WCAG dans certaines sections (badges, alertes), et plusieurs composants dynamiques n'offrent pas de solutions de repli sans JavaScript.

## Pistes d'amélioration

- Regrouper les réglages avancés (motifs personnalisés, destinations secondaires) dans des panneaux accordéon ou un second écran dédié, et proposer des aides contextuelles (exemples dynamiques, autocomplétion, validation en direct).
- Segmenter le JavaScript par onglet (code-splitting) et ne charger Chart.js qu'en présence d'un canvas de statistiques, afin de réduire le coût initial sur les écrans les plus consultés.
- Ajouter des contrôles de contraste (classes `is-dark`/`is-light` dynamiques) et prévoir des fallback server-side ou `admin-post.php` pour les actions critiques comme la création/restauration de sauvegardes afin d'assurer la continuité de service même si le bundle échoue.

## Note de version – 2025-03

### Chantiers clôturés
- **Escalade omnicanale traçable** : rappels automatiques avec backoff, notifications multi-canaux et suivi des accusés sont centralisés dans la file (`[backup-jlg/includes/class-bjlg-notification-queue.php](../backup-jlg/includes/class-bjlg-notification-queue.php)`, `[backup-jlg/includes/class-bjlg-notification-receipts.php](../backup-jlg/includes/class-bjlg-notification-receipts.php)`) et exposés dans l’interface (`[backup-jlg/includes/class-bjlg-admin.php](../backup-jlg/includes/class-bjlg-admin.php)`, `[backup-jlg/assets/js/admin-dashboard.js](../backup-jlg/assets/js/admin-dashboard.js)`).
- **Supervision quotas distants** : les métriques normalisées (used/quota/free) sont collectées pour chaque destination (`[backup-jlg/includes/class-bjlg-remote-storage-metrics.php](../backup-jlg/includes/class-bjlg-remote-storage-metrics.php)`), agrégées dans le module avancé et alimentent les alertes de capacité (`[backup-jlg/includes/class-bjlg-admin-advanced.php](../backup-jlg/includes/class-bjlg-admin-advanced.php)`).
- **Rappels pré-mise à jour** : le gardien de mises à jour orchestre désormais des rappels dédiés (notifications et emails) pour préparer les snapshots (`[backup-jlg/includes/class-bjlg-update-guard.php](../backup-jlg/includes/class-bjlg-update-guard.php)`, `[backup-jlg/includes/class-bjlg-settings.php](../backup-jlg/includes/class-bjlg-settings.php)`).

### Roadmap – Top 3 restants
1. **Support multisite & gestion centralisée** : adapter la structure des tables et l’API REST pour les réseaux WordPress (`[backup-jlg/includes/class-bjlg-rest-api.php](../backup-jlg/includes/class-bjlg-rest-api.php)`, `docs/roadmap-suivi.md`).
2. **Rapports post-sauvegarde & tests de reprise** : automatiser les restaurations sandbox et publier des rapports SLA détaillés (`[backup-jlg/includes/class-bjlg-backup.php](../backup-jlg/includes/class-bjlg-backup.php)`, `[backup-jlg/includes/class-bjlg-history.php](../backup-jlg/includes/class-bjlg-history.php)`).
3. **Snapshots pré-update configurables** : exposer des réglages fins (composants ciblés, rappels) dans l’UI (`[backup-jlg/includes/class-bjlg-update-guard.php](../backup-jlg/includes/class-bjlg-update-guard.php)`, `[backup-jlg/includes/class-bjlg-admin.php](../backup-jlg/includes/class-bjlg-admin.php)`).
