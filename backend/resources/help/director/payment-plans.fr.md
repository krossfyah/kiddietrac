---
title: Plans de paiement
category: Billing
order: 79
roles: agency_admin, centre_director, platform_admin
---
# Plans de paiement

**Finances → Plans de paiement.** Un plan de paiement répartit un total convenu en paiements datés, et chaque paiement génère sa propre facture peu avant son échéance. Cette fonction s’appelait auparavant *plan de paiement échelonné*; seul le nom a changé.

[[open: #payment-plans | Ouvrir Plans de paiement]]

![Plans de paiement, avec la facture et la date d’échéance de chaque paiement](https://api.kiddietrac.com/help-img/payment-schedules.png)

## En créer un

[[show: #payment-plans @ + New payment schedule | Afficher le bouton Nouveau plan de paiement]]

**+ Nouveau plan de paiement** se déroule en deux étapes.

**Premièrement**, le survol. Choisissez la famille, puis saisissez le **Montant total**, la fréquence des échéances (**Tous les** mois, 2 semaines ou semaine), ainsi que les dates de **Première échéance** et de **Dernière échéance**. Remplissez **À quoi cela sert-il?**, qui est imprimé sur chaque facture comme la ligne pour laquelle le total est dû. Vous pouvez aussi ajouter des **Frais supplémentaires** et des **Notes** internes, qui ne sont jamais imprimées.

Sous **Quand chaque versement est-il dû?**, choisissez l’une de ces options :

- **Envoyer un avis** : la facture est envoyée un nombre de jours déterminé avant chaque échéance. Ce nombre part du paramètre de votre agence (**5** par défaut) et peut être modifié pour ce plan.
- **Exigible immédiatement** : chaque facture est émise à sa date d’échéance et due le jour même.

**Deuxièmement**, la révision. Chaque paiement est listé avec sa date et son montant, et **tout peut être modifié** avant l’enregistrement : changez un montant, déplacez une date, ou ajoutez ou supprimez une ligne. Rien n’est écrit tant que vous n’avez pas confirmé.

Avant d’enregistrer, choisissez **Quand ceux-ci sont-ils envoyés?** :

- **Garder comme brouillons** : chaque facture reste en attente jusqu’au début de son délai d’avis, puis est émise automatiquement. Rien n’est dû avant.
- **Émettre maintenant** : toutes les factures sont émises aujourd’hui et dues immédiatement. Les dates d’échéance ne changent pas.

## Quand chaque facture est émise

Chaque paiement génère une facture à l’enregistrement, conservée comme brouillon **En attente**. Un traitement quotidien émet chacune le matin où son délai d’avis commence. Par exemple, avec 5 jours d’avis, un versement dû le 23 est émis le 18. Une famille qui convient en juin de payer jusqu’en décembre ne reçoit pas sept factures en juin.

Tant qu’elle n’est pas émise, une facture en attente n’est pas due : elle est exclue du solde de la famille, des montants impayés du grand livre du compte et de tous les rappels de facturation. La date d’échéance n’est jamais déplacée; seul le délai d’avis change.

La valeur par défaut pour toute l’agence se règle sous **Paramètres → Facturation → Valeurs par défaut et frais → Plans de paiement**. Voir [Configuration et paramètres de facturation](billing-setup).

> **Remarque :** L’émission n’envoie aucun courriel. La facture apparaît dans l’onglet Facturation de la famille. Utilisez **Renvoyer** si vous voulez aussi la lui envoyer par courriel.

## Lire le tableau

| Colonne | Ce qu’elle indique |
|---|---|
| **Date d’échéance** / **Montant** | le paiement lui-même |
| **Facture** | le numéro de la facture correspondante |
| **Émise** | la date d’émission. Sur une ligne en attente, elle est grisée et marquée *planifiée*, car elle n’a pas encore eu lieu |
| **Émise par** | qui a émis la facture. Sur une ligne en attente, la personne qui a créé le plan s’affiche en grisé. **Automatique** signifie que le traitement quotidien l’a émise, et **Importée** qu’elle provient d’un système de facturation externe |
| **Statut** | le statut de la facture, dans la même pastille de couleur que dans Comptabilité |

Les statuts que vous verrez :

- **En attente** : pas encore émise. Elle attend son tour.
- **Planifiée** : émise, mais pas encore due. Rien n’a été manqué.
- **Délivré** : émise et due maintenant.
- **Partiellement payée**, **Payé**, **En retard** et **Annuler** ont le même sens ici que partout ailleurs.

Le statut affiché est celui de la **facture**, et non de la ligne du plan. C’est la facture qui consigne si l’argent est arrivé.

## Actions par ligne

Le menu **⋮** de chaque ligne n’offre que ce qui s’applique à cette ligne :

- **Voir la facture** et **Télécharger la facture**
- **Émettre maintenant** : lignes en attente seulement. Émet la facture aujourd’hui; aucun courriel n’est envoyé
- **Renvoyer** : lignes émises seulement. Vous confirmez d’abord l’adresse courriel
- **Modifier le versement** : lignes en attente seulement. Modifie ce paiement sans toucher au reste du plan

Pour modifier le plan en entier, utilisez **Modifier le plan** sur la carte du plan. Cela retire et recrée les paiements qui n’ont pas encore été émis.

**Sélectionnez plusieurs lignes** pour agir sur elles ensemble : **Émettre maintenant**, **Télécharger** ou **Supprimer**. Seules les lignes en attente peuvent être émises ou supprimées. Les lignes déjà émises sont ignorées, et le message vous indique combien.

## Annuler un paiement

L’annulation se fait depuis **Comptabilité**, et non depuis cet écran, parce qu’elle retire un document et déduit de l’argent du solde d’une famille. Lorsque vous annulez une facture générée par un plan, **son versement est retiré avec elle**. Il quitte le plan, et le total et le nombre de versements du plan sont recalculés, de sorte que le plan ne compte jamais de l’argent qui n’existe plus. Voir [Comptabilité](accounting).

## Annuler un plan

**Annuler le plan** liste les factures du plan. Celles en attente sont cochées pour retrait, et vous pouvez décocher celles que vous voulez conserver. Les factures **déjà émises ne peuvent pas être retirées ici**, car un paiement est peut-être déjà en cours. Annulez-les délibérément depuis Comptabilité si c’est vraiment ce que vous voulez.

## Où ils apparaissent

Un plan actif s’affiche dans le **grand livre du compte** de la famille sous **À venir**, chaque paiement indiquant la facture correspondante. Voir [Grands livres des comptes](account-ledgers).

## Questions courantes

??? Pourquoi une facture en attente n’a-t-elle pas encore été envoyée? | Elle est émise le matin où son délai d’avis commence, soit la date d’échéance moins les jours d’avis. Utilisez Émettre maintenant pour l’envoyer plus tôt.
??? Puis-je modifier le montant d’un paiement? | Oui, tant qu’il est en attente : utilisez Modifier le versement dans le menu de la ligne. Une fois émise, annulez-la dans Comptabilité et ajoutez-en une de remplacement.
??? Modifier le délai d’avis de l’agence touche-t-il les plans existants? | Un plan conserve le délai d’avis choisi à sa création, même lorsqu’il est modifié. Le nouveau paramètre sert de valeur de départ pour les nouveaux plans.

Voir aussi : [Comptabilité](accounting) · [Grands livres des comptes](account-ledgers) · [Ce que signifie le statut d’une facture](invoice-statuses)
