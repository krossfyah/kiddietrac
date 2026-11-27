---
title: Calendrier de facturation hebdomadaire / aux deux semaines
category: Billing
order: 80
roles: agency_admin, centre_director, platform_admin
---
# Calendrier de facturation

**Finances → Calendrier de facturation** indique à quelle fréquence une famille est facturée : chaque semaine, aux deux semaines ou chaque mois, et quel jour. Utilisez-le pour une famille qui a convenu de payer à la semaine ou aux deux semaines plutôt qu'une fois par mois, afin que tout le bureau voie son rythme et sa prochaine date de facturation.

[[open: #billing-schedule | Ouvrir le calendrier de facturation]]

## Définir le calendrier d'une famille

1. Dans le menu latéral, ouvrez **Finances** et choisissez **Calendrier de facturation**.
2. Choisissez la famille par son nom dans la liste **Famille**. Son calendrier se charge tout seul (ou appuyez sur **Charger**).
3. Choisissez la **Fréquence** : **Hebdomadaire**, **Aux deux semaines** ou **Mensuel**. Une famille sans calendrier affiche **Mensuel**.
4. Pour l'hebdomadaire ou aux deux semaines, entrez le **Day of week (0=Sun … 6=Sat)**. Par exemple, 1 est le lundi et 5 le vendredi.
5. Pour le mensuel, entrez le **Day of month (1-31)**, par exemple 1 pour le premier du mois.
6. Appuyez sur **Enregistrer l’horaire**. Le formulaire se recharge et affiche **Prochain prélèvement** avec la date calculée à partir de vos choix.

> 💡 Seul le champ qui correspond à la fréquence est utilisé : le jour de la semaine pour l'hebdomadaire et aux deux semaines, le jour du mois pour le mensuel. Vous pouvez laisser l'autre tel quel.

### Comment la prochaine date est calculée

- **Hebdomadaire** : la prochaine fois que ce jour de la semaine revient.
- **Aux deux semaines** : une semaine après la prochaine fois que ce jour revient ; la première date tombe donc dans 8 à 14 jours.
- **Mensuel** : ce jour-là le mois prochain.

## Modifier ou désactiver un calendrier

1. Choisissez la famille dans la liste **Famille**.
2. Pour le modifier, choisissez une nouvelle **Fréquence** ou un nouveau jour et appuyez de nouveau sur **Enregistrer l’horaire**. La nouvelle date remplace l'ancienne.
3. Pour l'arrêter, appuyez sur **Désactiver**. La famille revient à la facturation habituelle de votre agence.

## Où le calendrier apparaît

Le calendrier actif d'une famille apparaît dans son **grand livre** (administration de l'agence : **Finances → Grands livres des comptes**), sous **À venir**, comme « Next weekly invoice » (ou biweekly / monthly) avec la date. Cette ligne n'a pas de montant et indique qu'aucune facture n'a encore été émise, car le montant n'est fixé qu'à l'émission. Voir [Grands livres des comptes](account-ledgers).

> ℹ️ Enregistrer un calendrier de facturation ne crée pas de facture et ne débite aucune carte à lui seul. Les factures viennent toujours de vos moyens habituels : la **Facturation en lot**, un **plan de paiement** ou une facture créée à la main. Le prélèvement automatique débite une carte enregistrée quand une facture arrive à échéance. Voir [Prélèvement automatique](autopay).

## Quel outil choisir?

| Vous voulez… | Utilisez |
|---|---|
| Indiquer qu'une famille est facturée chaque semaine, aux deux semaines ou chaque mois | **Calendrier de facturation** (cet écran) |
| Répartir un total convenu en paiements datés, chacun avec sa facture | [Plans de paiement](payment-plans) |
| Choisir la carte ou le compte avec lequel une famille paie | Le portefeuille de la famille et le [Prélèvement automatique](autopay) |
| Définir la fréquence par défaut de l'agence pour les nouvelles factures et grilles de frais | **Paramètres → Facturation → Valeurs par défaut et frais** ([Configuration et paramètres de facturation](billing-setup)) |

## Qui peut faire quoi

- **Administration de l'agence** et **administration de la plateforme** : définir, modifier et désactiver un calendrier pour n'importe quelle famille de l'agence (la plateforme, pour l'agence choisie dans le sélecteur de la barre du haut).
- **Directions de centre** : la même chose, pour les familles de leurs propres centres. Une famille d'un autre centre ne peut pas être ouverte.
- **Éducatrices** et **parents** : aucun accès à cet écran.

## Questions fréquentes

??? La liste Famille indique « Could not load families ». | La liste n'a pas pu être récupérée. Rechargez la page ; l'administration de la plateforme doit d'abord choisir une agence dans le sélecteur de la barre du haut.
??? J'ai appuyé sur Charger et je vois « Choose a family first. » | Choisissez une famille dans la liste Famille, puis appuyez sur Charger.
??? Rien ne semble se passer quand j'appuie sur Enregistrer l’horaire. | Le formulaire se recharge sur place. Regardez la ligne Prochain prélèvement sous les champs : elle affiche la nouvelle date.
??? La famille est-elle avisée du changement? | Non. Cet écran n'envoie ni courriel, ni texto, ni notification. Avisez la famille vous-même si son jour de facturation change.
??? Est-ce que cela change le montant que paie la famille? | Non. Cela indique quand elle est facturée, pas combien. Les montants viennent de sa grille de frais et de ses factures.
??? Pourquoi le grand livre n'affiche-t-il aucun montant pour la prochaine date? | Le montant n'est fixé qu'à l'émission de la facture ; le grand livre nomme donc la date et laisse le montant vide.

## Écrans connexes

[[open: #payment-plans | Plans de paiement]] [[open: #external-billing | Comptabilité]]

Voir aussi : [Plans de paiement](payment-plans) · [Prélèvement automatique](autopay) · [Grands livres des comptes](account-ledgers)
