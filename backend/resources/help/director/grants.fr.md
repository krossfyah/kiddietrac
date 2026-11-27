---
title: Subventions (fonctionnement, rehaussement salarial et financement ponctuel)
category: Billing
order: 86
roles: agency_admin, centre_director, platform_admin
---
# Subventions (fonctionnement, rehaussement salarial et financement ponctuel)

**Finances → Subventions** suit le financement que votre agence ou votre centre reçoit et doit dépenser dans une période donnée : subventions de fonctionnement, rehaussement salarial, financement CWELCC fondé sur les coûts, immobilisations et sommes ponctuelles. Pour chaque subvention, vous voyez ce qui a été attribué, reçu et dépensé, et si les dépenses suivent le rythme avant la date du rapport. Ouvrez cet écran quand les fonds arrivent, quand vous les dépensez et quand le rapport au bailleur de fonds approche.

[[open: #grants | Ouvrir Subventions]]

> ℹ️ Subventions sert à l'argent que l'agence reçoit. Les subventions de frais qui réduisent la facture d'une famille sont dans **Conformité → Subventions** (l'écran CWELCC et subvention provinciale).

## Ajouter une subvention (administrateurs d'agence)

[[show: #grants @ + Add a grant | Me montrer Ajouter une subvention]]

1. Ouvrez **Finances → Subventions**.
2. Appuyez sur **+ Ajouter une subvention**.
3. Saisissez le **Nom de la subvention** (par exemple « Subvention de rehaussement salarial 2026 ») et le **Bailleur de fonds**.
4. Ajoutez le **Agreement / reference #** (numéro d'entente) figurant sur la lettre de financement.
5. Choisissez le **Type** : General operating, Wage enhancement, CWELCC cost-based funding, Capital / repairs, One-time / special purpose ou Other.
6. Choisissez le **Centre**, ou laissez **All centres (agency-wide)**.
7. Indiquez le **Début de la période** et la **Fin de la période**, le **Montant accordé** et la date du **Rapport à remettre le**.
8. Notez les conditions ou les coûts admissibles dans **Notes (conditions, eligible costs)**.
9. Appuyez sur **Enregistrer**. Le message **Grant saved** s'affiche et la subvention s'ouvre.

## Lire la liste des subventions

1. Utilisez le filtre de la liste pour afficher **Subventions actives**, **Fermé** ou **Tous**.
2. Chaque carte affiche **Attribué**, **Reçu**, **Dépensé**, **Non dépensé** et **Reste à recevoir**, la période et la date du rapport.
3. Lisez la barre : le remplissage est la part de la subvention dépensée, le trait est la part de la période écoulée.
4. Lisez l'étiquette de rythme : **En bonne voie**, **Underspending** (les dépenses sont nettement en retard sur le calendrier; les fonds non dépensés pourraient devoir être remboursés), **Dépenses en avance** (vérifiez que la subvention suffira) ou **Overspent** (dépassement).
5. Cliquez sur une carte pour ouvrir la subvention.

## Consigner les entrées et sorties d'argent

1. Ouvrez la subvention.
2. Sous **Transactions**, appuyez sur le bouton qui correspond :
   - **+ Fonds reçus** quand le bailleur de fonds verse un versement;
   - **+ Dépenses** quand vous dépensez l'argent de la subvention;
   - **Fonds remboursés** quand vous renvoyez l'argent non dépensé;
   - **Ajustement** pour une correction (un montant négatif réduit).
3. Indiquez la **Date** et le **Montant**.
4. Pour une dépense, choisissez la **Catégorie** (Wages, Benefits, Rent / occupancy, Program supplies, etc.) et, si le coût vient d'une facture fournisseur, choisissez-la sous **Linked expense bill (optional)**.
5. Ajoutez une **Description** et une **Reference (cheque, EFT, payroll run)**.
6. Appuyez sur **Enregistrer**. Les totaux, la barre et **Par mois** se mettent à jour aussitôt.

Pour annuler une entrée, appuyez sur **Supprimer** dans sa ligne et confirmez. Les totaux sont recalculés et la suppression est conservée dans le journal d'audit.

## Faire rapport au bailleur de fonds

1. Ouvrez la subvention.
2. Vérifiez la note de rythme sous la barre et le tableau **Par mois**.
3. Appuyez sur **⬇ Exporter en CSV**. Le fichier liste chaque transaction avec les totaux (attribué, reçu, dépensé, remboursé, ajustements, non dépensé, reste à recevoir) pour votre rapport.
4. Une fois la période terminée et le rapport remis, appuyez sur **Modifier**, mettez le **Statut** à **Fermé** et appuyez sur **Enregistrer**.

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Administrateur d'agence | Ajoute et modifie toutes les subventions, consigne les transactions de chacune. |
| Directeur de centre | Voit les subventions de ses propres centres et celles de toute l'agence. Consigne et supprime des transactions sur les subventions de ses centres. Ne peut ni ajouter ni modifier une subvention, ni changer une subvention de toute l'agence. |
| Administrateur de plateforme | Comme un administrateur d'agence, dans l'agence où il travaille. |

Les éducatrices et les parents ne voient pas cet écran.

## FAQ et problèmes courants

??? Je ne vois ni + Ajouter une subvention ni Modifier. | Seul un administrateur d'agence peut créer ou modifier une subvention. Un directeur de centre peut consigner des transactions sur les subventions de ses propres centres.
??? « Only an agency admin can change an agency-wide grant. » | La subvention couvre tous les centres. Demandez à votre administrateur d'agence de saisir l'entrée.
??? « Name, period and amount are required. » | Remplissez **Nom de la subvention**, **Début de la période**, **Fin de la période** et **Montant accordé**.
??? « A date and an amount are required. » ou « The amount must be more than zero. » | Chaque entrée exige une date et un montant supérieur à zéro. Seul un **Ajustement** peut être négatif.
??? La liste indique « No grants yet. » | Aucune subvention n'a été créée pour vos centres. Un administrateur d'agence les ajoute avec **+ Ajouter une subvention**. Vérifiez aussi que le filtre n'est pas sur **Fermé**.
??? Ma facture fournisseur n'est pas dans la liste Linked expense bill. | Une subvention de centre n'offre que les factures de ce centre et celles de toute l'agence. Saisissez d'abord la facture dans **Finances → Dépenses**.
??? KiddieTrac envoie-t-il le rapport ou un courriel? | Non. Subventions conserve vos dossiers; rien n'est envoyé au bailleur de fonds, aux familles ni au personnel. Chaque changement est dans le journal d'audit.

## Écrans liés

[[open: #expenses | Dépenses]] [[open: #cwelcc | Subventions CWELCC et provinciales]]

Voir aussi : [Dépenses](expenses) · [Subventions CWELCC et provinciales](subsidies) · [Financement de la main-d'œuvre de l'Ontario](ontario-workforce-funding)
