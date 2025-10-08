# Revue de code Backup JLG

## Problèmes identifiés

### 1. Désactivation involontaire de la rotation des incrémentales
*Fichier :* `includes/class-bjlg-settings.php`

Lors de l'enregistrement des paramètres, la clé `incremental_rotation_enabled` est ramenée à `false` dès qu'elle est absente de la requête (`array_key_exists('incremental_rotation_enabled', $_POST) ? … : false`). Cela signifie qu'un client REST ou une requête AJAX partielle qui souhaite seulement modifier la rétention (`incremental_max_incrementals`, `incremental_max_age`, etc.) désactivera la rotation sans le vouloir. On perd donc un garde-fou important contre la dérive des sauvegardes incrémentales et on peut se retrouver avec une chaîne infinie d'incrémentales. Il faudrait conserver la valeur existante (ou la valeur par défaut) lorsque le champ n'est pas envoyé, au lieu de forcer `false`. 【F:backup-jlg/includes/class-bjlg-settings.php†L320-L337】

### 2. Calcul de statistiques fragiles lorsque des fichiers sont inaccessibles
*Fichier :* `includes/class-bjlg-cleanup.php`

La méthode `calculate_storage_stats()` additionne directement les valeurs retournées par `filesize()` et `filemtime()` sans vérifier leur succès. Si un fichier est supprimé entre-temps ou si PHP n'a pas les droits, ces fonctions retournent `false` : on additionne alors `false` (donc `0`), on pousse `false` dans `$dates`, et `min($dates)` ou `max($dates)` renverront `0` (soit le 1er janvier 1970). On obtient donc des statistiques incohérentes et on masque les erreurs d'accès disque. Il faudrait ignorer les entrées qui retournent `false` (et loguer l’anomalie) avant de calculer la somme et les bornes. 【F:backup-jlg/includes/class-bjlg-cleanup.php†L540-L575】

### 3. Export / import de paramètres incomplet
*Fichiers :* `includes/class-bjlg-settings.php`

Les fonctions d’export / import ne couvrent qu’une poignée d’options (`cleanup`, `whitelabel`, `encryption`, `notifications`, `performance`, `gdrive`, `webhooks`, `schedule`, `required_capability`). Des réglages importants – par exemple `bjlg_incremental_settings`, les destinations distantes (`bjlg_s3_settings`, `bjlg_dropbox_settings`, etc.) ou les préférences de sauvegarde – sont ignorés. Un administrateur qui migre ses réglages via l’export perdra donc silencieusement ces paramètres. Il faudrait soit documenter clairement le périmètre, soit inclure ces options supplémentaires dans `$option_keys` et dans la routine de sanitation associée. 【F:backup-jlg/includes/class-bjlg-settings.php†L908-L934】【F:backup-jlg/includes/class-bjlg-settings.php†L1001-L1207】

## Recommandations
- Conserver la valeur existante de `incremental_rotation_enabled` quand le champ est absent et n’écraser que les clés effectivement fournies.
- Filtrer les résultats de `filesize()` et `filemtime()` pour ne conserver que les valeurs valides, et consigner les échecs afin de diagnostiquer les problèmes d’accès disque.
- Élargir la liste des options exportées/importées (et leur sanitation) afin de couvrir les réglages incrémentaux, les destinations distantes et les préférences de sauvegarde, ou indiquer explicitement que ces réglages ne sont pas gérés.
