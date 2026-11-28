---
title: Rôles personnalisés et permissions
---
# Rôles personnalisés et permissions

**Rôles et permissions** montre ce que chaque rôle de votre agence peut faire, sous forme de liste de permissions regroupées par domaine. Les rôles intégrés de KiddieTrac (Agency admin, Centre director, Educator, Home visitor, Parent / Guardian, Auditor et les autres) portent un badge gris **SYSTEM**. Quand votre agence a besoin d'un autre ensemble, par exemple une éducatrice responsable ou un commis comptable, vous créez un rôle **CUSTOM** et cochez les permissions qu'il doit avoir. Voir [[roles-and-responsibilities]] pour le rôle de chaque rôle intégré.

[[open: #admin-roles | Ouvrir Rôles et permissions]]

## Lire un rôle

1. Ouvrez **Settings › Rôles et permissions** (🛂 dans le menu).
2. Choisissez un rôle dans la liste **Rôles** à gauche. Chacun montre son nombre de permissions et, pour les rôles personnalisés, s'il couvre **un centre** ou **toute l'agence**. Agency admin s'ouvre en premier.
3. Lisez le panneau de droite : le nom du rôle, sa description et, sous **Autorisations**, chaque permission regroupée par domaine (Centres, Families & children, Staff, Daily care, Attendance & kiosk, Medical, Communications, Invoicing & billing, Reports, Agency settings et plus). Chaque permission montre sa clé, comme `centres.edit`, et ce qu'elle permet.
4. Lisez le compteur au bas (« … / … permissions selected ») : combien sont cochées sur l'ensemble des permissions.

> ℹ️ Les rôles intégrés sont gérés par KiddieTrac et montrent ce que chaque rôle peut faire aujourd'hui. Leurs cases sont grisées pour les administrateurs d'agence.

## Créer un rôle personnalisé à partir d'un rôle intégré

1. Choisissez le rôle intégré le plus proche de vos besoins, par exemple **Educator**.
2. Appuyez sur **Copy as custom role**.
3. Tapez le nom du rôle (par exemple « Éducatrice responsable ») et appuyez sur **Create**. Le nouveau rôle commence avec les mêmes permissions.
4. Cochez ou décochez des permissions. Utilisez **Toggle all** à côté d'un domaine pour tout cocher ou tout effacer d'un coup.
5. Appuyez sur **Enregistrer les modifications**. Un message **Role saved** s'affiche et le compteur de la liste se met à jour.

## Créer un rôle personnalisé de zéro

1. Appuyez sur **+ Nouveau** en haut de la liste **Rôles**.
2. Tapez un nom et appuyez sur **Create**.
3. Choisissez **Based on** (le rôle intégré le plus proche, ou **— none —**).
4. Choisissez **Covers** : **One centre** ou **Toute l’agence**. Toute l'agence est réservée aux rôles basés sur Agency admin; pour toute autre base, l'écran l'indique et garde **One centre**.
5. Cochez ses permissions, ajoutez une description au besoin et appuyez sur **Enregistrer les modifications**.

## Renommer ou supprimer un rôle personnalisé

1. Choisissez le rôle personnalisé et cliquez sur son nom en haut pour le modifier.
2. Appuyez sur **Enregistrer les modifications**.
3. Pour le retirer, appuyez sur **🗑 Delete role** au bas et confirmez. Le rôle est archivé; toute personne qui l'aurait eu aurait besoin d'un autre rôle.

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Administrateur d'agence | Lit tous les rôles; crée, modifie et supprime les rôles personnalisés de son agence. Ne peut pas changer les rôles intégrés. |
| Administrateur de la plateforme | Tout ce qui précède, peut ajuster les rôles intégrés d'une agence et voit l'onglet **🔎 Permission check**. |
| Direction de centre et autres | Ne voient pas cet écran. |

### La vérification des permissions (administrateurs de la plateforme)

L'onglet **🔎 Permission check** compare la liste des permissions avec ce que les gens ont réellement fait, sans rien bloquer. **Would block — must be zero before enforcing** liste les cas que la liste refuserait aujourd'hui. Utilisez **Turn off** ou **Turn on watching** pour arrêter ou lancer la surveillance.

## FAQ et quand quelque chose ne va pas

??? Pourquoi les cases sont-elles grisées? | Vous regardez un rôle intégré. Les rôles intégrés sont gérés par KiddieTrac. Appuyez sur **Copy as custom role** pour créer votre propre version.
??? Cocher une permission change-t-il ce que les gens peuvent faire aujourd'hui? | Pas encore. Les permissions sont enregistrées et surveillées, mais l'accès dépend toujours du rôle intégré de chaque personne. Servez-vous de la liste pour documenter ce que chaque rôle devrait faire.
??? Comment donner un rôle personnalisé à une personne? | Pas encore : le choix de rôle dans la gestion des utilisateurs n'offre que les rôles intégrés. Donnez-lui pour l'instant le rôle intégré le plus proche.
??? « Only a role based on Agency admin can cover the whole agency. » | Réglez **Based on** sur Agency admin, ou choisissez **One centre** dans **Covers**.
??? « Built-in roles are managed by KiddieTrac. » | Vous avez essayé d'enregistrer un rôle intégré. Copiez-le plutôt en rôle personnalisé.
??? « Could not load roles » s'affiche. | Votre session a pris fin ou aucune agence n'est choisie. Choisissez l'agence active dans la barre du haut et actualisez la page.
??? Quelqu'un est-il avisé quand je change un rôle? | Non. Les changements sont inscrits au journal d'audit; personne ne reçoit de courriel ni de notification.

## Écrans liés

[[open: #admin-users | Gestion des utilisateurs]] [[open: #audit-logs | Journal d'audit]]

- [[roles-and-responsibilities]]
- [[audit-log]]
