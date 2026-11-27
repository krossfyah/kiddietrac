---
title: Rappels des registres quotidiens
category: Staff Management
order: 31
---
# Rappels des registres quotidiens

Les familles comptent sur le registre quotidien : les observations, les repas et les siestes, la photo de
la journée de leur enfant. Les rappels des registres quotidiens font en sorte que les lacunes soient repérées le soir même,
et non à la prochaine inspection.

Chaque soir, chaque éducateur qui a travaillé ce jour-là reçoit un court courriel professionnel énumérant
tout ce qui n’a pas été consigné. Il indique combien de jours d’une période récente comportaient des lacunes, passe à l’étape supérieure
lorsque cela devient une tendance, et se termine par de nouveaux conseils. Le directeur du centre et les administrateurs
de l’agence sont en copie.

Les paramètres se trouvent dans **Paramètres → Gestion des salles → Registres quotidiens**. Les administrateurs de l’agence les modifient.
Les directeurs peuvent les consulter, ainsi que les rappels envoyés pour leurs propres centres.

[[open: #educator-rooms?tab=records | Ouvrir les paramètres des registres quotidiens]]

## Ce qui est vérifié

Pour chaque éducateur, chaque jour où le centre est ouvert :

| Élément | Vérifié lorsque | Par défaut |
|---|---|---|
| **Observations** | Des enfants étaient présents | Au moins 1 |
| **Entrées du journal quotidien** (repas, siestes, toilettes, activités et notes ensemble) | Des enfants étaient présents | Au moins 3 |
| **Photos ou vidéos** partagées avec les familles | Des enfants étaient présents | Au moins 1 |
| **Repas et collations consignés** | Des enfants étaient présents | Au moins un repas ou une collation |
| **Plan de leçon** pour la salle ou le centre de l’éducateur cette semaine | Chaque jour de travail | Activé |
| **Menu** de la semaine, rédigé et publié | Chaque jour de travail | Activé |

Les chiffres quotidiens sont les mêmes que ceux que l’éducateur voit dans son courriel « Votre journée en bref » de 19 h,
de sorte que les deux ne se contredisent jamais.

Seuls les éducateurs qui ont travaillé ce jour-là sont vérifiés : un pointage, un enfant qu’ils ont accueilli, ou
tout ce qu’ils ont consigné. Un éducateur pointé sans enfants dans la salle ne se voit pas demander
d’observations sur personne. Les jours de fermeture du centre sont ignorés.

## Configuration (administrateurs de l’agence)

1. Allez à **Paramètres → Gestion des salles** et ouvrez l’onglet **📝 Registres quotidiens**.
2. Cochez **Envoyer les rappels des registres quotidiens**.
3. Réglez **Envoyer à**, selon l’heure de votre agence. Par défaut : 18 h 30.
4. Sous **Ce que les éducateurs doivent consigner chaque jour**, définissez les minimums. Saisissez
   **0** pour désactiver une vérification.
5. Sous **Prévu pour la semaine**, cochez **Plan de leçon** et **Menu** si vous voulez qu’ils
   soient vérifiés.
6. Sous **Copies et escalade**, cochez **Mettre en copie le directeur et les administrateurs de l’agence** et réglez
   **Escalader après** (par exemple, 3 jours sur 30 jours).
7. Appuyez sur **Enregistrer**.

Les rappels sont **activés par défaut**, avec les valeurs par défaut ci-dessus, jusqu’à ce qu’un administrateur de l’agence les modifie.
Pour les arrêter, décochez **Envoyer les rappels des registres quotidiens** et appuyez sur **Enregistrer**.

## Escalade

Le courriel indique toujours combien de jours de travail de la période comportaient des registres incomplets, par
exemple « Il s’agit du 2e jour de travail des 30 derniers jours avec des registres incomplets ».

Dès que ce nombre atteint votre valeur **Escalader après**, le courriel change :

- l’objet commence par **Escaladé :** et indique le nombre, par exemple « (4 jours sur 30) »;
- il précise que le rappel a été escaladé et communiqué au directeur et à l’administrateur
  conformément à la politique de documentation de votre agence.

Avant cela, le courriel indique à l’éducateur combien de jours de ce type entraînent une escalade.

## Qui est en copie

Les administrateurs de l’agence choisissent qui est en copie dans **Paramètres → Notifications → 🚩 Registres quotidiens escaladés**.

- **Rôles :** les administrateurs de l’agence, les directeurs du centre de l’éducateur et les auditeurs. Par défaut : les administrateurs de l’agence et les directeurs du centre.
- **Ces personnes :** choisissez des membres nommés du personnel de votre agence. Une personne choisie par son nom n’est informée que pour les centres où elle travaille, sauf si elle est administratrice de l’agence.
- **Comment ils sont informés :** courriel (une copie de chaque rappel), la cloche dans KiddieTrac et une notification sur le téléphone (seulement lorsqu’un rappel est escaladé).

Les super-administrateurs de KiddieTrac, les comptes d’intégration et les accès de revendeurs ne sont jamais en copie, même s’ils ont un rôle d’administrateur dans votre agence. Le paramètre ne peut pas être enregistré sans personne. **Qui est informé maintenant** montre, centre par centre, exactement qui serait informé. Chaque personne peut toujours le désactiver pour elle-même dans **Paramètres → Notifications → Me prévenir pour → Registres quotidiens escaladés**.

[[open: #notification-settings?tab=escalation | Choisir qui reçoit les rappels escaladés]]

## Ce que reçoit l’éducateur

- Un courriel de votre agence, avec le directeur et les administrateurs de l’agence en copie (si vous
  l’avez coché). Il énumère chaque élément manquant, ce qui a été consigné par rapport à ce qui était attendu, et pourquoi
  c’est important.
- Trois conseils : deux sur ce qui a été oublié et un pour les journées chargées. Les conseils changent chaque jour
  et diffèrent d’un éducateur à l’autre.
- Une cloche de notification dans KiddieTrac qui ouvre le **Journal quotidien**.

Chaque éducateur reçoit au plus un rappel par jour. Rien n’est envoyé à un éducateur dont les
registres étaient complets.

## Rappels envoyés — 14 derniers jours

Sous les paramètres, un tableau énumère les rappels des 14 derniers jours : date, éducateur et
centre, ce qui n’a pas été consigné, et combien de jours de la période comportaient des lacunes. Les rappels
escaladés sont marqués **escaladé**. Un rappel qui n’a pas pu être envoyé par courriel affiche
**(non envoyé par courriel)**.

Les directeurs ne voient ici que leurs propres centres.

??? Est-ce envoyé aux visiteurs à domicile ou aux directeurs ? | Non. Seules les personnes ayant le rôle d’éducateur dans un centre reçoivent le rappel. Les directeurs et les administrateurs de l’agence sont en copie.
??? Un éducateur peut-il encore corriger le registre après le courriel ? | Oui. Le courriel lui demande d’ajouter les entrées le soir même ou dès le lendemain matin.
??? Pourquoi un éducateur n’a-t-il pas reçu de rappel ? | Il n’a pas travaillé ce jour-là, le centre était fermé, tout avait été consigné, ou son compte est désactivé.

Voir aussi : [Gestion des salles](room-controls), [Journaux de soins quotidiens](daily-care-logs).
