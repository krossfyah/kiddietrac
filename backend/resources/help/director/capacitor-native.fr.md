---
title: Applications natives iOS / Android
category: Mobile
order: 90
roles: agency_admin, platform_admin
---
# Applications natives iOS et Android

Une configuration d’enveloppe Capacitor est livrée dans la v22p51. L’application Web demeure la source de référence : la coque native charge `https://app.kiddietrac.com` et ajoute les notifications push natives, l’écran de démarrage, l’habillage de la barre d’état et la distribution par les boutiques d’applications.

## Prérequis de compilation (une seule fois)

- **Compte Apple Developer** (99 $/an) pour iOS
- **Compte Google Play Developer** (25 $, paiement unique) pour Android
- macOS avec **Xcode** pour les builds iOS
- N’importe quel système avec **Android Studio** pour les builds Android

## Compiler localement

```bash
cd parent-portal
npm install
npx cap init "KiddieTrac" "com.kiddietrac.app" --web-dir=.
npx cap add ios
npx cap add android
npx cap sync
npx cap open ios     # opens Xcode
npx cap open android # opens Android Studio
```

Dans chaque IDE : configurez la signature du code, définissez l’identifiant de bundle, ajoutez les icônes de l’application et les ressources de l’écran de démarrage, compilez un bundle de publication, puis téléversez-le dans App Store Connect / Play Console.

## Distribution

iOS : TestFlight d’abord → App Store public après 1 à 2 semaines de vérification.
Android : piste interne → tests fermés → production.

## Ce qui change dans l’application Web

Rien pour les utilisateurs finaux. La coque native définit `window.Capacitor`, ce qui permet d’activer quelques fonctions :
- Push natif (meilleure livraison sur iOS — le push Web ne fonctionne pas sur Safari iOS)
- Caméra native pour la capture de photos d’enfants et du personnel
- Sélecteur de fichiers natif pour le téléversement de documents

Ces points d’intégration sont des ébauches dans la v22p51 et seront complétés dans la v22p52.
