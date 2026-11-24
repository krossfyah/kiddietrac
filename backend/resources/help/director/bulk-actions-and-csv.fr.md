---
title: Actions en lot et exportations CSV
category: Administration
order: 59
---
# Actions en lot et exportations CSV

La plupart des listes d’administration prennent en charge les actions en lot par sélection multiple et un téléchargement CSV en un clic.

## Téléchargements CSV

Un bouton vert **⤓ CSV** apparaît sur ces écrans :

| Écran | Nom du fichier | Colonnes |
|---|---|---|
| **Administration → Gestion des utilisateurs** | `users-YYYY-MM-DD.csv` | ID, Nom, Courriel, Téléphone, Statut, Rôles, Dernière connexion, Créé |
| **Administration → Familles** | `families-YYYY-MM-DD.csv` | ID, Nom de famille, Centre, Courriel, Téléphone, Adresse, Ville, Enfants, Tuteurs, Solde impayé |
| **Administration → Journal d’audit** | `audit-log.csv` | Quand, Action, Type d’entité, ID de l’entité, Auteur, Courriel, IP, Données |
| **Formulaires personnalisés → fenêtre des réponses** | `<title>-responses-YYYY-MM-DD.csv` | Soumis le, Auteur, Courriel + une colonne par champ du schéma |
| **Facturation en lot** | `invoices-YYYY-MM-DD.csv` | N° de facture, Famille, Statut, Période, Émise, Échéance, Sous-total, Subvention, Rabais, Taxe, Total, Payé, Solde |

Toutes les exportations utilisent l’encodage UTF-8 avec BOM afin qu’Excel affiche correctement les caractères spéciaux.

## Actions en lot sur les utilisateurs

L’onglet Utilisateurs comporte une colonne de cases à cocher. Sélectionnez plusieurs lignes pour afficher une barre d’actions en lot :

- **Renvoyer le message de bienvenue** — déclenche `/admin/users/{id}/resend-welcome` pour chaque ligne
- **Supprimer** — supprime logiquement chaque utilisateur (une confirmation est demandée au préalable)

Une case « tout sélectionner » se trouve dans l’en-tête. La case de votre propre ligne d’utilisateur est désactivée afin que vous ne puissiez pas vous supprimer vous-même en lot.

## Actions en lot sur les familles

Faites passer l’onglet Familles en vue **Tableau** (bascule dans la barre d’outils). Même principe de cases à cocher, avec une barre d’actions en lot ambre contenant **Supprimer**, qui supprime logiquement les lignes de famille. Les enfants et l’historique d’audit sont conservés : seule la ligne de la famille est retirée.

## Principe

Les boutons de téléchargement CSV et les barres d’actions en lot utilisent les mêmes fonctions (`downloadCsv()` pour les téléchargements, `Api.delete` / `Api.post` séquentiels pour les actions en lot). Ajouter ce principe à d’autres listes d’administration est simple : ouvrez une demande de soutien si vous le souhaitez sur une liste qui ne l’offre pas encore.
