---
title: Dépenses (fournisseurs, bons de commande et factures fournisseurs)
category: Billing
order: 85
roles: agency_admin, platform_admin
---
# Dépenses

**Finances → Dépenses.** C’est l’argent que votre agence verse. Suivez les fournisseurs, les bons de commande et les factures fournisseurs, de la commande à l’approbation puis au paiement.

[[open: #expenses | Ouvrir les dépenses]]

L’écran comporte quatre onglets.

## 📊 Résumé

- **En souffrance** : le solde impayé de toutes les factures.
- **En retard** : le montant dont l’échéance est dépassée, et le nombre de factures.
- **Payé ce mois-ci**, **Fournisseurs** et **Bons de commande ouverts** (brouillons et commandes pas encore reçues).

Sous les tuiles, les dépenses sont ventilées **Par statut** et **Dépenses par catégorie**.

## 🏢 Fournisseurs

**+ Nouveau fournisseur** enregistre un fournisseur : **Nom du fournisseur** (obligatoire), **Nom du contact**, **Centre**, **Courriel**, **Téléphone**, **Catégorie par défaut**, **N° de taxe / d’entreprise**, **Adresse** et un interrupteur **Actif**. La liste indique ce que vous devez encore à chaque fournisseur. Cliquez sur un fournisseur pour le modifier ou le supprimer.

> **Astuce :** Décochez **Actif** pour un fournisseur que vous n’utilisez plus. Il reste au dossier avec son historique au lieu d’être supprimé.

## 📦 Bons de commande

[[show: #expenses @ Purchase orders > + New purchase order | Montrez-moi Nouveau bon de commande]]

**+ Nouveau bon de commande** : choisissez le **Fournisseur** et, au besoin, un **Centre**, indiquez la **Date de commande** et la **Catégorie**, puis ajoutez les **Lignes** et les taxes. **Créer le bon de commande** l’enregistre comme commandé.

Ouvrez un bon de commande pour le faire avancer :

- **Marquer comme reçu** à l’arrivée des marchandises.
- **→ Convertir en facture** à la réception de la facture du fournisseur. Cela crée la facture à partir de la commande, de sorte que rien n’est saisi deux fois. Offert jusqu’à ce que la commande soit marquée comme reçue.
- **Modifier** pour le changer.

## 🧾 Factures fournisseurs

Les factures fournisseurs sont les factures de vos fournisseurs. **+ Nouvelle facture** en enregistre une avec son **Fournisseur**, son **Centre**, son **N° de facture du fournisseur**, sa **Catégorie**, sa **Date d’échéance** et ses **Lignes**. Vous pouvez filtrer la liste par statut : **En attente d’approbation**, **Approuvée**, **Partiellement payée**, **En retard**, **Payée** ou **Brouillon**.

Ouvrez une facture pour agir dessus :

- [ ] **✓ Approuver** : pour une facture en brouillon ou en attente d’approbation.
- [ ] **💵 Enregistrer un paiement** : saisissez le **Montant**, la **Méthode** (virement bancaire, chèque, virement Interac, carte de crédit, argent comptant ou autre) et une **Référence** facultative. Un paiement partiel laisse le reste indiqué comme dû.
- [ ] **Annuler** : annule une facture que vous ne devriez pas payer. Une confirmation vous est demandée.

> **Remarque :** Enregistrer un paiement ici consigne que vous avez payé. Cela n’envoie pas d’argent au fournisseur.

## Qui peut l’utiliser

C’est dans le menu **Finances** pour les **administrateurs de l’agence** et les **administrateurs de la plateforme**.

Voir aussi : [Comptabilité](accounting) · [Cycles de paie, heures et talons de paie](payroll-documents)
