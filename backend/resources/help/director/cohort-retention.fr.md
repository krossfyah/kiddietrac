---
title: Rétention par cohorte
---
# Rétention par cohorte

**Compliance › Retention** montre combien d'enfants arrivés chez vous dans chacun des 12 derniers mois sont encore
inscrits aujourd'hui. Il s'agit de garder les familles, pas de la durée de conservation des dossiers (pour ça, voir
[Conservation des données et conformité](data-retention-compliance)). Servez-vous-en pour repérer un mois où plus de
familles que d'habitude sont parties peu après le début, et chercher pourquoi.

[[open: #retention | Ouvrir la rétention par cohorte]]

## Lire le rapport

1. Ouvrez **Compliance › Retention**. La bannière indique **📊 Cohort retention** et votre **Average retention** sur l'ensemble des mois.
2. Regardez le tableau **Cohort breakdown**. Chaque ligne est un mois de début (par exemple 2026-09).
3. **Enrolled** est le nombre d'enfants arrivés ce mois-là. **Still** est le nombre de ceux qui ne sont pas partis.
4. **Retention** égale Still ÷ Enrolled, avec une barre : verte à 80 % ou plus, ambre dès 50 %, rouge sous 50 %. Un tiret (—) veut dire que personne n'a commencé ce mois-là.
5. Comparez les mois récents aux plus anciens : les cohortes récentes ont eu moins de temps pour partir, elles paraissent donc souvent meilleures.

> 💡 Un mois rouge avec un ou deux enfants seulement n'est pas une tendance. Regardez les mois qui comptent assez d'arrivées.

## Agir sur un mois faible

1. Notez le mois de début en ambre ou en rouge.
2. Ouvrez **Children** ou **Families** et regardez qui a commencé ce mois-là et est parti depuis, ainsi que le motif noté.
3. Si le même motif revient (tarifs, heures, changement de groupe), parlez-en à votre équipe. Un court sondage par **Engagement › NPS** peut vous en apprendre plus.

## Comment les chiffres sont calculés

- Un enfant compte dans le mois de sa date d'inscription.
- Un enfant compte comme « encore inscrit » tant qu'aucune date de départ n'est inscrite à son dossier.
- Les enfants dont le dossier a été supprimé sont exclus.
- Le rapport couvre les 12 derniers mois, y compris le mois en cours, et il est recalculé chaque fois que vous l'ouvrez.

## Qui peut faire quoi

| Rôle | Voit |
|---|---|
| Administrateur d'agence | Toute l'agence. |
| Directeur de centre | Les chiffres de l'agence (le rapport n'est pas séparé par centre). |
| Administrateur de plateforme | L'agence choisie dans le sélecteur d'agence. |

Le rapport ne montre que des nombres : aucun nom d'enfant ou de famille.

## Questions fréquentes et problèmes

??? Chaque mois affiche un tiret. | Aucun enfant de l'agence n'a de date d'inscription dans les 12 derniers mois. Vérifiez que les dates d'inscription sont remplies dans les dossiers.
??? Une famille est partie, mais le rapport compte encore son enfant. | La date de départ de l'enfant n'a pas été inscrite. Faites le départ dans son dossier; le rapport se met à jour à la prochaine ouverture.
??? Puis-je exporter ce rapport? | Pas à partir de cet écran. Utilisez **Administration › Reports** pour bâtir un rapport avec les dates d'inscription et de départ.
??? Le rapport envoie-t-il quelque chose aux familles? | Non.

## Écrans connexes

[[open: #forecast | Prévision des inscriptions]] [[open: #ai-churn | Risque de départ]]

Voir aussi [[enrolment-forecast]] et [[churn-risk]].
