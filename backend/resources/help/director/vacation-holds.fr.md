---
title: Crédits de places réservées (vacances)
category: Billing
order: 84
---
# Places réservées (vacances)

**Places réservées (vacances)** suspend les frais d’une famille pendant son absence. Une famille demande une suspension (ou vous en ajoutez une pour elle), vous l’approuvez ou la refusez, et une suspension approuvée donne un crédit à la famille. Les directeurs et administrateurs d’agence ouvrent cet écran quand une famille annonce une absence, et pour traiter les demandes en attente.

[[open: #vacation-holds | Ouvrir Places réservées (vacances)]]

## Approuver ou refuser une demande

Les nouvelles demandes ont le statut **demandé**, avec **Approuver** et **Refuser** dans la ligne. Les directeurs et administrateurs d’agence reçoivent une notification quand une famille fait une demande — « Vacation hold requested by » suivi de la famille, avec les dates.

1. Ouvrez **Administration › Places réservées (vacances)**.
2. Trouvez la ligne au statut **demandé**. Vérifiez **Famille**, **Centre**, **Dates** et **Motif**.
3. Appuyez sur **Approuver**. La boîte **Approuver la suspension pour vacances** s’ouvre.
4. Tapez un montant de crédit pour la famille, ou laissez la case vide pour un calcul automatique.
5. Appuyez sur **Approuver**. La ligne passe à **approved** et la colonne **Crédit** affiche le montant.
6. Pour refuser, appuyez plutôt sur **Refuser** dans la ligne. Elle passe tout de suite à **denied**; il n’y a rien à remplir.

Appuyer sur **Annuler** dans la boîte d’approbation laisse la demande telle quelle — rien n’est approuvé.

> ⚠️ Un montant vide est une estimation de **30 $ par jour** pour chaque jour de la suspension, premier et dernier jours compris (10 jours = 300 $). Si votre tarif quotidien est différent, tapez le vrai montant.

## Ajouter une suspension pour une famille

Utilisez cette option quand une famille vous prévient par téléphone, par courriel ou à la porte.

1. Appuyez sur **+ Ajouter une suspension** dans la bannière.
2. Dans **🏖 Ajouter une suspension pour vacances**, choisissez la famille sous **Famille** (**Sélectionner une famille…**).
3. Indiquez **De** et **À**. La date de fin ne peut pas être avant la date de début.
4. Ajoutez un **Reason (optional)**, par exemple « Vacances en famille ».
5. Appuyez sur **Ajouter une suspension**. La suspension s’ajoute à la liste comme **demandé**, et les tuteurs de la famille sont avisés : « A vacation hold was added to your account ».
6. Approuvez-la avec les étapes ci-dessus pour fixer le crédit.

## Ce que voit la famille

- Quand vous approuvez, chaque tuteur reçoit une notification : « Vacation hold approved — Credit of $… will be applied. »
- Quand vous refusez, il reçoit « Vacation hold denied — Unable to approve at this time. »
- La notification ouvre son écran **Facturation**.

> ℹ️ Le crédit est inscrit sur la suspension. Assurez-vous qu’il se rend à la facture de la famille : vérifiez sa prochaine facture dans **Facturation**, et ajoutez-y le crédit s’il n’y est pas. Voir [[family-ledger]].

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d’agence | Voir toutes les suspensions de l’agence, en ajouter, approuver ou refuser |
| Directeur de centre | Voir, ajouter, approuver et refuser les suspensions des familles de ses centres seulement; celles des autres centres ne sont pas listées |
| Administrateur de plateforme | La même chose qu’un administrateur d’agence, dans l’agence consultée |
| Parent | Demande une suspension pour sa propre famille et est avisé de la décision |

Les éducatrices ne voient pas cet écran.

## Questions fréquentes et problèmes

??? Il n’y a pas de bouton Approuver ni Refuser dans une ligne. | Les boutons ne paraissent que tant qu’une suspension est **demandé**. Une suspension approuvée ou refusée est finale; ajoutez-en une nouvelle si les dates changent.
??? « Family and both dates are required. » | Choisissez une famille et remplissez **De** et **À**, puis appuyez de nouveau sur **Ajouter une suspension**.
??? « No families loaded — select an agency first. » | La liste des familles est vide parce qu’aucune agence n’est choisie. Choisissez votre agence dans le sélecteur d’agence et rouvrez l’écran.
??? J’ai appuyé sur Annuler par erreur. | Rien n’a changé — la suspension est toujours **demandé**. Appuyez de nouveau sur **Approuver**.
??? Le crédit était mauvais. | Une suspension décidée ne peut pas être modifiée ici. Ajustez la prochaine facture de la famille dans **Facturation**, ou demandez à votre administrateur d’agence.
??? La liste affiche « No vacation holds. » | Aucune famille n’a demandé de suspension et aucune n’a été ajoutée. Utilisez **+ Ajouter une suspension** pour en ajouter une.
??? Y a-t-il une limite de durée? | Pas dans KiddieTrac. Beaucoup de centres permettent jusqu’à deux semaines par année — appliquez votre propre politique quand vous approuvez.

## Écrans liés

[[open: #admin-families | Familles]]
[[open: #closures | Fermetures]]
[[open: #tuition-increases | Hausses de frais]]

Voir aussi [[family-ledger]] et [[generating-monthly-invoices]].
