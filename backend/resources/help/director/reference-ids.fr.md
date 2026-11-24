---
title: Identifiants de référence des enfants, familles et employés
category: Administration
order: 30
---
# Identifiants de référence des enfants, familles et employés

Chaque enfant, famille, employé et parent de votre agence a un identifiant de référence, comme **ILH-C-0042**. Servez-vous-en pour retrouver rapidement un dossier, le donner au téléphone et faire correspondre les personnes entre documents et chiffriers.

## Comment un identifiant est construit

- **Les lettres de votre agence**, par exemple **ILH**. Par défaut, ce sont les initiales du nom de votre agence.
- **Une lettre de type :** **C** enfant, **F** famille, **S** employé, **P** parent ou tuteur.
- **Un numéro** qui s’incrémente au sein de votre agence, pour chaque type.

Chaque nouveau dossier reçoit automatiquement son identifiant. Les numéros ne sont jamais réutilisés ni renumérotés, même lorsqu’un enfant est retiré ou qu’une famille part.

Une personne qui est à la fois employée et parent dans votre agence a deux identifiants : un **S** et un **P**.

## Où les identifiants apparaissent dans le portail

Vous voyez un identifiant dans une petite case grise. Appuyez sur le bouton de copie (**⧉**) à côté pour le copier.

- **Liste des enfants et dossier de l’enfant :** sous le nom de l’enfant, avec l’étiquette **ID de l’enfant**. La section famille du dossier affiche l’**ID de la famille**.
- **Liste des familles et détails de la famille :** sous le nom de la famille, avec l’identifiant de chaque enfant à côté.
- **Gestion des utilisateurs :** sous le nom de chaque personne. Son dossier affiche son **ID d’employé**, son **ID de parent**, ou les deux.
- **Vos propres identifiants :** **Paramètres → Profil → Votre ID KiddieTrac**.

Les éducateurs voient l’identifiant de chaque enfant dans leur propre liste d’enfants et sur la page de l’enfant.

## Recherche par identifiant

Saisissez un identifiant dans le champ **🔍 Rechercher…** en haut de la barre latérale, ou appuyez sur **Ctrl + K** (**⌘K** sur un Mac) pour ouvrir la palette de commandes. Les résultats affichent l’identifiant à côté de chaque nom, et les parents ont maintenant leur propre groupe **Parents**.

La recherche est tolérante. Tous les exemples suivants trouvent **ILH-C-0042** :

- l’identifiant complet : `ILH-C-0042`
- avec espaces ou sans tirets : `ilh c 42`, `ILHC42`
- le type et le numéro : `C-0042`, `c42`
- seulement le numéro : `0042` ou `42`
- le début d’un identifiant : `ILH-C-00` énumère les identifiants qui commencent ainsi

Un mot simple est traité comme un nom, donc saisir un nom de famille fonctionne comme avant. La même recherche souple fonctionne dans le champ de filtre au-dessus des listes, de sorte que vous pouvez coller un identifiant pour restreindre un tableau.

Qui peut trouver quoi :

- Les **administrateurs d’agence** cherchent dans toute l’agence.
- Les **directeurs de centre** cherchent seulement dans leurs propres centres.
- Les **administrateurs de plateforme** doivent d’abord choisir une agence.
- Les **éducateurs** peuvent saisir l’identifiant d’un enfant dans la recherche de leur propre liste d’enfants.

> **Astuce :** Un identifiant imprimé avec les anciennes lettres de votre agence trouve quand même le dossier, de sorte que les formulaires de l’an dernier continuent de fonctionner après un changement de préfixe.

## Changer les lettres de votre agence

Seul un **administrateur d’agence** (ou un administrateur de plateforme) peut changer le préfixe.

1. Allez à **Aperçu de l’agence → ✏️ Modifier l’agence → Ouvrir Image de marque →**.
2. Ouvrez l’onglet **🌎 Région et identifiants**.
3. Dans **Préfixe de l’identifiant de référence**, saisissez de 2 à 6 lettres ou chiffres, en commençant par une lettre.
4. Appuyez sur **Enregistrer le préfixe**. Les exemples sous le champ se mettent à jour pour montrer les nouveaux identifiants.

[[open: #admin-branding?tab=region | Ouvrir Image de marque → Région et identifiants]]

Le changement s’applique à tous les identifiants à la fois. Deux agences ne peuvent pas utiliser les mêmes lettres. Le changement est consigné dans le journal d’audit.

## Les identifiants sur les documents

Les identifiants sont imprimés sur les documents que vous produisez, de sorte qu’une page peut toujours être associée à son dossier :

- **Fiche d’urgence :** l’identifiant de l’enfant et celui de la famille.
- **Rapport d’incident :** l’identifiant de l’enfant. La copie du personnel affiche aussi l’identifiant de la personne qui l’a rempli.
- **Factures :** l’identifiant de la famille à côté du nom du destinataire de la facture, dans tous les styles de facture.
- **Reçus, relevés de compte et grand livre de la famille :** l’identifiant de la famille ou du titulaire du compte.
- **Rapport mensuel CWELCC :** l’identifiant de chaque famille.
- **Portfolio et bulletin :** l’identifiant de l’enfant.
- **Talons de paie et documents de paie :** l’identifiant de l’employé. Les modèles de documents de paie peuvent utiliser le champ **{{payee_id}}**.
- **Formulaires signés :** l’identifiant du signataire à côté de la date de signature, lorsque le formulaire a été signé dans le portail. Les formulaires signés par un lien sans connexion n’en ont pas.

## Les identifiants dans les exportations

Les exportations CSV et les rapports comprennent maintenant une colonne d’identifiant, pour que vous puissiez faire correspondre les lignes entre chiffriers. Cela comprend les exportations des enfants, des familles et du personnel, la paie, les subventions, CWELCC, les contrôles du sommeil, les vérifications des antécédents, les accusés de réception des politiques, les soumissions de formulaires, le financement de la main-d’œuvre, OCCMS et Ohio PFCC, les rapports intégrés et les feuilles de temps. Dans l’exportation des enfants, la colonne d’identifiant affiche l’identifiant de référence.

??? Un nouveau format de numéro de facture utilisera-t-il ces identifiants? | Non. Les numéros de facture ont leur propre format sous Image de marque → Factures. Voir [Style et numérotation des factures](invoice-numbering-and-style).
??? Puis-je attribuer un numéro particulier à un enfant? | Non. Les numéros sont attribués dans l’ordre et ne changent jamais, de sorte qu’un identifiant pointe toujours vers le même dossier.
??? Un directeur a cherché un identifiant et n’a rien obtenu. | Les directeurs ne trouvent que les dossiers de leurs propres centres. Un identifiant d’un autre centre ne donne aucun résultat.

Voir aussi : [Image de marque en marque blanche](white-label-branding) · [Actions en lot et CSV](bulk-actions-and-csv) · [Palette de commandes](command-palette)
