---
title: Extraction de documents par IA
---
# Extraction de documents par IA

**Marketing › AI doc extract** lit la photo ou la numérisation d'un document et en tire les champs importants (noms,
dates, numéros) pour vous éviter de les taper. C'est pratique pour les certificats d'un nouvel employé, les carnets
de vaccination, les vérifications d'antécédents, les pièces d'identité et les formulaires d'inscription papier. Vous
recopiez vous-même le résultat dans le bon dossier — rien n'est enregistré automatiquement.

[[open: #ai-docs | Ouvrir l'extraction de documents]]

## Lire un document

1. Prenez une photo ou une numérisation nette du document (PNG, JPEG ou WebP), à plat et bien éclairé.
2. Ouvrez **Marketing › AI doc extract**. Le titre indique **AI document extraction**.
3. Sous **Upload a file (image)**, choisissez l'image.
4. Choisissez le **Document type** : **Immunization record**, **Staff certification**, **Background / VSS check**, **ID document** ou **Enrollment form**.
5. Appuyez sur **Extract fields**. « Extracting… » s'affiche pendant la lecture, puis les champs apparaissent dans la boîte foncée, un par ligne (par exemple le nom, la date de délivrance et la date d'expiration).
6. Comparez chaque valeur au papier, puis tapez-la ou collez-la dans le dossier de l'enfant ou de l'employé.

> ⚠️ Vérifiez toujours le résultat avec l'original. Une écriture à la main, un reflet ou un bord coupé peut donner une mauvaise date ou un mauvais nom.

## Utiliser un lien au lieu d'un fichier

1. Laissez la case du fichier vide.
2. Collez un lien dans **…or a document URL (publicly reachable)**.
3. Choisissez le **Document type** et appuyez sur **Extract fields**.

> ⚠️ N'utilisez un lien que pour un document qui doit être public. Ne mettez jamais le document personnel d'un enfant ou d'un employé sur un lien public — téléversez plutôt le fichier.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d'agence | Utiliser l'outil. |
| Directeur de centre | Utiliser l'outil. |
| Administrateur de plateforme | Utiliser l'outil dans l'agence choisie dans le sélecteur. |

L'outil utilise l'IA. Le document est envoyé seulement pour être lu et obtenir les champs; KiddieTrac ne le joint à aucun dossier.

## Questions fréquentes et problèmes

??? J'obtiens « Error: … » dans la boîte. | L'image n'a pas pu être lue. Essayez une photo plus nette, un autre format (PNG ou JPEG) ou un fichier plus petit.
??? Certains champs sont vides. | L'outil ne les a pas trouvés sur le document. Tapez-les vous-même.
??? Le document est-il enregistré quelque part? | Non. Téléversez l'original dans le dossier de la personne si vous devez le garder.
??? L'élément de menu n'apparaît pas. | Les outils d'IA dépendent de votre forfait. Demandez à votre administrateur d'agence.

## Écrans connexes

[[open: #compliance | Tableau de bord de conformité]]

Voir aussi [[immunization-report]] et [[staff-training-and-checks]].
