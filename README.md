# Backup JLG

Backup JLG est un plugin WordPress complet de sauvegarde et restauration qui combine chiffrement AES-256, automatisation, API REST et intégrations cloud pour protéger les sites professionnels. Ce dépôt contient le code du plugin ainsi que ses dépendances Composer optionnelles.

## 🎯 Objectifs du plugin
- Garantir des sauvegardes fiables (fichiers + base de données) avec chiffrement côté serveur.
- Accélérer les opérations grâce au traitement parallèle, à la compression optimisée et aux sauvegardes incrémentales.
- Offrir une automatisation avancée (planification, API REST complète, webhooks et rotation automatique).
- Faciliter la restauration, le diagnostic et le support via une interface WordPress moderne et des outils de debug intégrés.

## 🧩 Fonctionnalités existantes

### Sauvegarde & restauration
- Assistant de sauvegarde manuel avec modèles réutilisables, sélection fine des composants, filtres d’inclusion/exclusion, vérification d’intégrité et envoi multi-destination.【F:backup-jlg/includes/class-bjlg-admin.php†L332-L507】【F:backup-jlg/includes/class-bjlg-backup.php†L944-L1006】【F:backup-jlg/includes/class-bjlg-backup.php†L2119-L2166】
- Vérification proactive de l’espace disque avant la création d’une archive avec marge de sécurité configurable et retour d’erreur unifié (UI, API, webhooks) pour éviter les sauvegardes partielles.【F:backup-jlg/includes/class-bjlg-backup.php†L602-L726】
- Chiffrement AES-256 avec gestion de clé, génération sécurisée et API Ajax pour tester/déverrouiller les archives.【F:backup-jlg/includes/class-bjlg-encryption.php†L17-L155】
- Sauvegardes incrémentales pilotées par manifeste (rotation configurable, suivi des destinations distantes, analyse des changements) et mise à jour automatique à chaque archive.【F:backup-jlg/includes/class-bjlg-incremental.php†L16-L156】【F:backup-jlg/includes/class-bjlg-backup.php†L964-L995】
- Restauration dans l’environnement de production ou en sandbox isolée via l’interface ou l’API REST, avec vérifications d’éligibilité des utilisateurs.【F:backup-jlg/includes/class-bjlg-rest-api.php†L192-L216】【F:backup-jlg/includes/class-bjlg-restore.php†L22-L263】【F:backup-jlg/includes/class-bjlg-restore.php†L1057-L1092】

### Automatisation & pilotage
- Planification avancée : intervalles personnalisés, duplication, lancement immédiat, gestion des destinations secondaires et synchronisation Cron.【F:backup-jlg/includes/class-bjlg-scheduler.php†L35-L207】
- Déclencheurs quasi temps réel (watcher inotify, webhooks applicatifs, binlogs MySQL) avec fenêtres de calme configurables et métriques dédiées pour tracer chaque lot d’événements.【F:backup-jlg/includes/class-bjlg-scheduler.php†L3005-L3394】【F:backup-jlg/includes/class-bjlg-admin.php†L4628-L4714】【F:backup-jlg/assets/js/admin-scheduling.js†L660-L838】
- Nettoyage automatique quotidien avec rotation des logs, purge locale/distante, suppression de fichiers temporaires et historique configurable, déclenchable aussi à la demande.【F:backup-jlg/includes/class-bjlg-cleanup.php†L41-L142】
- Table d’audit dédiée consignant chaque action (succès/échec), intégrée au tableau de bord et exposée via l’API.【F:backup-jlg/includes/class-bjlg-history.php†L16-L117】【F:backup-jlg/includes/class-bjlg-rest-api.php†L233-L284】
- Escalade multi-niveaux entièrement configurable (email → Slack → SMS, etc.) avec modèles par gravité pour adapter l’introduction, les actions et la conclusion des messages selon la criticité.【F:backup-jlg/includes/class-bjlg-notifications.php†L600-L737】【F:backup-jlg/includes/class-bjlg-admin.php†L3290-L3361】
- Snapshots pré-update configurables avec choix du mode (complet ou ciblé par type de mise à jour) et rappels différés planifiés via WP-Cron pour alerter l’équipe avant l’installation des mises à jour.【F:backup-jlg/includes/class-bjlg-update-guard.php†L44-L560】【F:backup-jlg/includes/class-bjlg-admin.php†L4684-L4826】【F:backup-jlg/assets/js/admin-settings.js†L1-L120】
- Bilan de santé complet (Cron, stockage, versions, extensions critiques) pour diagnostiquer l’environnement avant ou après une sauvegarde.【F:backup-jlg/includes/class-bjlg-health-check.php†L17-L152】
- Gestion fine des téléchargements : génération de liens éphémères sécurisés pour les archives, journalisation et contrôle d’accès frontend/backoffice.【F:backup-jlg/includes/class-bjlg-actions.php†L16-L200】

### Sécurité
- Chiffrement optionnel des archives, HMAC d’intégrité et possibilité de protéger par mot de passe via l’interface d’administration.【F:backup-jlg/includes/class-bjlg-encryption.php†L17-L155】
- Rate limiting des appels REST basé sur IP/API key/JWT pour bloquer les abus et journaliser les dépassements.【F:backup-jlg/includes/class-bjlg-rate-limiter.php†L18-L62】【F:backup-jlg/includes/class-bjlg-rest-api.php†L220-L349】
- Gestion des clés API (création, rotation, révocation, attribution à un utilisateur précis) avec logs dédiés et nonce personnalisé.【F:backup-jlg/includes/class-bjlg-api-keys.php†L13-L172】
- Webhook entrant sécurisé (clé secrète, anti-rejeu, limitation) et webhooks sortants pour être notifié des succès/échecs.【F:backup-jlg/includes/class-bjlg-webhooks.php†L17-L196】【F:backup-jlg/includes/class-bjlg-webhooks.php†L420-L475】

### API & intégrations
- API REST riche couvrant la gestion complète des sauvegardes, restaurations, historiques, statistiques, paramètres et planifications, avec pagination/validation détaillées.【F:backup-jlg/includes/class-bjlg-rest-api.php†L54-L319】
- Téléchargements REST protégés par jetons temporaires et routage dédié pour la restauration distante.【F:backup-jlg/includes/class-bjlg-rest-api.php†L178-L219】【F:backup-jlg/includes/class-bjlg-actions.php†L66-L200】
- Destinations distantes prêtes à l’emploi : Google Drive, Amazon S3, Wasabi, Dropbox, OneDrive, pCloud, Azure Blob, Backblaze B2 et SFTP, sélectionnables dans l’interface et depuis l’automatisation.【F:backup-jlg/includes/class-bjlg-admin.php†L62-L78】【F:backup-jlg/includes/class-bjlg-backup.php†L2175-L2214】
- Tableau de bord récapitulatif (cartes, tendances, alertes) alimenté par les services avancés du plugin, avec un panneau « Files d’attente » dédié aux notifications et purges distantes, exportable vers un bloc Gutenberg public si nécessaire.【F:backup-jlg/includes/class-bjlg-admin.php†L146-L459】【F:backup-jlg/includes/class-bjlg-admin-advanced.php†L30-L231】【F:backup-jlg/assets/css/admin.css†L312-L404】【F:backup-jlg/includes/class-bjlg-blocks.php†L67-L200】

## ⚙️ Dépendances et prérequis
- PHP ≥ 7.4 avec les fonctions `shell_exec` et `proc_open` disponibles pour tirer parti des optimisations (le plugin fonctionne sans, mais en mode dégradé).
- WordPress ≥ 5.0 (Tested up to 7.1 ; l’utilisation en multisite doit être validée avant production).
- Base de données MySQL ≥ 5.6 ou équivalent compatible.
- Mémoire PHP de 256 Mo recommandée et temps d’exécution de 300 s minimum pour les sites volumineux.
- (Optionnel) Composer pour installer les dépendances facultatives comme Google Drive (`google/apiclient`).

## 📦 Installation
### Méthode standard
1. Télécharger l’archive du plugin ou cloner ce dépôt dans `wp-content/plugins/`.
2. Vérifier les prérequis serveur (PHP, WordPress, extensions requises).
3. Activer le plugin depuis l’interface d’administration WordPress.

### Installation avec Composer
```bash
cd wp-content/plugins
# Cloner le dépôt si nécessaire
composer create-project jlg/backup-jlg backup-jlg
# Installer les dépendances optionnelles (Google Drive, tests…)
composer install
```
Les dépendances sont installées dans `vendor-bjlg/` afin de ne pas entrer en conflit avec d’autres plugins.

## 🔧 Configuration
1. **Mode debug et limites serveur** : ajouter au besoin dans `wp-config.php` :
   ```php
   define('BJLG_DEBUG', true);            // Active les logs du plugin
   define('BJLG_ENCRYPTION_KEY', 'base64:...');
   define('WP_MEMORY_LIMIT', '256M');
   define('WP_MAX_MEMORY_LIMIT', '512M');
   ```
2. **Chiffrement** : dans *Backup JLG → Chiffrement*, activer le toggle, lancer le test et générer une clé depuis l’interface si vous n’en avez pas défini.
3. **API & intégrations** : générer une clé API via *Backup JLG → API & Intégrations* pour l’usage CI/CD ou les webhooks externes. Conserver la clé dans un gestionnaire sécurisé.
4. **Planification** : configurer la fréquence des sauvegardes dans *Backup JLG → Réglages*. Vérifier que `wp-cron.php` est autorisé à s’exécuter (ou configurer une tâche Cron système).
5. **Stockage distant (optionnel)** : après `composer install`, renseigner les identifiants Google Drive (ou autre service supporté) dans l’écran d’intégrations.
6. **Monitoring du stockage distant** : ajustez le seuil d’alerte (en %) et la fréquence de rafraîchissement dans *Backup JLG → Réglages* pour être averti lorsque vos destinations dépassent la capacité définie.

## 🚀 Exemples d’utilisation
### Interface WordPress
- Créez une sauvegarde manuelle via *Backup JLG → Sauvegarde & Restauration*, appliquez vos modèles puis suivez la progression en temps réel.
- Planifiez plusieurs scénarios récurrents (fréquences, destinations secondaires, filtres) depuis *Réglages → Planification*.
- Consultez l’historique complet, déclenchez un nettoyage ou lancez une restauration (production ou sandbox) directement depuis le tableau de bord.

### API REST
```bash
# Lister les sauvegardes avec une clé API
curl -H "X-API-Key: bjlg_xxxxx" https://example.com/wp-json/backup-jlg/v1/backups

# Lister les sauvegardes et demander immédiatement un lien téléchargeable
curl -H "X-API-Key: bjlg_xxxxx" "https://example.com/wp-json/backup-jlg/v1/backups?with_token=1"

# Lancer une sauvegarde à la demande
curl -X POST https://example.com/wp-json/backup-jlg/v1/backups \
  -H "X-API-Key: bjlg_xxxxx" \
  -H "Content-Type: application/json" \
  -d '{"components":["db","uploads"],"encrypt":true}'
```

#### Télécharger une sauvegarde

Les réponses des routes `GET /backups` et `GET /backups/{id}` contiennent désormais uniquement l’URL REST `.../backups/{id}/download` lorsque `with_token` est absent ou vaut `0`. Appelez ce point d’entrée pour générer un token de téléchargement à la demande :

```bash
curl -H "X-API-Key: bjlg_xxxxx" \
  https://example.com/wp-json/backup-jlg/v1/backups/{backup-id}/download
```

Vous pouvez toujours demander la génération immédiate d’un lien signé en ajoutant `with_token=1` à vos requêtes `GET /backups` ou `GET /backups/{id}`.

## 🧪 Commandes Composer utiles
- `composer test` : exécute la suite PHPUnit située dans le plugin.
- `composer cs` : lance PHP_CodeSniffer avec la norme WordPress.
- `composer cs-fix` : corrige automatiquement les violations de style détectées.

## 🔍 Comparaison avec les offres professionnelles
- Consultez [docs/comparaison-pro.md](docs/comparaison-pro.md) pour une analyse détaillée des forces de Backup JLG face aux solutions de sauvegarde managées et pour une feuille de route d’améliorations priorisées.【F:docs/comparaison-pro.md†L1-L126】

## ⚠️ Limitations connues
- Le multi-threading et les benchmarks automatiques nécessitent des fonctions systèmes (`shell_exec`, `proc_open`) souvent désactivées sur les hébergements mutualisés ; le plugin bascule alors en traitement séquentiel.【F:backup-jlg/includes/class-bjlg-performance.php†L57-L109】
- L’orchestration multisite (tables dédiées, préfixes, mutualisation API) reste à finaliser ; validez soigneusement chaque déploiement réseau avant production.【F:docs/roadmap-suivi.md†L123-L135】【F:docs/priorites-gaps.md†L17-L24】
- Les exports SLA consolidés (rapports post-mortem, projections de saturation, recommandations automatiques) sont en cours : les nouvelles métriques de quotas doivent encore alimenter des rapports partageables.【F:docs/roadmap-suivi.md†L19-L122】【F:docs/priorites-gaps.md†L25-L32】
- Les performances optimales supposent des limites PHP élevées (mémoire, temps d’exécution) ; sur des valeurs faibles les sauvegardes de sites volumineux peuvent échouer.

## 🔮 Améliorations proposées
- **Orchestrer les alertes multi-canales** : finaliser la boucle en générant automatiquement des rapports de résolution, des accusés de réception et des rappels intelligents sur les scénarios d’escalade multi-niveaux désormais configurables avec templates par gravité.【F:backup-jlg/includes/class-bjlg-notifications.php†L600-L778】【F:backup-jlg/includes/class-bjlg-notification-queue.php†L360-L503】【F:backup-jlg/assets/js/admin-settings.js†L240-L420】
- **Documenter la purge distante** : le worker gère les retries, les alertes de retard, expose désormais un tableau de bord SLA (âge moyen, destinations touchées, dernière purge) et alimente l’interface ; la prochaine étape est de générer des prédictions de saturation et d’orchestrer des actions correctives automatiques.【F:backup-jlg/includes/class-bjlg-remote-purge-worker.php†L11-L320】【F:backup-jlg/includes/class-bjlg-admin-advanced.php†L160-L420】【F:backup-jlg/includes/class-bjlg-admin.php†L900-L1134】
- **Affiner la planification** : les pas 5/15 minutes et un champ Cron avancé validé côté serveur/REST permettent désormais de couvrir les scénarios sur mesure ; reste à proposer des aides contextuelles et garde-fous UI pour les expressions complexes.【F:backup-jlg/includes/class-bjlg-admin.php†L3008-L3071】【F:backup-jlg/includes/class-bjlg-scheduler.php†L172-L329】【F:backup-jlg/includes/class-bjlg-rest-api.php†L1600-L1665】
- **Suivre le stockage distant** : compléter les métriques du tableau de bord (actuellement centrées sur le répertoire local) avec les quotas et consommations renvoyés par chaque destination distante afin d’anticiper les alertes de capacité.【F:backup-jlg/includes/class-bjlg-admin-advanced.php†L60-L185】

### 📊 Suivi de la feuille de route

Pour connaître l’état d’avancement, les dépendances et les prochaines étapes de chaque axe stratégique (notifications, purge distante, planification avancée, supervision du stockage, multisite, etc.), consultez le tableau de synthèse dans [`docs/roadmap-suivi.md`](docs/roadmap-suivi.md). Ce document est mis à jour à mesure que les chantiers progressent et s’appuie sur les mêmes références techniques que la comparaison avec les offres professionnelles.【F:docs/roadmap-suivi.md†L1-L94】【F:docs/comparaison-pro.md†L94-L159】

## 📄 Licence
Backup JLG est distribué sous licence [GPL v2 ou ultérieure](https://www.gnu.org/licenses/gpl-2.0.html). Toute contribution doit respecter les termes de cette licence.

