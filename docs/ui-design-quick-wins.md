# Améliorations UI sans Tailwind ni Radix

Cette note recense des pistes pragmatiques pour moderniser l'interface d'administration de Backup JLG tout en restant dans l'écosystème WordPress natif.

## 1. Capitaliser sur les composants WordPress

- Tirer parti des composants React fournis par `@wordpress/components` (Button, Card, Notice, TabPanel) pour remplacer progressivement les variantes `.bjlg-*`. Les styles héritent automatiquement du thème admin et du mode sombre.
- Utiliser `@wordpress/data` pour synchroniser l'état des vues (onglets, notifications) et éviter les mises à jour DOM manuelles via jQuery.

## 2. Introduire des tokens CSS

- Définir dans `admin.css` une couche de variables personnalisées (`--bjlg-surface`, `--bjlg-border`, `--bjlg-accent`) initialisées depuis `--wp-admin-theme-color` et `--wp-admin-theme-color-darker`.
- Remplacer les couleurs codées en dur (`#f6f7f7`, `#dcdcde`, `#2271b1`, etc.) par ces tokens afin d'assurer le contraste et la compatibilité future avec un mode sombre.

## 3. Optimiser la navigation existante

- Convertir la liste d'onglets en `TabPanel` responsive : sur desktop, conserver les onglets horizontaux ; sous 960 px, afficher un `SelectControl` ou `DropdownMenu` pour éviter le débordement horizontal.
- Segmenter la page unique en sous-vues (`monitoring`, `restauration`, `journal`, `paramètres`) pour alléger le premier rendu et clarifier le parcours utilisateur.

## 4. Améliorer la hiérarchie visuelle

- Uniformiser les cartes (`.bjlg-action-card`, `.bjlg-card`) avec une grille responsive à deux largeurs (≥1200 px et ≤782 px) et des espacements (`spacing` scale) homogènes.
- Ajouter des styles d'états (survol, focus visible, désactivation) alignés sur les classes WordPress (`.is-primary`, `.is-destructive`) pour les boutons personnalisés.

## 5. Renforcer l'accessibilité

- Annoncer les mises à jour AJAX via `wp.a11y.speak()` et ajouter des conteneurs `role="status"` pour les alertes rafraîchies dynamiquement.
- Respecter les tailles de police relatives (`rem`) et vérifier le contraste AA des notifications (`.bjlg-alert--warning`, `.bjlg-alert--error`).

## 6. Soin du responsive

- Introduire des breakpoints supplémentaires (1024 px, 600 px) pour réorganiser les sections `grid` et éviter les scrolls horizontaux.
- Transformer les barres d'action horizontales en panneaux latéraux repliables (`.bjlg-filters`) sur mobile.

Ces évolutions restent compatibles avec la base actuelle (PHP + jQuery) et peuvent être déployées progressivement, tout en ouvrant la porte à une migration douce vers les composants WordPress modernes.
