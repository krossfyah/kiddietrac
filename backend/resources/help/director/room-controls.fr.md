---
title: Gestion des salles
---
# Gestion des salles

**Gestion des salles** regroupe les paramètres qui façonnent la journée d’une salle : qui
travaille où, à quelle fréquence on compte les enfants, les vérifications du sommeil, les
rappels des registres quotidiens, la sortie automatique de nuit et la détection des
promenades. Les administrateurs de l’agence et les directeurs de centre l’ouvrent dans le
menu latéral : **Paramètres** → **Gestion des salles**.

[[open: #educator-rooms | Ouvrir la gestion des salles]]

## Les six onglets

| Onglet | Ce qu’il règle | Qui peut le changer |
|---|---|---|
| **🚪 Affectations de salle** | Les salles où travaille chaque personne | Directeurs et administrateurs de l’agence |
| **👀 Comptages** | Les rappels de comptage réguliers, et qui les reçoit | Administrateurs de l’agence |
| **😴 Vérifications du sommeil** | Votre politique de sommeil : intervalles de vérification et alertes | Administrateurs de l’agence |
| **📝 Registres quotidiens** | Le rappel du soir aux éducateurs sur les registres manquants | Administrateurs de l’agence |
| **⏱️ Déconnexion automatique** | La fermeture des quarts et des journées d’enfants que personne n’a fermés | Directeurs et administrateurs de l’agence |
| **🚶 Promenades** | La détection Walk Watch en arrière-plan sur Android | Administrateurs de l’agence |

Là où seuls les administrateurs de l’agence peuvent faire des changements, les directeurs
voient quand même les paramètres, grisés, ou une note indiquant **Only agency administrators can change this.**

## Affecter des salles à une personne

1. Ouvrez l’onglet **🚪 Affectations de salle**.
2. Choisissez la personne dans la liste **Éducateur**. Ses salles s’affichent, en lecture seule.
3. Appuyez sur **✏️ Modifier les affectations**. Une barre **✏️ Modification** apparaît : « Cochez une salle pour l’affecter. Rien n’est enregistré avant que vous appuyiez sur Enregistrer. »
4. Cochez les salles où elle travaille, et décochez celles où elle ne travaille plus.
5. Appuyez sur **Enregistrer les modifications**, ou sur **Annuler** pour tout remettre comme avant.

Cela détermine les enfants que la personne voit, et permet d’attribuer les présences et les
départs à une personne plutôt qu’à tout le centre. Une bannière compte les personnes sans
salle et les salles sans personne. Voir [Affectations de salle et rotations des salles](room-assignments).

## Activer les rappels de comptage

1. Ouvrez l’onglet **👀 Comptages**.
2. Cochez **Remind educators after** et choisissez un délai de 15 à 120 minutes. Le changement s’enregistre dès que vous le faites.
3. Sous **Qui reçoit les rappels de comptage**, décochez un centre ou une personne pour arrêter ses rappels. Elle peut quand même faire des comptages.

Quand une salle où des enfants sont pointés passe ce délai sans comptage, les éducateurs
pointés dans cette salle reçoivent une notification dans la cloche et une notification push.
Les rappels sont désactivés par défaut. Ils s’arrêtent à la fin du quart prévu de l’éducateur
ce jour-là, ou à l’heure de fermeture du centre s’il n’y a pas d’horaire : une personne qui
reste pointée ne reçoit pas de rappels toute la soirée.
Voir [Comptages et superviseur par intérim](headcounts-and-acting-supervisor).

## Vérifications du sommeil

1. Ouvrez l’onglet **😴 Vérifications du sommeil**.
2. Réglez la fréquence de vérification des enfants endormis de moins de 12 mois et de 12 à 24 mois.
3. Choisissez si le directeur est avisé d’une vérification en retard, et le délai de grâce avant qu’il le soit. Les centres qui ont leur propre politique peuvent avoir leurs propres intervalles.
4. Appuyez sur **Enregistrer**.

Voir [Vérifications du sommeil, politique et rapport pour l’inspecteur](sleep-checks).

## Registres quotidiens

Chaque soir, les éducateurs qui ont travaillé ce jour-là reçoivent un courriel sur tout ce qui
n’a pas été consigné : observations, registres quotidiens, photos et vidéos, repas, ainsi que
le plan de leçons et le menu de la semaine. Dans l’onglet **📝 Registres quotidiens**, vous
réglez **Ce que les éducateurs doivent consigner chaque jour**, le moment de l’envoi et les
**Copies et escalade**, puis vous appuyez sur **Enregistrer**. **Rappels envoyés — 14 derniers jours**
montre ce qui a été envoyé. Voir [Rappels des registres quotidiens](daily-records-reminders).

## Déconnexion automatique

1. Ouvrez l’onglet **⏱️ Déconnexion automatique**.
2. Activez **Fermer les quarts du personnel** et réglez l’heure.
3. Réglez **Abandonner un quart après** (14 heures par défaut).
4. Activez **Faire sortir les enfants** et réglez cette heure.
5. Appuyez sur **Enregistrer la sortie automatique**.

C’est le même réglage que **Settings → Clock settings → Auto sign-off**. Le changer à un
endroit le change aux deux. Voir [Sortie automatique](auto-sign-off).

## Promenades

Dans l’onglet **🚶 Promenades**, un administrateur de l’agence peut cocher **Detect walks in the background (Android app)** et appuyer sur **Enregistrer**. KiddieTrac propose toujours de commencer ou de terminer une promenade quand l’application est ouverte; ce réglage permet à l’application Android de remarquer une promenade même avec le téléphone dans une poche. Android demande à chaque éducateur d’autoriser la localisation « tout le temps » et affiche une **notification de position permanente** pendant que la détection fonctionne. Elle est désactivée par défaut, et un centre sans position sur la carte (vérifiez son adresse) est indiqué comme désactivé.

## Qui peut faire quoi

- Les **administrateurs de l’agence** peuvent changer tous les onglets.
- Les **directeurs de centre** peuvent changer les affectations de salle et la sortie automatique de leurs centres, et voient les autres onglets en lecture seule.
- Les **éducateurs** n’ouvrent pas la gestion des salles. Ils en voient l’effet : les enfants de leurs salles, les rappels de comptage et le courriel du soir sur les registres.

## FAQ et dépannage

??? Où est passé le réglage des rappels de comptage ? | Il a été déplacé du bas de la page des comptages vers Paramètres → Gestion des salles → Comptages. La page des comptages indique si les rappels sont activés, avec un lien **Change in Room Controls →** vers ici.
??? Où sont passés les réglages des vérifications du sommeil ? | Ils ont été déplacés de Paramètres → Notifications vers Paramètres → Gestion des salles → Vérifications du sommeil.
??? La gestion des salles est-elle l’ancien écran des affectations de salle ? | Oui. Les affectations de salle sont maintenant le premier onglet de la gestion des salles, et fonctionnent de la même façon.
??? Un onglet indique seulement « Only agency administrators can change this. » | Ce réglage appartient à l’agence. Demandez à un administrateur de l’agence de le changer.
??? On me demande si je veux abandonner les modifications non enregistrées quand je choisis une autre personne. | Vous modifiiez les salles d’une personne sans enregistrer. Choisissez de continuer et appuyez sur Enregistrer les modifications, ou abandonnez-les.
??? Le message « This agency has no rooms yet. » s’affiche. | Ajoutez d’abord des salles à un centre (Centres), puis revenez affecter les personnes.

## Liens utiles

[[open: #educator-rooms | Gestion des salles]]
[[open: #headcounts | Comptages]]

- [Affectations de salle et rotations des salles](room-assignments)
- [Comptages et superviseur par intérim](headcounts-and-acting-supervisor)
- [Vérifications du sommeil, politique et rapport pour l’inspecteur](sleep-checks)
- [Rappels des registres quotidiens](daily-records-reminders)
- [Sortie automatique](auto-sign-off)
- [Walk Watch — promenades et sorties suivies par GPS en direct](field-trip-gps)
