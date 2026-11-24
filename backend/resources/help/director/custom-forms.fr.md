---
roles: agency_admin, platform_admin
title: Créateur de formulaires personnalisés
category: Daily Operations
order: 68
---
# Formulaires personnalisés

Créez sans code des formulaires d’inscription, de consentement, de sondage et de rétroaction. Vous les trouverez sous **Administration → Formulaires personnalisés**.

## Créer un formulaire

Cliquez sur **+ Nouveau formulaire** et remplissez :

- **Titre** — ce que le personnel voit dans la liste
- **Description** — affichée aux répondants au-dessus des questions
- **Destinataires** — toutes les familles / actuellement inscrites / liste d’attente / prospects / personnel seulement
- **Statut** — Brouillon (vous seul le voyez) / Publié (en ligne pour les destinataires) / Archivé

Ajoutez ensuite des champs. Dix types sont pris en charge :

| Type | À utiliser pour |
|---|---|
| Texte court | Noms, adresses, réponses d’une ligne |
| Texte long | Notes et descriptions sur plusieurs lignes |
| Courriel | Format de courriel validé |
| Nombre | Saisie numérique |
| Date | Sélecteur de date |
| Liste déroulante | Choisir un élément dans une liste |
| Case à cocher | Oui/non |
| Choix multiple | Choisir un élément avec des boutons radio |
| ✍️ Signature | Un bloc de signature — les répondants signent du doigt ou à la souris |
| 💳 Paiement | Un prélèvement (indiquez le montant); inscrit au compte de la famille lorsqu’il est autorisé |

Chaque champ comporte : une étiquette, une option « obligatoire », un texte indicatif, un texte d’aide, des options (pour liste déroulante / boutons radio) et — pour Paiement — un **montant à prélever**.

## Visibilité conditionnelle

Tout champ après le premier peut être réglé sur « Afficher seulement lorsqu’un autre champ a une valeur précise ». Choisissez le champ dont il dépend et laissez la case « égale à » vide (correspond à toute valeur non vide) ou saisissez une valeur précise.

Exemple : demander « Pourquoi refusez-vous? » seulement lorsque le bouton radio « Votre enfant sera-t-il présent? » est réglé sur « Non ».

## Réponses

Cliquez sur la pastille **N réponses** dans la ligne d’un formulaire pour ouvrir la fenêtre des réponses. Chaque réponse indique le nom de la personne, l’horodatage et chaque réponse associée à son étiquette.

Le bouton vert **⤓ Télécharger le CSV** exporte toutes les réponses avec une colonne par champ, dans l’ordre du schéma.

## Aperçu

Cliquez sur **👁 Aperçu** sur n’importe quel formulaire pour voir exactement ce que verront les répondants — y compris votre logo de marque blanche et votre couleur principale dans l’en-tête. L’aperçu fonctionne sur les formulaires en brouillon, ce qui vous permet de vérifier un formulaire avant de le publier.

## Envoyer un formulaire aux parents par courriel

À partir d’un formulaire publié, cliquez sur **✉ Envoyer aux parents par courriel** pour envoyer le lien du formulaire aux destinataires correspondants. Le courriel part de l’adresse propre à votre agence (lorsque le courriel est activé et qu’un expéditeur est configuré sous **Paramètres → Facturation → Paramètres**).

## Marque blanche

Les formulaires — et l’**Aperçu** du formulaire — affichent votre **logo et votre couleur principale** lorsque l’image de marque blanche est configurée. Voir *Image de marque blanche et factures*.

## Trouver des formulaires et des réponses

Les longues listes sont consultables et paginées : utilisez la **zone de recherche** au-dessus de la liste des formulaires pour filtrer par titre, et la **pagination** au bas de la fenêtre des réponses pour parcourir de grands ensembles de réponses.

## Côté parent

Les tuteurs voient les formulaires publiés dont les destinataires correspondent à la situation de leur famille dans l’entrée **Votre enfant → Formulaires** de la barre latérale. Les formulaires déjà soumis affichent un badge vert ✅. Les formulaires comportant un champ de **signature** ou de **paiement** demandent ces éléments avant de pouvoir être soumis.
