---
title: Paramètres RH : année de congé, absences courtes, règles de présence, moteur de paie et Google Jobs
---
# Paramètres RH : année de congé, absences courtes, règles de présence, moteur de paie et Google Jobs

Les **Paramètres RH** regroupent les règles de toute l'agence que suit le reste de KiddieTrac RH : le début de l'année de congé, si une demande peut faire passer un solde sous zéro, la durée d'une journée de travail, quand les absences courtes sont signalées, le fonctionnement des retards, du score d'absence et des chevauchements de congés, l'activation du moteur de paie et Google Jobs. Seuls les administrateurs d'agence peuvent l'ouvrir. Qui approuve les congés se règle dans l'onglet **Approbateurs**, juste à côté.

[[open: #hr-settings | Ouvrir les paramètres RH]]

## Régler l'année de congé et les soldes

1. Ouvrez **Ressources humaines > Paramètres RH**. L'onglet **Paramètres** s'ouvre.
2. Dans **Début de l'année de congé**, choisissez **On the same date for everyone** (même date pour tout le monde) ou **On each person's start-date anniversary** (date anniversaire d'embauche de chacun).
3. Si vous avez choisi la même date, choisissez le mois et le jour sous **Date** (par exemple le 1er janvier).
4. Dans **Demandes qui passent sous zéro**, choisissez **Refuse them** (les refuser) ou **Allow them, with a warning** (les permettre avec un avertissement).
5. Dans **Heures dans une journée de travail**, inscrivez la durée d'une journée normale (8 sauf si vous la changez). Elle sert partout où une journée de congé doit devenir des heures, par exemple quand une fermeture est prise sur les congés.
6. Appuyez sur **Enregistrer les paramètres**. Un message vert confirme l'enregistrement.

> ⚠️ Changer le début de l'année de congé ne modifie pas les soldes déjà accordés. Les nouvelles attributions et les reports suivent la nouvelle date à partir de la prochaine année de congé.

## Signaler les absences courtes répétées

Le rapport d'absences signale toute personne qui a beaucoup d'absences courtes sur une période. C'est un simple compte, que vous fixez.

1. Sous **Absences courtes répétées**, inscrivez le nombre d'**Absences** (3 sauf si vous le changez).
2. Inscrivez **Of this many days or less** (de ce nombre de jours ou moins; 2 sauf si vous le changez), pour ne compter que les absences courtes.
3. Inscrivez **Within (months)** (sur combien de mois; 6 sauf si vous le changez).
4. Appuyez sur **Enregistrer les paramètres**.

Les personnes qui atteignent ce seuil sont signalées dans **Ressources humaines > Absences et retards**.

## Régler les règles de présence

La carte des présences se trouve sous les paramètres principaux.

1. Sous **Retards**, inscrivez le **Délai de grâce (minutes)** : un premier pointage plus tard que le début du quart plus ce nombre de minutes compte comme un retard (5 sauf si vous le changez).
2. Choisissez s'il faut **Prévenir les directeurs du centre** à chaque fois, et si les **Entrepreneurs** sont vérifiés.
3. Sous **Score d'absence**, inscrivez **Signaler à partir de** (50 sauf si vous le changez) et **Sur les derniers (mois)** (12 sauf si vous le changez).
4. Cochez les **Types de congé comptés**. Aucun coché = vos types de congé de maladie.
5. Sous **Chevauchements de congés et personnel minimum**, choisissez s'il faut **Montrer qui est aussi absent** à la personne qui approuve.
6. Dans **Sous le personnel minimum**, choisissez **Avertir la personne qui approuve** ou **Ne pas permettre de l'approuver**.
7. Pour chaque centre, inscrivez le **Personnel minimum un jour ouvrable**. **Staff placed there** montre combien de personnes travaillent à ce centre.
8. Appuyez sur **Enregistrer les règles de présence**.

> ℹ️ Le score d'absence multiplie le nombre d'épisodes d'absence distincts, au carré, par le total des jours d'absence : beaucoup d'absences courtes pèsent donc plus qu'une seule longue. Un congé qui n'exige pas d'approbation (comme les journées de maladie) n'est jamais refusé par la règle du personnel minimum; il est seulement montré.

## Activer le moteur de paie

La carte **Payroll engine** calcule le RPC, l'AE, le RQAP et l'impôt pour chaque paie, fait les cycles de paie, produit les talons de paie et montre ce qu'il faut verser. Il est désactivé tant que vous ne l'activez pas, et il ne change pas votre exportation de paie actuelle.

1. Lisez l'avis sur la carte.
2. Cochez la case par laquelle vous vous engagez à vérifier les premiers cycles avec votre comptable (demandé une seule fois).
3. Cochez **Payroll engine on**.
4. Inscrivez votre numéro de compte de retenues de l'ARC (RP), votre numéro de Revenu Québec si vous avez du personnel au Québec, le **Remitter type** (type de remettant) et, si vous voulez le voir dans les rapports, le taux de la CNESST ou de la commission des accidents du travail.
5. Appuyez sur **Save payroll engine**. Un message confirme que le moteur est activé, et les onglets **Pay runs** et **Remittances** apparaissent sous **Paie**.

Pour les cycles de paie, les versements et les soldes d'ouverture, voir [Moteur de paie : cycles de paie, talons et versements](payroll-engine).

## Afficher vos postes dans Google Jobs

1. Sous **Google Jobs**, réglez **Afficher nos postes ouverts dans Google Jobs** sur **Oui, les afficher** (c'est **No (default)** tant que vous ne le changez pas).
2. Appuyez sur **Enregistrer Google Jobs**. Un message vert confirme l'enregistrement.
3. Appuyez sur **Ouvrir votre page d'emplois** pour voir ce que voient les candidats.

Chaque offre ouverte porte alors les détails que lit Google : le titre, la ville, la province et le pays (jamais l'adresse civique), et la paie quand vous l'avez indiquée. Google peut prendre quelques jours avant d'afficher les nouveaux postes.

## Où se trouvent les autres règles RH

Les règles propres à une tâche se trouvent dans l'onglet de cette tâche, à côté du travail qu'elles changent : publication et acceptation des quarts dans **Horaire et quarts**, heures supplémentaires et arrondi des feuilles de temps dans **Pointages et feuilles de temps**, fermetures prises sur les congés et prorata du temps partiel dans **Congés > Closures & checks**, reconnaissance dans **Prix des éducatrices > Recognition**, et période d'essai et invitations dans le **Tableau de bord RH** (**Paramètres de période d'essai et d'invitation**).

## Qui peut faire quoi

| Rôle | Paramètres RH |
|---|---|
| Administrateur d'agence | Ouvre et change tous les paramètres ici et dans **Approbateurs** |
| Direction de centre | Ne voit pas les **Paramètres RH**; suit les règles (par exemple l'avertissement de personnel minimum quand elle approuve) |
| Éducatrices et visiteuses à domicile | Pas cet écran |

## FAQ et quand quelque chose ne va pas

??? Les paramètres RH ne sont pas dans mon menu. | Seuls les administrateurs d'agence les voient, et seulement si l'agence a le module KiddieTrac RH.
??? La carte du moteur de paie n'apparaît pas. | Elle ne s'affiche qu'aux personnes autorisées à la modifier. Demandez à votre administrateur d'agence.
??? Un message me demande de cocher l'avis d'abord. | Cochez la case de l'avis, puis enregistrez de nouveau. Ce n'est demandé que la première fois.
??? Le nouveau début d'année de congé n'a changé aucun solde. | C'est normal : les soldes déjà accordés ne sont jamais modifiés. La nouvelle date s'applique à partir de la prochaine attribution.
??? Une direction a quand même pu approuver sous le personnel minimum. | **Avertir la personne qui approuve** ne fait qu'avertir. Choisissez **Ne pas permettre de l'approuver** pour bloquer les approbations; un congé sans approbation est quand même inscrit.
??? Enregistrer ces paramètres envoie-t-il des courriels? | Non. Les paramètres changent seulement le fonctionnement de KiddieTrac à partir de maintenant.

## Écrans liés

[[open: #hr-settings?tab=approvers | Approbateurs]] [[open: #time-off?tab=types | Types de congé]] [[open: #hr-absence | Absences et retards]] [[open: #hr | Tableau de bord RH]]

Voir aussi [[hr-attendance]], [[hr-leave-types]] et [[hr-people-recognition]].
