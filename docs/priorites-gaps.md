# Écarts restants vs roadmap et concurrence

Ce document inventorie les chantiers encore manquants pour atteindre les objectifs définis dans la roadmap produit et pour se hisser au niveau des suites professionnelles évaluées dans [`docs/comparaison-pro.md`](comparaison-pro.md).

## Axes stratégiques

| Axe | Manques identifiés | Références |
| --- | --- | --- |
| Notifications multi-canales | Les rapports de résolution et la diffusion automatique des plans d’action manquent encore pour compléter les accusés de réception et rappels désormais livrés.【F:docs/roadmap-suivi.md†L11-L59】【F:docs/comparaison-pro.md†L70-L108】 | docs/roadmap-suivi.md, docs/comparaison-pro.md |
| Purge distante automatisée | Les SLA ne sont pas encore exposés (temps moyen, projections de saturation) ni reliés aux quotas distants malgré le worker robuste et le panneau avancé.【F:docs/roadmap-suivi.md†L61-L84】【F:docs/comparaison-pro.md†L109-L134】 | docs/roadmap-suivi.md, docs/comparaison-pro.md |
| Planification avancée | Pas d’assistance UI sur les expressions Cron (exemples, prévisualisation, garde-fous) alors que le champ expert est disponible côté interface et REST.【F:docs/roadmap-suivi.md†L86-L107】【F:docs/plan-amelioration-ux-fiabilite.md†L23-L29】 | docs/roadmap-suivi.md, docs/plan-amelioration-ux-fiabilite.md |
| Supervision du stockage distant | Les projections de saturation multi-destinations, recommandations automatisées et exports SLA restent à implémenter malgré la collecte normalisée des quotas distants.【F:docs/roadmap-suivi.md†L60-L122】【F:docs/comparaison-pro.md†L150-L159】 | docs/roadmap-suivi.md, docs/comparaison-pro.md |
| Support multisite & gestion centralisée | Manque de mutualisation des tables et des appels API pour piloter plusieurs sites comme le proposent les consoles agence.【F:docs/roadmap-suivi.md†L124-L135】【F:docs/comparaison-pro.md†L116-L126】 | docs/roadmap-suivi.md, docs/comparaison-pro.md |

## Écarts complémentaires vs solutions pro

- **Observabilité SLA et rapports post-sauvegarde** : les suites pro publient des rapports détaillés et des indicateurs RTO/RPO, alors que Backup JLG dispose seulement de l’audit SQL et des notifications actuelles.【F:docs/comparaison-pro.md†L36-L58】【F:docs/plan-amelioration-ux-fiabilite.md†L25-L29】
- **Automatisation des tests de restauration** : aucun run périodique n’est orchestré pour vérifier les archives chiffrées, contrairement aux offres managées qui valident automatiquement la récupérabilité.【F:docs/comparaison-pro.md†L46-L50】【F:docs/plan-amelioration-ux-fiabilite.md†L25-L28】
- **Modernisation de l’UI WordPress** : la navigation reste mono-page avec composants maison sans adoption de `@wordpress/components`, là où les concurrents proposent des consoles modulaires et accessibles.【F:docs/comparaison-pro.md†L62-L90】【F:docs/plan-amelioration-ux-fiabilite.md†L31-L36】
- **Support quasi temps réel** : la planification repose sur WP-Cron et ne propose pas de déclencheurs événementiels pour réduire le RPO comme le proposent BlogVault ou Jetpack Backup.【F:docs/comparaison-pro.md†L46-L58】

Ces éléments servent de checklist pour prioriser les prochains sprints et valider l’alignement fonctionnel avant toute communication produit majeure.
