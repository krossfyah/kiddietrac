---
title: Pointeuse du personnel
category: Daily Operations
order: 76
---
# Pointeuse du personnel

Chaque membre du personnel pointe son arrivée au début de son quart et son départ à la fin. Vous la trouverez sous **Personnel → Pointeuse** dans la barre latérale.

## Pointer

L’écran affiche un seul gros bouton :

- **« Pointer l’arrivée » en vert** lorsque vous n’êtes pas en quart
- **« Pointer le départ » en rouge** lorsque vous l’êtes, avec les heures écoulées affichées

Touchez-le. C’est tout le processus.

## Pointages récents

La liste sous le bouton affiche vos 60 derniers pointages avec les heures d’arrivée et de départ et le total d’heures. Un quart ouvert (en cours) affiche « — en cours — » au lieu d’une durée.

## Vue du gestionnaire

Les directeurs et les administrateurs d’agence voient les pointages de chaque membre du personnel de leur centre dans
**Feuilles de temps**, qui regroupe les entrées de la pointeuse et les éventuels quarts saisis manuellement.

Chaque ligne y comporte un menu **⋮** : corriger un quart, en ajouter un qui n’a jamais été pointé, ou
supprimer un quart qui n’a pas eu lieu. La pointeuse d’un éducateur ne fait que basculer; un quart laissé ouvert
pendant la nuit ne peut donc être corrigé que là. Voir [Feuilles de temps et correction des heures](timesheets).

## Champ source

Chaque pointage enregistre sa provenance (web, borne ou mobile), ce qui vous permet de savoir comment un quart a été pointé.

## Paramètres de rappel et de fermeture automatique

**Paramètres → Paramètres du pointage** regroupe les deux volets de la pointeuse :

- **Rappels** — quand relancer une personne qui n’a pas pointé son arrivée, et une personne encore
  en quart. Réglez les heures selon votre journée; un centre qui ouvre à 6 h ne devrait pas recevoir
  le rappel à 10 h comme tout le monde.
- **Déconnexion automatique** — ferme les quarts et les journées d’enfants que quelqu’un a oublié de fermer.
  Le même réglage se trouve aussi sous **Paramètres → Gestion des salles → Déconnexion automatique**.

Les rappels sont vérifiés une fois par heure; ils partent donc pendant l’heure choisie plutôt qu’à une
minute précise. Les deux sont réglés par agence, dans votre propre fuseau horaire.

## Qui reçoit le rappel « encore en quart »

À l’heure que vous avez fixée pour **Rappeler aux personnes encore en quart**, KiddieTrac examine chaque
quart encore ouvert, y compris un quart resté ouvert un jour antérieur (jusqu’à 14 jours en arrière). Une
personne reçoit un rappel lorsque :

- son quart prévu à l’horaire pour la journée est terminé, si elle en a un; et
- elle est en quart depuis plus longtemps que sa journée habituelle plus une heure. Sans historique,
  cela donne 6 heures. Ce n’est jamais moins de 5 heures et **jamais plus de 8 heures**, de sorte que le
  rappel arrive toujours avant la déconnexion automatique de nuit.

Les quarts fermés par la déconnexion automatique sont exclus du calcul de la journée habituelle d’une personne.
Sans cela, une personne qui oublie souvent semblerait travailler des journées de 12 heures et ne
recevrait jamais de rappel.

Personne ne reçoit de rappel un jour où un congé est approuvé.

## Enfants encore présents

À la même heure, les éducateurs sont aussi avisés des **enfants encore présents**. Le rappel
est envoyé à la personne qui a enregistré l’arrivée de chaque enfant. Si c’est un parent qui l’a fait, il va
aux éducateurs affectés à la salle de l’enfant. Il énumère les enfants, demande à l’éducateur
d’enregistrer leur départ au moment du départ, et, si la déconnexion automatique des enfants est activée, indique à quelle heure
ils seront déconnectés automatiquement.

Ce rappel n’est envoyé que si **Rappeler aux personnes encore en quart** est activé.

## Commutateurs courriel et notification push

Les commutateurs **Envoyer par courriel** et **Envoyer à l’application** de l’onglet **Rappels** s’appliquent aux
deux rappels. Si vous désactivez le courriel, seuls la notification et le push sont envoyés; si vous désactivez le push,
seuls la notification et le courriel sont envoyés. La notification dans l’application est toujours ajoutée.

Voir aussi : [Déconnexion automatique](auto-sign-off), [Feuilles de temps et correction des heures](timesheets).
