---
title: Rembourser un paiement
category: Billing
order: 78
roles: agency_admin, centre_director, platform_admin
---
# Rembourser un paiement

**Finances → Remboursements** rend de l’argent à une famille, en totalité ou en partie, sur un paiement qu’elle a fait. Un remboursement est le seul mouvement d’argent que personne de l’autre côté ne confirme; chaque remboursement est donc signé par la personne qui l’approuve et consigné avec la date, l’heure et sa provenance.

[[open: #refunds | Ouvrir Remboursements]]

![L’écran Remboursements : choisissez le paiement ou entrez le numéro de facture](https://api.kiddietrac.com/help-img/refunds.png)

## Trouver le paiement à rembourser

Vous pouvez trouver l’argent de deux façons : dans la liste, ou par numéro de facture.

1. Ouvrez **Finances → Remboursements**.
2. Ouvrez la liste **Choisissez le paiement à rembourser**. Elle montre tout ce qui, dans l’agence, a encore de l’argent remboursable : la famille, le montant remboursable, le numéro de facture ou de paiement, la date, et ce qui a déjà été remboursé.
3. Choisissez le paiement. Son détail s’ouvre en dessous.

Ou, quand vous partez d’un numéro plutôt que d’un nom :

1. Tapez le numéro dans **Or enter the invoice number to refund:**, soit le numéro imprimé sur la facture envoyée à la famille. Une partie du numéro suffit.
2. Appuyez sur **Trouver une facture** (ou sur Entrée).
3. Si une seule facture correspond, elle s’ouvre tout de suite. Si plusieurs correspondent, chacune est listée avec le nom de la famille, le montant facturé et le montant reçu; appuyez sur **Remboursement** à côté de la bonne.

> ⚠️ Un même numéro peut appartenir à plusieurs factures (par exemple, des numéros formés à partir d’une date peuvent être partagés par de nombreuses familles). Toutes les correspondances sont listées et c’est vous qui choisissez. Rien n’est choisi à votre place.

Les administrateurs d’agence peuvent aussi partir du compte d’une famille : ouvrez-le dans **Finances → Grands livres des comptes** et appuyez sur **↩ Refund**. Vous ne voyez alors que les paiements de cette famille, ce qui est plus sûr quand vous savez déjà à qui appartient l’argent.

## Émettre le remboursement

En haut, trois montants s’affichent : **Montant du paiement**, **Already refunded** et **Remboursable**. En dessous, **Historique des remboursements** liste les remboursements antérieurs sur ce paiement, avec leur statut.

1. Sous **Émettre un nouveau remboursement**, tapez le **Montant**. Tout montant jusqu’au montant remboursable fonctionne; un remboursement partiel est donc simplement un montant plus petit.
2. Choisissez un **Motif** : **Demandé par le client**, **Débit en double**, **Paiement en trop**, **Geste commercial** ou **Crédit de vacances**.
3. Ajoutez des **Notes** au besoin (la famille voit le motif et les notes dans son avis).
4. Tapez votre nom complet dans **Votre nom**.
5. Signez dans la zone **Signature** (« Signez ici pour approuver »). Appuyez sur **Effacer la signature** pour recommencer.
6. Appuyez sur **Émettre le remboursement**.

Une boîte verte confirme le remboursement et son statut. Les montants se mettent à jour quelques secondes plus tard.

L’argent déjà remboursé est déduit : un paiement de 1 680 $ dont 420 $ ont été rendus affiche **1 260 $** remboursables. Rembourser plus que ce qui reste est refusé, avec le montant exact restant.

## Ce qui arrive à l’argent

Cela dépend de la façon dont la famille a payé.

- **Carte payée par Helcim ou Stripe** : l’argent retourne automatiquement sur la carte. Avec Helcim, un remboursement complet le jour même est d’abord tenté comme **annulation**, pour qu’il disparaisse du relevé de carte au lieu d’y figurer comme un débit et un crédit. La famille est avisée « Refund sent » et que l’argent devrait arriver dans son compte d’ici quelques jours.
- **Tout autre paiement** (virement Interac, comptant, chèque, banque) : le remboursement est consigné et le solde est rouvert au compte, mais aucun argent ne bouge de lui-même. La famille est avisée que son service de garde s’occupera du paiement. Une boîte indique **Aucune somme n’a encore été envoyée**; si l’envoi Interac est configuré, appuyez sur **Send $… by Interac now**, ou sur **Je m’en occupe moi-même**.

Pour une facture venue d’un système de facturation externe, le solde est rouvert au grand livre de la famille plutôt que réécrit sur la facture, parce que ce système écrase ses factures à chaque synchronisation.

## Rembourser de l’argent sans dossier de paiement

L’argent reçu par une intégration peut figurer sur une facture sans reçu dans KiddieTrac. Une note bleue l’indique. Vous pouvez quand même le rembourser : l’écran affiche **Reçu**, **Remboursable** et la **Facture**, et le reçu est créé au moment où vous approuvez, pour que le remboursement ait quelque chose à renverser. Remplissez les mêmes champs et appuyez sur **Émettre le remboursement**; le bouton affiche ensuite **Remboursement consigné**.

## Rembourser d’abord, puis annuler

Une facture sur laquelle un paiement a été fait ne peut pas être annulée. Pour en annuler une, remboursez d’abord l’argent ici, puis annulez la facture dans **Finances → Comptabilité**.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d’agence | Rembourser tout paiement de l’agence, depuis cet écran ou depuis les grands livres des comptes. |
| Directeur de service de garde | Rembourser les paiements des familles qu’il voit, depuis cet écran. |
| Administrateur de plateforme | La même chose qu’un administrateur d’agence, pour l’agence qu’il consulte. |
| Éducateur | Ne peut pas ouvrir Remboursements. Le serveur répond « Only an agency admin or a centre director can approve a refund. » |

## FAQ et quand quelque chose ne va pas

??? La liste indique « Aucun paiement de cette agence ne comporte de fonds remboursables ». | Aucun paiement n’a d’argent restant à rembourser : rien n’a encore été payé, ou tout a été remboursé. Vérifiez le numéro de facture avec Trouver une facture pour en être sûr.
??? « No invoice found with the number … » | Vérifiez le numéro sur la facture reçue par la famille, ou choisissez la famille dans la liste au-dessus.
??? « Cannot refund $…; only $… of this payment is left to refund. » | Une partie du paiement a déjà été remboursée. Entrez le montant indiqué ou moins.
??? « Type your full name to approve this refund. » ou « Please sign in the box to approve this refund. » | Votre nom et votre signature sont tous deux requis. Remplissez celui qui manque et appuyez de nouveau sur Émettre le remboursement.
??? « This payment was taken through Helcim (or Stripe), but … is no longer set up for this agency » | L’argent ne peut pas retourner automatiquement sur la carte; rien n’a donc été consigné. Un administrateur d’agence peut reconnecter le prestataire dans Paramètres → Prestataires de paiement, ou rembourser autrement.
??? Le prestataire de cartes a refusé le remboursement. | Sa raison s’affiche en rouge et rien n’est consigné. Lisez la raison (par exemple, la carte a été fermée) et réessayez, ou réglez le paiement autrement.
??? La famille est-elle avisée? | Oui. Chaque tuteur reçoit un avis dans l’application et un courriel : « Refund sent » quand l’argent est retourné sur la carte, « Refund approved » quand le service de garde s’occupera du paiement.
??? Où voir les remboursements plus tard? | Choisissez de nouveau le même paiement : Historique des remboursements liste chaque remboursement avec sa date, son montant, son motif et son statut. Les administrateurs d’agence le voient aussi au compte de la famille dans les grands livres des comptes.

## Écrans connexes

[[open: #external-billing | Comptabilité]] [[open: #billing-schedule | Calendrier de facturation]]

Voir aussi : [Comptabilité](accounting) · [Grands livres des comptes](account-ledgers) · [Prestataires de paiement](payment-providers)
