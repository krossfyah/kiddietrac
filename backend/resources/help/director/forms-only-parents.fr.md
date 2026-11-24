---
title: Parents (formulaires seulement)
category: Enrolment
order: 3
roles: agency_admin, platform_admin
---
# Parents (formulaires seulement)

Lorsque vous envoyez des formulaires à un parent qui n’est pas encore dans KiddieTrac, un **compte temporaire, réservé aux formulaires**, est créé pour lui afin qu’il puisse signer. Tant que sa famille n’est pas ajoutée, ce compte n’appartient à aucune famille. Cette page explique comment ces comptes sont liés, signalés et nettoyés.

[[open: #notification-settings | Ouvrir les paramètres de notification]]

## Les lier à leur famille

Ajoutez la famille comme d’habitude avec l’assistant **Nouvelle famille**. À l’étape **Tuteurs** :

- Saisissez le nom ou le courriel du parent. Si un compte réservé aux formulaires correspond, un encadré jaune **Compte réservé aux formulaires trouvé** apparaît, avec son courriel et les formulaires signés.
- Appuyez sur **Lier à cette famille**, vérifiez les détails dans la fenêtre, puis appuyez sur **Utiliser ce compte**. Le tuteur est rempli à partir du compte.

Vous pouvez aussi simplement utiliser le **même courriel** que celui auquel les formulaires ont été envoyés. Dans les deux cas, lorsque la famille est enregistrée, le compte la rejoint avec tous les formulaires signés. Si aucun identifiant de connexion ne lui avait été envoyé, il est invité comme tout nouveau tuteur. Le lien est consigné dans le journal d’audit.

Voir [Ajouter une famille](adding-a-family).

## Dans le résumé

Tant qu’ils ne sont pas rattachés à une famille, ces parents figurent dans le courriel de résumé des administrateurs sous **🧾 Parents avec formulaires mais sans famille**: nom, courriel, formulaires signés et date à laquelle un compte inutilisé sera supprimé. Seuls les administrateurs d’agence reçoivent cette section.

## La liste et le paramètre

Barre latérale → **Paramètres** → **Notifications** → **🧾 Parents (formulaires seulement)**. Seuls les administrateurs d’agence peuvent la modifier.

- **Supprimer les comptes inutilisés après … jours** — **30** par défaut. Saisissez **0** pour ne jamais les supprimer. Appuyez sur **Enregistrer**.
- Le tableau liste chaque parent avec ses formulaires (**signés / assignés**) et un statut :
  - **Utilisé — ajoutez sa famille** — le parent s’est connecté ou a signé un formulaire.
  - **Inutilisé · supprimé** *(date)* — ne s’est jamais connecté et n’a jamais signé de formulaire.
- **Supprimer** retire un compte immédiatement. Son identifiant de connexion est supprimé; les formulaires déjà signés restent au dossier. Ne le faites que si le parent ne se joint pas.

## Suppression automatique

Chaque nuit, les comptes réservés aux formulaires inutilisés et plus anciens que votre limite sont supprimés. Un compte que quelqu’un a utilisé — connexion ou formulaire signé — n’est **jamais** supprimé automatiquement. Il reste dans la liste jusqu’à ce que vous ajoutiez la famille ou le supprimiez vous-même.

??? La suppression d’un compte supprimera-t-elle ses formulaires signés? | Non. Les formulaires restent au dossier.
??? Où se trouve Paramètres → Notifications? | Barre latérale → **Paramètres** → **Notifications**. Chaque type de rappel a son propre onglet; **🧾 Parents (formulaires seulement)** en fait partie.
