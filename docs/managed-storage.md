# Offre « Stockage managé »

La destination **Stockage managé** fournit un coffre-fort multi-région opérée par l’équipe JLG pour les sauvegardes critiques. Cette fiche produit rassemble les éléments contractuels, techniques et opérationnels nécessaires pour cadrer un déploiement.

## Aperçu

| Élément | Détail |
| --- | --- |
| Modèle de service | Stockage d’archives supervisé 24/7 par JLG |
| Couverture géographique | Réplication active dans 3 régions minimum (UE/NA par défaut) |
| Objectifs SLA | RTO ≤ 45 min, RPO ≤ 15 min, disponibilité ≥ 99,9 % |
| Facturation | Forfait capacité + surconsommation (voir ci-dessous) |
| Engagement initial | 12 mois renouvelables automatiquement |

## Architecture et résilience

- Données chiffrées au repos (AES-256) avec rotation trimestrielle des clés.
- Réplication synchrone entre deux régions primaires et réplication asynchrone vers un site témoin.
- Snapshots de cohérence validés toutes les 4 heures, avec tests de restauration automatisés hebdomadaires.
- Supporte la réhydratation « juste-à-temps » via le connecteur WordPress (flux `managed_storage`).

## Quotas et dimensionnement

| Palier | Capacité incluse | Pics d’IOPS recommandés | Prix HT / mois |
| --- | --- | --- | --- |
| S1 | 1 To | 1 500 | 390 € |
| S2 | 3 To | 3 500 | 890 € |
| S3 | 8 To | 7 000 | 1 990 € |

- La surconsommation est facturée 0,12 €/Go/mois proratisé.
- Les projections embarquées génèrent des alertes à 85 % (warning) et 95 % (critique) d’utilisation agrégée.
- Les quotas réseau sont recalculés toutes les 30 minutes et exposés via l’API `/network-metrics`.

## SLA et obligations

- RTO contractuel : 45 minutes maximum pour un site WordPress complet (éprouvé mensuellement).
- RPO contractuel : 15 minutes sur les bases de données, 60 minutes sur les fichiers médias.
- Penalties : crédit de 10 % par tranche de 30 minutes au-delà du RTO/RPO garanti.
- Notifications obligatoires via webhook `sla_alert` (voir section intégrations) pour intégrer vos SOC/NOC.
- Changements majeurs (migration de région, maintenance planifiée) annoncés 15 jours à l’avance.

## Processus d’onboarding

1. **Qualification** : audit de la volumétrie et des flux (formulaire disponible dans l’admin > Intégrations).
2. **Signature** : validation du contrat-cadre et commande du palier initial (S1, S2 ou S3).
3. **Provisioning** : création des coffres, génération de la clé API et configuration automatique dans le plugin.
4. **Vérifications** : exécution d’un test de réplication et d’une restauration sandbox supervisée.
5. **Go-live** : activation des alertes SLA, communication des procédures d’escalade, remise du rapport initial.

## Intégrations et alertes

- Le connecteur fournit un endpoint REST `/network-metrics` pour agréger quotas, projections et statut SLA.
- Un webhook `sla_alert` est déclenché à chaque changement d’état (info → warning → danger) avec les métadonnées suivantes : copies disponibles/attendues, RTO/RPO calculés, pourcentage d’utilisation réseau et résumé des projections.
- Compatible avec les canaux Slack/Teams/Discord via le module de notifications JLG.

## Limites et exclusions

- Les sauvegardes dépassant 2 To par archive sont automatiquement segmentées ; la restauration peut nécessiter un préchauffage manuel.
- Le service ne couvre pas les workloads hors WordPress (bases analytiques, assets volumineux non versionnés).
- Les demandes de purge légale doivent être signalées au moins 72 heures à l’avance.
- La bande passante sortante gratuite est limitée à 5 To/mois ; au-delà, 0,08 €/Go est appliqué.

## Support et escalade

- Support standard : 24/7, temps de réponse cible 30 minutes sur incident critique.
- Escalade technique via le webhook `sla_alert` ou l’API interne `bjlg_sla_alert`.
- Portail client : https://support.backup-jlg.example/ (accès réservé aux comptes managés).

## Ressources complémentaires

- Guide administrateur : `backup-jlg/assets/docs/managed-storage.html` (version HTML embarquée pour le plugin).
- Tableau de bord réseau : onglet **Monitoring** dans l’admin WordPress (`/wp-admin/admin.php?page=backup-jlg`).
- Référentiel sécurité : voir `docs/verification-sauvegardes-chiffrees.md` pour les politiques de chiffrement.

---

_Mise à jour : 2025-03-12_
