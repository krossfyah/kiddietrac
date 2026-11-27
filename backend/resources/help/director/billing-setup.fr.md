---
title: Configuration et paramètres de facturation
category: Billing
order: 10
roles: agency_admin, platform_admin
---
# Configuration et paramètres de facturation

**Paramètres → Facturation** regroupe tout ce qui régit ce que votre agence facture et comment : taxes, grilles de frais, rabais fratrie, valeurs par défaut des factures, frais de retard, modes de paiement, frais supplémentaires, courriels de facturation et rappels. Configurez-le une fois au moment de commencer à facturer dans KiddieTrac, et revenez-y quand un taux ou une règle change.

[[open: #billing-settings | Ouvrir les paramètres de facturation]]

## Les onglets

- **🧾 Taxe** : votre taux de taxe et son nom sur la facture.
- **💵 Forfaits de frais** : des grilles de frais réutilisables. Voir [Forfaits de frais](tuition-plans).
- **👨‍👩‍👧 Rabais fratrie** : voir [Rabais fratrie](sibling-discounts).
- **⚙️ Valeurs par défaut et frais** : valeurs par défaut des factures, plans de paiement, frais de retard, modes de paiement, prélèvement automatique et frais supplémentaires.
- **🛠 Communications de facturation** : les interrupteurs courriel et texto de l'agence et la langue par défaut.
- **🔔 Rappels** : rappels automatiques des factures à venir et en retard. Voir [Rappels de facturation automatiques](automated-billing-reminders).

L'administration de la plateforme voit aussi **🏦 Facturation de la plateforme** : ce que KiddieTrac facture aux agences, et non ce que les agences facturent aux familles. Voir [Factures de la plateforme](platform-invoices).

L'écran se souvient du dernier onglet utilisé.

## Définir votre taux de taxe

1. Ouvrez **Paramètres → Facturation**. L'onglet **🧾 Taxe** s'ouvre en premier.
2. Entrez le **Taux** en pourcentage du sous-total de la facture, par exemple 13.
3. Dans **Appelé**, tapez le nom imprimé sur la facture, par exemple TVH ou TPS.
4. Cochez **Tick "Apply tax" by default on new invoices** si votre agence est inscrite pour percevoir la taxe. Sinon, laissez-la décochée.
5. Vérifiez la ligne d'exemple sous les champs : elle montre ce que le taux ajoute à une facture de 100,00 $. Au-delà de 30 %, un avertissement signale une probable faute de frappe.
6. Appuyez sur **Enregistrer les modifications**. « ✓ Saved » apparaît près du bouton et le message **Paramètres de facturation enregistrés** le confirme.

La taxe s'applique au sous-total : le montant de base plus les lignes ajoutées. Sur chaque facture, elle peut encore être modifiée ou retirée, par exemple pour un bénéficiaire qui n'est pas taxé.

## Définir les valeurs par défaut et les frais

[[show: #billing-settings @ Defaults & fees > Billing defaults | Afficher l’onglet Valeurs par défaut et frais]]

1. Ouvrez l'onglet **⚙️ Valeurs par défaut et frais**.
2. Sous **Paramètres de facturation par défaut**, choisissez la **Fréquence par défaut** (hebdomadaire, aux deux semaines ou mensuelle) et entrez le **Deposit** et le **Registration fee** présélectionnés quand vous créez des factures et des grilles de frais.
3. Sous **Plans de paiement**, réglez **Émettre les factures ce nombre de jours avant leur échéance** (5 par défaut, jusqu'à 60 ; 0 émet à la date d'échéance même).
4. Dans la même carte, réglez **Afficher les factures impayées aux familles ce nombre de jours avant leur échéance** (14 par défaut). L'écran Facturation d'une famille et son total « à payer maintenant » n'incluent que les factures dues dans cette fenêtre ; les factures en retard s'affichent toujours.
5. Sous **Frais de retard**, entrez les **Frais de retard (%)** sur le solde en retard, le **Cap** (plafond) par frais et le **Délai de grâce (jours)** après l'échéance.
6. Sous **Modes de paiement acceptés**, cochez chaque façon dont les familles peuvent payer : carte, virement bancaire (ACH), virement Interac, comptant, chèque ou autre.
7. Sous **Prélèvement automatique**, cochez **Inscrire les nouvelles familles au prélèvement automatique par défaut** et, au besoin, **Exiger le prélèvement automatique (les familles doivent garder une carte au dossier)**.
8. Sous **Frais supplémentaires de paiement**, entrez un taux **Credit / debit card (%)** et un taux **EFT / Interac e-Transfer (%)** (chacun jusqu'à 10 %, 0 par défaut). L'exemple dessous montre ce que chacun ajoute à un paiement de 500,00 $.
9. Appuyez sur **Enregistrer la configuration de facturation**. « ✓ Saved. » apparaît et le message **Configuration de la facturation enregistrée** le confirme.

### Ce que font ces paramètres

- **Préavis des plans de paiement** : chaque versement reste un brouillon en attente jusqu'à ce nombre de jours avant son échéance, puis il est émis tout seul et la famille le doit. Les dates d'échéance ne changent jamais ; seul le préavis change. Voir [Plans de paiement](payment-plans).
- **Frais de retard** : si vous ne les avez jamais modifiés, ils indiquent 1,5 %, 25 $ et 0 jour. Voir [Configuration des frais de retard](late-fee-config).
- **Virement Interac** : la ligne sous les modes de paiement indique où arrivent les virements, ou dit « Interac e-Transfer is not set up yet. » avec un lien **Set up in Payment providers →**.
- **Credit/debit card (shown on invoices)** : ajoute « Payer par carte de crédit ou de débit dans l'application » à la section Comment payer des factures et des courriels de facture, une fois vos paiements par carte actifs.
- **Annoncer les nouvelles factures aux familles** : chaque nouvelle facture apparaît aussi dans les Nouvelles du parent payeur, pour lui seul, avec son montant et les boutons Voir la facture / Payer.
- **Les frais supplémentaires** s'appliquent au montant payé, pas au total de la facture, et jamais au comptant ni au chèque. Les frais apparaissent sur leur propre ligne de la facture. Voir [Frais supplémentaires de paiement](payment-surcharges).

> ⚠️ Le calcul automatique des frais de retard est actuellement désactivé pour toute la plateforme ; enregistrer un taux ici ne commence donc à facturer personne. Voir [Automatisation des frais de retard](late-fees).

## Courriel, texto et langue de facturation

1. Ouvrez l'onglet **🛠 Communications de facturation**.
2. Cochez ou décochez **✉️ Enable email for this agency (invoices, reminders, announcements)**.
3. Cochez ou décochez **📱 Enable SMS for this agency (billing reminders, alerts)**.
4. Choisissez la **Langue par défaut** des messages de facturation destinés aux parents.
5. Appuyez sur **Enregistrer les paramètres**.

> ⚠️ Désactiver le courriel arrête les courriels de facture, les rappels de facturation et les annonces pour toute l'agence, y compris les relevés et les renvois à partir de la Comptabilité.

## Qui peut faire quoi

- **Administration de l'agence** : tous les onglets.
- **Administration de la plateforme** : tous les onglets pour l'agence choisie dans le sélecteur de la barre du haut, plus **🏦 Facturation de la plateforme**.
- **Directions de centre** : n'ont pas cet écran. Les rappels de facturation sont leur seul paramètre de facturation ; demandez le reste à votre administration d'agence.
- **Éducatrices** et **parents** : aucun accès.

## Questions fréquentes

??? L'onglet Taxe indique « This section is available to agency administrators ». | Votre rôle ne peut pas modifier les paramètres de facturation de l'agence. Demandez à votre administration d'agence.
??? Un onglet indique « Could not load this section. » | Rechargez la page. Si le problème persiste, communiquez avec le soutien KiddieTrac.
??? J'ai enregistré des frais de retard, mais personne n'a été facturé. | Le calcul automatique des frais de retard est désactivé pour toute la plateforme. Rien n'est facturé tant qu'il n'est pas activé.
??? Changer le taux de taxe modifie-t-il les anciennes factures? | Non. Le taux s'applique aux nouvelles factures. Les factures déjà émises gardent leur propre taxe.
??? Changer les jours de préavis déplace-t-il les plans de paiement existants? | Non. Un plan garde le préavis choisi à sa création ; la nouvelle valeur sert de point de départ aux nouveaux plans.
??? L'enregistrement a échoué. | Le message après « Save failed: » en donne la raison, par exemple une valeur hors limites. Corrigez le champ et enregistrez de nouveau.

## Écrans connexes

[[open: #payment-providers | Fournisseurs de paiement]] [[open: #external-billing | Comptabilité]] [[open: #payment-plans | Plans de paiement]]

Voir aussi : [Frais supplémentaires de paiement](payment-surcharges) · [Fournisseurs de paiement](payment-providers) · [Plans de paiement](payment-plans) · [Configuration des frais de retard](late-fee-config) · [Style et numérotation des factures](invoice-numbering-and-style)
