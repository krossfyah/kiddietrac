---
title: Configuration de la facturation et paramètres
category: Billing
order: 10
roles: agency_admin, platform_admin
---
# Configuration de la facturation et paramètres

**Paramètres → Facturation.** Tout ce qui détermine ce que votre agence facture, et comment, se trouve sur un seul écran divisé en onglets.

[[open: #billing-settings | Ouvrir les paramètres de facturation]]

## Les onglets

- **🧾 Taxe** : votre taux de taxe et son nom sur la facture (TVH, TPS, etc.). Un interrupteur permet aussi de cocher **Appliquer la taxe** par défaut sur les nouvelles factures.
- **💵 Forfaits de frais** : forfaits de frais réutilisables. Voir [Forfaits de frais](tuition-plans).
- **👨‍👩‍👧 Rabais fratrie** : voir [Rabais fratrie](sibling-discounts).
- **⚙️ Valeurs par défaut et frais** : les paramètres décrits ci-dessous.
- **🛠 Communications de facturation** : les interrupteurs de courriel et de SMS de l’agence, et la langue par défaut.
- **🔔 Rappels** : rappels automatiques sur les factures à venir et en souffrance. Voir [Rappels de facturation automatisés](automated-billing-reminders).

Les administrateurs de la plateforme voient aussi un onglet **🏦 Facturation de la plateforme**. Il contrôle ce que KiddieTrac facture aux agences, et non ce que les agences facturent aux familles. Voir [Factures de la plateforme](platform-invoices).

## Valeurs par défaut et frais

[[show: #billing-settings @ Defaults & fees > Billing defaults | Afficher l’onglet Valeurs par défaut et frais]]

**Paramètres de facturation par défaut** : la **Fréquence par défaut** (hebdomadaire, aux deux semaines ou mensuelle), le **Dépôt** et les **Frais d’inscription** présélectionnés lorsque vous créez des factures et des forfaits de frais.

**Plans de paiement** : **Émettre les factures ce nombre de jours avant leur échéance.** La valeur par défaut est **5** et le maximum est 60. Chaque versement d’un plan de paiement reste un brouillon en attente jusqu’à ce nombre de jours avant son échéance. Il est alors émis automatiquement et la famille le doit. Réglez **0** pour l’émettre à la date d’échéance même. Les échéances ne changent jamais; ce réglage modifie seulement le préavis donné à la famille. Voir [Plans de paiement](payment-plans).

**Frais de retard** : des **Frais de retard (%)** calculés sur le solde en souffrance, un **Plafond** par frais et un **Délai de grâce (jours)** après l’échéance. Si vous ne les avez jamais modifiés, ils indiquent 1,5 %, 25 $ et 0 jour. Voir [Configuration des frais de retard](late-fee-config).

> **Important :** L’exécution automatique des frais de retard est actuellement désactivée pour toute la plateforme; enregistrer un taux ici ne commence donc à facturer personne. Voir [Automatisation des frais de retard](late-fees).

**Modes de paiement acceptés** : cochez les façons dont les familles peuvent payer : carte de crédit ou de débit, virement bancaire (ACH), virement Interac, espèces, chèque et autre.

**Prélèvement automatique** : **Inscrire les nouvelles familles au prélèvement automatique par défaut** et **Exiger le prélèvement automatique (les familles doivent garder une carte au dossier)**.

**Frais supplémentaires de paiement** : un taux **Carte de crédit / débit (%)** et un taux **Virement / Interac (%)**. Chacun peut aller jusqu’à 10 % et les deux sont à 0 par défaut. Un exemple chiffré sous les cases montre ce que chaque taux ajoute à un paiement de 500 $. Voir [Frais supplémentaires de paiement](payment-surcharges).

Cliquez sur **Enregistrer la configuration de facturation** pour appliquer tout ce qui se trouve dans cet onglet.

## Paramètres connexes ailleurs

- **Prestataires de paiement** (Paramètres) : les comptes qui encaissent réellement les paiements par carte et par virement. Voir [Prestataires de paiement](payment-providers).
- **Image de marque** : le style des factures, leur numérotation, le logo et la couleur. Voir [Style et numérotation des factures](invoice-numbering-and-style).

> **Remarque :** Seuls les administrateurs d’agence et les administrateurs de la plateforme peuvent modifier ces paramètres. Les directeurs de centre peuvent gérer uniquement les rappels de facturation.

Voir aussi : [Frais supplémentaires de paiement](payment-surcharges) · [Prestataires de paiement](payment-providers) · [Plans de paiement](payment-plans) · [Configuration des frais de retard](late-fee-config)
