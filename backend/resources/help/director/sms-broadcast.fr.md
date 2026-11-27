---
title: Diffusion SMS
category: Communications
order: 40
---
# Diffusion SMS

Envoyez un texto ponctuel au personnel ou aux familles. Utilisez-le pour les urgences (fermetures, évacuations,
intempéries) lorsqu’un courriel n’est pas assez urgent.

[[open: #sms | Ouvrir la diffusion SMS]]

## Texto ou appel

Un commutateur **Envoyer par** — **💬 Message texte** ou **📞 Appel vocal** — se trouve en haut de l’écran. Tout ce qui se trouve en dessous — le
sélecteur de destinataires, la zone de message, l’historique des envois — fonctionne de la même façon dans les deux cas.

Un appel fait sonner le téléphone de la personne et lit à voix haute un court message, pour le seul type
d’avis dont vous ne pouvez pas présumer que quelqu’un l’a lu. Il est plus restreint qu’un texto quant aux personnes qu’il peut joindre.
Voir [Appels d’annonce](voice-announcements).

## Avant l’envoi

- [ ] **Un opérateur configuré et sélectionné** dans **Paramètres → Paramètres des opérateurs** (Twilio ou Telnyx; les appels vocaux passent par Telnyx). Les identifiants relèvent d’un administrateur d’agence — si vous êtes directeur de centre et que rien ne part, adressez-vous à lui. Voir [Paramètres des opérateurs](carrier-settings).
- [ ] **Notifications activées pour l’agence**, sous **Paramètres → Paramètres de messagerie**. C’est un interrupteur général multicanal : s’il est désactivé, rien ne part, même si un opérateur est parfaitement configuré.
- [ ] **Diffusions par texto** cochées sous **Types de textos envoyés par cette agence** dans l’écran des paramètres des opérateurs.

## Qui reçoit le message

Chaque personne doit :

- avoir un **numéro de cellulaire** dans son dossier;
- avoir **accepté de recevoir des textos** — elle dit oui lors de l’intégration, ou plus tard avec
  **J’accepte de recevoir des textos** dans son propre profil (voir
  [Textos et appels téléphoniques](texts-and-phone-calls));
- ne pas avoir désactivé les **Diffusions par texto** dans ses propres choix.

Les personnes qui ne remplissent pas ces conditions sont ignorées. Avant l’envoi, la ligne sous les destinataires indique combien de personnes peuvent être jointes, par exemple « 12 of 15 can be texted », et combien n’ont pas de cellulaire ou n’ont pas donné leur accord. Invitez les autres à s’inscrire à partir de l’onglet **Alertes par texto**.
Une diffusion qui ne joint personne le dit clairement plutôt que d’annoncer un succès.

## Envoi

1. Ouvrez **Diffusion SMS** sur l’onglet **Envoyer un message**.
2. Choisissez les **Destinataires** :
   - **Par rôle** — tuteur / éducateur / directeur de centre / administrateur d’agence
   - **Par centre** (ou prestataire, selon le terme de votre agence) — toutes les personnes ayant un
     rôle dans celui que vous choisissez
   - **Par salle** — les éducateurs affectés à cette salle, ainsi que les tuteurs des
     enfants actuellement inscrits dans cette salle
   - **Toute l’agence** — toutes les personnes ayant un rôle actif
3. Pour **par centre** ou **par salle**, choisissez lequel dans le sélecteur qui apparaît. Le
   message ne partira pas tant que vous ne l’aurez pas fait — un public qui ne peut pas être restreint est refusé plutôt que
   discrètement élargi à tout le monde.
4. Rédigez votre message — jusqu’à **300 caractères**. Indiquez de la part de qui il est (le nom de votre agence)
   et mettez la phrase importante en premier; les gens lisent l’aperçu.
5. Cliquez sur **Envoyer la diffusion**.

[[show: #sms @ Send broadcast | Montrez-moi le bouton d’envoi]]

## Numéros de téléphone partagés

Certaines familles utilisent un même téléphone pour deux comptes — deux parents avec un seul numéro, ou un parent qui
est aussi membre du personnel. KiddieTrac gère cela par **téléphone**, au sein de votre agence :

- **Une copie par téléphone.** Si trois comptes du même public partagent un numéro, le
  téléphone reçoit le message une seule fois, et non trois.
- **Le consentement suit le téléphone.** Si une personne associée à ce numéro a accepté de recevoir des textos, le
  téléphone peut recevoir des textos; le texto est attribué au compte qui a donné son accord.
- **STOP arrête le téléphone.** Un STOP envoyé depuis un appareil arrête les textos vers **tous** les comptes associés à ce
  numéro dans votre agence, pas seulement le plus récent. START les réactive tous.

## Quand les gens répondent

Une réponse à une diffusion ne se perd pas dans un journal d’opérateur. Elle arrive dans le **Centre de messages** comme une discussion
de cette personne (marquée comme fil SMS), le personnel concerné est avisé, et une réponse que vous écrivez
dans ce fil repart vers son téléphone sous forme de texto. Les réponses sont aussi listées dans l’onglet **Réponses**.

- **STOP** (aussi UNSUBSCRIBE, CANCEL, END, QUIT) arrête tous les textos de votre agence vers ce téléphone.
- **START** les réactive.
- **HELP** (ou INFO) renvoie le nom de votre agence et la façon de vous joindre.

## Coût

Chaque texto est facturé par votre opérateur par segment. Un message simple de 160 caractères ou moins correspond à
un segment; les messages plus longs, ou ceux qui contiennent des émojis ou des lettres accentuées, en utilisent davantage. Si
Telnyx est sélectionné, **Paramètres → Paramètres des opérateurs** affiche votre solde et ce qu’ont coûté les 30
derniers jours.

## L’historique

L’onglet **Envoyés et reçus** liste les textos récents dans les deux sens,
avec l’heure dans le fuseau horaire de votre agence, l’état de livraison et — en cas d’échec — la
raison fournie par l’opérateur. Chaque message est aussi consigné dans le journal d’audit.

[[show: #sms @ Sent & received | Montrez-moi l’historique des messages]]

## Questions

??? Pourquoi un parent a-t-il été ignoré? | Il n’a pas de numéro de cellulaire, n’a pas accepté de recevoir des textos, a désactivé pour lui-même les diffusions par texto, ou a répondu STOP depuis ce téléphone.
??? Un parent dit avoir reçu un seul texto pour deux comptes. Est-ce normal? | Oui. Les comptes qui partagent un téléphone reçoivent une seule copie.
??? Un parent a répondu STOP par erreur. | Demandez-lui d’envoyer START au même numéro. Cela réactive les textos pour tous les comptes associés à ce téléphone.

Voir aussi : [Appels d’annonce](voice-announcements), [Paramètres des opérateurs](carrier-settings),
[Textos et appels téléphoniques](texts-and-phone-calls).
