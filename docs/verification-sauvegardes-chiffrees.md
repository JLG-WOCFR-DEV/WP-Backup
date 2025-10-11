# Vérifications des sauvegardes chiffrées

Les contrôles post-sauvegarde (lecture du manifeste, validation du dump SQL, simulation de restauration, etc.) sont désormais exécutés même lorsque l'archive finale est chiffrée. Le module de chiffrement génère automatiquement une copie déchiffrée dans un répertoire temporaire, exécute l'ensemble des vérifications dessus puis supprime chaque fichier temporaire, y compris en cas d'erreur.

## Mot de passe pour les archives protégées

Si vos sauvegardes sont protégées par mot de passe, la vérification doit pouvoir déchiffrer l'archive. Deux approches sont possibles :

1. **Mot de passe stocké dans les options** : renseignez un champ `password` (ou `encryption_password`) dans l'option `bjlg_encryption_settings`. Le module utilisera automatiquement cette valeur lors des contrôles.
2. **Filtre dédié** : exposez dynamiquement le mot de passe via le filtre `bjlg_post_backup_checks_password`. Cela permet, par exemple, de récupérer le secret depuis une variable d'environnement ou un coffre-fort externe :

   ```php
   add_filter('bjlg_post_backup_checks_password', static function ($password, $filepath, $post_checks, $backup) {
       // Retourner le mot de passe à utiliser pour la copie temporaire.
       return getenv('BJLG_BACKUP_PASSWORD');
   }, 10, 4);
   ```

Sans mot de passe valide, la vérification échouera et un message explicite indiquera comment fournir le secret.

## Nettoyage automatique

Les copies déchiffrées ne sont conservées qu'entre la préparation et la fin des vérifications. Elles sont supprimées même en cas d'échec, garantissant qu'aucune donnée sensible ne reste sur le disque après l'opération.
