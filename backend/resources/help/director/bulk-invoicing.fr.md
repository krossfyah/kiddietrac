---
title: Génération de factures en lot
category: Billing
order: 70
---
# Génération de factures en lot

Lancez la facturation mensuelle de toutes les familles inscrites d’un centre en un clic. Vous la trouverez sous **Administration → Facturation en lot** dans la barre latérale.

## Fonctionnement

1. Choisissez la **période de facturation** en haut — mois et année. Par défaut, le mois courant.
2. Choisissez un centre dans la liste et cliquez sur **Générer les factures**.
3. Confirmez l’invite. Une facture par famille inscrite est créée, avec :
   - Des lignes de frais de garde par enfant (une ligne par enfant inscrit à ce centre)
   - Des lignes de **subvention CWELCC** soustraites automatiquement lorsque le centre y a adhéré
   - Des lignes de **rabais pour fratrie** appliquées lorsque l’agence a configuré des paliers (voir `sibling-discounts.md`)
   - Début et fin de période réglés sur le mois complet
   - `issued_at` fixé au premier du mois, `due_at` 15 jours plus tard
   - Statut = `sent`
4. Après l’exécution, la pastille de résultat sur chaque ligne de centre indique le nombre de factures créées et leur valeur totale en $.

## Relancer l’exécution

Les factures déjà générées pour ce mois ne sont **pas dupliquées** : les lignes existantes restent telles quelles. Relancer pour le même mois crée des factures uniquement pour les familles qui n’en ont pas déjà une pour cette période. Vous pouvez la relancer sans risque.

Si vous devez tout effacer et recommencer, supprimez d’abord les factures manuellement (ou par SQL).

## Autorisations

- **Administrateur d’agence** — exécute pour n’importe quel centre de son agence
- **Administrateur de la plateforme** — exécute pour n’importe quel centre de la plateforme au moyen du sélecteur d’agence
- **Directeur de centre** — utilise la route existante `/director/invoices/generate` depuis son tableau de bord (son propre centre seulement)
- **Éducateur** — aucun accès

## Ce qui est facturé

- Une ligne de **frais de garde** par enfant inscrit, calculée selon le tarif mensuel de sa salle
- Une ligne de **subvention** par enfant lorsque le centre a adhéré au CWELCC (soustraite du total)
- Une ligne d’**ajustement** par palier de rabais pour fratrie applicable (soustraite du total)

L’en-tête de la facture contient les montants cumulés `subtotal`, `subsidy_amount`, `discount_amount`, `total` et `balance_due`.

## Dépannage

Si l’exécution indique 0 facture générée, vérifiez :

- Des enfants du centre sont-ils actuellement `enrolled` (et non en liste d’attente, retirés ou diplômés)?
- Ces enfants ont-ils une inscription active (`enrollments.end_date IS NULL`)?
- Les familles avaient-elles déjà une facture v22p42 pour ce mois précis? Les lignes existantes sont ignorées.

L’écran de facturation en lot appelle le même module que le bouton « Générer ce mois-ci » du portail directeur : même logique, mêmes résultats, mais utilisable centre par centre dans toute une agence.
