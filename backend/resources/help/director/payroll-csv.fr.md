---
title: Exportation CSV prête pour la paie
category: Staff Management
order: 32
---
# Exportation CSV prête pour la paie

Téléchargez un chiffrier des heures pointées par chaque membre du personnel pour une période de paie, prêt à importer dans ADP, Wave ou le fournisseur de paie de votre agence. Le fichier vient de l’écran **Paie** dans **Ressources humaines**.

[[open: #payroll | Ouvrir Paie]]

## Télécharger le fichier des heures

1. Ouvrez **Ressources humaines > Paie**. La vue **⏱️ Hours & payslips** s’ouvre sur les 30 derniers jours.
2. Appuyez sur une période rapide comme **Dernière période de paie** ou **Last month**, ou saisissez les dates **From** et **To**.
3. Appuyez sur **Exécuter** pour vérifier d’abord les heures à l’écran.
4. Appuyez sur **⤓ CSV**. Le fichier se télécharge sur votre ordinateur.

Le fichier s’appelle `payroll-AAAA-MM-JJ-to-AAAA-MM-JJ.csv`. Il est enregistré en UTF-8 avec marque d’ordre des octets : les accents et les noms s’ouvrent correctement dans Excel, Numbers et Google Sheets.

## Ce que contient le fichier

Une ligne pour chaque membre du personnel à chaque centre où il a pointé, avec :

- le nom et le courriel;
- le nom du centre;
- le nombre de pointages (combien de fois la personne a pointé son arrivée);
- le total des minutes;
- le total des heures, arrondi à deux décimales.

Seuls les pointages fermés comptent. Une personne encore pointée n’apparaît pas tant qu’elle n’a pas pointé son départ.

> ℹ️ C’est un fichier d’heures. Les personnes payées sans pointer (personnel salarié, entrepreneurs) n’ont pas de pointage : elles sont à l’écran avec leurs talons de paie, mais pas dans le CSV.

## Corriger les heures d’abord

L’exportation lit les mêmes pointages que **Pointages et feuilles de temps**. Si un quart est faux (un départ que personne n’a pointé, un quart jamais pointé), corrigez-le là avant d’exporter, pas dans le chiffrier après coup.

1. Ouvrez **Ressources humaines > Pointages et feuilles de temps**.
2. Corrigez le quart avec son menu **⋮**.
3. Revenez à **Paie** et appuyez de nouveau sur **⤓ CSV**.

## Qui peut l’utiliser

Les administrateurs de l’agence et les directeurs de centre. Les directeurs obtiennent le personnel de leurs propres centres.

## FAQ

??? Le fichier est vide. | Personne n’a pointé entre ces dates. Élargissez les dates et appuyez sur **Exécuter** pour vérifier.
??? Une personne manque dans le fichier. | Elle n’a aucun pointage fermé dans la période, ou elle est encore pointée.

## Voir aussi

- [Cycles de paie, heures et talons de paie](payroll-documents)
- [Feuilles de temps et correction des heures](timesheets)
