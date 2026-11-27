---
title: Rapports OCCMS — registre de présence (ROA) des subventions et sommaire CWELCC
category: Compliance
order: 47
---
# Rapports OCCMS

Chaque mois, les centres ontariens sous contrat pour la subvention des frais de garde saisissent dans OCCMS un **registre de présence (ROA)** : un code pour chaque jour et chaque enfant bénéficiant d'une subvention, plus la capacité autorisée, la capacité de fonctionnement, les inscriptions et les places libres par groupe d'âge. La date limite de la région de York est le **8 du mois suivant**. OCCMS n'offre aucune connexion à laquelle KiddieTrac puisse envoyer des données; KiddieTrac prépare donc le ROA pour que vous le copiiez, et conserve une trace de qui l'a vérifié et de quand il a été saisi.

Ouvrez **Conformité → Rapports OCCMS** (agences de l'Ontario seulement; les directeurs voient leurs propres centres, les administrateurs d'agence voient tous les centres). L'écran compte les onglets **Registre de présence**, **Monthly attendance & fees** (présences et frais du mois), **Sommaire CWELCC** et, pour les administrateurs d'agence, **Paramètres**.

[[open: #subsidy-roa | Ouvrir Rapports OCCMS]]

## Vérifier les jours

1. Choisissez le centre et le mois en haut.
2. Dans **Registre de présence**, chaque enfant ayant une subvention des frais ce mois-là (de **Conformité → Subventions → Subvention provinciale**) a une ligne avec un code par jour.
3. Touchez un jour pour changer son code. Un jour modifié est souligné en orange, et chaque changement (ancien code, nouveau code, qui, quand) figure sous **Historique**.
4. Lisez la fin de chaque ligne : jours de présence, absences du mois, absences utilisées cette année civile par rapport à l'allocation, et combien il en reste.
5. Lisez **Requiert de l'attention** : les enfants qui ont utilisé 80 % ou la totalité de leur allocation, et tout enfant absent **5 jours de suite sans préavis**. Avisez l'agente ou l'agent de la famille (CSR).

Les codes sont calculés pour vous :

- **P** Présent : l'enfant a été inscrit à l'arrivée.
- **S** Malade, **V** Vacances, **A** Absent : une absence signalée par la famille ou le personnel (malade → S, vacances → V, autre → A).
- **A** encadré de rouge : absent un jour prévu, sans rien de signalé.
- **H** Jour férié : une fermeture de type *Holiday*. **N** Jour non payé : toute autre fermeture (journée pédagogique, urgence, rénovation).
- **W** Retrait : à la date de retrait de l'enfant.
- **O** Autre : seulement après vérification auprès de votre municipalité.
- Vide : pas de garde ce jour-là (fin de semaine, jour où l'enfant n'est pas prévu). Un point gris signifie aucune subvention ce jour-là.

## Indiquer la capacité

1. Faites défiler jusqu'au tableau de capacité dans **Registre de présence**. La capacité autorisée est le total des capacités de vos salles pour chaque groupe d'âge.
2. Saisissez la capacité **Operating** (de fonctionnement) de chaque groupe d'âge : les places que vous pouvez combler avec le personnel dont vous disposez, jamais plus que la capacité autorisée.
3. Appuyez sur **Enregistrer la capacité d'exploitation**. Elle est reportée au mois suivant. Les inscriptions et les places libres sont calculées pour le dernier jour du mois.

## Examiner, approuver et saisir dans OCCMS

1. Vérifiez les jours et la capacité.
2. Appuyez sur **Marquer comme examiné**. Le bouton s'active le 1er du mois suivant.
3. Une **autre personne** appuie sur **Approuver**. La grille est alors figée telle qu'approuvée : les changements de présence ultérieurs ne la modifient pas (vous verrez combien de jours ont changé depuis).
4. Saisissez-le dans OCCMS, puis appuyez sur **Consigner la soumission à OCCMS**, indiquez la **Date de saisie** (et une référence si vous en avez une) et appuyez sur **Record**. Le mois est verrouillé.

Modifier un jour après l'examen ramène le mois à l'état de brouillon. **Rouvrir** (avec un motif) déverrouille un mois approuvé; **Modifier** (avec un motif) déverrouille un mois soumis pour le corriger et saisir la correction dans OCCMS.

**Registre de présence en CSV** et **Registre de présence imprimable (PDF)** vous donnent la grille disposée comme le ROA d'OCCMS, avec le tableau de capacité et les noms des signataires.

## Feuille mensuelle des présences et des frais

1. Ouvrez l'onglet **Monthly attendance & fees**.
2. Lisez, pour chaque enfant inscrit, les heures de garde de chaque jour (de l'arrivée au départ), les heures du mois, **Parent fees**, **Gov. fees**, **Total fees** et **Space type**.
3. Appuyez sur **Sheet as Excel** ou **Printable sheet (PDF)**. La version imprimée ajoute une colonne pour la signature du parent.

La feuille est en lecture seule : corrigez les arrivées, absences, fermetures ou factures à la source. Elle se trouve aussi sous **Rapports → Monthly attendance & fees**.

## Sommaire CWELCC et frais de base

1. Ouvrez l'onglet **Sommaire CWELCC**.
2. Lisez les enfants inscrits dans les salles du centre par groupe d'âge, répartis entre admissibles au CWELCC et non admissibles, à côté de la capacité autorisée et de fonctionnement.
3. Vérifiez **Départs du CWELCC dans les deux prochains mois** (les familles doivent avoir 2 mois de préavis) et **Vérifiez avant de déclarer** (familles dont l'inscription au CWELCC ne correspond pas à l'âge de l'enfant).
4. Sous **CWELCC base fees**, saisissez le tarif quotidien au 31 décembre 2024 de chaque groupe d'âge et appuyez sur **Save December 31, 2024 fees**. Les frais de base sont le plus bas entre le plafond quotidien et ce tarif, jamais sous le plancher.
5. Revoyez les frais de chaque enfant CWELCC en montant quotidien (**Within** ou **Over by**), les autres frais facturés aux familles CWELCC et les jours de fermeture utilisés cette année.
6. Appuyez sur **Sommaire CWELCC en CSV** pour le télécharger.

Un enfant est admissible avant 6 ans; celui qui a 6 ans entre le 1er janvier et le 30 juin le reste jusqu'au 30 juin (groupe préscolaire, maternelle ou familial), sinon jusqu'à la fin du mois de ses 6 ans; dans une salle d'âge scolaire, jusqu'à la fin du mois de son anniversaire.

## Paramètres et rappels (administrateurs d'agence)

1. Ouvrez l'onglet **Paramètres**.
2. Choisissez votre **Municipality (CMSM / DSSAB)** : région de York (48 jours d'absence par année, 3 de moins pour chaque mois après janvier où la subvention commence), ville de Toronto (35 par année, au plus 20 de suite), région de Peel (saisissez l'allocation du Service Provider Handbook de Peel) ou Autre (règles de York jusqu'à ce que vous fixiez les vôtres).
3. Changez au besoin un nombre, comme **Jours d'absence par année**, **Maximum de jours d'absence consécutifs** ou le **Jour d'échéance du mois**, ou appuyez sur **Utiliser les valeurs par défaut de la municipalité**.
4. Cochez ou décochez le rappel et appuyez sur **Enregistrer**.

Le 1er, les directeurs et les administrateurs reçoivent une notification dans la cloche et un courriel indiquant que le ROA du mois précédent est prêt à examiner, puis un autre deux jours avant la date d'échéance (le 6 pour York) s'il n'est pas encore approuvé.

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Administrateur d'agence | Tous les centres; tous les onglets, y compris **Paramètres**. |
| Directeur de centre | Ses propres centres; examine, approuve et consigne les soumissions. Pas d'onglet **Paramètres**. |
| Administrateur de plateforme | Comme un administrateur d'agence, dans l'agence où il travaille. |

L'écran n'apparaît que dans les agences de l'Ontario. Les éducatrices et les parents ne le voient pas.

## FAQ et problèmes courants

??? Marquer comme examiné est grisé. | Le mois n'est pas terminé. Le bouton s'active le 1er du mois suivant.
??? Approuver est grisé : « A different person must sign off ». | Vous avez examiné le mois. Une deuxième personne (directeur ou administrateur) l'approuve.
??? Un enfant manque au ROA. | Seuls les enfants ayant une subvention provinciale des frais ce mois-là sont listés. Ajoutez-la sous **Conformité → Subventions → Subvention provinciale**.
??? L'allocation d'absences indique « not set ». | Un administrateur d'agence la saisit dans l'onglet **Paramètres**.
??? Un jour a changé après mon approbation. | La grille approuvée reste telle quelle et indique combien de jours ont changé depuis. Utilisez **Rouvrir** pour la corriger.

## Écrans liés

[[open: #cwelcc | Subventions]] [[open: #admin-families | Familles]]

Voir aussi : [Subventions — CWELCC et subvention provinciale des frais de garde](subsidies)
