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

## Licences et statut de facturation

Le forfait et les modules d'une agence se trouvent sur sa licence, dans **Platform → Licences**.

- Chaque agence forme un groupe. Cliquez sur son en-tête (ou appuyez sur Entrée ou Espace) pour
  ouvrir ou fermer ses lignes de forfait et de modules. Fermé, l'en-tête affiche les modules en
  pastilles à côté de la durée, du nombre de lignes et du total. **Expand all** et **Collapse all**
  agissent sur toute la liste, et votre choix pour chaque agence est gardé sur cet ordinateur.
- Un groupe s'ouvre de lui-même seulement quand la liste est filtrée sur cette agence, quand
  l'agence est dans son délai de grâce ou n'a aucun forfait en cours, ou quand sa licence se
  termine dans les 30 jours sans renouvellement automatique.
- Le **⋮** de l'en-tête d'une agence contient **Convert to paid** (essais), **Renew all**,
  **Term & renewal**, **＋ Add add-on** (ou **＋ Add plan**), **Included centres** et, en dernier,
  **Cancel licence**. Le **⋮** de chaque ligne contient **Edit** (prix, quantité, note) et, pour
  les modules, **Remove add-on**.
- **Toutes les agences → Modifier → Plan** affiche **Plan & add-ons** en lecture seule : le
  forfait, chaque module avec son numéro de pièce et son prix, et la date de renouvellement.
  **Manage in Licences →** ouvre Licences filtré sur cette agence. Modifiez la licence là, pas dans
  la fenêtre Modifier.
- Un changement de licence ou de fonction atteint les onglets ouverts de l'agence sans que
  personne ne se déconnecte.

**Le statut de facturation suit la licence.** Un forfait payé ou gratuit rend l'agence
**active**, un essai la met en **trial**, le délai de grâce la garde **active** (les factures de
renouvellement continuent d'être émises), et l'absence de forfait en cours la rend **suspended**.
Le statut est en lecture seule dans **My agency → Manage** et **Features**. **Suspend** garde
l'agence suspendue malgré tout changement de licence jusqu'à ce que vous appuyiez sur **Resume**.
**Pin the status** dans **Manage** garde une agence en **trial** ou **active** quoi que dise sa
licence (utilisé pour les agences de démonstration) ; un statut épinglé ne lance jamais la
facturation, et une suspension l'emporte toujours.

## Ce que les admins de la plateforme ne peuvent pas faire (pour l’instant)

- Modifier les données d’un locataire sans d’abord passer à cette agence. Le contexte de l’agence active limite encore la plupart des opérations d’écriture.
- Gérer la facturation de la plateforme elle-même (l’intégration Stripe se fait par locataire).

## Accorder le rôle d’admin de la plateforme à un autre utilisateur

Depuis Gestion des utilisateurs → cliquez sur **Gérer** sur l’utilisateur → dans la section Rôles, choisissez **Admin de la plateforme** dans la liste déroulante des rôles et cliquez sur **Appliquer**. Remarque : seul un admin de la plateforme existant peut accorder ce rôle; il n’existe aucune voie d’élévation de privilèges à partir de agency_admin.

## Retirer le rôle d’admin de la plateforme

Pour retirer le rôle, communiquez avec votre contact du soutien de la plateforme. La fenêtre Gérer ajoute actuellement les rôles de façon cumulative par sécurité; la révocation d’un rôle est une étape manuelle délibérée.
