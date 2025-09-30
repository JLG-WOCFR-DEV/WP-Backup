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
- **Notifications** (Email, Slack, Discord, Telegram, SMS)
- **Compatible WP-CLI**

### 📊 Monitoring
- **Dashboard moderne** avec statistiques en temps réel
- **Graphiques de performance** (Chart.js)
- **Benchmark intégré** pour tester le système
- **Historique détaillé** de toutes les actions
- **Health checks** automatiques

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
- **WordPress** : 5.0 ou supérieur
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

#### Obtenir le statut

```bash
curl https://site.com/wp-json/backup-jlg/v1/status \
  -H "X-API-Key: bjlg_xxxxx"
```

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

### Telegram

1. Créez un bot avec @BotFather
2. Obtenez le token et le chat ID
3. Configurez dans les réglages

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

# Fichiers du plugin
chmod 755 wp-content/plugins/backup-jlg
chmod 644 wp-content/plugins/backup-jlg/*.php
```

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