---
roles: agency_admin, platform_admin
title: Ajouter une nouvelle agence
category: Administration
order: 5
---
# Ajouter une nouvelle agence

Ajouter une agence cliente est une action d’**administrateur de plateforme**. Elle se fait dans un court **assistant en 4 étapes** qui prépare d’un seul coup tout ce dont l’agence a besoin : l’agence, un premier centre et le premier accès administrateur.

## Par où commencer

1. En bas à gauche, cliquez sur le **sélecteur d’agence** (la pastille qui indique l’agence actuelle) et choisissez **Toutes les agences** — ou utilisez **Plateforme → Toutes les agences**.
2. Cliquez sur **+ Créer une agence** (en haut à droite). L’assistant s’ouvre.

## Les 4 étapes (tout est obligatoire)

1. **Plan et logo**
   - **Plan** — choisissez **Starter (49 $)**, **Growth (149 $)** ou **Enterprise (349 $)**. Growth et Enterprise **incluent la marque blanche** (l’administrateur de l’agence configure son logo et ses couleurs à sa première connexion).
   - **Logo de l’agence** — téléversez un PNG/SVG (2 Mo max.).
   - **Couleur principale**.
2. **Détails de l’agence** — nom de l’agence, adresse (ligne 1, ville, province, code postal), **pays de résidence** (détermine la devise et la conformité de l’agence), **langue par défaut**, courriel de contact et téléphone de contact.
3. **Administrateur** — prénom, nom, courriel et téléphone du premier administrateur de l’agence.
4. **Révision** — vérifiez tout, puis **Créer l’agence**.

## Ce qui se passe à la création

- L’agence est créée avec un **essai de 30 jours** et un **centre par défaut** (portant le nom de l’agence et utilisant l’adresse — renommez-le ou ajoutez-en d’autres sous **Centres**).
- L’administrateur est créé et reçoit par **courriel une invitation** à définir son mot de passe. (L’écran de confirmation affiche aussi le lien d’invitation au cas où le courriel serait retardé.)
- Si le plan inclut la marque blanche, l’image de marque est activée automatiquement — l’administrateur termine son logo, ses couleurs et ses liens de confidentialité à sa première connexion sous **Image de marque**.

## Si l’administrateur n’a pas reçu le courriel d’invitation

La livraison des courriels dépend du DNS de votre domaine (SPF/DKIM); une invitation peut donc se retrouver dans les indésirables ou ne pas arriver. Sur l’écran **Toutes les agences** :

- Cliquez sur **✉ Renvoyer** sur la ligne de l’agence pour renvoyer l’invitation — une boîte de dialogue affiche le **lien de définition du mot de passe** que vous pouvez copier et envoyer directement; l’intégration ne dépend ainsi jamais de la livraison des courriels.
- Cliquez sur **📧 Journal des courriels** (en haut de l’écran) pour voir **tous les courriels envoyés par le système** — le destinataire, l’objet et le moment. (Cela confirme l’envoi; cela ne peut pas confirmer que la boîte de réception du destinataire l’a accepté.)

> Qui peut l’utiliser : **Administrateur de plateforme**.
