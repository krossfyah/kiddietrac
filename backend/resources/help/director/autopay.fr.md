---
title: Cycle de paiement (prélèvements automatiques)
category: Billing
order: 76
roles: agency_admin, platform_admin
---
# Cycle de paiement (prélèvements automatiques)

**Finances → Cycle de paiement** prélève en une seule fois les factures ouvertes de toutes les familles inscrites au prélèvement automatique, sur leur carte enregistrée. Vous vérifiez la liste, décochez les familles à retenir, puis lancez le traitement. Le prélèvement automatique s’exécute aussi tout seul chaque nuit à 3 h, et chaque traitement (manuel ou de nuit) est conservé sous **Traitements précédents**, pour voir qui a été prélevé et qui ne l’a pas été.

[[open: #payment-run | Ouvrir le cycle de paiement]]

## Avant de commencer

- [ ] Un processeur de cartes est connecté dans **Paramètres → Prestataires de paiement**. Sinon, une note jaune ⚠️ en haut indique que rien ne peut encore être prélevé, et **Prélever les éléments sélectionnés** reste grisé.
- [ ] Les familles ont enregistré une carte et activé le prélèvement automatique (voir « Comment une famille active le prélèvement automatique » plus bas).
- [ ] Les factures à encaisser sont émises (les brouillons ne sont jamais prélevés).

## Prélever maintenant les familles en prélèvement automatique

1. Ouvrez **Finances → Cycle de paiement**.
2. Regardez **Ready to charge**. Chaque ligne affiche la **Famille** (et le service de garde), le numéro de **Facture**, l’**Échéance**, la **Carte** enregistrée et le **Montant**. Les factures en retard portent une étiquette rouge **En retard**.
3. Décochez toute famille à retenir cette fois-ci (par exemple, une famille qui a appelé pour dire qu’elle paiera autrement). La case de l’en-tête (**Tout sélectionner**) coche ou décoche toutes les lignes.
4. Vérifiez le total à côté de **Selected:** en bas, par exemple « 12 invoices · $4,380.00 ».
5. Appuyez sur **Prélever les éléments sélectionnés**.
6. Lisez la confirmation (« Charge 12 invoices? ») et appuyez sur le bouton **Charge $…** pour continuer, ou annulez.

Pendant le traitement, le bouton affiche **Charging…**. À la fin, un message indique combien de prélèvements ont réussi et combien ont échoué (« 11 charged ($4,015.00), 1 failed »), les deux listes se mettent à jour, et le détail du traitement s’ouvre tout seul.

> ⚠️ Un prélèvement ne peut pas être annulé depuis cet écran. Pour rendre de l’argent, utilisez **Finances → Remboursements**.

## Faire le suivi des familles non couvertes par le prélèvement automatique

La liste **Not covered by auto-pay** montre les familles qui doivent de l’argent mais n’ont aucune carte enregistrée pour le prélèvement automatique, avec le nombre de **Invoices**, l’**Échéance la plus ancienne** et le montant **À payer**. Personne dans cette liste n’est prélevé ni considéré comme ayant payé.

1. Repérez la famille dans **Not covered by auto-pay**.
2. Ouvrez sa facture dans **Finances → Comptabilité**, ou son compte dans **Finances → Grands livres des comptes**.
3. Envoyez un rappel, ou consignez le paiement sur la facture quand il arrive (virement Interac, comptant, chèque).

## Consulter un traitement précédent

1. Descendez jusqu’à **Traitements précédents**. Chaque ligne affiche **Quand**, le **Type** (**Nightly (automatic)** ou **Manuelle** avec le prénom de la personne qui l’a lancé), le **Résultat**, le nombre de prélèvements **Facturé** et **Échec**, et le montant **Encaissé**.
2. Cliquez sur une ligne pour l’ouvrir.
3. Lisez le **Résultat** de chaque famille : **Facturé**, **Échec** ou **Non débité**. Une ligne en échec ou ignorée affiche la raison en dessous (par exemple, carte refusée ou expirée).
4. Appuyez sur **Fermer**.

Le résultat du traitement est **Terminé** quand tout est passé, **Certains ont échoué** quand une partie a échoué, **Échec** quand rien n’est passé, et **Aucun processeur de paiement** quand aucun processeur de cartes n’était connecté à ce moment.

## Comment une famille active le prélèvement automatique

Les familles le font elles-mêmes dans le portail des familles : **Prélèvement automatique → + Add card**, elles saisissent la carte dans le formulaire sécurisé, puis **Enregistrer la carte**. Le prélèvement automatique s’active dès que la carte est enregistrée. Les données de la carte vont directement au processeur de cartes et n’atteignent jamais KiddieTrac. Pour arrêter, la famille appuie sur **Désactiver le prélèvement automatique**; la carte reste au dossier, et elle peut le réactiver plus tard avec **Activer le prélèvement automatique**.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d’agence | Ouvrir le cycle de paiement, prélever les familles, consulter tous les traitements précédents. |
| Administrateur de plateforme | La même chose, pour l’agence dans laquelle il travaille. |
| Directeur de service de garde | Ne voit pas cet écran. Prélever des cartes déplace l’argent de l’agence; cela reste donc aux administrateurs d’agence. |
| Familles | Enregistrer une carte et activer ou désactiver le prélèvement automatique dans leur propre portail. Elles ne voient jamais cet écran. |

## FAQ et quand quelque chose ne va pas

??? Le bouton Prélever les éléments sélectionnés est grisé. | Soit aucune ligne n’est cochée, soit aucun processeur de cartes n’est connecté. La note jaune en haut indique « No card processor is connected for this agency, so nothing can be charged yet. » Connectez-en un dans Paramètres → Prestataires de paiement.
??? La liste Ready to charge est vide. | Elle indique « No auto-pay family has an open balance right now. » Soit toutes les familles en prélèvement automatique sont à jour, soit les familles qui doivent n’ont pas de carte au dossier (elles apparaissent sous Not covered by auto-pay).
??? Je vois « A payment run is already in progress for this agency. » | Quelqu’un d’autre (ou le traitement de nuit) prélève en ce moment. Attendez une minute, rechargez la page et vérifiez les traitements précédents avant de réessayer, pour que personne ne soit prélevé deux fois.
??? La carte d’une famille a échoué. Que se passe-t-il? | Rien n’est prélevé et la facture reste ouverte. La famille reçoit un avis indiquant que le paiement a échoué. Une fois sa carte mise à jour, le traitement suivant (manuel ou de nuit) réessaie.
??? Le traitement de nuit prélève-t-il les familles que j’ai décochées? | Oui. Décocher une famille la retient seulement de ce traitement manuel. Le traitement de nuit prélève toutes les familles en prélèvement automatique qui ont un solde ouvert. Pour qu’une famille ne soit pas prélevée, demandez-lui de désactiver le prélèvement automatique.
??? J’ai prélevé le mauvais montant. | Rendez l’argent avec un remboursement dans Finances → Remboursements. Le remboursement est signé et la famille en est avisée.
??? Les familles reçoivent-elles un courriel quand elles sont prélevées? | Oui, une facture payée envoie à la famille sa confirmation de paiement habituelle. Un prélèvement échoué envoie plutôt un avis d’échec de paiement.

## Écrans connexes

[[open: #payment-providers | Prestataires de paiement]] [[open: #refunds | Remboursements]] [[open: #account-ledgers | Grands livres des comptes]] [[open: #external-billing | Comptabilité]]

Voir aussi : [Prestataires de paiement](payment-providers) · [Rembourser un paiement](refunds) · [Grands livres des comptes](account-ledgers)
