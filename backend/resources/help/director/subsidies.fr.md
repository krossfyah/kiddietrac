---
title: Subventions — CWELCC et subvention provinciale pour frais de garde
category: Billing
order: 12
roles: agency_admin, centre_director, platform_admin
---
# Subventions — CWELCC et subvention provinciale pour frais de garde

**Barre latérale → Subventions** comporte deux onglets : **CWELCC** et **Subvention provinciale**. L’écran
se souvient de l’onglet où vous étiez.

[[open: #cwelcc | Ouvrir les subventions]]

Les administrateurs d’agence voient toute l’agence. Un directeur de centre voit et gère uniquement ses propres
centres.

## CWELCC

Le rapport mensuel CWELCC : une ligne par famille inscrite, avec ses enfants, les frais
facturés ce mois-là, la subvention au taux de la famille et la portion du parent. Choisissez un
**Mois** pour en voir un autre, et téléchargez-le avec **⤓ CSV** ou **⤓ PDF** pour votre
demande de remboursement.

Le rapport s’appuie sur les **périodes d’inscription** de chaque famille, de sorte qu’un mois passé montre toujours ce qui
s’appliquait à l’époque : une famille qui a depuis quitté le CWELCC apparaît encore dans les mois où elle était
inscrite, et un changement de taux n’affecte que les mois à partir de sa date de début. Les factures annulées et les brouillons
ne sont pas comptés.

[[show: #cwelcc @ CWELCC | Montrez-moi l’onglet CWELCC]]

## Dans le dossier de la famille

Ouvrez une famille (**Familles**, puis la famille) et faites défiler jusqu’à **Subventions**. Vous y voyez
tout l’historique de la famille : chaque période d’inscription au CWELCC avec son taux et la personne qui l’a enregistrée,
et chaque subvention provinciale accordée à la famille, y compris celles qui sont terminées et celles
supprimées par erreur.

Les directeurs et les administrateurs y enregistrent les changements CWELCC :

- **Inscrire au CWELCC** — à partir d’une date, à un taux.
- **Changer le taux** — le nouveau taux s’applique à partir de la date que vous choisissez; les mois antérieurs gardent
  l’ancien taux.
- **Mettre fin au CWELCC** — le dernier jour. Les mois antérieurs conservent leur inscription.

Un changement ne réécrit jamais une période antérieure, de sorte qu’une demande se lit plus tard comme elle se lisait
lorsqu’elle a été déposée. Chaque changement est consigné dans le journal d’audit.

## Subvention provinciale

La **subvention provinciale pour frais de garde** d’un enfant : le numéro de dossier figurant sur la lettre d’approbation, le
montant (par mois ou par jour), les dates d’application et les documents du gouvernement.
**Tant qu’elle s’applique, la subvention est déduite des frais de scolarité de cet enfant sur la facture de la famille**
— la famille ne paie que le reste, et jamais moins que zéro.

- **Par mois** — le montant mensuel est déduit de chaque facture.
- **Par jour** — le montant quotidien × le nombre de jours où l’enfant est **prévu** à l’horaire ce
  mois-là (ses jours de semaine réservés), sans compter les jours de fermeture du centre. Calculé à la
  génération de la facture; la ligne de facture l’indique, p. ex. *Subvention (21 jours prévus
  × 20,00 $)*. Consignez les jours fériés dans le calendrier du centre pour qu’ils ne soient pas comptés.

[[show: #cwelcc @ Provincial subsidy | Montrez-moi l’onglet Subvention provinciale]]

### En saisir une

- [ ] Appuyez sur **＋ Ajouter une subvention**.
- [ ] Choisissez l’**enfant**.
- [ ] Saisissez le **numéro de dossier**, choisissez **Par mois** ou **Par jour**, et saisissez le montant.
- [ ] Définissez le **premier jour**, et le **dernier jour** si l’approbation en comporte un.
- [ ] Sous **Documents de la province ou du gouvernement**, joignez la lettre d’approbation (PDF, photo ou Word, jusqu’à 15 Mo chacun). Vous pouvez en joindre plusieurs.
- [ ] Appuyez sur **Enregistrer**.

Les documents sont privés : seuls les directeurs et administrateurs connectés peuvent les ouvrir, depuis la fenêtre
**Modifier** de la subvention ou le dossier de la famille. En supprimer un le masque; chaque téléversement et chaque
suppression figure dans le journal d’audit.

La liste montre les frais de scolarité de chaque enfant à côté de la subvention, et affiche le montant en rouge si la
subvention dépasse les frais de scolarité — à vérifier de plus près.

### Quand elle change

- **Modifier** corrige le montant, le numéro de dossier ou les dates.
- **Mettre fin à la subvention** fixe son dernier jour. Les factures émises après ce jour ne l’incluent plus.
  Utilisez-le lorsqu’une subvention prend fin, pour en conserver l’historique.
- **Supprimer** est réservé à une subvention saisie par erreur.

Les trois se trouvent dans le menu **⋮** de la ligne.

> **Important :** Un enfant ne peut avoir qu’une subvention à la fois, car les factures en appliquent une par enfant. Si les dates chevauchent une subvention existante, l’enregistrement est refusé et nomme celle qui gêne. Mettez d’abord fin à l’ancienne — pour un renouvellement, terminez-la la veille du début de la nouvelle.

> **Remarque :** Les changements s’appliquent aux factures générées à partir de maintenant. Les factures déjà émises conservent les montants avec lesquels elles ont été émises.

### La liste mensuelle

L’onglet affiche les subventions qui s’appliquent dans le **Mois** choisi, avec le nombre d’enfants
et le total du mois. Cochez **Afficher toutes les subventions, y compris terminées** pour voir
tout l’historique. **⤓ CSV** télécharge le mois pour le rapprochement avec la province ou
la municipalité.

Chaque ajout, modification, fin et suppression est consigné dans le journal d’audit avec l’enfant, le numéro de dossier,
le montant et les dates.

## Questions

??? Une famille a quitté le CWELCC. Disparaîtra-t-elle du rapport du printemps dernier? | Non. Le rapport s’appuie sur les périodes d’inscription, de sorte que la famille reste dans tous les mois où elle était inscrite.
??? Où sont passées les « subventions CWELCC »? | Elles s’appellent maintenant Subventions, avec CWELCC comme premier onglet. Le rapport est inchangé.
??? Une subvention provinciale apparaît-elle sur la facture du parent? | Oui. La facture montre les frais de scolarité, une ligne Subvention qui déduit le montant, et le solde que la famille doit.
??? Un enfant peut-il avoir le CWELCC et une subvention provinciale? | Le rapport CWELCC est calculé à partir de l’inscription de la famille au CWELCC. Sur une facture, un enfant n’a qu’une ligne de subvention à la fois, et l’onglet Subvention provinciale refuse une subvention qui en chevauche une autre.

Voir aussi : [Générer les factures mensuelles](generating-monthly-invoices),
[États des factures](invoice-statuses).
