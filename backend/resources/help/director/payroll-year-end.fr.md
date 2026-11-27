---
title: Fin d'année de paie : feuillets T4, T4A et RL-1
category: Staff Management
order: 69
roles: agency_admin, platform_admin, centre_director
screens: payroll?tab=yearend
---
# Fin d'année de paie : feuillets T4, T4A et RL-1

Quand le moteur de paie est activé, **Ressources humaines › Paie › Fin d'année** prépare les feuillets fiscaux de chaque personne pour l'année, les compare à vos talons de paie et à vos versements, et vous donne le fichier à téléverser à l'ARC. Les feuillets sont dus le **dernier jour de février** (le 28, ou le 29 lors d'une année bissextile). De novembre à mars, le tableau de bord RH affiche une tuile avec le compte à rebours.

La paie KiddieTrac est canadienne : elle produit seulement des feuillets de l'ARC et de Revenu Québec.

## D'où viennent les montants
- Les **cycles de paie finalisés** dans KiddieTrac, selon la date de paie (une période de décembre 2026 payée le 8 janvier 2027 va sur les feuillets de 2027).
- Les **soldes d'ouverture** (Paie › Soldes d'ouverture) : ce qu'une paie précédente a versé plus tôt dans l'année.
- Les **talons d'une autre paie qui montrent des retenues** (RPC, AE ou impôt). Un talon avec seulement le salaire brut n'arrive jamais sur un feuillet.
- Les **travailleurs autonomes** reçoivent un **T4A** avec la case 048 (honoraires) s'ils ont reçu plus de 500 $ dans l'année ou si de l'impôt a été retenu.

Rien n'est compté deux fois : un talon déjà inclus dans un solde d'ouverture est laissé de côté.

## Étapes (administrateurs de l'agence)
1. Ouvrez **Paramètres de fin d'année** et choisissez le **code de prestations dentaires** (case 45) et la personne-ressource (nom, téléphone, courriel). Le numéro de compte de paie de l'ARC vient de Paramètres RH › Moteur de paie.
2. Appuyez sur **Générer les feuillets**. Vous pouvez les générer de nouveau autant de fois que voulu jusqu'à la finalisation.
3. Utilisez **⋮ › Modifier les cases saisies** pour les montants que KiddieTrac ne connaît pas : cotisations syndicales (44), dons de bienfaisance (46), cotisations à un RPA (20) et son numéro (50), facteur d'équivalence (52), autre code dentaire (45).
4. Lisez le **Rapprochement**. Chaque ligne doit concorder au cent près : feuillets contre talons de paie, contre le rapport de versements (PD7A), et contre les soldes d'ouverture et les talons d'une autre paie.
5. À partir du **1er janvier**, quand tout concorde et qu'aucun cycle de paie de l'année n'est encore ouvert, appuyez sur **Finaliser les feuillets**. Chaque personne voit alors ses feuillets dans **Mes RH › Mes feuillets fiscaux**.
6. Appuyez sur **Sommaires et fichier ARC** : téléchargez le **Sommaire T4** et le **fichier ARC (XML)**, puis téléversez le fichier dans le **Transfert de fichiers par Internet** de l'ARC (ou saisissez les feuillets dans Mon dossier d'entreprise). KiddieTrac vérifie le fichier avec le schéma de l'ARC avant de le remettre. KiddieTrac ne produit jamais la déclaration à votre place.

## Employés du Québec (RL-1)
Les employés du Québec reçoivent un T4 (case 22 = impôt fédéral seulement) et une **fiche d'information RL-1** avec les cases du RL-1 (A, B.A, B.B, C, E, G, H, I), plus une fiche de sommaire RL-1. Revenu Québec certifie les logiciels qui produisent les relevés RL-1 et leurs fichiers, et KiddieTrac n'est pas certifié : produisez les RL-1 dans Mon dossier pour les entreprises de Revenu Québec ou avec un logiciel certifié, à l'aide de ces fiches.

## Corrections après la finalisation
Un feuillet finalisé ne change jamais.
- **⋮ › Modifier ce feuillet** : KiddieTrac le recalcule selon la paie actuelle (en gardant les cases saisies) et le marque **Modifié**.
- **⋮ › Annuler ce feuillet** : pour un feuillet émis à la mauvaise personne ou inutile.
Appuyez ensuite de nouveau sur **Finaliser les feuillets** (le rapprochement doit passer) et téléchargez **Fichier ARC : modifications T4**. La personne voit la nouvelle version dans Mes RH, marquée Modifié.

## Qui voit quoi
- Administrateurs de l'agence : tout, et le NAS complet sur le PDF d'un feuillet et dans le fichier ARC.
- Directeurs de centre (avec la paie) : les feuillets des personnes de leurs centres, en lecture seule, NAS masqué.
- Personnel : ses propres feuillets finalisés, dans Mes RH, avec son propre NAS.
- Personne ne peut modifier, corriger ou finaliser son propre feuillet.

Chaque génération, finalisation, modification et téléchargement est inscrit au journal d'audit. Vérifiez les feuillets avec votre comptable avant de les produire.
