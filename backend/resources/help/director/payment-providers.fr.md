---
title: Prestataires de paiement (Helcim, Zum Rails, Stripe)
category: Billing
order: 12
roles: agency_admin, platform_admin
---
# Prestataires de paiement

**Paramètres → Prestataires de paiement.** C’est ici que vous connectez les comptes de paiement par lesquels votre agence est payée : **Zum Rails**, **Stripe** et **Helcim**.

[[open: #payment-providers | Ouvrir Prestataires de paiement]]

Chaque agence encaisse son propre argent, et chacune saisit donc ses propres clés. Rien ne se rabat sur un compte KiddieTrac ni sur celui d’une autre agence. Tant que vos clés ne sont pas saisies et que le prestataire n’est pas activé, ce prestataire ne peut ni encaisser ni retourner d’argent pour vous.

## Lire l’écran

Il y a un onglet par prestataire. Le point à côté de chaque nom indique s’il est prêt : vert signifie qu’un identifiant fonctionnel est enregistré, et gris signifie **Pas encore configuré**. Le même statut est répété au bas de chaque onglet sous la forme **● Prêt** ou **○ Pas encore configuré**.

Chaque onglet comporte les deux mêmes commandes en haut :

- **Activé** : lorsque cette option est désactivée, aucun paiement ne peut être encaissé ni envoyé par ce prestataire, quoi qu’il y ait d’enregistré plus bas.
- **Mode** : **Bac à sable** ou **Production**. Restez en bac à sable jusqu’à ce que vous ayez testé un vrai paiement du début à la fin.

**Les secrets ne sont jamais réaffichés une fois enregistrés.** Une clé enregistrée n’apparaît que par ses quatre derniers caractères, par exemple **••••a1b2**. Laisser une case de secret vide lors de l’enregistrement conserve la clé déjà enregistrée; vous pouvez donc changer une URL sans ressaisir toutes les clés.

## Helcim (cartes de crédit et de débit)

[[show: #payment-providers @ Helcim | Afficher l’onglet Helcim]]

Helcim accepte les **cartes de crédit et de débit**, et gère les **remboursements et les annulations**. Les familles saisissent la carte dans la fenêtre propre à Helcim, de sorte qu’un numéro de carte n’atteint jamais KiddieTrac.

- [ ] Dans Helcim, allez à **All Tools → Integrations → API Access** et créez un jeton d’API avec les autorisations **Payment API** activées.
- [ ] Collez-le dans **Jeton d’API**.
- [ ] Au besoin, remplissez **ID du terminal (facultatif)**.
- [ ] Saisissez la **Devise (CAD ou USD)**.
- [ ] Cochez **Activé**, définissez le **Mode**, puis cliquez sur **Enregistrer Helcim**.
- [ ] Cliquez sur **Tester la connexion**.

**Tester la connexion** demande à Helcim s’il accepte le jeton enregistré. Vous verrez **✓ Helcim a accepté ce jeton** suivi de **EN DIRECT, de vraies cartes seront débitées** ou de **étiqueté comme bac à sable**, puis de la devise. Si le jeton est erroné ou n’a pas les autorisations, la raison s’affiche en rouge.

[[show: #payment-providers @ Helcim > Test connection | Afficher Tester la connexion]]

> **Important :** Helcim n’a pas d’adresse de bac à sable distincte. Un jeton de test et un jeton réel atteignent le même service Helcim, et **c’est le jeton qui détermine si de l’argent réel circule**. Le paramètre Mode ne fait qu’étiqueter ce que voient le personnel et les familles; réglez-le donc selon le type de jeton que vous avez collé.

> **Astuce :** Utilisez toujours **Tester la connexion** après avoir enregistré. Un jeton erroné, ou sans les autorisations requises, s’enregistre quand même et affiche quand même un point vert. Le test est le seul moyen de confirmer que tout fonctionne avant qu’une famille essaie de payer.

### Comment un paiement Helcim est consigné

Un paiement n’est consigné **qu’une fois que KiddieTrac a vérifié le résultat de Helcim**. Le montant consigné est celui que Helcim déclare réglé, et non celui qui avait été demandé. Le solde et le statut de la facture suivent ensuite ce qui a réellement été payé. Une facture déjà annulée ou remboursée est laissée telle quelle pour qu’une personne l’examine, même si un paiement arrive à son égard.

### Rembourser un paiement Helcim

Remboursez-le depuis **Remboursements** ou depuis le grand livre du compte de la famille, comme pour n’importe quel autre paiement. L’argent retourne automatiquement sur la carte :

- **Même jour, montant complet** : KiddieTrac essaie d’abord d’**annuler** le débit, afin qu’il disparaisse du relevé du titulaire de carte au lieu d’y figurer comme un débit et un crédit.
- **Sinon**, ou si l’annulation est refusée : le paiement est **remboursé** par Helcim. Seul un remboursement peut être partiel.

Si Helcim n’est plus configuré pour votre agence, le remboursement est refusé avec un message qui en explique la raison, et aucun remboursement n’est consigné. Voir [Rembourser un paiement](refunds).

## Zum Rails (paiements bancaires et cartes)

Zum Rails gère les **paiements bancaires (EFT) et les paiements par carte** des familles, ainsi que les remboursements. Vos identifiants proviennent de votre portail Zum, sous **Settings → Webhook & API**. L’onglet demande l’**URL de base de l’API**, l’**ID du portefeuille Zum**, le **Nom d’utilisateur de l’API**, le **Mot de passe de l’API**, le **Secret du webhook** et le **Chemin du point de terminaison de remboursement**.

Sous les cases, l’onglet affiche une **URL de rappel pour leur portail**. Collez-la dans Zum avec le même secret de webhook que vous avez saisi ici. Les paiements ne sont confirmés que lorsque Zum appelle cette adresse; une URL erronée échoue donc silencieusement au règlement du paiement.

## Stripe

Stripe accepte les **paiements par carte et les modes de paiement enregistrés**. Vos clés proviennent de votre tableau de bord Stripe, sous **Developers → API keys** : la **Clé publiable**, la **Clé secrète** et le **Secret de signature du webhook**.

## Qui peut l’utiliser

Les **administrateurs de l’agence** et les **administrateurs de la plateforme** seulement. Les directeurs de centre ne peuvent pas ouvrir cet écran, parce que ces clés font circuler l’argent de l’agence et relèvent de la personne qui détient le compte bancaire.

Voir aussi : [Frais de service sur les paiements](payment-surcharges) · [Rembourser un paiement](refunds) · [Configuration et paramètres de facturation](billing-setup)
