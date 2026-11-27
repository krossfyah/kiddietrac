---
title: État des résumés IA — vérifier le résumé quotidien de chaque enfant
---
# État des résumés IA — vérifier le résumé quotidien de chaque enfant

Chaque soir, KiddieTrac rédige un court **résumé quotidien** pour chaque enfant à partir de ce que le personnel a noté dans la journée (repas, siestes, humeurs, activités, observations). Les parents le voient sur leur écran d'accueil sous **✨ YOUR DAILY DIGEST**. **Opérations → État des résumés IA** montre, enfant par enfant, si le résumé du jour est rédigé, et vous permet d'en rédiger un qui manque ou de le refaire après une correction des notes.

[[open: #digest-status | Ouvrir l'état des résumés IA]]

## Vérifier les résumés du jour

1. Ouvrez **État des résumés IA**. La page s'ouvre sur la date du jour.
2. Lisez les quatre tuiles du haut : **Enfants** (les enfants de vos centres), **Généré** (avec le pourcentage de couverture), **En attente** (il y a des notes, le résumé n'est pas encore rédigé) et **Aucun événement** (rien de noté, donc rien à résumer).
3. La ligne sous le titre indique **IA activée** ou **AI disabled (templated fallback)**. Quand l'IA est désactivée, les familles reçoivent un résumé plus simple, bâti à partir d'un modèle.
4. Parcourez le tableau. Chaque ligne montre l'enfant (**Child**), le nombre d'**Events** notés, l'étiquette de **Status** (**GENERATED**, **PENDING** ou **NO EVENTS**), l'heure **Generated at** et un **Preview** du texte.

> ℹ️ Les résumés sont rédigés une fois, en soirée. Pendant la journée, la plupart des enfants affichent **PENDING** : c'est normal.

## Consulter une autre journée

1. Cliquez sur la case de date dans le coin supérieur droit de la page.
2. Choisissez la journée. La page se recharge avec les résumés de ce jour-là.
3. Appuyez sur **Actualiser** pour relire la liste, par exemple après qu'une éducatrice a ajouté des notes manquantes.

## Rédiger un résumé maintenant, ou de nouveau

1. Trouvez l'enfant dans le tableau.
2. Appuyez sur **Générer** (pas encore de résumé) ou **Régénérer** (un résumé existe déjà). Le bouton affiche **Working...** pendant que l'IA rédige.
3. Une fenêtre s'ouvre, intitulée **Digest for** suivi du nom de l'enfant, avec le nouveau texte. Lisez-le.
4. Appuyez sur **Fermer**. Le tableau se met à jour et la ligne affiche **GENERATED**.

> ⚠️ **Régénérer** remplace le résumé que voit la famille. Si un parent a déjà lu l'ancien, il verra le nouveau texte la prochaine fois qu'il ouvrira l'application.

[[show: #digest-status @ Refresh | Me montrer le bouton Actualiser]]

## Qui peut faire quoi

| Rôle | Ce qu'il voit |
|---|---|
| Directeur de centre | Les enfants de ses propres centres |
| Administrateur d'agence | Les enfants de tous les centres ouverts de l'agence |
| Administrateur de plateforme | La même chose, pour l'agence où il travaille |
| Éducatrices et parents | Aucun accès. Les parents lisent le résumé lui-même sur leur écran d'accueil |

Les centres fermés et les enfants qui sont partis ne sont pas listés.

## Questions fréquentes et problèmes

??? Le bouton Générer est grisé. | Aucun événement n'a été noté pour l'enfant ce jour-là (**No events to summarize**). Ajoutez d'abord les notes de la journée, puis appuyez sur **Générer**.
??? Un enfant affiche PENDING tard en soirée. | Le traitement du soir ne l'a peut-être pas encore atteint, ou l'IA n'a pas répondu. Appuyez sur **Générer** pour cet enfant.
??? Une erreur s'affiche après avoir appuyé sur Générer. | Le service d'IA est peut-être occupé ou non configuré. Attendez quelques minutes et réessayez. Si l'erreur persiste, ouvrez un billet de soutien; les familles reçoivent quand même le résumé bâti à partir du modèle.
??? « No children in scope ». | Aucun enfant inscrit n'a été trouvé pour cette date dans vos centres. Choisissez une autre date ou vérifiez les inscriptions.
??? « Could not load digest status ». | Rechargez la page. Si le problème revient, ouvrez un billet de soutien.
??? Régénérer envoie-t-il une nouvelle notification au parent? | Non. Le résumé est mis à jour sur place; le parent le voit la prochaine fois qu'il ouvre son écran d'accueil.

## Écrans connexes

[[open: #care-log | Journal quotidien]]

Voir aussi : [Outils d'IA](ai-tools), [Notifications de l'agence](agency-notifications).
