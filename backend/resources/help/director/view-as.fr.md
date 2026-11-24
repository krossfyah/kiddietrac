---
title: Voir comme un autre utilisateur
category: Administration
order: 36
roles: agency_admin, platform_admin
---
# Voir comme un autre utilisateur

**Pied de la barre latérale → Afficher en tant que….** Permet à un administrateur de la plateforme de voir le portail exactement comme le voit une personne donnée (ses écrans *et ses données*), puis de revenir à sa propre session.

## Qui peut l’utiliser

**Les administrateurs de la plateforme seulement.** Les commandes apparaissent au pied de la barre latérale, sous votre nom et le sélecteur d’agence; personne d’autre ne les a. Le serveur refuse quiconque n’est pas administrateur de la plateforme.

## Deux commandes, deux fonctions différentes

Il y a deux commandes « Afficher en tant que » au pied de la barre latérale, et elles ont des rôles différents.

| Commande | Ce qu’elle change | Les données que vous voyez |
|---|---|---|
| **👁 Afficher en tant que** (une liste déroulante de rôles) | Les menus et les écrans, tels que ce *rôle* les aurait | Toujours les vôtres |
| **Afficher en tant que…** (un bouton qui ouvre un sélecteur de personnes) | Tout. Vous êtes connecté en tant que cette *personne* | Les siennes |

Utilisez la liste de rôles pour vérifier à quoi ressemble le menu d’un rôle. Utilisez **Afficher en tant que…** quand vous devez voir ce que voit réellement un parent, un éducateur ou un directeur en particulier, par exemple pour un billet de soutien qui dit « Je ne vois pas mon enfant ».

## Prévisualiser un rôle

Choisissez un rôle dans la liste **👁 Afficher en tant que**. La page se recharge sur l’écran d’accueil de ce rôle et la commande devient violette, avec la mention **👁 Afficher en tant que**, pour que ce soit difficile à manquer. Choisissez **Super administrateur (défaut)** pour revenir.

Les sections de la barre latérale réservées à la plateforme (Plateforme, Ventes, Revendeur, Site web) sont masquées pendant la prévisualisation d’un autre rôle, parce que ce rôle ne les verrait jamais. Aide et guides passe aussi aux articles de ce rôle.

## Voir comme une personne

1. Appuyez sur **Afficher en tant que…** au pied de la barre latérale.
2. Cherchez par nom ou courriel, et restreignez au besoin par **Parents**, **Éducateurs**, **Directeurs** ou **Administrateurs**. Chaque résultat indique le rôle, l’agence et le centre de la personne.
3. Appuyez sur la ligne de la personne (**Voir →**).

Le portail se recharge en tant que cette personne, sur son écran d’accueil, dans son agence.

Il s’agit d’une **vraie connexion en tant que cette personne**, pas d’un déguisement. Chaque écran demande au serveur *ses* données, avec *ses* permissions. Ce que vous voyez est ce qu’elle voit, et tout ce que vous faites est fait avec son compte.

> **Important :** Vous agissez en son nom. Regardez, ne modifiez pas. Un message envoyé, un formulaire signé ou un dossier modifié pendant que vous voyez comme quelqu’un est fait sous son nom.

## La bannière de sortie

Pendant que vous voyez comme quelqu’un, une pastille orange foncé s’affiche en haut de chaque écran : **👁 Afficher en tant que *nom* · *rôle*** avec un bouton **Quitter**.

**Quitter** rétablit votre propre session (votre compte, votre agence active et tout aperçu de rôle que vous aviez) et recharge le tableau de bord. Vous n’avez pas à vous reconnecter.

## Bon à savoir

- **Cela n’affecte que cet onglet du navigateur.** Votre propre session est mise de côté dans l’onglet et rétablie en quittant. Un autre onglet où vous êtes connecté reste à votre nom.
- **La session empruntée dure deux heures.** Passé ce délai, elle cesse de fonctionner; appuyez sur **Quitter** et recommencez si vous en avez encore besoin.
- **Chaque démarrage est vérifié.** Le journal d’audit consigne qui a commencé à voir comme qui, avec l’adresse IP et l’appareil.
- **Un aperçu de rôle est mémorisé pour votre compte sur cet appareil**, de sorte que votre prochaine connexion s’ouvre dans le même aperçu. Remettez-le à **Super administrateur (défaut)** quand vous avez terminé.

## Questions courantes

??? La personne sait-elle que j’ai vu comme elle? | Elle n’est pas avisée. Le démarrage est inscrit dans le journal d’audit.
??? Pourquoi un administrateur d’agence ne peut-il pas faire cela? | Voir les données d’une autre personne comme elle est un pouvoir de niveau plateforme; seuls les administrateurs de la plateforme l’ont.
??? J’ai prévisualisé un rôle et il manque des éléments dans mon menu. | Vous êtes toujours en prévisualisation. Remettez la liste Afficher en tant que à Super administrateur (défaut).

Voir aussi : [Administration de la plateforme (aperçu)](platform-admin-overview),
[Journal d’audit](audit-log), [Gérer plus d’une agence](multi-agency-switching).
