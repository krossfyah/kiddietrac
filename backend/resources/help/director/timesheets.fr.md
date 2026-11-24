---
title: Feuilles de temps et correction des heures
category: Staff Management
order: 31
---
# Feuilles de temps et correction des heures

**Personnel → Feuilles de temps.** Chaque quart travaillé dans vos centres sur une période, par personne,
avec le total en haut. C’est la feuille à partir de laquelle la paie est établie — les mêmes pointages que
**Paie** lit pour calculer ce qui est dû à quelqu’un — de sorte qu’une heure erronée ici devient un
montant erroné sur un bulletin de paie.

## Choisir ce que vous regardez

Trois commandes, qui s’appliquent toutes dès que vous les modifiez :

- **Centre** — un seul prestataire, ou *Tous les centres*, c’est-à-dire tous les centres que vous pouvez voir
  plutôt que tous les centres de l’agence.
- **Du** et **Au** — la période. Saisissez-les dans l’ordre que vous voulez; les dates sont inversées
  pour vous si vous les remplissez de droite à gauche. L’écran s’ouvre sur les 30 derniers jours.
- **🔄** relit la période, **⬇️** la télécharge en CSV pour votre fournisseur de paie.

## Lire une ligne

Chaque ligne est un quart : la date, qui l’a travaillé, l’arrivée, le départ, la pause et les heures.

- **Un quart ouvert** affiche un tiret long pour le départ et **0 h**. Personne n’a pointé son
  départ. Il n’est volontairement *pas* totalisé — un quart inachevé est à relancer, pas à
  payer — et il n’est volontairement pas masqué non plus.
- **« Aucune heure enregistrée »** désigne une personne affectée à votre centre qui n’a rien pointé durant
  la période. Une ligne à zéro est une question qui mérite d’être posée; une ligne absente est invisible.
- **Pause** indique 0 min pour tout le monde. La pointeuse actuelle n’enregistre pas les pauses, et les
  heures sont présentées sans ajustement plutôt que comme si une pause avait été déduite.

## Corriger un quart

Chaque ligne comporte le menu standard **⋮** à son extrémité droite. Sur un téléphone, il se trouve au
bas de la carte de la ligne.

### Modifier les heures

Ouvre le quart avec son heure d’arrivée et son heure de départ prêtes à modifier, et une zone pour le motif.

- Les heures sont celles de l’**horloge du centre**, pas de votre appareil. La boîte de dialogue le précise. Un administrateur
  qui travaille depuis un autre fuseau horaire saisit l’heure à laquelle la personne a réellement travaillé.
- Laissez le départ vide pour laisser un quart ouvert; remplissez-le pour fermer un quart
  qui n’a jamais été pointé à la sortie.
- Le motif est facultatif ici et mérite d’être écrit quand même — il est inscrit sur le quart
  lui-même, de sorte que la prochaine personne qui lit la ligne peut voir pourquoi elle a changé.

La modification est refusée, avec la raison à l’écran, si le départ n’est pas postérieur à
l’arrivée ou si le quart dépasse 24 heures. Un quart aussi long correspond presque toujours à une
mauvaise date à l’une des extrémités, et le payer en silence est pire que le refuser.

### Ajouter un quart

Pour le quart qui n’a jamais été pointé : une tablette éteinte, un oubli, quelqu’un qui remplace
à la dernière minute. Disponible sur chaque ligne, car un quart fractionné est un second pointage pour
quelqu’un qui en a déjà un, et c’est la **seule** action offerte sur une ligne « Aucune heure enregistrée ».

- **Centre** indique où le quart est classé, et donc quelle paie le règle. Cela
  compte pour toute personne affectée à plusieurs sites; ce n’est pas deviné à votre place.
- Un **motif est obligatoire**. Un quart saisi à la main est une déclaration d’heures pour lesquelles quelqu’un
  sera payé, et la note du quart indique qui l’a saisi et pourquoi.
- Un chevauchement avec un quart existant est refusé — il compterait les heures en double.

### Supprimer le quart

Pour un quart qui n’a pas eu lieu : un double pointage, une arrivée pointée sur la tablette de quelqu’un d’autre, une
entrée saisie au mauvais nom. La modification répond à « ces heures sont erronées »; ceci répond à
« ceci n’est pas réel ».

Un motif est demandé et l’action est irréversible. Le journal d’audit conserve tout le quart supprimé —
les heures qu’il contenait et ses notes — car, une fois supprimé, il n’y a plus rien d’autre à lire.

## Qui peut le faire

Un directeur ou un administrateur d’agence, pour le centre où le quart a été **travaillé**. C’est le centre
inscrit sur le pointage lui-même, et non celui où la fiche du membre du personnel est classée en premier — donc pour une personne affectée à plusieurs sites, le directeur du site où elle a
réellement travaillé est celui qui peut corriger.

## Ce qui est enregistré

Chaque correction, saisie et suppression écrit une ligne d’audit indiquant la personne, les heures
avant et après, et qui a fait le changement. Vous les trouverez dans
**Administration → Journal d’audit** sous *Heures du personnel corrigées*, *Quart du personnel saisi à la main*
et *Quart du personnel supprimé*. Le quart porte aussi une courte note, de sorte que la ligne de la
feuille de temps indique qu’elle a été corrigée sans que personne ait à aller vérifier.

## Bon à savoir

- Corriger un pointage ici est la même correction que celle de la fiche utilisateur de la personne, écrite au même endroit — il y a une seule histoire pour un quart, pas deux.
- Un éducateur ne peut pas modifier ses propres heures. Sa pointeuse ne fait que basculer, c’est pourquoi un quart
  laissé ouvert pendant la nuit ne peut être corrigé que d’ici.
- Si quelqu’un vous dit qu’il a oublié de pointer son arrivée, c’est l’écran où le corriger.

Voir aussi : [Pointeuse du personnel](time-clock), [Exécutions de paie, heures et bulletins](payroll-documents),
[Exportation CSV prête pour la paie](payroll-csv), [Déconnexion automatique](auto-sign-off).
