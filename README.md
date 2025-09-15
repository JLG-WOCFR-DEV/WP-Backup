# Backup JLG

Backup JLG est un plugin WordPress complet de sauvegarde et restauration qui combine chiffrement AES-256, automatisation, API REST et intégrations cloud pour protéger les sites professionnels. Ce dépôt contient le code du plugin ainsi que ses dépendances Composer optionnelles.

## 🎯 Objectifs du plugin
- Garantir des sauvegardes fiables (fichiers + base de données) avec chiffrement côté serveur.
- Accélérer les opérations grâce au traitement parallèle, à la compression optimisée et aux sauvegardes incrémentales.
- Offrir une automatisation avancée (planification, notifications, webhooks et API REST complète).
- Faciliter la restauration, le diagnostic et le support via une interface WordPress moderne et des outils de debug intégrés.

## ⚙️ Dépendances et prérequis
- PHP ≥ 7.4 avec les fonctions `shell_exec` et `proc_open` disponibles pour tirer parti des optimisations (le plugin fonctionne sans, mais en mode dégradé).
- WordPress ≥ 5.0 (testé en environnement single-site ; l’utilisation en multisite doit être validée avant production).
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

## 🚀 Exemples d’utilisation
### Interface WordPress
- Créer une sauvegarde manuelle via *Backup JLG → Sauvegarde & Restauration* en sélectionnant les composants (base, plugins, thèmes, uploads).
- Planifier des sauvegardes récurrentes et recevoir des notifications (Email, Slack, Discord, Telegram, SMS) depuis l’onglet *Réglages*.
- Restaurer une sauvegarde existante ou importer un fichier `.zip` directement depuis l’écran principal.

### API REST
```bash
# Lister les sauvegardes avec une clé API
curl -H "X-API-Key: bjlg_xxxxx" https://example.com/wp-json/backup-jlg/v1/backups

# Lancer une sauvegarde à la demande
curl -X POST https://example.com/wp-json/backup-jlg/v1/backups \
  -H "X-API-Key: bjlg_xxxxx" \
  -H "Content-Type: application/json" \
  -d '{"components":["db","uploads"],"encrypt":true}'
```

### WP-CLI (si activé)
```bash
wp backup-jlg backup --components=db,uploads --encrypt
wp backup-jlg restore --file=/chemin/vers/sauvegarde.zip
```

## 🧪 Commandes Composer utiles
- `composer test` : exécute la suite PHPUnit située dans le plugin.
- `composer cs` : lance PHP_CodeSniffer avec la norme WordPress.
- `composer cs-fix` : corrige automatiquement les violations de style détectées.

## ⚠️ Limitations connues
- Le multi-threading et les benchmarks automatiques nécessitent des fonctions systèmes (`shell_exec`, `proc_open`) souvent désactivées sur les hébergements mutualisés ; le plugin bascule alors en traitement séquentiel.
- L’intégration Google Drive et certaines notifications externes requièrent l’installation des dépendances Composer et la configuration d’identifiants tiers.
- Les environnements WordPress multisite ne sont pas officiellement supportés : réaliser des tests approfondis avant déploiement.
- Les performances optimales supposent des limites PHP élevées (mémoire, temps d’exécution) ; sur des valeurs faibles les sauvegardes de sites volumineux peuvent échouer.

## 📄 Licence
Backup JLG est distribué sous licence [GPL v2 ou ultérieure](https://www.gnu.org/licenses/gpl-2.0.html). Toute contribution doit respecter les termes de cette licence.

