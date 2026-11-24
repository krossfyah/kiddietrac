---
title: Générer les factures mensuelles
category: Billing
order: 1
---

# Générer les factures mensuelles

Au début de chaque mois (ou quand vous le préférez), générez les factures de toutes les familles inscrites en un seul clic.

## Comment fonctionne la génération des factures

Lorsque vous cliquez sur **Générer les factures**, Kiddietrac :

1. Trouve toutes les inscriptions actives de votre centre
2. Les regroupe par famille
3. Crée, pour chaque famille, une facture avec une ligne par enfant
4. Applique la subvention active de l’enfant, si elle est enregistrée (par exemple une subvention provinciale pour frais de garde — voir [Subventions](subsidies)), pour réduire le montant dû par la famille
5. Met la facture au statut **Envoyé** et l’envoie par courriel à la famille (si vous avez configuré la messagerie)

La famille voit la facture dans son application parent sous **Facturation**.

## Marche à suivre

1. Allez à **Familles → Factures** (ou cliquez sur « Créances » sur votre tableau de bord)
2. Cliquez sur **Générer les factures pour [mois]**
3. Confirmez — Kiddietrac indique combien de factures seront créées et le montant total
4. Cliquez sur **Générer**

## Que se passe-t-il si j’ai déjà généré les factures de ce mois-ci?

Le système ne crée pas de doublons. Si vous l’avez déjà lancé ce mois-ci, il ignore les familles qui ont déjà une facture pour ce mois et ne génère que pour les enfants nouvellement inscrits.

## Comment les factures sont calculées

Pour chaque enfant inscrit :

- **Sous-total** = frais mensuels de l’inscription
- **Subvention** = le montant mensuel de la subvention active de l’enfant à la date de la facture, tiré de **Subventions**
- **Net** = sous-total − subvention

Si une famille a plusieurs enfants, les montants de tous les enfants sont additionnés en une seule facture.

## Enregistrer les paiements

Lorsqu’une famille paie (virement Interac, chèque, argent comptant, etc.), enregistrez-le :

1. Cliquez sur le numéro de la facture
2. Cliquez sur **Enregistrer un paiement**
3. Saisissez le montant, le mode de paiement, la date de paiement et toute référence
4. Enregistrez

Le solde de la facture se met à jour automatiquement. Si la famille paie le montant complet, le statut de la facture passe à **Payé**.

Vous pouvez aussi enregistrer un paiement depuis **Finances → Comptabilité**, qui propose d’ajouter vos frais de service de carte ou de virement si vous en facturez. Voir [Comptabilité](accounting) et [Frais supplémentaires de paiement](payment-surcharges).

## Et les cartes de crédit?

Les familles peuvent payer en ligne dès que votre agence a connecté un prestataire de paiement sous **Paramètres → Prestataires de paiement**. D’ici là, vous recevez les paiements en dehors de l’application (virement, chèque, etc.) et vous les enregistrez ici pour que le compte de la famille soit à jour. Voir [Prestataires de paiement](payment-providers).

## Numéros de facture et présentation

Les numéros de facture suivent le format propre à votre agence, et les factures sont présentées dans le style choisi sous **Image de marque**. Voir [Style et numérotation des factures](invoice-numbering-and-style).

## Astuces

- **Générez les factures le 1er de chaque mois**. Réglez un rappel dans votre calendrier.
- **Envoyez un courriel de rappel** aux familles ayant des factures en retard vers le 15
- **Suivez les modes de paiement**. Dans la plupart des centres de l’Ontario, le virement Interac est le mode le plus courant

## Modifier ou annuler une facture

Vous ne pouvez pas supprimer une facture une fois générée (cela préserve vos dossiers financiers). Au besoin :

- **Corriger une facture** : utilisez **Modifier** sur la facture dans **Finances → Comptabilité**.
- **Annuler une facture** : utilisez **Annuler la facture** dans **Comptabilité**. Elle conserve son numéro, n’est plus due, et la famille en est avisée par courriel. Voir [Comptabilité](accounting).
- **Rembourser une famille** : utilisez **Finances → Remboursements**, qui enregistre qui l’a approuvé. Remboursez tout paiement reçu avant d’annuler la facture. Voir [Rembourser un paiement](refunds).
