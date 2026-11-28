---
title: Facturation et commissions (revendeur)
---
# Facturation et commissions (revendeur)

**Facturation et commissions** montre, un mois à la fois, ce que KiddieTrac a facturé à chacune de vos agences pour son forfait, ce qui a été encaissé, ce qui reste dû et la commission que vous avez gagnée. Utilisez-le en fin de mois pour vérifier vos gains et envoyer un relevé à votre comptable.

[[open: #reseller-billing | Ouvrir Facturation et commissions]]

## Consulter un mois

1. Ouvrez **Facturation et commissions** dans la section **Reseller** du menu.
2. Choisissez le mois dans la liste en haut à droite. L'écran s'ouvre sur le mois en cours.
3. Lisez les tuiles : **Facturé ce mois-ci**, **Encaissé ce mois-ci**, **Encore dû (tous les mois)**, **Votre commission** et **Agences qui ont payé** (par exemple « 3 / 5 »).
4. Lisez la ligne sous les tuiles. Elle indique vos conditions, par exemple « Your commission: 10% of the money collected before tax. Set by the platform. »
5. Lisez le tableau, une ligne par agence : **Agency**, **Plan**, **Billed**, **Collected**, **Before tax**, **Still owed** et **Commission**.
6. Descendez jusqu'à **Invoices behind these numbers** pour voir chaque facture de forfait comptée : son numéro, sa période, son statut, son montant, et si elle a été payée ce mois-ci, facturée ce mois-ci ou si elle était due d'avant.

## Comment votre commission est calculée

- Un **pourcentage** de l'argent encaissé ce mois-là, **avant taxes** (la colonne **Before tax**, pas **Collected**).
- Plus, si vos conditions en prévoient un, un **montant fixe par agence qui a payé** ce mois-là.
- L'argent facturé mais pas encore encaissé ne rapporte rien tant qu'il n'est pas payé; il apparaît plutôt dans **Still owed**.

> ℹ️ Vos conditions sont fixées par KiddieTrac. Vous pouvez les lire ici, mais pas les modifier.

## Télécharger le relevé

1. Choisissez le mois.
2. Appuyez sur **Télécharger le relevé (CSV)**.
3. Ouvrez le fichier (nommé comme `statement-2026-11.csv`) dans un tableur. Il contient les mêmes lignes et totaux qu'à l'écran.

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Revendeur | Voit et télécharge le relevé de son propre revendeur. |
| Administrateur de la plateforme | Choisit n'importe quel revendeur dans **Revendeur affiché** et fixe les conditions sous **Revendeurs › Commission** (le bouton **Monthly statement →** y ouvre cet écran pour ce revendeur). |
| Administrateurs d'agences sous un revendeur | Ne voient ni le relevé ni la commission du revendeur. |

## FAQ et quand quelque chose ne va pas

??? On lit « No commission terms are set for this reseller yet. » | KiddieTrac n'a pas encore saisi vos conditions. Ouvrez un ticket ([[raise-a-ticket]]) pour qu'elles soient ajoutées; les mois précédents sont alors calculés avec les nouvelles conditions.
??? Ma commission est plus basse que prévu. | Elle est calculée sur l'argent encaissé **avant taxes** ce mois-là, pas sur ce qui a été facturé. Vérifiez **Still owed** pour les agences qui n'ont pas encore payé.
??? Une agence affiche 0 $ facturé. | Aucune facture de forfait n'a été émise pour elle ce mois-là (par exemple, elle est encore en essai). Consultez **Invoices behind these numbers** pour voir ce qui a été compté.
??? « Could not download the statement » s'affiche. | Votre session a peut-être pris fin. Actualisez la page, reconnectez-vous si on vous le demande et appuyez de nouveau sur **Télécharger le relevé (CSV)**.
??? Le tableau indique « No agencies yet. » | Aucune agence n'appartient encore à votre revendeur. Ajoutez-en une dans **Intégrer une agence**.
??? Quelqu'un reçoit-il un courriel quand j'ouvre cet écran? | Non. Cet écran ne fait que lire des chiffres; rien n'est envoyé à vos agences.

## Écrans liés

[[open: #reseller | Toutes mes agences]] [[open: #reseller-support | Centre d’assistance]]

- [[reseller-all-my-agencies]]
- [[managing-resellers]]
- [[reseller-guide]]
