---
title: Authentification unique (Google et Microsoft)
category: Settings
order: 40
roles: agency_admin, platform_admin
---

# Authentification unique (Google et Microsoft)

Permettez à votre personnel et aux familles de se connecter avec leur compte Google ou Microsoft existant plutôt qu’avec un mot de passe distinct. *La configuration est faite par un administrateur de la plateforme sous **Paramètres → Méthodes de connexion**.*

## Comment ça fonctionne

- Les boutons de connexion n’apparaissent sur la page de connexion que pour les fournisseurs que vous avez configurés.
- La connexion sociale **se rattache à un compte invité existant** par courriel vérifié — elle ne crée jamais de nouveaux comptes. Si quelqu’un se connecte avec une adresse Google/Microsoft qui ne correspond à aucun utilisateur invité, l’accès lui est poliment refusé.
- Rien ne change pour les personnes qui préfèrent le courriel et le mot de passe; cette option reste toujours disponible.

## L’activer

1. Allez à **Paramètres → Méthodes de connexion** (administrateurs de la plateforme seulement).
2. Pour chaque fournisseur, copiez l’**URL de redirection** affichée sur la page dans la console développeur du fournisseur (Google Cloud Console ou Microsoft Entra), puis collez l’**ID client** et le **Secret client** dans KiddieTrac.
3. Enregistrez. Le bouton du fournisseur apparaît immédiatement sur la page de connexion.

Pour désactiver un fournisseur, effacez ses identifiants avec le bouton **Désactiver**.

## Notes de sécurité

- Les secrets sont en **écriture seule** — une fois enregistrés, ils ne sont plus jamais affichés.
- Seules les clés OAuth sont écrites dans la configuration du serveur; aucun autre paramètre n’est touché.
