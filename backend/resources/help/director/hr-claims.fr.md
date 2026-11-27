---
title: RH : notes de frais et kilométrage
category: Staff Management
order: 65
roles: agency_admin, platform_admin, centre_director, educator, home_visitor
---
# RH : notes de frais et kilométrage

Le personnel se fait rembourser l’argent dépensé pour le travail : un reçu pour du matériel, ou les kilomètres parcourus. Un directeur ou un administrateur de l’agence approuve la demande, et elle est remboursée sur la prochaine paie de la personne. Les notes de frais font partie du module KiddieTrac RH et se trouvent dans l’onglet **Notes de frais et kilométrage** de **Ressources humaines > Paie**.

[[open: #payroll?tab=claims | Ouvrir Notes de frais et kilométrage]]

## Configurer les remboursements (administrateurs de l’agence, une seule fois)

1. Ouvrez **Ressources humaines > Paie** et choisissez l’onglet **Notes de frais et kilométrage**.
2. Appuyez sur **Réglages des remboursements**.
3. Saisissez le **Mileage rate ($ per km)** (taux par km). Sans taux, le personnel ne peut pas demander de kilométrage et l’onglet indique qu’aucun taux n’est encore fixé.
4. Sous **Categories staff choose from (one per line)**, tapez une catégorie par ligne (par exemple Bricolage, Collations, Stationnement).
5. Laissez cochée la case **A receipt photo is required for every expense claim**, sauf si vous acceptez vraiment des dépenses sans reçu.
6. Appuyez sur **Enregistrer les paramètres**. Un message confirme que les réglages sont enregistrés.

> ℹ️ KiddieTrac ne fixe jamais de taux par kilomètre pour vous. Utilisez le taux convenu par votre agence.

## Faire une demande (personnel)

Le personnel fait ses propres demandes à partir de **Mes RH**; les personnes que vous approuvez n’ont donc jamais besoin de cet écran.

1. Ouvrez **Mes RH** et repérez **Notes de frais et kilométrage**.
2. Appuyez sur **Nouvelle demande**. Le formulaire de demande s’ouvre.
3. Sous **Type**, choisissez **Dépense (avec un reçu)** ou **Kilométrage**.
4. Pour une dépense : choisissez la **Catégorie**, entrez le montant avant taxes et la taxe, décrivez à quoi elle a servi et ajoutez la **Photo du reçu** (sur un téléphone, l’appareil photo s’ouvre).
5. Pour du kilométrage : indiquez le point de départ, la destination et la **Distance (km)**. Le montant est la distance multipliée par le taux de votre agence.
6. Appuyez sur **Envoyer la demande**. La demande s’affiche **En attente d'approbation**.

Une demande encore en attente peut être reprise avec **Retirer la demande**.

## Approuver ou refuser une demande

1. Ouvrez **Ressources humaines > Paie > Notes de frais et kilométrage**.
2. Gardez le filtre **À approuver** pour ne voir que les demandes en attente d’une décision.
3. Appuyez sur **Voir la demande** sur une ligne. La demande s’ouvre avec le reçu, le montant et la personne qui l’a faite.
4. Ouvrez le reçu pour vérifier qu’il correspond au montant.
5. Appuyez sur **Approuver**, ou sur **Refuser**.
6. Si vous refusez, écrivez la raison (la personne la voit) et confirmez avec **Decline claim**.

La personne reçoit un avis dans les deux cas. Une demande approuvée passe à **Approuvée : sur la prochaine paie**; une demande refusée affiche **Refusé** avec votre raison.

[[show: #payroll?tab=claims @ Claims & mileage > Claim settings | Montrez-moi les réglages]]

## Comment les demandes approuvées sont payées

1. Un administrateur de l’agence ouvre l’onglet **Paie** et appuie sur **＋ Generate payroll** pour la période.
2. La ligne de chaque personne dans le traitement liste les demandes approuvées à rembourser, comprises dans sa paie brute.
3. Quand l’administrateur appuie sur **Approuver et créer les bulletins de paie**, les demandes sont inscrites sur les talons de paie et passent à **Payé**.

Une demande payée ne peut pas être payée une deuxième fois. Les demandes ne sont pas ajoutées aux talons de paie qui arrivent d’un système de paie externe.

## Vérifier ce qui a été demandé

1. Appuyez sur **Rapport des remboursements**.
2. Choisissez les dates.
3. Passez de **By person** à **By category** ou **By month**. Les totaux ne comptent que les demandes approuvées et payées.

## Qui peut faire quoi

| Rôle | Ce qu’il peut faire |
|---|---|
| Administrateur de l’agence | Approuver ou refuser toutes les demandes de l’agence, modifier les **Réglages des remboursements**, faire la paie qui les rembourse. |
| Directeur de centre | Approuver ou refuser les demandes du personnel de ses propres centres; lire le rapport pour ces centres. |
| Éducatrice, éducateur, visiteur à domicile | Faire, suivre et retirer leurs propres demandes dans **Mes RH**. |

Personne n’approuve sa propre demande, pas même un administrateur de l’agence. Les reçus sont des fichiers privés : seuls la personne, son directeur et les administrateurs de l’agence peuvent les ouvrir.

## FAQ et en cas de problème

??? L’onglet Notes de frais et kilométrage n’apparaît pas. | L’onglet fait partie du module KiddieTrac RH. Sans lui, Paie n’affiche que son propre onglet. Parlez du module à votre administrateur d’agence.
??? Le personnel ne peut pas choisir Kilométrage. | Aucun taux par kilomètre n’est fixé. Un administrateur de l’agence en ajoute un dans les **Réglages des remboursements**.
??? La liste est vide. | Le filtre **À approuver** ne montre que les demandes en attente. Choisissez **All** pour tout voir, et vérifiez que les personnes font partie de vos centres.
??? Je ne peux pas approuver une demande. | C’est votre propre demande. Un autre directeur ou un administrateur de l’agence l’approuve.
??? La demande indique « Add a photo of the receipt. » | Les reçus sont obligatoires pour les dépenses. La personne ajoute une photo, ou un administrateur décoche la règle du reçu.
??? Une demande approuvée n’est pas sur le talon de paie. | Elle n’est ajoutée qu’aux talons créés avec **＋ Generate payroll** après son approbation. Vérifiez la période du traitement et que la personne était cochée.

## Écrans liés

[[open: #payroll | Paie]] [[open: #my-hr | Mes RH]]

- [Paie, heures et talons de paie](payroll-documents)
- [[my-hr]]
