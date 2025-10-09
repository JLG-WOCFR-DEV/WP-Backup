# Audit UX / UI / Performance / Accessibilité / Fiabilité

Ce document résume les principaux constats relevés lors de l'analyse du plugin « Backup - JLG ».

## Points saillants

- L'assistant de création de sauvegarde propose de nombreux réglages techniques (modèles de motifs d'inclusion/exclusion, destinations secondaires, tests post-sauvegarde) directement dans l'écran principal, sans contextualisation progressive ni aide interactive, ce qui peut dérouter des profils non experts.
- L'interface admin s'appuie sur un unique bundle JavaScript de plus de 5 000 lignes chargé systématiquement avec Chart.js, même quand certains onglets n'en ont pas l'usage, ce qui impacte le temps d'interaction initial.
- Les contrastes colorimétriques et les états focus reposent sur des teintes pastels pouvant descendre sous les seuils WCAG dans certaines sections (badges, alertes), et plusieurs composants dynamiques n'offrent pas de solutions de repli sans JavaScript.

## Pistes d'amélioration

- Regrouper les réglages avancés (motifs personnalisés, destinations secondaires) dans des panneaux accordéon ou un second écran dédié, et proposer des aides contextuelles (exemples dynamiques, autocomplétion, validation en direct).
- Segmenter le JavaScript par onglet (code-splitting) et ne charger Chart.js qu'en présence d'un canvas de statistiques, afin de réduire le coût initial sur les écrans les plus consultés.
- Ajouter des contrôles de contraste (classes `is-dark`/`is-light` dynamiques) et prévoir des fallback server-side ou `admin-post.php` pour les actions critiques comme la création/restauration de sauvegardes afin d'assurer la continuité de service même si le bundle échoue.
