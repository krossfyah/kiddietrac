---
roles: agency_admin, platform_admin
title: Flux de documents à plusieurs étapes
category: Administration
order: 65
---
# Flux de documents

Approbation de documents par plusieurs signataires, avec piste d’audit complète. Le parent signe → l’administrateur examine → le directeur contresigne, dans l’ordre.

## Créer un flux (administrateur)

1. Barre latérale → **Flux de documents**.
2. Touchez **+ Nouveau flux**.
3. Saisissez le titre, le type de document et le texte complet du document.
4. Indiquez la liste ordonnée des signataires (6 étapes au maximum). Chaque étape comporte :
   - **Rôle** (guardian / educator / centre_director / agency_admin)
   - **ID d’utilisateur précis** (facultatif — laissez vide pour permettre à tout utilisateur ayant ce rôle)
   - **Libellé du signataire** (texte affiché, comme « Signature du parent » ou « Contresignature du directeur »)
5. Enregistrez.

## Progression du flux

- Le signataire de l’étape 1 est avisé automatiquement.
- Il signe avec un pavé de signature (dessin sur canevas) et son nom.
- Le système consigne : l’image de la signature, l’utilisateur signataire, l’horodatage, l’adresse IP, le hachage SHA-256 de (document_text + user_id + timestamp).
- Le signataire de l’étape 2 est avisé automatiquement une fois l’étape 1 terminée.
- Et ainsi de suite jusqu’à la dernière étape.

## État final

Lorsque la dernière étape est signée :
- Le statut du flux passe à `complete`.
- L’initiateur reçoit une notification.
- Le document complet, chaque signature et chaque étape d’audit sont verrouillés.

## Ce que consigne chaque étape

- L’ordre de l’étape
- Le rôle de signataire exigé
- L’utilisateur ayant réellement signé
- L’horodatage de la signature
- L’image de la signature (PNG en base64)
- Le hachage de la signature (SHA-256)
- L’adresse IP du signataire
- Le libellé du signataire
- Des notes facultatives

## Quand l’utiliser

- Contrats d’inscription (parent + directeur)
- Autorisation médicale (parent + directeur + infirmière, s’il y a lieu)
- Autorisation de sortie éducative (parent + éducateur principal)
- Accusé de réception d’une politique (parent seulement, une seule étape)
- Documents de fin de service (parent + administrateur)

## Piste d’audit

Chaque document signé est immuable une fois terminé. La chaîne de hachage vous permet de prouver devant un tribunal que le document n’a pas été modifié après la signature.

> **Remarque :** Pour envoyer un formulaire PDF téléversé à des parents ou à du personnel, suivre qui l’a signé, et l’examiner, le contresigner ou le renvoyer, utilisez plutôt le **Gestionnaire de formulaires**. Voir [Gestionnaire de formulaires et trousses de formulaires](forms-manager).

Voir aussi : [Gestionnaire de formulaires et trousses de formulaires](forms-manager),
[Examiner, contresigner et renvoyer des formulaires](reviewing-and-counter-signing-forms).
