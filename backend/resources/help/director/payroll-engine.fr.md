---
title: Moteur de paie : cycles de paie, talons de paie et versements
category: Staff Management
order: 67
roles: agency_admin, platform_admin, centre_director
screens: payroll?tab=payruns, payroll?tab=remit, payroll?tab=ytd, hr-settings
---
# Moteur de paie : cycles de paie, talons de paie et versements

Le moteur de paie calcule la paie de chaque personne avec toutes les retenues canadiennes (RPC, AE, RQAP et impôt), produit les talons de paie et montre ce que vous devez à l'ARC. Il fait partie du module RH de KiddieTrac et reste **désactivé** tant qu'un administrateur de l'agence ne l'active pas. Tant qu'il est désactivé, votre export de paie fonctionne exactement comme avant.

## L'activer (administrateurs de l'agence)

Ouvrez **Paramètres RH** et trouvez **Moteur de paie**.

1. Lisez l'avis et cochez-le : **vérifiez avec votre comptable avant votre premier vrai cycle de paie**. Vous l'acceptez une seule fois.
2. Cochez **Moteur de paie activé**.
3. Saisissez votre **compte de paie de l'ARC** (9 chiffres, RP, 4 chiffres), votre **numéro de Revenu Québec** si vous avez du personnel au Québec, votre **type de versement** et, si vous le souhaitez, votre **taux d'indemnisation des accidents du travail** (utilisé seulement pour une estimation dans le rapport des versements).

Chaque personne a aussi besoin d'une **configuration fiscale de paie** dans son profil RH (province d'emploi, fréquence de paie) et d'un taux de rémunération. Le personnel remplit son propre TD1 dans **Mes RH → Formulaires fiscaux**.

## Un cycle de paie

Ouvrez **Paie → Cycles de paie** et choisissez **Nouveau cycle de paie**. Choisissez le centre (ou tous les centres), la période et la date de paie.

- Les heures viennent de la pointeuse (feuilles de temps) : le temps payé de chaque personne, avec les heures supplémentaires et les remboursements approuvés.
- Chaque ligne montre les heures, la paie brute, le RPC, l'AE, l'impôt et la paie nette. Ouvrez **⋮ → Détail du calcul** pour voir chaque montant, le cumul de l'année avant cette paie et les tables de l'ARC utilisées.
- Sur un brouillon, vous pouvez ajouter une prime, un avantage imposable ou une retenue après impôt, ou exclure quelqu'un. **Recalculer** relit la pointeuse.

Ensuite : **Envoyer pour révision → Approuver → Finaliser et produire les talons.** La personne qui approuve ne peut pas être payée dans ce cycle. À la finalisation, chacun reçoit son talon dans l'application et un courriel indiquant qu'il est prêt, sans aucun montant.

Un cycle finalisé n'est jamais modifié. Pour le corriger, utilisez **⋮ → Faire un ajustement** : il paie ce qui a changé depuis (par exemple un pointage corrigé en retard).

## Voir un talon de paie

Les administrateurs et les directeurs (pour le personnel de leurs centres) peuvent ouvrir un talon dans le portail sans son mot de passe. Le **téléchargement**, et tout talon envoyé par courriel à l'employé, est toujours le fichier protégé par mot de passe. Chaque consultation est inscrite au journal d'audit.

## Versements

**Paie → Versements** additionne les cycles finalisés par période de versement : RPC et AE (les deux parts) et impôt dus à l'ARC, ce qui est dû à Revenu Québec pour le personnel du Québec, et la date d'échéance selon votre type de versement. Servez-vous-en pour remplir votre bordereau PD7A et vérifiez-le avec votre comptable.

## Soldes d'ouverture (administrateurs de l'agence)

Si vous commencez en cours d'année, saisissez dans **Paie → Soldes d'ouverture** ce que votre ancienne paie a versé à chaque personne cette année, pour que le RPC et l'AE s'arrêtent au maximum annuel et que le cumul des talons soit juste. Vous pouvez coller un tableur. Vous ne pouvez pas saisir le vôtre.

## Ce que montre la fiche de paie

Chaque fiche de paie PDF a la même présentation :

- **Une bande aux couleurs de votre agence** avec votre logo dans un badge rond, votre raison sociale, adresse, téléphone, courriel, numéro d'entreprise de l'ARC (s'il est saisi) et le centre.
- **L'employé** : nom complet, numéro d'employé, poste, centre, type d'emploi, date d'entrée, adresse personnelle, téléphone et courriel.
- **Détails de la paie** : NAS affiché uniquement sous la forme **XXX-XXX-123** (jamais le numéro complet), province d'emploi, période de paie, date de paie, numéro de fiche, cycle de paie, taux de rémunération et mode de paiement (pour le dépôt direct, seuls les 4 derniers chiffres du compte).
- **Cumul annuel et cette paie** : brut, retenues et net, avec la **paie nette** dans un grand encadré.
- **Gains** (taux, heures, cette paie, cumul annuel) et **Retenues** (RPC ou RRQ, AE ou RQAP, impôt fédéral et provincial, autres) avec les totaux. Les cotisations de l'employeur et les soldes de congés sont donnés à titre d'information.
- Les **remarques** du cycle de paie, vos **conditions** et un filigrane diagonal léger **NON NÉGOCIABLE** avec la ligne **NON NÉGOCIABLE — CECI N'EST PAS UN CHÈQUE** sur chaque page.

Une fiche pour laquelle la paie n'a pas calculé les retenues (fiche manuelle ou salaire brut seulement) montre ce qui a été enregistré et indique **Retenues non calculées dans KiddieTrac**. Aucun montant n'est inventé.

## Conditions de la fiche de paie (administrateurs d'agence)

Ouvrez **Paramètres de paie** et trouvez **Conditions de la fiche de paie**. Écrivez un énoncé par ligne. Indiquez le nombre de jours dont le personnel dispose pour signaler une erreur de paie (30 par défaut); `{days}` dans votre texte est remplacé par ce nombre. Gardez le texte standard et chacun le lit dans sa langue (anglais, français, espagnol ou hindi). **Utiliser le texte standard** le rétablit.

## Mots de passe des fiches de paie

Chaque fiche de paie en PDF qu'une personne télécharge ou reçoit par courriel est protégée par un mot de passe, comme iLearn protège ses fiches de paie : fiches du moteur de paie, fiches créées par **Generate payroll** ou ajoutées à la main, fiches au brut seulement, relevés hebdomadaires, et copies des T4, T4A et RL-1. Le fichier est verrouillé par un chiffrement AES-256 ; une fois ouvert, il peut être imprimé et copié.

- **Le mot de passe :** les 3 premières lettres du nom de famille en MAJUSCULES, puis la date de naissance au format MMJJAAAA (mois, jour, année). Par exemple, Jordan Ellis, né le 2 mars 1990, utilise `ELL03021990`.
- **Aucune date de naissance au dossier :** le dernier jour de la période de paie la remplace (le 31 décembre de l'année d'imposition pour un feuillet fiscal). **Aucun nom de famille :** les 3 premières lettres du prénom. Une fiche de paie n'est jamais envoyée sans verrou.
- **Le personnel reçoit le format, jamais le mot de passe**, dans la liste des fiches de paie de l'application et dans le courriel de la fiche de paie. Le courriel ne contient aucun montant.
- **Qui est concerné :** **Payroll settings → Payslip passwords** liste toutes les personnes payées ici sans date de naissance ou sans nom de famille au dossier. Ajoutez l'information dans leur dossier RH et leur prochain téléchargement utilise le mot de passe habituel.
- Ouvrir une fiche de paie dans le portail (la vue du bureau, pour les administrateurs et les directeurs) ne demande aucun mot de passe. Cette protection ne peut pas être désactivée.
