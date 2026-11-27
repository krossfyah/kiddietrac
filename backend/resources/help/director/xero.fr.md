---
title: Xero
category: Billing
order: 79
roles: agency_admin, platform_admin
---
# Xero

**Paramètres → Xero** envoie vos factures, annulations, paiements et remboursements KiddieTrac vers votre organisation Xero. Les familles deviennent des contacts Xero, les factures conservent leurs numéros KiddieTrac, et une nouvelle synchronisation les met à jour au lieu de créer des doublons. C’est à sens unique : KiddieTrac → Xero. Rien ici ne déplace d’argent; c’est uniquement de la tenue de livres.

[[open: #xero | Ouvrir Xero]]

L’écran a trois cartes numérotées. Chacune affiche un ✓ une fois terminée et déverrouille la suivante.

## Étape 1 : configurer votre application Xero

KiddieTrac se connecte par **votre propre** application Xero; la connexion appartient donc à votre agence.

1. Allez à [developer.xero.com → My Apps](https://developer.xero.com/app/manage) et créez une **Web app**.
2. Sur la carte KiddieTrac **Votre application Xero**, appuyez sur **Copier** à côté de l’**URI de redirection** et collez-la dans l’application Xero.
3. Copiez l’**ID client** de l’application dans KiddieTrac.
4. Générez un **Secret client** dans Xero et collez-le dans KiddieTrac.
5. Réglez **Type d’application Xero** : choisissez **Created March 2026 or later** pour une nouvelle application, ou **Created before March 2026** pour une plus ancienne.
6. Appuyez sur **Enregistrer les détails de l’application**. Un message indique **Xero app details saved**.

Le secret est stocké chiffré. Laissez plus tard la zone du secret vide pour conserver celui qui est enregistré.

## Étape 2 : connecter votre organisation

1. Sur la carte **Connecter votre organisation**, appuyez sur **Se connecter à Xero →**. Xero s’ouvre dans une nouvelle fenêtre.
2. Connectez-vous à Xero et choisissez votre organisation.
3. Appuyez sur **Allow access**.
4. La fenêtre se ferme et la carte affiche **Connected to** suivi du nom de votre organisation.
5. Si vous avez donné accès à plus d’une organisation, choisissez celle qui recevra les factures et appuyez sur **Utiliser cette organisation**.

Pour la changer plus tard, appuyez sur **Changer d’organisation**, choisissez-en une autre et appuyez sur **Changer**.

## Étape 3 : choisir les comptes et les options de synchronisation

Les listes sont lues en direct dans votre organisation Xero (« Reading accounts from Xero… »).

1. Choisissez le **Sales account for tuition *** (obligatoire) : là où les lignes de facture sont inscrites.
2. Choisissez le **Compte pour les subventions** (une subvention comme CWELCC est inscrite en ligne négative), ou laissez **Même que les frais de garde**.
3. Choisissez le **Compte bancaire pour les paiements** : les paiements par carte et bancaires reçus dans KiddieTrac y sont inscrits. Laissé vide, les paiements ne sont pas envoyés.
4. Choisissez le **Tax rate (only for invoices that charge tax)**. Les services de garde sont habituellement exonérés; une facture qui facture des taxes attend qu’un taux soit choisi.
5. Choisissez comment **Les factures arrivent dans Xero comme** : **Approved (ready for payments)** ou **Draft (you approve them in Xero)**. Les paiements ne peuvent être inscrits que sur des factures approuvées.
6. Réglez **Seulement les factures émises à partir du** sur votre date de début dans Xero, pour que les anciennes factures ne soient pas envoyées deux fois.
7. Cochez **Enregistrer les paiements et remboursements sur les factures dans Xero** et, si vous le souhaitez, **Synchroniser automatiquement toutes les 30 minutes**. C’est désactivé par défaut; désactivé, rien ne part vers Xero tant que vous n’appuyez pas sur **Synchroniser maintenant**.
8. Appuyez sur **Enregistrer les paramètres des comptes et de la synchronisation**. Un message indique **Xero settings saved**.

## Synchroniser et lire le journal de synchronisation

1. Appuyez sur **Synchroniser maintenant**. Le bouton affiche **Syncing…** pendant l’envoi.
2. Lisez le résultat : ce qui a été créé, mis à jour, annulé, ignoré ou a échoué. Une grosse première synchronisation peut indiquer *more waiting*; appuyez de nouveau sur **Synchroniser maintenant**.
3. Consultez le **Journal de synchronisation**. Il liste chaque résultat avec sa raison, par exemple « Choose the Xero sales account for tuition first » ou « Changed in KiddieTrac, but invoice INV-0042 is paid in Xero, so it was left as it is. »

Ce qui est envoyé :

- **Familles → contacts.** Chaque famille devient un contact Xero, marqué du numéro de contact `KT-` suivi du numéro de famille, pour être retrouvée plutôt que dupliquée.
- **Factures émises** (envoyées, partiellement payées, payées, en retard) → factures de vente avec le même numéro. Les brouillons KiddieTrac ne sont jamais envoyés. La subvention et le rabais sont des lignes négatives distinctes pour que le total corresponde.
- **Les modifications** d’une facture mettent à jour la même facture Xero. Une facture déjà payée dans Xero n’est pas touchée, et le journal de synchronisation l’indique.
- **Les factures annulées** sont annulées dans Xero (ou supprimées si elles sont encore un brouillon Xero). Une facture avec des paiements dans Xero n’est pas annulée; retirez d’abord les paiements dans Xero.
- **Les paiements** sont inscrits sur la facture dans le compte bancaire choisi; **les remboursements** retirent ce paiement.

## Changer d’application ou se déconnecter

1. Pour utiliser une autre application Xero, appuyez sur **Changer d’application**. Xero est déconnecté, parce qu’une autre application ne peut pas utiliser la connexion actuelle; reconnectez-vous à l’étape 2.
2. Pour arrêter la synchronisation, appuyez sur **Déconnecter** et confirmez. Tout ce qui est déjà dans Xero y reste, et reconnecter la même organisation reprend là où vous en étiez.
3. Pour retirer aussi l’ID et le secret de votre application, appuyez sur **Effacer et déconnecter**.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d’agence | Configurer, connecter, synchroniser et déconnecter Xero pour son agence. |
| Administrateur de plateforme | La même chose, pour l’agence qu’il consulte. |
| Directeur de service de garde | Ne voit pas cet écran. |

## FAQ et quand quelque chose ne va pas

??? Xero a affiché « invalid scope ». | Xero attribue des autorisations différentes aux applications plus récentes. Changez le Type d’application Xero à l’étape 1, enregistrez et reconnectez-vous.
??? Xero a mis fin à la connexion. | Xero révoque une connexion inutilisée pendant 60 jours, ou retirée dans Xero. Appuyez de nouveau sur Se connecter à Xero → (ou Reconnecter).
??? Une famille apparaît deux fois dans Xero. | Le nom d’un contact Xero doit être unique. Si un autre contact portait déjà le nom de la famille, celui de KiddieTrac est nommé avec (KT-…) à la fin.
??? Les paiements n’arrivent pas dans Xero. | Choisissez un compte bancaire pour les paiements, cochez l’enregistrement des paiements et remboursements, et envoyez les factures comme Approved : Xero ne peut pas inscrire de paiement sur un brouillon.
??? Les étapes 2 et 3 sont grisées. | Chaque carte déverrouille la suivante. Enregistrez d’abord les détails de l’application, puis connectez-vous; l’étape 3 indique « Connect first. » tant que ce n’est pas fait.
??? Les familles sont-elles avisées de quelque chose? | Non. Rien n’est envoyé aux familles depuis cet écran.

## Écrans connexes

[[open: #quickbooks | QuickBooks]] [[open: #sage | Sage]] [[open: #external-billing | Comptabilité]]

Voir aussi : [Synchronisation QuickBooks Online](qbo-sync) · [Sage](sage)
