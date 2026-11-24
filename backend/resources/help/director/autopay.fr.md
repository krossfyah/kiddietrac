---
title: Prélèvement automatique (carte enregistrée)
category: Billing
order: 76
---
# Prélèvement automatique

Enregistrez une carte de crédit une seule fois et nous la débiterons automatiquement pour chaque facture à payer. Annulez à tout moment.

## Configuration (parent)

1. Menu latéral → **Prélèvement automatique**.
2. Cliquez sur **+ Ajouter une carte**.
3. Saisissez les renseignements de votre carte dans le formulaire Stripe : numéro, date d’expiration, CVC, code postal.
4. Cliquez sur **Enregistrer la carte**. Le prélèvement automatique s’active automatiquement.

Les renseignements de votre carte ne transitent jamais par nos serveurs : ils vont directement à Stripe par le formulaire sécurisé.

## Fonctionnement des prélèvements

Une tâche planifiée quotidienne à 03 h 00 (heure de l’Est) repère toutes les factures impayées des familles dont le prélèvement automatique est activé et débite la carte enregistrée. Un prélèvement réussi ajoute une ligne de paiement, réduit le solde de la facture et fait passer son statut à payée lorsque le solde atteint zéro.

Si un prélèvement échoue (carte expirée, fonds insuffisants, etc.), vous recevez immédiatement une notification `payment_failed` dans votre boîte de réception. Mettez la carte à jour et nous réessaierons à la prochaine exécution.

## Le désactiver

Menu latéral → **Prélèvement automatique** → **Désactiver le prélèvement automatique**. Votre carte enregistrée reste au dossier (vous pourrez donc le réactiver plus tard), mais aucun prélèvement automatique n’aura lieu.

## Prélèvement manuel par votre prestataire

Votre directeur de centre peut aussi appuyer sur **Débiter la carte enregistrée** sur n’importe quelle facture — utile si vous voulez payer plus tôt ou régler un solde immédiatement.
