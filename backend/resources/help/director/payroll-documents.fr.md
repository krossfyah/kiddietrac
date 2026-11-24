---
title: Cycles de paie, heures et talons de paie
category: Staff Management
order: 30
roles: agency_admin, platform_admin
---
# Cycles de paie, heures et talons de paie

Un seul écran répond aux deux moitiés d’une même question : qu’a travaillé cette personne, et combien a-t-elle été payée pour cela.

![Paie : Éducateurs, Autre personnel et Entrepreneurs, chacun avec ses talons de paie](https://api.kiddietrac.com/help-img/payroll.png)

## Trois groupes

**Éducateurs**, **Autre personnel** et **Entrepreneurs** sont des onglets distincts, et non une longue liste. Ils sont payés selon des modalités différentes et approuvés par des personnes différentes, et chacun a son propre sous-total. L’écran s’ouvre sur le premier onglet qui contient quelqu’un.

Le **Total des heures** global des deux groupes de personnel reste affiché en dessous.

## Les entrepreneurs sont différents, volontairement

Un entrepreneur ne pointe pas. Il n’a pas d’identifiant de connexion et est payé par nom; il n’y a donc ni pointages ni heures à déclarer. L’afficher à côté de personnes avec un nombre de pointages mettrait une colonne vide et zéro heure en face d’un argent réellement versé.

Son onglet indique plutôt **ce qu’il a été payé** pendant la période, et le précise sur la page.

## Quelqu’un de payé sans avoir pointé

Le rapport inclut toute personne **payée** pendant la période, et pas seulement celles qui ont **pointé**. Un visiteur à domicile payé à la visite, un gestionnaire salarié, toute personne à montant fixe : ils apparaissent avec 0 pointage et 0 heure, ce qui est vrai pour eux, ainsi que leurs talons de paie.

Sans cela, un mois où personne n’a pointé s’affichait comme un rapport vide alors que des talons de paie existaient pour ce mois.

## Les talons de paie sur la ligne

La colonne **Talons de paie** indique combien de documents une personne a pour la période et à quel total ils s’élèvent. Ouvrez-la et chaque document est listé en dessous avec son propre menu **⋮** :

- **Talon de paie** : ouvre le PDF.
- **Imprimer** : l’ouvre et l’envoie à l’imprimante.
- **Télécharger** : enregistre le PDF.
- **Envoyer le talon de paie par courriel** : l’envoie, avec le PDF en pièce jointe, à une adresse que vous confirmez.
- **Envoyer par Interac** : le paie, si la personne a un compte (voir ci-dessous).
- **Marquer comme payé** / **Marquer comme non payé** : l’inscription manuelle, qui alterne.

L’envoi par courriel demande l’adresse chaque fois. Elle est préremplie lorsqu’une adresse est au dossier, et elle doit être confirmée : il n’y a pas de destinataire par défaut pour la paie de quelqu’un.

## Payer un talon de paie

**Envoyer par Interac** déplace de l’argent réel; il demande donc une confirmation et nomme le montant et la personne :

> Envoyer 1 424,90 $ par Interac à Bruni Meeser? Cette opération déplace de l’argent réel.

Le paiement est lié au talon de paie qu’il règle, de sorte que le dossier montre ce qui a été payé et pour quoi.

**Les entrepreneurs ne peuvent pas être payés ainsi.** Interac envoie à un compte de personne, et un entrepreneur est un nom plutôt qu’un compte. Leurs talons de paie sont marqués payés à la main; tout le reste du menu fonctionne normalement.

## Générer la paie

**＋ Générer la paie** ouvre l’assistant de cycle : choisissez la période, cochez les personnes, et les heures sont calculées à partir de leurs pointages fermés. Ajoutez des lignes et des notes, approuvez, et les talons de paie sont créés.

Seuls les pointages **fermés** comptent. Toute personne encore pointée est nommée sur sa ligne pour que vous voyiez ce qui a été exclu.

Le cycle comporte les trois mêmes onglets : **Éducateurs**, **Autre personnel** et **Entrepreneurs**. L’onglet **Entrepreneurs** liste les bénéficiaires que votre agence a déjà payés. Ils n’ont pas de pointeuse; vous saisissez donc seulement un montant. Le **Lignes et notes** de chaque ligne contient des lignes de détail (kilométrage, un complément, une déduction) et une note pour le talon de paie. **Approuver et créer les talons de paie** les crée.

Lorsque vous cochez quelqu’un et saisissez son taux horaire, **Paie** est rempli avec heures × taux comme point de départ. Vous pouvez le modifier. Une personne qui **a déjà un talon de paie manuel pour la même période est ignorée**, et on vous indique combien de lignes ont été ignorées et pourquoi, afin qu’un cycle ne puisse pas payer deux fois la même personne.

**Le taux n’est jamais rempli pour vous.** Il commence vide et quelqu’un le saisit, à chaque cycle. Les chiffres historiques ne permettent pas de distinguer un taux horaire de la paie d’une période entière : les données indiquent des « heures » en face de montants manifestement non horaires, et multiplier par le mauvais produit un talon de paie que personne n’avait l’intention d’approuver.

## L’exportation CSV

**⤓ CSV** exporte le rapport d’heures de la période. C’est un fichier d’heures; il liste donc les personnes qui ont pointé, et les talons de paie des personnes sans pointage se trouvent à l’écran plutôt que dans le fichier.

## D’où viennent les heures

Chaque heure de cet écran est un pointage sur la pointeuse. **Feuilles de temps** est l’endroit où ces pointages sont lus et corrigés, un **⋮** par quart, et c’est là qu’il faut régler un quart ouvert ou manquant *avant* un cycle de paie plutôt qu’après la création des talons de paie.
Voir [Feuilles de temps et correction des heures](timesheets).
