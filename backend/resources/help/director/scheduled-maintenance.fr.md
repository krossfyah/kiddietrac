---
title: Maintenance planifiée et mises à jour des applis
---
# Maintenance planifiée et mises à jour des applis

**Platform › Maintenance** met le portail hors ligne pendant une période prévue, envoie un avis par courriel à tout le monde et fixe quelles versions des applis mobiles doivent être mises à jour. Pendant la maintenance, personne ne peut se connecter sauf les administrateurs de la plateforme. Ouvrez-le avant une mise à niveau ou un déménagement prévu, et après chaque nouvelle version d'appli.

[[open: #maintenance | Ouvrir Maintenance]]

## Planifier une période de maintenance

1. Ouvrez **Platform › 🛠️ Maintenance**.
2. Remplissez **Starts** et **Ends** avec la date et l'heure de la période. Laissez les deux vides pour bloquer la connexion dès l'enregistrement, jusqu'à ce que vous désactiviez le mode.
3. Tapez le **Message shown to users** (par exemple l'heure prévue du retour). Il s'affiche à la page de connexion.
4. Activez **Maintenance mode**.
5. Appuyez sur **Save**. Vous voyez **✓ Saved.**, ou **✓ Saved — maintenance is ACTIVE now.** si la période est déjà commencée.

> ⚠️ Quand **Maintenance mode** est activé **et** que l'heure est dans la période, la connexion est bloquée pour tous sauf les administrateurs de la plateforme. Une bannière rouge, **● Maintenance is ACTIVE right now**, s'affiche en haut de cet écran pendant ce temps.

## Envoyer l'avis par courriel

1. Enregistrez d'abord la période : le courriel utilise ses dates et son message.
2. Sous **📧 Heads-up email**, vérifiez l'adresse dans la case (ce doit être la vôtre) et appuyez sur **Send test**. Lisez le courriel de test.
3. Quand il est correct, appuyez sur **Email all users** et confirmez **Email the maintenance notice to ALL users right now?**
4. La ligne sous les boutons indique à combien d'adresses il a été envoyé. **All-user notice last sent …** affiche la date ensuite.

> ⚠️ **Email all users** va à chaque utilisateur qui a une adresse courriel sur la plateforme, dans toutes les agences, sans tenir compte des réglages de courriel des agences, parce qu'un avis d'interruption est opérationnel. Envoyez-le une seule fois.

## Terminer la maintenance

1. Désactivez **Maintenance mode**.
2. Appuyez sur **Save**. La connexion refonctionne tout de suite.

Si vous avez indiqué une heure **Ends**, la connexion revient aussi d'elle-même à la fin de la période.

## Demander aux applis Android de se mettre à jour

La carte **📱 Mobile app updates (Google Play)** montre la version en ligne sur Google Play.

1. Une fois une version Play en ligne, tapez son **Latest build** et son **Version name**.
2. Seulement si les vieilles applis doivent cesser de fonctionner, augmentez **Minimum build** (0 = aucun) et confirmez.
3. Ajoutez un **Message** facultatif pour l'invitation.
4. Appuyez sur **Save**. **✓ Saved - apps check this the next time they open.**

Les applis plus vieilles que la dernière version voient **Update available**; celles plus vieilles que le minimum voient un **Update required** bloquant. Si Google Play affiche une version plus récente que celle enregistrée, une ligne orangée vous dit quoi inscrire.

## Demander aux applis iPhone de se mettre à jour

Dans la partie **📱 iPhone (App Store)** de la même carte :

1. Vérifiez la ligne qui indique ce que l'App Store affiche (avec le lien **App Store page**).
2. Remplissez **Minimum version** (vide = aucun) et, au besoin, **Latest version** et **Message**.
3. Appuyez sur **Save**.

Les iPhone ne reçoivent une invitation que pour une version que l'App Store affiche vraiment.

## Qui peut faire quoi

| Rôle | Accès |
|---|---|
| Administrateur de la plateforme | Tout ici, et peut encore se connecter pendant la maintenance. |
| Tous les autres | Voient le message de maintenance à la page de connexion et ne peuvent pas se connecter tant qu'elle est active. |

## Questions fréquentes et problèmes

??? « Set up the maintenance window first. » | Enregistrez une période (dates et message) avant d'appuyer sur Email all users.
??? « Enter an email. » | Tapez une adresse dans la case avant d'appuyer sur Send test.
??? « The minimum build cannot be newer than the latest build. » | Baissez Minimum build ou augmentez Latest build pour que le minimum ne dépasse pas la dernière version.
??? Les gens sont encore bloqués une fois le travail fini. | Désactivez Maintenance mode et appuyez sur Save, ou vérifiez l'heure Ends.
??? « Could not load maintenance settings ». | L'écran n'a pas pu lire ses réglages. Actualisez; seuls les administrateurs de la plateforme peuvent l'ouvrir.

## Écrans liés

[[open: #server-health | État du serveur]] [[open: #mail-settings | Réglages du courriel]] [[open: #platform-overview | Vue d'ensemble de la plateforme]]
