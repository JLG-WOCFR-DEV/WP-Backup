# Plan de mitigation – Jetons de téléchargement BJLG

## 1. Résumé exécutif
Un défaut de conception dans la diffusion des sauvegardes permettait à tout acteur disposant d’un jeton de téléchargement actif d’accéder aux archives, même s’il n’était pas l’émetteur initial. Les routes AJAX et front-end (`admin-ajax.php?action=bjlg_download` et `?bjlg_download=`) ne lient pas le jeton à la session, elles se basent uniquement sur la charge utile stockée en transitoire (`bjlg_download_{token}`).【F:backup-jlg/includes/class-bjlg-actions.php†L24-L120】【F:backup-jlg/includes/class-bjlg-actions.php†L231-L320】 Quand la charge utile ne contient pas l’identifiant de l’émetteur (schéma ≤ v1), n’importe quel compte ayant la capacité `bjlg_get_required_capability()` — ou un attaquant ayant seulement le jeton si le site abaisse cette capacité — peut télécharger et exfiltrer la sauvegarde, ouvrant une voie d’escalade de privilèges via la fuite de données sensibles.【F:backup-jlg/includes/class-bjlg-actions.php†L471-L503】【F:backup-jlg/backup-jlg.php†L200-L240】

## 2. Versions affectées
- 1.0.0 à 2.0.2 : le schéma de charge utile (`bjlg_download_*`) ne persistait ni `issued_by` ni un indicateur d’appartenance, rendant les tokens partageables sans contrôle d’identité.
- 2.0.3 : ajoute le champ `issued_by` et l’exige à la validation, mais ne purge pas les tokens existants ; les sites n’ayant pas déclenché `BJLG_Actions::maybe_upgrade_download_tokens()` après la mise à jour restent exposés tant que d’anciens transitoires sont actifs.【F:backup-jlg/includes/class-bjlg-actions.php†L364-L420】

## 3. Impact
- **Confidentialité :** fuite totale des sauvegardes (base de données + fichiers) => compromission des identifiants, secrets d’API et données clients.
- **Intégrité :** accès non autorisé aux archives permet de préparer des attaques ultérieures (ingénierie sociale, relecture du code).
- **Disponibilité :** faible, mais une restauration malveillante reste possible si l’archive est réintroduite par d’autres canaux.
- **Score CVSS provisoire :** 8.2 (AV:N/AC:L/PR:L/UI:N/S:C/C:H/I:L/A:N) – accès réseau, faible complexité, privilèges faibles (rôle éditeur/support si capacité abaissée), impact élevé sur la confidentialité.

## 4. Mesures immédiates
1. **Purge forcée des transitoires** : exécuter `BJLG_Actions::maybe_upgrade_download_tokens()` via un correctif ou un MU-plugin temporaire pour supprimer les charges utiles héritées.【F:backup-jlg/includes/class-bjlg-actions.php†L364-L420】
2. **Rotation des jetons** : invalider tous les liens partagés (diagnostic, téléchargements REST, partages externes) et générer de nouveaux tokens une fois le patch appliqué.
3. **Restriction des capacités** : vérifier les filtres `bjlg_required_capability` et rétablir `manage_options` au minimum tant que le correctif n’est pas déployé.【F:backup-jlg/backup-jlg.php†L200-L240】
4. **Journalisation ciblée** : activer les logs (`BJLG_Debug`) et auditer l’historique `backup_download_*` pour détecter d’éventuels abus sur la période concernée.【F:backup-jlg/includes/class-bjlg-actions.php†L199-L320】

## 5. Plan de communication
- **Support & CSM** : envoyer un bulletin interne « priorité haute » détaillant la purge obligatoire et les actions d’audit avant la fin de journée (UTC). Inclure un script WP-CLI simplifié pour invalider les transitoires.
- **Clients / administrateurs** : préparer un message prêt-à-envoyer (email + bannière admin) expliquant le risque, les versions affectées et les étapes (mise à jour, rotation des tokens, vérification des journaux).
- **Équipe WP.org** : notifier l’équipe plugin review avec le brouillon d’avis de sécurité et la fenêtre de publication envisagée (proposer 72h de préavis pour la divulgation coordonnée).

## 6. Suivi & prochaines étapes
- Lancer un ticket « security-hotfix » pour intégrer la purge forcée au bootstrap du plugin et ajouter un CRON de nettoyage quotidien.
- Planifier une revue de régression couvrant les autres générateurs de tokens (`BJLG_Diagnostics`, API REST, destinations distantes) et ajouter des tests unitaires sur `BJLG_Actions::build_download_token_payload()` pour vérifier la présence systématique de `issued_by` et `requires_cap`.
- Organiser un post-mortem (J+7) incluant l’analyse des logs, la rotation des clés API et la mise à jour des modèles de menace.
