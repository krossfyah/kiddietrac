---
title: Feuilles de temps et correction des heures
---
# Feuilles de temps et correction des heures

L'écran **Feuilles de temps** liste chaque quart travaillé dans vos centres pendant une période : la date, la personne, le pointage d'arrivée, le pointage de départ, la pause et les heures, avec le total d'heures et le nombre d'employés en haut. Les directions et les administrateurs de l'agence s'en servent pour vérifier les heures avant la paie et pour corriger un quart mal pointé ou pas pointé du tout. La **Paie** lit ces mêmes pointages : une heure erronée ici devient un montant erroné sur le talon de paie.

[[open: #timesheets | Ouvrir les feuilles de temps]]

## Choisir les heures à afficher

1. Ouvrez **Ressources humaines > Pointages et feuilles de temps**. L'onglet **Feuilles de temps** s'ouvre en premier.
2. Choisissez un centre dans la liste, ou laissez **Tous les centres** (tous ceux que vous voyez).
3. Inscrivez le premier et le dernier jour dans les deux cases de date. L'écran s'ouvre sur les 30 derniers jours, et chaque changement s'applique tout de suite.
4. Tapez un nom ou une date dans **Search staff or date…** pour réduire la liste.
5. Appuyez sur 🔄 (**Recharger**) pour relire la période, ou sur ⬇️ (**Télécharger le CSV**) pour l'enregistrer pour votre fournisseur de paie.

> 💡 Vous avez rempli les dates à l'envers? L'écran les inverse pour vous.

## Lire une ligne

- **Un quart ouvert** n'a pas de pointage de départ et affiche **0h**. Personne n'a pointé la sortie. Il n'est pas compté dans le total : faites le suivi et fermez-le (voir plus bas).
- **No hours logged** désigne une personne prévue à votre centre qui n'a rien pointé pendant la période.
- **Payé**, sous les heures, n'apparaît que si votre agence a des règles de feuilles de temps (arrondi, calcul selon le quart, pause non payée). La ligne du haut indique alors les heures payées après les règles : ce sont celles que la paie utilise.
- Un 🔒 à la place des boutons veut dire que le quart fait partie d'une feuille de temps approuvée et verrouillée pour la paie.

## Corriger les heures d'un quart

1. Trouvez le quart et appuyez sur ✏️ (**Modifier les heures**) au bout de la ligne. Sur un téléphone, les boutons sont au bas de la carte.
2. Changez **Pointer l'arrivée** ou **Pointer le départ**. Les heures suivent l'horloge du centre, pas celle de votre appareil; la fenêtre indique le fuseau horaire.
3. Pour fermer un quart sans pointage de sortie, remplissez **Pointer le départ**. Laissez-le vide pour garder le quart ouvert.
4. Expliquez pourquoi dans **Reason (optional)**. Le motif est inscrit sur le quart, pour que la prochaine personne comprenne le changement.
5. Appuyez sur **Enregistrer les modifications**. Un message **Heures corrigées** s'affiche et la ligne se met à jour.

L'écran refuse le changement, et dit pourquoi, si le départ n'est pas après l'arrivée ou si le quart dépasse 24 heures.

## Ajouter un quart jamais pointé

1. Sur la ligne de la personne (ou sa ligne **No hours logged**), appuyez sur ➕ (**Ajouter un quart**).
2. Choisissez le **Centre** où le quart est classé. C'est lui qui décide quelle paie le règle : vérifiez-le pour quelqu'un qui travaille à plusieurs endroits.
3. Inscrivez **Pointer l'arrivée** et **Pointer le départ**.
4. Écrivez le **Motif**. Il est obligatoire pour un quart saisi à la main.
5. Appuyez sur **Ajouter un quart**. Un message **Quart ajouté** s'affiche.

Un quart qui chevauche un quart existant de la personne est refusé, car les heures compteraient deux fois.

## Supprimer un quart qui n'a pas eu lieu

1. Appuyez sur 🗑️ (**Supprimer le quart**) sur la ligne : un double pointage, ou un pointage sous le mauvais nom.
2. Expliquez pourquoi le quart est supprimé.
3. Appuyez sur **Supprimer le quart**. Un message **Quart supprimé** s'affiche.

> ⚠️ Une suppression ne peut pas être annulée. Le journal d'audit garde tout le quart (heures et notes) et le nom de la personne qui l'a supprimé.

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Administrateur de l'agence | Voir et corriger les feuilles de temps de tous les centres. |
| Direction de centre | Voir et corriger les quarts travaillés dans ses propres centres (le centre du pointage, pas le centre d'attache de la personne). |
| Éducatrice, éducateur, visiteur à domicile | N'ont pas accès à cet écran. Avec le module RH, ils demandent une correction dans **Mes RH > Corriger mon pointage**. |

Chaque correction, quart saisi à la main et suppression laisse une trace dans **Administration > Journal d'audit**.

## Questions fréquentes et problèmes

??? Il y a un 🔒 à la place des boutons de modification. | Le quart fait partie d'une période approuvée et verrouillée dans **Pointages et feuilles de temps > Approuver et verrouiller**. Un administrateur de l'agence doit d'abord la déverrouiller, en donnant un motif.
??? La liste affiche « No timesheets in this date range. » | Personne n'a pointé à ce centre pendant ces dates. Vérifiez le centre et les deux dates, ou choisissez **Tous les centres**.
??? Quelqu'un a oublié de pointer son arrivée. Où corriger? | Ici : appuyez sur ➕ **Ajouter un quart** sur sa ligne, avec un motif.
??? J'obtiens « The clock-out has to be after the clock-in. » | Le départ est avant l'arrivée. Pour un quart qui dépasse minuit, mettez la date du lendemain au départ.
??? Le personnel peut-il modifier ses propres heures? | Non. Son horloge ne fait que pointer l'arrivée et le départ. Un quart resté ouvert la nuit est fermé ici par la direction, ou la personne demande une correction dans Mes RH.
??? La personne est-elle avertie quand je change ses heures? | Aucun avis n'est envoyé depuis cet écran. Le changement est indiqué sur le quart et inscrit au journal d'audit. (Une correction demandée dans Mes RH l'avertit de la décision.)
??? Pourquoi le chiffre Payé diffère-t-il des heures? | Les règles de feuilles de temps de votre agence (arrondi, calcul à partir du début ou jusqu'à la fin du quart, pause non payée) s'appliquent seulement aux heures payées. Le pointage lui-même ne change jamais.

## Écrans liés

[[open: #timesheets?tab=lock | Approuver et verrouiller]]
[[open: #timesheets?tab=punchfix | Corrections de pointage]]
[[open: #payroll | Paie]]

- [[hr-overtime-timesheets]]
- [[hr-rota-publish]]
- [Paie, heures et talons de paie](payroll-documents)
- [Exportation CSV pour la paie](payroll-csv)
