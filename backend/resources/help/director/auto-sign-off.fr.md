---
title: Déconnexion automatique
category: Staff Management
order: 45
---

# Déconnexion automatique

Il y a toujours quelqu’un qui oublie. Un quart laissé ouvert pendant la nuit devient une semaine de 40 heures dans le rapport de paie, et un enfant encore affiché comme présent à minuit rend les chiffres de ratio inutilisables. La déconnexion automatique ferme ce qui a été oublié, à l’heure que vous choisissez, et avise le soir même les personnes concernées.

Elle est **désactivée** tant que vous ne l’activez pas, et le personnel et les enfants se règlent séparément. Les directeurs et les administrateurs d’agence peuvent la modifier.

## Étapes rapides

1. Allez à **Paramètres → Gestion des salles** et ouvrez l’onglet **⏱️ Déconnexion automatique**. (Le même réglage se trouve aussi sous **Paramètres → Paramètres du pointage → Déconnexion automatique**.)
2. Activez **Fermer les quarts du personnel** et réglez l’heure, p. ex. 19 h 00.
3. Réglez **Abandonner un quart après** — le nombre d’heures au-delà duquel un pointage ouvert est considéré comme abandonné plutôt que long. La valeur par défaut est 14.
4. Activez **Déconnecter les enfants** et réglez cette heure, p. ex. 18 h 30.
5. Appuyez sur **Enregistrer la déconnexion automatique**.

[[open: #educator-rooms?tab=signoff | Ouvrir les paramètres de déconnexion automatique]]

## Le point qui compte

Un pointage oublié est fermé à l’heure que vous avez choisie **le jour où il a commencé** — jamais à l’heure à laquelle la tâche s’exécute.

Cette distinction est tout l’intérêt de la fonction. Un quart ouvert le mardi et repéré le jeudi serait autrement fermé « maintenant » et enregistrerait un quart de 48 heures, un chiffre pire que l’information manquante qu’il remplace. Le fermer le mardi à 19 h 00 enregistre quelque chose qu’un rapport de paie peut supporter.

Si un quart a commencé *après* votre heure de fermeture — un quart de soirée ou de nuit —, la règle de l’heure ne peut pas s’appliquer; c’est alors la règle « abandonner après N heures » qui le ferme.

## Qui est avisé

Rien n’est fermé en silence. Chaque fois que la déconnexion automatique ferme quelque chose, KiddieTrac envoie des avis le soir même.

### Lorsqu’un quart du personnel est fermé

- **La personne** reçoit une notification, une notification push sur son téléphone et un courriel : « Vous avez été déconnecté automatiquement ». On y indique l’heure d’arrivée, l’heure de départ automatique et le centre, on explique pourquoi c’est important (ratios et permis, paie, rapports et financement) et on lui demande d’indiquer à son directeur ou à son administrateur l’heure à laquelle elle est réellement partie.
- **Les directeurs du centre et les administrateurs d’agence** reçoivent une notification et un courriel : « Départ automatique : » suivi du nom de la personne, avec les mêmes détails et un rappel de corriger l’heure dans **Feuilles de temps**.

### Lorsque cela se répète

Si la même personne a été déconnectée automatiquement **3 fois ou plus en 7 jours**, les directeurs et les administrateurs d’agence reçoivent aussi **Oublis de pointage de départ répétés**, avec la liste des dates. On y suggère d’en discuter, puisque les rappels n’ont pas changé l’habitude. Cet avis est envoyé une fois par jour au plus.

### Lorsque des enfants sont déconnectés

- **Le personnel du centre** — éducateurs, visiteurs à domicile, directeurs — et les administrateurs d’agence reçoivent un résumé par centre. Il liste chaque enfant, l’heure de son arrivée et l’heure de départ automatique, et demande au personnel de corriger les heures dans **Présences**.
- **Chaque famille** reçoit une notification et un courriel : « Veuillez confirmer votre heure de départ ». On lui demande d’indiquer au centre l’heure à laquelle elle est réellement venue chercher l’enfant, avec le numéro de téléphone et le courriel du centre s’ils sont au dossier. On lui précise aussi que tout va bien.

Les avis respectent les paramètres de courriel de votre agence. Les personnes dont le compte est désactivé ne sont pas jointes. Si un avis ne peut pas être envoyé, la déconnexion a tout de même lieu.

## Ce qu’elle laisse derrière elle

- **Un pointage du personnel** reçoit la mention `[auto sign-off: no clock-out recorded]` ajoutée à ses notes, pour que quiconque lit la feuille de temps voie que les heures n’ont pas été saisies par une personne.
- **La journée d’un enfant** reçoit une ligne indiquant qu’aucun départ n’a été enregistré, marquée de façon que le système distingue une fermeture automatique d’une note saisie par quelqu’un.
- **La personne elle-même** voit aussi un avis à sa prochaine connexion.

Les quarts fermés automatiquement ne servent pas à calculer la « journée habituelle » d’une personne pour les rappels de pointage de départ; une habitude d’oubli n’empêche donc pas les rappels de lui parvenir.

## Ce qu’elle ne fera pas

- Elle n’invente pas d’heures. Un pointage qu’elle ne peut pas fermer de façon raisonnable est laissé à un humain.
- Elle ne corrige pas une heure erronée — elle ne fait que fermer un pointage ouvert. Si les heures enregistrées sont fausses, modifiez le pointage dans la feuille de temps.
- Elle ne s’exécute pas dans les centres d’une agence qui l’a désactivée.

## Si les heures sont erronées

La déconnexion automatique est un filet de sécurité, pas un substitut au pointage de départ. Lorsque l’heure de fermeture n’est pas la bonne, modifiez directement le pointage — la note reste, de sorte que la correction est visible plutôt que cachée. Pour un enfant, corrigez l’heure de départ dans **Présences** dès que la famille vous dit à quelle heure elle est venue le chercher.

Les éducateurs peuvent voir quand cela leur est arrivé sous **Mes heures**. Le rappel qui incite à pointer son départ avant d’en arriver là se trouve dans l’onglet **Rappels** de **Paramètres → Paramètres du pointage**. Voir [Pointeuse du personnel](time-clock).

??? Les rappels de décompte continueront-ils après les heures de travail? | Non. Les rappels de décompte cessent à la fin du quart prévu à l’horaire d’un éducateur, ou à l’heure de fermeture du centre s’il n’a pas d’horaire, même s’il est encore pointé.
??? Les parents reçoivent-ils un message à chaque fois? | Seulement lorsque leur propre enfant a été déconnecté automatiquement. Chaque famille reçoit un seul courriel pour tous ses enfants de ce centre.
