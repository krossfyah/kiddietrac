---
title: Xero
category: Billing
order: 79
roles: agency_admin, platform_admin
---
# Xero

**Paramètres → Xero.** Envoyez vos factures, annulations, paiements et remboursements KiddieTrac vers votre
organisation Xero. Les familles deviennent des contacts Xero, les factures conservent leurs numéros KiddieTrac, et
une nouvelle synchronisation les met à jour au lieu de créer des doublons. La synchronisation est à sens unique : KiddieTrac → Xero.

L’écran comporte trois étapes. Chacune déverrouille la suivante.

## 1. Votre application Xero

KiddieTrac se connecte par **votre propre** application Xero; la connexion appartient donc à votre agence.

1. Allez à [developer.xero.com → My Apps](https://developer.xero.com/app/manage) et créez une **application Web**.
2. Copiez l’**URI de redirection** depuis l’écran KiddieTrac (il y a un bouton Copier) et collez-la dans l’application.
3. Copiez l’**ID client** de l’application, générez un **secret client** et collez les deux dans KiddieTrac.
4. **Type d’application Xero** : choisissez *Créée en mars 2026 ou après* pour une nouvelle application. Xero attribue
   des autorisations différentes aux applications plus récentes; si Xero affiche un jour **« invalid scope »**, changez ce paramètre et reconnectez-vous.
5. **Enregistrer les détails de l’application.**

Le secret est stocké chiffré. Laissez plus tard la zone du secret vide pour conserver celui qui est enregistré.

## 2. Connecter votre organisation

Appuyez sur **Se connecter à Xero →**. Xero s’ouvre dans une nouvelle fenêtre : connectez-vous, choisissez votre organisation et
appuyez sur **Autoriser l’accès**. La fenêtre se ferme d’elle-même et l’écran affiche **Connecté à …**.

Si vous avez donné accès à plus d’une organisation, KiddieTrac demande à laquelle envoyer les factures.
Vous pouvez changer plus tard avec **Changer d’organisation**.

## 3. Comptes et synchronisation

Les listes affichées ici sont lues en direct depuis votre organisation Xero.

| Paramètre | Ce qu’il fait |
|---|---|
| **Compte de ventes pour les frais de garde** (obligatoire) | L’endroit où les lignes de facture sont comptabilisées. |
| **Compte pour les subventions** | La subvention (p. ex. CWELCC) est ajoutée comme ligne négative. Vide : le compte des frais de garde est utilisé. |
| **Compte bancaire pour les paiements** | Les paiements par carte et bancaires reçus dans KiddieTrac y sont enregistrés. Vide : les paiements ne sont pas encore envoyés. |
| **Taux de taxe** | Seulement pour les factures qui comportent une taxe. La garde d’enfants est habituellement exonérée; une facture qui comporte une taxe attend qu’un taux soit choisi. |
| **Les factures arrivent dans Xero comme** | *Approuvées* (prêtes à recevoir des paiements) ou *Brouillon* (vous les approuvez dans Xero). Les paiements ne peuvent être enregistrés que sur des factures approuvées. |
| **Seulement les factures émises à partir du** | Réglez cette date sur votre date de début dans Xero afin que les anciennes factures ne soient pas envoyées deux fois. |
| **Enregistrer les paiements et les remboursements** | Envoie les paiements sur les factures et retire un paiement qui a été remboursé. |
| **Synchroniser automatiquement toutes les 30 minutes** | Désactivé par défaut. Désactivé signifie que rien n’est envoyé à Xero tant que vous n’appuyez pas sur **Synchroniser maintenant**. |

## Ce qui est envoyé

- **Familles → contacts.** Chaque famille devient un contact Xero, marqué du numéro de contact `KT-` suivi du numéro de famille, afin d’être retrouvé plutôt que dupliqué.
- **Factures émises** (envoyées, partiellement payées, payées, en retard) → factures de ventes portant le même numéro. Les brouillons KiddieTrac ne sont jamais envoyés. La subvention et le rabais sont des lignes négatives distinctes, de sorte que le total concorde.
- **Les modifications** d’une facture mettent à jour la même facture Xero. Une facture déjà payée dans Xero est laissée telle quelle, et le journal de synchronisation l’indique.
- **Les factures annulées** sont annulées dans Xero (ou supprimées, si elles sont encore à l’état de brouillon dans Xero). Une facture comportant des paiements dans Xero n’est pas annulée; retirez d’abord les paiements dans Xero.
- **Les paiements** sont enregistrés sur la facture dans le compte bancaire que vous avez choisi; **les remboursements** retirent ce paiement.

Rien ici ne déplace d’argent. Il s’agit uniquement de comptabilité.

## Synchroniser maintenant et le journal de synchronisation

**Synchroniser maintenant** envoie tout ce qui est en attente et indique ce qui a été créé, mis à jour, annulé, ignoré ou
en échec. Une première synchronisation volumineuse peut indiquer *il en reste*; appuyez de nouveau sur **Synchroniser maintenant**.

Le **Journal de synchronisation** liste chaque résultat avec sa raison, p. ex. *« Choisissez d’abord le compte de ventes Xero pour
les frais de garde »* ou *« Modifiée dans KiddieTrac, mais la facture INV-0042 est payée dans Xero; elle a donc été laissée telle quelle. »*

## Se déconnecter

**Déconnecter** arrête la synchronisation; tout ce qui se trouve déjà dans Xero y reste, et reconnecter la même
organisation reprend là où vous en étiez. **Effacer et déconnecter** supprime aussi l’ID et le secret de votre application.

## Questions courantes

**« Xero a affiché invalid scope. »** Changez le **Type d’application Xero** à l’étape 1 et reconnectez-vous.

**« Xero a mis fin à la connexion. »** Xero révoque une connexion inutilisée pendant 60 jours, ou retirée
dans Xero. Appuyez de nouveau sur **Se connecter à Xero →**.

**« Une famille apparaît deux fois dans Xero. »** Le nom d’un contact Xero doit être unique. Si un autre contact
portait déjà le nom de la famille, celui de KiddieTrac est nommé avec `(KT-…)` à la suite.

Voir aussi : [Synchronisation QuickBooks Online](qbo-sync) · [Sage](sage)
