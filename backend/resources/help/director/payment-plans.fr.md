---
title: Plans de paiement
category: Billing
order: 79
roles: agency_admin, centre_director, platform_admin
---
# Plans de paiement

**Finances → Plans de paiement** répartit un total convenu en paiements datés, et chaque paiement crée sa propre facture peu avant son échéance. Utilisez-le quand une famille accepte de payer un solde, des frais d'inscription ou les frais d'une session en versements. (Le nom à l'écran a changé, mais la fonction est la même.)

[[open: #payment-plans | Ouvrir Plans de paiement]]

![Plans de paiement, avec la facture et la date d'échéance de chaque paiement](https://api.kiddietrac.com/help-img/payment-schedules.png)

En haut, **📊 Statistiques** montre les plans actifs, ce qui est dû dans les 30 prochains jours, les paiements manqués, la façon dont les familles paient et les encaissements prévus, pour votre agence (ou vos centres, pour une direction). Filtrez par **Centre** et **Status**.

## Créer un plan de paiement

[[show: #payment-plans @ + New payment schedule | Afficher le bouton Nouveau plan de paiement]]

1. Appuyez sur **+ Nouveau plan de paiement**.
2. Choisissez la **Famille**.
3. Entrez le **Total amount ($)** et choisissez à quelle fréquence un paiement arrive à échéance sous **Every** : **Month**, **2 weeks** ou **Week**.
4. Réglez les dates **Première échéance** et **Dernière échéance**.
5. Remplissez **What is this for?**. C'est imprimé sur chaque facture. Vous pouvez utiliser {child}, {service}, {month} et {period} dans le libellé ; s'il ne nomme pas l'enfant, le nom de l'enfant est ajouté.
6. Sous **When is each instalment due?**, choisissez **Préavis** (la facture part un certain nombre de jours avant chaque échéance, à partir du paramètre de votre agence, 5 par défaut) ou **Dû immédiatement** (chaque facture est émise à sa date d'échéance).
7. Facultatif : ajoutez des **Extra charges (optional)** avec **+ Add line**, et des **Notes (optional)** internes, jamais imprimées.
8. Appuyez sur **Créer le plan**. La révision s'ouvre.
9. Vérifiez chaque paiement. Vous pouvez changer un montant, déplacer une date, **Remove** une ligne ou **+ Add instalment**. Rien n'est encore enregistré.
10. Sous **When do these go out?**, choisissez **Garder comme brouillons** (chaque facture reste en attente jusqu'au début de son préavis) ou **Émettre maintenant** (toutes les factures sont émises aujourd'hui et dues tout de suite ; les dates d'échéance ne changent pas).
11. Appuyez sur **Enregistrer l’horaire**. La carte du plan apparaît avec une ligne par paiement.

## Quand chaque facture est émise

Chaque paiement crée une facture à l'enregistrement, gardée comme brouillon **En attente**. Un traitement quotidien émet chacune le matin où son préavis commence : avec 5 jours de préavis, un versement dû le 23 est émis le 18. Une famille qui accepte en juin de payer jusqu'en décembre ne reçoit pas sept factures en juin.

Tant qu'elle n'est pas émise, une facture en attente n'est pas due : elle est exclue du solde de la famille, des montants impayés du grand livre et de tous les rappels de facturation. La date d'échéance ne bouge jamais ; seul le préavis change. La valeur par défaut de l'agence se trouve sous **Paramètres → Facturation → Valeurs par défaut et frais → Plans de paiement** ([Configuration et paramètres de facturation](billing-setup)).

> ℹ️ L'émission n'envoie aucun courriel. La facture apparaît dans l'onglet Facturation de la famille. Utilisez **Renvoyer** si vous voulez aussi l'envoyer par courriel.

## Lire le tableau

| Colonne | Ce qu'elle montre |
|---|---|
| **Date d’échéance** / **Montant** | le paiement lui-même |
| **Facture** | le numéro de la facture derrière lui |
| **Délivré** | la date d'émission ; sur une ligne en attente, elle est grisée et marquée *prévue* |
| **Délivré par** | qui a émis la facture. **Automatic** veut dire que le traitement quotidien l'a émise ; **Importé** veut dire qu'elle vient d'un système de facturation externe |
| **Statut** | le statut de la facture, avec la même pastille de couleur que dans la Comptabilité |

- **En attente** : pas encore émise, elle attend son tour.
- **Prévue** : émise, mais pas encore due. Rien n'a été manqué.
- **Délivré** : émise et due maintenant.
- **Partiellement payée**, **Payé**, **En retard** et **Void** veulent dire la même chose qu'ailleurs.

Le statut affiché est celui de la facture, pas celui de la ligne du plan : c'est la facture qui indique si l'argent est arrivé.

## Travailler sur un paiement

1. Choisissez la famille dans la liste **Famille** (l'écran se souvient de votre dernier choix).
2. Sur la ligne du paiement, ouvrez **⋮**.
3. Choisissez ce qu'il vous faut. Seules les actions qui s'appliquent à cette ligne apparaissent :
   - **Voir la facture** ou **Télécharger la facture** ;
   - **Émettre maintenant** (lignes en attente seulement) : l'émet aujourd'hui, sans courriel ;
   - **Renvoyer** (lignes émises seulement) : vous confirmez d'abord l'adresse courriel ;
   - **Modifier le versement** (lignes en attente seulement) : change ce paiement sans toucher au reste.

Pour agir sur plusieurs lignes, cochez-les et utilisez **Émettre maintenant**, **Télécharger** ou **Supprimer**. Seules les lignes en attente peuvent être émises ou supprimées ; le message indique combien ont été sautées.

## Modifier ou annuler un plan au complet

1. Sur la carte du plan, appuyez sur **Modifier la planification** pour changer le plan. Cela retire et recrée les paiements pas encore émis, et garde le préavis choisi à la création.
2. Pour arrêter un plan, appuyez sur **Annuler le plan**. Ses factures sont listées, celles en attente cochées pour être retirées ; décochez celles que vous voulez garder.
3. Confirmez. Les factures déjà émises ne peuvent pas être retirées ici, car un paiement est peut-être en route. Annulez-les volontairement dans la **Comptabilité** si c'est vraiment ce que vous voulez.

L'annulation d'une facture se fait toujours dans la [Comptabilité](accounting). Quand vous annulez une facture créée par un plan, son versement est retiré : il quitte le plan, et le total et le nombre de versements sont recalculés.

## Qui peut faire quoi

- **Administration de l'agence** et **administration de la plateforme** : créer, modifier et annuler des plans pour toute famille de l'agence.
- **Directions de centre** : la même chose pour les familles de leurs propres centres ; les Statistiques ne couvrent que leurs centres.
- **Éducatrices** : aucun accès.
- **Parents** : voient leurs propres plans, en lecture seule, et paient les factures à partir de leur onglet Facturation.

Un plan actif apparaît aussi dans le **grand livre** de la famille, sous **À venir**. Voir [Grands livres des comptes](account-ledgers).

## Questions fréquentes

??? Pourquoi une facture en attente n'est-elle pas encore partie? | Elle est émise le matin où son préavis commence : la date d'échéance moins les jours de préavis. Utilisez Émettre maintenant pour l'envoyer plus tôt.
??? Puis-je changer le montant d'un seul paiement? | Oui, tant qu'il est en attente : utilisez Modifier le versement dans le menu de la ligne. Une fois émise, annulez-la dans la Comptabilité et ajoutez-en une nouvelle.
??? Changer le préavis de l'agence touche-t-il les plans existants? | Non. Un plan garde le préavis choisi à sa création, même quand il est modifié.
??? L'écran indique « Select a family above to see their payment schedules. » | Choisissez une famille dans la liste Famille. L'administration de la plateforme doit parfois d'abord choisir une agence dans le sélecteur de la barre du haut.
??? Erreur « That range does not contain a single due date. » | Les dates de première et de dernière échéance sont trop proches pour la fréquence choisie. Élargissez la période ou choisissez une fréquence plus courte.
??? Erreur « Say what this is for — it is printed on every invoice. » | Remplissez What is this for? avant de créer le plan.
??? Les Statistiques indiquent « Insights are for directors and administrators of this agency. » | Votre rôle ou votre agence active ne peut pas voir les chiffres de l'agence. Passez à la bonne agence ou demandez à votre administration.

## Écrans connexes

[[open: #external-billing | Comptabilité]] [[open: #billing-schedule | Calendrier de facturation]]

Voir aussi : [Comptabilité](accounting) · [Grands livres des comptes](account-ledgers) · [Signification des statuts de facture](invoice-statuses) · [Configuration et paramètres de facturation](billing-setup)
