---
title: Préférences de langue (FR / ES)
category: Settings
order: 95
---
# Langue

KiddieTrac est offert en **anglais**, en **français** et en **espagnol**. L’interface, la navigation et les principaux libellés sont traduits. Les articles d’aide et le contenu rédigé par l’agence restent dans la langue où ils ont été écrits.

## Changer de langue

1. Barre latérale → **Langue**.
2. Cliquez sur la langue souhaitée.
3. La page se recharge avec la nouvelle langue appliquée.

Votre préférence est enregistrée dans votre dossier d’utilisateur : vous n’avez pas à la changer à chaque visite.

## Langue par défaut de l’agence

Les administrateurs d’agence peuvent définir une **Langue par défaut** dans **Administration → Paramètres de facturation**. Les nouveaux utilisateurs de cette agence commencent dans cette langue. Chacun peut la modifier pour lui-même.

## Couverture

Interface courante : 100 %. Libellés de navigation : 100 %. Formulaires et écrans : environ 80 % (en cours). Modèles de courriel : en anglais seulement pour l’instant.

## Remplacements propres à l’agence

Pour remplacer un texte précis pour votre agence (p. ex. appeler les « Éducateurs » des « Enseignants »), ajoutez une ligne dans `agency_locale_overrides` (`key_path`, `value`) et le LocaleController la fusionnera par-dessus le jeu de textes par défaut.
