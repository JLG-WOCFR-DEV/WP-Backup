# Audit UI/UX et Design — Backup JLG vs solutions pro

Ce document synthétise les écarts d'expérience entre l'interface d'administration de Backup JLG et les consoles de sauvegarde professionnelles (BlogVault, ManageWP, UpdraftPlus Premium…). Il s'appuie sur l'état actuel du plugin et formule des axes d'amélioration pour rapprocher l'UX/UI des standards agency-grade.

## 1. Synthèse comparative

| Dimension | Backup JLG | Tendances des apps pro |
| --- | --- | --- |
| **Architecture d'interface** | Monopage avec onglets WordPress classiques, chargée dynamiquement dans `render_admin_page()`.【F:backup-jlg/includes/class-bjlg-admin.php†L123-L171】 | Consoles multi-sections dédiées (Monitoring, Sauvegardes, Restaurations…) avec navigation latérale persistante et breadcrumbs.
| **Système de design** | Styles CSS maison (cards, alertes, boutons) et JS jQuery custom pour les états, sans réutiliser `@wordpress/components`.【F:backup-jlg/assets/css/admin.css†L720-L828】【F:backup-jlg/assets/js/admin.js†L60-L118】 | Design systems cohérents : composants React accessibles, tokens de couleurs dynamiques, dark mode natif.
| **Onboarding & guidance** | Bloc statique « Vue d’ensemble » + texte onboarding injecté depuis le JS, sans checklist ni progression.【F:backup-jlg/includes/class-bjlg-admin.php†L173-L214】【F:backup-jlg/assets/js/admin.js†L90-L137】 | Assistants guidés (progress trackers, CTA contextuels) et recommandations automatisées.
| **Responsive & mobilité** | Adaptations CSS ponctuelles (cartes → liste sous 782 px) mais navigation tabulaire et filtres restent horizontaux, peu ergonomiques sur mobile.【F:backup-jlg/assets/css/admin.css†L732-L774】【F:backup-jlg/assets/css/admin.css†L900-L939】 | Layout responsive optimisé : tab bars repliées, panneaux latéraux convertis en tiroirs, gestes tactiles.
| **Accessibilité & feedback** | États AJAX insérés sans annonce ARIA, focus non géré après action, couleurs fixes dans le bloc Gutenberg sans respect des variables WP.【F:backup-jlg/assets/js/admin.js†L118-L137】【F:backup-jlg/assets/js/block-status.js†L60-L122】【F:backup-jlg/assets/css/block-status.css†L1-L64】 | Notifications toast vocalisées, focus management systématique, palettes adaptatives (mode sombre, daltonisme).

## 2. Constats détaillés

1. **Navigation monopage saturée** : l’onglet `backup_restore` charge sauvegardes, historique, restauration et paramètres secondaires dans une seule vue, ce qui complexifie la découverte des fonctionnalités avancées.【F:backup-jlg/includes/class-bjlg-admin.php†L136-L170】
2. **Composants non standardisés** : les cartes et boutons utilisent une grille CSS propriétaire avec des couleurs codées en dur (`#f6f7f7`, `#0073aa`), difficile à aligner sur le thème WP ou un mode sombre.【F:backup-jlg/assets/css/admin.css†L720-L828】
3. **Feedback AJAX silencieux** : les mises à jour orchestrées par `updateActions()` et `updateAlerts()` modifient le DOM sans annoncer l’état aux lecteurs d’écran, faute de `wp.a11y.speak()` ou de `role="status"` dédié.【F:backup-jlg/assets/js/admin.js†L90-L137】
4. **Bloc Gutenberg rigide** : `block-status.css` impose un fond blanc, un border radius fixe et des boutons custom, sans utiliser les presets `color`/`typography`, entraînant un contraste variable selon le thème.【F:backup-jlg/assets/css/block-status.css†L1-L44】
5. **Responsive partiel** : si la timeline adopte un mode liste sous 782 px, les onglets et barres d’action restent horizontaux et scrollables, rendant l’interface difficile à piloter au tactile.【F:backup-jlg/assets/css/admin.css†L732-L774】【F:backup-jlg/assets/css/admin.css†L900-L939】

## 3. Recommandations d'amélioration

### 3.1 Architecture & navigation
- **Segmenter l’interface en sous-pages** (`admin.php?page=backup-jlg-monitoring`, `...-automation`, etc.) ou en `TabPanel` React, pour isoler monitoring, restauration et réglages. Implémenter un menu latéral persistant inspiré des consoles SaaS pro.【F:backup-jlg/includes/class-bjlg-admin.php†L123-L171】
- **Introduire un tableau de bord dédié** avec widgets configurables (cartes, graphiques) et possibilité de réorganiser les sections via `wp.data` comme le proposent BlogVault/ManageWP.

### 3.2 Design system & composants
- **Migrer les cartes/boutons vers `@wordpress/components`** (`Card`, `Button`, `Notice`, `TabPanel`) pour bénéficier de l’accessibilité native et du support mode sombre, en remplaçant progressivement les sélecteurs `.bjlg-*`.【F:backup-jlg/assets/css/admin.css†L720-L828】
- **Définir des tokens CSS personnalisables** (`--bjlg-surface`, `--bjlg-border`, `--bjlg-accent`) dérivés des variables `--wp-admin-theme-color` afin d’assurer un contraste suffisant et un thème aligné sur WordPress.【F:backup-jlg/assets/css/block-status.css†L1-L44】

### 3.3 Onboarding & guidance
- **Transformer l’onboarding en checklist interactive** avec suivi d’étapes (`wp.components.Steps` ou `ProgressControl`) et actions directes (créer une clé API, configurer le chiffrement). Relier chaque étape aux hooks `bjlg_backup_complete`/`bjlg_backup_failed` pour actualiser automatiquement les statuts.【F:backup-jlg/includes/class-bjlg-admin.php†L173-L214】【F:backup-jlg/assets/js/admin.js†L90-L137】
- **Ajouter des alertes contextualisées** (ex. absence de clé API, chiffrement inactif) sous forme de `Notice` persistantes jusqu’à résolution, à l’image des rappels d’onboarding pro.

### 3.4 Accessibilité & feedback
- **Publier les mises à jour via `wp.a11y.speak()`** lors des succès/erreurs AJAX et définir des conteneurs `role="status"` pour les cartes actualisées, améliorant la conformité WCAG.【F:backup-jlg/assets/js/admin.js†L90-L137】
- **Gérer le focus après action** (ex. envoi de sauvegarde, ajout de destination) en ciblant les CTA ou messages de confirmation.
- **Normaliser les alertes du bloc Gutenberg** avec les classes `components-notice` et les variantes `is-success`/`is-warning`, pour hériter de la sémantique ARIA et des contrastes officiels.【F:backup-jlg/assets/js/block-status.js†L60-L122】【F:backup-jlg/assets/css/block-status.css†L1-L64】

### 3.5 Responsive & mobile-first
- **Basculer les onglets en menu déroulant** (`<SelectControl>` ou `DropdownMenu`) sous 960 px et transformer la toolbar de filtres en panneau latéral coulissant pour faciliter l’usage tactile.【F:backup-jlg/includes/class-bjlg-admin.php†L136-L170】【F:backup-jlg/assets/css/admin.css†L900-L939】
- **Ajouter des points de rupture intermédiaires** (1024 px, 600 px) pour ajuster les grilles, réduire les marges et réordonner les cartes d’action.

### 3.6 Cohérence front / éditeur
- **Autoriser la personnalisation du bloc** via les presets `supports.color` et `supports.typography` de `block.json`, puis remplacer les valeurs fixes par des variables CSS héritées du thème, afin de garantir un rendu aligné côté front et éditeur.【F:backup-jlg/assets/css/block-status.css†L1-L44】
- **Afficher des états de chargement accessibles** (skeleton `components.Skeleton`, `Spinner`) dans `block-status.js` pour informer l’éditeur lors des requêtes `apiFetch` et proposer une action de rechargement en cas d’erreur.【F:backup-jlg/assets/js/block-status.js†L60-L122】

### 3.7 Observabilité & reporting
- **Enrichir la timeline de planification** avec des vues cumulatives (heatmap hebdo, histogramme des succès/échecs) et des filtres par destination ou type de contenu ; aujourd’hui elle se limite à deux vues (semaine/mois) rendues côté jQuery, sans drill-down ni regroupement multi-sites.【F:backup-jlg/includes/class-bjlg-admin.php†L780-L808】【F:backup-jlg/assets/js/admin.js†L502-L516】
- **Transformer le « bilan de santé » texte en tableau de bord partageable** (widgets graphiques, export PDF/CSV, envoi programmé par e-mail) afin d’aligner la visibilité sur l’état des sauvegardes avec ce que proposent BlogVault ou ManageWP, qui combinent tendances et alerting automatique.【F:backup-jlg/includes/class-bjlg-health-check.php†L512-L532】
- **Structurer l’historique des actions en vues filtrables et exports prêts à l’audit** (recherche par utilisateur, statut, plage temporelle) plutôt qu’une simple liste plate ; tirer parti du catalogue d’actions déjà typé pour générer des timelines et rapports conformes aux exigences clients/ITSM.【F:backup-jlg/includes/class-bjlg-history.php†L445-L459】

### 3.8 Collaboration & gouvernance
- **Introduire des rôles granularisés** (Technicien, Observateur, Client final) et une gestion des accès par site ou groupe, au-delà de la capacité globale `manage_options` actuellement requise, pour refléter les modèles multi-équipes des agences et MSP.【F:backup-jlg/backup-jlg.php†L25-L80】
- **Ajouter des mentions d’auteur et d’approbateur sur les actions sensibles** (restauration, suppression de sauvegarde) avec validation en deux étapes ou commentaires, afin de capitaliser sur l’historique existant pour offrir une traçabilité comparable aux consoles pros.【F:backup-jlg/includes/class-bjlg-history.php†L445-L459】
- **Prévoir un mode « centre de services »** : vue consolidée des tickets/notifications pour plusieurs clients, délégation temporaire d’accès et intégration SSO (Google/Microsoft) afin de fluidifier le support managé et les handovers d’équipes.【F:backup-jlg/backup-jlg.php†L25-L80】

### 3.9 Automatisation & remédiation proactive
- **Standardiser des « playbooks » de reprise** (planification auto d’une sauvegarde à la détection d’un échec, rerun automatique après incident) plutôt que s’appuyer sur les seuls boutons « Exécuter »/« Mettre en pause » présents dans les cartes de planification.【F:backup-jlg/includes/class-bjlg-admin.php†L725-L760】
- **Mettre en place des recommandations dynamiques** dans l’UI (ex. proposer l’activation du chiffrement si non coché, suggérer une destination secondaire) via des encadrés guidés plutôt que des notices statiques générées par `renderScheduleFeedback()`.【F:backup-jlg/assets/js/admin.js†L550-L579】
- **Coupler les webhooks et l’API REST existants à des workflows prêts à l’emploi** (ex. intégrations Slack, Teams, ServiceNow) pour lancer automatiquement diagnostics, sauvegardes d’urgence ou notifications clients dès qu’un événement critique est loggé.【F:backup-jlg/includes/class-bjlg-rest-api.php†L301-L318】【F:backup-jlg/includes/class-bjlg-webhooks.php†L321-L354】

## 4. Prochaines étapes suggérées

1. Prioriser la refonte navigation + design system pour rapprocher l'expérience de BlogVault/ManageWP (impact adoption + accessibilité).
2. Lancer un sprint UI/UX dédié à l’onboarding et aux notifications proactives (inspiré de Jetpack Backup) pour renforcer la valeur perçue.
3. Préparer une « Design Review » trimestrielle avec test utilisateurs (admin WordPress, agences) pour valider l’ergonomie mobile et l’alignement brand.

Ces évolutions placeront Backup JLG au niveau des suites professionnelles en matière d’ergonomie, tout en valorisant les capacités avancées déjà présentes dans le code.
