---
title: Financement de la main-d’œuvre de l’Ontario (SAS et CWELCC)
category: Payroll
order: 40
roles: agency_admin, platform_admin
---
# Financement de la main-d’œuvre de l’Ontario (SAS et CWELCC)

Pour les agences de l’Ontario qui reçoivent la subvention d’amélioration salariale (WEG) et la rémunération de la main-d’œuvre CWELCC (WCF). KiddieTrac calcule les montants de chaque personne, les inscrit sur chaque bulletin de paie sous forme de lignes distinctes, vérifie la règle des 32 jours et produit le rapprochement de fin d’année. Il ne fait que tenir des registres. Il ne paie jamais personne.

## L’activer
**Paramètres → Paie → Financement de la main-d’œuvre de l’Ontario.** Cochez **Utiliser le financement de la main-d’œuvre de l’Ontario dans la paie**. Les valeurs de 2026 sont préremplies à partir des lignes directrices de 2026 :

- Plancher salarial : 25,86 $ l’heure pour le personnel de programme RECE, 26,86 $ pour les superviseurs RECE et les visiteurs en services de garde à domicile
- Hausse annuelle de la WCF : jusqu’à 4,00 $ l’heure, jusqu’à ce que le salaire atteigne 28 $ (personnel de programme) ou 31 $ (superviseurs)
- WEG : jusqu’à 2,00 $ l’heure, jusqu’à ce que le salaire atteigne 33,81 $

Quand votre gestionnaire de services publie les valeurs de l’an prochain, choisissez l’année et saisissez-les. Rédigez votre **politique de distribution** dans la même carte, ou appuyez sur **Ajouter à la bibliothèque de politiques** pour téléverser la copie signée et suivre sa révision annuelle.

## Le salaire de chaque personne
Ouvrez **Finances → Financement de la main-d’œuvre → Salaires du personnel** et appuyez sur **Salaires** sur la ligne d’une personne. Saisissez son **salaire de base**, plus toute majoration GOF de 2024 que vous versez encore. KiddieTrac calcule ensuite le reste dans l’ordre des lignes directrices : la WEG, puis la hausse WCF, puis le complément du plancher salarial.

- L’admissibilité découle du rôle de la personne et d’une certification **RECE** active dans sa fiche de personnel. Un directeur sans RECE, ou un éducateur approuvé par le directeur, est considéré **non-RECE** : il peut recevoir la WEG, mais pas la WCF. Vous pouvez modifier l’admissibilité.
- Laissez un montant de financement vide pour utiliser le calcul, ou saisissez une valeur manuelle. Si une valeur manuelle dépasse un plafond ou le maximum, un avertissement s’affiche.
- Quand la paie de quelqu’un change, ajoutez un **nouvel enregistrement** à partir de la date d’entrée en vigueur. L’ancien enregistrement se termine la veille. Pour corriger une erreur, utilisez **Corriger**. Chaque modification est consignée dans le journal d’audit.

## Cycles de paie
Quand vous choisissez **Générer la paie**, chaque personne financée affiche ses taux WEG, WCF et de plancher. En cochant une personne, vous ajoutez une ligne distincte pour chaque composante (heures × taux) et l’incluez dans sa paie. Le bulletin de paie en PDF présente le salaire de base, la WEG et la rémunération de la main-d’œuvre sur des lignes distinctes. Le financement est versé pour chaque heure travaillée, y compris les heures supplémentaires, au même montant horaire.

**Période de paie** affiche les heures du pointage × chaque composante pour n’importe quelle période. Elle indique aussi si les bulletins de paie de cette période comportent les lignes de financement. Vous pouvez télécharger les lignes en CSV.

## Financement reçu et règle des 32 jours
Dans **Financement reçu**, consignez chaque paiement de votre gestionnaire de services : la date, le montant, le volet et la période couverte. Chaque paiement doit parvenir à la paie du personnel dans les 32 jours. Chacun est marqué **Respecté** (avec la date de la première paie), **En attente** (avec les jours restants) ou **MANQUÉ**. Une saisie erronée est annulée, non supprimée.

## Rapprochement de fin d’année
**Rapport de fin d’année** totalise chaque année civile par personne et par poste : heures, ETP, salaires de base, WEG, hausse WCF, plancher salarial et l’allocation d’avantages de 17,5 %. Le personnel non-RECE approuvé par le directeur figure dans un tableau distinct, et le financement reçu est comparé à ce que vous avez versé. Téléchargez-le en CSV ou en PDF; chaque téléchargement est consigné dans le journal d’audit. Conservez ces registres pendant sept ans. KiddieTrac ne les supprime jamais.
