---
title: Sage (Sage Accounting et Sage 50)
category: Billing
order: 80
roles: agency_admin, platform_admin
---
# Sage

**Paramètres → Sage** couvre les deux produits Sage. Choisissez l’onglet correspondant à celui que vous utilisez :

- **☁️ Sage Accounting (infonuagique)** se synchronise en direct, comme Xero et QuickBooks.
- **🖥️ Sage 50 (bureau)** n’a pas de connexion en ligne; KiddieTrac génère donc les fichiers que Sage 50 importe.

## Sage Accounting (infonuagique)

### 1. Votre application Sage

1. Dans [Sage Developer Self Service](https://developerselfservice.sageone.com/), créez une application.
2. Ajoutez l’**URL de rappel** affichée à l’écran de KiddieTrac (utilisez **Copier**).
3. Collez l’**ID client** et le **Secret client** de l’application dans KiddieTrac, puis **Enregistrer les détails de l’application**.

### 2. Connecter votre entreprise

Appuyez sur **Se connecter à Sage →**, connectez-vous et autorisez l’accès. Si vous avez plusieurs entreprises dans Sage,
choisissez celle à laquelle envoyer les factures (vous pourrez changer plus tard).

### 3. Comptes et synchronisation

| Paramètre | Ce qu’il fait |
|---|---|
| **Compte de ventes pour les frais de garde** (obligatoire) | Où les lignes de facture sont comptabilisées. |
| **Compte pour les subventions** | La subvention est saisie comme une ligne négative. Vide : utilise le compte des frais de garde. |
| **Compte bancaire pour les paiements** | Les paiements reçus dans KiddieTrac deviennent des encaissements clients ici. |
| **Taux de taxe pour les lignes non taxées / taxées** | Les services de garde sont généralement exemptés. Une facture qui comporte une taxe attend qu’un taux taxé soit choisi. |
| **Seulement les factures émises à partir du** | Votre date de début dans Sage, afin que les anciennes factures ne soient pas dupliquées. |
| **Enregistrer les paiements et les remboursements** / **Synchroniser automatiquement toutes les 30 minutes** | Comme pour Xero. La synchronisation automatique est désactivée par défaut. |

Ce qui est envoyé : chaque famille devient un client Sage (référence `KT-` + numéro de famille); chaque facture
émise devient une facture de vente dont la **référence** est le numéro de facture KiddieTrac (Sage numérote
ses propres factures); les annulations sont annulées dans Sage; les paiements sont affectés à la facture et les remboursements
les retirent. **Synchroniser maintenant** et le **Journal de synchronisation** fonctionnent exactement comme sur l’écran Xero.

## Sage 50 (bureau)

Sage 50 importe deux types de fichiers : les **fiches** (clients) et les **écritures de journal général**.
KiddieTrac génère les deux.

### 1. Vos numéros de compte — et un compte à créer d’abord

Sage 50 **n’importera pas** de lignes de journal vers son propre compte **Comptes clients** lié une fois
votre entreprise configurée. Commencez donc par créer dans Sage 50 un compte d’actif pour les soldes des familles
(**Configuration → Plan comptable**), par exemple **1210 Comptes clients KiddieTrac – familles**.

Entrez ensuite vos numéros de compte (4 à 8 chiffres) dans KiddieTrac :

| Compte | Exemple | Requis? |
|---|---|---|
| **Compte client des familles** | 1210 | Obligatoire. Le compte que vous venez de créer, et non votre compte clients lié. |
| **Revenus de frais de garde** | 4100 | Obligatoire. |
| **Compte bancaire pour les paiements** | 1060 | Pour inclure les paiements. Doit être différent du compte client. |
| **Compte de subvention** | 1220 | Facultatif. Vide : les frais sont comptabilisés nets de la subvention. |
| **Compte de rabais** | 4190 | Facultatif. Vide : les frais sont comptabilisés nets des rabais. |
| **Taxes à payer** | 2310 | Seulement si vos factures comportent une taxe. |

### 2. Fichier des clients

**⬇️ Télécharger les clients (CSV)** : vos familles actuelles, dans l’ordre des colonnes attendu par Sage 50.
Dans Sage 50 : **Fichier → Importer/Exporter → Importer des fiches → Clients**, choisissez le fichier, vérifiez
que les colonnes correspondent, puis terminez. Faites-le une fois, puis de nouveau quand de nouvelles familles s’ajoutent.

### 3. Fichier de journal

1. Au besoin, définissez les dates **De / À**. La première fois, mettez comme **De** votre date de début dans Sage 50.
2. Appuyez sur **Aperçu** pour voir ce que contiendra le fichier.
3. Appuyez sur **⬇️ Télécharger le fichier de journal**.
4. Sauvegardez votre entreprise dans Sage 50, puis **Fichier → Importer/Exporter → Importer des transactions → Importer des écritures de journal général**, et choisissez le fichier `.TXT`.

Chaque facture émise forme une écriture équilibrée : le compte client des familles est débité du montant dû par la famille,
la subvention et le rabais (s’ils sont associés à un compte) sont débités, les revenus de frais de garde et la taxe sont crédités. Chaque
paiement débite la banque et crédite le compte client des familles.

**Chaque fichier ne contient que ce qui est nouveau depuis le précédent.** Si les montants d’une facture changent après
son exportation, le fichier suivant contient une **écriture de redressement** pour la différence; une facture exportée
qui est annulée, ou un paiement exporté qui est remboursé, donne lieu à une **écriture d’annulation**.

Chaque fichier généré figure sous **Fichiers générés** et peut être téléchargé de nouveau. Si Sage 50
refuse un fichier, rien n’est comptabilisé : corrigez les numéros de compte et importez de nouveau le même fichier.

Voir aussi : [Xero](xero) · [Synchronisation QuickBooks Online](qbo-sync)
