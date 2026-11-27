---
title: RH : retards, score d'absence et chevauchements de congés
category: Staff Management
order: 68
roles: agency_admin, platform_admin, centre_director
---
# RH : retards, score d'absence et chevauchements de congés

Trois indicateurs de présence du module RH de KiddieTrac. Un administrateur d'agence fixe les règles dans **Ressources humaines > Paramètres RH > Règles de présence** ; les directeurs voient les résultats de leurs centres.

## Retards

Le premier pointage de chaque quart prévu à l'**horaire** est comparé au début du quart. Au-delà du délai de grâce (5 minutes sauf si votre agence le change), c'est un retard. Le retour d'une pause et les jours sans quart ne sont pas comparés.

Ouvrez **Ressources humaines > Absences et retards > Retards** pour voir, sur une période : les pointages comparés, les retards, le total et la moyenne des minutes, le plus long, le pourcentage à l'heure et une tendance (par exemple *Surtout le lundi*). Le menu ⋮ d'une ligne montre chaque pointage face à son quart. **Exporter vers Excel** télécharge la liste.

Si votre agence active **Prévenir les directeurs du centre**, ils reçoivent un avis quand quelqu'un pointe en retard.

## Score d'absence

La colonne **Score d'absence** du **Rapport d'absences** donne plus de poids aux absences courtes et fréquentes : nombre d'absences × nombre d'absences × jours d'absence, sur les 12 derniers mois (la formule de Bradford). Trois absences d'un jour donnent 27 ; une absence de trois jours donne 3. Des absences séparées seulement par une fin de semaine ou un jour de fermeture comptent pour une.

Par défaut, il compte les jours de maladie. Votre agence peut choisir les types de congé, la période et le seuil (50 par défaut). Toute personne au seuil ou au-dessus est signalée ; **Seulement au-dessus du score d'absence** n'affiche qu'elles. Le score invite à une conversation, jamais à une décision automatique.

## Chevauchements de congés et personnel minimum

Quand quelqu'un demande un congé, le formulaire indique combien d'autres personnes de son centre sont déjà absentes ou en ont demandé pour ces jours (jamais qui). La personne qui approuve voit qui, dans la fenêtre d'approbation et dans le courriel.

Un administrateur d'agence peut fixer un **personnel minimum un jour ouvrable** pour chaque centre. La personne qui approuve voit alors quels jours passeraient en dessous. Votre agence choisit si c'est un avertissement ou si l'approbation est bloquée. Un congé sans approbation, comme un jour de maladie, n'est jamais refusé, seulement signalé.
