---
title: Rembourser un paiement
category: Billing
order: 78
roles: agency_admin, centre_director, platform_admin
---
# Rembourser un paiement

Un remboursement est le seul mouvement d’argent que personne, de l’autre côté, ne confirme. Une facture est convenue avec la famille; un paiement arrive parce que quelqu’un a choisi de l’envoyer. Un remboursement, lui, est autorisé par une seule personne au sein de l’agence, puis il a simplement lieu. C’est pourquoi il est signé, et chacun est consigné.

![L’écran Remboursements : choisissez le paiement ou saisissez le numéro de facture](https://api.kiddietrac.com/help-img/refunds.png)

## Qui peut le faire

**Uniquement les administrateurs d’agence et les directeurs de centre.** Un éducateur qui ouvre l’écran est refusé, avec un message qui en explique la raison.

## Deux façons d’y accéder

**Depuis le grand livre du compte** : ouvrez le grand livre de la personne et appuyez sur **↩ Remboursement**. Vous ne voyez que ses factures, ce qui est la voie la plus sûre lorsque vous savez déjà à qui appartient l’argent.

**Depuis l’écran Remboursements** : pour quand vous travaillez à partir d’un numéro plutôt que d’un nom.

- La **liste déroulante** présente tout ce qui, dans l’agence, comporte de l’argent encore remboursable, avec la famille, le montant remboursable et le numéro de facture. Choisissez la personne.
- Ou saisissez le **numéro de facture** : celui imprimé sur la facture envoyée à la famille, par exemple `iL-INV-1779383993378`. Les numéros partiels fonctionnent.

> **Un même numéro peut appartenir à plusieurs factures.** `PINVO-05082026` est partagé par onze familles différentes. Lorsque cela se produit, toutes les correspondances sont listées avec le nom de la famille, et c’est vous qui choisissez. Rien n’est choisi à votre place.

## Partiel ou intégral

Le montant correspond par défaut à tout ce qui est encore remboursable, et le plafond est indiqué à l’écran. Saisissez un montant inférieur pour un remboursement partiel. Rembourser plus que ce qui reste est refusé, et le message vous indique exactement ce qu’il reste :

> Impossible de rembourser 1 512,00 $; il ne reste que 567,00 $ à rembourser sur ce paiement.

Les sommes déjà remboursées sont déduites. Une facture qui a reçu 1 680 $ et dont 420 $ ont été retournés affiche **1 260 $ remboursables**.

## L’approuver

Choisissez un motif, ajoutez une note au besoin, puis **saisissez votre nom et signez dans la case**. Les deux sont obligatoires : la signature est conservée avec le remboursement, de même que la date, l’heure et l’adresse d’où elle provient.

Cette trace fait passer de *« quelqu’un a remboursé 735 $ »* à *« cette personne nommée a approuvé ce remboursement à cette minute précise et y a apposé son nom »*.

## Et ensuite

Cela dépend de la façon dont l’argent a été payé.

**Un paiement par carte effectué par l’entremise de Helcim retourne automatiquement sur la carte.** Vous n’avez aucune autre étape à faire :

- **Le jour même, montant intégral** : KiddieTrac tente d’abord d’**annuler** la transaction, afin qu’elle disparaisse du relevé de carte de la famille au lieu d’y apparaître comme un débit suivi d’un crédit.
- **Sinon**, ou si l’annulation est refusée : le montant est **remboursé** par Helcim. Seul un remboursement peut être partiel.

Si Helcim refuse, son motif est affiché et rien n’est enregistré. Si Helcim n’est plus configuré pour votre agence, le remboursement est refusé avec un message expliquant que l’argent ne peut pas être retourné automatiquement. Voir [Fournisseurs de paiement](payment-providers).

**Pour tout autre paiement, l’argent ne bouge pas de lui-même.** Le remboursement est consigné, le solde se rouvre sur le compte, et la famille est avisée que son centre prendra des dispositions pour le paiement. Là où Interac est offert, le virement vous est proposé comme une étape distincte et délibérée.

Pour une facture KiddieTrac, le solde est réécrit sur la facture elle-même. Pour une facture provenant d’un système de facturation externe, ce n’est pas le cas : ce système écrase ces champs à chaque synchronisation, de sorte qu’un remboursement inscrit là disparaîtrait discrètement à la prochaine interrogation. Le grand livre rouvre plutôt le solde, que rien n’écrase.

## Argent sans enregistrement de paiement

Lorsqu’un paiement est arrivé par une intégration, KiddieTrac peut détenir la facture sans reçu. Ces factures sont tout de même remboursables : le reçu est écrit à l’instant où le remboursement est approuvé, de sorte que le remboursement ait quelque chose à renverser. Vous n’avez rien à faire : cela fait partie de l’approbation.

## Rembourser d’abord, annuler ensuite

Une facture sur laquelle de l’argent a été payé ne peut pas être annulée. Pour en annuler une, remboursez d’abord l’argent ici, puis annulez la facture dans **Comptabilité**. Voir [Comptabilité](accounting).

Voir aussi : [Comptabilité](accounting) · [Grands livres de compte](account-ledgers) · [Fournisseurs de paiement](payment-providers)
