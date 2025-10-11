# Plan comparatif et améliorations — Backup JLG vs applications pro

Ce document synthétise les écarts observés entre l'expérience proposée par Backup JLG et les suites de sauvegarde professionnelles (BlogVault, Jetpack Backup, ManageWP, UpdraftPlus Premium). Il hiérarchise les améliorations prioritaires en matière d'UX/UI, d'ergonomie, de fiabilité opérationnelle et de design afin d'accélérer l'alignement sur les standards « agency-grade ».

## 1. Synthèse comparative

| Axe | État actuel Backup JLG | Constats sur les apps pro | Impact prioritaire |
| --- | --- | --- | --- |
| **UX / Navigation** | Interface monopage avec onglets WordPress statiques pour l'ensemble des fonctionnalités (sauvegarde, restauration, historique, réglages).【F:backup-jlg/includes/class-bjlg-admin.php†L123-L170】 | Consoles multi-sections avec navigation latérale persistante, wizards contextuels et suivis de progression. | Fragmenter les parcours pour réduire la charge cognitive et préparer un mode « rôle ».
| **Ergonomie & guidance** | Onboarding textuel sans checklist, feedback AJAX silencieux, modes « rapide / avancé » non persistants.【F:backup-jlg/assets/js/admin.js†L90-L199】【F:backup-jlg/includes/class-bjlg-admin.php†L1120-L1336】 | Applications pro proposent des checklists guidées, recommandations dynamiques et modes utilisateurs mémorisés. | Ajouter des guides interactifs, mémoriser les préférences et exposer des recommandations automatisées.
| **Fiabilité & observabilité** | Purge distante avec retries exponentiels, alertes de retard et panneau de suivi, notifications multi-canales opérationnelles ; tests de restauration automatiques et indicateurs SLA restent absents.【F:backup-jlg/includes/class-bjlg-remote-purge-worker.php†L11-L321】【F:backup-jlg/includes/class-bjlg-admin.php†L899-L1061】【F:backup-jlg/includes/class-bjlg-notifications.php†L21-L198】 | Suites gérées publient des rapports automatiques, gèrent les SLA (RTO/RPO) et offrent des alertes temps réel multi-canales avec escalade. | Documenter les SLA de purge, automatiser des tests de reprise et ajouter des politiques d'escalade.
| **Design & accessibilité** | Composants CSS maison (cartes, boutons, bloc Gutenberg) avec couleurs en dur, peu d'adaptation mobile et absence d'annonces ARIA.【F:backup-jlg/assets/css/admin.css†L720-L939】【F:backup-jlg/assets/js/block-status.js†L60-L122】 | Design systems cohérents (`@wordpress/components`), tokens de thème dynamiques, focus management systématique. | Migrer vers les composants WP, introduire des tokens et renforcer l'accessibilité (focus, contrastes, presets).

## 2. Améliorations UX / UI & ergonomie

1. **Segmenter l'administration en sous-pages ou TabPanel React** pour isoler Monitoring, Sauvegarde, Restauration, Réglages et Intégrations. Cette découpe facilitera l'application de permissions granulaires et ouvrira la voie à des parcours spécialisés.【F:backup-jlg/includes/class-bjlg-admin.php†L123-L170】
2. **Installer un menu latéral responsive** (Collapse sous 960 px) avec breadcrumbs et résumé d'état global afin de reproduire la navigation hiérarchique des consoles pro.【F:backup-jlg/assets/css/admin.css†L732-L774】
3. **Transformer l'onboarding statique en checklist interactive** (`@wordpress/components` Steps/Checklist) avec CTA directs (générer une clé API, activer le chiffrement) et progression enregistrée par utilisateur.【F:backup-jlg/assets/js/admin.js†L90-L157】
4. **Persister la préférence « mode rapide / avancé »** dans la meta utilisateur (`update_user_meta`) et proposer un switch global visible dès l'accueil pour réduire la complexité perçue lors des visites suivantes.【F:backup-jlg/includes/class-bjlg-admin.php†L1120-L1225】
5. **Ajouter des feedbacks accessibles** : `wp.a11y.speak()` pour les succès/erreurs AJAX, conteneurs `role="status"`, gestion du focus après action, afin d'aligner l'expérience clavier/lecteur d'écran sur les attentes professionnelles.【F:backup-jlg/assets/js/admin.js†L118-L199】
6. **Repenser les filtres et timelines en panneaux déroulants** avec sélection tactile optimisée (panneau latéral ou `DropdownMenu`) au lieu de toolbars horizontales peu adaptées au mobile.【F:backup-jlg/assets/css/admin.css†L900-L939】

## 3. Renforcement de la fiabilité et de l'observabilité

1. **Documenter le SLA de purge distante** : capitaliser sur les retries, alertes et monitoring existants pour afficher l’âge moyen, les délais estimés et les quotas distants, puis déclencher des actions correctives avant saturation.【F:backup-jlg/includes/class-bjlg-remote-purge-worker.php†L11-L321】【F:backup-jlg/includes/class-bjlg-admin.php†L899-L1061】
2. **Automatiser les tests de restauration** en planifiant des restaurations sandbox périodiques (cron dédié) avec rapport attaché à l'historique et aux notifications afin de prouver la récupérabilité des archives chiffrées.【F:backup-jlg/includes/class-bjlg-backup.php†L2087-L2129】【F:backup-jlg/includes/class-bjlg-restore.php†L44-L207】
3. **Ajouter des politiques d’escalade aux notifications multi-canales** : proposer plages de silence, hiérarchie des canaux (email → Slack → SMS) et templates par gravité afin de se rapprocher des consoles omnicanales.【F:backup-jlg/includes/class-bjlg-notifications.php†L21-L198】【F:backup-jlg/includes/class-bjlg-notification-queue.php†L360-L503】
4. **Publier des rapports de validation post-sauvegarde** (hash, destinations atteintes, anomalies) directement dans l'historique et par e-mail pour fournir un niveau de transparence comparable aux SLA pro.【F:backup-jlg/includes/class-bjlg-history.php†L16-L117】【F:backup-jlg/includes/class-bjlg-backup.php†L2168-L2233】
5. **Surveiller les quotas des destinations distantes** en collectant les métriques renvoyées par les connecteurs (S3, Azure, B2…) et en les affichant dans le tableau de bord afin d'anticiper les dépassements et de déclencher des actions correctives.【F:backup-jlg/includes/class-bjlg-admin-advanced.php†L30-L186】【F:backup-jlg/includes/class-bjlg-admin.php†L373-L540】

## 4. Modernisation du design et de l'identité visuelle

1. **Migrer les cartes et boutons vers `@wordpress/components`** (`Card`, `Button`, `Notice`, `TabPanel`) en réduisant les styles CSS spécifiques `.bjlg-*` pour hériter automatiquement du design system WordPress, du mode sombre et des règles d'accessibilité.【F:backup-jlg/assets/css/admin.css†L720-L828】
2. **Définir une palette de tokens CSS** (`--bjlg-surface`, `--bjlg-border`, `--bjlg-accent`) dérivée des variables `--wp-admin-theme-color` et `--wp-admin-theme-color-darker-20`, afin d'assurer une cohérence visuelle et un contraste AA sans multiplier les overrides.【F:backup-jlg/assets/css/admin.css†L35-L118】
3. **Refondre le bloc Gutenberg « Statut »** pour adopter les presets `color`/`typography`, ajouter un état `Spinner` et un message d'erreur actionnable, ce qui améliore l'intégration dans l'éditeur et sur le front office.【F:backup-jlg/assets/js/block-status.js†L60-L122】【F:backup-jlg/assets/css/block-status.css†L1-L64】
4. **Documenter un kit UI interne** (Figma ou Storybook React) listant composants, tokens et comportements responsives, facilitant la contribution et la cohérence lors des futures évolutions multi-équipes.

La mise en œuvre graduelle de ces chantiers alignera Backup JLG sur les meilleures pratiques des applications de sauvegarde professionnelles, tout en valorisant les capacités avancées déjà présentes dans le plugin.
