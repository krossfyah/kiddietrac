---
title: Boîte de notifications
category: Settings
order: 67
---
# Boîte de notifications

Toutes les alertes de KiddieTrac pour votre compte au même endroit. Vous la trouverez sous **Paramètres → 🔔 Notifications** dans la barre latérale (tous les rôles).

## Ce qui s’y affiche

Tout ce qui déclenche `DB::table('notifications')->insert()` côté serveur :

- Nouveaux messages de discussion (quand vous avez manqué la notification du système)
- Campagnes de marketing qui vous sont envoyées
- Paiements enregistrés sur les factures de votre famille
- Frais de retard ajoutés
- Réservations de visites (vue directeur / administrateur)
- Accusés d’envoi des résumés

## Filtrer

Trois options en haut :

- **Tous** — tout, les éléments lus les plus anciens mêlés aux non lus
- **Non lus seulement** — ce qui demande encore de l’attention
- **Lecture seule** — vue historique

## Marquer comme lu, et comme non lu

Trois façons :

- **Cliquer sur une ligne** — la marque comme lue en vous menant à ce qu’elle désigne
- **Le menu ⋮ de la ligne** — **Marquer comme lu** ou **Marquer comme non lu**, ainsi que **Supprimer**
- **Tout marquer comme lu** — demande d’abord confirmation, puis efface toutes les lignes non lues

Marquer un élément comme **non lu** permet de revenir en arrière si vous avez ouvert une notification par accident.
Jusqu’à récemment, ce n’était pas possible : une ligne touchée par erreur sortait du filtre des non lus
et du compteur de la cloche pour de bon.

## Suppression

Utilisez **Supprimer** dans le menu ⋮ de la ligne. Une confirmation est demandée, car la suppression d’une notification
est irréversible. Pour en effacer plusieurs à la fois, utilisez **Sélectionner** et cochez les lignes.

## Liens directs

Les notifications portent un `data.url`; ainsi, cliquer sur une notification de discussion vous mène à `#chat`, une facture à `#billing`, une visite à `#tours`, etc.

## Signaux visuels

- **Point bleu** sur le bord gauche = non lu
- **Fond bleu pâle** = non lu
- **Icône emoji selon le type** : 💬 discussion, 🧾 facture, ✅ paiement, 📢 annonce, 📅 résumé, 📣 marketing, 🚪 visite, 📝 formulaire
