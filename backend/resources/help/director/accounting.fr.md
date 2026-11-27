---
title: Comptabilité (toutes les factures dans une seule liste)
category: Billing
order: 38
roles: agency_admin, centre_director, platform_admin
---
# Comptabilité

**Finances → Comptabilité** réunit chaque facture de votre agence dans une seule liste, peu importe avec qui elle est et où elle a été créée. D'ici, vous ouvrez les factures, enregistrez les paiements, confirmez les virements Interac, renvoyez, modifiez et annulez. Ouvrez-la quand de l'argent arrive, quand une famille redemande sa facture ou quand une facture doit être annulée.

[[open: #external-billing | Ouvrir la comptabilité]]

## Ce que contient la liste

La Comptabilité lit les trois endroits d'où une facture peut venir ; aucun argent ne manque donc :

- **Factures KiddieTrac** : créées dans KiddieTrac, par exemple par la Facturation en lot ou un plan de paiement. Elles portent l'étiquette **KIDDIETRAC** sous le numéro.
- **Factures synchronisées** : créées dans un système de facturation externe, puis synchronisées.
- **Factures de bénéficiaires** : de l'argent facturé à une personne qui n'est pas une famille, ou payé à celle-ci, comme une éducatrice ou un entrepreneur. La ligne indique **Créée dans Ma paie** au lieu d'offrir des actions.

Les cartes **En souffrance** et **Collected** du haut additionnent ce que la liste affiche ; elles décrivent donc toujours le filtre choisi.

> ℹ️ Une facture en attente d'un plan de paiement est listée, mais n'est pas comptée comme en souffrance. Personne n'a encore été invité à payer ce montant. Voir [Plans de paiement](payment-plans).

## Trouver une facture

1. Choisissez un onglet : **En souffrance** (tout sauf les annulées), **Payé**, **En retard**, **Annulée** ou **e-Transfers to confirm**.
2. Dans la première liste, choisissez avec qui est la facture : **Tout le monde**, **Parents**, **Éducateurs** ou **Prestataires** (le mot suit le paramètre de votre agence), **Entrepreneurs** ou **Autre** (tout ce qui n'a pas encore de type, listé pour être vérifié).
3. Facultatif : choisissez une famille dans la liste des familles. Choisir autre chose que Parents efface le filtre famille, car un entrepreneur n'a pas de famille.
4. Facultatif : cherchez par numéro de facture, description ou statut.

[[show: #external-billing @ Voided | Montrez-moi l’onglet Annulée]]

## Enregistrer un paiement reçu

1. Trouvez la facture et ouvrez son menu **⋮**.
2. Choisissez **Enregistrer le paiement**. La fenêtre affiche l'**Invoice total** et ce qui est **En souffrance**.
3. Vérifiez le **Montant réglé**. Il commence au solde complet ; un paiement partiel est permis.
4. Choisissez le **Mode de paiement** : virement Interac / TEF, comptant, chèque, virement bancaire, carte (prise hors ligne) ou autre.
5. Réglez la **Date de réception** et tapez le **Numéro de référence** (numéro du virement ou du chèque). Ajoutez une **Note (optional)** au besoin.
6. Si votre agence applique des frais supplémentaires pour ce mode, une case propose d'ajouter les frais de service. Elle reste décochée à moins que vous la cochiez. Voir [Frais supplémentaires de paiement](payment-surcharges).
7. Appuyez sur **Enregistrer le paiement**. Le solde et le statut de la ligne se mettent à jour.

## Confirmer un virement Interac signalé par une famille

Quand une famille indique dans son écran de facturation qu'elle a envoyé un virement, il vous attend dans l'onglet **e-Transfers to confirm** (l'onglet affiche combien).

1. Ouvrez l'onglet **e-Transfers to confirm**, ou utilisez **⋮ > Confirm e-Transfer** sur la facture.
2. La fenêtre **Confirm Interac e-Transfer** montre de qui il vient, l'**Amount sent**, la **Reference** et les factures visées.
3. Vérifiez dans votre banque que ce virement est bien arrivé avant de l'approuver.
4. Si votre banque affiche un autre montant, modifiez **Amount received**.
5. Appuyez sur **✅ Approve & record payment**. Le paiement est enregistré sur la ou les factures.
6. S'il n'est pas arrivé, appuyez sur **Rejeter**, écrivez la raison sous **Why? The family is sent this**, puis appuyez sur **Reject and tell the family**.

## Demander un virement Interac à une famille

KiddieTrac n'envoie jamais la demande d'argent lui-même ; c'est votre centre qui le fait, à partir de la fonction Demander des fonds de sa propre banque.

1. Sur une facture KiddieTrac ou synchronisée impayée, ouvrez **⋮ > Envoyer une demande de virement Interac**.
2. La fenêtre présente ce que le formulaire de votre banque demande, chaque élément avec un bouton **Copy**, et les coordonnées du tuteur payeur sous **Request it from**. Seules les coordonnées déjà au profil d'un tuteur sont proposées.
3. Dans votre banque, ouvrez Virement Interac → Demander des fonds et collez les renseignements.
4. De retour dans KiddieTrac, appuyez sur **Marquer comme demandé**. La facture garde trace de la demande, et une famille qui en avait demandé une est invitée à regarder dans l'application de sa banque.

## Autres actions sur une ligne

**Sur une facture KiddieTrac :** **Voir la facture** (le personnel la voit sans le bouton Payer de la famille), **Enregistrer le paiement**, **Renvoyer la facture**, **Modifier** (pour qui elle est et ce qu'elle contient), **Copies aux tuteurs** (la copie de chaque tuteur, quand la facture est partagée), **Télécharger la facture** et **Annuler la facture**.

**Sur une facture synchronisée :** **Voir** et **Télécharger** quand la source fournit un document, **Modifier**, **Renvoyer la facture**, **Enregistrer le paiement** et **Annuler la facture**.

> ⚠️ Une facture synchronisée appartient au système de facturation d'où elle vient. La modifier ou l'annuler ne change que la copie de KiddieTrac, et la prochaine synchronisation de ce système peut écraser votre changement. Faites le même changement à la source s'il doit durer.

## Annuler une facture

1. Ouvrez le menu **⋮** de la facture et choisissez **Annuler la facture**.
2. Donnez la raison demandée et confirmez.
3. La facture garde son numéro et reste dans la liste, marquée **Void**, sous l'onglet **Annulée**.

Ce qui se passe aussi : si un plan de paiement l'a créée, ce versement est retiré du plan. La famille reçoit un courriel lui disant que la facture est annulée et qu'elle ne doit rien, et le bureau reçoit aussi un courriel. Rien n'est envoyé si le courriel de votre agence est désactivé.

Pour en annuler plusieurs, cochez les lignes et utilisez la barre d'actions groupées (**Télécharger** ou annuler). L'annulation groupée montre d'abord le total annulé, et saute toute facture qui a de l'argent reçu ou qui est déjà annulée.

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Administration de l'agence | Tout sur cet écran, pour chaque centre |
| Direction de centre | Tout sur cet écran, pour ses propres centres |
| Administration de la plateforme | Tout, pour l'agence choisie dans le sélecteur de la barre du haut |
| Éducatrice, parent | Aucun accès. Les parents voient leurs factures dans leur onglet **Facturation** |

## Questions fréquentes

??? Annuler la facture est absent ou refusé. | Une facture sur laquelle de l'argent a été payé ne peut pas être annulée. Remboursez d'abord (Finances → Remboursements), puis annulez. Une facture avec un paiement en ligne en cours doit aussi attendre que ce paiement passe ou échoue.
??? Envoyer une demande de virement Interac est absent. | L'action n'apparaît que sur une facture avec un solde qui n'est pas annulée, payée, en brouillon ou remboursée.
??? La fenêtre de demande indique « No email or mobile on their profile. » | Ajoutez un courriel ou un numéro de cellulaire au profil du tuteur payeur, puis réessayez.
??? La liste est vide. | Vérifiez l'onglet et le filtre « avec qui ». Les factures annulées ne s'affichent que dans l'onglet Annulée ; un filtre famille cache toutes les autres.
??? Un versement en attente est listé mais pas compté En souffrance. | Il n'est pas encore émis ; personne ne le doit. Il compte dès le début de son préavis.
??? Enregistrer le paiement envoie-t-il un courriel à la famille? | Sur une facture KiddieTrac, oui : le payeur reçoit un reçu par courriel et, une fois la facture payée au complet, la famille et vous êtes avisés. Rien n'est envoyé si le courriel de votre agence est désactivé.
??? La fenêtre de virement indique « That e-Transfer no longer exists. » | Quelqu'un d'autre l'a déjà traité. Actualisez l'onglet.

## Écrans connexes

[[open: #payment-plans | Plans de paiement]] [[open: #refunds | Remboursements]] [[open: #billing-schedule | Calendrier de facturation]]

Voir aussi : [Grands livres des comptes](account-ledgers) · [Rembourser un paiement](refunds) · [Plans de paiement](payment-plans) · [Signification des statuts de facture](invoice-statuses)
