---
title: Automatisation des frais de retard
category: Billing
order: 72
roles: agency_admin, platform_admin
---
# Automatisation des frais de retard

**Paramètres → Facturation → Valeurs par défaut et frais → Frais de retard** est l’endroit où votre règle est définie. Cet article explique comment le traitement automatique l’applique.

> **Important :** Le traitement des frais de retard est actuellement **désactivé pour toute la plateforme**. Aucun frais de retard n’est ajouté automatiquement tant que KiddieTrac ne l’a pas activé. Ajouter un frais change ce que les familles doivent; c’est donc une décision délibérée, prise une seule fois, et non un effet secondaire de l’enregistrement d’un paramètre.

## Quand il s’exécute

Une fois activé, le traitement s’exécute **chaque nuit à 02 h 00 (heure de Toronto)**. Si une nuit est manquée, l’exécution suivante rattrape le retard, et aucun frais n’est appliqué deux fois.

## Quelles factures reçoivent un frais

Une facture reçoit des frais de retard seulement si **toutes** ces conditions sont réunies :

- Elle a été créée dans KiddieTrac. Les factures synchronisées depuis un système de facturation externe ne sont pas touchées.
- Son statut est délivrée, partiellement payée ou en retard. Une facture **En attente** d’un échéancier de paiement n’est jamais facturée.
- Elle a encore un solde.
- Sa date d’échéance, plus vos **jours de grâce**, est passée.
- Elle n’a pas déjà reçu de frais de retard **ce mois civil**.
- Le pourcentage de frais de retard de votre agence est supérieur à 0.

## Ce qui arrive à la facture

1. Une ligne nommée **Frais de retard · AAAA-MM** est ajoutée à la facture, pour le mois où le frais a été imputé.
2. Le total et le solde de la facture augmentent du montant du frais.
3. Une facture délivrée qui n’était pas encore marquée en retard passe à **En retard**.
4. Chaque tuteur de la famille reçoit une notification dans l’application : *Frais de retard appliqués à la facture …*, avec le montant.
5. Le frais est inscrit au journal d’audit.

Le frais correspond au solde × votre pourcentage, plafonné à votre plafond. Voir [Configuration des frais de retard](late-fee-config) pour les paramètres.

## Arrêter les frais de retard pour une facture

Une facture payée ou annulée n’est jamais facturée. Pour arrêter les frais sur une facture qui ne doit pas être relancée, réglez-la ou annulez-la dans la **Comptabilité**. Pour arrêter les frais de retard dans toute votre agence, mettez **Frais de retard (%)** à 0.

## Questions courantes

??? Pourquoi aucun frais de retard n’a-t-il été imputé alors qu’une facture est en retard? | Le traitement des frais de retard à l’échelle de la plateforme est actuellement désactivé. Vos paramètres sont enregistrés et prendront effet lorsqu’il sera activé.
??? Une famille peut-elle être facturée deux fois dans un même mois? | Non. Une facture reçoit au plus un frais de retard par mois civil.
??? Les frais de retard s’appliquent-ils aux factures synchronisées? | Non. Seulement aux factures créées dans KiddieTrac.

Voir aussi : [Configuration des frais de retard](late-fee-config) · [Rappels de facturation automatisés](automated-billing-reminders) · [Comptabilité](accounting)
