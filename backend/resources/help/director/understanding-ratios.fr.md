---
title: Comprendre les ratios
category: Daily Operations
order: 1
---

# Comprendre les ratios éducateur-enfants

La Loi de 2014 sur la garde d’enfants et la petite enfance de l’Ontario (LGEPE, CCEYA en anglais) fixe les ratios maximaux éducateur-enfants pour chaque groupe d’âge. Kiddietrac les calcule en temps réel et signale les salles qui ne sont pas conformes.

## Ratios standards de la CCEYA en Ontario

| Groupe d’âge | Ratio (éducateur : enfants) |
|---|---|
| Nourrisson (0-18 mois) | 1 : 3 |
| Bambin (18-30 mois) | 1 : 5 |
| Préscolaire (30-60 mois) | 1 : 8 |
| Maternelle (44-67 mois) | 1 : 13 |
| Âge scolaire | 1 : 15 |

Lorsque vous créez une salle, vous définissez son ratio cible. Kiddietrac s’en sert pour calculer la conformité.

## Comment Kiddietrac calcule les ratios

Pour chaque salle, chaque minute, Kiddietrac vérifie :

1. Combien d’enfants sont actuellement pointés dans cette salle
2. Combien d’éducateurs sont pointés (répartis par salle s’il existe des affectations de quarts, sinon répartis également entre les salles)
3. Éducateurs requis = ceil(enfants_présents / enfants_du_ratio)
4. Si éducateurs_présents >= requis → CONFORME (COMPLIANT)
5. Si éducateurs_présents == requis ET enfants_présents > 0 → JUSTE (TIGHT) (vous êtes au minimum légal : un enfant de plus et vous êtes en infraction)
6. Si éducateurs_présents < requis → INFRACTION (BREACH)

## Les règles de votre province ou État (nouveau, octobre 2026)

KiddieTrac vérifie maintenant les ratios selon les règles de la province ou de l’État de votre agence (Aperçu de l’agence → Modifier l’agence → Pays et conformité). Lorsque ces règles sont connues en détail, elles remplacent le ratio propre à la salle :

- **L’enfant le plus jeune présent détermine le ratio.** En Ohio, un nourrisson de moins de 12 mois dans un groupe mixte fait passer tout le groupe à 1:5.
- **Taille du groupe.** Chaque tranche d’âge a une taille de groupe maximale; une salle qui la dépasse affiche **OVER GROUP SIZE** même s’il y a assez d’éducateurs.
- **Garde en milieu familial.** Les foyers de type A et de type B de l’Ohio utilisent la règle des foyers : 7 enfants par fournisseur de soins, pas plus de 3 de moins de 2 ans chacun, et un plafond total (7 pour le type B, 14 pour le type A). Vos propres enfants de moins de 6 ans comptent : pointez-les aussi.
- **Un deuxième adulte sur place** dès que 7 enfants ou plus sont présents (centres de l’Ohio).
- **Heure de la sieste.** La fiche indique combien d’éducateurs peuvent assurer la surveillance pendant la sieste (Ohio : le double du ratio pendant 2 heures au plus, jamais avec des nourrissons). Cette information est affichée à titre de planification; elle ne transforme jamais une infraction en situation conforme.

La fiche de ratio indique quelle règle a été utilisée, par exemple *« Enfant le plus jeune : moins de 12 mois → 1:5 (Ohio) »*, et énumère tout ce qu’elle a repéré d’autre.

**Centres agréés de l’Ontario** (octobre 2026) : définissez le type de permis du lieu à *Centre de garde d’enfants agréé* sous **Ratios par salle → Paramètres des ratios**, et KiddieTrac applique le Règl. de l’Ont. 137/15 — annexe 1 : ratios et tailles de groupe, périodes à ratio réduit, comptage des superviseurs, règle des deux adultes, âges mixtes, groupes d’âge familiaux et un EPEI inscrit (RECE) dans chaque groupe. Voir *Règles de ratio de l’Ontario (centres agréés)*.

La garde en milieu familial en Ontario, les lieux sans type de permis défini et les autres provinces conservent le ratio propre à chaque salle. Rien ne change dans la façon dont ces salles sont mesurées.

## Que faire quand une salle est en INFRACTION (BREACH)

- **Un éducateur a oublié de pointer son arrivée** — demandez-lui de pointer (sur son téléphone ou sur la tablette)
- **Un éducateur est en pause** — assurez-vous qu’un autre éducateur le remplace
- **Vous avez plus d’enfants que prévu** — déplacez temporairement un enfant vers une autre salle
- **Vous manquez chroniquement de personnel** — embauchez un autre éducateur

Une infraction de plus de 15 minutes constitue une violation de la CCEYA. Documentez toute infraction que vous n’avez pas pu éviter.

## Pourquoi JUSTE (TIGHT) est important

JUSTE signifie que vous êtes au minimum légal. S’il arrive quoi que ce soit — un enfant arrive, un éducateur fait une pause toilettes — vous êtes en infraction. Considérez JUSTE comme un avertissement pour prévoir du renfort.

## Quarts et pointage

Si vous avez établi à l’avance des quarts pour les éducateurs, Kiddietrac se sert des quarts prévus pour déterminer qui se trouve dans quelle salle. Si aucun quart n’est défini, il compte les personnes pointées au centre et les répartit également entre les salles du centre.

Une agence peut plutôt choisir de compter chaque éducateur pointé dans la salle à laquelle il est affecté (Affectations de salle); les éducateurs affectés à plusieurs salles, ou à aucune, sont tout de même répartis — arrondis à l’inférieur, de sorte que personne n’est compté deux fois. Demandez au soutien de KiddieTrac d’activer cette option pour votre agence.

Pour la plupart des petits centres, il suffit de pointer l’arrivée et le départ. Les grands centres bénéficient de la planification des quarts.
