# Backup - JLG 🛡️

Une solution professionnelle complète de sauvegarde et restauration pour WordPress avec chiffrement AES-256, API REST, et optimisations de performance.

## ✨ Fonctionnalités

### 🔐 Sécurité
- **Chiffrement AES-256-CBC** de toutes les sauvegardes
- **HMAC-SHA256** pour l'intégrité des données
- **API Keys sécurisées** pour l'accès distant
- **Tokens JWT** pour l'authentification
- **Protection par mot de passe** optionnelle
- **Limiteur de taux REST** basé sur l'adresse IP

### 🚀 Performance
- **Multi-threading** pour des sauvegardes 60-70% plus rapides
- **Sauvegardes incrémentales** pour économiser l'espace
- **Compression optimisée** avec plusieurs niveaux
- **Traitement par chunks** pour les gros sites
- **Cache intelligent** des métadonnées

### 🔌 Intégrations
- **API REST complète** pour CI/CD
- **Webhooks** pour déclencher des sauvegardes
- **Google Drive** (avec Composer)
- **Notifications** (Email, Slack, Discord)
- **Compatible WP-CLI**

### 📊 Monitoring
- **Dashboard moderne** avec statistiques en temps réel
- **Graphiques de performance** (Chart.js)
- **Benchmark intégré** pour tester le système
- **Historique détaillé** de toutes les actions
- **Health checks** automatiques
- **Vue par destination** : temps moyen de purge, projections de vidage et suivi des quotas pour chaque connecteur

## 📦 Installation

### Méthode 1 : Installation standard

1. Téléchargez le plugin
2. Décompressez dans `/wp-content/plugins/`
3. Activez depuis l'administration WordPress

### Méthode 2 : Avec Composer (pour Google Drive)

```bash
cd wp-content/plugins/backup-jlg
composer install
```

### Configuration requise

- **PHP** : 7.4 ou supérieur
- **WordPress** : 5.0 ou supérieur (Tested up to 7.1)
- **MySQL** : 5.6 ou supérieur
- **Mémoire PHP** : 256MB recommandé
- **Temps d'exécution** : 300s ou illimité recommandé

## 🔧 Configuration

### 1. Configuration de base

Ajoutez dans `wp-config.php` :

```php
// Mode debug du plugin
define('BJLG_DEBUG', true);

// Clé de chiffrement (générez-la depuis l'interface)
define('BJLG_ENCRYPTION_KEY', 'votre_cle_base64_ici');

// Augmenter les limites si nécessaire
define('WP_MEMORY_LIMIT', '256M');
define('WP_MAX_MEMORY_LIMIT', '512M');
```

### 2. Activation du chiffrement

1. Allez dans **Backup JLG → Chiffrement**
2. Activez le toggle de chiffrement
3. Cliquez sur "Lancer le test"
4. Générez une nouvelle clé si nécessaire

### 3. Configuration de l'API

1. Allez dans **Backup JLG → API & Intégrations**
2. Générez une clé API
3. Copiez la clé (elle ne sera plus visible après)

### 4. Limiteur de taux REST

Par défaut, le plugin ne se fie qu'à `REMOTE_ADDR` pour identifier les clients et
éviter les usurpations via des en-têtes HTTP. Si votre site est derrière un
reverse proxy géré (Cloudflare, load balancer, etc.) qui réécrit les en-têtes,
indiquez explicitement ceux à utiliser :

```php
// Dans un mu-plugin ou functions.php :
add_filter('bjlg_rate_limiter_trusted_proxy_headers', function () {
    return ['HTTP_X_FORWARDED_FOR'];
});
```

Il est également possible de définir l'option `bjlg_trusted_proxy_headers`
(`HTTP_X_FORWARDED_FOR,HTTP_CF_CONNECTING_IP`, par exemple). **Attention :** ne
faites confiance à ces en-têtes que si le proxy supprime systématiquement toute
valeur fournie par le client. Dans le cas contraire, l'adresse IP pourrait être
falsifiée et contourner le limiteur de taux.

### 5. Vérifier la connexion Google Drive

Une fois l'autorisation OAuth terminée, rendez-vous dans **Backup JLG → Réglages → Google Drive** et cliquez sur le bouton **Tester la connexion**. Le plugin enverra une requête légère pour valider le Client ID, le Client Secret et le dossier cible, affichera immédiatement le résultat et mémorisera la date du dernier test. Utilisez ce bouton après chaque changement d'identifiants pour confirmer que l'accès Drive est fonctionnel.

### 6. Ajuster les alertes de quota distant

1. Ouvrez **Backup JLG → Réglages** puis l'encart **Surveillance du stockage**.
2. Renseignez le pourcentage à partir duquel une destination distante doit être considérée comme saturée.
3. Enregistrez les réglages pour que le nouveau seuil soit pris en compte par le tableau de bord, les alertes et les notifications.

> ℹ️ Le seuil s'applique à toutes les destinations distantes configurées et doit rester compris entre **1 %** et **100 %**. Le tableau de bord indiquera automatiquement les destinations qui dépassent ce seuil et enverra un événement `bjlg_storage_warning` exploitable pour vos intégrations.

## 🎯 Utilisation

### Interface Web

1. **Créer une sauvegarde manuelle** :
   - Allez dans **Backup JLG → Sauvegarde & Restauration**
   - Sélectionnez les composants
   - Cliquez sur "Lancer la sauvegarde"

2. **Planifier des sauvegardes** :
   - Allez dans **Backup JLG → Réglages**
   - Configurez la fréquence et l'heure
   - Sauvegardez

3. **Restaurer** :
   - Cliquez sur "Restaurer" à côté d'une sauvegarde
   - Ou uploadez un fichier .zip
   - Pour les sauvegardes chiffrées (`.enc`), fournissez le mot de passe exact (minimum 4 caractères). Les champs vides sont refusés
     afin de garantir la protection des archives.

### Bloc éditeur « État des sauvegardes »

> 💡 Ce bloc dynamique s’appuie sur les métriques du tableau de bord et l’API REST `backup-jlg/v1/backups` pour présenter un résumé "front-office" fidèle.

1. Dans l’éditeur de blocs, ajoutez **Backup JLG → État des sauvegardes JLG**.
2. Utilisez le panneau **Options du bloc** pour choisir :
   - l’affichage du bouton **« Lancer une sauvegarde »** (ouvre la page d’administration correspondante),
   - l’affichage des alertes (échecs récents, absence d’archives, etc.),
   - l’affichage de la liste des **dernières archives générées**.
3. Le bloc montre automatiquement :
   - la dernière sauvegarde réussie et la prochaine exécution planifiée,
   - la taille totale du stockage et le nombre d’archives,
   - un CTA secondaire vers l’assistant de restauration.
4. En cas d’erreur ou d’accès interdit, un message dédié apparaît et un bouton « Réessayer » permet de relancer le chargement.

👉 Conseil : placez ce bloc sur une page interne destinée aux administrateurs afin qu’ils puissent lancer rapidement une sauvegarde ou consulter l’état du stockage sans ouvrir tout le tableau de bord.

### API REST

#### Authentification

```bash
# Avec API Key
curl -H "X-API-Key: bjlg_xxxxx" https://site.com/wp-json/backup-jlg/v1/backups
```

#### Créer une sauvegarde

```bash
curl -X POST https://site.com/wp-json/backup-jlg/v1/backups \
  -H "X-API-Key: bjlg_xxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "components": ["db", "plugins", "themes"],
    "encrypt": true,
    "type": "incremental"
  }'
```

> ℹ️ La propriété `description`, si fournie, est assainie et limitée à 255 caractères avant d'être stockée afin de garantir la sécurité des informations renvoyées par `bjlg_check_backup_progress`.

#### Obtenir le statut

```bash
curl https://site.com/wp-json/backup-jlg/v1/status \
  -H "X-API-Key: bjlg_xxxxx"
```

#### Observer les purges distantes

```bash
curl https://site.com/wp-json/backup-jlg/v1/monitoring/remote-purge \
  -H "X-API-Key: bjlg_xxxxx"
```

Réponse (extrait) :

```json
{
  "metrics": {
    "pending": { "total": 3, "average_seconds": 42 },
    "throughput": { "average_completion_seconds": 78 }
  },
  "destinations_overview": {
    "updated_at": 1700000000,
    "destinations": {
      "google_drive": {
        "pending": 1,
        "forecast_seconds": 180,
        "quota": {
          "current_ratio": 0.72,
          "projected_saturation": 1700003600
        }
      }
    }
  }
}
```

Utilisez ce point d’API pour afficher un tableau de bord externe ou automatiser les alertes lorsque la file de purge ou les quotas se rapprochent du seuil configuré.

#### Lister les sauvegardes

```bash
curl https://site.com/wp-json/backup-jlg/v1/backups?page=1&per_page=10 \
  -H "X-API-Key: bjlg_xxxxx"
```

### WP-CLI

```bash
# Créer une sauvegarde
wp bjlg backup create --components=db,plugins --encrypt

# Lister les sauvegardes
wp bjlg backup list

# Restaurer
wp bjlg backup restore backup-2024-01-15.zip

# Nettoyer les anciennes sauvegardes
wp bjlg cleanup --keep=5
```

### Webhook

Déclenchez une sauvegarde via une requête POST sécurisée :

* **Endpoint** : `https://site.com/?bjlg_trigger_backup=1`
* **Header** : `X-BJLG-Webhook-Key: VOTRE_CLE_WEBHOOK` (ou utilisez `Authorization: Bearer VOTRE_CLE_WEBHOOK`)

```bash
curl -X POST https://site.com/?bjlg_trigger_backup=1 \
  -H "Content-Type: application/json" \
  -H "X-BJLG-Webhook-Key: VOTRE_CLE_WEBHOOK"
```

> ℹ️ L'ancien format `https://site.com/?bjlg_trigger_backup=VOTRE_CLE_WEBHOOK` reste supporté durant la période de transition, mais sera retiré après migration.

> ❗ Si aucun composant valide n'est demandé (`components=foo` par exemple), l'API répond désormais avec un code **400** et le message `No valid components were requested. Allowed components are: db, plugins, themes, uploads.` sans réserver de créneau de sauvegarde.

## 📊 Endpoints API

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/info` | Informations sur l'API |
| POST | `/auth` | Authentification |
| GET | `/backups` | Liste des sauvegardes |
| POST | `/backups` | Créer une sauvegarde |
| GET | `/backups/{id}` | Détails d'une sauvegarde |
| DELETE | `/backups/{id}` | Supprimer une sauvegarde |
| GET | `/backups/{id}/download` | Télécharger une sauvegarde |
| POST | `/backups/{id}/restore` | Restaurer une sauvegarde |
| GET | `/status` | Statut du système |
| GET | `/health` | Santé du système |
| GET | `/stats` | Statistiques |
| GET | `/history` | Historique |
| GET/PUT | `/settings` | Configuration |
| GET/POST | `/schedules` | Planification |

## 🔔 Notifications

### Email

Configuration automatique avec l'email admin WordPress.

### Slack

1. Créez un webhook dans Slack
2. Ajoutez l'URL dans les réglages
3. Testez avec le bouton "Test"

### Discord

1. Créez un webhook dans Discord
2. Ajoutez l'URL dans les réglages
3. Personnalisez l'avatar et le nom

### Canaux supplémentaires à implémenter

- **Telegram Bot** : prévoir un expéditeur dédié dans `BJLG_Notification_Transport` et étendre l’UI avant d’exposer ce canal aux
  utilisateurs finaux.【F:backup-jlg/includes/class-bjlg-notification-transport.php†L13-L154】
- **SMS / providers tiers** : le socle de file d’attente (`BJLG_Notification_Queue`) gère les retries ; il reste à brancher un
  provider (Twilio, OVH, etc.) et à enrichir les réglages pour collecter les identifiants nécessaires.【F:backup-jlg/includes/class-bjlg-notification-queue.php†L8-L196】【F:backup-jlg/includes/class-bjlg-settings.php†L40-L115】

## 🐛 Débogage

### Activer les logs

```php
// Dans wp-config.php
define('BJLG_DEBUG', true);
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Localisation des logs

- Plugin : `/wp-content/bjlg-debug.log`
- WordPress : `/wp-content/debug.log`
- Sauvegardes : `/wp-content/bjlg-backups/`

### Pack de support

1. Allez dans **Backup JLG → Logs & Outils**
2. Cliquez sur "Créer un pack de support"
3. Téléchargez le fichier ZIP

## 🚀 Optimisations recommandées

### Serveur

```apache
# .htaccess
php_value memory_limit 256M
php_value max_execution_time 300
php_value post_max_size 128M
php_value upload_max_filesize 128M
```

### PHP.ini

```ini
memory_limit = 256M
max_execution_time = 0
max_input_time = 300
post_max_size = 128M
upload_max_filesize = 128M
```

### MySQL

```sql
SET GLOBAL max_allowed_packet = 64M;
SET GLOBAL wait_timeout = 600;
```

## 🔒 Sécurité

### Permissions recommandées

```bash
# Dossier de sauvegarde
chmod 755 wp-content/bjlg-backups
chmod 644 wp-content/bjlg-backups/.htaccess
chmod 644 wp-content/bjlg-backups/index.php
chmod 644 wp-content/bjlg-backups/web.config

# Fichiers du plugin
chmod 755 wp-content/plugins/backup-jlg
chmod 644 wp-content/plugins/backup-jlg/*.php
```

> ℹ️ Le dossier `wp-content/bjlg-backups/` contient désormais des fichiers sentinelles (`.htaccess`, `index.php`, `web.config`) créés automatiquement pour bloquer l'accès direct. Les opérations de nettoyage doivent les conserver en place.

### Headers de sécurité

```php
// Ajoutez dans wp-config.php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
```

## 📈 Benchmarks

| Taille du site | Sans optimisation | Avec multi-threading | Gain |
|----------------|-------------------|---------------------|------|
| 100 MB | 45s | 18s | 60% |
| 500 MB | 3min 20s | 1min 15s | 63% |
| 1 GB | 7min 10s | 2min 40s | 63% |
| 5 GB | 35min | 12min | 66% |

## 🤝 Support

- **Documentation** : [https://docs.backup-jlg.com](https://docs.backup-jlg.com)
- **Support** : support@jlg.dev
- **GitHub** : [https://github.com/jlg/backup-jlg](https://github.com/jlg/backup-jlg)

## 📝 Changelog

### Version 2.0.3 (2024-04-23)
- 🔧 Harmonisation de la version Composer avec la version déclarée dans le plugin principal.
- 📦 Préparation de la diffusion Packagist pour garantir la distribution de la version correcte.

### Version 2.0.0 (2024-01-15)
- ✨ Ajout du chiffrement AES-256
- ✨ API REST complète
- ✨ Multi-threading pour performances
- ✨ Sauvegardes incrémentales
- ✨ Interface moderne
- ✨ Système de notifications avancé
- 🛠️ Correction : l'API REST met à jour correctement les réglages de notifications et de webhooks
- 🐛 Correction du bug d'export SQL
- 🔧 Optimisations générales

### Version 1.0.0 (2024-01-01)
- 🎉 Version initiale

## 📄 Licence

GPL v2 ou ultérieure

## 👨‍💻 Auteur

**JLG** - Développement WordPress Premium

---

Made with ❤️ by JLG