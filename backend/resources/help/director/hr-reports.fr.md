---
title: RH : tableau de bord, rapports, période d'essai et informations manquantes
category: Staff Management
order: 68
roles: agency_admin, platform_admin, centre_director
---
# RH : tableau de bord, rapports, période d'essai et informations manquantes

Fait partie du module RH de KiddieTrac. Les administrateurs d'agence voient toute l'agence; les directeurs voient le personnel de leurs propres centres.

## Le tableau de bord

Ouvrez **RH > Tableau de bord**.

- **Liste de mise en place RH** : cinq étapes avec une barre de progression : ajouter vos types de congé, fixer les droits à congé, compléter le dossier RH de chacun, donner à chacun un gestionnaire qui approuve ses demandes, et faire en sorte que votre personnel se connecte. **Configurer** mène directement à l'étape.
- **Fin de période d'essai** : les personnes dont la période d'essai se termine dans les 30 prochains jours, ou s'est terminée sans être confirmée. Le menu ⋮ permet de **marquer la période d'essai réussie**, de la **prolonger** jusqu'à une nouvelle date ou d'ouvrir le dossier RH.
- **À faire** : le nombre de personnes à qui il manque des informations, et de celles qui ne se sont jamais connectées.

Les administrateurs d'agence peuvent appuyer sur **Paramètres de période d'essai et d'invitation** pour fixer :

- la durée de la période d'essai (90 jours par défaut);
- combien de jours avant la fin le gestionnaire reçoit un rappel (14 jours par défaut);
- après combien de jours une invitation non utilisée est considérée comme expirée (60 jours par défaut).

Le rappel va au gestionnaire inscrit au dossier RH. S'il n'y en a pas, il va au directeur du centre.

## Rapports

Ouvrez **RH > Rapports** et choisissez un rapport.

- **Effectif** : le personnel actuel par centre et par type d'emploi.
- **Arrivées et départs** : qui est arrivé et qui est parti entre deux dates, mois par mois. Le rapport donne le **roulement** (départs ÷ effectif moyen) et la **rétention** (les personnes présentes le premier jour qui le sont encore le dernier).
- **Rétention** : pour chaque année d'arrivée, combien sont encore là et combien sont restés 90 jours, puis un an.
- **Ancienneté** : moyenne et médiane en années, par tranches.
- **Passif de congés** : ce que vous devriez si tout le monde partait aujourd'hui. Il compte les soldes de congé payés au départ, multipliés par le taux de rémunération de chacun. Seules les personnes qui voient la paie peuvent l'ouvrir.
- **Heures supplémentaires** : les demandes par personne, avec les heures payées et les heures mises en banque.
- **Retards** : les pointages comparés au début du quart, par centre et par personne.
- **Horaire et heures travaillées** : les heures prévues comparées aux heures pointées, sur 93 jours au plus.
- **Congés cette année et l'an dernier** : ce que chacun a acquis et pris, cette année de congé et la précédente.
- **Absences de l'équipe** : qui est absent, ou a demandé à l'être, entre deux dates, et combien de personnes sont absentes chaque jour.
- **Informations manquantes** : voir la section plus bas.
- **Pas encore connectés** : le personnel qui ne s'est jamais connecté, avec la date de l'invitation. Une invitation plus ancienne que votre réglage est marquée **Expirée**. Les administrateurs d'agence peuvent choisir **Renvoyer l'invitation** dans le menu ⋮. Un nouveau courriel de bienvenue part alors avec un nouveau mot de passe temporaire. L'expiration marque seulement l'invitation dans cette liste : elle n'empêche personne de se connecter.
- **Période d'essai** : toutes les personnes en période d'essai. Cochez **Inclure les réussies et les plus anciennes** pour voir tout le monde.
- **Tout ce que nous détenons** : choisissez une personne pour voir chaque type de dossier que KiddieTrac détient à son sujet, par exemple pour une demande d'accès à l'information. Les administrateurs peuvent aussi télécharger toutes ses données.

**Imprimer** imprime le rapport. Les boutons d'exportation sous chaque tableau le téléchargent en CSV, Excel ou PDF.

## Informations manquantes

La vérification repère l'absence de date d'entrée, de gestionnaire, de contact d'urgence, de titre de poste ou de type d'emploi. Les administrateurs d'agence n'ont pas de gestionnaire au-dessus d'eux : aucun ne leur est demandé.

Les personnes qui voient la paie ont aussi des vérifications de paie, surlignées en rouge :

- aucun taux de rémunération;
- aucune coordonnée de dépôt de paie;
- aucun NAS ou SSN (administrateurs d'agence seulement);
- une paie sous le salaire minimum.

La vérification du salaire minimum se fait selon la province ou l'État du centre principal. Elle porte sur la paie horaire, et sur les salaires quand le dossier RH indique des heures par semaine. Les contractuels ne sont pas vérifiés.

Le tableau des salaires minimums s'affiche sous le rapport, avec la date de la dernière vérification. Il couvre les provinces et les États où des agences KiddieTrac sont présentes aujourd'hui, et applique chaque nouveau taux à partir de sa date d'entrée en vigueur. Les minimums municipaux peuvent être plus élevés : vérifiez les vôtres.

## Paiement des congés et types de congé

Dans **RH > Types de congé**, le réglage **Payé au départ** décide quels soldes comptent dans la trousse de départ et le rapport de passif de congés. Par défaut, ce sont votre type vacances et la banque d'heures supplémentaires. Les jours de maladie et personnels ne sont pas comptés, sauf si vous choisissez **Oui** pour ce type.
