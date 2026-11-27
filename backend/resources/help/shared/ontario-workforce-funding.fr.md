---
title: Financement de la main-d'œuvre de l'Ontario (WEG et CWELCC)
category: Payroll
order: 40
roles: agency_admin, platform_admin
---
# Financement de la main-d'œuvre de l'Ontario (WEG et CWELCC)

**Finances → Financement de la main-d'œuvre** s'adresse aux agences de l'Ontario qui reçoivent la subvention de rehaussement salarial (WEG) et la rémunération de la main-d'œuvre CWELCC (WCF). KiddieTrac calcule les montants horaires de chaque personne, les inscrit sur chaque talon de paie en lignes distinctes, vérifie la règle des 32 jours pour chaque versement reçu et prépare le rapprochement de fin d'année. Il ne fait que tenir les dossiers : il ne paie jamais personne.

[[open: #workforce-funding | Ouvrir Financement de la main-d'œuvre]]

L'écran compte quatre onglets : **Salaires du personnel**, **Période de paie**, **Financement reçu** et **Rapport de fin d'année**.

## L'activer

1. Ouvrez **Paramètres → Paie** et trouvez la carte **🍁 Ontario workforce funding (WEG + CWELCC)**.
2. Cochez **Utiliser le financement de la main-d'œuvre de l'Ontario dans la paie**.
3. Sous **Valeurs pour**, vérifiez l'année. Les valeurs 2026 sont déjà remplies selon les lignes directrices 2026 : un salaire plancher de 25,86 $ l'heure pour le personnel de programme EPEI (RECE) et de 26,86 $ pour les superviseurs EPEI et les visiteurs en garde à domicile; une augmentation WCF d'au plus 4,00 $ l'heure jusqu'à ce que le salaire atteigne 28 $ (personnel de programme) ou 31 $ (superviseurs); la WEG d'au plus 2,00 $ l'heure jusqu'à ce que le salaire atteigne 33,81 $.
4. Quand votre gestionnaire de services publie les valeurs de l'année suivante, choisissez cette année et saisissez-les.
5. Rédigez votre **Politique de distribution**, avec **Approved by (name / title)** et **Approuvée le**, ou appuyez sur **Ajouter à la bibliothèque de politiques** pour téléverser la copie signée et suivre sa révision annuelle.
6. Appuyez sur **Enregistrer les paramètres de financement**.

## Saisir le salaire d'une personne

1. Ouvrez **Finances → Financement de la main-d'œuvre** et restez sur **Salaires du personnel**.
2. Appuyez sur **Salaires** dans la ligne de la personne. La fenêtre **Composantes du salaire** s'ouvre.
3. Indiquez **En vigueur à partir du**, puis saisissez le **Base wage ($/h)** (salaire de base) et tout **2024 GOF kept ($/h)** que vous versez encore.
4. Laissez **Situation du financement** sur **Automatic (role + RECE registration)**, ou choisissez-en une pour la forcer.
5. Laissez **WEG override ($/h)**, **Remplacement de l'augmentation WCF** et **Remplacement du complément au plancher** vides pour utiliser le calcul, ou saisissez un montant. Dépasser un plafond ou un maximum affiche un avertissement.
6. Appuyez sur **Enregistrer**. Le message **Wage record saved** s'affiche et la ligne montre **Base**, **WEG**, **WCF increase**, **Floor top-up** et **Total /h**.

KiddieTrac calcule les montants dans l'ordre des lignes directrices : la WEG, puis l'augmentation WCF, puis le complément au salaire plancher. L'admissibilité découle du rôle de la personne et d'une certification **RECE** active à son dossier. Un directeur sans RECE, ou une éducatrice approuvée par la direction, compte comme non-RECE : il peut recevoir la WEG, mais pas la WCF.

Quand la paie de quelqu'un change, ajoutez un **nouveau dossier** à partir de la date d'effet; l'ancien se termine la veille. Pour corriger une erreur, appuyez plutôt sur **Corriger** sur le dossier. Chaque changement est au journal d'audit, avec l'ancienne et la nouvelle valeur.

## Le verser dans une paie

1. Faites la paie comme d'habitude et appuyez sur **Générer la paie**.
2. Chaque personne financée affiche ses taux WEG, WCF et plancher. La cocher ajoute à sa paie une ligne distincte pour chaque part (heures × taux).
3. Dans l'onglet **Période de paie**, choisissez les dates et appuyez sur **Afficher** pour voir les heures pointées × chaque taux, et si les talons de cette période portent les lignes de financement. **⬇ Lines (CSV)** les télécharge.

Le talon de paie PDF montre le salaire de base, la WEG et la rémunération de la main-d'œuvre sur des lignes distinctes. Le financement est versé pour chaque heure travaillée, heures supplémentaires comprises, au même montant horaire.

## Consigner le financement reçu

1. Ouvrez l'onglet **Financement reçu**.
2. Appuyez sur **+ Enregistrer un financement reçu**.
3. Saisissez **Reçu le** et le **Amount ($)** (montant), et sous **Financement** choisissez **WEG + rémunération de la main-d'œuvre**, **WEG seulement** ou **Rémunération de la main-d'œuvre seulement**.
4. Ajoutez **Période couverte à partir du** / **to** (au), le **Centre (optional)** (ou **Toute l'agence**), une **Référence** et des **Notes**.
5. Appuyez sur **Enregistrer**. Le message **Funding receipt recorded** s'affiche.

À partir de la date de réception, vous avez 32 jours pour le verser sur la paie régulière. La colonne **32-day check** indique **Met** (avec la première date de paie financée), **Pending** (avec les jours restants) ou **MISSED**. Une entrée erronée s'annule avec **Void**, motif à l'appui : elle reste au dossier, marquée annulée.

## Produire le rapport de fin d'année

1. Ouvrez l'onglet **Rapport de fin d'année** et choisissez l'année.
2. Lisez les tuiles (**Financement reçu**, **WEG paid**, **WCF paid**, **Benefit allocation 17.5%**, et **Surplus to return** ou **Shortfall**) et les tableaux par personne et par poste, avec à part le personnel non-RECE approuvé par la direction.
3. Appuyez sur **⬇ CSV** ou **⬇ PDF** pour le télécharger. Chaque téléchargement est au journal d'audit.

Conservez ces dossiers sept ans. KiddieTrac ne les supprime jamais.

## Qui peut faire quoi

Seuls les **administrateurs d'agence** (et les administrateurs de plateforme qui travaillent dans l'agence) voient cet écran, et seulement dans une agence de l'Ontario. Les directeurs de centre, les éducatrices et les parents ne le voient pas. Le personnel voit les lignes de financement sur ses propres talons de paie.

## FAQ et problèmes courants

??? Financement de la main-d'œuvre n'est pas dans mon menu. | Il n'apparaît que pour les administrateurs d'une agence de l'Ontario. Vérifiez la province de l'agence dans ses paramètres.
??? L'écran indique que les lignes de financement sont désactivées pour la paie. | Ouvrez **Paramètres → Paie**, cochez **Utiliser le financement de la main-d'œuvre de l'Ontario dans la paie** et appuyez sur **Enregistrer les paramètres de financement**.
??? Quelqu'un reçoit la WEG mais pas la WCF. | La personne compte comme non-RECE : vérifiez qu'une certification **RECE** active figure à son dossier, ou réglez **Situation du financement** à la main.
??? « The date and the amount are needed. » | Remplissez **Reçu le** et **Amount ($)**, puis enregistrez de nouveau.
??? Un versement affiche MISSED. | Aucune paie financée n'a été versée dans les 32 jours suivant la date de réception. Versez-le à la prochaine paie régulière et gardez une note pour votre gestionnaire de services.
??? Certains talons ne sont pas comptés dans le rapport de fin d'année. | Les talons sans ventilation WEG/WCF (par exemple synchronisés d'un autre système de paie) sont exclus; le rapport indique combien.
??? Est-ce que cet écran envoie de l'argent ou un courriel? | Non. Il tient les dossiers et ajoute des lignes aux talons de paie. Aucun courriel ni texto n'est envoyé de cet écran.

## Écrans liés

[[open: #payroll-settings | Paramètres de paie]] [[open: #grants | Subventions]]

Voir aussi : [Subventions](grants) · [Paies, heures et talons de paie](payroll-documents)
