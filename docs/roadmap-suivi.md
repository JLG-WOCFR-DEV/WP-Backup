# Suivi des axes d'amélioration prioritaires

Ce tableau de bord complète la section « 🔮 Améliorations proposées » du README en apportant une vision consolidée de l'état d'avancement, des dépendances et des prochaines étapes pour chaque chantier stratégique. Il s'appuie sur les observations détaillées dans [`docs/comparaison-pro.md`](comparaison-pro.md) afin de rester aligné avec les attentes des solutions professionnelles.

## Synthèse par axe

| Axe | Problème constaté | État actuel | Prochaines étapes | Impact attendu |
| --- | --- | --- | --- | --- |
| Notifications multi-canales | Première étape d’escalade et fenêtres de silence présentes, mais absence de scénarios multi-niveaux, de rappels récurrents et de templates par gravité comme sur les solutions pro.【F:docs/comparaison-pro.md†L70-L108】 | Dispatcher asynchrone multi-canaux (email, Slack, Discord, Teams, SMS) avec retries, file observable, fenêtre de silence configurable et escalade différée vers les canaux critiques depuis l’interface.【F:backup-jlg/includes/class-bjlg-notifications.php†L21-L470】【F:backup-jlg/includes/class-bjlg-notification-queue.php†L40-L196】【F:backup-jlg/includes/class-bjlg-admin.php†L2674-L2922】【F:backup-jlg/assets/js/admin-dashboard.js†L328-L420】 | Définir des scénarios multi-niveaux (email → Slack → SMS), proposer des modèles contextualisés et des rapports de résolution. | Alignement sur les alertes temps réel exigées par les équipes support/DevOps.【F:docs/comparaison-pro.md†L70-L108】 |
| Purge distante automatisée | Métriques SLA disponibles (âge moyen, destinations, dernier succès) mais absence de projections de saturation et d’actions correctives automatisées comparables aux consoles SaaS.【F:docs/comparaison-pro.md†L109-L134】 | Worker `bjlg_process_remote_purge_queue` cadencé toutes les 5 min + déclenchement asynchrone, retries exponentiels, alertes de retard, journalisation, métriques SLA et synthèse dans le tableau de bord.【F:backup-jlg/includes/class-bjlg-remote-purge-worker.php†L11-L320】【F:backup-jlg/includes/class-bjlg-admin-advanced.php†L160-L420】 | Ajouter des projections de saturation, des alertes proactives et des actions de remédiation automatiques avant dépassement de quota. | Réduction du stockage distant et parité avec l'automatisation pro.【F:docs/comparaison-pro.md†L109-L134】 |
| Planification avancée | Intervalles 5/15 minutes ajoutés mais absence de champ Cron libre pour les scénarios d'orchestration fine.【F:backup-jlg/includes/class-bjlg-settings.php†L18-L72】【F:backup-jlg/includes/class-bjlg-scheduler.php†L47-L119】 | UI expose les fréquences courtes et propose désormais un champ Cron expert avec validations serveur/REST et calcul du prochain passage avant mise en production.【F:backup-jlg/includes/class-bjlg-admin.php†L3008-L3071】【F:backup-jlg/includes/class-bjlg-scheduler.php†L172-L329】【F:backup-jlg/includes/class-bjlg-rest-api.php†L1600-L1665】 | Ajouter des aides contextuelles (exemples, auto-complétion) et des contrôles côté interface pour éviter les expressions gourmandes ou contradictoires. | Flexibilité accrue pour les environnements exigeants (CI/CD, snapshots pré-déploiement).【F:docs/comparaison-pro.md†L133-L149】 |
| Supervision du stockage distant | Le tableau de bord agrège seulement les tailles locales alors que les solutions SaaS surveillent les quotas distants.【F:backup-jlg/includes/class-bjlg-admin-advanced.php†L60-L185】 | Collecte locale fonctionnelle via le module avancé (répertoires, tendances).【F:backup-jlg/includes/class-bjlg-admin-advanced.php†L60-L185】 | Intégrer les API des destinations distantes (S3, Drive, etc.), stocker les quotas et générer des alertes. | Prévention proactive des incidents de capacité et SLA renforcé.【F:docs/comparaison-pro.md†L150-L159】 |
| Support multisite & gestion centralisée | Aucun support officiel multisite ni supervision multi-projet contrairement aux consoles agence (ManageWP, BlogVault).【F:README.md†L111-L116】 | API REST robuste et historique SQL centralisés mais pensés pour un seul site.【F:backup-jlg/includes/class-bjlg-rest-api.php†L54-L319】 | Adapter la création des tables, gérer les préfixes multisite et mutualiser les appels API. | Adoption par les agences et rapprochement des offres pro multi-tenant.【F:docs/comparaison-pro.md†L116-L126】 |

## Détails opérationnels par axe

### Notifications multi-canales
- **Livré** : un dispatcher unique pilote email, Slack, Discord, Teams et SMS avec gestion des fenêtres de silence, relance différée et observabilité dans le tableau de bord et la file d’attente dédiée.【F:backup-jlg/includes/class-bjlg-notifications.php†L21-L470】【F:backup-jlg/includes/class-bjlg-notification-queue.php†L40-L196】【F:backup-jlg/includes/class-bjlg-admin.php†L2674-L2922】【F:backup-jlg/assets/js/admin-dashboard.js†L328-L420】
- **À prioriser** : ajouter des scénarios multi-niveaux, des rappels programmés et des modèles par gravité pour se hisser au niveau des consoles omnicanales pro.【F:docs/comparaison-pro.md†L70-L108】【F:docs/plan-amelioration-ux-fiabilite.md†L25-L28】
- **Blocage identifié** : l’absence de templates et de hiérarchisation des canaux limite la lisibilité des alertes en situation de crise par rapport aux suites pro.【F:docs/comparaison-pro.md†L70-L108】

### Purge distante automatisée
- **Livré** : le worker `bjlg_process_remote_purge_queue` gère les retries, le backoff, les alertes de retard et expose l’état de la file dans l’interface avancée pour suivre les SLA actuels.【F:backup-jlg/includes/class-bjlg-remote-purge-worker.php†L11-L320】【F:backup-jlg/includes/class-bjlg-admin-advanced.php†L160-L420】
- **À prioriser** : calculer et afficher le temps moyen de purge, les projections de saturation et les quotas distants collectés via les destinations configurées.【F:docs/comparaison-pro.md†L109-L134】【F:docs/plan-amelioration-ux-fiabilite.md†L25-L29】
- **Blocage identifié** : sans indicateurs SLA, il reste difficile de prévenir les dépassements de capacité sur les stockages distants contrairement aux solutions SaaS de référence.【F:docs/comparaison-pro.md†L109-L134】

### Planification avancée
- **Livré** : l’interface expose des fréquences courtes (5/15 minutes), un champ Cron expert validé côté serveur/REST et le calcul du prochain passage avant activation.【F:backup-jlg/includes/class-bjlg-admin.php†L3008-L3071】【F:backup-jlg/includes/class-bjlg-scheduler.php†L172-L329】【F:backup-jlg/includes/class-bjlg-rest-api.php†L1600-L1665】
- **À prioriser** : ajouter des aides contextuelles, des exemples d’expression et des garde-fous UI pour éviter les erreurs de Cron gourmandes ou contradictoires.【F:docs/comparaison-pro.md†L133-L149】【F:docs/plan-amelioration-ux-fiabilite.md†L23-L29】
- **Blocage identifié** : les utilisateurs avancés doivent encore se référer à une documentation externe pour sécuriser leurs expressions, ce qui freine l’adoption face aux consoles pro qui prévisualisent le planning.【F:docs/comparaison-pro.md†L133-L149】

### Supervision du stockage distant
- **Livré** : le tableau de bord agrège les volumes locaux, l’âge des archives et les tendances afin de détecter les dérives de stockage sur le serveur WordPress.【F:backup-jlg/includes/class-bjlg-admin-advanced.php†L60-L185】
- **À prioriser** : requêter les API des destinations (S3, Azure, B2, etc.) pour afficher quotas et consommation, puis déclencher des alertes préventives en cas de dépassement imminent.【F:docs/comparaison-pro.md†L150-L159】【F:docs/plan-amelioration-ux-fiabilite.md†L29-L30】
- **Blocage identifié** : sans métriques distantes, les équipes ne peuvent pas piloter la capacité globale et restent exposées aux interruptions de service côté fournisseur cloud.【F:docs/comparaison-pro.md†L150-L159】

### Support multisite & gestion centralisée
- **Livré** : l’API REST et l’audit SQL sont structurés mais restent dimensionnés pour une instance unique.【F:backup-jlg/includes/class-bjlg-rest-api.php†L54-L319】
- **À prioriser** : adapter la création des tables, gérer les préfixes multisite et centraliser l’authentification pour administrer plusieurs sites depuis une console unique.【F:README.md†L111-L116】【F:docs/comparaison-pro.md†L116-L126】
- **Blocage identifié** : l’absence de mutualisation freine les agences habituées aux consoles multi-tenant comme ManageWP ou BlogVault Agency.【F:docs/comparaison-pro.md†L116-L126】

## Priorités temporelles

- **0–3 mois** : clôturer les quick wins (notifications effectives, worker de purge, exposition des destinations Azure/B2) pour couvrir les écarts les plus visibles avec UpdraftPlus/BlogVault.【F:docs/comparaison-pro.md†L160-L193】 
- **3–6 mois** : refondre progressivement l'UI (multi-pages, composants `@wordpress/components`) et automatiser les snapshots pré-mise à jour afin d'égaler les suites pro sur l'expérience et la sécurité.【F:docs/comparaison-pro.md†L194-L226】
- **>6 mois** : investir dans le support multisite, la supervision orientée SLA et un stockage managé multi-région pour se positionner sur les offres premium gérées.【F:docs/comparaison-pro.md†L227-L260】

## Suivi et mise à jour

Chaque entrée est revue lors des itérations produit. Lorsqu'un axe évolue (ex. livraison d'un canal Slack, ajout d'une API de quota), mettre à jour la colonne « État actuel » et détailler les gains observés dans la colonne « Impact attendu ». Conserver les références de code actualisées afin de garantir la traçabilité entre la documentation et l'implémentation.
