---
title: Subventions — CWELCC et subvention provinciale des frais de garde
category: Billing
order: 12
roles: agency_admin, centre_director, platform_admin
---
# Subventions — CWELCC et subvention provinciale des frais de garde

**Conformité → Subventions** regroupe les deux formes d'aide aux frais que reçoivent vos familles. L'onglet **CWELCC** présente le rapport mensuel CWELCC pour votre réclamation. L'onglet **Subvention provinciale** consigne la subvention des frais de chaque enfant accordée par la province ou la municipalité, qui est ensuite déduite des frais de garde de cet enfant sur la facture de la famille. L'écran se souvient de l'onglet où vous étiez.

[[open: #cwelcc | Ouvrir Subventions]]

Les administrateurs d'agence voient toute l'agence. Un directeur de centre ne voit et ne gère que ses propres centres.

## Produire le rapport mensuel CWELCC

[[show: #cwelcc @ CWELCC | Me montrer l'onglet CWELCC]]

1. Ouvrez **Conformité → Subventions** et appuyez sur **CWELCC**.
2. Choisissez le **Mois**. Le **Rapport mensuel CWELCC** se recharge pour ce mois.
3. Lisez les tuiles : **CHILDREN** (enfants), puis (en Ontario) **BASE FEE REVENUE**, **FEE SUBSIDY PAID** et **PARENTS PAID**.
4. Vérifiez les lignes : une par famille inscrite, avec les **Enfants**, le **Centre**, les dates d'inscription et les montants **Base fee**, **Subvention des frais** et **Parents** du mois.
5. Appuyez sur **⤓ CSV** ou **⤓ PDF** pour télécharger le mois pour votre réclamation.

En Ontario depuis 2025, les parents paient les frais de base, moins toute subvention des frais. Les plafonds des frais de base et la vérification des frais supplémentaires sont dans **Conformité → Rapports OCCMS**.

Le rapport lit les **périodes d'inscription** de chaque famille : un mois passé montre toujours ce qui s'appliquait alors. Une famille qui a quitté le CWELCC apparaît encore dans les mois où elle était inscrite, et un changement ne touche que les mois à partir de sa date de début. Les factures annulées et les brouillons ne sont pas comptés.

## Inscrire une famille au CWELCC

1. Ouvrez **Administration → Familles** et ouvrez la famille.
2. Faites défiler jusqu'à **Subventions**. Vous y voyez tout l'historique de la famille : chaque période d'inscription au CWELCC avec la personne qui l'a saisie, et chaque subvention provinciale, y compris celles qui sont terminées.
3. Appuyez sur **Inscrire au CWELCC**, indiquez **De** (le premier jour) et le **Subsidy rate (%)** si votre province en utilise un, puis appuyez sur **Enregistrer**.
4. Quand quelque chose change, appuyez sur **Modifier le taux** (le nouveau taux s'applique à partir de la date choisie; les mois précédents gardent l'ancien) ou sur **Mettre fin au CWELCC** et indiquez le **Dernier jour** (les mois précédents gardent leur inscription).

Un changement ne réécrit jamais une période antérieure : une réclamation se lit plus tard comme au moment où elle a été déposée. Chaque changement est dans le journal d'audit.

## Ajouter une subvention provinciale des frais

[[show: #cwelcc @ Provincial subsidy | Me montrer l'onglet Subvention provinciale]]

1. Ouvrez **Conformité → Subventions** et appuyez sur **Subvention provinciale**.
2. Appuyez sur **＋ Ajouter une subvention**. La fenêtre **Ajouter une subvention provinciale** s'ouvre.
3. Choisissez l'**Enfant**.
4. Saisissez le **Numéro de dossier** inscrit sur la lettre d'approbation.
5. Sous **La subvention est versée**, choisissez **Par mois** ou **Par jour**, puis saisissez le **Monthly amount ($)** ou le **Daily amount ($)**.
6. Indiquez le **Premier jour**, et le **Last day (optional)** si l'approbation en prévoit un. Ajoutez **Approved on (optional)** et **Notes (optional)** au besoin.
7. Sous **Documents de la province ou du gouvernement**, joignez la lettre d'approbation (PDF, photo ou Word, jusqu'à 15 Mo chacun). Vous pouvez en joindre plusieurs.
8. Appuyez sur **Enregistrer**. La subvention apparaît dans la liste du mois.

Comment elle est déduite de la facture pendant qu'elle s'applique :

- **Par mois** : le montant mensuel est déduit de chaque facture mensuelle.
- **Par jour** : le montant quotidien × le nombre de jours où l'enfant est **prévu** ce mois-là, sans compter les jours de fermeture du centre. La ligne de la facture l'indique, par exemple *Subsidy (21 scheduled days × $20.00)*. Inscrivez les congés au calendrier du centre pour qu'ils ne soient pas comptés.

La famille ne paie que le reste, jamais moins de zéro. Les factures déjà émises ne changent pas.

## Modifier, terminer ou supprimer une subvention

1. Dans l'onglet **Subvention provinciale**, trouvez la ligne de l'enfant. Le montant **Subvention** devient rouge s'il dépasse les **Frais de garde** : à vérifier.
2. Ouvrez le menu **⋮** de la ligne.
3. Choisissez **Modifier** pour corriger le montant, le numéro de dossier, les dates ou les documents.
4. Choisissez **Mettre fin à la subvention** et indiquez le **Dernier jour** quand la subvention s'arrête. Les factures après ce jour ne l'incluent plus, et son historique est conservé.
5. Choisissez **Supprimer** seulement pour une subvention saisie par erreur, et confirmez.

> ⚠️ Un enfant ne peut avoir qu'une subvention provinciale à la fois. Si les dates chevauchent une subvention existante, l'enregistrement est refusé et la nomme. Pour un renouvellement, terminez l'ancienne la veille du début de la nouvelle.

L'onglet liste les subventions qui s'appliquent dans le **Mois** choisi, avec **CHILDREN** et **SUBVENTION CE MOIS-CI**. Cochez **Afficher toutes les subventions, y compris celles qui sont terminées** pour tout l'historique. **⤓ CSV** télécharge le mois pour le rapprochement avec la province ou la municipalité. Chaque ajout, modification, fin et suppression est dans le journal d'audit.

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Administrateur d'agence | Les deux onglets pour chaque centre; saisit les changements CWELCC et les subventions provinciales. |
| Directeur de centre | La même chose, pour ses propres centres seulement. |
| Administrateur de plateforme | Comme un administrateur d'agence, dans l'agence où il travaille. |

Les éducatrices ne voient pas cet écran. Les parents ne voient que le résultat : une ligne **Subvention** sur leur facture. Seuls les directeurs et administrateurs connectés peuvent ouvrir les documents de subvention.

## FAQ et problèmes courants

??? Une famille a quitté le CWELCC. Va-t-elle disparaître du rapport du printemps dernier? | Non. Le rapport lit les périodes d'inscription : la famille reste dans chaque mois où elle était inscrite.
??? L'onglet CWELCC indique « No CWELCC-enrolled families for this month. » | Aucune famille de vos centres n'était inscrite ce mois-là. Inscrivez les familles à partir de leur dossier, sous **Subventions**.
??? « Choose the child. » / « Enter the monthly amount. » / « Enter the first day. » | Un champ obligatoire est vide. Remplissez-le et appuyez de nouveau sur **Enregistrer**.
??? « The last day cannot be before the first day. » | Vérifiez les deux dates; le dernier jour doit être le même jour que le premier ou après.
??? « The subsidy was saved, but the document was not uploaded » | La subvention est enregistrée. Ouvrez-la avec **Modifier** et joignez de nouveau le document (PDF, photo ou Word, jusqu'à 15 Mo).
??? La subvention provinciale paraît-elle sur la facture du parent? | Oui. La facture montre les frais de garde, une ligne **Subvention** qui déduit le montant, et le solde dû par la famille.
??? Un enfant peut-il avoir le CWELCC et une subvention provinciale? | Oui. Le rapport CWELCC vient de l'inscription CWELCC de la famille; la subvention provinciale est une ligne par enfant sur la facture.
??? Où est passé « Subventions CWELCC »? | C'est maintenant **Subventions**, avec **CWELCC** comme premier onglet. Le rapport n'a pas changé.
??? Le financement par subvention se saisit-il ici? | Non. Les subventions de fonctionnement et salariales que reçoit l'agence sont dans **Finances → Subventions** (l'écran des subventions reçues).

## Écrans liés

[[open: #subsidy-roa | Rapports OCCMS]] [[open: #admin-families | Familles]] [[open: #grants | Subventions reçues]]

Voir aussi : [Rapports OCCMS](occms-reporting) · [Générer les factures mensuelles](generating-monthly-invoices) · [Statuts des factures](invoice-statuses)
