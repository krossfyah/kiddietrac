---
roles: agency_admin, platform_admin
title: Rôles personnalisés et permissions
category: Administration
order: 20
---
# Rôles personnalisés et permissions

KiddieTrac est fourni avec des rôles intégrés (système) : Administrateur de l’agence, Directeur du centre, Éducateur, Visiteur à domicile, Parent / tuteur et Auditeur (l’Admin de la plateforme se situe au-dessus des agences). Consultez [[roles-and-responsibilities]] pour savoir ce que fait chacun. Pour la plupart des agences, ces rôles suffisent : ils couvrent les types de personnel que l’on trouve dans la majorité des services de garde.

Lorsque vous devez modéliser des structures de personnel qui ne correspondent pas clairement à ces rôles — par exemple une Éducatrice principale qui peut signer les plans de leçon mais pas les factures, ou un Comptable qui peut produire des rapports mais pas modifier les centres —, vous créez un **rôle personnalisé** avec une matrice de permissions sur mesure.

## Où le trouver

Barre latérale → **Paramètres → Rôles et permissions**. Vous verrez un écran à deux colonnes : la liste des rôles à gauche, la matrice de permissions à droite.

## Lire la matrice

Le volet de droite affiche le nom et la description du rôle sélectionné, ainsi qu’une grille de 56 clés de permission regroupées par catégorie — Centres, Familles et enfants, Portée parent, Personnel, Salles, Facturation, Rapports, Incidents, Médical, Observations et curriculum, Présences et borne, Retards de ramassage, Tâches, Visites à domicile, Documents (y compris le Gestionnaire de formulaires), Communications (y compris les modèles de courriel), Événements et RSVP, Aide et formation, et Paramètres de l’agence.

Chaque permission comporte une courte étiquette en police à chasse fixe (p. ex. `centres.edit`) et une description en langage clair (p. ex. « Modifier les paramètres et l’image de marque du centre »). Cochez la case de chaque permission que le rôle doit avoir.

Sous chaque catégorie, cliquez sur **Tout basculer** pour inverser toutes les permissions de cette catégorie d’un coup — pratique pour « ce rôle a tout dans ce domaine, rien dans les autres ».

## Rôles système et rôles personnalisés

Les rôles système (avec l’insigne gris **SYSTÈME**) peuvent voir leurs permissions ajustées, mais leur nom, leur clé et leur existence ne peuvent pas être modifiés, car le code sous-jacent y fait référence. Les rôles personnalisés (avec l’insigne bleu **PERSONNALISÉ**) sont entièrement modifiables et supprimables.

## Créer un rôle personnalisé

1. Cliquez sur **+ Nouveau** en haut de la liste des rôles.
2. Saisissez un nom (p. ex. « Éducatrice principale »).
3. Le nouveau rôle apparaît sans aucune permission cochée. Cochez celles qu’il doit avoir.
4. Cliquez sur **Enregistrer les modifications**.

Le rôle est maintenant visible dans la fenêtre Gérer de Gestion des utilisateurs — attribuez-le aux utilisateurs comme vous le feriez pour un rôle système.

## Attribuer un rôle personnalisé à un utilisateur

Gestion des utilisateurs → cliquez sur **Gérer** pour l’utilisateur → dans la section Rôles, choisissez le rôle dans la liste déroulante → cliquez sur **Appliquer**. Les rôles sont cumulatifs : ajouter un nouveau rôle ne retire pas les rôles existants.

## Isolation des données entre centres et agences

Les rôles déterminent aussi **les données que vous pouvez voir**, indépendamment de la matrice de permissions :

- **Administrateur de l’agence / Admin de la plateforme** voient tous les centres de leur **agence active** — par exemple, la liste **Enfants** affiche tous les enfants de tous les centres de l’agence, et le filtre de centre permet de restreindre l’affichage.
- **Directeur du centre / Éducateur** ne voient que les familles, les enfants, les salles et les rapports de **leur propre centre**.
- Les agences sont entièrement séparées : une nouvelle agence (ou un nouveau centre d’une autre agence) démarre avec ses propres paramètres et ne voit **jamais** les données d’une autre agence. Changez d’**agence active** à partir de la barre supérieure si vous administrez plus d’une agence.

Si vous voyez « agence introuvable », assurez-vous qu’une **agence active est sélectionnée** dans la barre supérieure.

## Remarque sur la phase A

Dans la version actuelle, les permissions enregistrées sur les rôles personnalisés sont consignées, mais pas encore appliquées : toutes les vérifications de routes existantes utilisent les clés de rôle d’origine. La phase B (un déploiement prévu) fera consulter la matrice de permissions par ces vérifications. D’ici là, considérez la matrice comme une documentation de ce que chaque rôle DEVRAIT pouvoir faire — utile à des fins de vérification.
