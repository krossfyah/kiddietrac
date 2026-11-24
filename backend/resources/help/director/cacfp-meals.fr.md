---
roles: agency_admin, platform_admin
title: Suivi des repas CACFP
category: Compliance
order: 70
---
# Suivi des repas CACFP

Suivez les repas servis par enfant et par jour pour les demandes de remboursement provinciales ou étatiques, dans le cadre de l’équivalent **canadien** du Child and Adult Care Food Program.

## Liste quotidienne (éducateur)

1. Barre latérale → **Repas CACFP**.
2. Consultez la liste du jour : chaque enfant inscrit + 5 colonnes de types de repas (déjeuner / collation du matin / dîner / collation de l’après-midi / souper).
3. Touchez chaque case pour consigner un repas comme servi.
4. Les modifications sont enregistrées instantanément.

## Palier d’admissibilité de la famille

Chaque famille a un `cacfp_tier` de `free` / `reduced` / `paid` (défini par l’administrateur selon le revenu de la famille). Le système associe ce palier à chaque repas afin que le rapport mensuel ventile le nombre de repas par palier : ce sont les données que votre organisme provincial ou étatique demande pour le remboursement.

## Définir le palier (administrateur)

Modifiez la fiche d’une famille → définissez `cacfp_tier` + `cacfp_eligibility_date`. Les attestations d’admissibilité doivent être refaites chaque année.

## Rapport mensuel

Barre latérale → Repas CACFP → le haut de la page affiche :
- Repas gratuits servis ce mois-ci : N
- Repas à tarif réduit servis ce mois-ci : N
- Repas à plein tarif servis ce mois-ci : N
- Total général : N

Exportez en PDF ou en XLSX pour le déposer auprès de votre bureau provincial ou étatique du CACFP.

## Bonnes pratiques

- Consignez les repas au moment du service (et non en fin de journée) : c’est plus exact.
- Recertifiez le palier chaque année avec chaque famille.
- Comparez chaque mois les comptes de la cuisine avec ceux de la liste.
