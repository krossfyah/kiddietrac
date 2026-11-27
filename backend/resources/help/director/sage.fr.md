---
title: Sage (Sage Accounting et Sage 50)
category: Billing
order: 80
roles: agency_admin, platform_admin
---
# Sage (Sage Accounting et Sage 50)

**Paramètres → Sage** envoie vos factures et paiements KiddieTrac vers Sage. L’écran couvre les deux produits Sage; choisissez l’onglet de celui que vous utilisez :

- **☁️ Sage Accounting (cloud)** se synchronise en direct, comme Xero et QuickBooks.
- **🖥️ Sage 50 (desktop)** n’a pas de connexion en ligne; KiddieTrac produit donc les fichiers que Sage 50 importe.

[[open: #sage | Ouvrir Sage]]

## Sage Accounting : configurer votre application Sage

1. Ouvrez l’onglet **☁️ Sage Accounting (cloud)**.
2. Dans [Sage Developer Self Service](https://developerselfservice.sageone.com/), créez une application.
3. Sur la carte KiddieTrac **Votre application Sage**, appuyez sur **Copier** à côté de l’**URL de rappel** et ajoutez-la à l’application Sage.
4. Collez l’**ID client** et le **Secret client** de l’application dans KiddieTrac.
5. Appuyez sur **Enregistrer les détails de l’application**. Un message indique **Sage app details saved**.

## Sage Accounting : connecter votre entreprise

1. Sur la carte **Connecter votre entreprise**, appuyez sur **Se connecter à Sage →**. Sage s’ouvre dans une nouvelle fenêtre.
2. Connectez-vous à Sage et autorisez l’accès.
3. Si vous avez plusieurs entreprises dans Sage, choisissez celle qui recevra les factures et appuyez sur **Utiliser cette entreprise**. Vous pourrez la changer plus tard avec **Changer d’entreprise**.

## Sage Accounting : comptes et synchronisation

Les listes sont lues en direct dans Sage (« Reading your accounts from Sage… »).

1. Choisissez le **Sales ledger account for tuition *** (obligatoire) : là où les lignes de facture sont inscrites.
2. Choisissez le **Compte pour les subventions** (une subvention comme CWELCC est inscrite en ligne négative), ou laissez **Même que les frais de garde**.
3. Choisissez le **Compte bancaire pour les paiements** : les paiements reçus dans KiddieTrac y deviennent des encaissements clients.
4. Choisissez le **Taux de taxe pour les lignes non taxables** (les services de garde sont habituellement exonérés) et, seulement si vos factures facturent des taxes, le **Taux de taxe pour les lignes taxables**. Une facture qui facture des taxes attend qu’un taux taxable soit choisi.
5. Réglez **Seulement les factures émises à partir du** sur votre date de début dans Sage, pour que les anciennes factures ne soient pas dupliquées.
6. Cochez **Enregistrer les paiements et les remboursements sur les factures dans Sage** et, si vous le souhaitez, **Synchroniser automatiquement toutes les 30 minutes** (désactivé par défaut).
7. Appuyez sur **Enregistrer les paramètres des comptes et de la synchronisation**. Un message indique **Sage settings saved**.
8. Appuyez sur **Synchroniser maintenant**, puis consultez le **Journal de synchronisation** pour voir ce qui est arrivé à chaque facture et chaque paiement, et pourquoi.

Ce qui est envoyé : chaque famille devient un client Sage (référence `KT-` + numéro de famille); chaque facture émise devient une facture de vente dont la **référence** est le numéro de facture KiddieTrac (Sage numérote ses propres factures); les annulations sont annulées dans Sage; les paiements sont imputés à la facture et les remboursements les retirent.

**Déconnecter** arrête la synchronisation; tout ce qui est déjà dans Sage y reste, et la reconnexion reprend là où vous en étiez. **Effacer et déconnecter** retire aussi l’ID et le secret de votre application. **Changer d’application** déconnecte, parce qu’une autre application ne peut pas utiliser la connexion actuelle.

## Sage 50 : entrer vos numéros de compte

Une fois votre entreprise configurée, Sage 50 **n’importe pas** de lignes de journal dans son propre compte lié **Accounts Receivable**; il vous faut donc d’abord un compte de plus.

1. Dans Sage 50, créez un compte d’actif pour les soldes des familles (**Setup → Chart of Accounts**), par exemple **1210 Comptes clients des familles KiddieTrac**.
2. Dans KiddieTrac, ouvrez l’onglet **🖥️ Sage 50 (desktop)**.
3. Sous **Vos numéros de compte Sage 50**, entrez vos numéros de compte (4 à 8 chiffres) :

| Compte | Exemple | Nécessaire? |
|---|---|---|
| **Compte clients des familles** | 1210 | Obligatoire. Le compte que vous venez de créer, pas votre compte clients lié. |
| **Compte des revenus de frais de garde** | 4100 | Obligatoire. |
| **Compte bancaire pour les paiements** | 1060 | Pour inclure les paiements. Doit être différent du compte clients. |
| **Compte des subventions** | 1220 | Facultatif. Vide : les frais de garde sont inscrits nets de la subvention. |
| **Compte des rabais** | 4190 | Facultatif. Vide : les frais de garde sont inscrits nets des rabais. |
| **Compte de taxes à payer** | 2310 | Seulement si vos factures facturent des taxes. |

4. Appuyez sur **Enregistrer les numéros de compte**. Vous voyez **Sage 50 account numbers saved**.

## Sage 50 : importer vos clients

Faites-le une fois, puis de nouveau quand de nouvelles familles s’inscrivent.

1. Sous **Customers file (once, then for new families)**, appuyez sur **⬇️ Download customers (CSV)**. Vos familles actuelles sont téléchargées dans l’ordre de colonnes attendu par Sage 50.
2. Dans Sage 50, choisissez **File → Import/Export → Import Records → Customers**.
3. Choisissez le fichier, vérifiez que les colonnes correspondent, et terminez.

## Sage 50 : importer les factures et les paiements

1. Sous **Journal file — invoices and payments**, réglez au besoin **From (optional)** et **Au (facultatif)**. La première fois, réglez **From** sur votre date de début dans Sage 50. Laissez les deux vides pour inclure tout ce qui n’a pas encore été exporté.
2. Appuyez sur **Aperçu** pour voir ce que contiendra le fichier, par exemple « 12 invoices, 9 payments — 21 journal entries ». Tout avertissement ⚠️ vous dit quoi corriger d’abord.
3. Appuyez sur **⬇️ Download journal file**.
4. Faites une copie de sauvegarde de votre entreprise Sage 50.
5. Dans Sage 50, choisissez **File → Import/Export → Import Transactions → Import general journal entries**, puis le fichier `.TXT`.

Chaque facture émise forme une écriture équilibrée : le compte clients des familles est débité de ce que la famille doit, la subvention et le rabais (s’ils sont associés) sont débités, les revenus de frais de garde et la taxe sont crédités. Chaque paiement débite la banque et crédite le compte clients des familles.

**Chaque fichier ne contient que ce qui est nouveau depuis le précédent.** Si les montants d’une facture changent après son exportation, le fichier suivant contient une **écriture de redressement** pour la différence; une facture exportée puis annulée, ou un paiement exporté puis remboursé, reçoit une **écriture de contrepassation**. Chaque fichier produit est listé sous **📁 Files made** et peut être téléchargé de nouveau.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d’agence | Configurer et utiliser les deux onglets Sage pour son agence. |
| Administrateur de plateforme | La même chose, pour l’agence qu’il consulte. |
| Directeur de service de garde | Ne voit pas cet écran. |

## FAQ et quand quelque chose ne va pas

??? Sage 50 a refusé mon fichier de journal. | Rien n’est inscrit quand Sage 50 refuse un fichier. Corrigez les numéros de compte, puis téléchargez de nouveau le même fichier depuis Files made et importez-le.
??? Pourquoi ne puis-je pas utiliser mon compte Accounts Receivable dans Sage 50? | Sage 50 n’accepte pas de lignes de journal importées dans son compte clients lié une fois l’entreprise configurée. Utilisez le compte clients des familles distinct.
??? L’aperçu indique « Nothing new to export. » | Tout ce qui se trouve dans cette période a déjà été exporté. Chaque fichier ne contient que ce qui est nouveau depuis le précédent; téléchargez de nouveau un fichier antérieur depuis Files made au besoin.
??? Les étapes 2 et 3 de l’onglet cloud sont grisées. | Chaque carte déverrouille la suivante. Enregistrez d’abord les détails de l’application, puis connectez-vous; l’étape 3 indique « Connect first. » tant que ce n’est pas fait.
??? Une facture est en attente et n’est pas envoyée à Sage Accounting. | Ouvrez le journal de synchronisation pour en voir la raison, par exemple une facture qui facture des taxes alors qu’aucun taux de taxe pour les lignes taxables n’est choisi.
??? Les familles sont-elles avisées de quelque chose? | Non. Rien n’est envoyé aux familles depuis cet écran.

## Écrans connexes

[[open: #xero | Xero]] [[open: #quickbooks | QuickBooks]] [[open: #external-billing | Comptabilité]]

Voir aussi : [Xero](xero) · [Synchronisation QuickBooks Online](qbo-sync)
