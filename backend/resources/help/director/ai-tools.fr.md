---
title: Outils IA (résumé / risque d’attrition / extraction de documents)
category: AI
order: 50
---
# Outils IA

Trois outils alimentés par l’IA, tous basés sur Claude.

## Score de risque d’attrition

**Menu latéral → Risque d’attrition** (directeur / administrateur de l’agence).

Score de risque heuristique de 0 à 100 par famille. **Aucun appel à l’IA** — simple agrégation SQL de l’activité d’observation, des présences enregistrées, des factures en retard et du solde dû. Mis à jour chaque fois que vous chargez la page.

Catégories :
- **Élevé** (≥60) — nécessite une intervention cette semaine
- **Moyen** (30-59) — à surveiller
- **Faible** (<30) — engagé

Les signaux sont détaillés dans le tableau — « Observations en baisse de plus de 50 % », « 2 factures en retard », etc. Servez-vous-en pour savoir *pourquoi* une famille est signalée.

## Résumé hebdomadaire (destiné aux parents)

Un résumé convivial d’un paragraphe de la semaine de l’enfant. Généré sur demande, gratuit pour les parents.

**Parent :** menu latéral → nom de l’enfant → **Portfolio** → **Générer le résumé IA**.

Utilise Claude avec les observations et les activités de l’enfant suivies cette semaine. Le résultat arrive en 5 à 15 secondes. Ne l’affichez pas en temps réel — affichez un indicateur « génération en cours… ».

## Extraction de documents

**Menu latéral → Extraction de documents IA** (administrateur / directeur).

Téléversez une photo ou une numérisation d’un document — dossier de vaccination, certification, vérification VSS, pièce d’identité, formulaire d’inscription — et nous en extrayons les champs structurés en JSON.

1. Collez l’URL du document (le document doit être accessible publiquement — utilisez d’abord le téléversement **MediaController** si vous l’avez sur disque, puis copiez l’URL retournée).
2. Choisissez le type de document.
3. Cliquez sur **Extraire les champs**.
4. Le JSON s’affiche en dessous. Copiez pour l’instant les champs manuellement dans la fiche de l’enfant ou du membre du personnel concerné — le lien automatique arrivera dans v22p52.

Des frais s’appliquent par appel de vision Claude. Prévoyez environ 0,01 à 0,03 $ par document selon la taille.
