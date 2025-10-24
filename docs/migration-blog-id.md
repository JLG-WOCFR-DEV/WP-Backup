# Migration du réseau vers le stockage multi-sites (`blog_id`)

Cette procédure décrit comment mettre à niveau un réseau WordPress vers la nouvelle
architecture Backup JLG 2.1 qui introduit :

- l'identifiant de site (`blog_id`) dans toutes les écritures d'historique ;
- la consolidation réseau de l'interface d'administration et des endpoints REST ;
- la synchronisation des clés API par site.

## 1. Préparation

1. Mettre le réseau en maintenance et prévenir les administrateurs.
2. Vérifier que vous disposez d'un accès `wp-cli` avec un compte disposant des
   capacités `manage_network` et `manage_sites`.
3. Réaliser une sauvegarde complète de la base de données (dump SQL ou snapshot).

> **Astuce** : conserver la commande de restauration correspondante, elle sera
> nécessaire en cas de retour arrière.

## 2. Mettre à jour les tables d'historique

Toutes les tables `wp_bjlg_history` doivent être recréées via `dbDelta` pour
ajouter les colonnes et index basés sur `blog_id`.

```bash
# Pour le site réseau (table centrale)
wp eval 'BJLG\\BJLG_History::create_table(0);'

# Pour l’ensemble des sous-sites
wp site list --field=blog_id | xargs -I % wp --url=$(wp site get % --field=url) \
    eval 'BJLG\\BJLG_History::create_table();'
```

La seconde commande ré-exécute `dbDelta` dans le contexte de chaque site et
peut être lancée plusieurs fois sans risque.

## 3. Synchroniser les clés API et les permissions

La lecture des clés API applique désormais un `blog_id`. Exécuter la commande
suivante pour mettre à jour les enregistrements existants (réseau puis sites).

```bash
# Vue réseau
wp eval 'BJLG\\BJLG_API_Keys::get_keys();'

# Tous les sites du réseau
wp site list --field=blog_id | xargs -I % wp --url=$(wp site get % --field=url) \
    eval 'BJLG\\BJLG_API_Keys::get_keys();'
```

Cette opération force la persistance des clés normalisées avec leur site de
rattachement et garantit la cohérence des permissions associées.

## 4. Vérifier l’API REST et l’interface réseau

1. Confirmer que le mode réseau est actif : `wp option get bjlg_network_mode` doit
   retourner `network`.
2. Vérifier les nouveaux endpoints REST :

   ```bash
   wp rest get backup-jlg/v1/network/sites
   wp rest get backup-jlg/v1/network/history
   ```

   Les réponses doivent inclure `totals`, `sites` et les champs `blog_id`.

3. Ouvrir `/wp-admin/network/admin.php?page=backup-jlg-network` et vérifier que
   le tableau de bord consolidé s’affiche (tableau, filtres, statut des sites).

## 5. Validation

- Créer une clé API sur deux sites différents et déclencher une sauvegarde.
- Vérifier que l’historique réseau affiche les deux entrées avec leurs statuts.
- Confirmer qu’un appel `wp rest get backup-jlg/v1/network/history --query-args="blog_id=<ID>"`
  ne retourne que les actions du site ciblé.

## 6. Retour arrière

En cas de problème :

1. Restaurer la sauvegarde SQL.
2. Revenir à la version précédente du plugin.
3. Purger le cache objet éventuel (`wp cache flush`).

> **Important** : toute table recréée avec `dbDelta` peut être restaurée depuis
> le dump SQL si nécessaire.

La migration est terminée une fois que toutes les commandes ci-dessus se sont
exécutées sans erreur et que le tableau de bord réseau affiche les statistiques
consolidées.
