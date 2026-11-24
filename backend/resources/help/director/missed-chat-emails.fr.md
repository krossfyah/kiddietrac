---
title: Notifications par courriel des messages manqués
category: Settings
order: 80
---
# Notifications par courriel des messages manqués

Si un message de discussion reste non lu pendant plus de **30 minutes**, KiddieTrac envoie au destinataire un courriel de rappel pour qu’il ne manque rien d’important. Le courriel arrive aux couleurs de votre agence — couleurs, logo, adresse de soutien — avec un lien d’un seul toucher pour revenir dans la conversation.

## Fonctionnement

Toutes les 15 minutes, une tâche planifiée recherche les messages non lus plus anciens que le délai. Pour chacun, elle regarde qui fait partie de la conversation :

- **Parents → personnel** : lorsqu’un tuteur envoie un message, toutes les personnes ayant accès à la conversation reçoivent le rappel — directeurs de centre, administrateurs d’agence, éducateurs du centre.
- **Personnel → parents** : lorsqu’un directeur ou un éducateur répond, les tuteurs de la famille reçoivent le rappel.

Plusieurs messages non lus dans le même fil sont regroupés en un seul courriel. Si trois messages sont arrivés en une heure, le destinataire reçoit un seul résumé ordonné, et non trois courriels distincts.

## Ce que montre le courriel

Chaque message non lu est présenté sous forme de carte contenant :
- le nom de l’expéditeur
- l’heure (relative — « il y a 12 minutes »)
- le corps du message, tronqué (320 premiers caractères)
- le nombre de pièces jointes, s’il y en a

Un bouton bien visible intitulé **« Ouvrir la conversation »** au bas du courriel mène directement à `#chat` dans le portail.

## Pourquoi vous cessez de recevoir des rappels

Une fois qu’un message a fait l’objet d’un courriel, il est **marqué comme notifié** dans la base de données (horodatage `email_notified_at`). Le planificateur n’enverra pas de nouveau courriel pour le même message, même s’il reste non lu. Ainsi :

- Lire le message dans le portail → aucun courriel futur à son sujet (horodatage read_at).
- Répondre → même effet (read_at est inscrit du côté du destinataire lorsqu’il ouvre le fil).
- Ignorer → un seul courriel est envoyé, et c’est tout. Le fil reste dans votre liste des non lus, mais le courriel ne se répétera pas.

## Qui est exclu

- Les utilisateurs sans adresse courriel au dossier sont ignorés.
- Les utilisateurs supprimés (suppression logique) sont ignorés.
- Les expéditeurs ne reçoivent jamais de rappel pour leurs propres messages.
- Les utilisateurs qui ont déjà lu le message sont ignorés (l’horodatage `read_at` est inscrit lorsque le panneau de discussion affiche le message).

## Image de marque par agence

Le courriel passe par le **même SMTP par agence** que celui des résumés quotidiens et hebdomadaires :
- Si vous avez configuré l’hôte, l’utilisateur et le mot de passe SMTP sous **Agency edit → Email settings**, le message provient de votre domaine par l’entremise de votre boîte aux lettres.
- Sinon, il revient au réglage par défaut de la plateforme (`MAIL_*` dans le `.env` du serveur).
- Les locataires en marque blanche reçoivent un courriel propre, à leur image — sans pied de page « Powered by Kiddietrac ».

## Tests

Depuis SSH :

    php artisan kiddietrac:chat-emails --dry-run

Affiche ce qui serait envoyé sans rien envoyer. Utile avant de configurer SMTP pour la première fois, afin de confirmer que la détermination des destinataires est correcte.

    php artisan kiddietrac:chat-emails --dry-run --delay=0

Même chose, mais en ignorant le délai de 30 minutes, de sorte que chaque message non lu actuellement dans le système est évalué. Idéal pour vérifier que votre logique de jointure retourne les bons destinataires.

    php artisan kiddietrac:chat-emails

Envoi réel. À utiliser avec prudence — chaque message en attente fera l’objet d’un courriel.

## Personnaliser le délai

Le délai par défaut de 30 minutes équilibre « ne pas harceler les gens » et « ne rien manquer d’urgent ». Si vous voulez une autre cadence :

- **De façon permanente** : passez `--delay=N` dans l’appel artisan d’une entrée cron personnalisée.
- **Par agence** : c’est un candidat pour la v22p41+ — ouvrez un billet de soutien si vous voulez un seuil différent de 30 minutes.

## Piste d’audit

Chaque exécution d’envoi écrit une entrée `audit_logs` avec `action='chat.email_notified'`, le nombre de courriels envoyés et les identifiants des messages touchés. Vous pouvez la consulter depuis l’écran **Audit log** du menu Administration.
