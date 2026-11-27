---
title: RH : vérification du lieu au pointage et alerte d'heures supplémentaires
category: Staff Management
order: 67
roles: agency_admin, platform_admin, centre_director, educator, home_visitor
---
# RH : vérification du lieu au pointage et alerte d'heures supplémentaires

Deux règles de pointage facultatives, incluses dans le module RH de KiddieTrac. Un administrateur de l'agence les règle dans **RH > Horaire prévu > Règles de pointage**.

## Vérification du lieu au pointage d'arrivée

Tracez une zone autour de chaque centre ou service de garde en milieu familial : un rayon en mètres autour de l'adresse, ou autour d'un point choisi sur la carte. Choisissez ensuite la règle :

- **Désactivée** : on ne demande jamais le lieu.
- **Permettre et signaler** : les pointages loin du centre, ou sans lieu, fonctionnent quand même. Ils sont listés pour la direction et l'administration.
- **Bloquer** : les pointages loin du centre, ou sans lieu, sont refusés avec une raison claire. Une direction peut quand même pointer la personne.

Le lieu n'est demandé que lorsqu'une personne pointe son arrivée dans un centre qui a une zone. Avant que l'appareil ne le partage, la personne voit pourquoi on le demande. KiddieTrac ne garde que « au centre » ou « loin » et la distance, jamais le lieu lui-même. Personne n'est suivi, et le pointage de départ n'est jamais bloqué. Le kiosque n'est pas vérifié.

## Alerte d'heures supplémentaires

Indiquez le nombre d'heures par semaine à partir duquel commencent les heures supplémentaires (44 par défaut, le seuil des normes d'emploi de l'Ontario) et le moment de l'alerte (40 par défaut). Quand les heures travaillées cette semaine atteignent l'alerte, puis les heures supplémentaires, la personne et la direction de son centre reçoivent un avis. Chaque avis n'est envoyé qu'une fois par semaine. Les entrepreneurs sont exclus, sauf si vous désactivez cette option.

## Ce que voit la direction

**RH > Horaire prévu** liste les pointages de la semaine faits loin du centre, et les personnes proches des heures supplémentaires ou qui les dépassent, pour vos propres centres.
