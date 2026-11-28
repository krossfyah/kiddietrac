---
title: Gérer les revendeurs
---
# Gérer les revendeurs

**Revendeurs** est l'écran de l'équipe de la plateforme pour les partenaires qui vendent KiddieTrac sous leur propre marque. Chaque revendeur possède ses agences et définit une seule marque pour elles. Ici, vous créez un revendeur, réglez sa marque et sa licence, choisissez ses agences, donnez accès à des personnes, fixez sa commission et préparez sa propre application Android à sa marque.

[[open: #resellers | Ouvrir Revendeurs]]

## Créer un revendeur

1. Ouvrez **Revendeurs** dans la section **Reseller** du menu.
2. Appuyez sur **+ Nouveau revendeur** dans la bannière.
3. Tapez le **Company name** (nom de l'entreprise) et appuyez sur **Create**.
4. Le nouveau revendeur est sélectionné dans la liste de gauche, sur son onglet **Marque**. Son **App build code** s'affiche à côté des onglets.

## Régler sa marque et sa licence

1. Choisissez le revendeur dans la liste de gauche (chacun montre son nombre d'agences et son statut).
2. Dans l'onglet **Marque**, remplissez **Nom de l’application et du produit** et **Courriel d’assistance**, téléversez les **Logos** et réglez les **Couleurs** et la **Bannière de page**, en suivant l'**Aperçu en direct**.
3. Sous **Licence**, cochez **Masquer KiddieTrac partout** si la licence le prévoit : ses agences ne voient alors que le nom et les logos du revendeur dans les écrans, courriels, textos, documents et notifications. Seule la plateforme peut changer cette case.
4. Cochez **Permettre à mes agences d’utiliser leur propre logo et leurs couleurs** si ses agences peuvent remplacer la marque.
5. Appuyez sur **Enregistrer la marque**.

## Choisir ses agences

1. Ouvrez l'onglet **Agences**.
2. Cochez chaque agence qui appartient à ce revendeur. Une agence grisée avec « — with … » appartient déjà à un autre revendeur : décochez-la d'abord chez celui-ci.
3. Appuyez sur **Enregistrer les agences**. Un message vert « Agencies updated. » s'affiche et les agences cochées montrent la marque du revendeur.

## Donner accès à des personnes

1. Ouvrez l'onglet **Personnes**. Il liste qui gère le revendeur.
2. Tapez l'**Email of an existing account** (courriel d'un compte existant) et appuyez sur **Ajouter**. La personne reçoit le rôle Revendeur : elle travaille comme administratrice dans chaque agence du revendeur, ajoute des agences, gère leurs administrateurs, voit la facturation et la commission, et modifie la marque.
3. Appuyez sur **Supprimer** à côté d'une personne pour lui retirer le rôle.

[[show: #resellers @ People | Me montrer l'onglet Personnes]]

## Fixer la commission

1. Ouvrez l'onglet **Commission**.
2. Entrez le **Percentage** (pourcentage) de l'argent encaissé avant taxes.
3. Entrez le **Flat per paying agency ($)** (montant fixe par agence qui paie), ou laissez 0.
4. Appuyez sur **Enregistrer la commission**. Appuyez sur **Monthly statement →** pour ouvrir le relevé **Facturation et commissions** de ce revendeur.

## Préparer l'application du revendeur

1. Ouvrez l'onglet **Application**.
2. Entrez le **Package id** (par exemple `com.littlesteps.app`). Il devient permanent une fois l'application publiée.
3. Réglez le **Status** (**Not started**, **Kit downloaded**, **Built**, **Published**), et au besoin **Version number (optional)** et **Version name (optional)**.
4. Ajoutez le lien **Fiche Google Play** quand il existe, et cochez **Le fichier Firebase est en place** quand c'est le cas.
5. Appuyez sur **Enregistrer**, ou sur **⬇ Download build kit** pour enregistrer et télécharger le fichier de trousse pour l'ordinateur de compilation.
6. Suivez la **Liste de vérification**; chaque ligne se coche d'elle-même une fois faite. **On the phone** montre l'icône (le logo carré de l'onglet Marque) et le nom que les gens verront.

> ℹ️ Les gens peuvent aussi utiliser l'application KiddieTrac standard : elle passe à la marque du revendeur après la connexion.

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Administrateur de la plateforme | Tout ce qui se trouve sur cet écran. |
| Revendeur | Ne voit pas cet écran. Modifie sa marque dans **Image de marque** et lit ses conditions dans **Facturation et commissions**. |
| Administrateur d'agence | Ne voit pas cet écran. |

## FAQ et quand quelque chose ne va pas

??? « No account uses that email. Create the person first (Admin → Users), then add them here. » | La personne a besoin d'un compte KiddieTrac avant de recevoir le rôle Revendeur. Créez l'utilisateur, puis ajoutez de nouveau son courriel.
??? « Several accounts share that email… » | Demandez à la personne quel compte utiliser et ajoutez celui-là.
??? « That short name is already used by a reseller or an agency. » | Choisissez un autre nom d'entreprise en créant le revendeur.
??? « That is the KiddieTrac app's own package id; a reseller app needs its own. » | Entrez un identifiant propre au revendeur, comme com.leurnom.app.
??? « Choose the package id first. » | Remplissez **Package id** avant d'appuyer sur **⬇ Download build kit**.
??? La liste indique « No resellers yet. » | Appuyez sur **+ Nouveau revendeur** pour créer le premier.
??? Quelqu'un reçoit-il un courriel quand j'ajoute une personne ou enregistre la marque? | Non. La personne ajoutée voit la section Reseller à sa prochaine connexion ou au rechargement de la page; les agences voient la marque enregistrée à leur prochaine page.

## Écrans liés

[[open: #reseller | Aperçu du revendeur]] [[open: #reseller-billing | Facturation et commissions]] [[open: #platform-agencies | Toutes les agences]]

- [[reseller-all-my-agencies]]
- [[reseller-billing-and-commissions]]
- [[white-label-branding]]
