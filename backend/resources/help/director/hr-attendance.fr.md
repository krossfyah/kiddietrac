---
title: RH : rapport d'absences, retards, score d'absence et chevauchements de congés
---
# RH : rapport d'absences, retards, score d'absence et chevauchements de congés

**Ressources humaines > Absences et retards** montre qui a été absent et qui est arrivé en retard, pour les dates de votre choix. L'écran a deux vues : **Congés** (congés approuvés, absences courtes répétées et score d'absence) et **Retards** (premiers pointages comparés au quart prévu à l'horaire). Utilisez-le avant une rencontre de suivi, à la fin du mois, ou quand vous remarquez une tendance. Il fait partie du module complémentaire KiddieTrac RH.

[[open: #hr-absence | Ouvrir Absences et retards]]

## Produire le rapport d'absences

1. Ouvrez **Ressources humaines > Absences et retards**. La vue **Congés** s'ouvre.
2. Réglez les dates **De** et **À**.
3. Choisissez le regroupement : **Par personne**, **Par type de congé**, **Par mois** ou **Par centre**.
4. Précisez avec **Tous les centres** (si vous en avez plusieurs) et **Tous les types de congé**.
5. Lisez les totaux en haut : **Jours de congé**, **Heures de congé**, **Personnes**, **Absences courtes répétées** et **Au-dessus du score d'absence**.
6. Appuyez sur **Exporter vers Excel** pour télécharger ce que vous voyez.

Le tableau donne les **Jours de congé**, **Heures de congé**, **Absences**, **Absences courtes**, le **Score d'absence** et le détail par type de congé. Appuyez sur **⋮ > Voir le profil RH** pour voir les soldes et les congés récents d'une personne.

## Repérer les absences courtes répétées

1. Regroupez le rapport **Par personne**.
2. Cochez **Seulement les absences courtes répétées**.
3. Les personnes qui atteignent la règle de votre agence (par exemple 3 absences ou plus de 2 jours ou moins en 6 mois) s'affichent avec une pastille ambre **Absences courtes répétées**.

La ligne sous les totaux rappelle la règle. Les administrateurs de l'agence la changent dans **Ressources humaines > Paramètres RH** (**Absences**, **Of this many days or less**, **Within (months)**).

## Utiliser le score d'absence

1. Regroupez le rapport **Par personne**.
2. Cochez **Seulement au-dessus du score d'absence**.
3. Lisez la colonne **Score d'absence**. Sous chaque score, vous voyez le calcul, par exemple « 3 × 3 × 3 ».

Le score est absences × absences × jours d'absence sur la période (la formule de Bradford) : plusieurs absences courtes pèsent plus qu'une longue. Trois absences d'un jour donnent 27; une absence de trois jours donne 3. Des absences séparées seulement par une fin de semaine ou un jour de fermeture comptent pour une. Toute personne au seuil ou au-dessus (50, sauf si votre agence le change) reçoit une pastille **Above 50**.

> 💡 Le score sert à ouvrir une conversation, jamais à prendre une décision automatique. Regardez d'abord les raisons des absences.

## Vérifier les retards

1. Appuyez sur **Retards** en haut de l'écran.
2. Réglez **De**, **À** et, s'il est affiché, le centre.
3. Cochez **Seulement les personnes en retard** pour masquer celles qui étaient à l'heure.
4. Lisez les totaux : **Pointages comparés**, **En retard**, **Retard au total** et **Personnes en retard**.
5. Sur une ligne, appuyez sur **⋮ > Voir les pointages**. La fenêtre **Pointages par rapport à l'horaire** liste chaque jour : le début du quart, l'heure du pointage et **À l'heure**, « dans le délai de grâce » ou « X min de retard ».
6. Appuyez sur **Exporter vers Excel** pour télécharger la liste.

Seul le premier pointage de chaque quart prévu est comparé. Est en retard quiconque arrive plus tard que le délai de grâce (5 minutes, sauf si votre agence le change) après le début du quart. Le retour d'une pause et les jours sans quart ne sont pas comparés. La colonne **Tendance** fait ressortir des habitudes comme « Surtout les lundis ».

## Régler les règles de présence (administrateurs de l'agence)

1. Ouvrez **Ressources humaines > Paramètres RH**.
2. Descendez jusqu'aux sections **Retards**, **Score d'absence** et **Chevauchements de congés et personnel minimum**.
3. Réglez le **Délai de grâce (minutes)**, **Prévenir les directeurs du centre** et si les **Entrepreneurs** sont comparés.
4. Réglez **Signaler à partir de**, **Sur les derniers (mois)** et cochez les **Types de congé comptés** (aucun coché = congés de maladie).
5. Choisissez **Montrer qui est aussi absent** et ce qui se passe **Sous le personnel minimum** : **Avertir la personne qui approuve** ou **Ne pas permettre de l'approuver**.
6. Inscrivez un **Personnel minimum un jour ouvrable** pour chaque centre, ou laissez **No minimum**.
7. Appuyez sur **Enregistrer les règles de présence**.

## Chevauchements de congés et personnel minimum

Quand quelqu'un demande un congé, son formulaire indique combien d'autres personnes de son centre sont déjà absentes ou demandent ces jours (jamais qui). La personne qui approuve voit qui, dans la fenêtre **Approuver le congé** et dans le courriel d'approbation (« Also away at… »). Avec un minimum réglé, elle voit quels jours tomberaient sous le minimum; en mode **Ne pas permettre de l'approuver**, ces approbations sont refusées. Un congé sans approbation, comme un congé de maladie, n'est jamais refusé, seulement signalé.

## Qui peut faire quoi

- **Administrateurs de l'agence** : tous les centres; changent les règles dans les Paramètres RH.
- **Directeurs de centre** : les personnes de leurs propres centres; règles en lecture seule.
- **Personnel** : ne voit pas cet écran; voit seulement le nombre de chevauchements sur sa propre demande.

## FAQ et quand quelque chose ne va pas

??? Retards indique « No clock-ins to compare in this range. » | Les retards ont besoin de quarts à l'horaire. Un pointage un jour sans quart n'est pas comparé. Vérifiez que l'horaire de ces dates est publié.
??? Le rapport d'absences est vide. | Il ne montre que les congés approuvés. Les demandes en attente et refusées n'y sont pas. Élargissez les dates ou retirez les filtres de centre et de type de congé.
??? Les directions sont-elles avisées des retards? | Seulement si un administrateur a réglé **Prévenir les directeurs du centre** à **Oui, un avis quand quelqu'un est en retard**. Les directions du centre reçoivent alors un avis dans la cloche. Sinon, c'est seulement dans le rapport.
??? Quelqu'un était en retard, mais n'est pas dans la liste. | Seul le premier pointage d'un quart est comparé, et les entrepreneurs sont exclus sauf si votre agence les compare. Un pointage dans le délai de grâce compte comme à l'heure.
??? Puis-je changer le seuil du score? | Les administrateurs de l'agence le peuvent, dans **Ressources humaines > Paramètres RH** sous **Score d'absence**.

## Écrans liés

[[open: #time-off | Demandes de congé]] [[open: #timesheets | Pointages et feuilles de temps]] [[open: #hr-reports | Rapports RH]]

- [[hr]] — personnes et soldes de congés
- [[time-off]] — approuver un congé
- [[hr-reports]] — les rapports de retards et d'absences
