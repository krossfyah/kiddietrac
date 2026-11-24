---
title: Connexion à double authentification (MFA)
category: Settings
order: 10
---
# Connexion à double authentification (MFA)

La double authentification (MFA) ajoute une seconde preuve d’identité à la connexion : en plus de votre mot de passe, vous saisissez un code à 6 chiffres provenant d’une application d’authentification sur votre téléphone. Même si votre mot de passe est divulgué, un pirate ne peut pas entrer sans votre téléphone.

Fortement recommandée pour chaque administrateur d’agence et directeur de centre.

## Ce dont vous avez besoin

Toute application d’authentification TOTP : Google Authenticator, Authy, 1Password, Bitwarden, Microsoft Authenticator et la plupart des gestionnaires de mots de passe fonctionnent. Choisissez celle que vous utilisez déjà.

## La configurer

1. Ouvrez l’entrée **Paramètres → Double authentification (MFA)** de la barre latérale.
2. Cliquez sur **Configurer la MFA**. Kiddietrac affiche une chaîne secrète et une liste de 10 codes de récupération.
3. Dans votre application d’authentification, choisissez « Ajouter un compte » → « Saisir la clé de configuration » et collez le secret. Enregistrez.
4. Conservez les 10 codes de récupération dans un endroit sûr, comme un gestionnaire de mots de passe. Vous ne les reverrez plus.
5. De retour dans Kiddietrac, saisissez le code à 6 chiffres actuel de votre application d’authentification et cliquez sur **Confirmer et activer**.

Vous verrez « MFA activée » : c’est terminé. À votre prochaine connexion, après votre mot de passe, un code vous sera demandé.

## Se connecter avec la MFA activée

Après avoir saisi votre mot de passe, Kiddietrac affiche un nouveau champ : **Code d’authentification**. Saisissez le code à 6 chiffres que votre application affiche et soumettez-le.

Si vous n’avez pas accès à votre téléphone, saisissez plutôt l’un de vos codes de récupération de 10 caractères. Chaque code de récupération ne fonctionne qu’une fois : une fois utilisé, il est consommé.

## Désactiver la MFA

Ouvrez Paramètres → Double authentification → saisissez votre code actuel (ou un code de récupération) → confirmez. Vous revenez à une connexion par mot de passe seulement. Une réactivation ultérieure repart de zéro.

## Si vous perdez l’accès à votre téléphone

Utilisez un code de récupération à la connexion. Une fois connecté, allez à Paramètres → Double authentification → Désactiver, puis reconfigurez-la avec le nouveau téléphone.

Si vous avez aussi perdu vos codes de récupération, communiquez avec l’administrateur de votre plateforme : il peut effacer la MFA de votre compte depuis son écran de gestion des utilisateurs.
