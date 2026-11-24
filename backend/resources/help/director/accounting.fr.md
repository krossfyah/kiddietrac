---
title: Comptabilité (toutes les factures dans une liste)
category: Billing
order: 38
roles: agency_admin, centre_director, platform_admin
---
# Comptabilité

**Finances → Comptabilité.** Toutes les factures de l’agence figurent dans une seule liste, quelle que soit la personne visée et l’endroit où elles ont été émises. Vous pouvez y ouvrir des factures, enregistrer des paiements, les renvoyer, les modifier et les annuler.

[[open: #external-billing | Ouvrir la comptabilité]]

## Ce que contient la liste

La comptabilité lit les trois sources possibles d’une facture; aucun montant n’y manque donc :

- **Factures KiddieTrac** : émises dans KiddieTrac, par exemple par une exécution mensuelle ou un échéancier de paiement. Elles portent l’étiquette **KIDDIETRAC** sous le numéro.
- **Factures synchronisées** : émises dans un système de facturation externe et synchronisées.
- **Factures de bénéficiaires** : sommes facturées à une personne qui n’est pas une famille, ou qui lui sont payées, comme un éducateur ou un entrepreneur. Elles sont émises dans **Ma paie**, et la ligne indique **Émise dans Ma paie** au lieu de proposer des actions.

Les cartes **En souffrance** et **Encaissé** en haut additionnent ce que la liste affiche; elles décrivent donc toujours le filtre que vous avez choisi.

> **Remarque :** Une facture d’échéancier de paiement en attente figure dans la liste, mais **n’est pas comptée comme en souffrance**. Personne n’a encore demandé cet argent. Voir [Échéanciers de paiement](payment-plans).

## Filtrer

**Onglets** : **En souffrance** (tout sauf les factures annulées), **Payé**, **En retard** et **Annulée**. Les factures annulées sont exclues de la vue par défaut parce qu’elles ne sont plus dues, mais elles sont à un clic.

[[show: #external-billing @ Voided | Montrez-moi l’onglet Annulée]]

**Personne visée par la facture** : la première liste restreint les factures à un type de contrepartie :

- **Tout le monde**
- **Parents** : toutes les factures adressées aux familles, qu’elles soient KiddieTrac ou synchronisées
- **Éducateurs** ou **Prestataires** : le terme suit le réglage de votre agence pour désigner ses installations
- **Entrepreneurs**
- **Autre** : tout ce qui n’a pas reçu de type. Ces factures sont listées plutôt que cachées, car une ligne sans étiquette est justement celle qui mérite d’être vérifiée

Choisir autre chose que Parents efface le filtre **famille**, car un entrepreneur n’a pas de famille.

**Famille** et **recherche** (numéro de facture, description ou statut) s’appliquent à la sélection en cours.

## Actions sur une ligne

**Sur une facture KiddieTrac :**

- **Voir la facture** : ouvre la fiche de la facture. Le personnel voit la facture sans le bouton **Payer** de la famille, afin que personne ne paie par erreur la facture d’une famille avec sa propre carte.
- **Enregistrer un paiement** : voir ci-dessous.
- **Renvoyer la facture**
- **Modifier** : change la personne visée par la facture et son contenu.
- **Télécharger la facture**
- **Annuler la facture**

**Sur une facture synchronisée :** **Voir** et **Télécharger** (lorsque la source fournit un document), **Modifier**, **Renvoyer la facture**, **Enregistrer un paiement** et **Annuler la facture**.

> **Important :** Une facture synchronisée appartient au système de facturation dont elle provient. La modifier ou l’annuler ne change que la copie de KiddieTrac, et la prochaine synchronisation de ce système peut écraser votre changement. Faites le même changement à la source s’il doit durer.

## Enregistrer un paiement

**Enregistrer un paiement** ouvre une boîte de dialogue qui indique le total de la facture et ce qui reste dû. Saisissez :

- **Montant réglé**. Pendant la saisie, elle vous indique si ce montant règle la facture en entier, laisse un solde ou dépasse ce qui est dû. Un paiement partiel est permis.
- **Mode de paiement** : virement Interac / EFT, espèces, chèque, virement bancaire, carte (prise hors ligne) ou autre.
- **Date de réception**, un **Numéro de référence** et une **Note** facultative.

Si votre agence applique des frais sur les paiements par carte ou par EFT, une case à cocher propose d’ajouter les frais de service. Voir [Frais de paiement](payment-surcharges).

## Annuler une facture

**Annuler la facture** annule définitivement la facture, après confirmation et saisie d’un motif :

- Elle conserve son numéro et reste dans la liste, marquée **Annulée**.
- Si un échéancier de paiement l’a émise, **cette échéance est retirée** de l’échéancier.
- La famille reçoit un courriel lui indiquant que la facture est annulée et qu’elle ne doit rien, et le bureau en reçoit un aussi. Rien n’est envoyé si le courriel de votre agence est désactivé.

Une facture sur laquelle de l’argent a été payé ne peut pas être annulée. Remboursez d’abord l’argent, puis annulez. Une facture dont un paiement en ligne est en cours ne peut pas non plus être annulée tant que ce paiement n’est pas réglé ou n’a pas échoué.

## Travailler sur plusieurs à la fois

Cochez des lignes pour agir sur elles ensemble : **Télécharger** et **Annuler**. L’annulation de plusieurs factures affiche d’abord le total annulé, et ignore toute facture sur laquelle de l’argent a été payé ou qui est déjà annulée.

## Qui peut l’utiliser

Les **administrateurs d’agence**, les **directeurs de centre** et les **administrateurs de plateforme**.

Voir aussi : [Grands livres des comptes](account-ledgers) · [Rembourser un paiement](refunds) · [Échéanciers de paiement](payment-plans) · [Signification des statuts de facture](invoice-statuses)
