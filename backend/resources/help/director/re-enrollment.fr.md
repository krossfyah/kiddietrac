---
roles: agency_admin, centre_director, platform_admin
title: Campagnes de réinscription
category: Enrolment
order: 60
---
# Campagnes de réinscription

**Re-enrollment campaigns** demande à chaque famille inscrite si son enfant revient au prochain trimestre. Vous lancez une campagne avec un nom, le trimestre et une date limite ; chaque tuteur reçoit un avis et un courriel avec un bouton **Answer now**, la famille répond **Oui, il revient** ou **Non** pour chaque enfant (avec une note si elle veut), et l’écran compte qui a répondu et qui se réinscrit.

[[open: #reenrollment | Ouvrir Réinscription]]

## Lancer une campagne

1. Ouvrez **Engagement › Re-enrollment**.
2. Appuyez sur **+ Launch campaign** dans la bannière.
3. Choisissez le **Centre** (les administrateurs d’agence peuvent aussi choisir **All centres** ; les directeurs choisissent un de leurs centres).
4. Vérifiez le **Campaign name** et indiquez le **Target term**, par exemple « Fall 2027 ».
5. Choisissez la **Deadline**, le dernier jour pour répondre. Elle doit être après aujourd’hui.
6. Appuyez sur **Launch campaign**.

Un message indique combien d’enfants ont été invités et combien de familles ont été averties ; la campagne apparaît en haut de la liste comme **Open**.

> ⚠️ Les familles sont averties dès le lancement. Il n’y a ni brouillon ni annulation : vérifiez le centre, le nom, le trimestre et la date limite avant.

## Ce que reçoivent les familles

- Un avis dans l’appli : **Re-enrol for** le trimestre, avec la date limite.
- Un courriel aux couleurs de votre agence, avec leur prénom, la liste de leurs enfants et un bouton **Answer now**. Le bouton ouvre une page où elles répondent pour chaque enfant, sans se connecter. Le lien ne vaut que pour leur famille et jusqu’à la date limite.
- Dans l’appli, l’avis ouvre leur écran **Re-enrolment** avec les mêmes choix.

Les familles peuvent changer leur réponse jusqu’à la date limite. Les tuteurs qui n’ont pas encore accepté leur invitation ne reçoivent pas de courriel ; ils voient l’avis en se connectant.

## Rappel automatique

Les familles qui n’ont pas répondu reçoivent **un** rappel (avis et courriel), le nombre de jours de rappel de l’agence avant la date limite (7 par défaut), à partir de 9 h. Le rappel ne nomme que les enfants encore sans réponse. Les administrateurs d’agence changent ce nombre avec **⚙ Reminder rule** (0 = aucun rappel). La colonne **Deadline** indique quand le rappel part ou est parti.

## Suivre les résultats

1. Lisez la ligne : **Invited**, **Responded**, **Renewed** (en vert) et **Not returning**.
2. Dans le menu **⋮**, choisissez **View answers** pour voir la réponse de chaque famille par enfant, sa note, quand et comment elle a répondu (appli ou lien du courriel) et qui a reçu le rappel. Filtrez avec **All / No answer yet / Coming back / Not returning**.
3. Relancez par message ou téléphone les familles qui n’ont pas répondu.
4. Après la date limite, planifiez les groupes d’après **Renewed** et offrez les places libres à votre **liste d’attente**.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d’agence | Lancer pour un centre ou toute l’agence, voir toutes les réponses, régler le rappel |
| Directeur de centre | Lancer pour ses propres centres ; ne voit que les familles et chiffres de ses centres |
| Administrateur de la plateforme | Comme un administrateur d’agence, dans l’agence consultée |
| Parent | Répond pour ses enfants dans l’appli ou par le courriel, jusqu’à la date limite |

## FAQ et problèmes

??? Launch campaign ne fait rien. | Tous les champs sont obligatoires, et la date limite doit être après aujourd’hui.
??? « Choose one of your centres for this campaign. » | Les directeurs lancent pour un de leurs centres. Choisissez-le sous **Centre**.
??? « Invited 0 children ». | Aucun enfant de ce centre n’avait le statut Inscrit. Vérifiez **Administration › Children**.
??? Le lien du courriel ne fonctionne pas pour une famille. | Le lien expire après la date limite ou si la famille est partie. Elle peut répondre dans l’appli jusqu’à la date limite ; ensuite, notez sa réponse vous-même.
??? Une famille n’a pas reçu le courriel. | Vérifiez son adresse et qu’elle a accepté son invitation. Elle peut toujours répondre dans l’appli depuis l’avis.
??? Peut-on fermer ou supprimer une campagne ? | Non. Elle se ferme d’elle-même après la date limite et reste dans la liste.
??? Les anciennes campagnes affichent 0 réponse. | Les chiffres viennent des réponses des familles. Les campagnes lancées avant que les familles puissent répondre n’en ont aucune.

## Écrans liés

[[open: #tuition-increases | Augmentations des frais]]
[[open: #synced-waitlist | Liste d’attente]]
[[open: #admin-children | Enfants]]

Voir aussi [[de-enrolling-a-family]] et [[synced-waitlist]].
