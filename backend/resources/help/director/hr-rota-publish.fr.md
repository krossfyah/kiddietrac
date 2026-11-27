---
title: RH : publier l'horaire, copier des quarts, quarts prédéfinis, budget de main-d'œuvre, corrections de pointage
---
# RH : publier l'horaire, copier des quarts, quarts prédéfinis, budget de main-d'œuvre, corrections de pointage

Des outils d'horaire du module RH de KiddieTrac. **Ressources humaines > Horaires et quarts > Outils de quarts** regroupe l'étape de publication, les quarts prédéfinis et le budget hebdomadaire de main-d'œuvre; l'onglet **Horaire** gagne **📋 Copy** et une barre de publication. **Ressources humaines > Pointages et feuilles de temps > Corrections de pointage** sert à approuver les demandes de correction de pointage du personnel.

[[open: #schedule?tab=rotatools | Ouvrir Outils de quarts]]

## Activer l'étape de publication (administrateurs de l'agence)

1. Ouvrez **Ressources humaines > Horaires et quarts** et choisissez **Outils de quarts**.
2. Sous **Étape de publication**, cochez la case pour bâtir l'horaire en brouillon et le publier quand il est prêt.
3. Si le personnel doit confirmer chaque quart, cochez aussi la case « Staff must accept each shift ».
4. Appuyez sur **Enregistrer**. Un message **Rota settings saved.** s'affiche.

L'étape est désactivée par défaut, et l'horaire fonctionne alors comme avant. Activée, les nouveaux quarts, les changements et les retraits dans l'**Horaire** attendent en brouillon, marqués **Brouillon**, **Modifié · non publié** ou **À retirer**. Le personnel, le pointage, les alertes de ratio et la paie ne voient que les quarts publiés. Tous les quarts déjà à l'horaire comptent comme publiés.

## Publier l'horaire

1. Bâtissez ou modifiez la semaine dans l'onglet **Horaire** comme d'habitude (**+ Nouveau quart**, **⚡ Autofill week**).
2. Lisez la barre au-dessus de la grille : « 📝 N changes not published yet ».
3. Appuyez sur **Publish N** dans cette barre.
4. Vérifiez le nombre de quarts nouveaux, modifiés et à retirer, puis appuyez sur **Publier**. Un message **Horaire publié** s'affiche.

Chaque personne reçoit un seul avis pour tous ses changements. Un brouillon qui ne convient plus (la personne est partie, ou il chevauche maintenant un autre quart) reste en brouillon et est listé. Pour jeter tous les brouillons, appuyez sur **Supprimer tous les brouillons** dans la même fenêtre et confirmez : les quarts publiés restent tels quels. L'onglet **Plan des horaires** liste les brouillons et les alertes de ratio telles qu'elles seraient une fois publiées.

> ℹ️ Le remplissage de nuit et les heures habituelles publient encore tout de suite.

## Copier des quarts

1. Dans l'onglet **Horaire**, en vue semaine, appuyez sur **📋 Copy**.
2. Sous **Quoi copier**, choisissez **La semaine dernière dans cette semaine (tout le monde)**, **La semaine d'une personne** ou **Un jour sur d'autres jours**.
3. Pour une personne, choisissez-la et la semaine à copier; pour un jour, choisissez **Copier ce jour** et cochez les jours sous **Vers**.
4. Appuyez sur **Copier**.

Les jours de fermeture, les congés approuvés, les dates d'indisponibilité, les quarts qui se chevauchent et les heures déjà passées sont laissés de côté, et le résultat les liste. Avec l'étape de publication, les copies restent en brouillon jusqu'à la publication.

## Créer des quarts prédéfinis

1. Dans **Outils de quarts**, appuyez sur **+ Nouveau quart prédéfini**.
2. Donnez-lui un nom (par exemple « Tôt 7 h – 15 h »), les heures, le rôle, le centre (ou **Tous les centres**) et une **Couleur**.
3. Appuyez sur **Enregistrer**.

Dans le formulaire de quart, choisissez-le dans **Quart prédéfini** pour remplir les heures et le rôle; le quart s'affiche alors dans cette couleur. **Archiver** le cache (les quarts déjà créés gardent leur couleur); **Restaurer** le ramène.

## Fixer un budget hebdomadaire de main-d'œuvre (administrateurs de l'agence)

1. Dans **Outils de quarts**, trouvez **Budget hebdomadaire de main-d'œuvre**. Seules les personnes qui voient la paie voient ce tableau; une direction qui voit la paie voit les chiffres, mais pas le bouton.
2. Appuyez sur **Fixer le budget** sur la ligne d'un centre.
3. Inscrivez le montant hebdomadaire et appuyez sur **Enregistrer**. Laissez vide pour retirer le budget.

L'**Horaire** affiche alors « 💵 Planned … projected … », et le tableau montre **Prévu cette semaine**, **Coût prévu** et **Du budget**. Le personnel salarié et les personnes sans taux horaire sont comptés, mais pas chiffrés.

## Approuver les corrections de pointage

1. Ouvrez **Ressources humaines > Pointages et feuilles de temps** et choisissez **Corrections de pointage**.
2. **En attente** liste les demandes, avec ce qui est **Enregistré**, ce qui est **Demandé** et **Pourquoi**.
3. Appuyez sur **Approuver**, ajoutez une note au besoin et appuyez sur **Approuver et corriger**. Ou appuyez sur **Refuser**, expliquez pourquoi et appuyez sur **Refuser**.

L'approbation change le pointage (marqué comme corrigé) et les heures suivent dans la feuille de temps et la paie. La personne est avisée dans les deux cas. **Décidé** montre les demandes passées.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur de l'agence | Activer l'étape de publication et l'acceptation, fixer les budgets, tout ce qui suit. |
| Direction de centre | Publier, copier, créer des quarts prédéfinis et approuver les corrections dans ses centres. Voit les budgets seulement si elle voit la paie. |
| Éducatrice, éducateur, visiteur à domicile | Accepter ses quarts dans **Mes RH > Quarts à accepter**, demander une correction dans **Mes RH > Corriger mon pointage**. |

## Questions fréquentes et problèmes

??? Les brouillons changent-ils ce que voit le personnel? | Non. Jusqu'à la publication, le personnel, le pointage, les alertes de ratio, la paie et les avis de quart ne voient que l'horaire publié.
??? Puis-je désactiver l'étape de publication? | Oui, quand aucun brouillon n'attend. Publiez-les ou supprimez-les d'abord.
??? Il n'y a pas de bouton Approuver sur une correction. | C'est votre propre demande : une autre direction ou un administrateur de l'agence la décide.
??? Une correction est refusée avec « Locked timesheet ». | Le jour fait partie d'une période de paie approuvée et verrouillée. Un administrateur de l'agence la déverrouille d'abord dans **Approuver et verrouiller**.
??? Un membre du personnel a appuyé sur « I can't do this shift ». | Les directions du centre sont avisées. **Outils de quarts > En attente d'acceptation du personnel** l'affiche comme **Impossible**, avec sa raison.
??? Je ne vois pas le bouton 📋 Copy. | Il s'affiche seulement en vue semaine, pour les agences qui ont le module RH.

## Écrans liés

[[open: #schedule | Horaire]]
[[open: #schedule?tab=rota | Plan des horaires]]
[[open: #timesheets?tab=lock | Approuver et verrouiller]]

- [[hr-rota]]
- [[hr-open-shifts]]
- [[hr-overtime-timesheets]]
