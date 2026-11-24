---
title: Synchronisation QuickBooks Online
category: Billing
order: 78
roles: agency_admin, platform_admin
---
# Synchronisation QuickBooks Online

**Paramètres → QuickBooks.** Envoyez vos factures, annulations, paiements et remboursements KiddieTrac à votre
société QuickBooks Online. Les familles deviennent des clients, les factures conservent leurs numéros KiddieTrac,
et une nouvelle synchronisation les met à jour au lieu de créer des doublons. La synchronisation est à sens unique : KiddieTrac → QuickBooks.

L’écran comporte trois étapes, les mêmes que pour Xero et Sage.

## 1. Votre application Intuit

1. Dans [developer.intuit.com → Dashboard](https://developer.intuit.com/app/developer/dashboard), créez une application avec la portée **Accounting**.
2. Sous **Keys & credentials**, ajoutez l’**URI de redirection** affichée dans KiddieTrac (utilisez **Copier**).
3. Collez l’**ID client** et le **Secret client** dans KiddieTrac.
4. **Environnement** : *Production* pour votre vraie société, ou *Sandbox* pour essayer avec une société de test et les clés de développement.
5. **Enregistrer les détails de l’application.**

Si votre agence n’a pas enregistré sa propre application, l’application de la plateforme KiddieTrac est utilisée (l’écran l’indique).

## 2. Connecter votre société

Appuyez sur **Se connecter à QuickBooks →**, ouvrez une session Intuit, choisissez votre société et appuyez sur **Connecter**.
La fenêtre se ferme et l’écran affiche **Connecté à …**.

## 3. Produits, comptes et synchronisation

Les listes sont lues en direct à partir de votre société QuickBooks.

| Paramètre | Ce qu’il fait |
|---|---|
| **Produit/service pour les frais de garde** (obligatoire) | Les lignes de facture utilisent cet article, donc elles aboutissent dans son compte de revenus. |
| **Produit/service pour les subventions** | La subvention est ajoutée comme ligne négative. Si vide, l’article des frais de garde est utilisé. |
| **Déposer les paiements dans** | Là où les paiements sont reçus. Par défaut : Fonds non déposés. |
| **Code de taxe pour les lignes non taxées** | Les sociétés canadiennes en ont besoin d’un (généralement *Exonéré*). |
| **Code de taxe pour les lignes taxées** | Seulement pour les factures qui facturent une taxe; ces factures attendent qu’un code soit choisi. |
| **Seulement les factures émises à partir du** | Votre date de début dans QuickBooks, pour que les factures plus anciennes ne soient pas dupliquées. |
| **Enregistrer les paiements et remboursements** | Les paiements sont reçus sur la facture; une facture remboursée est supprimée. |
| **Synchroniser automatiquement toutes les 30 minutes** | Désactivé par défaut. |

## Ce qui est envoyé

- **Familles → clients**, retrouvés à chaque synchronisation plutôt que dupliqués.
- **Factures émises** (jamais les brouillons KiddieTrac) avec le même numéro de facture; la subvention et le rabais sont des lignes négatives pour que le total corresponde.
- **Les modifications** mettent à jour la même facture QuickBooks; une facture déjà entièrement payée dans QuickBooks est laissée telle quelle.
- **Les annulations** sont annulées dans QuickBooks, sauf si des paiements y sont appliqués.
- **Paiements et remboursements**, comme ci-dessus.

## Synchroniser maintenant et le journal de synchronisation

**Synchroniser maintenant** envoie tout ce qui est en attente et indique le résultat. Le **Journal de synchronisation** énumère chaque
facture et chaque paiement avec ce qui s’est passé et pourquoi.

## Déconnexion

**Déconnecter** arrête la synchronisation; ce qui se trouve dans QuickBooks y reste. **Effacer et déconnecter** supprime aussi
les identifiants de votre application. Changer d’application ou d’environnement déconnecte, car l’ancienne
connexion appartient à l’ancienne application.

> **Avant octobre 2026**, l’écran QuickBooks pouvait se connecter mais n’envoyait jamais réellement une facture.
> Rien n’a jamais été transmis, il n’y a donc rien à nettoyer. Commencez par l’étape 1.

Voir aussi : [Xero](xero) · [Sage](sage)
