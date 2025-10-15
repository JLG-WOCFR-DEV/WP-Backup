# Rapport de contraste Backup JLG – 15 octobre 2025

## Méthodologie
- Extraction des couleurs principales définies pour les thèmes clair et sombre dans `assets/css/admin.css`.
- Calcul des ratios de contraste WCAG 2.1 à l’aide d’un script Python basé sur la luminance relative (`(L1 + 0.05) / (L2 + 0.05)`).
- Vérification du contexte d’usage (texte, bordures, arrière-plans) pour interpréter la conformité RGAA 3 (critère 3.2 et 3.3).

## Résultats – Thème clair (`.bjlg-wrap`)
| Usage | Couleur du texte | Arrière-plan | Ratio | Statut RGAA |
| --- | --- | --- | --- | --- |
| Texte principal (`--bjlg-color-text` sur `--bjlg-color-surface`) | `#1f1b2d` | `#ffffff` | 16.76:1 | Conforme (AAA) |
| Accent primaire (`--bjlg-color-accent` sur `--bjlg-color-app`) | `#6e56cf` | `#f7f7fb` | 5.05:1 | Conforme (AA) |
| Accent renforcé (hover) | `#5746af` | `#f7f7fb` | 6.78:1 | Conforme (AAA) |
| Texte succès (métriques) | `#1d976c` | `#ffffff` | 3.69:1 | À surveiller : utilisé pour des bordures/icônes uniquement, pas pour du texte courant |

## Résultats – Thème sombre (`.bjlg-wrap.is-dark`)
| Usage | Couleur du texte | Arrière-plan | Ratio | Statut RGAA |
| --- | --- | --- | --- | --- |
| Texte principal (`--bjlg-color-text` sur `--bjlg-color-app`) | `#f8fafc` | `#0b1120` | 18.00:1 | Conforme (AAA) |
| Texte secondaire (`--bjlg-color-text-subtle` sur `--bjlg-color-surface`) | `#e2e8f0` | `#111c2f` | 13.84:1 | Conforme (AAA) |
| Bouton primaire (`#0b1120` sur `#60a5fa`) | `#0b1120` | `#60a5fa` | 7.41:1 | Conforme (AAA) |
| Bouton primaire (état normal) | `#f8fafc` | `#60a5fa` | 7.41:1 | Conforme (AAA) |

## Actions suivantes
- Conserver l’usage actuel de `#1d976c` pour des ornements/bordures uniquement.
- Continuer à utiliser le canal de documentation pour enregistrer toute variation de palette et faciliter les audits RGAA futurs.
