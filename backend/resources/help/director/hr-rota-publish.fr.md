---
title: RH : publier l’horaire, copier des quarts, quarts prédéfinis, budget de main-d’œuvre, corrections de pointage
category: Staff
order: 48
roles: agency_admin, platform_admin, centre_director
---
# RH : publier l’horaire, copier des quarts, quarts prédéfinis, budget de main-d’œuvre, corrections de pointage

Fait partie du module RH de KiddieTrac. Les outils d’horaire sont dans **Ressources humaines > Horaire et quarts**. Les corrections de pointage et la règle de pause sont dans **Ressources humaines > Pointages et feuilles de temps**.

## Préparer l’horaire en brouillon, puis le publier

Un administrateur d’agence l’active dans **Horaire et quarts > Outils de quarts > Étape de publication**. C’est désactivé par défaut, et l’horaire fonctionne alors exactement comme avant.

Une fois activé, les nouveaux quarts, les modifications et les retraits sur l’**Horaire** attendent en brouillon. La grille les marque **Brouillon**, **Modifié · non publié** ou **À retirer**. Le personnel, le pointage, les alertes de ratio et la paie ne voient que les quarts publiés. Chaque quart déjà à l’horaire compte comme publié.

Appuyez sur **Publier** dans la barre jaune quand l’horaire est prêt. Chaque personne reçoit un seul avis pour ses propres quarts. Un brouillon qui ne convient plus (la personne est partie, ou il chevauche maintenant un autre quart) reste en brouillon et est listé. **Supprimer tous les brouillons** jette les brouillons et laisse l’horaire publié tel quel. L’onglet **Plan d’horaire** liste les brouillons et montre les alertes de ratio telles qu’elles seraient une fois publiés.

Le remplissage de nuit et les heures habituelles publient toujours directement.

## Le personnel accepte ses quarts

Avec l’étape de publication activée, un administrateur peut aussi cocher **Le personnel doit accepter chaque quart**. Chaque nouveau quart attend alors dans **Mes RH > Quarts à accepter** de la personne. Si elle appuie sur **Je ne peux pas faire ce quart** et explique pourquoi, la direction du centre est avertie. **Outils de quarts > En attente d’acceptation du personnel** liste chaque quart pas encore accepté.

## Copier des quarts

Appuyez sur **📋 Copier** dans l’Horaire. Vous pouvez copier la semaine dernière dans cette semaine, la semaine d’une personne, ou un jour sur d’autres jours de la semaine. Les jours où le centre est fermé ou non ouvert sont laissés de côté, tout comme les congés approuvés, les indisponibilités, les quarts qui se chevauchent et les heures déjà passées. Chaque élément laissé de côté est listé.

## Quarts prédéfinis

**Outils de quarts > Quarts prédéfinis** contient des quarts nommés comme Tôt 7 h – 15 h, chacun avec une couleur. Choisissez-en un dans le formulaire de quart pour remplir les heures et le rôle. Le quart s’affiche ensuite de cette couleur dans l’Horaire. Archiver un quart prédéfini garde la couleur des quarts déjà créés.

## Budget hebdomadaire de main-d’œuvre

Seules les personnes qui voient la paie voient ceci. Un administrateur fixe un budget hebdomadaire par centre dans **Outils de quarts**. L’Horaire et le Plan d’horaire montrent alors la semaine prévue (quarts publiés et brouillons) × le taux horaire de chaque personne, comparée à ce budget. Le personnel salarié et les personnes sans taux sont comptés mais pas chiffrés.

## Corrections de pointage

Le personnel demande dans **Mes RH > Corriger mon pointage** quand un pointage est erroné ou manquant. Les demandes arrivent dans **Pointages et feuilles de temps > Corrections de pointage**. En approuver une modifie le pointage, le marque comme corrigé, et les heures suivent sur la feuille de temps et dans la paie. Personne n’approuve une correction de ses propres pointages. Un jour d’une feuille de temps approuvée et verrouillée ne peut pas être modifié avant qu’un administrateur d’agence la déverrouille.

## Pause non payée automatique

Dans **Feuilles de temps > Règles**, un administrateur peut retirer automatiquement une pause non payée des longs pointages, par exemple 30 minutes de tout pointage de plus de 6 heures. C’est désactivé par défaut. Une fois activé, la feuille de temps, la paie, les talons de paie et les exportations utilisent les mêmes heures payées. Le pointage lui-même ne change jamais.

## Heures contractuelles

La feuille de temps a une colonne **Contractuel** à côté de Prévu, Travaillé et Payé. Ce sont les heures par semaine du dossier RH de la personne, pour les jours de la période.

??? Les brouillons changent-ils ce que voit le personnel? | Non. Tant que vous ne publiez pas, le personnel, le pointage, les alertes de ratio, la paie et les avis de quarts ne voient que l’horaire publié.
??? Puis-je désactiver l’étape de publication? | Oui, quand aucun brouillon n’attend. Publiez-les ou supprimez-les d’abord.
