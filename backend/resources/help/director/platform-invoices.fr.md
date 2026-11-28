---
title: Comptabilité AR/AP (facturer les agences pour KiddieTrac)
---
# Comptabilité AR/AP (facturer les agences pour KiddieTrac)

**Platform › 🏦 Accounting AR/AP** est la comptabilité de KiddieTrac : les factures que les agences doivent pour la plateforme, les paiements faits, les frais et crédits supplémentaires, le forfait de facturation de chaque agence et les dépenses de KiddieTrac. C'est séparé des factures que les agences envoient aux familles. Seuls les super-administrateurs de la plateforme peuvent l'ouvrir.

[[open: #sales-invoices | Ouvrir la comptabilité AR/AP]]

Les onglets du haut sont **🧾 Invoices**, **💳 Payments**, **➕ Extra line items**, **🤝 Referral credits**, **🏷️ Billing plans**, **⚙️ Settings** et **📤 Expenses (AP)**.

## Créer les factures dues

1. Ouvrez **🧾 Invoices**.
2. Appuyez sur **Raise invoices due now**.
3. Lisez l'aperçu : chaque agence qui sera facturée et le montant, et sous **Not due**, les agences laissées de côté et pourquoi.
4. Appuyez sur **Raise invoices** pour les créer, ou sur **Cancel** pour arrêter. Rien n'est créé avant votre confirmation.
5. Les nouvelles factures apparaissent sous **Open** comme brouillons. La prochaine date de facturation de chaque agence avance selon son intervalle.

> ℹ️ Une agence sans prochaine date de facturation n'est pas en facturation récurrente et n'est jamais facturée automatiquement. Les renouvellements de licence sont facturés ici aussi.

## Faire une facture ponctuelle

1. Appuyez sur **＋ New invoice**.
2. Choisissez l'**Agency**, réglez la **Due date**, le **Tax %** et le **Tax name** (un taux au-dessus de 0 a besoin d'un nom, comme TVH).
3. Ajoutez des lignes avec **＋ Add a line** : **Description**, **Qty**, **Price** et **Taxed**. Le sous-total, les taxes et le total se mettent à jour pendant que vous tapez.
4. Ajoutez une **Note on the invoice (optional)** et appuyez sur **Save draft**. Un message indique **Draft … created**.

## Travailler avec une facture

Les onglets **Open**, **Paid** et **Voided** affichent chacun leur nombre, avec des tuiles facturé, perçu, à recevoir, taxes et en retard par devise. Un ⚠ à côté de **Due** veut dire en retard. Chaque ligne n'offre que ce qui s'applique :

1. **View invoice** ouvre le PDF (sur chaque ligne, même annulée).
2. **Émettre** (brouillons seulement) enregistre la date d'émission. Rien n'est envoyé par courriel.
3. **Envoyer la facture par courriel** demande l'adresse chaque fois ; sans adresse, rien n'est envoyé. Le bouton fonctionne sur toute facture non annulée : vous pouvez donc renvoyer une copie.
4. **Enregistrer le paiement** enregistre l'argent reçu.
5. **Lines & payments** ajoute une ligne, retire un supplément, utilise **Waive late fees** ou marque un paiement **Returned (NSF)…**.
6. **Edit invoice** modifie les lignes, la date d'échéance et les taxes, avec une **Reason for the change** gardée au journal d'audit.
7. **Reissue** annule la facture et crée une copie corrigée selon la licence actuelle ; **Void** l'annule mais la garde au dossier, estompée, sans montant dû.
8. **Refund…** apparaît une fois un paiement enregistré, pour rembourser. **Delete draft** n'apparaît que pour un brouillon jamais envoyé par courriel.

## Confirmer les paiements déclarés par les agences

1. Ouvrez **💳 Payments**. **Payments to confirm** liste ce que les administrateurs d'agence ont déclaré depuis leur page Licence.
2. Appuyez sur **Confirm received** pour l'enregistrer et envoyer un reçu par courriel, ou sur **Not received** et donnez une raison ; l'administrateur d'agence qui l'a déclaré est averti.
3. Plus bas, le **Payment ledger** liste chaque paiement, remboursement et paiement retourné avec **Date**, **Agency**, **Invoice**, **Method**, **Amount**, **Receipt** et **Status**.

## Forfaits de facturation, suppléments et réglages

1. **🏷️ Billing plans** : une ligne par agence avec **Before discounts**, **Discounts / complimentary**, **Every**, **Tax**, **Total**, **Next invoice** et **Bill to**. Appuyez sur **View** pour régler **Price**, **Currency**, **Every**, **Tax %**, **Tax name**, **Next invoice** et les **Business details on the invoice**, puis **Save**. Les agences marquées **billed from licences** prennent leur prix dans **Platform › 🔑 Licences**.
2. **➕ Extra line items** : ajoutez des frais à la prochaine facture d'une agence ou à chacune (**Every invoice** / **Next invoice only**), puis **Pause**, **Resume** ou **Delete**.
3. **🤝 Referral credits** : un crédit unique sur la facture d'un mois choisi.
4. **⚙️ Settings** : **🔁 Recurring invoices** (**On**, **Raise invoices at**, **Which agencies**, **Invoices are due**, **BCC on invoices**, **Email them automatically**, puis **Save recurring invoices**), ainsi que les rappels et reçus, les frais de retard, les frais de paiement retourné et les suppléments selon le mode de paiement (**Save settings**).

> ⚠️ Activer **Email them automatically** demande une confirmation : les factures sont alors envoyées aux agences dès leur création. Au Canada, les suppléments sur carte de crédit sont plafonnés à 2,4 % et interdits sur le débit.

## Qui peut faire quoi

**Super-administrateurs de la plateforme** seulement. Aucun rôle d'agence, représentant des ventes ou revendeur ne voit les comptes à recevoir de KiddieTrac. Les administrateurs d'agence voient leurs propres factures et déclarent leurs paiements depuis leur page Licence.

## Questions fréquentes et problèmes

??? « No invoices yet » | Réglez le prix d'une agence dans **🏷️ Billing plans** (ou donnez-lui une licence), puis utilisez **Raise invoices due now**.
??? « Every line needs a description and a price » | Remplissez les deux sur chaque ligne, ou retirez la ligne vide avec ✕.
??? « A tax rate needs a name (HST, GST) » | Tapez le nom de la taxe ; une taxe sans nom s'imprimerait comme une simple ligne « Tax ».
??? Pourquoi ne puis-je pas supprimer une facture? | Seul un brouillon jamais envoyé peut être supprimé. Dès qu'une agence en a une copie, utilisez **Void** ou **Reissue**.
??? L'agence indique « no address on file ». | Ouvrez **🏷️ Billing plans**, appuyez sur **View** et remplissez les coordonnées d'entreprise. Ce qui reste vide n'apparaît pas sur la facture.
??? Émettre envoie-t-il la facture? | Non. Utilisez **Envoyer la facture par courriel**, ou activez l'envoi automatique dans **⚙️ Settings**.

## Écrans liés

[[open: #licences | Licences]] · [[open: #platform-sales | Rapports de ventes]] · [[open: #admin-mrr | Tableau MRR]] · [[open: #sales-expenses | Dépenses]]

Voir aussi : [[platform-licences]] · [[platform-sales-reports]] · [[billing-setup]] · [[platform-admin-overview]]
