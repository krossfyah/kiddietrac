---
title: Factures de la plateforme (facturation des agences)
category: Billing
order: 90
roles: agency_admin, platform_admin
---
# Factures de la plateforme

**Revendeur → Factures.** C’est ce que les agences doivent à KiddieTrac pour la plateforme : le prix de chaque agence, les factures émises pour elle et si elles ont été payées. C’est distinct des factures que les agences envoient aux familles.

[[open: #sales-invoices | Ouvrir les factures de la plateforme]]

## Plans de facturation : ce que chaque agence paie

[[show: #sales-invoices @ Billing plans | Me montrer les Plans de facturation]]

**Plans de facturation** ouvre un tableau avec une ligne par agence :

- **Prix**, **Devise** (CAD ou USD) et **Tous les** (la fréquence de facturation de l’agence).
- **Taxe** : un taux et son nom, p. ex. 13 % TVH. Un taux supérieur à 0 doit avoir un nom, car une taxe sans nom s’imprimerait comme une simple ligne « Taxe ».
- **Total** : le prix plus la taxe. Il se met à jour pendant la saisie, ce qui vous permet de voir la taxe avant d’enregistrer.
- **Prochaine facture** : la date de la prochaine facturation de l’agence. **Une agence sans date de prochaine facture n’est pas en facturation récurrente** et n’est jamais facturée automatiquement. Utilisez ceci pour mettre une agence en pause sans perdre sa tarification.
- **Entreprise…** : les renseignements de l’agence pour le bloc **Facturé à**, tels que la dénomination sociale, l’adresse, le numéro d’inscription aux taxes et le courriel de facturation. Tout champ laissé vide est omis de la facture.

Cliquez sur **Enregistrer** sur la ligne. Le prix et les renseignements de l’entreprise sont enregistrés ensemble.

## Émettre des factures

**Émettre les factures dues maintenant** facture chaque agence dont la date de prochaine facture est arrivée, et avance chaque date selon l’intervalle de facturation de l’agence. Un aperçu s’affiche d’abord, nommant chaque agence et chaque montant et listant celles qui ont été ignorées, avec la raison. Rien n’est créé tant que vous n’avez pas confirmé.

Les factures sont émises comme **brouillons**.

## Travailler avec une facture

Des onglets en haut affichent **Ouvertes**, **Payées** et **Annulées**, avec les totaux pour chaque devise. Chaque ligne n’offre que ce qui s’applique à elle :

- **Voir la facture** : ouvre le PDF. Cela fonctionne sur toutes les lignes, y compris les annulées.
- **Émettre** : brouillons seulement. Émettre enregistre la date; aucun courriel n’est envoyé.
- **Envoyer la facture par courriel** : demande l’adresse chaque fois. Si vous n’en donnez aucune, rien n’est envoyé. Vous pouvez l’utiliser sur toute facture non annulée, y compris une déjà émise.
- **Marquer comme payée** : demande une confirmation.
- **Annuler** : demande une confirmation. La facture reste au dossier, estompée, mais ne compte plus comme due.
- **Modifier la facture** : brouillons seulement. Changez le montant avant taxe, la date d’échéance et le taux de taxe.
- **Supprimer le brouillon** : seulement pour un brouillon jamais envoyé par courriel. Dès qu’une agence en détient une copie, la facture ne peut plus qu’être annulée.

## Facturation automatique

**Paramètres → Facturation → 🏦 Facturation de la plateforme** contrôle l’exécution nocturne de 06:00. Avec les deux interrupteurs désactivés, rien ne se produit de lui-même.

[[show: #billing-settings @ Platform billing > Raise invoices automatically | Me montrer la Facturation de la plateforme]]

- **Émettre les factures automatiquement** : crée des brouillons pour toute agence dont la date de facturation est arrivée. Rien n’est envoyé.
- **Les envoyer automatiquement par courriel à l’agence** : émet chaque facture et l’envoie par courriel au contact de l’agence avec le PDF en pièce jointe. Une agence sans adresse de contact utilisable reste en brouillon.

**Les renseignements de votre entreprise**, l’**Adresse professionnelle** et le **numéro d’inscription TPS/TVH**, sont imprimés sur chaque facture émise par KiddieTrac. L’envoi automatique par courriel **ne peut pas fonctionner tant que les deux ne sont pas remplis**, et l’onglet l’indique. Sans eux, chaque PDF partirait marqué comme incomplet.

## Qui peut l’utiliser

**Admins de la plateforme** seulement. Aucun rôle d’agence ne peut le voir.

Voir aussi : [Configuration et paramètres de facturation](billing-setup) · [Aperçu de l’admin de la plateforme](platform-admin-overview)
