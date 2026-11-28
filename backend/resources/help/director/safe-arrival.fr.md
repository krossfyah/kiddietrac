---
title: Safe Arrival — chaque enfant prévu, bien arrivé
---
# Safe Arrival — chaque enfant prévu, bien arrivé

Safe Arrival veille à ce qu’aucun enfant attendu aujourd’hui ne passe inaperçu. Si un enfant
n’a pas été pointé à son heure d’arrivée prévue et que personne n’a signalé d’absence,
KiddieTrac le demande aux parents. S’ils ne répondent pas, il peut **les appeler**, puis il
alerte les éducateurs de la salle et le directeur — et le tableau **Safe Arrival** montre qui
doit encore être appelé. En fin de journée, il peut aussi vérifier chaque enfant encore
pointé. Ouvrez-le dans le menu latéral : **Opérations** → **Safe Arrival**.

> ℹ️ Safe Arrival est inclus dans le module **SMS & voice**. Sans ce module, les administrateurs de l’agence le voient grisé avec un badge **Upgrade**.

[[open: #safe-arrival | Ouvrir Safe Arrival]]

## Comment ça fonctionne

1. Toutes les 5 minutes, KiddieTrac examine chaque enfant **prévu aujourd’hui** dans un centre **ouvert aujourd’hui**.
2. Quand l’**heure d’arrivée prévue + le seuil d’arrivée** est passée, que l’enfant n’est pas pointé et qu’aucune absence n’a été signalée, on demande une fois aux parents : *« Maya est-elle arrivée ? »* — par les moyens choisis par chaque parent (application, courriel, texto). Ils peuvent toucher **Not attending today** dans l’application.
   Chaque message à une famille vient **du nom de votre agence** (un texto commence par *« Sunny Days Child Care : Maya était attendue à... »*, le courriel vient de votre agence, l’appel dit *« un appel Safe Arrival de Sunny Days Child Care »*), et une éducatrice n’est jamais nommée que par son **prénom** (un service de garde nommé d’après sa responsable devient *« le service de garde de Maria »*).
3. Si l’appel téléphonique est activé et qu’il n’y a toujours pas de réponse, les parents reçoivent un appel automatisé (voir plus bas).
4. S’il n’y a toujours ni pointage ni absence, les éducateurs de la salle — et les directeurs ou administrateurs choisis — reçoivent une alerte qui ouvre ce tableau.
5. Dès que l’enfant est pointé, coché dans un comptage, ou qu’une absence est signalée, la vérification se ferme d’elle-même.

Rien n’est vérifié un jour où l’enfant n’est pas prévu, ni quand le centre est fermé.

## L’activer

Safe Arrival est **désactivé** tant qu’un administrateur de l’agence ne l’active pas, parce qu’il envoie des messages aux parents.

1. Ouvrez **Safe Arrival** et appuyez sur **⚙️ Paramètres**. La fenêtre **Paramètres d’arrivée sécuritaire** s’ouvre.
2. Cochez **Vérifier que chaque enfant prévu arrive**.
3. Réglez le **⏱ Seuil d’arrivée** — combien de minutes après l’heure d’arrivée prévue on demande aux parents (15 par défaut).
4. Réglez le délai d’attente de la réponse des parents avant d’alerter les éducateurs de la salle (20 par défaut).
5. Au besoin, cochez **Téléphoner aux parents s’ils ne répondent pas**, et réglez **Appeler ce nombre de minutes après que les parents ont été avertis** (10 par défaut).
6. Réglez l’**Heure prévue pour un enfant qui n’en a pas** (9 h 00 par défaut).
7. Sous **Qui est alerté**, cochez **Les directeurs du centre de l’enfant** ou **Tous les administrateurs de l’agence**, choisissez **Quand les directeurs/administrateurs sont alertés**, **Envoyer un courriel aux directeurs/administrateurs** et s’il faut **Also text them (SMS)**.
8. Appuyez sur **Enregistrer**.

[[show: #safe-arrival @ Settings | Me montrer le bouton des paramètres]]

> 💡 Donnez à chaque enfant sa propre heure d’arrivée dans son dossier (**Heures habituelles**). Les enfants sans heure sont vérifiés selon l’heure par défaut, et le tableau les marque **(default)**.

D’autres choix dans la même fenêtre :

- **Commencer à vérifier un nouvel enfant le jour suivant son ajout** (activé par défaut), pour qu’une famille encore en inscription ne reçoive pas de texto « où est votre enfant ? » dès le premier jour.
- **Comptages** — **A child ticked in a headcount counts as arrived**, ou **Ignore headcounts. Only a sign-in counts**, et la durée pour **Maintenir le décompte pendant**.
- **💛 Not here after every check** — **Mark absent and email the family** : **After the last alert, if still not here** (60 minutes par défaut), **At the centre's closing time**, ou **Never (leave it for staff)**. La famille reçoit un court courriel bienveillant indiquant que l’enfant a été marqué absent, avec la façon de vous joindre.
- **⏰ When a parent says they're running late** — qui est avisé quand un parent signale une arrivée ou un départ en retard. Personne n’est questionné ni alerté avant cette heure.

Quand Safe Arrival est activé, il remplace l’ancien rappel de 9 h 30 « votre enfant est-il
arrivé ? » : les parents reçoivent un seul message, au bon moment, au lieu de deux.

## L’appel téléphonique

L’appel est **désactivé** tant qu’un administrateur de l’agence ne coche pas **Téléphoner aux parents s’ils ne répondent pas**. Il exige que les appels vocaux soient configurés pour votre agence (Paramètres → **Paramètres des opérateurs** → **Appels vocaux**). L’appel automatisé nomme l’enfant et demande au parent d’appuyer sur une touche :

- **1 — absent aujourd’hui.** Inscrit comme absence du jour, exactement comme **Not attending today**. La vérification se ferme.
- **2 — en route, arrivera en retard.** Le tableau affiche **On the way (parent)**. Si l’enfant n’est toujours pas pointé à temps, le personnel est alerté malgré tout.
- **3 — déjà déposé.** L’enfant n’est **pas** pointé sur la seule parole du parent. La salle, le directeur et les administrateurs reçoivent une alerte **urgente** — *« 🚨 Safe Arrival : Maya déposée mais PAS pointée »* — pour trouver l’enfant et le pointer. Si l’alerte n’atteint le téléphone de personne, elle est aussi envoyée par courriel.

Quelle que soit la touche, le personnel est avisé et c’est inscrit au journal d’audit. Seule la première réponse compte. Les tuteurs sont appelés **un à la fois, le principal d’abord**; les comptes de ramassage ne sont jamais appelés, pas plus que les personnes qui ont refusé les appels.

## Formulation et options de l’appel

1. Dans **⚙️ Paramètres**, ouvrez **📞 Appel téléphonique — formulation et options**.
2. Décochez **Offer 2 (on the way)** ou **Offer 3 (dropped off)** pour retirer un choix (le 1 est toujours offert).
3. Choisissez **Qui est appelé** (**Each guardian in turn, primary first** ou **Primary guardian only**), **Poser la question** (une, 2 ou 3 fois), **Wait for a key (seconds)** (5 à 30) et **Avant d’appeler le tuteur suivant** (1 minute par défaut).
4. Changez les zones de texte si vous le voulez. Laissez une zone vide pour utiliser la formulation standard affichée en gris. Utilisez **{child}**, **{child_full}**, **{agency}** (qui appelle : le nom de votre agence), **{centre}**, **{room}** et **{time}**.
5. Appuyez sur **▶ Jouer l’appel d’essai sur mon téléphone** pour l’entendre au numéro de cellulaire de votre propre profil, avec « Test Child ». Rien n’est consigné. **Rétablir la formulation standard** vide les zones.
6. Appuyez sur **Enregistrer**.

## Utiliser le tableau

1. Regardez les tuiles : **ATTENDUS AUJOURD’HUI**, **ARRIVED**, **ABSENT**, **NOT DUE YET** et **NEEDS ATTENTION**. Utilisez **Tous les centres** pour choisir un centre et **↻ Actualiser** pour mettre à jour (le tableau s’actualise aussi chaque minute).
2. Sous **Requiert de l’attention**, les enfants en retard sont listés en premier — **dropped off — NOT signed in** tout en haut — avec le nom de chaque tuteur et un numéro **📞** à toucher pour appeler, et l’heure où les parents ont été questionnés et appelés.
3. Fermez chaque cas avec **Consigner une absence** (avec une raison — inscrite comme absence du jour), **Parent joint** (avec une note, p. ex. *« Parlé à la mère à 9 h 40, arrive à 10 h 15 »*), **Autre** (avec une note), ou **⏰ Late drop-off** pour indiquer quand l’enfant arrivera.
4. **📞 Phone messages from parents** et **🎫 Temporary pickups** apparaissent quand il y a quelque chose à traiter.
5. **Tous les autres aujourd’hui** montre qui est arrivé, qui est absent et pourquoi, et qui n’est pas encore attendu.

## Fin de journée — toujours présent

1. Dans **⚙️ Paramètres**, cochez **Fin de journée : vérifier chaque enfant encore pointé**.
2. Réglez le **⏱ Seuil de départ**, **Then alert directors/admins after (minutes)**, **Then ask the parents after (minutes)** et l’**Heure de fermeture pour un centre qui n’en a pas**. L’heure de départ propre à l’enfant (**Heures habituelles**) est utilisée si elle existe.
3. Sur le tableau, **🕔 Fin de journée — toujours présent** liste les enfants dont l’heure de départ est passée. Fermez chaque cas avec **Parti à** (fait sortir l’enfant), **Toujours là**, **⏰ Retard de ramassage** ou **Autre**.

## Historique

Ouvrez **🕘 Historique** pour les vérifications passées, jusqu’à 92 jours à la fois : choisissez **De** et **À**, **Arrivée + fin de journée** (ou **Arrivée seulement**, **Fin de journée seulement**), un centre, le nom d’un enfant, et **Only ones that alerted staff**. Chaque vérification garde l’heure où les parents ont été questionnés et appelés, la touche choisie, l’heure où le personnel a été alerté, et comment elle a été fermée, par qui et avec quelle note.

## Qui peut faire quoi

- Les **administrateurs de l’agence** voient tous les centres et sont les seuls à voir **⚙️ Paramètres**, à changer les réglages ou à faire un appel d’essai.
- Les **directeurs de centre** voient et ferment les vérifications de leurs propres centres.
- Les **éducateurs** voient et ferment les vérifications de leurs propres salles.
- Les **parents** répondent par l’application, par courriel, par texto ou par téléphone, et peuvent signaler une absence ou une arrivée en retard.

## FAQ et dépannage

??? Pourquoi un enfant n’a-t-il pas été vérifié aujourd’hui ? | Il n’est pas prévu aujourd’hui (vérifiez ses jours d’inscription), le centre est fermé (congé ou fermeture au calendrier), une absence a déjà été signalée, ou il a été ajouté aujourd’hui et commence demain.
??? Un parent dit ne pas avoir reçu le message. | Les messages suivent les choix de chaque parent dans son profil. Vérifiez qu’il a l’application avec les notifications activées, une adresse courriel, ou qu’il a accepté les textos.
??? Un parent n’a pas été appelé. | Vérifiez que l’appel téléphonique est activé, que les appels vocaux sont configurés, que le parent a un numéro de téléphone et n’a pas refusé les appels, et si **Primary guardian only** est choisi.
??? L’appel d’essai n’a pas sonné. | Ajoutez votre numéro de cellulaire à votre propre profil. L’essai ne peut pas appeler un numéro qui a refusé les appels.
??? Une carte indique « ⚠️ No phone alert reached: … (emailed instead) ». | Aucune notification push n’a atteint ces personnes : elles ont reçu un courriel. Demandez-leur d’activer les notifications dans l’application KiddieTrac.
??? Les éducateurs peuvent-ils le désactiver ? | Non. Seul un administrateur de l’agence peut changer les réglages ou faire un appel d’essai.

## Liens utiles

[[open: #safe-arrival | Safe Arrival]]
[[open: #headcounts | Comptages]]
[[open: #late-pickups | Retards de départ]]

- [Textos et appels téléphoniques — choisir ce que vous recevez](texts-and-phone-calls)
- [Comptages et superviseur par intérim](headcounts-and-acting-supervisor)
- [Retards de départ et frais](late-pickup-fees)
- [Sortie automatique](auto-sign-off)
