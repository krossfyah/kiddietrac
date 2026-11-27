---
title: Forfaits de frais de garde
category: Billing
order: 20
roles: agency_admin, centre_director, platform_admin
---
# Forfaits de frais de garde

Un forfait de frais, c'est un montant nommé et sa fréquence de facturation, par exemple « Bambins temps plein, 1 250 $ par mois ». Vous créez vos forfaits une fois, puis vous vous en servez pour créer les factures des familles qui y sont inscrites. Les directeurs le trouvent sous **Finances → Forfaits de frais**; les administrateurs d'agence l'ont aussi dans **Paramètres → Facturation**, onglet **💵 Forfaits de frais**.

[[open: #tuition-plans | Ouvrir Forfaits de frais]]

## Créer un forfait

1. Ouvrez **Finances → Forfaits de frais** (ou **Paramètres → Facturation → 💵 Forfaits de frais**).
2. Appuyez sur **+ Nouveau plan**. La fenêtre **Nouveau plan de frais** s'ouvre.
3. Saisissez le **Nom du plan**, par exemple « Bambins temps plein ».
4. Saisissez le **Amount ($)** (montant).
5. Choisissez la **Fréquence de facturation** : **Hebdomadaire**, **Aux deux semaines**, **Mensuel** ou **One-time** (une seule fois).
6. Si le forfait vise un seul centre, choisissez-le sous **Centre (optional)**. Ajoutez un **Age group (optional)** et une **Description (optional)** au besoin.
7. Laissez **Active (available to apply to families)** coché.
8. Appuyez sur **Créer le plan**. Le message **Plan saved** s'affiche et le forfait s'ajoute à la liste avec son montant et sa fréquence.

## Modifier ou retirer un forfait

1. Cliquez sur la ligne du forfait. La fenêtre **Modifier le plan** s'ouvre.
2. Changez ce qu'il faut et appuyez sur **Enregistrer les modifications**.
3. Pour cesser d'utiliser un forfait tout en le gardant au dossier, décochez **Active (available to apply to families)** et enregistrez. Il affiche **INACTIVE** dans la liste et perd son bouton **💸 Générer les factures**.
4. Pour supprimer un forfait créé par erreur, appuyez sur **Supprimer** et confirmez. Le message **Plan deleted** s'affiche.

> ℹ️ Changer le montant d'un forfait ne modifie pas les factures déjà créées. Les nouvelles factures utilisent le nouveau montant.

## Créer des factures à partir d'un forfait

[[show: #tuition-plans @ Generate invoices | Me montrer Générer les factures]]

1. Sur un forfait actif, appuyez sur **💸 Générer les factures**.
2. Sous **Créer comme**, choisissez **Draft (review first)** (brouillon, pour vérifier avant que les familles les voient) ou **Sent (issue now)** (émises tout de suite).
3. Indiquez **Due in (days)** (échéance en jours). La valeur de départ est 14.
4. Sous **Familles**, cochez les familles à facturer, ou appuyez sur **Tout sélectionner**. Un forfait réservé à un centre n'affiche que les familles de ce centre.
5. Appuyez sur **Générer les factures**. Un message indique combien ont été créées, par exemple « Generated 12 invoices (draft). »
6. Ouvrez **Finances → Comptabilité** pour trouver les nouvelles factures, vérifier les brouillons et les envoyer.

Chaque facture a une ligne de frais de garde avec le nom, la fréquence et le montant du forfait, datée d'aujourd'hui, numérotée selon la règle habituelle de votre agence, dans la devise de votre agence (selon **Country & compliance**).

> ⚠️ **Générer les factures** crée une nouvelle facture chaque fois que vous appuyez. Appuyer deux fois pour les mêmes familles les facture deux fois : annulez les factures en trop dans la liste des factures.

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Administrateur d'agence | Crée, modifie et supprime tous les forfaits, pour toute l'agence ou un seul centre, et génère les factures. |
| Directeur de centre | Voit les forfaits de ses propres centres et ceux de toute l'agence. Crée et modifie les forfaits de ses centres et génère les factures de ses familles. Les forfaits de toute l'agence restent à l'administrateur d'agence. |
| Administrateur de plateforme | Comme un administrateur d'agence, dans l'agence où il travaille. |

Les éducatrices et les parents ne voient pas cet écran. Les parents ne voient que les factures.

## FAQ et problèmes courants

??? Il n'y a pas de bouton Générer les factures sur un forfait. | Le forfait est inactif. Ouvrez-le, cochez **Active (available to apply to families)** et enregistrez.
??? La liste indique « No plans yet. Click + New plan to create your first fee plan. » | Aucun forfait n'existe encore pour vos centres. Appuyez sur **+ Nouveau plan**.
??? « Choose one of your centres. » | Comme directeur de centre, vous ne pouvez enregistrer que les forfaits de vos propres centres. Choisissez-en un sous **Centre (optional)**; les forfaits de toute l'agence relèvent de l'administrateur d'agence.
??? « Plan name is required » | Saisissez un **Nom du plan** avant d'enregistrer.
??? « Sélectionnez au moins une famille » | Cochez au moins une famille sous **Familles**, puis appuyez de nouveau sur **Générer les factures**.
??? Une famille attendue n'est pas dans la liste. | Un forfait réservé à un centre n'affiche que les familles de ce centre. Les familles retirées ne sont pas affichées.
??? Les familles reçoivent-elles un courriel quand je génère des factures? | Ce bouton n'envoie ni courriel ni texto. Créez-les en brouillon, vérifiez-les dans **Finances → Comptabilité**, puis envoyez-les comme d'habitude.

## Écrans liés

[[open: #external-billing | Comptabilité]] [[open: #billing-schedule | Calendrier de facturation]] [[open: #tuition-increases | Hausses de frais]]

Voir aussi : [Générer les factures mensuelles](generating-monthly-invoices) · [Statuts des factures](invoice-statuses) · [Planifier une hausse des frais](tuition-increases)
