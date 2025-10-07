# Comparaison avec les solutions de sauvegarde professionnelles

Ce document positionne Backup JLG face aux offres haut de gamme (UpdraftPlus Premium, BlogVault, Jetpack Backup, ManageWP…) en s’appuyant exclusivement sur les capacités observables dans le code du plugin. L’objectif est double : valoriser les forces existantes et identifier les écarts fonctionnels qui freinent encore l’adoption par des équipes habituées aux solutions managées.

## Points forts actuels

- **Parcours de sauvegarde assisté et personnalisable** : formulaires avec modèles, filtres d’inclusion/exclusion, vérification post-sauvegarde et routage multi-destination reproduisent des workflows avancés que l’on retrouve chez UpdraftPlus ou BlogVault.【F:backup-jlg/includes/class-bjlg-admin.php†L332-L507】【F:backup-jlg/includes/class-bjlg-backup.php†L944-L1006】【F:backup-jlg/includes/class-bjlg-backup.php†L2119-L2166】
- **Protection des archives** : chiffrement AES-256, gestion de clé sécurisée et HMAC d’intégrité alignent le plugin sur les exigences de Jetpack Backup pour le stockage chiffré.【F:backup-jlg/includes/class-bjlg-encryption.php†L17-L155】
- **Automatisation robuste** : planificateur multi-scénarios, rotation automatique des archives, bilan de santé et historique exploitable via l’API REST offrent une observabilité équivalente aux consoles premium.【F:backup-jlg/includes/class-bjlg-scheduler.php†L35-L207】【F:backup-jlg/includes/class-bjlg-cleanup.php†L41-L142】【F:backup-jlg/includes/class-bjlg-health-check.php†L17-L152】【F:backup-jlg/includes/class-bjlg-history.php†L8-L158】【F:backup-jlg/includes/class-bjlg-rest-api.php†L54-L319】
- **Sécurité d’accès** : rate limiting, gestion de clés API et webhooks signés se rapprochent des contrôles d’accès « agency-grade » de ManageWP ou BlogVault.【F:backup-jlg/includes/class-bjlg-rate-limiter.php†L18-L62】【F:backup-jlg/includes/class-bjlg-api-keys.php†L13-L172】【F:backup-jlg/includes/class-bjlg-webhooks.php†L18-L328】
- **Restauration production/sandbox orchestrée** : la possibilité de préparer, nettoyer et promouvoir des sandboxes permet de tester les archives avant promotion, une approche voisine des workflows de restauration sécurisés proposés par BlogVault Staging.【F:backup-jlg/includes/class-bjlg-restore.php†L44-L207】【F:backup-jlg/includes/class-bjlg-restore.php†L503-L616】
- **Distribution sécurisée des exports** : tokens de téléchargement temporaires, contrôles d’accès et journalisation détaillée renforcent la conformité et la traçabilité attendues sur les offres managées.【F:backup-jlg/includes/class-bjlg-actions.php†L16-L200】【F:backup-jlg/includes/class-bjlg-history.php†L8-L158】

## Écarts observés face aux offres pro

- **Notifications multi-canales incomplètes** : l’interface propose email/Slack/Discord mais seul le webhook interne est implémenté, là où les solutions commerciales poussent des alertes temps réel sur plusieurs canaux.【F:backup-jlg/includes/class-bjlg-settings.php†L41-L54】【F:backup-jlg/includes/class-bjlg-webhooks.php†L24-L29】
- **Catalogue d’object storage** : Azure Blob et Backblaze B2 sont configurables côté réglages mais non exposés dans l’UI ni instanciés par défaut, quand UpdraftPlus Premium ou BlogVault les intègrent nativement.【F:backup-jlg/includes/class-bjlg-settings.php†L107-L124】【F:backup-jlg/includes/class-bjlg-backup.php†L2119-L2166】
- **Nettoyage distant** : la rotation incrémentale enregistre les suppressions à propager mais aucun worker natif ne consomme la file d’attente, à la différence des solutions SaaS qui purgent automatiquement les copies distantes.【F:backup-jlg/includes/class-bjlg-incremental.php†L279-L347】
- **Pilotage avant mise à jour** : il n’existe pas encore de déclencheur automatique avant une mise à jour de plugin/thème, une fonctionnalité courante des offres professionnelles pour éviter les régressions.
- **Couverture multisite et gestion centralisée** : le plugin reste officiellement mono-site tandis que les suites pro gèrent la supervision multi-projets depuis une même console (ManageWP, BlogVault Agency).【F:README.md†L111-L116】
- **Stockage isolé managé** : Backup JLG délègue la résilience au stockage choisi par l’utilisateur ; les solutions SaaS haut de gamme répliquent automatiquement les archives sur une infrastructure managée avec redondance géographique, fonctionnalité encore absente nativement.

## Synthèse comparative

| Domaine | Backup JLG | Pratiques des solutions pro |
| --- | --- | --- |
| **Sauvegardes** | Full + incrémental pilotés par manifeste, compression ajustable et optimisation parallèle configurable.【F:backup-jlg/includes/class-bjlg-backup.php†L944-L1006】【F:backup-jlg/includes/class-bjlg-incremental.php†L16-L156】【F:backup-jlg/includes/class-bjlg-performance.php†L18-L140】 | Déclenchement continu (quasi temps réel) et stockage immédiatement externalisé pour réduire le RPO.|
| **Restauration** | Mode production et sandbox avec promotion contrôlée et bilan de santé préalable.【F:backup-jlg/includes/class-bjlg-restore.php†L44-L207】【F:backup-jlg/includes/class-bjlg-health-check.php†L17-L152】 | Restauration instantanée vers cloud privé ou staging isolé fourni par l’éditeur.|
| **Sécurité** | Chiffrement AES-256, tokens éphémères et rate limiting API.【F:backup-jlg/includes/class-bjlg-encryption.php†L17-L155】【F:backup-jlg/includes/class-bjlg-actions.php†L16-L200】【F:backup-jlg/includes/class-bjlg-rate-limiter.php†L18-L62】 | Chiffrement + stockage hors site chiffré à gestion automatique de la clé et contrôles RBAC avancés.|
| **Observabilité** | Tableau de bord, historique SQL dédié et API REST complète.【F:backup-jlg/includes/class-bjlg-admin.php†L146-L309】【F:backup-jlg/includes/class-bjlg-history.php†L8-L158】【F:backup-jlg/includes/class-bjlg-rest-api.php†L54-L319】 | Supervision centralisée multi-sites, SLA et alertes proactives multi-canales.|
| **Notifications** | Préférences pour email/Slack/Discord et webhooks internes déjà câblés, mais sans livraison effective vers les canaux externes listés.【F:backup-jlg/includes/class-bjlg-settings.php†L41-L55】【F:backup-jlg/includes/class-bjlg-webhooks.php†L24-L29】 | Alerte en temps réel multi-canale (email, SMS, chatops) avec escalade et fenêtres de maintenance.|
| **Pilotage stockage** | Indicateurs locaux (taille du répertoire, dernières archives) agrégés pour le tableau de bord.【F:backup-jlg/includes/class-bjlg-admin-advanced.php†L30-L186】 | Quotas et consommation rapprochés du stockage distant, alertes de dépassement et remédiation automatique.|
| **Intégrations** | Connecteurs prêts pour Drive, S3, Wasabi, Dropbox, OneDrive, pCloud et SFTP ; Azure/B2 préparés côté paramètres mais non exposés par défaut dans l’interface.【F:backup-jlg/includes/class-bjlg-admin.php†L373-L540】【F:backup-jlg/includes/class-bjlg-settings.php†L96-L125】【F:backup-jlg/includes/class-bjlg-admin.php†L1862-L1905】 | Catalogue complet (Azure, B2, Glacier, etc.) avec gestion automatique des quotas et monitoring d’API.|
| **Expérience admin** | Administration mono-page par onglets avec composants CSS maison et onboarding statique.【F:backup-jlg/includes/class-bjlg-admin.php†L121-L170】【F:backup-jlg/assets/css/admin.css†L741-L980】【F:backup-jlg/assets/js/admin.js†L80-L199】 | Consoles modulaires multi-pages, composants `@wordpress/components`, checklists interactives et vues adaptées mobile.|

## Recommandations prioritaires

1. **Implémenter l’envoi réel des notifications** (emails, Slack/Discord) en s’appuyant sur les réglages existants et sur les hooks `bjlg_backup_complete`/`bjlg_backup_failed`, afin d’égaler les alertes omnicanales des solutions gérées.【F:backup-jlg/includes/class-bjlg-settings.php†L41-L54】【F:backup-jlg/includes/class-bjlg-webhooks.php†L24-L29】
2. **Exposer Azure Blob et Backblaze B2** dans l’interface et dans l’usine à destinations (chargement automatique + tests de connexion) pour couvrir les clouds supportés par les offres pro.【F:backup-jlg/includes/class-bjlg-settings.php†L107-L124】【F:backup-jlg/includes/class-bjlg-backup.php†L2119-L2166】
3. **Créer un orchestrateur de purge distante** qui consomme l’action `bjlg_incremental_remote_purge` et déclenche les API des stockages concernés, assurant une rotation complète sans intervention manuelle.【F:backup-jlg/includes/class-bjlg-incremental.php†L279-L347】
4. **Ajouter un déclencheur de sauvegarde pré-mise à jour** (hook `upgrader_pre_install` ou équivalent) pour automatiser les snapshots avant chaque update, comme le proposent UpdraftPlus Premium ou Jetpack Backup.
5. **Étendre la prise en charge multisite** : support natif de WordPress multisite et mutualisation des historiques/API pour piloter plusieurs environnements depuis une seule instance, indispensable pour rivaliser avec les consoles agence.【F:README.md†L111-L116】【F:backup-jlg/includes/class-bjlg-rest-api.php†L54-L319】
6. **Renforcer la supervision proactive** : compléter le bilan de santé et l’audit SQL existants par des métriques d’usage (quota distant, temps moyen de restauration) exposées via API/webhooks afin de fournir les garanties de service attendues sur les offres premium.【F:backup-jlg/includes/class-bjlg-health-check.php†L17-L152】【F:backup-jlg/includes/class-bjlg-history.php†L8-L158】

## Axes d'amélioration alignés sur les offres pro

### Quick wins (0–3 mois)

- **Finaliser les canaux de notification** : implémenter l’envoi email et chatops en s’appuyant sur les préférences existantes et sur les hooks déjà en place (`bjlg_backup_complete`, `bjlg_backup_failed`).【F:backup-jlg/includes/class-bjlg-settings.php†L41-L55】【F:backup-jlg/includes/class-bjlg-webhooks.php†L24-L29】 Cela rapprocherait Backup JLG des alertes temps réel proposées par les acteurs pro.
- **Automatiser la purge distante** : créer un worker (cron ou queue) qui consomme l’action `bjlg_incremental_remote_purge` afin de synchroniser la rotation locale et le nettoyage côté cloud sans intervention humaine.【F:backup-jlg/includes/class-bjlg-incremental.php†L279-L324】
- **Exposer Azure Blob et Backblaze B2 dans l’UI** : ajouter ces destinations aux listes proposées par défaut et fournir un test de connexion guidé pour sécuriser l’onboarding.【F:backup-jlg/includes/class-bjlg-settings.php†L107-L125】【F:backup-jlg/includes/class-bjlg-admin.php†L1862-L1905】
- **Granularité de planification renforcée** : les fréquences 5 et 15 minutes complètent désormais les intervalles horaires à mensuels, rapprochant les déclencheurs des suites pro.【F:backup-jlg/includes/class-bjlg-settings.php†L18-L72】【F:backup-jlg/includes/class-bjlg-scheduler.php†L47-L119】【F:backup-jlg/assets/js/admin.js†L470-L526】 Un champ Cron personnalisé pourrait encore étendre la couverture pour les cas ultra-spécifiques.
- **Visibilité sur le stockage distant** : enrichir les métriques du tableau de bord (actuellement centrées sur le répertoire local) avec des appels API vers les destinations configurées pour suivre l’usage réel et alerter avant saturation.【F:backup-jlg/includes/class-bjlg-admin-advanced.php†L60-L181】

### Initiatives moyen terme (3–6 mois)

- **Parcours admin modulaires** : éclater la mono-page actuelle en sous-pages dédiées (monitoring, restauration, automatisation) et migrer progressivement vers `@wordpress/components` pour gagner en accessibilité native et en cohérence mobile.【F:backup-jlg/includes/class-bjlg-admin.php†L121-L170】【F:backup-jlg/assets/css/admin.css†L741-L980】
- **Onboarding piloté** : transformer la liste statique actuelle en checklist interactive avec suivi d’étapes, rappelant les assistants guidés des suites pro.【F:backup-jlg/assets/js/admin.js†L90-L199】
- **Pré-sauvegarde avant mises à jour** : brancher un déclencheur automatique sur `upgrader_pre_install`/`automatic_updates_complete` pour créer un snapshot avant toute mise à jour de plugin ou de thème, fonctionnalité différenciante dans les offres managées.【F:backup-jlg/includes/class-bjlg-admin.php†L121-L170】

### Paris long terme (>6 mois)

- **Support WordPress multisite & gestion centralisée** : adapter les contrôles de capacité et l’API pour piloter plusieurs sites depuis une seule console, en réponse aux besoins des agences.【F:README.md†L111-L116】【F:backup-jlg/includes/class-bjlg-rest-api.php†L54-L319】
- **Observabilité orientée SLA** : compléter le module d’agrégation par des métriques de performance (durée moyenne, RTO/RPO mesurés, disponibilité des destinations) et exposer ces données via API/webhooks pour faciliter la contractualisation SLA.【F:backup-jlg/includes/class-bjlg-admin-advanced.php†L30-L186】【F:backup-jlg/includes/class-bjlg-rest-api.php†L178-L319】
- **Résilience managée** : proposer un service de stockage managé multi-région (ou un partenariat cloud) qui réplique automatiquement les archives pour offrir la même garantie que les solutions SaaS premium.【F:backup-jlg/includes/class-bjlg-backup.php†L2186-L2233】

## Focus UX/UI, navigation mobile, accessibilité et apparence WordPress

### Options produit

- **Cadence de sauvegarde étendue mais perfectible** : le planificateur propose désormais des fréquences de 5 à 15 minutes en plus des intervalles horaires à mensuels, mais un champ Cron personnalisé permettrait d’adresser les scénarios d’orchestration avancés.【F:backup-jlg/includes/class-bjlg-settings.php†L18-L72】【F:backup-jlg/includes/class-bjlg-scheduler.php†L47-L119】
- **Canaux d’alerte à finaliser** : seuls les formulaires d’email, Slack et Discord existent côté réglages, sans SMS, Teams ou webhook sortant réellement câblés. Étendre les destinations et brancher l’envoi réel rapprocherait l’expérience des offres omnicanales.【F:backup-jlg/includes/class-bjlg-settings.php†L41-L55】【F:backup-jlg/includes/class-bjlg-webhooks.php†L24-L29】
- **Connecteurs cloud incohérents** : Azure Blob et Backblaze B2 disposent des champs de configuration mais ne sont ni listés dans les onglets ni instanciés automatiquement. Offrir un onboarding guidé avec tests de connexion imiterait les connecteurs « plug & play » des solutions premium.【F:backup-jlg/includes/class-bjlg-settings.php†L96-L125】【F:backup-jlg/includes/class-bjlg-admin.php†L480-L507】
- **Absence d’automatisation pré-update** : la page d’administration ne déclare aucun hook dédié aux mises à jour de plugins/thèmes, ce qui empêche de proposer une option « sauvegarde avant update » pourtant standard en pro.【F:backup-jlg/includes/class-bjlg-admin.php†L146-L170】

### Expérience UX/UI dans l’admin

- **Monopage dense** : tout l’écosystème (création, historique, restauration, réglages) vit sur une unique page avec onglets. Fragmenter en sous-pages ou wizards allègerait la charge cognitive et faciliterait la délégation par rôle.【F:backup-jlg/includes/class-bjlg-admin.php†L103-L170】
- **Composants 100 % maison** : les cartes, timelines et formulaires reposent sur des styles personnalisés (grid, cards, boutons) qui ne s’appuient pas sur les composants WP modernes (`<wp-components>`). Migrer vers `@wordpress/components` apporterait cohérence visuelle et accessibilité native.【F:backup-jlg/assets/css/admin.css†L1-L118】【F:backup-jlg/assets/js/admin.js†L4-L205】
- **Parcours onboarding statique** : l’onboarding injecte du texte mais aucun call-to-action contextuel ou checklist interactive, ce qui limite l’accompagnement. Ajouter une checklist avec suivi d’étapes (comme Jetpack) aiderait l’adoption.【F:backup-jlg/assets/js/admin.js†L90-L157】

### Navigation mobile

- **Onglets horizontaux non repliés** : la navigation reste une simple liste d’onglets WordPress sans adaptation mobile dédiée (sélecteur, hamburger, overflow auto). Sur smartphone, basculer vers un menu déroulant ou un `wp.components.TabPanel` améliorerait l’ergonomie.【F:backup-jlg/includes/class-bjlg-admin.php†L136-L143】【F:backup-jlg/assets/css/admin.css†L760-L766】
- **Listes plus accessibles mais perfectibles** : les tables deviennent des cartes empilées sous 782 px, mais le tri/filtre reste dispersé en toolbar horizontale. Proposer un panneau latéral « Filtres » ou un mode plein écran simplifierait la navigation tactile.【F:backup-jlg/assets/css/admin.css†L900-L1139】

### Accessibilité

- **Feedback silencieux** : plusieurs mises à jour AJAX s’affichent dans des `<div>` sans rôle annoncé (ex. actions dans le bloc éditorial), ce qui rend les changements invisibles pour les lecteurs d’écran. Ajouter `role="status"` ou gérer le focus sur les confirmations rapprocherait les standards pro.【F:backup-jlg/assets/js/block-status.js†L63-L114】
- **Couleurs non testées** : les alertes et cartes reposent sur des contrastes pastel personnalisés sans référence aux palettes WordPress, risquant un contraste insuffisant en mode sombre ou daltonien. Utiliser les variables CSS `--wp-admin-theme-color` et proposer un mode haute visibilité améliorerait l’AA.【F:backup-jlg/assets/css/admin.css†L35-L118】【F:backup-jlg/assets/css/block-status.css†L1-L78】
- **Focus management** : après une action (ex. création de preset), le script injecte du contenu mais ne renvoie pas le focus sur le message de succès, obligeant la navigation clavier à repartir du début. Implémenter `focus()` ciblé ou des annonces `wp.a11y.speak()` suivrait les pratiques pro.【F:backup-jlg/assets/js/admin.js†L205-L318】

### Apparence dans WordPress & éditeur visuel

- **Styles figés dans le bloc** : la feuille `block-status.css` impose couleurs, rayons et boutons custom qui ne respectent pas la typographie ni les variables du thème, d’où un rendu potentiellement dissonant côté front ou éditeur visuel. Supporter les presets `color`, `typography` et les variables globales assurerait une meilleure intégration.【F:backup-jlg/assets/css/block-status.css†L1-L86】【F:backup-jlg/block.json†L11-L27】
- **Éditeur sans prévisualisation dynamique** : le bloc charge un snapshot via `apiFetch` mais n’expose pas de skeleton ni de message clair lors du chargement/erreur, contrairement aux blocs pro qui affichent placeholders et liens de correction. Ajouter un état `Spinner` accessible et des actions de rechargement guiderait l’éditeur.【F:backup-jlg/assets/js/block-status.js†L8-L62】【F:backup-jlg/assets/js/block-status.js†L115-L184】
