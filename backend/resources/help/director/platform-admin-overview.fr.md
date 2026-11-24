---
roles: agency_admin, platform_admin
title: Admin de la plateforme (aperçu)
category: Administration
order: 35
---
# Admin de la plateforme (aperçu)

Admin de la plateforme est le rôle de niveau le plus élevé dans KiddieTrac. Il se situe au-dessus du rôle d’administrateur d’agence propre à chaque locataire et donne une vue d’ensemble de toutes les agences de la plateforme, ce qui est utile si vous exploitez KiddieTrac en mode SaaS pour plusieurs marques de garde d’enfants indépendantes.

Si vous avez ce rôle, vous verrez une section violette **Plateforme** en haut de votre barre latérale, avec deux entrées : Aperçu de la plateforme et Toutes les agences. Le bouton de changement d’agence gagne un petit badge violet **PLAT**.

## Ce que les admins de la plateforme peuvent faire

- Voir tous les utilisateurs de la plateforme dans Gestion des utilisateurs, et pas seulement ceux de l’agence active.
- Passer à n’importe quelle agence en quelques secondes grâce au sélecteur, sans invitation propre à chaque locataire.
- Créer une nouvelle agence cliente via Plateforme → Toutes les agences → + Créer une agence.
- Suspendre ou rétablir l’état de facturation d’une agence depuis le tableau Toutes les agences.
- Voir les totaux interagences (MRR, inscriptions, désabonnements) sur l’écran Aperçu de la plateforme.

## Outils réservés à la plateforme

Quelques écrans n’existent que pour les admins de la plateforme, parce qu’ils concernent
toute la plateforme plutôt qu’une seule agence :

- **Afficher en tant que**, au bas de la barre latérale : prévisualiser un rôle, ou voir le portail comme une
  personne précise. Voir [Afficher en tant qu’un autre utilisateur](view-as).
- **Paramètres → Sauvegardes** : la sauvegarde nocturne de la base de données et son état d’exécution. Voir
  [Sauvegardes de la base de données](database-backups).
- **Ventes → Dépôt de fichiers** : grilles tarifaires, présentations et modèles de contrat pour le
  côté ventes. Voir [Dépôt de fichiers](files-repository).
- **Paramètres → Alertes de sécurité** : voir [Alertes de sécurité et surveillance](security-alerts).

## Ce que les admins de la plateforme ne peuvent pas faire (pour l’instant)

- Modifier les données d’un locataire sans d’abord passer à cette agence. Le contexte de l’agence active limite encore la plupart des opérations d’écriture.
- Gérer la facturation de la plateforme elle-même (l’intégration Stripe se fait par locataire).

## Accorder le rôle d’admin de la plateforme à un autre utilisateur

Depuis Gestion des utilisateurs → cliquez sur **Gérer** sur l’utilisateur → dans la section Rôles, choisissez **Admin de la plateforme** dans la liste déroulante des rôles et cliquez sur **Appliquer**. Remarque : seul un admin de la plateforme existant peut accorder ce rôle; il n’existe aucune voie d’élévation de privilèges à partir de agency_admin.

## Retirer le rôle d’admin de la plateforme

Pour retirer le rôle, communiquez avec votre contact du soutien de la plateforme. La fenêtre Gérer ajoute actuellement les rôles de façon cumulative par sécurité; la révocation d’un rôle est une étape manuelle délibérée.
