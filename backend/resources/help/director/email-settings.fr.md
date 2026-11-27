---
title: Paramètres de courriel — les courriels de votre agence, l'expéditeur et les rappels
---
# Paramètres de courriel — les courriels de votre agence, l'expéditeur et les rappels

**Paramètres → Email settings** détermine comment les courriels partent pour votre agence : l'interrupteur général, le nom d'expéditeur que voient les familles, les centres et les groupes qui reçoivent des courriels, et les rappels automatiques (fermetures, jours fériés, anniversaires, vaccinations, facturation). Ouvrez-le quand vous configurez une nouvelle agence, ouvrez un nouveau centre ou quand quelqu'un dit ne pas recevoir de courriels.

[[open: #email-settings | Ouvrir les paramètres de courriel]]

L'écran a des onglets : **📬 Général**, **📤 Sortant**, **📥 Boîte aux lettres**, **🎛️ Livraison**, **🗓 Fermetures**, **🎂 Anniversaires**, **🩹 Vaccinations** et **💳 Facturation**. Les interrupteurs s'enregistrent dès que vous les changez; les cases où vous tapez s'enregistrent avec **Enregistrer les paramètres**.

## Activer ou couper les courriels pour toute l'agence

1. Ouvrez l'onglet **📬 Général**.
2. Repérez **📬 Mail & notifications**. C'est l'interrupteur général de tous les courriels et de toutes les notifications de l'agence.
3. Laissez-le activé. Coupez-le seulement pour tout arrêter (par exemple pendant la configuration d'une nouvelle agence) : désactivé, rien ne part vers les familles ni le personnel, textos et appels compris.

> ⚠️ Quand cet interrupteur est coupé, une diffusion ou une annonce peut quand même indiquer « envoyé » alors que personne ne la reçoit. Vérifiez-le en premier quand personne ne reçoit rien.

## Choisir le nom et l'adresse d'expéditeur

1. Dans **📬 Général**, tapez le **Nom d’expéditeur** que verront les familles, par exemple le nom de votre agence suivi de « Service de garde ».
2. Tapez l'**Adresse d’expéditeur**. Si vous la laissez vide, l'expéditeur par défaut de la plateforme est utilisé.
3. Appuyez sur **Enregistrer les paramètres**.
4. Appuyez sur **M’envoyer un courriel test** et vérifiez votre boîte de réception.

## Choisir les autres courriels automatiques

Dans **📬 Général**, vous trouvez aussi :

- **⏰ Attendance reminders** — un courriel à l'éducatrice qui note des moments de la journée pour un enfant qui n'est pas inscrit comme arrivé. Les administrateurs et les directeurs sont en copie cachée.
- **📨 Onboarding reminders** — un courriel quotidien aux personnes invitées qui n'ont pas terminé leur inscription. Choisissez l'heure dans **Envoyer chaque jour à** (heure locale de l'agence).
- **📷 One-Tap nudges** — un courriel hebdomadaire aux parents dont l'enfant a été inscrit à l'arrivée ou au départ à la main plus de deux fois dans la semaine, pour les encourager à utiliser One-Tap.
- **⏰ Unread chat messages** — combien de temps un message peut rester non lu avant qu'un courriel soit envoyé. Indiquez les minutes dans **Courriel après** et appuyez sur **Enregistrer les paramètres**. Tout ce qui attend est réuni dans un seul courriel par personne.

## Retenir les courriels d'un centre ou d'un groupe

1. Ouvrez l'onglet **🎛️ Livraison**. **🎛️ Centre & room email delivery** liste chaque centre et ses groupes.
2. Coupez un centre ou un groupe pour retenir tous les courriels à ses éducatrices et à ses familles, par exemple un centre qui n'est pas encore en service.
3. Un message **Email on** ou **Email off** confirme chaque changement.

## Rappels de fermeture et de jours fériés

1. Ouvrez l'onglet **🗓 Fermetures** et activez **🗓 Closure reminders**.
2. Activez **Envoyer dès l’ajout** pour annoncer une fermeture dès qu'elle est inscrite au calendrier.
3. Sous **Puis rappeler de nouveau**, cochez les jours avant la fermeture (par exemple **3 days** et **Day before**).
4. Pour fermer les jours fériés, activez **Fermer automatiquement les jours fériés légaux**, choisissez le **Calendrier des jours fériés**, cochez les jours de plus sous **Fermer aussi pour** et choisissez quand **Aviser tout le monde** (en jours ouvrables).

[[show: #email-settings @ Closures | Me montrer l'onglet Fermetures]]

## Boîtes de courriel, envoi sortant et courriels liés aux personnes

- **📥 Boîte aux lettres** : activez **🔗 Link emails to people** pour classer les courriels des boîtes de l'agence au dossier du parent, du membre du personnel ou du contact concerné. Choisissez si le lien se fait **Link automatically by email address**, pour quels types de personnes, et **Who can see linked emails**.
- **📤 Sortant** : **📤 Outbound email (SMTP)** permet d'envoyer par votre propre fournisseur. Laissez **Méthode d’envoi** sur **Par défaut de la plateforme** à moins que votre fournisseur vous ait donné des paramètres SMTP.

> ⚠️ Après avoir choisi **Mon propre serveur SMTP**, appuyez toujours sur **M’envoyer un courriel test**. Si le test n'arrive pas, revenez à **Par défaut de la plateforme** pour que les familles continuent de recevoir vos courriels.

## Qui peut faire quoi

| Rôle | Accès |
|---|---|
| Administrateur d'agence | Tout cet écran, pour son agence |
| Administrateur de plateforme | La même chose, plus l'onglet **🌐 Platform mail** |
| Directeur de centre | Pas dans son menu. Demandez à un administrateur d'agence |

## Questions fréquentes et problèmes

??? Personne ne reçoit de courriels. | Vérifiez **📬 Mail & notifications** dans Général, puis le centre et le groupe dans **🎛️ Livraison**. Vérifiez ensuite l'adresse courriel de la personne et ses propres choix dans **Mes paramètres**.
??? Le courriel test n'est pas arrivé. | Regardez dans les courriels indésirables. Si vous utilisez votre propre serveur SMTP, revenez à **Par défaut de la plateforme** et refaites le test.
??? « Could not load email settings ». | Rechargez la page. Si le problème revient, ouvrez un billet de soutien.
??? Puis-je modifier le texte d'un courriel? | Oui, dans **Paramètres → Email templates**.
??? Où sont les rappels de contre-signature, de fichiers et de biographies? | Dans **Paramètres → Agency notifications**.

## Écrans connexes

[[open: #email-templates | Modèles de courriel]] [[open: #notification-settings | Notifications de l'agence]] [[open: #email | Client de courriel]]

Voir aussi : [Notifications de l'agence](agency-notifications), [Modèles de courriel](email-templates), [Courriels pour les messages manqués](missed-chat-emails).
