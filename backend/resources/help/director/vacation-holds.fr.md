---
title: Crédits de places réservées (vacances)
category: Billing
order: 84
---
# Places réservées (vacances)

Suspendez les frais de garde pendant qu’une famille est en vacances. Le parent fait la demande, le directeur approuve en indiquant un montant de crédit.

## Parent : faire une demande

1. Barre latérale → **Places réservées (vacances)**.
2. Touchez **+ Demander une réservation**.
3. Choisissez la date de début et la date de fin, et ajoutez un motif.
4. Soumettez. Le directeur du centre reçoit une notification.

## Directeur / administrateur : approuver

1. Barre latérale → **Places réservées (vacances)**.
2. Les demandes en attente sont mises en évidence.
3. Touchez **Approuver** ou **Refuser**.
4. Pour une approbation, saisissez un montant de crédit (ou laissez vide pour le calcul automatique : `$30/day × days requested`).
5. Le crédit est enregistré pour être appliqué à la prochaine facture.

## Comment le crédit apparaît

- Une ligne `vacation_holds` consigne le montant approuvé.
- À la prochaine génération de facture, le crédit s’applique comme une ligne négative.
- La famille voit le crédit immédiatement dans son relevé.

## Limites

Aucune limite stricte, mais les politiques habituelles des centres plafonnent les places réservées à 2 semaines par an. Appliquez cette limite par la politique, pas par le système.
