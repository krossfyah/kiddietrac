---
title: Méthodes de connexion (Google, Microsoft et Facebook)
---

# Méthodes de connexion (Google, Microsoft et Facebook)

**🔐 Sign-in methods** permet au personnel et aux familles de se connecter avec un compte Google, Microsoft ou Facebook qu'ils ont déjà, au lieu de taper un mot de passe KiddieTrac. Un administrateur de la plateforme configure chaque fournisseur une seule fois, pour toute la plateforme, depuis **Platform › 🔏 Sign-in methods**. Le bouton d'un fournisseur reste caché sur la page de connexion tant que ses informations ne sont pas enregistrées ici.

[[open: #social-settings | Ouvrir les méthodes de connexion]]

## Comment ça fonctionne pour les personnes qui se connectent

- Le bouton **Google**, **Microsoft** ou **Facebook** n'apparaît sur la page de connexion que pour les fournisseurs qui affichent **● Enabled** ici.
- La connexion sociale **relie seulement un compte invité existant**, grâce à son adresse courriel vérifiée. Elle ne crée jamais de nouveau compte. Une personne dont le courriel Google ou Microsoft ne correspond à aucune personne invitée est refusée.
- Le courriel et le mot de passe fonctionnent toujours : personne n'est obligé d'utiliser un compte social.

## Configurer un fournisseur

Chaque fournisseur a sa propre carte. Faites ceci une fois par fournisseur.

1. Ouvrez **Platform › 🔏 Sign-in methods**.
2. Dans l'encadré bleu **Redirect / callback URL**, appuyez sur **Copy**. Le bouton affiche **Copied ✓**. Remplacez `{provider}` dans cette adresse par `google`, `microsoft` ou `facebook` au moment de la coller.
3. Sur la carte du fournisseur, ouvrez **How to create the Google app** (ou celle de Microsoft ou de Facebook) et suivez ses étapes dans la console de développement du fournisseur. Collez l'adresse de redirection à l'endroit indiqué.
4. Copiez l'identifiant et le secret de l'application dans la console de développement.
5. Collez-les dans **Client ID** et **Client Secret** sur la carte. Pour Microsoft, laissez **Tenant** à `common`, à moins de vouloir n'autoriser qu'une seule organisation.
6. Appuyez sur **Save Google** (ou **Save Microsoft**, **Save Facebook**). Un message **Saved** apparaît et la carte affiche **● Enabled**.
7. Ouvrez la page de connexion dans une fenêtre privée et vérifiez que le nouveau bouton y est.

[[show: #social-settings @ How to create the Google app | Me montrer les étapes]]

> ℹ️ Pour Microsoft, copiez la **Value** du secret, pas son **Secret ID**. Pour Facebook, l'application doit passer l'**App Review** de Meta et être mise en mode **Live** avant que d'autres personnes que ses testeurs puissent l'utiliser.

## Modifier ou désactiver un fournisseur

1. Pour remplacer un secret, tapez le nouveau dans **Client Secret** et appuyez sur **Save …**. Laissez le champ vide pour garder le secret enregistré : il affiche **•••••••• (saved — leave blank to keep)**.
2. Pour désactiver un fournisseur, appuyez sur **Disable** sur sa carte.
3. Confirmez **Disable google sign-in? Its credentials will be cleared.** Le bouton disparaît de la page de connexion et la carte affiche **Not set up**.

> ⚠️ Désactiver ne bloque personne : ceux qui utilisaient ce bouton se connectent plutôt avec leur courriel et leur mot de passe (ou le réinitialisent avec **Mot de passe oublié**).

## Notes de sécurité

- Les secrets sont **en écriture seule** : une fois enregistrés, ils ne sont plus jamais affichés, même à vous.
- Seules ces clés de connexion sont modifiées sur le serveur ; aucun autre réglage n'est touché.

## Qui peut faire quoi

- **Administrateurs de la plateforme** : configurent, modifient et désactivent chaque fournisseur.
- **Administrateurs d'agence et tous les autres** : ne peuvent pas changer ces réglages ; un administrateur d'agence qui ouvre l'écran voit **This page is available to platform administrators only.** Ils voient simplement les boutons sur la page de connexion une fois un fournisseur activé.

## Questions fréquentes et problèmes

??? Le bouton Google n'apparaît pas sur la page de connexion. | La carte doit afficher **● Enabled**. Actualisez la page de connexion ; une page en cache peut encore montrer les anciens boutons un moment.
??? Une personne est refusée après avoir choisi Google. | Son courriel Google ne correspond pas au courriel de son compte KiddieTrac. Invitez-la ou mettez son compte à jour avec cette adresse, ou demandez-lui de se connecter avec son courriel et son mot de passe.
??? Le fournisseur dit que l'adresse de redirection ne correspond pas. | L'adresse enregistrée dans la console de développement doit correspondre exactement, avec `google`, `microsoft` ou `facebook` à la place de `{provider}`.
??? « Save failed » est apparu. | L'identifiant ou le secret a été refusé, ou la connexion a coupé. Vérifiez que vous avez collé la valeur complète et réessayez.
??? Une agence peut-elle activer sa propre connexion Google? | Non. Les méthodes de connexion sont réglées pour toute la plateforme par un administrateur de la plateforme.

## Écrans liés

[[open: #security-alerts | Alertes de sécurité]] · [[open: #audit-logs | Journal d'audit]]

Voir aussi : [[security-alerts]] · [[passkeys]] · [[signing-in-and-unlocking]]
