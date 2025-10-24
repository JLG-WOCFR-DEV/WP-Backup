# Migration de la console d’administration

La console principale de Backup JLG est désormais segmentée en cinq écrans dédiés (Monitoring, Sauvegarde, Restauration, Réglages et Intégrations) rendus via les composants WordPress (`@wordpress/components`). L’interface embarque un menu latéral responsive, des cartes normalisées (`Card`) et un suivi vocal accessible (`role="status"` + `wp.a11y.speak`).

## Activation progressive

Pour limiter les régressions, l’interface moderne est livrée derrière le flag `bjlg_enable_modern_admin_shell`. Elle est activée par défaut mais peut être désactivée au besoin :

```php
add_filter('bjlg_enable_modern_admin_shell', '__return_false');
```

Une option persistance (`bjlg_enable_modern_admin`) est également lue si vous devez piloter l’activation dans un environnement multi-sites.

## Rappels pour les contributeurs

- Utiliser les tokens de design WordPress (couleurs, ombres, espacements) pour toute évolution du back-office.
- Préférer les composants standards (`Button`, `Card`, `Notice`, `TabPanel`, etc.) afin de conserver la cohérence visuelle.
- Conserver les retours accessibles (`role="status"`, `wp.a11y.speak`) lors de l’ajout d’actions ou de notifications.
