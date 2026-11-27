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
