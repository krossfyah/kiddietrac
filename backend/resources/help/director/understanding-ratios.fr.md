---
title: Comprendre les ratios
---
# Comprendre les ratios éducateurs-enfants

L’écran **Ratios par salle** compare les enfants pointés dans chaque salle avec le personnel
présent en ce moment, selon vos règles de permis, et signale toute salle dont le ratio n’est
pas respecté. Les administrateurs de l’agence l’ouvrent dans le menu latéral :
**Administration** → **Ratios par salle**.

[[open: #room-ratios | Ouvrir les ratios par salle]]

## Lire l’écran

1. Regardez les quatre tuiles du haut : **Ratio non respecté**, **Vérifier le personnel**, **Salles suivies** et **Ratio respecté**.
2. En dessous, une ligne 🔔 indique si les alertes de dépassement sont activées et qui est avisé.
3. Chaque centre est une carte, avec une ligne par salle : **Salle** (avec son groupe d’âge), **Enfants** (et la taille maximale du groupe, s’il y en a une), **Personnel** (présent / requis), **Pourquoi / vérifications** et **Statut**.
4. Lisez la pastille **Statut** :
   - **✓ Ratio respecté** — assez de personnel pour les enfants présents.
   - **At the limit** — exactement le minimum. Un enfant de plus, ou un éducateur qui s’absente, et le ratio n’est plus respecté.
   - **Vérifier le personnel** — il y a assez de personnel, mais une autre règle n’est pas respectée, par exemple un deuxième adulte sur place ou un éducateur qualifié dans le groupe. **Pourquoi / vérifications** précise laquelle.
   - **⚠ Ratio non respecté** — pas assez de personnel pour les enfants présents.
   - **⚠ Taille de groupe dépassée** — plus d’enfants que le groupe de la salle peut en accueillir, même avec assez de personnel.
5. **Pourquoi / vérifications** indique la règle utilisée, par exemple *« Youngest child: Under 12 months → 1:5 (Ohio) »*, et liste tout autre problème trouvé.

L’écran s’actualise de lui-même pendant qu’il est ouvert.

## Modifier les alertes de dépassement

1. Sur la ligne 🔔, appuyez sur **Modifier**. La fenêtre **🔔 Alertes de dépassement de ratio** s’ouvre.
2. Laissez **Alerter quand une salle dépasse le ratio** coché (activé par défaut). Les salles sont vérifiées toutes les 15 minutes de 6 h à 19 h : une alerte par dépassement, et une note quand le ratio de la salle est de nouveau respecté.
3. Sous **Qui est avisé**, cochez **Educators in the room**, **Directeurs de centre** ou **Administrateurs de l’agence**. Les éducateurs et les directeurs sont cochés par défaut.
4. Appuyez sur **Enregistrer**. Le message **Alertes de ratio enregistrées** s’affiche et la ligne 🔔 se met à jour.

## Comment le personnel est compté

Si vous planifiez les quarts à l’avance, KiddieTrac utilise les quarts pour savoir qui est dans
quelle salle. Sans quarts, il compte les personnes pointées au centre et les répartit entre
les salles du centre. Une agence peut plutôt choisir de compter chaque éducateur pointé dans
la salle où il est affecté (Affectations de salle); les personnes affectées à plusieurs salles,
ou à aucune, sont toujours réparties — arrondi à l’unité inférieure, pour que personne ne
soit compté deux fois. Demandez au soutien KiddieTrac d’activer cette option pour votre agence.

## Les règles de votre province ou de votre État

KiddieTrac vérifie les ratios selon les règles de la province ou de l’État de votre agence
(Vue d’ensemble de l’agence → Modifier l’agence → Pays et conformité). Quand ces règles sont
connues en détail, elles remplacent le ratio propre à la salle :

- **Le plus jeune enfant présent fixe le ratio.** En Ohio, un poupon de moins de 12 mois dans un groupe mixte fait passer tout le groupe à 1:5.
- **Taille du groupe.** Chaque tranche d’âge a une taille de groupe maximale; une salle qui la dépasse affiche **⚠ Taille de groupe dépassée**.
- **Garde en milieu familial.** Les milieux de type A et de type B de l’Ohio suivent la règle des milieux familiaux : 7 enfants par responsable, au plus 3 de moins de 2 ans chacun, et un maximum total (7 pour le type B, 14 pour le type A). Vos propres enfants de moins de 6 ans comptent — pointez-les aussi.
- **Un deuxième adulte sur place** dès que 7 enfants ou plus sont présents (centres de l’Ohio).
- **Sieste.** La carte peut indiquer combien d’éducateurs peuvent surveiller pendant la sieste. C’est pour la planification : cela ne transforme jamais un dépassement en conformité.

Les ratios standard de l’Ontario pour les centres agréés (Règl. de l’Ont. 137/15, annexe 1) :

| Groupe d’âge | Éducateur : enfants |
|---|---|
| Poupon | 1 : 3 |
| Bambin | 1 : 5 |
| Préscolaire | 1 : 8 |
| Maternelle | 1 : 13 |
| Âge scolaire primaire / moyen | 1 : 15 |

La garde en milieu familial de l’Ontario, les emplacements sans type de permis et les autres
provinces utilisent le ratio propre à chaque salle.

## Configurer les règles des centres agréés de l’Ontario

Les agences de l’Ontario voient **⚙ Paramètres des ratios** sur la carte de chaque centre.

1. Appuyez sur **⚙ Paramètres des ratios**. La fenêtre **Ratio settings — (centre)** s’ouvre.
2. Sous **Cet emplacement est un**, choisissez **Centre de garde agréé — règles du Règl. de l’Ont. 137/15** (ou une option de garde en milieu familial, ou **Not set — use each room's own ratio**).
3. Réglez **Début du programme** et **Fin du programme**.
4. Laissez **Use reduced ratios at arrival, departure and rest (s.8(4))** coché si vous les utilisez, et réglez **Arrival window (min)**, **Departure window (min)**, **Début de la période de repos** et **Rest period (min, max 120)**.
5. Saisissez les **Outdoor play times (always full ratio)**, par exemple 10:00-11:00, 15:30-16:30.
6. Sous **Groupe d’âge agréé de chaque salle**, choisissez le groupe de chaque salle.
7. Appuyez sur **Enregistrer**. La carte affiche **Règles des centres de l’Ontario** et une ligne avec les plages. Voir [Règles de ratio de l’Ontario (centres agréés)](ontario-ratio-rules).

## Que faire quand le ratio d’une salle n’est pas respecté

- **Un éducateur a oublié de pointer** — demandez-lui de pointer sur son téléphone ou la tablette.
- **Un éducateur est en pause** — assurez-vous qu’un autre éducateur le remplace.
- **Plus d’enfants que prévu** — déplacez un enfant vers une autre salle pour l’instant.
- **Manque de personnel à répétition** — prévoyez plus de personnel ou appelez un remplaçant.

Considérez **At the limit** comme un avertissement de faire venir du renfort. Documentez tout dépassement que vous n’avez pas pu éviter.

## Qui peut faire quoi

- Les **administrateurs de l’agence** voient les salles de tous les centres, et modifient les alertes de dépassement et les paramètres de ratio de l’Ontario. Les ratios par salle sont dans leur menu **Administration**.
- Les **directeurs de centre** et les **éducateurs** n’ont pas cet écran dans leur menu; ils peuvent recevoir les alertes de dépassement quand un administrateur les coche sous **Qui est avisé**.
- Les parents ne voient jamais les ratios.

## FAQ et dépannage

??? Une salle affiche un ratio non respecté, mais l’éducateur est là. | Il n’a probablement pas pointé, ou il est pointé sur un quart dans une autre salle. Demandez-lui de pointer, ou corrigez son quart.
??? Le message « No rooms tracked. » s’affiche. | L’agence n’a pas encore de salles actives. Ajoutez d’abord des salles à vos centres.
??? Je ne vois pas ⚙ Paramètres des ratios. | Ce bouton s’affiche seulement pour les agences de l’Ontario. Ailleurs, c’est le ratio propre à chaque salle, ou les règles de votre État, qui s’applique.
??? L’enregistrement des paramètres indique « Outdoor play times look like 10:00-11:00, separated by commas. » | Saisissez chaque plage sous la forme début-fin en heures de 24 h, avec une virgule entre les plages.
??? Qui reçoit une alerte de dépassement ? | Seulement les groupes cochés dans 🔔 Alertes de dépassement de ratio — par défaut, les éducateurs de la salle et les directeurs du centre — une fois par dépassement, puis de nouveau quand le ratio est respecté. Pour un centre sans directeur, l’alerte des directeurs va aux administrateurs de l’agence.

## Liens utiles

[[open: #room-ratios | Ratios par salle]]
[[open: #educator-rooms | Gestion des salles]]

- [Règles de ratio de l’Ontario (centres agréés)](ontario-ratio-rules)
- [Affectations de salle et rotations des salles](room-assignments)
- [Remplaçants](substitutes)
- [Horodateur du personnel](time-clock)
