=== Backup - JLG ===
Contributors: jlg
Tags: backup, restore, encryption, wordpress backup, scheduling
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sauvegarde et restauration WordPress avec chiffrement AES-256, API REST et destinations distantes.

== Description ==

Backup JLG protège un site WordPress (fichiers + base) avec chiffrement optionnel, planification, restauration et intégrations cloud.

* Assistant de sauvegarde manuelle et restauration (production ou sandbox)
* Chiffrement AES-256, HMAC d’intégrité, rotation incrémentale
* Planification, notifications, snapshots pré-update
* Destinations distantes (S3, Drive, Azure, SFTP, etc.)
* API REST et webhooks

L’administration utilise le chrome wp-admin natif (`wrap`, `nav-tab`, boutons WordPress). Les réglages sont déclarés via l’API Settings ; create / download / restore restent sur leurs flux dédiés (AJAX / admin-post).

== Installation ==

1. Copier le dossier `backup-jlg` dans `wp-content/plugins/`.
2. Activer le plugin.
3. Ouvrir Backup JLG, créer une sauvegarde, puis tester un téléchargement et une restauration.

PHP ≥ 7.4, WordPress ≥ 5.0. Mémoire 256 Mo recommandée pour les gros sites.

== Changelog ==

= 2.0.3 =
* IMPROVEMENT: Headers plugin complets (Requires at least, Requires PHP, Tested up to 7.1, License URI, Domain Path) et readme.txt WordPress.org.
* IMPROVEMENT: Settings API sur les réglages uniquement (hors cloud / planning). POST sans JavaScript = notice d’erreur visible, pas d’options.php.
* FIX: Enregistrer les performances ne désactive plus le chiffrement (collision sur `compression_level`).
* FIX: Formulaire Chiffrement (ancre `#bjlg-encryption-settings`) avec génération de clé et test, erreurs visibles.
