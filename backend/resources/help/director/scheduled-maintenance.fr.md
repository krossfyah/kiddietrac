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

## Envoyer le courriel d’avertissement

1. Enregistrez d’abord la période : le courriel reprend ses dates et son message.
2. Sous **📧 Heads-up email**, tapez votre propre adresse dans la case (elle est vide au départ) et appuyez sur **Send test**. Une copie marquée [TEST] arrive; lisez-la. Si la ligne indique que la passerelle de courriel l’a retenue, le journal des courriels dit pourquoi.
3. Quand tout est bon, appuyez sur **Email all users**. Une fenêtre compte qui serait joint maintenant, par agence, et qui est laissé de côté.
4. Tapez la phrase affichée (par exemple **EMAIL 34**) et appuyez sur **Send to 34**. Le nombre est celui des personnes qui recevront le courriel; un nombre erroné ou périmé est refusé.
5. La ligne sous les boutons indique combien de courriels sont partis et combien la passerelle a retenus. **All-user notice last sent …** affiche ensuite la date.

> ⚠️ **Email all users** ne joint que les personnes actives des agences en activité : le personnel ayant un rôle actif dans un centre ouvert, les parents d’une famille encore inscrite dans un centre ouvert, et les administrateurs de la plateforme. Les agences de test, les services de garde fermés et les comptes désactivés, suspendus ou seulement invités sont exclus. Chaque personne reçoit son propre message par la passerelle de courriel habituelle : une agence ou un centre dont le courriel est désactivé est donc retenu. L’auteur de l’envoi et le nombre de courriels sont consignés dans le journal d’audit. Envoyez-le une seule fois.

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
??? « Type EMAIL … to confirm. » | Le nombre a changé depuis le décompte, ou la phrase n’a pas été tapée exactement. Appuyez de nouveau sur Email all users et tapez la nouvelle phrase.
??? « Enter an email. » | Tapez une adresse dans la case avant d'appuyer sur Send test.
??? « The minimum build cannot be newer than the latest build. » | Baissez Minimum build ou augmentez Latest build pour que le minimum ne dépasse pas la dernière version.
??? Les gens sont encore bloqués une fois le travail fini. | Désactivez Maintenance mode et appuyez sur Save, ou vérifiez l'heure Ends.
??? « Could not load maintenance settings ». | L'écran n'a pas pu lire ses réglages. Actualisez; seuls les administrateurs de la plateforme peuvent l'ouvrir.

## Écrans liés

[[open: #server-health | État du serveur]] [[open: #mail-settings | Réglages du courriel]] [[open: #platform-overview | Vue d'ensemble de la plateforme]]
