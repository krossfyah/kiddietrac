---
title: Pointeuse du personnel
---
# Pointeuse du personnel

La **Pointeuse** sert à pointer votre arrivée au début du quart, à sortir pour le dîner ou une pause, et à pointer votre départ à la fin. Chaque pointage alimente les heures que votre agence voit dans les feuilles de temps et la paie. Les directions l'utilisent pour leurs propres quarts; pour voir et corriger les heures des autres, utilisez **Clock-ins & timesheets**.

[[open: #time-clock | Ouvrir la Pointeuse]]

La carte au centre indique où vous en êtes : « You're currently clocked out. », « You're clocked in since » suivi d'une heure et des heures écoulées, ou « You're on lunch » / « You're on a break ».

## Pointer l'arrivée

1. Ouvrez **Operations > Pointeuse**.
2. Appuyez sur le bouton vert **Pointer l'arrivée**.
3. Si votre agence demande votre position, la fenêtre « Share your location to clock in » s'ouvre. Appuyez sur **Share location** pour continuer.
4. Le bouton devient rouge et affiche **Pointer le départ**, et la carte indique l'heure de votre arrivée.

## Prendre le dîner ou une courte pause

Pendant que vous êtes pointé, deux boutons apparaissent sous le grand bouton.

1. Appuyez sur **🍽️ Dîner** ou **☕ Short break** quand vous quittez le plancher. Vous restez sur le quart du jour.
2. Le grand bouton devient mauve et affiche **I'm back**, et la carte indique depuis quand vous êtes absent.
3. À votre retour, appuyez sur **I'm back**.
4. Confirmez **Yes, I’m back** dans la fenêtre qui demande « Are you back from lunch? » (ou « from your break? »). Appuyez sur **Still on lunch** ou **Still on break** si vous avez appuyé par erreur.

## Pointer le départ

1. Appuyez sur le bouton rouge **Pointer le départ**.
2. Si des enfants que vous avez accueillis sont encore inscrits comme présents, une fenêtre les énumère et explique quoi faire. Faites d'abord leur départ, ou appuyez sur **Go ahead anyway** si quelqu'un d'autre s'en occupe.
3. Le bouton redevient vert et le quart apparaît dans **Pointages récents** avec ses heures.

## Lire vos pointages récents

**Pointages récents** liste vos quarts, du plus récent au plus ancien. Chaque rangée affiche la date, les heures d'arrivée et de départ, « lunch » ou « break » quand c'est la raison de la sortie, et le nombre d'heures. Un point vert est un quart terminé; un point ambre avec **In progress** est le quart en cours.

> 💡 Vous avez oublié de pointer? Vous ne pouvez pas modifier un pointage ici. Demandez à votre direction ou à votre administrateur d'agence de le corriger dans **Clock-ins & timesheets**, ou consultez [Feuilles de temps et correction des heures](timesheets).

## Rappels et fin de journée automatique

**Paramètres > Paramètres du pointage** (administrateurs d'agence) regroupe les deux volets de la pointeuse :

- **Reminders** : quand avertir quelqu'un qui n'a pas pointé son arrivée, et quelqu'un encore pointé. Un centre qui ouvre à 6 h peut recevoir ses rappels plus tôt que les autres.
- **Auto sign-off** : ferme les quarts et les journées d'enfants que quelqu'un a oublié de fermer. Le même réglage se trouve dans **Settings > Room Controls > Auto sign-off**.

Les rappels sont vérifiés une fois par heure; ils arrivent donc pendant l'heure choisie plutôt qu'à la minute près, selon le fuseau horaire de votre agence.

### Qui reçoit le rappel « encore pointé »

À l'heure réglée pour **Remind people still on the clock**, KiddieTrac regarde chaque quart encore ouvert, y compris un quart laissé ouvert un jour précédent (jusqu'à 14 jours). Une personne reçoit un rappel quand son quart prévu est terminé (si elle en a un) et qu'elle est pointée depuis plus longtemps que sa journée habituelle plus une heure. Sans historique, c'est 6 heures. Ce n'est jamais moins de 5 heures ni plus de 8 heures, pour que le rappel arrive toujours avant la fin de journée automatique de la nuit. Les quarts fermés automatiquement ne comptent pas dans le calcul de la journée habituelle, et personne ne reçoit de rappel un jour de congé approuvé.

À la même heure, les éducatrices sont averties des enfants encore présents : la personne qui a fait l'arrivée de chaque enfant, ou les éducatrices de la salle quand c'est un parent qui l'a faite. Le rappel énumère les enfants et, si la fin de journée automatique des enfants est activée, indique à quelle heure ils seront sortis automatiquement. Il n'est envoyé que si **Remind people still on the clock** est activé.

Les interrupteurs **Send by email** et **Send to the app** de l’onglet **Reminders** s’appliquent aux deux rappels. La notification dans l'application est toujours ajoutée.

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Direction de centre | Pointer, prendre le dîner et les pauses, voir ses propres pointages. Corriger les quarts des autres dans **Clock-ins & timesheets**. |
| Administrateur d'agence | La même chose, plus **Paramètres du pointage** pour toute l'agence. |
| Éducatrice | Pointer et voir ses propres pointages seulement. |

## FAQ et dépannage

??? Le bouton affiche « Could not update the clock. » | Le pointage n'a pas été enregistré. Vérifiez votre connexion et appuyez de nouveau. Vos pointages récents montrent s'il est passé.
??? J'ai laissé un quart ouvert toute la nuit. | La pointeuse ne fait qu'alterner entre arrivée et départ. Pointez votre départ maintenant, puis demandez à une direction ou à un administrateur d'agence de corriger l'heure de départ dans **Clock-ins & timesheets**.
??? Pourquoi m'a-t-on parlé d'enfants encore présents? | C'est vous qui avez fait leur arrivée et leur départ n'a pas été fait. Faites leur départ à l'écran des présences, ou appuyez sur **Go ahead anyway** si une autre éducatrice les a pris en charge.
??? On me demande sans cesse ma position. | Votre agence utilise la zone de pointage du module RH. Autorisez la localisation pour le site dans votre navigateur ou l'application, et pointez depuis le centre.
??? Comment sait-on d'où j'ai pointé? | Chaque pointage enregistre s'il vient du Web, d'un kiosque ou de l'application mobile, pour que les gestionnaires sachent comment le quart a été pointé.

## Voir aussi

[[open: #timesheets | Clock-ins & timesheets]]

- [Feuilles de temps et correction des heures](timesheets)
- [Fin de journée automatique](auto-sign-off)
- [[hr-clock]]
- [[time-clock-settings]]
