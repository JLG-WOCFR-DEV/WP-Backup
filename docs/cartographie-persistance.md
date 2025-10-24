# Cartographie des composants persistants

Ce document dresse l'état des lieux des structures de persistance utilisées par le plugin BJLG afin de préparer l'ajout d'un champ `site_blog_id` et la migration associée.

## 1. Tables personnalisées

### Table d'historique des actions
- **Nom par site :** `wp_{blog_id}_bjlg_history`.
- **Nom en mode réseau :** `wp_bjlg_history_network` (préfixe réseau suivi de `bjlg_history_network`).
- **Schéma actuel :**
  - `id` (PK, auto-incrément), `timestamp`, `action_type`, `status`, `details`, `metadata` (JSON sérialisé), `user_id`, `ip_address`.
  - Index secondaires sur `action_type`, `status`, `timestamp` et `user_id`.
- **Provisionnement :** table créée/actualisée via `dbDelta()` lors de l'activation ou d'une montée de version, avec prise en charge du contexte multisite via `BJLG_Site_Context::get_table_prefix()` et détection du stockage réseau activé. 【F:backup-jlg/includes/class-bjlg-history.php†L19-L452】【F:backup-jlg/includes/class-bjlg-site-context.php†L178-L234】

> **Impact attendu :** l'ajout du champ `site_blog_id` devra couvrir les deux suffixes (`bjlg_history` et `bjlg_history_network`) et l'upgrade devra migrer les données existantes en renseignant le blog courant ou 0 (réseau) selon la portée.

## 2. Options et réglages stockés en base

### 2.1. Pilotage du contexte multisite
- `bjlg_history_scope` : mémorise si l'historique/les clés API sont stockés au niveau site ou réseau. 【F:backup-jlg/includes/class-bjlg-site-context.php†L14-L175】
- `bjlg_network_mode` : active le mode réseau du plugin et conditionne la redirection des options vers le scope réseau. 【F:backup-jlg/includes/class-bjlg-site-context.php†L18-L505】

### 2.2. Options synchronisées entre sites et réseau
`BJLG_Site_Context::get_managed_option_names()` centralise toutes les options gérées, avec synchronisation automatique lorsque le mode réseau est actif. Cette liste couvre les réglages d'intégrations distantes, de notifications, de chiffrement, de planification, de RBAC et de whitelabel. 【F:backup-jlg/includes/class-bjlg-site-context.php†L22-L320】

> **Impact attendu :** toutes les options listées devront, à terme, pouvoir être filtrées par `site_blog_id` lors de leur lecture/écriture si elles sont conservées dans une table optionnelle dédiée.

### 2.3. Options structurées critiques
Les options suivantes contiennent des enregistrements composites qui devront intégrer la notion de site lors d'une migration :

| Option | Portée actuelle | Description synthétique | Références |
| --- | --- | --- | --- |
| `bjlg_api_keys` | Site ou réseau selon `history_scope` | Tableau associatif indexé par identifiant contenant : identifiant, secret haché, métadonnées utilisateur (ID, e-mail, rôle), dates de création/rotation et expiration. Persisté via `bjlg_get_option`/`bjlg_update_option` avec normalisation stricte. | 【F:backup-jlg/includes/class-bjlg-api-keys.php†L15-L305】 |
| `bjlg_notification_queue` | Synchronisée réseau | File d'objets de notification contenant identifiants, canaux, contexte, escalade et horodatages. Persistance via option + verrou transient. | 【F:backup-jlg/includes/class-bjlg-notification-queue.php†L1-L1159】 |
| `bjlg_schedule_settings` | Site (avec copie réseau si activé) | Collection des planifications (primaires/secondaires) incluant destinations, récurrence, filtres, évènements déclencheurs. | 【F:backup-jlg/includes/class-bjlg-scheduler.php†L371-L417】 |
| `bjlg_event_trigger_settings` & `bjlg_event_trigger_state` | Site | Paramètres et état des déclencheurs événementiels (hook WP Cron, suivi des dernières exécutions). | 【F:backup-jlg/includes/class-bjlg-scheduler.php†L2268-L2651】 |
| `bjlg_encryption_key` & `bjlg_encryption_salt` | Site | Matière cryptographique (clé AES encodée Base64, sel pour protection par mot de passe) générée et stockée via helpers dédiés. | 【F:backup-jlg/includes/class-bjlg-encryption.php†L140-L210】【F:backup-jlg/includes/class-bjlg-encryption.php†L768-L771】 |
| `bjlg_remote_storage_metrics` & `bjlg_remote_storage_metrics_snapshot` | Synchronisée réseau | Instantané des capacités de stockage distantes (destinations, quotas, erreurs) actualisé par cron et protégé par un verrou transient. | 【F:backup-jlg/includes/class-bjlg-site-context.php†L22-L83】【F:backup-jlg/includes/class-bjlg-remote-storage-metrics.php†L8-L213】 |
| `bjlg_monitoring_settings` | Synchronisée réseau | Paramètre le TTL des métriques distantes (`remote_metrics_ttl_minutes`) et les seuils d'alerte de quota (`storage_quota_warning_threshold`). | 【F:backup-jlg/includes/class-bjlg-settings.php†L204-L226】【F:backup-jlg/includes/class-bjlg-settings.php†L1095-L1126】 |
| `bjlg_supervised_sites` | Forcée réseau | Liste des sites suivis par le tableau de bord réseau, maintenue via l'écran d'administration multi-site. | 【F:backup-jlg/includes/class-bjlg-admin.php†L4883-L4916】 |

D'autres options listées (ex. `bjlg_backup_*`, `bjlg_*_settings`, `bjlg_capability_map`, `bjlg_notification_settings`, `bjlg_whitelabel_settings`) restent structurées mais portent principalement des tableaux de configuration. Elles devront être auditées pour décider si la migration impose un découpage par site ou si le simple ajout du `site_blog_id` au niveau de la table d'historique suffit.

### 2.4. Méta utilisateur
- `bjlg_onboarding_progress` : suiveur des étapes d'onboarding par utilisateur via `get_user_meta`/`update_user_meta`. 【F:backup-jlg/includes/class-bjlg-admin.php†L238-L284】

## 3. Transients et caches liés aux tâches
- `bjlg_backup_task_lock` + `_transient_` homonymes : verrou et état des tâches de sauvegarde enregistrés côté transient/option, comprenant propriétaire, dates et progression. 【F:backup-jlg/includes/class-bjlg-backup.php†L80-L399】
- `bjlg_notification_queue_lock`, `bjlg_remote_storage_metrics_refresh_lock`, `bjlg_api_key_stats_*`, etc. : verrous et caches temporaires liés aux composants ci-dessus. 【F:backup-jlg/includes/class-bjlg-notification-queue.php†L1-L1511】【F:backup-jlg/includes/class-bjlg-remote-storage-metrics.php†L8-L213】【F:backup-jlg/includes/class-bjlg-api-keys.php†L487-L494】

> **Impact attendu :** les transients restent propres à chaque blog (sauf transients réseau explicites). Ils pourront servir pour l'upgrade afin d'étager la migration par site si nécessaire.

## 4. Points de vigilance pour l'upgrade
1. **Détection du scope courant :** se baser sur `BJLG_Site_Context::get_history_scope()` et `bjlg_network_mode` pour déterminer quelles tables/options nécessitent un `site_blog_id` spécifique pendant la migration. 【F:backup-jlg/includes/class-bjlg-site-context.php†L14-L505】
2. **Migration incrémentale :** prévoir une routine capable de boucler sur chaque site (`with_site`) afin de mettre à jour l'historique et les options en conséquence. 【F:backup-jlg/includes/class-bjlg-site-context.php†L200-L466】
3. **Compatibilité REST/UI :** les changements devront être répercutés sur les filtres REST et la sélection de site côté JavaScript (cf. étapes 2 et 3 du plan utilisateur), après sécurisation de la persistance.

Cette cartographie servira de base au script d'upgrade et à l'évolution des endpoints pour introduire le champ `site_blog_id` dans l'ensemble des composants persistants.
