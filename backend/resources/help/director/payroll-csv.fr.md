---
title: Exportation CSV prête pour la paie
category: Staff Management
order: 32
---
# Exportation de la paie

Obtenez un CSV de période de paie avec les heures pointées de chaque membre du personnel. Importez-le directement dans ADP, Wave ou chez votre fournisseur de paie.

## Comment l’exécuter

1. Barre latérale → **Paie**.
2. Choisissez une plage de dates (par défaut, les 30 derniers jours).
3. Cliquez sur **Exécuter** pour voir un sommaire par membre du personnel, ou sur **⤓ CSV** pour télécharger le fichier.

## Ce qui est inclus

Pour chaque combinaison (membre du personnel × centre) dans la plage :
- Nom et courriel du membre du personnel
- Nom du centre
- Nombre de pointages (nombre d’événements de pointage d’arrivée)
- Total des minutes
- Total des heures (arrondi à deux décimales)

Seuls les pointages fermés sont comptés : quiconque est encore pointé n’apparaîtra qu’après avoir pointé son départ.

## Format du fichier

`payroll-YYYY-MM-DD-to-YYYY-MM-DD.csv`, en UTF-8 avec BOM, qui s’ouvre sans problème dans Excel / Numbers / Sheets.

## Corriger d’abord les heures

L’exportation lit les mêmes pointages que les **Feuilles de temps**. Si un quart est erroné — un pointage de départ que personne n’a fait, un quart jamais pointé — corrigez-le à cet endroit avant d’exporter, et non ensuite dans le tableur. Voir [Feuilles de temps et correction des heures](timesheets).
