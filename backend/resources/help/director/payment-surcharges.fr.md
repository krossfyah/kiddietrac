---
title: Frais de service sur les paiements (cartes et EFT)
category: Billing
order: 14
roles: agency_admin, centre_director, platform_admin
---
# Frais de service sur les paiements

**Paramètres → Facturation → Valeurs par défaut et frais → Frais de service sur les paiements.** Vous pouvez répercuter sur les familles le coût de traitement des paiements par carte et par virement bancaire sous forme de frais de service visibles.

[[show: #billing-settings @ Defaults & fees > Payment surcharges | Afficher les paramètres des frais de service]]

## Les deux taux

- **Carte de crédit / débit (%)** : utilisé lorsqu’un paiement est consigné comme **Carte (prise hors ligne)**.
- **EFT / Virement Interac (%)** : utilisé lorsqu’un paiement est consigné comme **Virement Interac / EFT** ou **Virement bancaire**.

Il y a deux taux parce que les deux modes de paiement vous coûtent des montants très différents. Chaque taux peut se situer entre 0 et 10 %. Les deux commencent à **0**, ce qui signifie que vous assumez le coût et que rien n’est ajouté.

**Les paiements en argent comptant et par chèque ne comportent jamais de frais**, pas plus que **Autre**. Sans processeur à payer, des frais sur de l’argent comptant seraient des frais pour rien.

## Quand les frais sont ajoutés

Des frais de service ne sont **jamais ajoutés automatiquement**. Ils ne le sont que lorsque quelqu’un consigne un paiement et coche la case correspondante :

1. Dans **Comptabilité**, ouvrez **Enregistrer le paiement** de la facture.
2. Saisissez le **Montant à régler** et choisissez **Mode de paiement**.
3. Si ce mode a un taux, une case apparaît avec les frais en dollars et le total à percevoir. Cochez-la pour ajouter les frais.
4. Cliquez sur **Enregistrer le paiement**.

Si le mode n’a pas de taux, ou si le taux est 0, la case n’apparaît pas du tout.

> **Important :** Aucuns frais de service ne sont ajoutés aux paiements que les familles font en ligne par le portail. Les frais ne s’appliquent qu’aux paiements consignés par votre personnel.

## Comment les frais sont calculés

Les frais sont un **pourcentage du paiement, et non de la facture**. Une famille qui paie 500 $ maintenant et 500 $ le mois prochain paie les frais sur chaque tranche de 500 $. C’est aussi ainsi que le processeur vous facture.

Le montant que vous saisissez est celui qui est réglé, et les frais s’y ajoutent. Par exemple, à 2,9 %, régler un solde de 500,00 $ ajoute 14,50 $ de frais. Le paiement est consigné à 514,50 $, et la facture est ramenée à zéro.

> **Astuce :** Saisissez le solde que vous réglez, et non le total frais compris. La boîte de dialogue ajoute les frais pour vous.

## Ce que la famille voit

Sur une facture KiddieTrac, les frais sont ajoutés sous forme de ligne distincte, par exemple **Frais de service (2,9 % carte)** ou **Frais de service (0,5 % virement Interac / EFT)**. Leur reçu énumère les frais de service séparément.

Sur une facture synchronisée depuis un système de facturation externe, il n’y a pas de ligne KiddieTrac où les ajouter. Les frais sont plutôt ajoutés au total de la facture. Ce système de facturation reste le système de référence; sa prochaine synchronisation peut donc réinitialiser le total.

## Questions courantes

??? Pourquoi la case des frais est-elle absente de la boîte de dialogue Enregistrer le paiement? | Le mode choisi n’a pas de taux de frais de service (l’argent comptant, le chèque et Autre n’en ont jamais), ou le taux de ce mode est 0.
??? Les frais sont-ils appliqués à un paiement partiel? | Oui. Ils sont calculés sur le montant que vous saisissez comme réglé; chaque paiement partiel comporte donc ses propres frais.
??? Modifier un taux change-t-il les frais déjà facturés? | Non. Les frais sont calculés au moment où le paiement est consigné et sont alors enregistrés sur la facture.

Voir aussi : [Configuration et paramètres de facturation](billing-setup) · [Comptabilité](accounting) · [Prestataires de paiement](payment-providers)
