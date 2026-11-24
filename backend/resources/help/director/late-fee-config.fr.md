---
title: Configuration des frais de retard
category: Billing
order: 74
roles: agency_admin, platform_admin
---
# Configuration des frais de retard

**Paramètres → Facturation → Valeurs par défaut et frais → Frais de retard.** Chaque agence définit sa propre règle de frais de retard.

[[show: #billing-settings @ Defaults & fees > Late fees | Afficher les paramètres des frais de retard]]

## Ce que vous pouvez définir

- **Frais de retard (%)** : le pourcentage du solde en souffrance qui est facturé. Il peut aller jusqu’à 25 %. Réglez-le à **0** pour désactiver les frais de retard dans votre agence.
- **Plafond** : le montant maximal d’un seul frais de retard, dans votre devise. Il peut aller jusqu’à 500.
- **Délai de grâce (jours)** : le nombre de jours après la date d’échéance avant qu’un frais ne s’applique. Il peut aller jusqu’à 60.

Cliquez sur **Enregistrer la configuration de facturation** pour appliquer.

Si vous ne les avez jamais modifiés, ils sont à **1,5 %**, avec un plafond de **25 $** et **0** jour de grâce. Ce sont des valeurs de départ, pas un choix de votre agence : révisez-les avant l’activation des frais de retard.

> **Important :** Le traitement automatique des frais de retard est actuellement **désactivé pour toute la plateforme**; aucun frais de retard n’est donc ajouté aux factures. Vos paramètres sont enregistrés et s’appliqueront dès que KiddieTrac activera le traitement. Voir [Automatisation des frais de retard](late-fees).

## Comment le frais est calculé

**Frais = le solde actuel de la facture × votre pourcentage, plafonné à votre plafond.**

Par exemple, à 1,5 % avec un plafond de 25 $, un solde en souffrance de 900 $ entraîne des frais de 13,50 $, et un solde de 2 000 $ entraîne 25,00 $.

Voir aussi : [Automatisation des frais de retard](late-fees) · [Configuration et paramètres de facturation](billing-setup) · [Rappels de facturation automatisés](automated-billing-reminders)
