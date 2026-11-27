---
title: La liste d’attente (demandes et prospects)
category: Enrolment
order: 12
---
# La liste d’attente (demandes et prospects)

**Liste d’attente** est l’endroit où vous gardez les familles qui ont demandé une place mais qui ne sont pas encore dans KiddieTrac : le nom du parent, son enfant, comment le joindre, quand il aimerait commencer et à quel point c’est urgent. Quand une place se libère, vous lui envoyez une mise à jour par courriel, vous l’approuvez ou la refusez, et vous la transformez en vraie famille d’un seul geste. Certaines entrées peuvent être **Synchronisée** depuis un système d’inscription relié; celles que vous ajoutez ici portent la mention **Ajoutée ici** et sont renvoyées automatiquement à ce système.

[[open: #synced-waitlist | Ouvrir la liste d’attente]]

> ℹ️ C’est la liste des *demandes* de l’agence. Un enfant déjà dans KiddieTrac qui attend un local paraît plutôt sous **Enfants** avec le statut **Liste d’attente**.

## Ajouter quelqu’un à la liste d’attente

1. Ouvrez **Inscriptions › Liste d’attente**.
2. Appuyez sur **＋ Ajouter à la liste d’attente** en haut à droite.
3. Remplissez **Nom du parent** (obligatoire) et ce que vous savez de **Nom de l’enfant**, **Email**, **Phone** et **Début souhaité**.
4. Choisissez une **Priorité** : **Élevé**, **Normale** ou **Faible**.
5. Ajoutez des **Notes** — frères et sœurs, horaire voulu, comment ils ont entendu parler de vous.
6. Appuyez sur **Ajouter**. Un message **Saved** apparaît et l’entrée s’ajoute à la liste comme **En attente**, avec la mention **Ajoutée ici**.

Les tuiles du haut comptent **Sur la liste d’attente**, **Approuvé**, **Refusé** et **Ajoutée ici**. Quand des familles d’un centre fermé attendent d’être placées, une tuile **Need a new home** paraît aussi.

## Envoyer une mise à jour à une famille

1. Trouvez l’entrée et ouvrez son menu **⋮**.
2. Choisissez **📣 Contacter**.
3. Tapez un **Sujet** (ou laissez-le vide — par défaut : « An update on your waitlist enquiry ») et votre **Message**.
4. Appuyez sur **Envoyer la mise à jour**.

La famille reçoit un courriel aux couleurs de votre agence, un message **Sent** indique « Update emailed to the parent. », et le statut de l’entrée devient **Contacté**. L’entrée doit avoir une adresse courriel — sinon, vous voyez « This lead has no email address on file. »

## Approuver, refuser ou modifier une entrée

1. Ouvrez le menu **⋮** de l’entrée.
2. Choisissez **✅ Approuver** ou **🚫 Refuser**. Le badge de statut change et un message le confirme. Rien n’est envoyé à la famille.
3. Pour changer les détails, choisissez **✏️ Modifier**, mettez à jour les champs dans **Modifier l’entrée de la liste d’attente** et appuyez sur **Enregistrer**.
4. Pour retirer une entrée pour de bon, choisissez **🗑 Supprimer** et confirmez avec **Supprimer**. Elle ne peut pas être récupérée.

## Transformer une demande en famille

1. Ouvrez le menu **⋮** de l’entrée et choisissez **🎓 Inscrire le parent**.
2. Lisez la confirmation : cela crée une famille (et l’enfant, si un nom d’enfant a été donné) dans KiddieTrac et lance l’inscription.
3. Appuyez sur **Inscrire**. Un message **Enrolled** indique « Family created — enrolment started. » et le statut de l’entrée devient **Enrolled**.
4. Dans la boîte **Family created**, choisissez **Rester ici** pour continuer avec la liste, ou **Ouvrir les familles**.
5. Dans **Administration › Familles**, ouvrez la nouvelle famille pour ajouter le local, la date de début, les frais et le reste des renseignements de l’enfant, puis invitez le parent.

La famille est créée dans un centre que vous gérez : pour un administrateur d’agence, le premier centre de l’agence; pour un directeur, son propre premier centre. L’adresse courriel du parent devient celle du parent principal de la famille.

## Placer une famille d’un centre fermé

Quand un centre ferme, un administrateur d’agence peut déplacer ses familles (sans enfant encore inscrit) vers cette liste. Leurs lignes indiquent **Previously at** l’ancien centre et montrent **🏠 Assigner** au lieu de **🎓 Inscrire le parent**.

1. Ouvrez le menu **⋮** de la ligne et choisissez **🏠 Assigner**.
2. Choisissez le nouveau fournisseur dans **Educator / centre**. Les frères et sœurs déménagent ensemble.
3. Appuyez sur **Assigner**. La ligne indique maintenant **Placed with** le nouveau centre et son statut est **Assigné**.
4. Ouvrez la liste d’attente de ce centre et appuyez sur **Inscrire** pour choisir le local, la date de début et les frais.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d’agence | Tout sur cet écran, pour toute l’agence; déplacer ici les familles d’un centre fermé |
| Directeur de centre | Ajouter, modifier, contacter, approuver, refuser, supprimer et inscrire — les familles arrivent dans ses propres centres |
| Administrateur de plateforme | La même chose qu’un administrateur d’agence, dans l’agence consultée |

Les éducatrices et les parents ne voient pas cet écran.

## Questions fréquentes et problèmes

??? « Parent name is required. » | Chaque entrée doit avoir au moins le nom du parent. Tapez-le dans **Nom du parent** et appuyez de nouveau sur **Ajouter**.
??? « Please write a message. » | **Contacter** demande un message. Le sujet est facultatif.
??? « This lead has no email address on file. » | Choisissez **✏️ Modifier**, ajoutez son **Email**, enregistrez, puis **📣 Contacter** de nouveau.
??? « This family is already in KiddieTrac. Use Assign to place them with a centre. » | La ligne est une famille déjà au dossier. Utilisez **🏠 Assigner**, pas **🎓 Inscrire le parent**, pour ne pas créer de famille en double.
??? « This agency has no centre to enrol into yet — add a centre first. » | Ajoutez un centre dans **Administration › Centres**, puis essayez de nouveau **🎓 Inscrire le parent**.
??? La liste affiche « No waitlist entries yet. Add one to get started. » | Personne n’a été ajouté et rien n’a encore été synchronisé. Appuyez sur **＋ Ajouter à la liste d’attente**.
??? Que veut dire Synchronisée? | L’entrée vient d’un système d’inscription relié à votre agence. Vous pouvez quand même la contacter, l’approuver, la refuser ou l’inscrire ici.
??? Approuver ou refuser envoie-t-il un courriel à la famille? | Non. Seul **📣 Contacter** envoie un courriel. Approuver et Refuser changent seulement le statut.

## Écrans liés

[[open: #admin-families | Familles]]
[[open: #admin-children | Enfants]]
[[open: #invitation-codes | Codes d’invitation]]

Voir aussi [[adding-a-family]] et [[inviting-parents-and-staff]].
