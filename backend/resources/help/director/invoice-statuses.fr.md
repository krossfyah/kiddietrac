---
title: Ce que signifie le statut d’une facture
category: Billing
order: 41
roles: agency_admin, centre_director, platform_admin
---
# Ce que signifie le statut d’une facture

![Factures, les factures à date future étant marquées Planifiée](https://api.kiddietrac.com/help-img/invoices-scheduled.png)

La Comptabilité et les échéanciers de paiement affichent la même pastille de couleur avec le même mot, de sorte qu’une facture se lit de la même façon sur les deux écrans.

| Statut | Signification |
|---|---|
| **En attente** | Pas encore délivrée. Facture d’un échéancier de paiement qui attend sa date d’émission. Personne ne la doit encore. |
| **Planifiée** | Délivrée, impayée et **pas encore échue**. Aucun paiement n’a été manqué. |
| **Délivrée** | Délivrée, échue maintenant ou dont la date est déjà passée, et impayée. |
| **En retard** | Date d’échéance dépassée. |
| **Partiellement payée** | Une partie de la somme a été reçue. |
| **Payée** | Réglée. |
| **Remboursée** | L’argent a été remboursé. |
| **Annulée** | Annulée. Ne comporte aucun solde. |

## Pourquoi le statut Planifiée existe

À tout moment, la plupart des factures impayées ne sont simplement pas encore échues. Les qualifier d’« ouvertes » noie celles qui doivent vraiment être relancées : sur cette plateforme, 159 des 166 factures ouvertes avaient une date future, si bien qu’une liste de 166 éléments n’en comptait en réalité que 7.

**Planifiée** dit la vérité : la facture est au calendrier et personne n’a rien manqué.

## Comment il est déterminé

Il n’est pas enregistré. Une facture devient échue avec le temps qui passe; le statut est donc calculé **à l’affichage de l’écran**, à partir de la date d’échéance comparée à aujourd’hui dans le fuseau horaire de votre agence. Il ne peut jamais être périmé et aucune tâche nocturne ne risque d’échouer.

Trois statuts ne sont volontairement jamais renommés :

- **Partiellement payée** : de l’argent a déjà été reçu; ce fait l’emporte sur le calendrier.
- **En attente** : pas délivrée du tout, ce qui est différent de « pas encore échue ».
- **En retard** : par définition, la date d’échéance est dépassée.

Une facture échue **aujourd’hui** reste Délivrée. Elle est due, mais elle n’est pas en retard.

## Ce que cela ne change pas

Seul le mot change. Les totaux à payer incluent toujours les sommes planifiées, car elles seront dues tôt ou tard; le grand livre indique **En retard** séparément lorsque vous ne voulez voir que ce qui est en retard. Les factures **En attente** font exception : elles n’ont pas été délivrées et ne sont donc comptées nulle part comme des sommes à payer.

Voir aussi : [Échéanciers de paiement](payment-plans) · [Comptabilité](accounting) · [Grands livres de compte](account-ledgers)
