---
title: Grand livre du compte + relevé
category: Billing
order: 77
---
# Grand livre du compte

Votre historique complet des factures, paiements et remboursements avec un solde courant. Téléchargeable sous forme de relevé PDF à l’image de votre agence.

## Où le trouver

- **Parent** : Menu latéral → **Grand livre du compte** sous Compte.
- **Directeur / administrateur** : Ouvrez une famille dans **Familles** → bouton « Voir le grand livre ». Chemin : `/api/v1/families/{id}/ledger`.

## Ce qu’il contient

Chaque ligne est triée par date :

- Les **factures** apparaissent comme des débits (argent que vous devez)
- Les **paiements** apparaissent comme des crédits (argent reçu)
- Les **remboursements** apparaissent comme des débits avec le préfixe `Refund:`

La colonne de droite affiche le **solde courant** après chaque transaction. Le haut de la page résume le solde en souffrance, le total facturé, le total payé et le total remboursé.

## Relevé PDF

Touchez **⤓ Télécharger le relevé PDF** dans l’en-tête. Cela génère un PDF propre à l’image de l’agence, avec le logo de l’agence, la liste complète des transactions et le solde en souffrance actuel. Utile pour la tenue de dossiers ou à des fins fiscales.

## Différent du Portefeuille

Le Portefeuille contient vos modes de paiement enregistrés. Le Grand livre est votre historique de transactions. Ce sont deux entrées distinctes du menu latéral.
