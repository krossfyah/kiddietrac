---
title: Safe Arrival — chaque enfant prévu, pris en compte
category: Operations
order: 5
roles: agency_admin, centre_director, platform_admin
---
# Safe Arrival — chaque enfant prévu, pris en compte

Safe Arrival s’assure qu’aucun enfant attendu aujourd’hui ne passe inaperçu. Si un enfant
n’est pas arrivé à l’heure de dépôt prévue et que personne n’a signalé
d’absence, KiddieTrac interroge les parents. S’ils ne répondent pas, il peut leur **téléphoner**,
puis il alerte les éducateurs de la salle et le directeur — et le tableau **Safe Arrival**
indique qui doit encore être appelé.

[[open: #safe-arrival | Ouvrir Safe Arrival]]

## Comment ça fonctionne

1. Toutes les 5 minutes, KiddieTrac examine chaque enfant **prévu aujourd’hui** dans un
   centre **ouvert aujourd’hui**.
2. Lorsque **l’heure de dépôt prévue de l’enfant + le délai de grâce** est dépassée, qu’il n’est
   pas arrivé et qu’aucune absence n’a été signalée, les parents sont interrogés une fois : *« Maya
   est-elle arrivée? »* — par les canaux choisis par chaque parent (application, courriel, texto). Ils peuvent toucher
   **Absent aujourd’hui** dans l’application.
3. Si l’appel téléphonique est activé et qu’il n’y a toujours pas de réponse après le **délai d’appel**,
   les parents reçoivent un appel automatisé (voir ci-dessous).
4. S’il n’y a toujours ni arrivée ni absence après le **délai d’escalade**, les éducateurs
   de la salle et le directeur du centre reçoivent une alerte qui ouvre ce tableau.
5. Dès que l’enfant est enregistré à son arrivée ou qu’une absence est signalée, la vérification se ferme
   d’elle-même.

Rien n’est vérifié un jour où l’enfant n’est pas prévu, ni lorsque le centre est fermé.

## L’activer

Safe Arrival est **désactivé** tant qu’un administrateur de l’agence ne l’active pas, car il envoie des messages aux parents.

- [ ] Ouvrez **Safe Arrival** et appuyez sur **⚙️ Paramètres**.
- [ ] Cochez **Vérifier que chaque enfant prévu arrive**.
- [ ] Réglez le nombre de minutes après l’heure prévue avant d’interroger les parents (15 par défaut).
- [ ] Réglez le temps d’attente des parents avant d’alerter le personnel (20 par défaut).
- [ ] Réglez l’heure prévue pour les enfants qui n’en ont pas (9 h 00 par défaut).
- [ ] Facultatif : cochez **Téléphoner aux parents s’ils ne répondent pas**, et réglez **Appeler ce nombre de minutes après l’interrogation des parents** (10 par défaut).
- [ ] Enregistrez.

[[show: #safe-arrival @ Settings | Montrez-moi le bouton des paramètres]]

> **Astuce :** Donnez à chaque enfant sa propre heure de dépôt dans son dossier (**Heures habituelles**). Les enfants qui n’en ont pas sont vérifiés selon l’heure par défaut, et le tableau les marque « (par défaut) ».

Lorsque Safe Arrival est activé, il remplace l’ancien rappel de 9 h 30 « votre enfant est-il arrivé? »
pour votre agence; les parents reçoivent donc un message au bon moment au lieu de deux.

## L’appel téléphonique

L’appel téléphonique est **désactivé** tant qu’un administrateur de l’agence ne coche pas **Téléphoner aux parents s’ils ne répondent pas**. Il exige que les appels vocaux soient configurés pour votre agence (Paramètres → **Paramètres des opérateurs** → **Appels vocaux**).

Un appel automatisé nomme l’enfant et demande au parent d’appuyer sur une touche :

- **1 — absent aujourd’hui.** Consigné comme l’absence du jour, exactement comme **Absent aujourd’hui**, donc visible dans le calendrier et la liste. La vérification se ferme.
- **2 — en route, arrivera en retard.** Noté sur la vérification. L’enfant est toujours attendu. S’il n’est toujours pas enregistré à l’heure d’escalade (comptée à partir de la réponse du parent), le personnel est alerté malgré tout.
- **3 — déjà déposé.** L’enfant n’est **pas** enregistré sur la seule parole du parent. À la place, la salle, le directeur et les administrateurs reçoivent une alerte **urgente** — *« 🚨 Safe Arrival : Maya a été déposée mais n’est PAS enregistrée »* — pour retrouver l’enfant et l’enregistrer. Si l’alerte n’atteint le téléphone de personne, elle est aussi envoyée par courriel.

Quelle que soit la touche pressée, les éducateurs de la salle, les directeurs du centre et les administrateurs de l’agence sont avisés, et c’est consigné dans le journal d’audit. Seule la première réponse compte; si un autre tuteur ou un membre du personnel s’en est déjà occupé, la personne qui appelle entend que l’enfant est déjà pris en compte.

Les tuteurs sont appelés **un à la fois, le principal d’abord**. Si un appel se termine sans qu’aucune touche ne soit pressée, le tuteur suivant est appelé. Les connexions de ramassage ne sont jamais appelées, pas plus que quiconque a refusé les appels téléphoniques.

## Texte de l’appel et options

Dans **⚙️ Paramètres**, ouvrez **📞 Appel téléphonique — texte et options** :

- **Offrir 2 (en route)** et **Offrir 3 (déposé)** — le 1 est toujours offert. Décochez une option pour l’omettre de l’appel.
- **Qui est appelé** — **Chaque tuteur à tour de rôle, le principal d’abord**, ou **Tuteur principal seulement**.
- **Poser la question** — une fois, 2 fois ou 3 fois si aucune touche n’est pressée.
- **Attendre une touche (secondes)** — de 5 à 30 (10 par défaut).
- **Le texte** — l’ouverture, chaque phrase « appuyez », et ce que le parent entend après chaque touche, après l’absence de touche, après une mauvaise touche et lorsque l’enfant est déjà pris en compte. Laissez une case vide pour utiliser le texte standard, affiché en gris. Utilisez **{child}**, **{child_full}**, **{centre}**, **{room}** et **{time}**; ils sont remplis à chaque appel.

Les touches signifient toujours la même chose — 1 absent, 2 en route, 3 déposé — quel que soit le texte.

**▶ Jouer un appel test sur mon téléphone** fait sonner le numéro de cellulaire de **votre propre profil** avec le texte tel qu’il est dans les cases en ce moment, avant l’enregistrement, en utilisant « Test Child ». Appuyez sur une touche pour entendre la réponse. Rien n’est consigné pour aucun enfant. **Rétablir le texte standard** vide les cases.

## Le tableau

**Requiert de l’attention** énumère d’abord les enfants en retard — un parent qui dit **déposé — PAS enregistré** tout en haut, puis ceux pour qui le personnel a déjà été alerté — avec le nom de chaque tuteur et un numéro de téléphone à toucher pour appeler. Un enfant dont le parent a pressé 2 affiche **En route (parent)**. Chaque carte indique quand les parents ont été appelés et ce qu’ils ont répondu. Fermez chacun :

- **Consigner une absence** — avec un motif. Elle est enregistrée comme l’absence du jour, exactement comme le
  « Absent aujourd’hui » d’un parent, de sorte que la liste et les rappels la voient.
- **Parent joint** — avec une note, p. ex. *« Parlé à la maman à 9 h 40, arrive à 10 h 15 »*.
- **Autre…** — tout autre cas, avec une note.

**Tous les autres aujourd’hui** montre qui est arrivé (et à quelle heure), qui est absent et pourquoi, qui
a été pris en compte et qui n’est pas encore attendu. Le tableau s’actualise chaque minute.

Les éducateurs voient leurs propres salles, les directeurs leurs centres, et les administrateurs de l’agence tous les centres
(avec un filtre par centre).

## Le dossier

Chaque vérification conserve le moment où les parents ont été interrogés, le moment où ils ont été appelés et la touche pressée, le moment où le personnel a été alerté, et la façon dont elle a été
fermée, par qui et avec quelle note. La fermeture d’un cas et chaque réponse téléphonique sont consignées dans le journal d’audit.

## Questions

??? Pourquoi un enfant n’a-t-il pas été vérifié aujourd’hui? | Il n’est pas prévu aujourd’hui (vérifiez ses jours d’inscription), le centre est fermé (jour férié ou fermeture au calendrier), ou une absence a déjà été signalée.
??? Un parent dit n’avoir pas reçu le message. | Les messages suivent les choix de chaque parent dans son profil. Vérifiez qu’il a installé l’application avec les notifications activées, ou qu’il a une adresse courriel, ou qu’il a accepté les textos.
??? Un parent n’a pas été appelé. | Vérifiez que l’appel téléphonique est activé, que les appels vocaux sont configurés, que le parent a un numéro de téléphone au dossier et n’a pas refusé les appels, et si **Tuteur principal seulement** est sélectionné.
??? L’appel test n’a pas sonné. | Ajoutez votre numéro de cellulaire à votre propre profil. Le test ne peut pas appeler un numéro qui a refusé les appels.
??? Les éducateurs peuvent-ils le désactiver? | Non. Seul un administrateur de l’agence peut modifier les paramètres ou faire un appel test.

Voir aussi : [Textos et appels téléphoniques](texts-and-phone-calls).
