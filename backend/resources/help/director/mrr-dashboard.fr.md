---
title: Tableau de bord MRR
---
# Tableau de bord MRR

**Platform › MRR dashboard** montre les revenus récurrents de KiddieTrac : ce que les agences paient chaque mois, ce que valent les licences gratuites et d'essai, ce qui a été facturé et encaissé, qui doit de l'argent et quelles licences se renouvellent bientôt. Ouvrez-le pour la revue mensuelle des revenus, avant un appel de renouvellement ou pour relancer des factures impayées. L'écran ne fait que lire des chiffres : rien n'y modifie une licence ou une facture.

[[open: #admin-mrr | Ouvrir le tableau MRR]]

## Vérifier les revenus du mois

1. Ouvrez **Platform › 💰 MRR dashboard**. La bannière indique quand les chiffres ont été calculés (**as of …**).
2. Lisez les quatre tuiles de couleur du haut :
   - **MRR** : revenu mensuel récurrent des licences payées, avec la variation nette du mois (▲ ou ▼), ou *No change this month*.
   - **ARR** : MRR × 12.
   - **Paying agencies** : combien d'agences paient, sur l'ensemble de vos clients, et combien sont gratuites ou à l'essai.
   - **ARPA** : revenu moyen par agence payante, par mois.
3. Lisez la deuxième rangée : **Complimentary value** (ce que coûteraient les licences gratuites au prix courant, par mois et par année), **Trial pipeline** (agences à l'essai au prix courant), **Collected this month** (avec le montant facturé) et **Outstanding** (devient rouge s'il y a un retard, avec le montant en retard et le nombre de factures).
4. Sous **This month**, vérifiez le mouvement du MRR : **New**, **Churned** et **Net**.

> ℹ️ Pas encore de clients payants? Une note indique combien d'agences ont une licence gratuite et ce qu'elles valent au prix courant. Cette valeur n'est jamais facturée.

## Suivre la tendance et la répartition des forfaits

1. Regardez **MRR, last 12 months**. La ligne pleine est le MRR payé; la ligne pointillée est la valeur des licences gratuites au prix courant.
2. Regardez **Plan mix** pour la part du MRR par forfait (ou de la valeur gratuite, quand personne ne paie encore). Chaque forfait indique son nombre d'agences.
3. Regardez **Invoiced vs collected** pour les factures KiddieTrac émises et les paiements reçus chaque mois. Les remboursements sont déduits de la barre encaissée.

## Relancer les sommes dues et les renouvellements

1. Vérifiez **Receivables** : ce que les agences doivent, réparti en **Current**, **1–30 days**, **31–60**, **61–90** et **90+**. Survolez une couleur pour voir son montant.
2. Vérifiez **Agencies by value** : le forfait, le type, la date **Boarded** et la valeur **Per month** de chaque agence, le MRR payé d'abord, puis la valeur gratuite.
3. Vérifiez **Renewals, next 90 days** : quand chaque licence arrive à échéance. **✓** sous **Auto** veut dire qu'elle se renouvelle seule; **Ends** veut dire qu'elle s'arrête si personne ne la renouvelle.
4. Vérifiez **By part number** pour les lignes actives, les lignes payées, le **MRR** et le **Comp / mo** par produit.
5. Pour agir sur une agence (renouveler, modifier, facturer), allez à **Licences** ou à **Accounting AR/AP**.

[[show: #admin-mrr @ Renewals, next 90 days | Montrez-moi]]

## Qui peut faire quoi

| Rôle | Ce qu'il voit |
|---|---|
| Administrateur de la plateforme | Tout le tableau, pour toutes les agences. |
| Administrateur d'agence, directeur de centre, tous les autres | Rien : l'écran n'est pas dans leur menu et le serveur refuse les chiffres. Les agences voient leur propre facture à leur écran Facturation. |

## Questions fréquentes et problèmes

??? Une tuile affiche 0 $. | Rien de ce type n'existe encore, par exemple aucune licence payée ou aucune facture ce mois-ci. Les tableaux le disent aussi (« No agencies on a licence yet. », « Nothing owing. »).
??? « No licence renews in the next 90 days. » | Aucune licence n'arrive à échéance dans les 90 prochains jours. Consultez Licences pour les dates plus lointaines.
??? Les chiffres ne correspondent pas à une facture. | Le MRR ramène chaque licence payée à un mois (une licence annuelle compte pour un douzième). Facturé et encaissé sont les vraies factures et les vrais paiements, remboursements déduits.
??? Un message rouge s'affiche au lieu de la page. | Les chiffres n'ont pas pu être chargés. Actualisez; seuls les administrateurs de la plateforme peuvent ouvrir cet écran.
??? Le tableau envoie-t-il des courriels? | Non. Il ne fait que lire. Les avis de renouvellement et les factures partent de Licences et d'Accounting AR/AP.

## Écrans liés

[[open: #licences | Licences]] [[open: #sales-invoices | Comptabilité CR/CF]] [[open: #platform-sales | Rapports de ventes]] [[open: #usage-spend | Utilisation et dépenses]] [[open: #admin-features | Indicateurs de fonctionnalités]]
