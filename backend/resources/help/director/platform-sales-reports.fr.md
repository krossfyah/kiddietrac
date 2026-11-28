---
title: Rapports de ventes de KiddieTrac
---

# Rapports de ventes de KiddieTrac

**Platform › 💹 Sales reports** montre ce que KiddieTrac lui-même a vendu et facturé : revenus par numéro de pièce, forfait, module, service et client. Ce sont uniquement des chiffres de la plateforme. Aucune agence ne les voit, et ils n'ont rien à voir avec les frais que les agences facturent aux familles. Servez-vous-en pour les bilans de fin de mois et de fin d'année, pour voir quels produits se vendent et pour vérifier combien d'argent facturé reste impayé.

[[open: #platform-sales | Ouvrir les rapports de ventes]]

## Choisir la période

1. Ouvrez **Platform › 💹 Sales reports**. L'écran s'ouvre sur **This year**.
2. Appuyez sur un bouton de période : **This year**, **This quarter**, **This month**, **Last 12 months** ou **All time**.
3. Ou réglez vos propres dates dans **From** et **to**. Les boutons de période se désactivent et le rapport se recharge pour vos dates.

## Lire les tuiles

| Tuile | Ce qu'elle compte |
|---|---|
| **Revenue invoiced** | Factures de la plateforme dans la période, avant taxes, avec le nombre de factures |
| **Collected** | Paiements reçus, et la part de ce qui a été facturé taxes incluses |
| **Outstanding** | Facturé, mais pas encore payé |
| **Recurring (MRR)** | Revenu mensuel récurrent à ce jour, avec le chiffre annuel (ARR) |
| **Booked on proposals** | Propositions signées dans la période, facturées ou non |
| **Paying customers** | Agences facturées dans la période |
| **Tax collected** | Taxes sur les factures de la période |

Les factures annulées ne sont jamais comptées.

## Lire les graphiques et les tableaux

1. **📈 Invoiced vs collected — last 12 months** : comparez la facturation de chaque mois avec ce qui est entré. Un écart veut dire de l'argent encore dû.
2. **🧩 Revenue by kind** : la répartition entre **Plans**, **Add-ons**, **Services**, **Discounts** et **Other**.
3. **🏆 Top customers** : les huit agences les plus facturées dans la période.
4. **🔢 Invoiced by part number** : chaque article facturé avec **Part #**, **Item**, **Kind**, **Qty**, **Invoiced** et **Share**, et une ligne **Total**.
5. **📝 Sold on proposals by part number** : ce qui a été vendu sur des propositions signées ou approuvées, y compris les services ponctuels pas encore facturés.

> 💡 Les anciennes factures, faites avant les lignes détaillées, comptent sous leur forfait : leur numéro de pièce peut donc être celui du forfait.

## Utiliser les chiffres

1. Pour vérifier les factures d'un seul client, ouvrez **Platform › 🏦 Accounting AR/AP** et tapez le nom de l'agence dans le filtre du tableau.
2. Pour changer le prix d'un produit ou son numéro de pièce, utilisez **Platform › 🔑 Licences** et le catalogue des forfaits ; ce rapport ne fait que lire ce qui a été facturé.
3. Pour voir les propositions derrière **Booked on proposals**, ouvrez **Sales › Proposals**.

## Qui peut faire quoi

- **Super-administrateurs de la plateforme** : voient tout le rapport.
- **Représentants des ventes** : ne voient pas cet écran. Ils voient leurs propres propositions dans **Sales › Proposals**.
- **Administrateurs d'agence et tous les autres rôles** : ne peuvent pas le voir ; toute autre personne qui l'ouvre voit **KiddieTrac sales reports are for superadmins.**

## Questions fréquentes et problèmes

??? « Could not load the report » est apparu. | La connexion a coupé ou votre session a pris fin. Actualisez la page et choisissez la période de nouveau.
??? Les tableaux disent « Nothing in this range. » | Aucune facture ni proposition signée entre vos dates **From** et **to**. Essayez **All time**.
??? Pourquoi Booked on proposals est-il plus élevé que Revenue invoiced? | Les propositions comptent dès leur signature ; les factures suivent au fil des périodes de facturation du contrat. Les services ponctuels ne sont peut-être pas encore facturés.
??? Pourquoi la part de Collected est-elle différente de ce que j'attendais? | Elle compare les paiements à ce qui a été facturé taxes incluses, alors que **Revenue invoiced** est avant taxes.
??? Les rabais réduisent-ils les totaux? | Oui. Ils apparaissent comme une catégorie à part dans **🧩 Revenue by kind** et réduisent ce qui a été facturé.

## Écrans liés

[[open: #sales-invoices | Comptabilité AR/AP]] · [[open: #licences | Licences]] · [[open: #admin-mrr | Tableau MRR]] · [[open: #sales-quotes | Propositions]]

Voir aussi : [[platform-invoices]] · [[platform-licences]]
