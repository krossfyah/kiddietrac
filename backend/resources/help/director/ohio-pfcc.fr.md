---
title: Services de garde subventionnés de l’Ohio (PFCC)
---
# Services de garde subventionnés de l’Ohio (PFCC)

Pour les agences de l’Ohio. Regroupez l’autorisation PFCC de chaque enfant, la quote-part hebdomadaire de la famille et les jours d’absence, et vérifiez les présences de chaque semaine avant de les soumettre dans TAP. Ouvrez l’écran dans le menu latéral : **Opérations** → **Ohio PFCC**. Il s’affiche pour les agences de l’Ohio, ou quand un administrateur de l’agence l’a activé (**L’activer quand même**, ou **Limites et alertes**).

[[open: #pfcc | Ouvrir Ohio PFCC]]

L’écran compte cinq onglets : **Autorisations**, **Quotes-parts**, **Rapprochement TAP**, **Répartition de la facture** et **Limites et alertes**. Les semaines vont du dimanche au samedi.

## Ajouter une autorisation

1. Ouvrez l’onglet **Autorisations** et appuyez sur **Ajouter une autorisation**. La fenêtre **Ajouter une autorisation PFCC** s’ouvre.
2. Choisissez l’**Enfant**.
3. Saisissez le **Numéro de dossier**.
4. Choisissez l’**Unité de garde** : à l’heure (moins de 10 heures par semaine), temps partiel (10 à 33) ou temps plein (33 et plus), et saisissez les **Unités par semaine**.
5. Saisissez le **Provider rate per unit ($)** indiqué sur l’autorisation de l’enfant, et la **Weekly family copay ($)**.
6. Réglez **Début**, et **Ends (optional)** s’il y a une date de fin. Ajoutez la date de l’**Entente de quote-part signée** et des **Notes** au besoin.
7. Appuyez sur **Ajouter**. Le message **Autorisation ajoutée** s’affiche.
8. Sur la ligne, appuyez sur **Téléverser** sous **Entente** pour joindre l’entente de quote-part signée. **Voir** l’ouvre de nouveau plus tard, et **Remplacer** la change.

KiddieTrac ne contient pas la grille de tarifs de l’Ohio. Utilisez **Modifier** pour changer une autorisation. Quand elle prend fin, appuyez sur **Fin**, indiquez le dernier jour de garde et appuyez sur **La terminer**.

## Consigner les quotes-parts

1. Ouvrez l’onglet **Quotes-parts**. Le **Registre hebdomadaire des quotes-parts** liste chaque semaine de l’autorisation choisie.
2. Choisissez l’enfant dans la liste en haut.
3. Sur une semaine, appuyez sur **Record**.
4. Saisissez le **Amount paid ($)** et la date **Payée le**. Laissez le **Statut** sur **Calculer**, ou choisissez **Annulé (exonéré)** ou **Non payé**, et ajoutez une **Note**.
5. Appuyez sur **Enregistrer**.

Les quotes-parts sont tenues à part des factures, et **les familles ne reçoivent jamais de message à leur sujet**. Quand une famille a manqué le nombre de semaines réglé sous **Limites et alertes** (2 par défaut), les directeurs du centre et les administrateurs de l’agence reçoivent un avis dans la cloche. L’Ohio s’attend à ce qu’un défaut de paiement de plus de 2 semaines soit signalé au comté dans les 3 semaines.

## Jours d’absence

Chaque autorisation montre les jours d’absence de l’enfant pour le semestre en cours (janvier à juin ou juillet à décembre) par rapport à votre limite. Les directeurs et les administrateurs reçoivent un avis dans la cloche quand un enfant est à 2 jours de la limite, et de nouveau s’il la dépasse. La limite est d’abord de **10**. Les sources de l’Ohio donnent 10 ou 20 jours selon les cas; **vérifiez-la en fonction de vos autorisations** et changez-la sous **Limites et alertes**.

## Vérifier une semaine pour TAP

1. Ouvrez l’onglet **Rapprochement TAP**.
2. Choisissez la semaine avec **‹ Semaine** et **Week ›** (et un centre, si vous en avez plusieurs).
3. Vérifiez chaque enfant signalé pour une garde **sans autorisation**, une arrivée **sans départ correspondant** (ou l’inverse), des **heures dépassant** ce que permet l’unité de garde, ou un enfant autorisé **sans aucune présence** cette semaine-là.
4. Faites la correspondance des présences dans TAP à la main. **Télécharger le CSV** vous donne la même liste dans un chiffrier.

Le système TAP de l’Ohio n’offre aucune connexion que KiddieTrac peut utiliser. Une semaine de plus de 3 semaines porte un avertissement : TAP supprime les présences correspondantes non soumises après 4 semaines. Quand tout correspond, vous voyez **Nothing flagged this week.**

## Répartir une facture entre l’État et la famille

1. Ouvrez l’onglet **Répartition de la facture** et trouvez une facture à l’état de **brouillon**.
2. Appuyez sur **Aperçu**. KiddieTrac calcule la part de l’État pour la période de la facture : unités autorisées × tarif − quote-part hebdomadaire, au prorata par semaine. Il indique ce que **La famille doit après la répartition**.
3. Si tout semble correct, appuyez sur **Appliquer à ce brouillon**. Le message **Répartition appliquée** s’affiche et la part de l’État est ajoutée à la facture comme subvention.
4. Pour la retirer avant l’envoi de la facture, appuyez sur **Voir**, puis sur **Annuler la répartition**.

Les factures déjà envoyées ne sont jamais touchées, une facture qui porte déjà une autre subvention n’est pas répartie, et les enfants sans autorisation ne sont pas touchés. Ce que la famille paie au-delà de la quote-part suit le réglage **Après une répartition, la famille paie** sous Limites et alertes : **Only the copay (the gap is written off)**, ou **The copay plus tuition above what the state pays**.

## Limites et alertes

Un administrateur de l’agence règle les **Jours d’absence permis par semestre**, **Alerte après des semaines de quote-part impayée**, **Après une répartition, la famille paie** et la **Cote Step Up To Quality**, puis appuie sur **Enregistrer les paramètres**. Hors de l’Ohio, deux autres cases activent **Show publicly funded child care for this agency (not in Ohio)** et **Show Step Up To Quality for this agency**.

## Qui peut faire quoi

- Les **administrateurs de l’agence** voient tous les centres et changent les limites et alertes.
- Les **directeurs de centre** voient et gèrent les autorisations, les quotes-parts, le rapprochement et les répartitions de facture de leurs propres centres. Les limites et alertes sont en lecture seule pour eux : « Only agency admins can change these. »
- Les éducateurs et les parents ne voient pas Ohio PFCC. Les familles ne reçoivent jamais de message au sujet des quotes-parts.

## FAQ et dépannage

??? Je ne vois pas Ohio PFCC dans le menu. | L’écran s’affiche seulement pour les agences de l’Ohio, ou quand un administrateur de l’agence l’active. Vérifiez la province ou l’État de l’agence.
??? Le message « Ohio publicly funded child care is not switched on for this agency. » s’affiche. | Un administrateur de l’agence peut appuyer sur L’activer quand même.
??? Ajouter indique « Choose a child. » | Choisissez l’enfant avant d’appuyer sur Ajouter. Seuls les enfants inscrits sont listés.
??? L’aperçu n’offre pas le bouton Appliquer. | La facture n’est pas un brouillon, elle porte déjà une autre subvention, ou aucun enfant de la facture n’a d’autorisation.
??? Les avis dans la cloche sont-ils parfois envoyés par courriel ou par texto ? | Non. Les alertes de jours d’absence et de quotes-parts impayées sont des avis dans la cloche, pour les directeurs et les administrateurs seulement.

## Liens utiles

[[open: #pfcc | Ohio PFCC]]
[[open: #sutq | Step Up To Quality]]

- [Step Up To Quality](step-up-to-quality)
- [Subventions](subsidies)
- [Produire les factures mensuelles](generating-monthly-invoices)
