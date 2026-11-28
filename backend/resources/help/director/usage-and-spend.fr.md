---
title: Utilisation et dépenses (textos, appels et IA par agence)
---
# Utilisation et dépenses (textos, appels et IA par agence)

**Platform › Usage & spend** montre ce que coûtent à KiddieTrac les textos, les appels téléphoniques et l'IA de chaque agence, signale les agences qui dépassent un seuil de dépenses hebdomadaire, et gère le **forfait de textos et d'appels** mensuel (le module SMS Incremental), où vous décidez si une agence qui l'a dépassé paie le surplus. Les agences ne voient jamais cet écran.

[[open: #usage-spend | Ouvrir Usage & spend]]

## Facturer une agence qui a dépassé son forfait de textos et d'appels

La carte du haut, **📶 Text & call allowance**, compte chaque texto et chaque appel passé, entrant et sortant, par mois civil. Chaque bloc supplémentaire de la taille du forfait coûte le prix indiqué (par exemple 1 000 permis et 2 400 utilisés = 2 blocs). **Rien n'est facturé tant que vous n'appuyez pas sur Add to next invoice.**

1. Utilisez **‹** et **›** pour choisir le mois.
2. Lisez la bannière : soit les agences au-dessus du forfait avec leur montant, soit **✅ No agency is waiting on an overage decision**.
3. Dans le tableau, regardez les barres **Texts** et **Calls** de chaque agence (orangée à 80 %, rouge avec **… blocks over**) et sa colonne **Billing** (**Next invoice …** ou **Not billed yet**).
4. Dans la colonne **Charge**, appuyez sur **Add … $ to next invoice**, lisez la confirmation et appuyez sur **Add to next invoice**. La ligne affiche **✓ … on next invoice**.
5. Ou appuyez sur **Waive** pour laisser tomber ce mois-là. La ligne affiche **Waived**.
6. Une erreur? Appuyez sur **Undo** à côté du montant ou de la dispense. Un montant peut être annulé jusqu'à ce que sa facture soit émise; ensuite, il indique **invoiced**.

> ⚠️ Si **Billing** indique **Not billed yet**, la facturation n'est pas activée pour cette agence : la ligne approuvée attend qu'elle le soit.

## Changer le forfait

1. Tapez de nouveaux nombres dans **Default texts / month** et **Default calls / month** (ils s'appliquent à toutes les agences).
2. Pour donner à une agence son propre forfait, tapez ses nombres dans **Own allowance (texts · calls)**. Laissez vide pour utiliser la valeur par défaut.
3. Appuyez sur **Save allowances**. Le message **Allowances saved** s'affiche.

## Vérifier les dépenses hebdomadaires et les seuils

1. Sous la carte du forfait, choisissez **Last 7 days** ou **Last 30 days**.
2. Lisez la bannière. Sur la vue de 7 jours, elle nomme les agences **over a threshold this week**, ou indique **✅ No agency is over a threshold for the last 7 days**. Les seuils sont hebdomadaires; la vue de 30 jours n'affiche donc aucun signalement.
3. Dans le tableau, lisez le coût **Texts**, **Calls**, **AI** et **Total** de chaque agence. Un coût au-dessus de son seuil est en rouge.
4. Sous **💲 Rates**, indiquez ce que vous coûte une unité : **Text, per segment**, **Call, per minute** et les deux tarifs d'IA (ils servent seulement pour un modèle d'IA absent de la liste de prix intégrée; les modèles listés ont leurs propres prix).
5. Sous **🚨 Default weekly thresholds**, indiquez la limite en dollars par semaine pour les textos, les appels et l'IA (0 = ne jamais signaler).
6. Pour donner à une agence ses propres limites, remplissez ses cases sous **Own weekly thresholds ($)**. Vide = valeur par défaut; 0 = ne jamais signaler cette agence.
7. Appuyez sur **Save rates & thresholds**. Le message **Rates and thresholds saved** s'affiche.

## Le courriel du résumé hebdomadaire

Chaque lundi à 7 h 15, les administrateurs de la plateforme reçoivent par courriel le **Platform weekly summary**, avec en tête toute agence au-dessus de son seuil hebdomadaire. Appuyez sur **✉️ Email me this week's summary** pour en recevoir une copie tout de suite à votre adresse.

## Qui peut faire quoi

| Rôle | Accès |
|---|---|
| Administrateur de la plateforme | Tout ici : tarifs, seuils, forfaits, montants et dispenses. |
| Administrateurs d'agence et tous les autres | Aucun accès. Une agence voit un dépassement approuvé seulement comme une ligne sur sa prochaine facture KiddieTrac. |

## Questions fréquentes et problèmes

??? « Your account has no email address. » | Le résumé de test va à votre propre adresse courriel. Ajoutez-en une à votre profil d'abord.
??? Une agence est en rouge mais je ne veux pas la facturer. | Appuyez sur Waive pour ce mois. Rien n'est facturé à moins que vous n'appuyiez sur Add to next invoice.
??? Je ne peux pas appuyer sur Undo. | Le montant est déjà sur une facture (« invoiced »). Corrigez-le plutôt dans Accounting AR/AP.
??? « That did not go through ». | Le montant, la dispense ou l'annulation a échoué. Actualisez et réessayez.
??? Les agences sont-elles avisées quand elles dépassent? | Non. Cet écran et le résumé hebdomadaire sont réservés aux administrateurs de la plateforme.

## Écrans liés

[[open: #ai-usage | Utilisation de l'IA]] [[open: #sales-invoices | Comptabilité CR/CF]] [[open: #sales-plans | Forfaits et prix]] [[open: #admin-mrr | Tableau MRR]]
