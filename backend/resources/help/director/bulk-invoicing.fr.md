---
title: Génération de factures en lot
category: Billing
order: 70
roles: agency_admin, platform_admin
---
# Génération de factures en lot

**Finances → Facturation en lot** crée d'un coup les factures de frais du mois pour chaque famille inscrite d'un centre, puis les envoie par courriel. Les rabais fratrie et la subvention CWELCC sont appliqués automatiquement. Le même écran permet aussi de créer une seule facture pour une famille, une éducatrice ou un entrepreneur.

[[open: #bulk-invoices | Ouvrir la facturation en lot]]

L'écran compte trois onglets : **👪 Parents** (factures de frais des familles, par centre), **🧑‍🏫 Éducateurs** et **🧾 Entrepreneurs et fournisseurs**.

## Créer les factures du mois pour un centre

1. Dans le menu latéral, ouvrez **Finances** et choisissez **Facturation en lot**.
2. En haut, choisissez la **Période de facturation** : le mois et l'année. Le mois courant est choisi au départ.
3. Dans l'onglet **👪 Parents**, trouvez le centre. Chaque ligne montre sa ville, le nombre d'enfants inscrits et le nombre de familles.
4. Ouvrez le menu **⋮** du centre et choisissez **Générer les factures**.
5. Lisez le message et confirmez. Une facture est créée par famille inscrite ; les familles déjà facturées pour ce mois, ici ou dans un système de facturation relié, sont sautées.
6. La ligne affiche « ✓ N created » avec le total, et le message **Factures générées** indique combien ont été créées et combien étaient déjà facturées.

Chaque facture contient :

- une ligne de **frais** par enfant inscrit, selon les frais mensuels de son inscription ;
- une ligne de **subvention CWELCC** déduite quand le centre y participe ;
- une ligne de **rabais fratrie** quand votre agence a des paliers configurés (voir [Rabais fratrie](sibling-discounts)) ;
- le mois complet comme période, émise le premier du mois et due 15 jours plus tard.

## Vérifier et envoyer les factures

1. Ouvrez le menu **⋮** du centre et choisissez **Voir les factures**. Une fenêtre liste les factures du centre pour la période choisie.
2. Appuyez sur l'icône d'œil (**Voir la facture**) d'une ligne pour ouvrir la facture à l'image de votre agence.
3. Pour annuler une facture erronée, utilisez le bouton d'annulation de sa ligne et confirmez. Une facture payée affiche plutôt **PAID** : remboursez d'abord le paiement, puis elle pourra être annulée.
4. Quand tout est correct, fermez la fenêtre et choisissez **⋮ > Envoyer les factures aux familles par courriel**.
5. Confirmez. Le message **Factures envoyées par courriel** indique combien ont été envoyées et combien ont été sautées faute de courriel au dossier.

> ⚠️ Générer les factures n'envoie rien. Les familles ne reçoivent un courriel que lorsque vous choisissez **Envoyer les factures aux familles par courriel**, et seulement si le courriel de votre agence est activé.

## Créer une seule facture

1. Appuyez sur **🧾 Générer une facture** dans l'onglet voulu (dans **🧑‍🏫 Éducateurs**, vous pouvez aussi appuyer sur **Invoice** à côté d'une personne).
2. Choisissez **Qui** dans la liste, ou tapez un nom.
3. Sous **Basé sur**, choisissez **Un montant fixe** et entrez le **Montant**, ou **Hours worked × a rate** et entrez **Hours** et **Rate / hour** (pour une éducatrice, **Utiliser les heures de la feuille de temps** remplit les heures).
4. Facultatif : réglez **Période du** / **to**, **Détails**, **Lignes supplémentaires** (**+ Add a line**), **Appliquer la taxe** et **Répéter selon un horaire** (chaque semaine, aux deux semaines ou chaque mois).
5. Appuyez sur **Générer**. Vérifiez le résumé sous **Vérifiez avant la création**.
6. Appuyez sur **Créer cette facture** (ou, pour une facture répétée, vérifiez chaque période sous **Confirmez chaque période** et appuyez sur **Créer ces factures**). Le message **Invoice created** le confirme.

La facture apparaît dans la liste de cet onglet, où vous pouvez la **View**, la **Resend**, la **Marquer comme émise**, la **Marquer comme payée** ou l'**Annuler**. Rien n'est envoyé à la création.

> 💡 Les éducatrices sont normalement payées à partir de leurs heures enregistrées dans la **Paie**, et les factures des fournisseurs se gèrent mieux dans les **Dépenses**. Les onglets le disent et y mènent.

## Relancer un mois

Les factures déjà générées pour ce mois ne sont pas dupliquées. Relancer le même mois crée des factures seulement pour les familles qui n'en ont pas encore pour cette période ; vous pouvez donc relancer sans crainte après avoir inscrit une nouvelle famille. Pour refaire la facture d'une famille, annulez d'abord l'ancienne (dans **Voir les factures** ou la **Comptabilité**), puis relancez.

## Qui peut faire quoi

- **Administration de l'agence** : lance n'importe quel centre de son agence.
- **Administration de la plateforme** : lance n'importe quel centre de l'agence choisie dans le sélecteur de la barre du haut.
- **Directions de centre** : n'ont pas cet écran. Demandez à votre administration d'agence de lancer le mois, ou créez les factures une à une à partir de la **Comptabilité**.
- **Éducatrices** et **parents** : aucun accès.

## Questions fréquentes

??? Le traitement a créé 0 facture. | Vérifiez que les enfants du centre sont Inscrits (pas en liste d'attente, retirés ou diplômés) avec une inscription ouverte, et que leurs familles n'ont pas déjà été facturées ce mois-ci, ici ou dans un système de facturation relié.
??? Le message indique « Échec de la génération ». | Le texte dessous en donne la raison. Corrigez-la (par exemple, choisissez la bonne agence) et relancez. Les familles facturées lors du premier essai sont sautées.
??? Des familles ont été sautées à l'envoi. | Elles n'ont pas de courriel au dossier. Ajoutez-en un au profil du tuteur, puis renvoyez, ou utilisez Renvoyer la facture dans la Comptabilité.
??? Erreur « Pop-up blocked ». | Autorisez les fenêtres surgissantes pour ce site afin de voir une facture.
??? « No centres in this agency. » | L'administration de la plateforme doit peut-être d'abord choisir une agence dans le sélecteur de la barre du haut.
??? Que contient Exporter les factures en CSV? | Toutes les factures de l'agence, pas seulement ce centre ou ce mois.

## Écrans connexes

[[open: #external-billing | Comptabilité]] [[open: #payment-plans | Plans de paiement]] [[open: #billing-settings | Paramètres de facturation]]

Voir aussi : [Générer les factures mensuelles](generating-monthly-invoices) · [Rabais fratrie](sibling-discounts) · [Comptabilité](accounting)
