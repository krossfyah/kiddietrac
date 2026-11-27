---
title: Dépenses (fournisseurs, bons de commande et factures fournisseurs)
category: Billing
order: 85
roles: agency_admin, centre_director, platform_admin
---
# Dépenses (fournisseurs, bons de commande et factures fournisseurs)

**Finances → Dépenses**, c'est l'argent que votre agence ou votre centre verse. Gardez la liste de vos fournisseurs, émettez des bons de commande, saisissez la facture du fournisseur quand elle arrive, approuvez-la et consignez chaque paiement. Ouvrez cet écran quand la facture d'un fournisseur arrive sur votre bureau ou pour voir ce qui reste à payer.

[[open: #expenses | Ouvrir Dépenses]]

L'écran compte quatre onglets : **📊 Résumé**, **🧾 Factures fournisseurs**, **📦 Bons de commande** et **🏢 Fournisseurs**.

## Lire le résumé

1. Ouvrez **Finances → Dépenses**. L'onglet **📊 Résumé** s'ouvre en premier.
2. Lisez les tuiles : **En souffrance** (le solde impayé des factures), **En retard** (le montant échu et le nombre de factures), **Payé ce mois-ci**, **Fournisseurs** et **Bons de commande ouverts** (bons en brouillon ou commandés).
3. Faites défiler jusqu'à **Par statut** et **Dépenses par catégorie** pour voir où va l'argent.

## Ajouter un fournisseur

1. Ouvrez l'onglet **🏢 Fournisseurs**.
2. Appuyez sur **+ Nouveau fournisseur**.
3. Saisissez le **Nom du fournisseur** (le seul champ obligatoire).
4. Ajoutez le **Nom du contact**, le **Courriel**, le **Téléphone**, l'**Adresse** et le **Tax / business #** (numéro de taxe ou d'entreprise) si vous les avez.
5. Choisissez le **Centre** que sert le fournisseur, ou laissez **All centres**, puis une **Catégorie par défaut** pour que les nouvelles factures la reprennent.
6. Appuyez sur **Créer le fournisseur**. Le message **Supplier saved** s'affiche et le fournisseur s'ajoute à la liste, avec ce que vous lui devez encore.

> 💡 Un fournisseur que vous n'utilisez plus : ouvrez le menu de sa ligne et choisissez **🗄 Archiver**. Il reste sur les anciennes factures et peut revenir avec **♻️ Restaurer**. Ne supprimez qu'un fournisseur saisi par erreur.

## Émettre un bon de commande

[[show: #expenses @ Purchase orders > + New purchase order | Me montrer Nouveau bon de commande]]

1. Ouvrez l'onglet **📦 Bons de commande**.
2. Appuyez sur **+ Nouveau bon de commande**.
3. Choisissez le **Fournisseur**, et le **Centre** si la commande vise un seul centre.
4. Indiquez la **Date de commande** et la **Catégorie**.
5. Sous **Articles de la facture**, saisissez pour chaque article une **Description**, la **Qty** et le **Unit $**, puis les taxes dans **Tax ($)**. Le sous-total et le total se mettent à jour pendant la saisie.
6. Appuyez sur **Créer le bon de commande**. Le message **PO created** s'affiche et la commande s'ajoute à la liste.

## Transformer une commande en facture

1. Dans l'onglet **📦 Bons de commande**, ouvrez la commande.
2. Quand la facture du fournisseur arrive, appuyez sur **→ Convertir en facture fournisseur**. La facture est créée à partir des lignes de la commande, sans rien ressaisir, et l'écran passe à **🧾 Factures fournisseurs**.
3. Si la commande n'aura pas lieu, choisissez plutôt **🚫 Annuler la commande** dans le menu de sa ligne.

> ⚠️ **→ Convertir en facture fournisseur** n'est offert que tant que la commande n'est ni reçue ni annulée. Convertissez d'abord la commande; n'utilisez **Marquer comme reçu** que pour une commande qui n'aura pas sa propre facture.

## Saisir une facture fournisseur

1. Ouvrez l'onglet **🧾 Factures fournisseurs**.
2. Appuyez sur **+ Nouvelle facture fournisseur**.
3. Choisissez le **Fournisseur**. Sa catégorie par défaut se remplit.
4. Choisissez le **Centre**, saisissez le **Supplier invoice #** (numéro de facture du fournisseur) et la **Date d'échéance**.
5. Saisissez les **Articles de la facture** et les taxes dans **Tax ($)**.
6. Appuyez sur **Créer la facture fournisseur**. Le message **Bill created** s'affiche.

Utilisez le filtre de statut au-dessus de la liste pour ne voir que les factures **Brouillon**, **En attente d'approbation**, **Approuvé**, **Partiellement payé**, **En retard** ou **Payé**.

## Approuver et payer une facture

1. Dans l'onglet **🧾 Factures fournisseurs**, ouvrez la facture (ou choisissez **👁 Voir** dans le menu de sa ligne).
2. Appuyez sur **✓ Approuver**. Le message **Facture fournisseur approuvée** s'affiche.
3. Au moment de payer, appuyez sur **💵 Enregistrer le paiement**.
4. Saisissez le **Montant**, choisissez la **Méthode** (**Bank transfer**, **Cheque**, **e-Transfer**, **Credit card**, **Cash** ou **Other**) et ajoutez une **Reference (optional)**, comme le numéro du chèque.
5. Appuyez sur **Enregistrer le paiement**. Le message **Paiement enregistré** s'affiche. Un paiement partiel laisse le reste comme solde et la facture passe à **Partiellement payé**.

> ℹ️ Enregistrer un paiement ne fait que consigner que vous avez payé. KiddieTrac n'envoie pas d'argent au fournisseur.

Pour annuler une facture que vous ne paierez pas, choisissez **🚫 Annuler** dans le menu de sa ligne et confirmez. Elle reste au dossier avec le statut **Void**.

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Administrateur d'agence | Tout, pour chaque centre et pour les dossiers de toute l'agence. |
| Directeur de centre | Ajoute et modifie les fournisseurs, commandes et factures de ses propres centres. Voit les fournisseurs et dossiers de toute l'agence, sans pouvoir les modifier. |
| Administrateur de plateforme | Comme un administrateur d'agence, dans l'agence où il travaille. |

Les éducatrices et les parents ne voient pas cet écran. Dépenses fait partie de votre forfait KiddieTrac; si le forfait de votre agence ne l'inclut pas, l'écran n'est pas dans le menu.

## FAQ et problèmes courants

??? Je ne trouve pas Dépenses dans le menu. | Il est sous **Finances** pour les administrateurs d'agence et les directeurs de centre. S'il manque, le forfait de votre agence n'inclut pas Dépenses : adressez-vous à votre administrateur d'agence.
??? L'enregistrement affiche « Choose one of your centres. » | Comme directeur de centre, vous ne pouvez enregistrer que pour vos propres centres. Choisissez-en un dans **Centre**.
??? « Only a record of one of your centres can be changed. » | La facture ou la commande appartient à un autre centre ou à toute l'agence. Demandez à votre administrateur d'agence de la modifier.
??? « Choisissez un fournisseur » ou « Ajoutez au moins une ligne » | Une facture ou une commande exige un fournisseur et au moins une ligne avec un montant. Ajoutez-les et enregistrez de nouveau.
??? La liste indique « No bills yet. » | Rien n'a été saisi pour vos centres. Appuyez sur **+ Nouvelle facture fournisseur**, ou convertissez un bon de commande.
??? Quelqu'un reçoit-il un courriel quand j'approuve ou paie une facture? | Non. Dépenses n'envoie ni courriel ni notification. Chaque changement est conservé dans le journal d'audit.
??? Puis-je lier une facture à une subvention? | Oui. Dans **Finances → Subventions**, saisissez une dépense et choisissez la facture sous **Linked expense bill (optional)**.

## Écrans liés

[[open: #grants | Subventions]] [[open: #external-billing | Comptabilité]]

Voir aussi : [Subventions](grants) · [Comptabilité](accounting) · [Paies, heures et talons de paie](payroll-documents)
