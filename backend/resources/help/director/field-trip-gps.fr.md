---
title: Walk Watch — promenades et sorties suivies par GPS en direct
---
# Walk Watch

Deux écrans s’occupent des enfants qui quittent le centre. **Sorties éducatives**
(Programmes → Sorties éducatives) sert à planifier une sortie et à recueillir les
autorisations de sortie des parents. **Walk Watch** (Administration → Walk Watch) est le
GPS en direct de KiddieTrac : un éducateur commence une promenade sur son téléphone, les
parents sont avisés tout de suite et peuvent suivre sur une carte en direct, et vous voyez
chaque groupe sorti — qui s’en occupe, et où il se trouve.

[[open: #field-trips | Ouvrir les sorties éducatives]]
[[open: #trip-gps | Ouvrir Walk Watch]]

## Planifier une sortie éducative

1. Ouvrez **Programmes** → **Sorties éducatives** et appuyez sur **+ Planifier une sortie**. La fenêtre **Nouvelle sortie éducative** s’ouvre.
2. Choisissez le **Centre**, puis saisissez le **Titre**, la **Destination** et la **Date de la sortie**.
3. Saisissez le **Cost per child ($)**, ou laissez 0 pour une sortie gratuite.
4. Sous **Enfants invités**, cochez chaque enfant qui y va.
5. Appuyez sur **Créer et envoyer les autorisations**. La sortie apparaît dans la liste et les parents de chaque enfant invité sont invités à signer une autorisation de sortie.

Les cercles au-dessus de la liste montrent **Upcoming trips**, **Slips signed**, **Awaiting parents**, **Past trips**, **Promenades** et **Children who went**, avec des graphiques des sorties et promenades par mois et des modes de déplacement des enfants.

## Suivre une sortie et ses autorisations

1. Touchez le nom d’une sortie, ou appuyez sur son **⋮** > **Voir les détails**. Les détails montrent la destination, la date et les heures, des tuiles **Enfants**, **Durée**, **Distance**, **Steps (est.)**, **Transport** et **Coût**, le trajet GPS sur une carte, les **🪜 Étapes de la sortie** et le tableau **🧒 Enfants de cette sortie**.
2. Lisez pour chaque enfant l’**Autorisation** (**Approuvé**, **Refusé** ou **En attente**), **Réponse de**, **Quand** et **Signé**.
3. Appuyez sur **🔑 Autorisations de sortie** (ou **⋮** > **Autorisations de sortie** dans la liste) pour voir les nombres **APPROVED**, **DENIED** et **PENDING**. Ouvrez **✍️ View signature** sous un enfant pour voir la signature du parent avec sa date, son heure et sa position approximative.
4. Pour retirer une sortie, appuyez sur **⋮** > **Supprimer la sortie** et confirmez. Cela supprime la sortie et ses autorisations.

## Commencer une promenade (éducateur)

1. Sur le téléphone, ouvrez **Walk Watch** (la tuile 🚶 Walk Watch) et remplissez **Where to?** (et, au besoin, de quoi il s’agit).
2. Cochez les enfants qui viennent. Seuls les enfants **pointés en ce moment** peuvent être ajoutés.
3. Touchez **▶ Démarrer la promenade et partager la position** et autorisez la localisation quand le téléphone le demande.
4. Une barre flottante **En direct** reste à l’écran pendant que la promenade est partagée : **Carte**, **👀 Nombre** pour compter les enfants de la promenade, et **Arrêter**.

Le téléphone envoie sa position environ toutes les 12 secondes pendant la promenade. Touchez **👀 Nombre** et cochez chaque enfant quand vous le voyez; si quelqu’un manque, l’écran propose d’alerter le directeur tout de suite.

## Suivre les promenades et les sorties (directeurs et administrateurs)

1. Ouvrez **Administration** → **Walk Watch**. Les promenades et sorties du jour sont listées, chacune avec une pastille de statut comme **Out now**, **Retour** ou **Planifiée**.
2. Chaque carte montre le centre et la destination, l’heure de départ et de retour du groupe, la distance et les points GPS, **Avec** (l’éducateur et un numéro à toucher pour appeler), **Départ de**, **En ce moment** (ou **Vu pour la dernière fois**) et les **Children on this outing**.
3. Pour voir un autre jour, utilisez **‹ Précédent**, **Aujourd’hui**, **Next ›** ou la case **Jour**. Les promenades passées montrent la carte de leur trajet.
4. Appuyez sur **↻ Actualiser** pour la dernière position. La vue du jour s’actualise aussi toutes les 30 secondes.

## Ce que reçoivent les parents

- Au départ de la promenade : une notification dans la cloche de l’application, une notification push dans l’application KiddieTrac et un courriel — *« Ava est partie en promenade »*, la destination et avec qui.
- Une bannière **promenade en direct** dans l’application ouvre la carte, actualisée environ toutes les 12 secondes, avec la distance, les pas et le temps écoulé.
- À la fin de la promenade : *« Ava est revenue de la promenade »* par la cloche, une notification push et un courriel.
- La promenade s’inscrit au registre quotidien de l’enfant, et le résumé du soir montre où il est allé avec la carte du trajet.

## Terminer une promenade

- L’éducateur touche **Arrêter** (ou **End walk**). Les parents sont avisés que les enfants sont revenus.
- Si l’application KiddieTrac est ouverte et la localisation autorisée, l’application peut remarquer que le groupe est revenu au centre et demander **🏠 Back at …?** — un seul toucher termine la promenade. La détection en arrière-plan est un réglage de l’agence (Gestion des salles → **🚶 Promenades**), désactivé tant que vous ne l’activez pas.
- Une promenade que personne n’a terminée est fermée automatiquement à la fin de la journée (aucun avis n’est alors envoyé aux parents).

## Qui peut faire quoi

- Les **administrateurs de l’agence** voient les sorties et promenades de tous les centres, planifient et suppriment des sorties, et activent la détection des promenades en arrière-plan.
- Les **directeurs de centre** planifient des sorties et voient les sorties et promenades de leurs propres centres. Walk Watch est dans le menu **Administration** de l’administrateur de l’agence.
- Les **éducateurs** commencent, comptent et terminent les promenades sur leur téléphone pour les enfants pointés.
- Les **parents** signent les autorisations de sortie et ne suivent que les promenades de leur propre enfant, pour les 30 derniers jours. La position n’est partagée que pendant une promenade en cours.

Le début et la fin d’une promenade sont inscrits au journal d’audit.

## FAQ et dépannage

??? Un enfant manque dans la liste de la promenade sur le téléphone. | Seuls les enfants pointés en ce moment peuvent être ajoutés. Pointez d’abord l’enfant.
??? Walk Watch indique « Nothing on this day ». | Aucune promenade ni sortie n’a été consignée ce jour-là. Essayez un autre jour avec ‹ Précédent ou Next ›.
??? Les détails de la sortie indiquent « No GPS route recorded for this trip yet. » | Le trajet apparaît quand le personnel commence la sortie sur son téléphone avec la localisation autorisée.
??? La planification indique « Pick at least one child ». | Cochez au moins un enfant sous Enfants invités, puis appuyez de nouveau sur Créer et envoyer les autorisations.
??? Les parents n’ont pas été avisés de la fin de la promenade. | Une promenade fermée automatiquement à la fin de la journée n’envoie aucun avis. Demandez aux éducateurs de toucher Arrêter à leur retour.
??? Un parent peut-il voir les autres enfants de la promenade ? | Non. Les parents ne voient que les promenades de leur propre enfant.

## Liens utiles

[[open: #field-trips | Sorties éducatives]]
[[open: #trip-gps | Walk Watch]]
[[open: #headcounts | Comptages]]

- [Comptages et superviseur par intérim](headcounts-and-acting-supervisor)
- [Gestion des salles](room-controls)
- [Circuits d’autobus et transport](bus-routes)
