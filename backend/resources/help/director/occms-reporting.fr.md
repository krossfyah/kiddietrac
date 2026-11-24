---
title: Rapports OCCMS — registre de présence (ROA) des subventions et résumé CWELCC
category: Compliance
order: 47
---
# Rapports OCCMS

Chaque mois, les centres ontariens sous contrat pour la subvention des frais de garde saisissent dans OCCMS un **registre de présence (ROA)** : un code pour chaque jour et chaque enfant bénéficiant d’une subvention, plus la capacité autorisée, la capacité de fonctionnement, les inscriptions et les places libres par groupe d’âge. La date limite de la région de York est le **8 du mois suivant**. OCCMS n’offre aucune connexion à laquelle KiddieTrac puisse envoyer des données; KiddieTrac prépare donc le ROA pour que vous le copiiez, et conserve une trace de qui l’a vérifié et de quand il a été saisi. Ouvrez **Conformité → Rapports OCCMS** (agences de l’Ontario seulement; les directeurs voient leurs propres centres, les administrateurs d’agence voient tous les centres).

## Les jours

Choisissez le centre et le mois. Chaque enfant bénéficiant d’une subvention ce mois-là (d’après **Subventions → Subvention provinciale**) a une ligne, avec un code par jour, déterminé à partir de :

- **P** Présent : l’enfant a été pointé à l’arrivée.
- **S** Malade, **V** Vacances, **A** Absent : une absence signalée par la famille ou le personnel (maladie → S, vacances → V, tout autre motif → A).
- **A** avec contour rouge : absent un jour prévu sans qu’aucune absence n’ait été signalée.
- **H** Jour férié : une fermeture de type *Holiday*. **N** Jour non payé : toute autre fermeture (journée pédagogique, urgence, rénovation).
- **W** Retrait : à la date de retrait de l’enfant.
- **O** Autre : seulement après vérification auprès de votre municipalité.
- Vide : aucun service ce jour-là (fin de semaine, jour où l’enfant n’est pas prévu). Un point gris signifie aucune subvention ce jour-là.

Touchez un jour pour changer son code. Un jour modifié est souligné en orange, et chaque changement (ancien code, nouveau code, qui, quand) est consigné sous **Historique**.

À la fin de chaque ligne : jours de présence, absences du mois, absences utilisées durant l’année civile par rapport à l’allocation, et combien il en reste. **Requiert de l’attention** liste les enfants qui ont utilisé 80 % ou la totalité de leur allocation, ainsi que tout enfant absent **5 jours de suite sans préavis** — avisez le conseiller de la famille (CSR).

## Capacité et places libres

La capacité autorisée est le total des capacités de vos salles pour chaque groupe d’âge. Saisissez la **capacité de fonctionnement** (les places que vous pouvez combler avec le personnel dont vous disposez; jamais plus que la capacité autorisée) et enregistrez. Elle se reporte au mois suivant. Les inscrits et les places libres sont calculés pour le dernier jour du mois.

## Révision, approbation et OCCMS

1. Vérifiez les jours et la capacité.
2. **Marquer comme examiné** (disponible le 1er du mois suivant).
3. Une **autre personne** appuie sur **Sign off**. La grille est alors figée comme signée : les changements de présence ultérieurs ne la modifient pas (vous verrez combien de jours ont changé depuis).
4. Saisissez-la dans OCCMS, puis appuyez sur **Record OCCMS submission** avec la date (et une référence, si vous en avez une). Le mois est verrouillé.

Modifier un jour après la révision remet le mois à l’état de brouillon. **Reopen** (avec un motif) déverrouille un mois approuvé; **Amend** (avec un motif) déverrouille un mois soumis afin que vous puissiez le corriger et saisir la correction dans OCCMS.

**Registre de présence en CSV** et **Registre de présence imprimable (PDF)** vous donnent la grille présentée comme le ROA d’OCCMS, avec le tableau des capacités et les noms des signataires.

## Résumé CWELCC

Le deuxième onglet compte les enfants inscrits dans les salles du centre par groupe d’âge, répartis entre admissibles et non admissibles au CWELCC, à côté de la capacité autorisée et de la capacité de fonctionnement, avec un CSV. Un enfant est admissible avant 6 ans; celui qui a 6 ans entre le 1er janvier et le 30 juin reste admissible jusqu’au 30 juin (préscolaire, maternelle ou groupe d’âge familial), sinon jusqu’à la fin du mois de ses 6 ans; dans une salle d’âge scolaire, jusqu’à la fin de son mois de naissance. Il liste aussi les enfants qui quittent le CWELCC dans les deux prochains mois (les familles doivent recevoir 2 mois de préavis) et les familles dont l’inscription au CWELCC ne correspond pas à l’âge de l’enfant.

## Paramètres et rappels (administrateurs d’agence)

Sous **Paramètres**, choisissez votre municipalité : **York Region** (48 jours d’absence par année, 3 de moins pour chaque mois après janvier où la subvention commence), **City of Toronto** (35 par année, au plus 20 de suite), **Region of Peel** (saisissez l’allocation du Service Provider Handbook de Peel) ou **Autre** (règles de York jusqu’à ce que vous définissiez les vôtres). Vous pouvez modifier n’importe quel nombre ainsi que le jour d’échéance.

Le 1er, les directeurs et administrateurs reçoivent une notification de la cloche et un courriel indiquant que le ROA du mois dernier est prêt à être examiné, et deux jours avant l’échéance (le 6) un autre rappel s’il n’est pas encore approuvé. Désactivez-les sous Paramètres.
