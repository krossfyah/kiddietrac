---
title: Rôles personnalisés et permissions
---
# Rôles personnalisés et permissions

**Rôles et permissions** présente les rôles intégrés de KiddieTrac (Administrateur de l’agence, Directeur du centre, Éducateur, Visiteur à domicile, Auditeur et les autres) et les **rôles personnalisés** de votre agence. Un rôle personnalisé est votre propre titre de poste, par exemple *Éducatrice responsable* ou *Commis comptable*, **basé sur** un rôle intégré. Il commence avec tout ce que son rôle de base peut faire, et vous désactivez ce que ce poste ne doit pas faire. Ce que vous désactivez disparaît du menu de la personne et lui est refusé si elle essaie quand même. Voir [[roles-and-responsibilities]] pour le rôle de chaque rôle intégré.

[[open: #admin-roles | Ouvrir Rôles et permissions]]

> ℹ️ Un rôle personnalisé peut seulement retirer des choses. Les personnes et les centres qu’il couvre viennent toujours de son rôle de base : un rôle personnalisé basé sur Éducateur voit seulement les centres et les enfants auxquels cet éducateur est affecté, jamais plus.

## Lire la liste des rôles

1. Ouvrez **Paramètres › Rôles et permissions** (🛂 dans le menu).
2. Lisez le tableau : le **Type** de chaque rôle (Intégré ou Personnalisé), le rôle sur lequel un rôle personnalisé est **Basé**, ses **Permissions** (« 3 désactivées » ou « Tout ce que son rôle de base a ») et le nombre de **Personnes** qui l’ont.
3. Appuyez sur **⋮** sur un rôle intégré et choisissez **👁 Voir** pour voir toutes ses permissions, regroupées par domaine.

## Créer un rôle personnalisé

1. Appuyez sur **＋ Nouveau rôle personnalisé**, ou appuyez sur **⋮** sur le rôle intégré le plus proche du poste et choisissez **⧉ Copier comme rôle personnalisé**.
2. Tapez un **Nom** (par exemple « Éducatrice responsable ») et choisissez **Basé sur**. Administrateur de l’agence et Auditeur couvrent toute l’agence; Directeur du centre, Éducateur et Visiteur à domicile couvrent les centres où chaque personne est affectée.
3. Ajoutez une **Description** si vous voulez.
4. Sous **Ce que ce rôle peut faire**, décochez chaque permission que ce poste ne doit pas avoir. Utilisez **Tout activer / désactiver** à côté d’un groupe (Au quotidien, Enfants et familles, Soins quotidiens, Personnel et RH, Argent et les autres) pour changer tout le groupe d’un coup.
5. Regardez **Ce que cette personne verra** à droite : les éléments du menu que le rôle cache sont barrés en rouge.
6. Appuyez sur **Créer le rôle**. Un message **Rôle créé** apparaît et le rôle s’ajoute au tableau.

## Modifier, copier ou supprimer un rôle personnalisé

1. Appuyez sur **⋮** sur le rôle personnalisé.
2. Choisissez **✏️ Modifier** pour changer son nom, sa description ou ses permissions, puis appuyez sur **Enregistrer les modifications**. Les personnes qui l’ont reçoivent le nouveau menu au prochain rafraîchissement de leur écran.
3. Choisissez **👁 Aperçu du menu** pour voir le menu de ses titulaires, **⧉ Dupliquer** pour partir de ce rôle, ou **🗑 Supprimer** pour l’enlever.

> ⚠️ Un rôle que quelqu’un détient ne peut pas être supprimé, et son **Basé sur** ne peut pas changer. Donnez d’abord un autre rôle à ces personnes dans **Gestion des utilisateurs**.

## Donner un rôle personnalisé à quelqu’un

1. Ouvrez **Gestion des utilisateurs** et ouvrez la personne.
2. À côté de **Ajouter / définir un rôle**, choisissez le rôle personnalisé (marqué **★**, sous **Vos rôles personnalisés**) et, pour un rôle de centre, le **Centre**.
3. Appuyez sur **Appliquer**. La personne affiche une pastille **★** avec le nom du rôle. Le rôle personnalisé s’applique à chaque centre où elle a son rôle de base dans cette agence.
4. Pour le retirer, choisissez de nouveau le rôle intégré et appuyez sur **Appliquer**.

## Qui peut faire quoi

| Rôle | Ce qu’il peut faire |
|---|---|
| Administrateur de l’agence | Crée, modifie, copie et supprime les rôles personnalisés de son agence et les donne au personnel. Ne peut pas changer les rôles intégrés. |
| Administrateur de la plateforme | Tout ce qui précède, peut ajuster la description d’un rôle intégré et voit l’onglet **🔎 Vérification des permissions**. |
| Directeur du centre et les autres | Ne voient pas cet écran. |

### La vérification des permissions (administrateurs de la plateforme)

L’onglet **🔎 Vérification des permissions** liste ce qui a été refusé aux rôles personnalisés et compare les listes de permissions des rôles intégrés avec ce que les gens ont vraiment fait (les rôles intégrés sont observés, jamais bloqués). Utilisez **Arrêter l’observation** ou **Lancer l’observation** pour arrêter ou démarrer l’observation.

## FAQ et quand quelque chose ne va pas

??? Un rôle personnalisé change-t-il quelque chose pour les personnes qui ont un rôle intégré? | Non. Les rôles intégrés fonctionnent exactement comme avant. Seules les personnes qui ont un rôle personnalisé sont limitées.
??? Un rôle personnalisé peut-il faire plus que son rôle de base? | Non. Il peut seulement désactiver des choses. Pour donner plus à quelqu’un, donnez-lui un autre rôle intégré.
??? Quelqu’un voit « Your role … does not include: … ». | Son rôle personnalisé a cette permission désactivée. Modifiez le rôle et cochez-la, ou donnez-lui un autre rôle.
??? La personne a deux rôles. Lequel l’emporte? | Si elle a aussi un rôle intégré du personnel dans la même agence, l’accès complet de ce rôle intégré s’applique et le rôle personnalisé ne limite rien.
??? « … people hold … (counting deactivated accounts). » | Le rôle ne peut pas être supprimé tant que quelqu’un le détient, même un compte désactivé. Donnez d’abord un autre rôle à ces personnes dans Gestion des utilisateurs, puis supprimez-le.
??? Quelqu’un voit « This part of KiddieTrac is not set up for custom roles yet ». | Un écran est nouveau et n’a pas encore de permission, donc les rôles personnalisés en sont exclus jusqu’à ce que KiddieTrac en ajoute une. Avisez le soutien; en attendant, donnez à la personne son rôle intégré si elle en a besoin tout de suite.
??? « Keep at least one permission ticked. » | Un rôle sans aucune case cochée n’ouvrirait rien. Cochez au moins une permission.
??? « Built-in roles are managed by KiddieTrac. » | Vous avez essayé de changer un rôle intégré. Copiez-le plutôt comme rôle personnalisé.
??? « Could not load roles » apparaît. | Votre session a pris fin ou aucune agence n’est choisie. Choisissez l’agence active dans la barre du haut et actualisez la page.
??? Quelqu’un est-il avisé quand je change un rôle? | Non. Chaque changement est inscrit au journal d’audit; personne ne reçoit de courriel ni d’avis.

## Écrans liés

[[open: #admin-users | Gestion des utilisateurs]] [[open: #audit-logs | Journal d'audit]]

- [[roles-and-responsibilities]]
- [[inviting-parents-and-staff]]
- [[audit-log]]
