---
title: Annonces
category: Communications
order: 35
---
# Annonces

**Opérations → Annonces.** Publiez un avis aux familles, au personnel ou aux deux, pour
l’ensemble de l’agence ou pour un seul centre. Les gens le voient dans le portail et, si vous
le choisissez, par courriel, message texte et notification de l’application.

[[open: #announcements | Ouvrir Annonces]]

## Publier une annonce

1. Ouvrez **Annonces** et appuyez sur le bouton **+** (**Nouvelle annonce**).
2. **Où** : **🏢 Partout — tous les centres**, ou un seul centre. Seuls les administrateurs de
   l’agence peuvent envoyer à toute l’agence. Un directeur de centre choisit l’un de ses centres.
3. **Qui** : choisissez le public (voir ci-dessous). La ligne sous la case reprend les deux
   choix en une seule phrase, par exemple « Tous les éducateurs de toute l’agence ».
4. **Image (facultatif)** : un JPEG, PNG ou GIF, jusqu’à 5 Mo.
5. Saisissez un **Titre** et rédigez le texte. Utilisez la barre d’outils pour le gras, l’italique,
   le soulignement, les listes et les liens. La mise en forme est conservée dans la copie envoyée par courriel.
6. Choisissez comment l’annonce part : **📧 Envoyer par courriel**, **📱 Envoyer par SMS**, **🔔 Notification dans l’application**,
   et — pour les directeurs et les administrateurs de l’agence — **📞 Appel vocal**. Courriel et notification
   dans l’application sont cochés par défaut.
7. Pour l’envoyer plus tard, ouvrez **Planifier pour plus tard (facultatif)** et choisissez une date et une heure, dans
   le fuseau horaire de votre agence. Elle part dans la minute qui suit cette heure, par tous les canaux
   cochés — appels téléphoniques compris — et affiche **⏱ Planifiée** dans la liste d’ici là.
8. Appuyez sur **Envoyer**.

## Choisir qui la reçoit

| Qui | Destinataires |
|---|---|
| **Tous les parents** | Tous les parents ayant un enfant à l’endroit choisi. C’est la valeur par défaut. |
| **Tous les éducateurs** | Tous les éducateurs de cet endroit |
| **Tous les entrepreneurs** | Toutes les personnes désignées comme entrepreneurs |
| **Tous les administrateurs et directeurs** | Les administrateurs de l’agence et les directeurs de centre |
| **Tout le monde** | Parents, éducateurs, entrepreneurs, administrateurs et directeurs |

Lorsque vous envoyez à un seul centre, le personnel rattaché à un autre centre est exclu. Un avis
concernant un centre n’est pas une nouvelle pour un autre. Le personnel rattaché à l’agence dans son ensemble, sans
centre propre, la reçoit quand même.

> **Remarque :** Personne n’est entrepreneur tant que vous ne l’avez pas indiqué. Il n’existe pas de rôle d’entrepreneur. Sur l’écran **Personnel**, activez **Entrepreneur** pour chaque personne que le public « Tous les entrepreneurs » doit joindre. Avec le module RH, les deux restent alignés : choisir le type d’emploi **Contrat** dans le dossier RH d’une personne active **Entrepreneur**, et passer à un autre type le désactive. Activer **Entrepreneur** ici met son type RH à Contrat ; le désactiver remet un type Contrat à « non défini ».

[[show: #staff @ Contractor | Montrez-moi la colonne Entrepreneur]]

## Images et messages texte

L’image est envoyée avec le message texte. Les grandes photos sont redimensionnées automatiquement pour
que les opérateurs les acceptent. Lorsqu’un téléphone ne peut pas recevoir de message avec image, le texte
arrive avec un lien vers l’image à la place.

Les SMS sont envoyés en texte brut; restez donc bref. Les textos ne sont envoyés qu’aux personnes dont un numéro de cellulaire
est au dossier, et seulement lorsque l’envoi de textos de votre agence est configuré. Voir
[Paramètres des opérateurs](carrier-settings).

## L’envoyer sous forme d’appel téléphonique

Cochez **📞 Appel vocal** pour faire aussi sonner les téléphones et lire l’annonce à voix haute —
« Annonce de *votre centre* », puis le titre et le message (jusqu’à 800 caractères).

- [ ] Cochez **📞 Appel vocal** et choisissez le **Motif de l’appel**.
- [ ] Gardez la formulation courte et simple : elle est lue par une voix synthétique.
- [ ] Appuyez sur **Envoyer**, puis sur **Passer les appels** pour confirmer. Les appels ne peuvent pas être annulés.

Seuls les motifs que votre agence autorise sont proposés (**Paramètres → Paramètres des opérateurs → Appels
vocaux**). Si les appels sont désactivés pour votre agence, la case l’indique et ne peut pas être cochée.
Les mêmes règles que pour les [Appels d’annonce](voice-announcements) s’appliquent à chaque personne : quiconque
a désactivé ce motif, ou choisi **Ne pas me téléphoner du tout**, est ignoré; un
non-urgent ne joint que les personnes qui ont accepté d’être contactées; un téléphone partagé ne sonne qu’une fois.
La ligne de résultat indique combien de personnes sont appelées et combien ont été ignorées.

> **Remarque :** Une annonce planifiée avec Appel vocal coché fait sonner les téléphones à l’heure planifiée, et non au moment où vous appuyez sur Envoyer.

??? Pourquoi est-ce que je ne vois pas Appel vocal? | Il est réservé aux directeurs de centre et aux administrateurs de l’agence, soit les mêmes personnes qui peuvent passer des appels depuis la diffusion SMS.
??? Pourquoi Appel vocal est-il grisé? | Les appels sont désactivés pour votre agence, ou aucun motif d’appel n’est autorisé. Un administrateur de l’agence définit les deux dans Paramètres → Paramètres des opérateurs → Appels vocaux.

## Comment les destinataires la reçoivent

- **Dans l’application** : une notification, plus l’annonce dans leur fil (**Nouvelles** pour les parents
  et les éducateurs).
- **Courriel** : la version mise en forme, envoyée depuis l’adresse de votre agence lorsqu’elle est configurée.
- **SMS** : la version en texte brut, seulement si vous avez coché **📱 Envoyer par SMS**.
- **Appel téléphonique** : l’annonce lue à voix haute, seulement si vous avez coché **📞 Appel vocal**.

> Qui peut l’utiliser : **Directeur de centre** (ses centres), **Administrateur de l’agence** (toute l’agence).

Voir aussi : [Diffusion SMS](sms-broadcast), [Paramètres des opérateurs](carrier-settings),
[Boîte de notifications](notifications-inbox).
