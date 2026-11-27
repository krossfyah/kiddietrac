---
title: Notifications de l'agence — rappels et qui est avisé de quoi
---
# Notifications de l'agence — rappels et qui est avisé de quoi

**Paramètres → Agency notifications** est l'endroit où un administrateur d'agence décide des rappels que KiddieTrac envoie au nom de l'agence et de qui les reçoit : formulaires à contresigner, biographies des fournisseurs, fichiers partagés, règles du Centre de messages, résumés quotidiens, moments instantanés, notifications d'application du personnel, registres quotidiens escaladés, vaccinations et exercices d'évacuation, et certificats du personnel. Chaque personne peut encore ajuster ses propres avis dans **Mes paramètres**; un rôle que vous coupez ici ne reçoit rien sur ce sujet.

[[open: #notification-settings | Ouvrir les notifications de l'agence]]

L'écran a un onglet par sujet : **✍️ Contre-signature**, **🧾 Parents (formulaires seulement)**, **🧑‍🏫 Biographies des fournisseurs**, **📎 Fichiers partagés**, **💬 Centre de messages**, **📨 Résumés quotidiens**, **🌈 Moments instantanés**, **📲 Obligatoire pour le personnel**, **🚩 Registres quotidiens escaladés**, **💉 Immunizations & drills** et **🎓 Certifications du personnel**. Chaque onglet a son propre bouton **Enregistrer**.

## Rappeler aux réviseurs les formulaires à contresigner

1. Ouvrez l'onglet **✍️ Contre-signature**.
2. Activez **Envoyer les rappels**.
3. Choisissez la **Fréquence** (**Tous les jours**, **En semaine seulement**, **Une fois par semaine** ou **Aux quelques jours**) et l'**Heure de la journée**, selon le fuseau horaire de votre agence.
4. Indiquez **Seulement les formulaires signés depuis au moins** un nombre d'heures, pour qu'un formulaire signé il y a quelques minutes ne soit pas relancé tout de suite.
5. Choisissez **Qui reçoit le rappel**. Les directeurs voient seulement les formulaires de leur centre; un centre sans directeur envoie ses formulaires aux administrateurs.
6. Cochez comment les aviser : **Envoyer par courriel**, **Afficher dans l’application**, **Notification push sur les téléphones**.
7. Appuyez sur **👁 Preview** pour voir le rappel, sur **📤 Send test…** pour en envoyer un, marqué [Test], à l'adresse que vous tapez, puis sur **Enregistrer**.

> ℹ️ Seuls les formulaires réglés à **Requires counter-signature** dans **Forms Manager → Edit** comptent.

## Décider à qui les éducatrices peuvent écrire

1. Ouvrez l'onglet **💬 Centre de messages**.
2. Pour **Educators** et **Home visitors**, choisissez **Familles qu’ils peuvent joindre** : **Familles de leurs salles** ou **Toutes les familles de leur centre**.
3. Cochez les membres du personnel à qui ils peuvent écrire (administrateurs d'agence, directeurs, autres éducatrices, visiteuses à domicile).
4. Choisissez ce qui arrive aux **Conversations these rules no longer allow** : **Conserver comme historique en lecture seule** ou **Les masquer**.
5. Réglez **Nudges** (le bouton 👋), **Families can nudge staff**, **Time between nudges**, **Ring through Do Not Disturb**, **Calls** et **Families can call staff**.
6. Appuyez sur **Enregistrer**. **✓ Saved — applies straight away** s'affiche.

> ℹ️ Les directeurs et les administrateurs peuvent toujours écrire à tout le monde, et les parents peuvent toujours répondre aux personnes qui leur écrivent.

## Quand les familles et le personnel reçoivent le résumé quotidien

1. Ouvrez **📨 Résumés quotidiens**.
2. Pour **Parents**, choisissez combien de temps après le départ de l'enfant le résumé part; réglez **Parents — end of day** pour un enfant dont le départ n'a jamais été inscrit.
3. Pour **Educators**, choisissez le délai après leur fin de quart, et **Educators — end of day** pour qui n'a jamais pointé sa sortie.
4. Appuyez sur **Enregistrer**.

Dans **🌈 Moments instantanés**, vous pouvez plutôt aviser les familles tout de suite des **Daily moments**, **Learning observations**, **Medication doses** et **Milestones**. Coupez **Instant moment notifications** et les familles les voient seulement dans le résumé de fin de journée.

## Autres sujets

- **📲 Obligatoire pour le personnel** — activez **Exiger les notifications d’application** pour que le personnel doive autoriser les notifications sur son téléphone.
- **🚩 Registres quotidiens escaladés** — les rôles et les personnes qui reçoivent les registres escaladés, et comment ils sont avisés.
- **💉 Immunizations & drills** — coupez un rôle pour arrêter tout avis sur ce sujet pour les personnes de ce rôle (cloche, téléphone et courriel).
- **🎓 Certifications du personnel** — **Expiry alerts** avant, le jour même et après l'expiration d'un certificat, au membre du personnel, à ses directeurs et aux administrateurs. **Prévisualiser les prochains rappels** montre qui est concerné.
- **📎 Fichiers partagés** — **Email parents about new files** et **Email staff when a family sends a file**. Le courriel dit seulement qu'un fichier attend; le fichier n'est jamais joint.
- **🧑‍🏫 Biographies des fournisseurs** — rappeler aux fournisseurs qui n'ont pas encore de biographie, et **Send admins a summary**.
- **🧾 Parents (formulaires seulement)** — les parents qui ont signé des formulaires mais n'ont pas encore de famille.

[[show: #notification-settings @ 💬 Message Centre | Me montrer l'onglet Centre de messages]]

## Qui peut faire quoi

| Rôle | Accès |
|---|---|
| Administrateur d'agence | Tous les onglets, pour son agence |
| Administrateur de plateforme | La même chose, pour l'agence où il travaille |
| Directeur de centre, éducatrice | Pas dans leur menu. Ils règlent leurs propres avis dans **Mes paramètres** |

## Questions fréquentes et problèmes

??? J'ai enregistré mais rien n'est parti. | Vérifiez **📬 Mail & notifications** dans **Paramètres → Email settings** : désactivé, rien ne part. Vérifiez ensuite que le rôle est coché dans l'onglet du sujet.
??? Le test indique « Not sent ». | La raison suit entre parenthèses. Le plus souvent, l'adresse est erronée ou les courriels de l'agence sont coupés.
??? Un directeur reçoit des rappels de contre-signature pour un autre centre. | Cela ne devrait pas arriver : les directeurs voient seulement les formulaires de leur centre. Vérifiez à quels centres le directeur est affecté dans **Staff**.
??? Une personne reçoit encore un avis après que j'ai coupé son rôle. | Une personne qui a deux rôles est avisée si l'un des deux est activé.
??? Où se trouve le réglage de contre-signature d'un formulaire? | Dans **Forms Manager → Edit**, cochez **Requires counter-signature**.

## Écrans connexes

[[open: #email-settings | Paramètres de courriel]] [[open: #forms-manager | Gestionnaire de formulaires]] [[open: #chat | Centre de messages]]

Voir aussi : [Paramètres de courriel](email-settings), [Réviser, contresigner et renvoyer des formulaires](reviewing-and-counter-signing-forms), [Centre de messages](message-centre-rules).
