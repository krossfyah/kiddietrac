---
title: Crédits de places réservées (vacances)
category: Billing
order: 84
---
# Places réservées (vacances)

**Places réservées (vacances)** suspend les frais d’une famille pendant son absence. Une famille demande une suspension (ou vous en ajoutez une pour elle), vous l’approuvez ou la refusez, et le crédit d’une suspension approuvée est déduit de la **prochaine facture** de la famille une fois la suspension terminée.

[[open: #vacation-holds | Ouvrir Places réservées (vacances)]]

## La règle de crédit

Votre agence choisit comment une suspension est créditée. Les administrateurs d’agence appuient sur **⚙ Credit rule** dans la bannière :

- **No credit** (la règle de départ) — les suspensions sont notées, mais rien n’est déduit de la facture.
- **Un pourcentage du tarif journalier, pour chaque jour prévu** — par exemple 50 %.
- **Un montant fixe ($) pour chaque jour prévu** — par exemple 20 $.

Seuls les jours où chaque enfant doit venir comptent (un enfant sans jours au dossier compte du lundi au vendredi). Les jours de fermeture du centre ne comptent pas. Le tarif journalier d’un enfant = ses frais mensuels × 12 ÷ 52 ÷ ses jours par semaine. La bannière montre toujours la règle en vigueur. La changer ne modifie pas les suspensions déjà approuvées.

## Approuver ou refuser une demande

Les nouvelles demandes affichent **Requested**. La colonne **Credit** montre ce que donnerait votre règle (« ≈ $…, N jours prévus ») ou **No credit**. Les directeurs du centre de la famille et les administrateurs d’agence sont avertis quand une famille fait une demande.

1. Ouvrez **Administration › Vacation holds** et trouvez la demande.
2. Dans le menu **⋮** de la ligne, choisissez **Approve**. La fenêtre **Approve vacation hold** montre, pour chaque enfant, les jours prévus, le tarif journalier et le crédit.
3. Gardez le crédit calculé, ou tapez un autre montant (0 = aucun crédit).
4. Appuyez sur **Approve**. La ligne affiche **Approved** et le crédit, avec « On the first invoice after » le dernier jour.
5. Pour refuser, choisissez **Deny** dans le menu **⋮**, ajoutez une note pour la famille si vous voulez, puis appuyez sur **Deny**.

## Comment le crédit arrive sur la facture

- Une fois le dernier jour passé, la prochaine facture de la famille (le lot mensuel ou son calendrier de facturation) porte une ligne **Vacation hold credit** avec les dates.
- Un crédit n’est jamais mis sur deux factures. S’il dépasse la facture, celle-ci passe à 0 $ et le reste est déduit de la suivante.
- Si vous annulez (void) la facture qui portait le crédit, le crédit passe sur la facture suivante.
- La colonne **Credit** indique où il est allé, par exemple « On INV-1042 ».

## Annuler une suspension

Choisissez **Cancel hold** dans le menu **⋮** tant que la suspension est demandée ou approuvée et que son crédit n’est pas encore sur une facture. Le crédit est annulé et la famille est avertie. Une fois le crédit facturé, annulez ou ajustez plutôt cette facture dans **Billing**.

## Ajouter une suspension pour une famille

1. Appuyez sur **+ Add hold** dans la bannière.
2. Choisissez la famille, réglez **From** et **To**, et ajoutez un motif (facultatif).
3. Appuyez sur **Add hold**. La suspension apparaît comme **Requested** et les tuteurs sont avertis.
4. Approuvez-la comme ci-dessus pour fixer le crédit.

## Ce que voit la famille

- Approuvée : « Your hold for … is approved. A credit of $… will come off your next invoice after the hold ends. » Sans crédit : « No credit applies to this hold. »
- Refusée : votre note, ou « Unable to approve at this time. »
- Annulée : la suspension est annulée et son crédit ne sera pas appliqué.
- La ligne de crédit sur sa facture. Les avis ouvrent son écran **Billing**.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d’agence | Voir toutes les suspensions, fixer la règle de crédit, ajouter, approuver, refuser et annuler |
| Directeur de centre | La même chose pour les familles de ses propres centres seulement |
| Administrateur de la plateforme | Comme un administrateur d’agence, dans l’agence consultée |
| Parent | Est averti de chaque décision et voit le crédit sur sa facture |

Les éducateurs ne voient pas cet écran.

## FAQ et problèmes

??? Approve ou Deny n’apparaît pas dans le menu ⋮. | Seulement tant que la suspension est **Requested**. Une suspension décidée ne peut pas l’être à nouveau ; annulez-la (si son crédit n’est pas facturé) et ajoutez-en une nouvelle si les dates changent.
??? « This hold is already approved. » | Quelqu’un l’a décidée avant vous. Actualisez l’écran.
??? Cancel hold n’apparaît pas. | Le crédit est déjà sur une facture, ou la suspension a été refusée ou annulée. Annulez ou ajustez la facture dans **Billing**.
??? Le crédit indique « Recorded only ». | La suspension a été décidée avant que les crédits n’aillent sur les factures. Ajoutez ce crédit à la main s’il est encore dû. Voir [[family-ledger]].
??? Le crédit est de 0 $ alors que nous en donnons. | La règle est **No credit**, ou les enfants n’ont pas de jours prévus ou de frais à ces dates. Un administrateur d’agence règle cela avec **⚙ Credit rule**.
??? « Family and both dates are required. » | Choisissez une famille et remplissez **From** et **To**, puis appuyez de nouveau sur **Add hold**.

## Écrans liés

[[open: #admin-families | Familles]]
[[open: #closures | Fermetures]]
[[open: #tuition-increases | Augmentations des frais]]

Voir aussi [[family-ledger]] et [[generating-monthly-invoices]].
