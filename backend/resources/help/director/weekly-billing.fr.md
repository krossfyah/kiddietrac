---
title: Calendrier de facturation hebdomadaire / aux deux semaines
category: Billing
order: 80
roles: agency_admin, centre_director, platform_admin
---
# Calendrier de facturation

**Finances → Calendrier de facturation** facture une famille selon son propre rythme : chaque semaine, aux deux semaines ou chaque mois, le jour choisi. À chaque date, KiddieTrac émet lui-même la facture de frais de garde de la famille, l'envoie par courriel et, si la famille paie par prélèvement automatique, débite sa carte enregistrée.

[[open: #billing-schedule | Ouvrir Calendrier de facturation]]

## Établir le calendrier d'une famille

1. Dans le menu, ouvrez **Finances** et choisissez **Calendrier de facturation**.
2. Choisissez la famille dans la liste **Famille**. Son calendrier se charge (ou appuyez sur **Charger**).
3. Choisissez **Fréquence** : **Chaque semaine**, **Aux deux semaines** ou **Chaque mois**.
4. Pour chaque semaine ou aux deux semaines, choisissez le jour dans **Facturer le**. Pour chaque mois, choisissez le jour dans **Facturer le jour**.
5. Lisez la ligne **Prochaine facture** : la date de la première facture et la période couverte. Elle change avec les champs.
6. Appuyez sur **Enregistrer le calendrier**. Le calendrier fonctionne dès lors.

### Ce que couvre et coûte chaque facture

| Fréquence | La facture couvre | Montant par enfant | Échéance |
|---|---|---|---|
| Chaque semaine | les 7 jours à partir de la date de facture | frais mensuels × 12 ÷ 52 | 7 jours après |
| Aux deux semaines | les 14 jours à partir de la date de facture | frais mensuels × 24 ÷ 52 | 7 jours après |
| Chaque mois | le 1er, ce mois-là; un autre jour, le mois **suivant** (facturé d'avance) | les frais mensuels | 15 jours après |

Les subventions et rabais familiaux sont calculés comme dans la facturation mensuelle, au prorata de la période. Les jours 29 à 31 deviennent le dernier jour d'un mois plus court (par exemple le 31 devient le 30 avril). Toutes les dates suivent le fuseau horaire de votre agence.

## Ce qui se passe à chaque date

- La facture est émise avec une ligne de frais par enfant inscrit, et la famille reçoit une notification et le courriel de la facture.
- Si la famille (ou un parent, en facturation partagée) a le prélèvement automatique, la carte est débitée tout de suite par votre traitement des paiements habituel, visible dans **Finances → Traitement des paiements**.
- Rien n'est émis, et le registre dit pourquoi, quand : une autre facture couvre déjà ces dates (la facturation mensuelle ou celle du partenaire), votre centre est fermé toute la période (fermetures touchant la facturation), ou aucun enfant n'a de frais.
- Une date n'est facturée qu'une fois. Si une date est passée depuis plus de 3 jours (panne), elle n'est pas facturée en retard; elle apparaît comme ignorée et vous pouvez émettre cette facture à la main au besoin.

## Dernières exécutions

Sous le formulaire, **Dernières exécutions** montre les 12 dernières dates : la date, la période, le résultat (**Facturée**, **Débitée**, **Débit refusé**, **Ignorée** ou **Échec**), le montant, le numéro de facture et une note.

Si une facture n'a pas pu être émise ou si la carte est refusée, les administrateurs d'agence et la direction du centre reçoivent une notification **La facturation planifiée demande votre attention**. Une carte refusée laisse la facture ouverte; les courriels habituels de paiement refusé sont envoyés.

## Modifier ou arrêter un calendrier

1. Choisissez la famille dans la liste.
2. Pour modifier, choisissez une autre fréquence ou un autre jour et appuyez sur **Enregistrer le calendrier**. La prochaine date est recalculée à partir d'aujourd'hui.
3. Pour arrêter, appuyez sur **Arrêter le calendrier** et confirmez. Plus aucune facture n'est émise; les factures déjà émises restent. La famille revient à votre facturation mensuelle habituelle.

> ⚠️ Un calendrier enregistré avant l'automatisation affiche **Pas encore actif**. Il ne démarre que lorsque vous appuyez de nouveau sur **Enregistrer le calendrier**, pour que personne ne soit facturé par surprise.

## Qui peut faire quoi

- **Administrateurs d'agence** et **de plateforme** : établir, modifier et arrêter un calendrier pour toute famille de l'agence.
- **Direction de centre** : la même chose pour les familles de ses propres centres.
- **Personnel éducateur** et **parents** : pas d'accès à cet écran.

## Questions fréquentes

??? Dois-je encore lancer la facturation mensuelle pour cette famille? | Non. La facturation mensuelle ignore une famille dont le mois est déjà couvert, et le calendrier ignore une période déjà facturée : une famille n'est jamais facturée deux fois.
??? Le statut indique « Pas encore actif ». | Le calendrier date d'avant l'automatisation. Appuyez sur **Enregistrer le calendrier** pour le démarrer.
??? Une exécution indique « Déjà facturé pour cette période ». | Une autre facture couvre déjà ces dates. Rien de plus n'a été émis.
??? La famille est-elle avisée? | Oui, par notification et courriel, comme pour toute facture. Les agences de test n'envoient rien.
??? Pourquoi la carte n'a-t-elle pas été débitée? | Pas de prélèvement automatique, fonction absente de votre forfait, ou aucun processeur de paiement connecté. La note l'indique; la facture reste ouverte.

## Écrans liés

[[open: #payment-plans | Échéanciers de paiement]] [[open: #payment-run | Traitement des paiements]] [[open: #external-billing | Comptabilité]]

Voir aussi : [Échéanciers de paiement](payment-plans) · [Prélèvement automatique](autopay) · [Grands livres](account-ledgers)
