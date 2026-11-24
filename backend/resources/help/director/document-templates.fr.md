---
roles: agency_admin, platform_admin
title: Modèles de documents
category: Settings
order: 40
---
# Modèles de documents

**Paramètres → Modèles de documents.** Contrôlez l’apparence réelle d’un bulletin de paie, d’une facture ou d’un reçu, sans attendre une mise à jour.


## Étapes rapides

1. Allez à **Paramètres → Modèles de documents**.
2. Choisissez le type de document — bulletin de paie, facture ou reçu.
3. Partez de la mise en page intégrée, ou appuyez sur **Importer** et choisissez un fichier Blade à convertir (c’est ainsi que les modèles d’iLearn ont été repris).
4. Modifiez le HTML, en utilisant `{{ name }}` pour une valeur et `{{#each rows}}` pour répéter une ligne.
5. **Aperçu** — il s’affiche à partir d’un véritable enregistrement.
6. **Activez** lorsque le résultat vous convient. Un seul modèle par type est actif à la fois.

Si aucun modèle n’est actif, les documents s’affichent avec la mise en page intégrée; activer un modèle est donc toujours réversible.

## Comment fonctionne un modèle

Un modèle est du HTML avec des paramètres substituables :

- `{{ name }}` — une valeur, échappée
- `{{{ name }}}` — une valeur insérée telle quelle, pour un bloc de balisage que vous fournissez
- `{{#if x}} … {{#else}} … {{/if}}` — affiche une section seulement s’il y a quelque chose à afficher
- `{{#each rows}} … {{/each}}` — répète une section pour chaque ligne

C’est tout le langage. Le moteur n’exécute aucun code et ne fait aucun calcul : chaque total, date et montant est calculé et mis en forme avant d’atteindre le modèle. Un modèle ne peut donc jamais abîmer un bulletin de paie ni divulguer ce qu’on ne lui a pas transmis.

## Importer une conception existante

**Importer** accepte un fichier Blade — le format dans lequel sont rédigés les bulletins de paie et les factures d’iLearn. Il convertit les parties qu’un modèle peut exprimer et signale tout ce qu’il n’a pas pu convertir, de sorte que vous sachiez quoi terminer à la main plutôt que de découvrir une ligne vide sur un bulletin de paie.

## Bon à savoir

- Un seul modèle par type de document est **actif** à la fois; les autres sont conservés.
- La mise en page intégrée est la solution de repli : si aucun modèle n’est actif, les documents s’affichent comme toujours.
- Faites un aperçu avant d’activer — l’aperçu utilise un véritable enregistrement.
