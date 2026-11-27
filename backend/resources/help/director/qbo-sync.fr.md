---
title: Synchronisation QuickBooks Online
category: Billing
order: 78
roles: agency_admin, platform_admin
---
# Synchronisation QuickBooks Online

**Paramètres → QuickBooks (Intuit)** envoie vos factures, annulations, paiements et remboursements KiddieTrac vers votre entreprise QuickBooks Online. Les familles deviennent des clients, les factures conservent leurs numéros KiddieTrac, et une nouvelle synchronisation les met à jour au lieu de créer des doublons. C’est à sens unique : KiddieTrac → QuickBooks. Rien ici ne déplace d’argent; c’est uniquement de la tenue de livres.

[[open: #quickbooks | Ouvrir QuickBooks]]

L’écran a trois cartes numérotées, comme Xero et Sage. Chacune affiche un ✓ une fois terminée et déverrouille la suivante.

## Étape 1 : configurer votre application Intuit

1. Dans [developer.intuit.com → Dashboard](https://developer.intuit.com/app/developer/dashboard), créez une application avec la portée **Accounting**.
2. Sur la carte KiddieTrac **Votre application Intuit**, appuyez sur **Copier** à côté de l’**URI de redirection**.
3. Dans votre application Intuit, sous **Keys & credentials**, collez l’URI de redirection.
4. Copiez l’**ID client** et le **Secret client** de l’application dans KiddieTrac.
5. Choisissez l’**Environnement** : *Production* pour votre vraie entreprise, ou *Sandbox* pour faire un essai avec une entreprise de test et les clés Development.
6. Appuyez sur **Enregistrer les détails de l’application**. Un message indique **QuickBooks app details saved**.

Si votre agence n’a enregistré aucune application à elle, l’écran indique « No app of your own saved — the KiddieTrac platform app is being used. » Le secret est conservé de façon confidentielle; laissez sa zone vide plus tard pour garder celui qui est enregistré.

## Étape 2 : connecter votre entreprise

1. Sur la carte **Connecter votre entreprise**, appuyez sur **Se connecter à QuickBooks →**. QuickBooks s’ouvre dans une nouvelle fenêtre.
2. Connectez-vous à Intuit et choisissez votre entreprise.
3. Appuyez sur **Connect** dans la fenêtre Intuit.
4. La fenêtre se ferme et la carte affiche **Connected to** suivi du nom de votre entreprise.

## Étape 3 : choisir les produits, les comptes et les options de synchronisation

Les listes sont lues en direct dans votre entreprise QuickBooks (« Reading your products and accounts from QuickBooks… »).

1. Choisissez le **Product/service for tuition *** (obligatoire). Les lignes de facture utilisent cet article; elles aboutissent donc dans son compte de revenus.
2. Choisissez le **Produit/service pour les subventions** (une subvention comme CWELCC est inscrite en ligne négative), ou laissez **Même que les frais de garde**.
3. Choisissez où **Déposer les paiements dans** (par défaut : **Undeposited Funds (default)**).
4. Choisissez le **Code de taxe pour les lignes non taxées** (les entreprises canadiennes en ont besoin, habituellement *Exempt*) et, seulement si vos factures facturent des taxes, le **Code de taxe pour les lignes taxées**.
5. Réglez **Seulement les factures émises à partir du** sur votre date de début dans QuickBooks, pour que les anciennes factures ne soient pas dupliquées.
6. Cochez **Enregistrer les paiements et remboursements sur les factures dans QuickBooks** et, si vous le souhaitez, **Synchroniser automatiquement toutes les 30 minutes** (désactivé par défaut).
7. Appuyez sur **Enregistrer les paramètres des produits et de la synchronisation**. Un message indique **QuickBooks settings saved**.

## Synchroniser et lire le journal de synchronisation

1. Appuyez sur **Synchroniser maintenant**. Le bouton affiche **Syncing…** pendant l’envoi.
2. Lisez le résultat, puis consultez **📜 Journal de synchronisation**. Il liste chaque facture et chaque paiement, avec ce qui s’est passé et pourquoi.
3. Sous le bouton, **Last sync** indique la dernière exécution et si la synchronisation automatique est activée.

Ce qui est envoyé :

- **Familles → clients**, retrouvés à chaque synchronisation plutôt que dupliqués.
- **Factures émises** (jamais les brouillons KiddieTrac) avec le même numéro de facture; la subvention et le rabais sont des lignes négatives pour que le total corresponde.
- **Les modifications** mettent à jour la même facture QuickBooks; une facture déjà entièrement payée dans QuickBooks n’est pas touchée.
- **Les annulations** sont annulées dans QuickBooks, sauf si des paiements y sont appliqués.
- **Les paiements** sont reçus sur la facture; un paiement remboursé est supprimé.

## Changer d’application ou se déconnecter

1. Pour utiliser une autre application Intuit ou un autre environnement, appuyez sur **Changer d’application**. QuickBooks est déconnecté, parce que l’ancienne connexion appartient à l’ancienne application; reconnectez-vous à l’étape 2.
2. Pour arrêter la synchronisation, appuyez sur **Déconnecter** et confirmez. Tout ce qui est déjà dans QuickBooks y reste, et reconnecter la même entreprise reprend là où vous en étiez.
3. Pour retirer aussi les identifiants de votre application, appuyez sur **Effacer et déconnecter**.

> ℹ️ Avant octobre 2026, l’écran QuickBooks pouvait se connecter mais n’envoyait jamais réellement de facture. Rien n’a jamais été transmis; il n’y a donc rien à nettoyer. Commencez à l’étape 1.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d’agence | Configurer, connecter, synchroniser et déconnecter QuickBooks pour son agence. |
| Administrateur de plateforme | La même chose, pour l’agence qu’il consulte. |
| Directeur de service de garde | Ne voit pas cet écran. |

## FAQ et quand quelque chose ne va pas

??? Les étapes 2 et 3 sont grisées. | Chaque carte déverrouille la suivante. Enregistrez d’abord les détails de l’application, puis connectez-vous; l’étape 3 indique « Connect first. » tant que ce n’est pas fait.
??? « Could not read your products and accounts from QuickBooks. » | La connexion a expiré ou a été retirée dans QuickBooks. Appuyez sur Reconnecter et connectez-vous de nouveau.
??? Une facture est en attente et n’est pas envoyée. | Ouvrez le journal de synchronisation pour en voir la raison. Un cas courant : une facture qui facture des taxes alors qu’aucun code de taxe pour les lignes taxées n’est choisi.
??? « Choose the product/service for tuition. » | L’étape 3 a besoin d’un article pour les frais de garde avant que quoi que ce soit puisse être enregistré ou synchronisé.
??? D’anciennes factures sont apparues en double. | Réglez Seulement les factures émises à partir du sur votre date de début QuickBooks avant la première synchronisation.
??? Les familles sont-elles avisées de quelque chose? | Non. Rien n’est envoyé aux familles depuis cet écran.

## Écrans connexes

[[open: #xero | Xero]] [[open: #sage | Sage]] [[open: #external-billing | Comptabilité]]

Voir aussi : [Xero](xero) · [Sage](sage)
