# Comparaison avec les solutions de sauvegarde professionnelles

## Points forts actuels

- **Parcours de sauvegarde assisté et personnalisable** : formulaires avec modèles, filtres d’inclusion/exclusion, vérifications post-sauvegarde et routage multi-destination reproduisent des workflows avancés que l’on retrouve chez UpdraftPlus ou BlogVault.【F:backup-jlg/includes/class-bjlg-admin.php†L332-L507】【F:backup-jlg/includes/class-bjlg-backup.php†L944-L1006】【F:backup-jlg/includes/class-bjlg-backup.php†L2119-L2166】
- **Protection des archives** : chiffrement AES-256, gestion de clé sécurisée et HMAC d’intégrité alignent le plugin sur les exigences de Jetpack Backup pour le stockage chiffré.【F:backup-jlg/includes/class-bjlg-encryption.php†L17-L155】
- **Automatisation robuste** : planificateur multi-scénarios, rotation automatique des archives, bilan de santé et historique exploitable via l’API REST offrent une observabilité équivalente aux consoles premium.【F:backup-jlg/includes/class-bjlg-scheduler.php†L35-L207】【F:backup-jlg/includes/class-bjlg-cleanup.php†L41-L142】【F:backup-jlg/includes/class-bjlg-health-check.php†L17-L152】【F:backup-jlg/includes/class-bjlg-history.php†L16-L117】【F:backup-jlg/includes/class-bjlg-rest-api.php†L54-L319】
- **Sécurité d’accès** : rate limiting, gestion de clés API et webhooks signés se rapprochent des contrôles d’accès « agency-grade » de ManageWP ou BlogVault.【F:backup-jlg/includes/class-bjlg-rate-limiter.php†L18-L62】【F:backup-jlg/includes/class-bjlg-api-keys.php†L13-L172】【F:backup-jlg/includes/class-bjlg-webhooks.php†L17-L196】

## Écarts observés face aux offres pro

- **Notifications multi-canales incomplètes** : l’interface propose email/Slack/Discord mais seul le webhook interne est implémenté, là où les solutions commerciales poussent des alertes temps réel sur plusieurs canaux.【F:backup-jlg/includes/class-bjlg-settings.php†L41-L54】【F:backup-jlg/includes/class-bjlg-webhooks.php†L24-L29】
- **Catalogue d’object storage** : Azure Blob et Backblaze B2 sont configurables côté réglages mais non exposés dans l’UI ni instanciés par défaut, quand UpdraftPlus Premium ou BlogVault les intègrent nativement.【F:backup-jlg/includes/class-bjlg-settings.php†L107-L124】【F:backup-jlg/includes/class-bjlg-backup.php†L2175-L2214】
- **Nettoyage distant** : la rotation incrémentale enregistre les suppressions à propager mais aucun worker natif ne consomme la file d’attente, à la différence des solutions SaaS qui purgent automatiquement les copies distantes.【F:backup-jlg/includes/class-bjlg-incremental.php†L279-L347】
- **Pilotage avant mise à jour** : il n’existe pas encore de déclencheur automatique avant une mise à jour de plugin/thème, une fonctionnalité courante des offres professionnelles pour éviter les régressions.

## Recommandations prioritaires

1. **Implémenter l’envoi réel des notifications** (emails, Slack/Discord) en s’appuyant sur les réglages existants et sur les hooks `bjlg_backup_complete`/`bjlg_backup_failed`, afin d’égaler les alertes omnicanales des solutions gérées.【F:backup-jlg/includes/class-bjlg-settings.php†L41-L54】【F:backup-jlg/includes/class-bjlg-webhooks.php†L24-L29】
2. **Exposer Azure Blob et Backblaze B2** dans l’interface et dans l’usine à destinations (chargement automatique + tests de connexion) pour couvrir les clouds supportés par les offres pro.【F:backup-jlg/includes/class-bjlg-settings.php†L107-L124】【F:backup-jlg/includes/class-bjlg-backup.php†L2175-L2214】
3. **Créer un orchestrateur de purge distante** qui consomme l’action `bjlg_incremental_remote_purge` et déclenche les API des stockages concernés, assurant une rotation complète sans intervention manuelle.【F:backup-jlg/includes/class-bjlg-incremental.php†L279-L347】
4. **Ajouter un déclencheur de sauvegarde pré-mise à jour** (hook `upgrader_pre_install` ou équivalent) pour automatiser les snapshots avant chaque update, comme le proposent UpdraftPlus Premium ou Jetpack Backup.
