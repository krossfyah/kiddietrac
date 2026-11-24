---
roles: agency_admin, platform_admin
title: Fil de partage de vidéos
category: Family Engagement
order: 22
---
# Fil vidéo

Comme les photos, mais pour de courtes vidéos — même fonctionnement pour le téléversement, le marquage et les réactions.

## Personnel : téléverser

1. Barre latérale → **Fil vidéo**.
2. Touchez **+ Téléverser une vidéo**.
3. Choisissez un fichier MP4 / MOV / WebM (64 Mo max. après l’augmentation de la limite PHP de v22p60).
4. Ajoutez au besoin une légende et marquez les enfants qui apparaissent.
5. Téléversez.

## Vue du parent

- Barre latérale → **Vidéos** sous Votre enfant.
- Voyez les vidéos où figurent vos enfants, de la plus récente à la plus ancienne.
- Touchez pour lire; touchez les boutons de réaction (❤️ 😊 😮 🎉 👏) pour réagir.
- La légende, le nom de la personne qui a téléversé et la date s’affichent sous chaque vidéo.

## Réactions

Même système de réactions que pour les photos. Les parents touchent un bouton emoji pour accuser réception ou remercier. Le personnel voit le nombre de chaque réaction sous chaque vidéo.

## Limites

- 64 Mo max. par vidéo (PHP `upload_max_filesize`)
- Durée max. de 10 minutes par vidéo (champ `duration_seconds`, appliqué côté client)
- Fichiers stockés dans `/storage/videos/YYYY/MM/`

## Quand utiliser des vidéos

- Premiers pas / premiers mots
- Spectacles de programmes spéciaux (récital, présentation)
- Événements pour toute la famille (défilé, arrivée d’une sortie éducative)
- Montrer un moment d’apprentissage en action

Pour les moments figés, les **photos** restent le meilleur choix : elles se téléchargent plus vite, se partagent plus facilement et occupent moins d’espace de stockage.
