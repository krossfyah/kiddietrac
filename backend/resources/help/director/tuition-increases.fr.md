---
title: Planifier une hausse des frais de garde
category: Billing
order: 83
roles: agency_admin, centre_director, platform_admin
---
# Planifier une hausse des frais de garde

**Finances → Hausses de frais** sert à planifier une hausse future des frais de garde : un pourcentage (par exemple 3,5 %) ou un montant fixe en dollars par mois, à partir d'une date donnée. KiddieTrac s'occupe ensuite du reste : il prévient chaque famille concernée avant la date, puis, à la date prévue, inscrit le nouveau montant dans ses frais de garde et dans vos plans tarifaires.

[[open: #tuition-increases | Ouvrir Hausses de frais]]

## Les étapes d'une hausse

| Statut | Ce que cela veut dire |
|---|---|
| **Planifiée** | Enregistrée. Rien n'a encore été envoyé. Vous pouvez encore la modifier ou la supprimer. |
| **Avisée** | Chaque famille concernée a été prévenue (notification et courriel), avec les frais mensuels actuels et nouveaux de ses propres enfants. Elle ne peut plus être modifiée. |
| **Appliquée** | À la date d'effet, le nouveau montant a été inscrit dans les frais mensuels de chaque enfant et dans les plans tarifaires. Les factures émises ensuite l'utilisent. |
| **En retard** | La date est passée depuis plus de 14 jours et la hausse n'a jamais été appliquée (par exemple, elle a été planifiée avant cette automatisation). Elle n'est pas appliquée en retard d'elle-même : appuyez sur **Appliquer maintenant** ou **Supprimer**. |

Toutes les dates suivent le fuseau horaire de votre agence. Les familles sont prévenues le **jour de l'avis** : la date d'effet moins le nombre de jours d'avis.

## Planifier une hausse

[[show: #tuition-increases @ + Schedule increase | Me montrer Planifier une hausse]]

1. Ouvrez **Finances → Hausses de frais** et appuyez sur **+ Planifier une hausse**.
2. Choisissez le **Centre**. Un administrateur d'agence peut aussi choisir **Tous les centres**; une direction choisit l'un de ses propres centres.
3. Choisissez la date dans **Les nouveaux frais commencent le**. Elle doit être après aujourd'hui.
4. Sous **Type**, choisissez **Pourcentage (%)** ou **Montant fixe ($ par mois)**.
5. Tapez la **Hausse** sans signe % ni $ : 3,5 pour 3,5 %, ou 25 pour 25 $ de plus par mois.
6. **Prévenir les familles ce nombre de jours avant** reprend la règle d'avis de votre agence (30 jours sauf changement). Modifiez-le pour cette hausse au besoin.
7. Ajoutez une **Note aux familles** si vous le souhaitez (par exemple « Notre ajustement annuel au coût de la vie »). Elle figure dans leur courriel.
8. Vérifiez l'aperçu sous les champs : chaque famille concernée, les frais mensuels de chaque enfant **Actuels** et **Nouveaux**, et les plans tarifaires qui changent. La ligne au-dessus indique le jour où les familles seront prévenues.
9. Appuyez sur **Planifier la hausse**.

> ⚠️ Si le jour de l'avis est déjà passé (par exemple 30 jours d'avis pour une date dans 10 jours), le formulaire vous avertit. Les familles sont alors prévenues dès le prochain passage automatique et reçoivent moins de préavis que votre règle.

### Ce que changent un pourcentage et un montant fixe

- Un **pourcentage** augmente les frais mensuels de chaque enfant concerné et chaque plan tarifaire actif du centre (ou de tous les centres) de ce pourcentage, arrondi au cent.
- Un **montant fixe** ajoute les dollars aux frais **mensuels** de chaque enfant et aux plans tarifaires **mensuels** seulement. Les plans hebdomadaires, aux deux semaines et ponctuels ne changent pas; l'aperçu les nomme.
- Seuls les enfants inscrits, avec des frais mensuels de plus de 0 $, dans des familles toujours actives, à des centres ouverts, sont inclus.

## Consulter, modifier et supprimer

Le menu **⋮** de chaque ligne propose :
- **Voir** : les détails et les personnes touchées. Avant l'avis, la liste est calculée à partir des frais d'aujourd'hui; après, c'est le registre de ce qui a été annoncé à chaque famille et de ce qui a été changé.
- **Modifier** (Planifiée seulement) : ouvre le même formulaire avec l'aperçu.
- **Supprimer** (Planifiée ou En retard) : la retire. Les familles déjà prévenues ne sont pas averties de l'annulation; prévenez-les vous-même.
- **Appliquer maintenant** (En retard seulement) : prévient les familles qui ne l'ont pas encore été, puis inscrit les nouveaux montants tout de suite.

## La règle d'avis de votre agence

Un administrateur d'agence appuie sur **⚙ Règle d'avis** pour fixer combien de jours avant une hausse les familles sont prévenues. C'est la valeur de départ des nouvelles hausses; celles déjà planifiées gardent la leur.

## Ce que reçoivent les familles

Chaque parent ou tuteur d'une famille concernée reçoit une notification et un courriel « Changement des frais de garde à partir du (date) » qui liste ses propres enfants avec les frais mensuels actuels et nouveaux, votre note et un bouton vers sa facturation. Personne ne voit les montants d'une autre famille. Une fois la hausse appliquée, les administrateurs d'agence et la direction du centre reçoivent une notification indiquant combien de frais ont été mis à jour.

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Administrateur d'agence | Planifie, modifie et supprime des hausses pour un centre ou tous les centres, applique celles en retard et fixe la règle d'avis. |
| Direction de centre | Voit les hausses de ses centres et celles de toute l'agence; planifie, modifie et supprime des hausses pour ses propres centres seulement. |
| Administrateur de plateforme | Comme un administrateur d'agence, dans l'agence où il travaille. |

Le personnel éducateur et les parents ne voient pas cet écran.

## Questions fréquentes et problèmes

??? « Les nouveaux frais doivent commencer après aujourd'hui. » | Choisissez une date plus tardive.
??? « Une hausse en pourcentage peut être d'au plus 50 %. » | Vérifiez le nombre. Pour un grand changement, utilisez **Montant fixe** ou modifiez directement les frais.
??? L'aperçu dit qu'aucune famille ne changerait. | Aucun enfant inscrit à ce centre n'a de frais mensuels de plus de 0 $. Vérifiez d'abord les inscriptions et les plans tarifaires.
??? Pourquoi ne puis-je pas modifier une hausse? | Les familles ont déjà été prévenues. Supprimez-la seulement si elle est En retard; sinon, planifiez une hausse corrective et prévenez les familles.
??? Les factures déjà émises changent-elles? | Non. Seules les factures émises après la date d'effet utilisent les nouveaux frais.
??? Un texto est-il envoyé? | Non. Les familles reçoivent une notification et un courriel. Les agences de test n'envoient rien.

## Écrans liés

[[open: #tuition-plans | Plans tarifaires]] [[open: #payment-plans | Échéanciers de paiement]] [[open: #external-billing | Comptabilité]]

Voir aussi : [Plans tarifaires](tuition-plans) · [Générer les factures mensuelles](generating-monthly-invoices)
