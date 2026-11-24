---
title: Se connecter avec Google
category: Getting Started
order: 8
---
# Se connecter avec Google

Oubliez le mot de passe. Connectez-vous à KiddieTrac avec votre compte Google.

## Comment l’utiliser

Sur l’écran de connexion :
1. Touchez **Continuer avec Google**.
2. Choisissez votre compte Google ou connectez-vous avec vos identifiants Google habituels.
3. Autorisez KiddieTrac à lire votre courriel et votre nom.
4. Vous êtes redirigé vers KiddieTrac, déjà connecté.

## Ce qui se passe en coulisses

- KiddieTrac reçoit votre courriel et votre nom Google par le processus OAuth.
- Nous vérifions si votre courriel est déjà associé à un compte KiddieTrac :
  - **Oui** → le compte est lié et vous êtes connecté
  - **Non** → un compte est créé automatiquement avec votre nom et votre courriel Google
- Par la suite, vous pouvez vous connecter avec Google ou avec un mot de passe — les deux fonctionnent.

## Confidentialité

- Nous demandons seulement le courriel et le profil de base (nom, avatar). Nous ne demandons jamais vos contacts ni votre calendrier Google.
- KiddieTrac ne voit pas votre mot de passe Google.
- Vous pouvez dissocier la connexion Google à tout moment dans les paramètres de votre profil.

## Autres fournisseurs

Apple Sign In et Microsoft Sign In suivent le même principe. Ils s’activent lorsque la plateforme configure les identifiants client correspondants.

## Pour l’administrateur de la plateforme (vous)

Configurez les identifiants OAuth :
1. Allez à https://console.cloud.google.com/apis/credentials
2. Créez un ID client OAuth 2.0
3. Ajoutez `https://api.kiddietrac.com/api/v1/sso/google/callback` comme URI de redirection
4. Copiez l’ID client et le secret client dans votre `.env` :
   ```
   GOOGLE_CLIENT_ID=…
   GOOGLE_CLIENT_SECRET=…
   ```
5. Le bouton « Continuer avec Google » s’active automatiquement.
