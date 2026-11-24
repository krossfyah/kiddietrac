---
title: Grands livres des comptes
category: Billing
order: 40
roles: agency_admin, platform_admin
---
# Grands livres des comptes

Un seul écran qui répond, pour une personne donnée : qu’est-ce qui lui a été facturé, qu’a-t-elle payé, que reste-t-il à payer et qu’est-ce qui s’en vient.

![Le grand livre du compte : choisissez une personne, et tout son historique financier tient sur une seule page](https://api.kiddietrac.com/help-img/account-ledger.png)

## Trouver un compte

Le sélecteur en haut comporte trois parties :

- **Rôle** — restreint la liste aux parents, aux éducateurs, aux directeurs, etc.
- **Rechercher** — nom ou courriel. Il attend que vous appuyiez sur **Entrée** ou sur le bouton de recherche; il ne filtre pas au fil de la saisie.
- **Compte** — la personne elle-même, affichée sous la forme **nom et rôle entre parenthèses** : *Amarachi Ihenekwe (Parent)*.

La liste des comptes n’affiche volontairement **pas** les soldes. Dans une liste de soixante-huit personnes, cela ferait soixante-huit montants à parcourir pour trouver quelqu’un, et le solde est la première chose qu’indique le grand livre une fois ouvert.

**⊞ Tous les comptes** affiche tous les comptes à la fois, avec ce que chacun doit. **📄 Un compte** permet de revenir en arrière.

## Ce que signifient les chiffres

Quatre chiffres concordent toujours :

> **Facturé − (Encaissé − Remboursé) = Solde**

- **Facturé** — tout ce qui a été facturé, à l’exclusion de ce qui a été annulé. Une facture annulée ne porte aucun solde et est déclarée séparément.
- **Encaissé** — ce que les factures elles-mêmes indiquent comme réglé.
- **Remboursé** — l’argent rendu. Un remboursement *annule un encaissement* : le solde remonte exactement du montant rendu.
- **Solde** — ce qui reste à payer aujourd’hui.

**L’argent n’est compté qu’une fois, à partir de la facture.** Lorsqu’un enregistrement de paiement existe, il s’affiche sur sa propre ligne — avec le mode de paiement, la référence et la vraie date, ce qui est plus informatif qu’un résumé — mais il n’est pas ajouté une seconde fois. Si les deux divergent un jour, c’est la facture qui a raison.

**Le trop-perçu est signalé, jamais compensé.** Lorsqu’on a reçu plus que ce qui a été facturé, la ligne le précise et le montant apparaît dans son propre chiffre. Qu’il s’agisse d’un crédit dont la famille peut se servir ou d’un paiement enregistré deux fois à la source, c’est à la personne qui lit d’en décider : il est donc indiqué, et non absorbé silencieusement.

## À venir

Tout ce qui est encore attendu sur le compte : chaque paiement d’un échéancier, ainsi que toute facturation récurrente.

Chaque ligne indique **la facture derrière le montant**, et son menu **⋮** permet d’ouvrir, de télécharger ou d’envoyer cette facture par courriel. Deux cas sont signalés plutôt que cachés :

- **« Aucune facture n’a encore été émise »** — un calendrier de facturation récurrente signifie seulement *« nous émettrons quelque chose le 1er »*. Le montant n’est pas arrêté avant l’émission; il n’y a donc rien à ouvrir.
- **« Le total de la facture diffère de ce montant »** — l’échéancier et la facture ne concordent pas, généralement parce que la facture a été modifiée après la convention de l’échéancier. À vérifier.

Un paiement manqué est conservé dans ce tableau et marqué **manqué**, au lieu d’être supprimé. C’est ce qu’il y a de plus utile sur la page.

## Toujours en souffrance

Chaque facture impayée, de la plus ancienne à la plus récente, avec le nombre de jours de retard. Le menu **⋮** de chaque ligne ouvre le document de la facture.

## Générer un relevé

**📄 Générer un relevé** produit un relevé complet du compte. Dans cette fenêtre, vous pouvez définir une période, le lire à l’écran, télécharger le PDF ou l’envoyer par courriel.

Le relevé porte l’image de marque de votre agence, les coordonnées du titulaire du compte et chaque ligne : factures, reçus, remboursements, annulations et ce qui reste dû. Sur une période, il indique aussi le solde reporté et le solde à reporter.

**L’envoi par courriel demande l’adresse chaque fois.** Elle est préremplie avec celle du compte, et il faut la confirmer — il n’y a aucun destinataire par défaut, car un relevé envoyé à la mauvaise personne ne se rattrape pas.

Si l’envoi est bloqué — l’interrupteur de courriel de l’agence est désactivé ou une règle de livraison bloque ce destinataire — on vous le dit clairement. Ici, « Envoyé » signifie que le message est réellement parti.

## Émettre un remboursement

Voir **[Rembourser un paiement](refunds)**.

## Une personne, ou toutes les factures

Cet écran répond aux questions sur **un seul compte**. Pour travailler sur toutes les factures de l’agence, y compris enregistrer des paiements, annuler des factures et filtrer selon la personne visée par la facture (parents, éducateurs, entrepreneurs), utilisez **Finances → Comptabilité**.

[[open: #external-billing | Ouvrir la comptabilité]]

Voir aussi : [Comptabilité](accounting) · [Rembourser un paiement](refunds) · [Échéanciers de paiement](payment-plans)
