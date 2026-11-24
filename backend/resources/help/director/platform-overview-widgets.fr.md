---
roles: agency_admin, platform_admin
title: Widgets de l’aperçu de la plateforme
category: Administration
order: 90
---
# Widgets de l’aperçu de la plateforme

L’écran **Aperçu de la plateforme** (visible par les admins de la plateforme seulement) présente un tableau de bord de direction de type SaaS avec sept tuiles d’indicateurs clés, deux graphiques de tendance, deux cartes d’analyse, une huitième tuile de mesure des sessions et une section **Indicateurs d’affaires** au thème sombre.

## Tuiles d’indicateurs clés

Agences · Centres · Enfants · Familles · Personnel · MRR · Sessions (actives au cours des dernières 24 h).

Chaque tuile a une bande d’accent à gauche dont la couleur correspond à la catégorie, avec une ligne d’indice sous le chiffre.

## Graphiques de tendance

**Tendance du MRR** — historique sur six mois. Diagramme à barres en dégradé de vert, avec les montants en dollars au-dessus de chaque barre.

**Croissance des agences** — historique sur six mois des inscriptions par rapport aux annulations. Chaque barre est colorée selon la direction nette : violet = mois net positif, ambre = au moins une annulation, rouge = mois net négatif.

## Meilleures agences + activité récente

**Meilleures agences par inscriptions** — les cinq plus grands locataires selon le nombre d’enfants, avec des pastilles aux couleurs de la marque et des barres de part par rapport au premier.

**Activité récente de la plateforme** — les 10 dernières lignes du journal d’audit jointes aux utilisateurs, de sorte que chaque événement montre qui a fait quoi et quand, avec des icônes emoji selon le type d’action.

## Section Indicateurs d’affaires

Bandeau au thème sombre au bas de l’écran, avec huit tuiles :

- **ARR** — revenu récurrent annualisé
- **ARPA** — revenu moyen par agence active
- **ARPU** — par enfant inscrit
- **Croissance du MRR %** — d’un mois à l’autre, avec l’écart absolu en dollars
- **Désabonnement %** (30 j) — annulées / nombre de départ
- **LTV** — valeur à vie estimée
- **NRR (6 m)** — MRR actuel / MRR d’il y a six mois
- **Capacité %** — total des inscrits / total des places autorisées

Toutes les données proviennent d’agrégats déjà chargés pour les graphiques de tendance (aucune requête SQL supplémentaire).

## Accès

Visible seulement par les utilisateurs ayant le rôle `platform_admin`. Les administrateurs d’agence voient leur propre tableau de bord limité à leur agence à `#dashboard`.
