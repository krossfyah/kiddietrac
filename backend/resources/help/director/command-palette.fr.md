---
title: Palette de commandes (Cmd/Ctrl-K)
category: Getting Started
order: 55
---
# La palette de commandes

Appuyez sur **`⌘K`** (macOS) ou **`Ctrl + K`** (Windows / Linux) **de n’importe où** dans le portail pour ouvrir une palette de recherche à accès rapide. Vous pouvez aussi appuyer sur **`/`** lorsque vous n’êtes pas déjà en train d’écrire dans un champ.

## Ce que vous pouvez y faire

| Saisie | Ce qu’elle trouve |
|---|---|
| Vide | Raccourcis vers les écrans : Tableau de bord, Marketing, Calendrier, Enfants, Familles, Centres, Utilisateurs, Journal d’audit, Facturation, Aide, MFA |
| 2 caractères ou plus | Correspondances en direct parmi les **centres**, **salles**, **familles**, **enfants** et le **personnel**, ainsi que des accès filtrés |

Les résultats sont regroupés par type : une recherche de `aco` affiche tout ce qui s’appelle Acorn (salles, enfants, personnel) sous leurs propres titres.

## Clavier

| Touche | Action |
|---|---|
| **`⌘K`** / **`Ctrl-K`** | Afficher / masquer la palette (fonctionne de n’importe quel écran, même en pleine saisie) |
| **`/`** | Ouvrir la palette (seulement si vous n’écrivez pas dans un autre champ) |
| **`↑` / `↓`** | Déplacer la sélection |
| **`Enter`** | Ouvrir le résultat sélectionné |
| **`Esc`** | Fermer la palette |

Vous pouvez aussi cliquer sur une ligne pour y accéder.

## Qui obtient la recherche en direct

Les administrateurs d’agence et de plateforme voient les correspondances en direct parmi les centres, salles, familles, enfants et membres du personnel. Les directeurs et les éducateurs ne voient que les raccourcis statiques vers les écrans (leur rôle n’a pas accès au service de recherche globale). Tout le monde a le raccourci clavier.

## Astuces

- La recherche porte sur l’**agence active** pour les administrateurs de plateforme multi-agences. Changez d’agence avec le sélecteur d’agence (en haut à droite) avant de chercher pour cibler un autre locataire.
- Les résultats de `/admin/search` sont limités à 5–8 par type, triés par pertinence, de sorte que la palette reste rapide même sur de grands portefeuilles.
- Si la palette semble lente, vérifiez votre réseau : chaque recherche est un aller-retour HTTP avec un délai antirebond de 180 ms.
