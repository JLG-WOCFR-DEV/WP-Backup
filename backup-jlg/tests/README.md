# Tests Backup JLG

## Nouvelles fixtures d'archives

Les tests disposent maintenant de `BJLG_Test_BackupFixtures` (`tests/Helpers/BJLG_Test_BackupFixtures.php`) pour générer des archives de sauvegarde temporaires. Cette classe permet :

- la création d'archives classiques ou chiffrées (avec ou sans mot de passe) ;
- la génération automatique du manifeste et des fichiers standards (`database.sql`, contenus `wp-content/...`) ;
- la corruption volontaire du HMAC afin de valider les voies d'erreur.

L'utilisation passe par :

```php
$archive = BJLG_Test_BackupFixtures::createBackupArchive([
    'manifest' => ['type' => 'full', 'contains' => ['plugins']],
    'files'    => ['wp-content/plugins/sample/plugin.php' => '...'],
    'encrypt'  => true,
    'password' => 'secret',
]);
```

Pour simuler une corruption d'intégrité :

```php
BJLG_Test_BackupFixtures::corruptEncryptedArchiveHmac($archive['path']);
```

Les archives créées sont stockées dans `BJLG_BACKUP_DIR` et doivent être nettoyées explicitement dans les tests lorsque nécessaire.

## Scénario bout-en-bout de restauration

`BJLG_RestoreTaskTest::test_encrypted_full_and_incremental_restore_flow_promotes_sandbox_changes` couvre le flux complet suivant :

1. restauration sandbox à partir d'une sauvegarde **complète chiffrée** ;
2. application d'une sauvegarde **incrémentale chiffrée** sur la même sandbox ;
3. promotion de la sandbox vers la production via `handle_publish_sandbox()` ;
4. vérification de la suppression des fichiers obsolètes et de la présence des nouveaux fichiers.

Le test prépare des fichiers de production factices, restaure les archives en sandbox, puis s'assure qu'après promotion les fichiers obsolètes sont supprimés et que les contenus mis à jour sont bien présents.

Ces éléments facilitent le maintien des tests lors des évolutions autour du chiffrement et des flux de restauration.
