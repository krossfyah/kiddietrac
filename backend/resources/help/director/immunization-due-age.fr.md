---
roles: agency_admin, centre_director, platform_admin
title: Calendrier de vaccination « Dû à l’âge de »
category: Compliance
order: 32
---
# Vaccination due à l’âge de

**Vaccinations → Dû à l’âge de.** Les doses enregistrées de chaque enfant comparées au
calendrier de votre agence, pour que les vaccins en retard soient signalés selon l’âge de l’enfant, sans que
personne ait à examiner les carnets à la main.

[[show: #immunizations @ Due at age | Afficher l’onglet Dû à l’âge de]]

## Les onglets

- **Dû à l’âge de** — toute la liste, chaque enfant par rapport au calendrier.
- **En retard seulement** — la même vue, limitée aux enfants ayant une dose en retard.
- **Dossiers reçus** — tous les dossiers de vaccination déposés pour vos enfants, et qui les a
  envoyés. Voir [Déposer un dossier de vaccination](filing-an-immunization-record).
- **Calendrier par défaut** — le calendrier lui-même.

Sur la ligne d’un enfant, le nom ou **Voir les vaccinations** ouvre son calendrier complet à côté de ce qui
est enregistré, et **📄 Dossiers déposés** mène directement aux carnets déposés pour lui.

## Le calendrier

Chaque agence a son propre calendrier de vaccins et de doses, avec un âge cible en mois :

| Vaccin | Dose | Dû à l’âge de |
|---|---|---|
| DCaT-VPI-Hib | 1re dose | 2 mois |
| Pneumocoque | 2e dose | 4 mois |
| RRO | 1re dose | 12 mois |
| Varicelle | 1re dose | 15 mois |
| RRO-V | 2e dose | 48 mois |
| DCaT-VPI | 5e dose (rappel) | 60 mois |
| … |

Le calendrier **NACI canadien** est rempli pour vous la première fois qu’il est ouvert. Ensuite,
il vous appartient de le modifier.

### Le modifier

Dans **Calendrier par défaut**, **+ Ajouter un élément** ajoute une ligne : **Vaccin**, **Dose**, **Dû à l’âge de
(mois)**, **Notes** et une case **Requis**. Le menu **⋮** de chaque ligne offre **Voir**,
**Modifier** et supprimer.
Modifier une ligne recalcule le statut de chaque enfant.

- Ajoutez des éléments propres à l’agence (un vaccin antigrippal saisonnier à 24 mois, par exemple) en plus des valeurs
  NACI par défaut.
- Décochez **Requis** pour un vaccin que votre centre considère comme facultatif. Il figure toujours dans le
  calendrier, marqué **Facultatif**. Le rappel aux familles peut être limité aux doses requises, et
  **Tout cocher (requis)** sur un formulaire d’exemption ne coche que celles-ci.

## Statut par enfant

Chaque dose du calendrier est dans l’un de ces états :

- **Enregistrée** — une dose correspondante figure au dossier de l’enfant.
- **Pas encore due** — l’enfant n’a pas encore cet âge.
- **Bientôt due** — due dans les deux prochains mois.
- **En retard** — l’âge prévu est dépassé et rien n’est enregistré.
- **Exempté** — une exemption est enregistrée, avec son motif affiché à côté. Voir
  [Exemptions de vaccination](immunization-exemptions).

> **Remarque :** Un dossier déposé n’est pas une dose enregistrée. Un carnet envoyé par un parent s’affiche comme **Détails en attente** jusqu’à ce que quelqu’un enregistre les doses qui s’y trouvent, et d’ici là l’enfant continue d’afficher ce qui est en retard.

## Rappels

Personne n’est rappelé automatiquement à moins que votre agence ne l’active. Les administrateurs d’agence configurent
un rappel à l’équipe et, facultativement, un rappel aux familles, dans **Paramètres → Paramètres de messagerie →
Vaccinations**. Les doses exemptées n’en font jamais partie. Voir
[Rappels de vaccination](immunization-reminders).

Voir aussi : [Déposer un dossier de vaccination](filing-an-immunization-record),
[Exemptions de vaccination](immunization-exemptions),
[Rapport de vaccination](immunization-report).
