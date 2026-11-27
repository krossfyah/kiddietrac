---
title: Réservations de visites
category: Daily Operations
order: 78
---
# Réservations de visites

Les familles intéressées peuvent demander une visite du centre par l’intermédiaire de votre point d’accès public de réservation. Les visites confirmées alimentent le calendrier du personnel afin que les directeurs sachent qui vient et quand. La vue de gestion se trouve sous **Marketing → Visites**.

## Le lien public

Votre URL publique de réservation est :

    POST https://api.kiddietrac.com/api/v1/public/tours

Intégrez-la dans un formulaire personnalisé de votre site Web qui envoie `{ agency_slug, centre_id, parent_name, parent_email, parent_phone, child_age_months, preferred_start_date, tour_at, notes }`. Une future livraison v22p51 fournira une page d’accueil publique hébergée, de sorte que vous n’aurez pas à créer le formulaire vous-même.

Le point d’accès est limité à 8 requêtes par heure par adresse IP afin d’éviter le pourriel.

## La vue d’administration

L’écran Visites énumère chaque réservation avec :

- Un rail coloré à gauche selon le statut (ambre = demandée, bleu = confirmée, vert = terminée, rouge = absence, gris = annulée)
- Nom du parent + courriel + téléphone
- Date et heure de la visite
- Âge de l’enfant en mois (s’il est fourni)
- Notes en texte libre de la famille

Un menu déroulant de statut sur chaque ligne fait avancer la réservation dans le flux de travail. Chaque demande crée aussi une notification pour le directeur du centre et les administrateurs de l’agence, qui voient ainsi les nouvelles demandes dans leur boîte de réception.

## Courriels de confirmation

L’envoi d’un courriel au demandeur lors de la confirmation fait partie d’un suivi v22p50. Pour l’instant, copiez son courriel depuis la ligne et répondez manuellement.
