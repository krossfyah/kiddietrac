---
title: Gestion des salles
category: Staff Management
order: 29
---
# Gestion des salles

**Gestion des salles** est l’endroit unique pour les paramètres qui façonnent la journée d’une salle : qui travaille
où, à quelle fréquence les enfants sont comptés, les vérifications du sommeil, les rappels des registres quotidiens et la
déconnexion automatique de la nuit.

Vous la trouverez dans la barre latérale sous **Paramètres → Gestion des salles**. Les administrateurs de l’agence et les directeurs de
centre peuvent l’ouvrir.

[[open: #educator-rooms | Ouvrir Gestion des salles]]

## Les cinq onglets

| Onglet | Ce qu’il règle | Qui peut le modifier |
|---|---|---|
| **🚪 Affectations de salle** | Les salles où travaille chaque personne | Directeurs et administrateurs de l’agence |
| **👀 Comptages** | Rappels de comptage de routine | Administrateurs de l’agence |
| **😴 Vérifications du sommeil** | Votre politique de sommeil : intervalles de vérification et alertes | Administrateurs de l’agence |
| **📝 Registres quotidiens** | Le rappel du soir aux éducateurs au sujet des registres manquants | Administrateurs de l’agence |
| **⏱️ Déconnexion automatique** | Fermeture des quarts et des journées d’enfants que personne n’a fermées | Directeurs et administrateurs de l’agence |

Lorsque seuls les administrateurs de l’agence peuvent faire des changements, les directeurs voient quand même les paramètres, grisés,
avec une note qui l’indique.

## Affectations de salle

Choisissez une personne, appuyez sur **Modifier**, cochez ses salles et appuyez sur **Enregistrer**. Cela détermine quels
enfants elle voit, et permet d’imputer les chiffres de présence et de départ à une personne
plutôt qu’à tout le centre. Voir [Affecter des éducateurs aux salles](room-assignments).

## Comptages

1. Cochez **Rappeler aux éducateurs après** et choisissez un délai, de 15 à 120 minutes.
2. Le changement est enregistré dès que vous le faites.

Lorsqu’une salle où des enfants sont arrivés reste aussi longtemps sans comptage, les éducateurs
pointés dans cette salle reçoivent une cloche et une notification push. Cela s’applique à chaque centre de
l’agence, et c’est désactivé par défaut.

Les rappels s’arrêtent à la fin du quart prévu de l’éducateur ce jour-là. Sans horaire, ils
s’arrêtent à l’heure de fermeture du centre. Une personne qui reste pointée après le travail n’est pas
rappelée toute la soirée.

Voir [Comptages et superviseur par intérim](headcounts-and-acting-supervisor).

## Vérifications du sommeil

Réglez la fréquence des vérifications des enfants qui dorment, de moins de 12 mois et de 12 à 24 mois,
si le directeur est avisé d’une vérification en retard, et le délai de grâce avant qu’il le soit. Les centres qui ont leur propre politique peuvent avoir leurs propres intervalles. Appuyez sur **Enregistrer**. Voir
[Vérifications du sommeil, politique et rapport de l’inspecteur](sleep-checks).

## Registres quotidiens

Chaque soir, les éducateurs qui ont travaillé ce jour-là reçoivent un courriel au sujet de tout ce qui n’a pas été consigné :
observations, journaux quotidiens, photos et vidéos, repas, ainsi que le plan de leçons et le menu de la semaine.
Leur directeur et les administrateurs de l’agence sont en copie. Vous réglez ce qui est attendu, le moment de l’envoi
et celui de l’escalade. Voir [Rappels des registres quotidiens](daily-records-reminders).

## Déconnexion automatique

1. Activez **Fermer les quarts du personnel** et réglez l’heure.
2. Réglez **Abandonner un quart après** (14 heures par défaut).
3. Activez **Enregistrer le départ des enfants** et réglez cette heure.
4. Appuyez sur **Enregistrer la déconnexion automatique**.

Il s’agit du même paramètre que **Paramètres → Paramètres du pointage → Déconnexion automatique**. Le modifier à
l’un ou l’autre endroit le modifie aux deux. Voir [Déconnexion automatique](auto-sign-off).

> **Astuce :** Sur la page Comptages, les administrateurs de l’agence voient si les rappels sont activés et un lien **Modifier dans Gestion des salles →** qui ouvre l’onglet Comptages.

??? Où est passé le paramètre des rappels de comptage? | Il est passé du bas de la page Comptages à Paramètres → Gestion des salles → Comptages. La page Comptages indique maintenant si les rappels sont activés, avec un lien vers cet endroit.
??? Où sont passés les paramètres des vérifications du sommeil? | Ils sont passés de Paramètres → Notifications à Paramètres → Gestion des salles → Vérifications du sommeil.
??? Gestion des salles est-il l’ancien écran Affectations de salle? | Oui. Affectations de salle est maintenant le premier onglet de Gestion des salles, et il fonctionne de la même façon.
