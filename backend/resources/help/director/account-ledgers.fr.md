---
title: Grands livres des comptes
category: Billing
order: 40
roles: agency_admin, platform_admin
---
# Grands livres des comptes

**Finances → Grands livres des comptes** répond à quatre questions sur n'importe quelle personne de votre agence : ce qui lui a été facturé, ce qu'elle a payé, ce qu'elle doit encore et ce qui s'en vient. Ouvrez-le quand un parent vous questionne sur son solde, avant d'envoyer un relevé ou avant d'émettre un remboursement.

[[open: #account-ledgers | Ouvrir les grands livres des comptes]]

![Le grand livre : choisissez une personne, et tout son historique financier tient sur une page](https://api.kiddietrac.com/help-img/account-ledger.png)

## Ouvrir le grand livre d'une personne

1. Dans le menu latéral, ouvrez **Finances** et choisissez **Grands livres des comptes**.
2. Facultatif : choisissez un **Rôle** pour limiter la liste aux parents, éducatrices, directions, etc. Chaque rôle indique combien de comptes il compte.
3. Facultatif : tapez un nom ou un courriel dans la recherche et appuyez sur **Entrée**. La recherche attend Entrée ; elle ne filtre pas pendant la saisie.
4. Choisissez la personne dans la liste **Compte**. Chacune apparaît avec son nom et son rôle entre parenthèses, par exemple *Maya (Parent)*.
5. Le grand livre s'ouvre : **Account details**, les chiffres du haut, les graphiques, **Toujours impayé**, **À venir** et l'**Historique du compte**.

> ℹ️ La liste des comptes n'affiche volontairement pas les soldes. Dans une liste de soixante personnes, ce serait soixante montants à parcourir pour trouver quelqu'un, et le solde est la première chose que le grand livre affiche une fois ouvert.

## Voir tous les comptes d'un coup

1. Appuyez sur **⊞ Tous les comptes**. Chaque compte est listé avec ce qu'il doit, et les totaux **Billed**, **Collected**, **En souffrance**, **Annulée** et **Versé** s'affichent en haut.
2. Utilisez le filtre pour afficher **Tout le monde**, seulement les comptes **Owing money**, ou seulement les personnes **Paid by us** (le personnel et les bénéficiaires que vous payez).
3. Sur une ligne, utilisez **⋮ > 📄 Ouvrir le grand livre** pour ouvrir le grand livre de la personne, ou **⋮ > ✉️ Envoyer le relevé par courriel** pour lui envoyer son relevé.
4. Appuyez sur **📄 Un compte** pour revenir à la vue d'un seul compte.

## Lire les chiffres

Les chiffres concordent toujours :

> 💡 **Facturé − (Reçu − Remboursé) = En souffrance**

- **Facturé** : tout ce qui a été facturé, sauf ce qui a été annulé. Une facture annulée ne porte aucun solde et apparaît dans son propre chiffre **Annulée**.
- **Reçu** : ce que les factures elles-mêmes indiquent comme réglé.
- **Remboursé** : l'argent rendu. Un remboursement « dé-encaisse » : le solde remonte exactement du montant rendu.
- **En souffrance** : ce qui est encore dû aujourd'hui. **En retard** est la partie dont l'échéance est passée.
- **À payer ce mois-ci**, **Manqués** et **Scheduled in total** apparaissent quand la personne a des versements ou une facturation récurrente à venir.

**L'argent est compté une seule fois, à partir de la facture.** Quand un paiement est enregistré, il apparaît sur sa propre ligne, avec le mode, la référence et la vraie date, mais il n'est pas additionné une deuxième fois. Si les deux ne concordent pas, c'est la facture qui a raison.

**Un trop-perçu est signalé, jamais compensé.** Si on a reçu plus que ce qui a été facturé, un chiffre **Trop-perçu** l'indique. À vous de décider s'il s'agit d'un crédit pour la famille ou d'un paiement enregistré deux fois : c'est donc affiché plutôt qu'absorbé en silence.

### À venir

Tout ce qui est encore attendu sur le compte : chaque paiement d'un plan de paiement et toute facturation récurrente. Chaque ligne nomme la facture derrière le montant, et son **⋮** ouvre, télécharge ou envoie cette facture par courriel. Deux cas sont signalés :

- **No invoice raised yet.** Un calendrier de facturation dit seulement « nous émettrons quelque chose à cette date ». Le montant n'est fixé qu'à l'émission ; il n'y a donc rien à ouvrir.
- Le total de la facture diffère du montant prévu : habituellement, la facture a été modifiée après l'entente. À vérifier.

Un paiement manqué reste dans ce tableau, marqué comme manqué. C'est l'élément le plus utile de la page.

### Toujours impayé et l'historique

**Toujours impayé** liste chaque facture impayée, de la plus ancienne à la plus récente, avec le nombre de jours de retard. Le **⋮ > 📄 Document de la facture** de chaque ligne l'ouvre. L'**Historique du compte**, plus bas, liste chaque ligne ; filtrez-le avec **Tout**, **Factures**, **Paiements**, **Remboursements**, **Annulations** ou **Versé**.

## Générer et envoyer un relevé

1. Ouvrez le grand livre de la personne.
2. Appuyez sur **📄 Générer un relevé**. La fenêtre **Générer un relevé de compte** s'ouvre.
3. Choisissez une **Période** : **Depuis le début**, **Ce mois-ci**, **Le mois dernier**, **3 derniers mois**, **Cette année** ou **Custom range…** (remplissez alors **From** et **To**).
4. Appuyez sur **👁 Voir** pour le lire à l'écran, **⤓ Télécharger** pour le PDF, ou **✉️ Email…** pour l'envoyer.
5. Pour l'envoi : vérifiez l'adresse **Envoyer à** (préremplie avec celle du compte), ajoutez une note facultative, cochez **Me mettre en copie** si vous voulez une copie, puis appuyez sur **Envoyer le relevé**.
6. Un message confirme « Statement emailed to » suivi de l'adresse à laquelle il est réellement parti.

Le relevé porte l'image de marque de votre agence, les coordonnées du titulaire du compte et chaque ligne : factures, reçus, remboursements, annulations et ce qui reste dû. Sur une période, il montre aussi le solde reporté au début et à la fin.

> ⚠️ L'adresse est demandée chaque fois et doit être confirmée. Il n'y a aucun destinataire par défaut, car un relevé envoyé à la mauvaise personne ne peut pas être repris.

## Émettre un remboursement à partir du grand livre

1. Ouvrez le grand livre de la personne et appuyez sur **↩ Remboursement**. La fenêtre **Émettre un remboursement** liste les factures sur lesquelles il reste de l'argent remboursable.
2. Choisissez la facture et, si elle a été payée en plusieurs paiements, choisissez **Quel paiement**.
3. Entrez le **Montant** (il commence au maximum remboursable) et choisissez un **Motif**, par exemple **Demandé par la famille**, **Paiement en trop** ou **Débit en double**. Ajoutez des **Notes** au besoin.
4. Sous **Approbation**, tapez **Votre nom complet** et signez dans le cadre.
5. Appuyez sur **Approuver le remboursement**. Le grand livre se recharge et le solde bouge aussitôt.

Pour savoir comment l'argent retourne à la famille, voyez [Rembourser un paiement](refunds).

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Administration de l'agence | Tout sur cet écran, pour chaque compte de l'agence |
| Administration de la plateforme | La même chose, pour l'agence choisie dans le sélecteur de la barre du haut |
| Direction de centre | N'a pas cet écran. Les directions utilisent **Finances → Comptabilité**, qui couvre leurs centres |
| Éducatrice, parent | Aucun accès. Les parents voient leur propre solde dans leur onglet **Facturation** |

## Questions fréquentes

??? Générer un relevé et Remboursement sont grisés. | Ils ne fonctionnent qu'une fois un compte ouvert. Choisissez d'abord une personne dans la liste Compte.
??? La personne cherchée n'est pas dans la liste. | Vérifiez que le filtre Rôle ne restreint pas la liste, videz la recherche et appuyez sur Entrée. Les dossiers fermés sont exclus.
??? La fenêtre de remboursement dit que rien ne peut être remboursé. | « Nothing on this account has money against it that can be refunded. » Une facture n'y apparaît qu'une fois un paiement reçu et pas déjà rendu.
??? Le message dit qu'aucun argent n'a encore été envoyé. | « Refund approved … no money has been sent yet » signifie que le remboursement est enregistré, mais que l'argent est rendu en dehors de KiddieTrac (comptant, chèque ou virement Interac). Envoyez-le comme d'habitude.
??? Le relevé n'est pas parti par courriel. | Si l'interrupteur courriel de votre agence est éteint, ou si une règle de livraison bloque cette adresse, on vous le dit clairement. « Envoyé » veut dire ici qu'il est vraiment parti.
??? Pourquoi une ligne À venir dit-elle qu'aucune facture n'est émise? | Elle vient d'un calendrier de facturation. Le montant n'est connu qu'à l'émission de la facture.
??? Erreur « The signature pad did not load ». | Rechargez la page. Un remboursement ne peut pas être approuvé sans signature.

## Écrans connexes

[[open: #external-billing | Comptabilité]] [[open: #payment-plans | Plans de paiement]] [[open: #refunds | Remboursements]]

Voir aussi : [Comptabilité](accounting) · [Rembourser un paiement](refunds) · [Plans de paiement](payment-plans) · [Calendrier de facturation](weekly-billing)
