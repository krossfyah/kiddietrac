---
title: Rappels de facturation automatisés
category: Billing
order: 25
---

# Rappels de facturation automatisés

**Paramètres → Facturation → 🔔 Rappels.** Planifiez des courriels automatiques aux familles au sujet des factures qui approchent de leur échéance et des factures en souffrance. Les administrateurs d’agence **et** les directeurs de centre peuvent les gérer.

[[show: #billing-settings @ Reminders > Send upcoming-invoice reminders | Afficher les paramètres de rappel]]

Les mêmes paramètres apparaissent aussi sous **Paramètres → Paramètres de messagerie → Facturation**, à côté de vos autres courriels automatisés. Il s’agit d’un seul ensemble de paramètres avec deux points d’accès : une modification faite à un endroit apparaît aussi à l’autre.

Les **directeurs de centre** ne voient que les rappels, pas le reste des paramètres de facturation :

[[open: #billing-setup | Ouvrir les rappels de facturation]]

## Ce que vous pouvez configurer

- **Rappels de facture** : cochez **Envoyer des rappels de facture à venir**, puis indiquez les **Jours avant l’échéance**, séparés par des virgules. La valeur par défaut est `7,3`.
- **Rappels de paiement en souffrance** : cochez **Envoyer des rappels de paiement en souffrance**, puis indiquez les **Jours après l’échéance**. La valeur par défaut est `1,7,14`.
- **Heure d’envoi quotidienne** : l’heure à laquelle les rappels partent, **dans le fuseau horaire de votre agence**. La valeur par défaut est 09:00.
- **✉️ Envoyer par courriel** : les rappels sont envoyés par courriel.
- **Qui reçoit une copie** : activez **Envoyer une copie de chaque rappel au personnel**, puis choisissez : les **administrateurs de l’agence**, les **directeurs du centre de la facture** (seulement pour les familles de leurs propres centres), **l’adresse du contact de facturation de l’agence**, et le personnel que vous cochez (par exemple un teneur de livres). Chaque copie est une **copie conforme invisible** (Cci) du courriel de la famille : les familles ne voient jamais les adresses du bureau. Interrupteur désactivé, aucune copie n’est envoyée au personnel. **Copier aussi les factures envoyées à leur propre date (versements)** ajoute aussi une copie de chaque nouvelle facture. Un membre du personnel qui est aussi tuteur dans cette famille reçoit seulement le courriel de sa famille, sans copie en plus.
- **Note personnalisée ajoutée à chaque rappel (facultatif)** : ajoutée à chaque rappel que vous envoyez.

Cliquez sur **Enregistrer les rappels**.

Les jours sont comptés à partir de la date d’échéance de chaque facture, selon le calendrier de votre agence. Chaque rappel est envoyé **une seule fois**, exactement le jour prévu.

## Qui les reçoit

Les rappels sont envoyés aux tuteurs de la famille :

- Si un tuteur est désigné pour **recevoir la facturation**, seuls ces tuteurs reçoivent le courriel.
- Sinon, chaque tuteur pouvant se connecter reçoit le courriel.
- Les tuteurs qui n’ont pas encore accepté leur invitation, ou dont le compte est désactivé, sont ignorés.

## Quelles factures sont incluses

Les factures créées dans KiddieTrac comme celles synchronisées depuis un système de facturation externe sont incluses, tant qu’il leur reste un solde.

- **Partiellement payée** : incluse. Le rappel indique le **solde restant**, et non le total initial.
- **Payée**, **remboursée**, **annulée** : jamais incluses. On ne relance personne pour de l’argent qu’il a payé ou qui lui a été remboursé.
- Factures **en attente** d’un plan de paiement : non incluses. Elles ne sont pas dues tant qu’elles ne sont pas émises.

Chaque rappel indique le numéro de la facture, le solde et la date d’échéance. Une famille ayant plusieurs factures ouvertes peut ainsi savoir de laquelle il s’agit.

## Activer les rappels

Les rappels sont **désactivés par défaut** pour toutes les agences. Rien n’est envoyé tant que vous ne cochez pas au moins l’un des deux types de rappel.

> **Important :** Il existe aussi un **interrupteur à l’échelle de la plateforme**, actuellement **désactivé**. Dans ce cas, l’onglet Rappels affiche une bannière jaune : *Les rappels automatisés sont actuellement DÉSACTIVÉS pour toute la plateforme.* Votre calendrier est enregistré et les envois commenceront dès que KiddieTrac activera les rappels pour la plateforme.

> **Astuce :** Vérifiez vos listes de jours, l’heure d’envoi et la note personnalisée avant l’activation de l’interrupteur de la plateforme. À partir de ce moment, de vraies familles commenceront à recevoir les rappels que vous avez planifiés.

## Questions courantes

??? Pourquoi une famille n’a-t-elle pas reçu de rappel? | Vérifiez qu’elle a un tuteur avec une connexion active, que la facture a encore un solde et qu’elle n’était pas encore en attente ce jour-là.
??? Puis-je envoyer par SMS? | Pas pour le moment. Les rappels de facturation sont envoyés uniquement par courriel.
??? Dans quel fuseau horaire est l’heure d’envoi? | Celui de votre agence, donc 09:00 signifie 9 h là où se trouvent vos centres.

Voir aussi : [Configuration de la facturation](billing-setup) · [Plans de paiement](payment-plans) · [Automatisation des frais de retard](late-fees)
