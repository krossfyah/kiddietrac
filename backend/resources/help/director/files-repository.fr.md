---
title: Dépôt de fichiers
category: Administration
order: 37
roles: agency_admin, platform_admin
---
# Dépôt de fichiers

**Ventes → Dépôt de fichiers.** Une étagère de référence pour l’équipe des ventes : grilles tarifaires, présentations, modèles de contrat et documents de licence. Elle appartient à KiddieTrac lui-même, et non à une agence.

[[open: #sales-library | Ouvrir le Dépôt de fichiers]]

## Qui peut l’utiliser

**Les administrateurs de la plateforme (superadmins) seulement.** Elle se trouve dans la section **💼 Ventes** de la barre latérale, que seul un administrateur de la plateforme possède, et c’est aussi une tuile de l’écran d’accueil des Ventes pour eux.

Les représentants des ventes ne la voient **pas**, même s’ils travaillent dans les Ventes. L’étagère contient des documents de tarification et de contrat, et le serveur refuse toute personne qui n’est pas administrateur de la plateforme. Un administrateur d’agence ne la voit jamais non plus.

## La disposition

- Les **dossiers** s’alignent à gauche. **Tous les fichiers**, en haut, affiche tout, quel que soit le dossier; chaque dossier indique le nombre de fichiers qu’il contient.
- **L’étagère**, au centre, liste les fichiers : **Fichier**, **Type**, **Dossier**, **Catégorie**, **Taille** et **Ajouté** (date, heure et personne qui l’a ajouté).
- La recherche et les en-têtes de colonnes triables sont les mêmes que dans tous les autres tableaux du portail. Taille et Ajouté se trient selon le nombre et l’heure réels, et non par ordre alphabétique.

La colonne **Fichier** affiche le nom réel du fichier avec son extension, avec le titre en dessous s’il diffère, puis les notes. La colonne **Type** commence par l’extension (PDF, DOCX, PPTX…) et nomme le format en toutes lettres en dessous.

## Ajouter un fichier

1. Choisissez d’abord le dossier voulu, si vous le souhaitez, afin que la boîte de dialogue s’ouvre dessus.
2. **＋ Ajouter un fichier**.
3. Choisissez le fichier. Jusqu’à **25 Mo** : un PDF, une présentation, un chiffrier ou une image.
4. Remplissez le reste :
   - **Dossier** : l’endroit où il sera rangé. *Tous les fichiers (aucun dossier)* est permis.
   - **Titre** : rempli automatiquement à partir du nom du fichier (sans l’extension).
   - **Catégorie** : choisissez-en une déjà utilisée, ou **＋ Nouvelle catégorie…** pour en saisir une nouvelle.
   - **Notes** : à quoi sert le fichier et à qui il s’adresse.
5. **Ajouter au dépôt**.

[[show: #sales-library @ Add a file | Montrez-moi le bouton Ajouter un fichier]]

> **Plusieurs à la fois :** dans le sélecteur de fichiers, sélectionnez autant de fichiers que vous voulez (Ctrl ou Maj, ⌘ sur un Mac), ou choisissez de nouveau pour en ajouter d’autres. Le dossier, la catégorie et les notes s’appliquent à tous, et chaque fichier garde son propre nom comme titre. Si certains échouent, ils restent listés avec la raison, et **Ajouter au dépôt** réessaie seulement ceux-là.

> **Astuce :** Nommez le fichier selon votre convention avant de le téléverser, p. ex. `KT-2026-Q4-RateCard-v3.pdf`. Le titre reprend ce nom, donc vous n’avez jamais à le retaper.

Le titre n’est rempli automatiquement que tant que vous n’y avez pas écrit vous-même. Une fois le titre modifié, choisir un autre fichier ne l’écrasera pas.

La catégorie est une liste déroulante volontairement. Une zone de texte libre est la façon dont une même étiquette finit orthographiée de trois manières (« Tarifs », « tarifs », « Grilles de prix »), et la colonne Catégorie cesse de regrouper quoi que ce soit.

Quelques types de fichiers qu’un serveur Web pourrait exécuter (par exemple `.php` ou `.sh`) sont refusés avec le message *« Ce type de fichier ne peut pas être stocké ici. »*

## La barre de téléversement

Pendant le téléversement d’un fichier, une barre foncée descend du haut de l’écran et affiche **Téléversement en cours** et le nom du fichier, le pourcentage et la quantité envoyée. Elle se termine par **Téléversement terminé** ou **Échec du téléversement**.

Cette barre n’est pas propre à cet écran. Tous les téléversements du portail l’affichent, y compris les pièces jointes de discussion, les photos, les fichiers d’incident et les documents électroniques. Lorsque le navigateur ne peut pas indiquer la taille, la barre affiche une bande animée au lieu d’un pourcentage.

## Dossiers

- **📁 Nouveau dossier** en crée un. Les noms sont uniques sans égard aux majuscules, donc « Tarifs » et « tarifs » sont le même dossier. Demander un dossier qui existe déjà vous en informe simplement.
- **Supprimer un dossier** (le **✕** à côté de son nom) ne supprime **pas** ses fichiers. Ils retournent dans **Tous les fichiers**, et la confirmation indique combien.

[[show: #sales-library @ New folder | Montrez-moi le bouton Nouveau dossier]]

## Déplacer, ouvrir et retirer un fichier

Chaque ligne a le menu **⋮** habituel :

- **Voir** ouvre le fichier.
- **📁 Déplacer vers un dossier** permet de choisir un autre dossier, ou *Tous les fichiers (aucun dossier)*. Il faut d’abord avoir au moins un dossier.
- **↩ Retirer du dossier** n’apparaît que sur un fichier rangé dans un dossier. Il remet le fichier au premier niveau.
- **🗑 Supprimer** retire le fichier de l’étagère. Le fichier stocké lui-même est conservé sur le serveur, mais aucun bouton ne permet de le récupérer; soyez donc certain avant de l’utiliser.

## Ce qui est consigné

Le téléversement, le déplacement et la suppression d’un fichier, ainsi que la création ou la suppression d’un dossier, écrivent chacun une ligne d’audit qui nomme le fichier ou le dossier. Ce sont des événements de niveau plateforme, non liés à une agence.

## Questions courantes

??? Un représentant des ventes peut-il voir ces fichiers? | Non. L’entrée de navigation, la tuile et le serveur s’arrêtent tous aux administrateurs de la plateforme.
??? Supprimer un dossier fait-il perdre les fichiers qu’il contient? | Non. Ils retournent dans Tous les fichiers.
??? Quelle est la taille maximale d’un fichier? | 25 Mo.

Voir aussi : [Administrateur de la plateforme (aperçu)](platform-admin-overview),
[Ouvrir et imprimer des documents](opening-and-printing-documents).
