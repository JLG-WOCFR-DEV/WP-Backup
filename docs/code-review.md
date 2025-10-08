# Revue du plugin Backup JLG

## Constat global
Le plugin couvre un périmètre fonctionnel très large (sauvegarde, planification, destinations externes, chiffrement, notifications, etc.) et concentre énormément de responsabilités dans quelques classes centrales. Les tests sont fournis mais reposent sur un bootstrap volumineux difficile à maintenir. Il y a donc un vrai potentiel de refactorings structurels et de nettoyage pour réduire la complexité cyclomatique, améliorer la testabilité et clarifier la gestion des dépendances.

## Refactorings prioritaires
### 1. Découper `BJLG_Backup`
* La classe gère à la fois la synchronisation via transients, l'exposition d'actions AJAX, la logique métier d'exécution de sauvegarde et l'orchestration des destinations. Le code de verrouillage statique est mélangé avec l'instance métier, ce qui rend l'ensemble difficile à tester ou à étendre.【F:backup-jlg/includes/class-bjlg-backup.php†L204-L360】
* L'action AJAX `handle_start_backup_task()` construit les tâches, manipule directement `$_POST`, gère la réservation du verrou et programme l'événement Cron ; toutes ces étapes pourraient être éclatées dans des services dédiés (builder de tâche, gestionnaire de verrou, planificateur).【F:backup-jlg/includes/class-bjlg-backup.php†L563-L661】
* La méthode `run_backup_task()` dépasse 400 lignes et orchestre tout : lecture de l'état, préparation des fichiers, calcul des filtres, export SQL, archivage ZIP, chiffrement, contrôles post-sauvegarde, distribution aux destinations et journalisation. Cette taille rend toute évolution risquée. Découper en étapes explicites (préparation, collecte, empaquetage, post-traitements) via un pipeline ou des objets dédiés clarifierait la logique et permettrait des tests unitaires ciblés.【F:backup-jlg/includes/class-bjlg-backup.php†L719-L1059】

### 2. Externaliser la gestion du verrou
Le code de verrouillage réimplémente plusieurs backends (`wp_cache`, transients, options, mémoire) avec beaucoup de branchements `function_exists`. En extrayant un composant `TaskLockStore` injectable (transient par défaut, avec adaptation automatique au cache objet), on isolerait cette complexité et on simplifierait `BJLG_Backup`. Cela permettrait aussi d'écrire des tests ciblés sur les scénarios de concurrence sans initialiser toute la classe.【F:backup-jlg/includes/class-bjlg-backup.php†L220-L360】【F:backup-jlg/includes/class-bjlg-backup.php†L434-L472】

### 3. Centraliser l'accès aux requêtes
`handle_start_backup_task()` et `get_boolean_request_value()` consomment directement `$_POST` (sans `wp_unslash` ni validations cohérentes), puis dispersent la logique de nettoyage dans plusieurs appels statiques. Introduire un objet Request/DTO (ou au moins une méthode de parsing dédiée) permettrait d'uniformiser la désérialisation, la validation et la journalisation des erreurs utilisateurs.【F:backup-jlg/includes/class-bjlg-backup.php†L569-L588】【F:backup-jlg/includes/class-bjlg-backup.php†L670-L697】

### 4. Modulariser la planification et les réglages
* `BJLG_Scheduler` concentre l'intégralité des endpoints AJAX, la synchronisation Cron, la normalisation des destinations et même des statistiques issues de l'historique. Une séparation en sous-composants (p. ex. `ScheduleRepository`, `ScheduleNormalizer`, `ScheduleRunner`) rendrait le code plus lisible et éviterait un singleton massif de plus de 1 000 lignes.【F:backup-jlg/includes/class-bjlg-scheduler.php†L35-L200】【F:backup-jlg/includes/class-bjlg-scheduler.php†L820-L904】
* `BJLG_Settings` contient les valeurs par défaut, la fusion, la sauvegarde AJAX et toute la validation (dont `sanitize_schedule_collection`). Séparer les responsabilités (p. ex. `SettingsDefaults`, `SettingsSanitizer`, `SettingsController`) réduirait la taille du fichier (1 900+ lignes) et favoriserait la réutilisation de la logique de validation hors contexte AJAX.【F:backup-jlg/includes/class-bjlg-settings.php†L31-L159】【F:backup-jlg/includes/class-bjlg-settings.php†L1625-L1818】

## Nettoyage des dépendances
* Plusieurs destinations chargent manuellement `vendor-bjlg/autoload.php`. Centraliser le chargement Composer (dans le fichier principal du plugin ou via un bootstrap unique) éviterait les doublons et réduirait le risque d'incohérence si le chemin change.【F:backup-jlg/includes/destinations/class-bjlg-google-drive.php†L21-L40】【F:backup-jlg/includes/destinations/class-bjlg-sftp.php†L15-L34】
* Composer n'utilise qu'une `classmap` globale. Passer à un autoload PSR-4 pour les classes du namespace `BJLG` permettrait de supprimer la génération lourde de la classmap et de clarifier l'organisation des fichiers (surtout si l'on découpe les classes volumineuses).
* Vérifier la nécessité du SDK Google complet (`google/apiclient`). Si seule la partie Drive est utilisée, on pourrait envisager une dépendance plus ciblée ou un chargement conditionnel (ex. via `composer suggest`), afin d'alléger l'installation par défaut.

## Qualité et maintenance des tests
`tests/bootstrap.php` définit une grande quantité de helpers, mocks WordPress et utilitaires (plus de 1 300 lignes). En l'extrayant en plusieurs fichiers (helpers génériques, stubs WordPress, builders de données de test), on réduirait le bruit dans les tests et on faciliterait la réutilisation. Cela donnerait aussi l'occasion d'introduire une hiérarchie de namespaces côté tests.【F:backup-jlg/tests/bootstrap.php†L1-L120】

## Opportunités supplémentaires
* Documenter et isoler la logique de transformation des paramètres (patterns, destinations, post-checks) dans des value objects rendrait les échanges entre `BJLG_Settings`, `BJLG_Scheduler` et `BJLG_Backup` plus explicites.
* Introduire un orchestrateur d'événements (ex. un bus interne) permettrait de mieux suivre les différentes étapes de la sauvegarde et de brancher des comportements optionnels (journalisation, notifications) sans gonfler les classes principales.

En synthèse, le plugin est riche en fonctionnalités mais gagnerait à une séparation des responsabilités nette. Les refactorings proposés visent à isoler la complexité (verrouillage, planification, validation) pour rendre le code plus testable et plus simple à faire évoluer.
