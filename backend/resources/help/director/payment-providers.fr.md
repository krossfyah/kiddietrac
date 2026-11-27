---
title: Prestataires de paiement (Helcim, Zum Rails, Stripe, Interac, cartes-cadeaux)
category: Billing
order: 12
roles: agency_admin, platform_admin
---
# Prestataires de paiement (Helcim, Zum Rails, Stripe, Interac, cartes-cadeaux)

**Paramètres → Prestataires de paiement** est l’endroit où vous connectez les comptes de paiement par lesquels votre agence est payée : **Helcim**, **Zum Rails** et **Stripe** pour les cartes et les paiements bancaires, les instructions **Virement Interac** pour les familles, et les services **🎁 Cartes-cadeaux** utilisés pour les récompenses des éducateurs.

[[open: #payment-providers | Ouvrir Prestataires de paiement]]

Chaque agence gère son propre argent; chaque agence entre donc ses propres clés. Rien ne se rabat sur un compte KiddieTrac ni sur celui d’une autre agence. Tant que vos clés ne sont pas entrées et que le prestataire n’est pas activé, il ne peut ni encaisser ni rembourser quoi que ce soit pour vous.

## Lire l’écran

Il y a un onglet par prestataire. Le point à côté de chaque nom indique s’il est prêt : vert signifie qu’un identifiant fonctionnel est enregistré, gris signifie **Pas encore configuré**. Le même état est répété en bas à droite de chaque onglet : **● Ready** ou **○ Not configured yet**.

Chaque onglet de carte ou de banque a les deux mêmes réglages en haut :

- **Activé** : quand il est désactivé, aucun paiement ne peut être encaissé ni envoyé par ce prestataire, peu importe ce qui est enregistré plus bas.
- **Mode** : **Bac à sable** ou **Production**. Restez en bac à sable tant que vous n’avez pas testé un vrai paiement du début à la fin.

> ℹ️ Les secrets ne sont plus jamais affichés une fois enregistrés. Une clé enregistrée n’apparaît que par ses quatre derniers caractères (par exemple ••••a1b2). Laisser une zone de secret vide à l’enregistrement conserve la clé déjà en place; vous pouvez donc changer une URL sans ressaisir toutes les clés.

## Connecter Helcim (cartes de crédit et de débit)

Helcim accepte les cartes de crédit et de débit, et gère les remboursements et les annulations. Les familles saisissent leur carte dans la fenêtre de Helcim; un numéro de carte n’atteint donc jamais KiddieTrac.

[[show: #payment-providers @ Helcim | Me montrer l’onglet Helcim]]

1. Dans Helcim, allez à **All Tools → Integrations → API Access** et créez un jeton d’API avec les autorisations **Payment API** activées.
2. Dans KiddieTrac, ouvrez l’onglet **🍁 Helcim**.
3. Collez le jeton dans **Jeton d’API**.
4. Au besoin, remplissez **Terminal ID (optional)**.
5. Entrez la **Currency (CAD or USD)**.
6. Cochez **Activé** et réglez **Mode** selon le type de jeton collé.
7. Appuyez sur **Save Helcim**. Vous voyez **✓ Saved** et le point devient vert.
8. Appuyez sur **Tester la connexion**.

Le test demande à Helcim s’il accepte le jeton enregistré. Vous voyez **✓ Helcim accepted this token** suivi de « LIVE, real cards will be charged » ou « labelled as sandbox », et de la devise. Si le jeton est erroné ou n’a pas les autorisations, la raison s’affiche en rouge.

> ⚠️ Helcim n’a pas d’adresse de bac à sable distincte. Un jeton de test et un jeton réel joignent le même service Helcim, et **c’est le jeton qui décide si de l’argent réel circule**. Le réglage **Mode** ne fait qu’étiqueter ce que voient le personnel et les familles.

> 💡 Appuyez toujours sur **Tester la connexion** après l’enregistrement. Un jeton erroné s’enregistre quand même et affiche quand même un point vert; le test est la seule façon de savoir qu’il fonctionne avant qu’une famille essaie de payer.

Un paiement Helcim n’est consigné qu’une fois que KiddieTrac a vérifié le résultat de Helcim, pour le montant que Helcim déclare réglé. Les remboursements retournent automatiquement sur la carte : un remboursement complet le jour même est d’abord tenté comme **annulation** (il disparaît du relevé); sinon, il est remboursé par Helcim. Seul un remboursement peut être partiel.

## Connecter Stripe (cartes, cartes enregistrées, Apple Pay et Google Pay)

1. Dans votre tableau de bord Stripe, ouvrez **Developers → API keys**.
2. Dans KiddieTrac, ouvrez l’onglet **💳 Stripe**.
3. Collez la **Publishable key** et la **Secret key**. Laissez **Webhook signing secret** vide : KiddieTrac le remplit lors de la connexion.
4. Cochez **Activé**, choisissez le **Mode** et appuyez sur **Save Stripe**.
5. Sous **Payment updates from Stripe**, vérifiez que la ligne indique **● Connected**. Si elle indique **○ Not connected yet**, appuyez sur **Connecter** (ou **Reconnecter**).
6. Pour offrir Apple Pay et Google Pay sur les factures, appuyez sur **Activer Apple Pay et Google Pay**. Vous voyez **✓ Apple Pay and Google Pay are on for** suivi des adresses de votre portail.

« Connected » signifie qu’un paiement terminé par un parent après avoir fermé l’application est quand même consigné. Tant que ce n’est pas connecté, un paiement n’est consigné que si le parent attend à l’écran.

## Connecter Zum Rails (paiements bancaires et cartes)

1. Dans votre portail Zum, ouvrez **Settings → Webhook & API**.
2. Dans KiddieTrac, ouvrez l’onglet **🏦 Zum Rails**.
3. Remplissez **URL de base de l’API**, **ID du portefeuille Zum**, **Nom d’utilisateur de l’API**, **Mot de passe de l’API**, **Secret du webhook** et **Chemin du point de terminaison de remboursement**.
4. Cochez **Activé**, choisissez le **Mode** et appuyez sur **Enregistrer Zum Rails**.
5. Copiez l’**URL de rappel pour leur portail** affichée sous les zones et collez-la dans Zum, avec le même secret de webhook.

> ⚠️ Les paiements Zum ne sont confirmés que lorsque Zum appelle cette adresse; une mauvaise URL échoue donc sans bruit au moment du règlement.

## Offrir le virement Interac

Il n’y a rien à connecter : les familles envoient l’argent de leur propre banque à votre adresse courriel de virement, et vous consignez le paiement à son arrivée.

1. Ouvrez l’onglet **Virement Interac**.
2. Cochez **Accept Interac e-Transfer**. Les familles ont alors un bouton « Payer par virement Interac » dans Facturation, et les factures l’indiquent sous la façon de payer.
3. Entrez l’**e-Transfer email (where families send money)** et, si vous le souhaitez, le **Recipient name (as their bank will show it)**.
4. Sous **Autodeposit**, indiquez si le dépôt automatique est activé. Sinon, remplissez les champs **Security question** et **Répondre**.
5. Au besoin, rédigez **Your instructions for families (optional)** et cochez **Families can ask for an e-Transfer request**.
6. Appuyez sur **👁 Preview what families see** pour vérifier la fiche qu’une famille recevra.
7. Appuyez sur **Save Interac e-Transfer**.

## Connecter un service de cartes-cadeaux

L’onglet **🎁 Cartes-cadeaux** contient les comptes **Giftbit** et **Tango Card** de votre agence, utilisés pour acheter des cartes-cadeaux comme récompenses des éducateurs. Les cartes sont payées à même votre propre compte chez ce service.

1. Ouvrez l’onglet **🎁 Cartes-cadeaux**.
2. Pour Giftbit : choisissez l’**Environnement**, collez le **Jeton d’API** et cochez **Use Giftbit for educator rewards**. Pour Tango Card : remplissez **Nom de la plateforme**, **Clé d’API**, **Identifiant du client** et **Identifiant du compte**, puis cochez **Use Tango Card for educator rewards**.
3. Appuyez sur **Enregistrer**.
4. Appuyez sur **Tester la connexion** pour vérifier que le compte répond.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d’agence | Tout faire sur cet écran pour sa propre agence. |
| Administrateur de plateforme | La même chose, pour l’agence qu’il consulte. |
| Directeur de service de garde | Ne voit pas cet écran : ces clés déplacent l’argent de l’agence et relèvent de la personne responsable du compte bancaire. |

## FAQ et quand quelque chose ne va pas

??? Les onglets de cartes et de banque sont absents; seuls Interac et les cartes-cadeaux s’affichent. | Les paiements par carte font partie de la fonction paiements de votre forfait. Sans elle, une note grise explique pourquoi (par exemple « Payment accounts are not available. ») et seuls les onglets Virement Interac et cartes-cadeaux fonctionnent. Parlez à votre contact KiddieTrac pour ajouter les paiements.
??? Le point est vert, mais les familles ne peuvent pas payer. | Le vert signifie seulement qu’une valeur est enregistrée. Vérifiez que Activé est coché, appuyez sur Tester la connexion (Helcim), vérifiez que Payment updates from Stripe indique Connected (Stripe), ou vérifiez l’URL de rappel dans votre portail Zum.
??? Je vois « Could not save » en rouge. | Le message qui suit en donne la raison (par exemple une valeur manquante). Corrigez-la et enregistrez de nouveau. Rien n’a été modifié.
??? « Add the e-Transfer email families send money to. » | Vous avez coché Accept Interac e-Transfer sans adresse courriel. Ajoutez l’adresse, puis enregistrez.
??? « Add the security question and its answer, or choose Autodeposit. » | Vous avez choisi « No Autodeposit ». Les familles ont besoin d’une question et d’une réponse pour accepter le virement; remplissez donc les deux.
??? Apple Pay affiche un avertissement après avoir appuyé sur le bouton. | L’avertissement nomme l’adresse et ce que Stripe a répondu. Vérifiez que Stripe est dans le bon mode et appuyez de nouveau sur le bouton; Google Pay n’a besoin de rien de plus.
??? Les familles sont-elles avisées quand je change de prestataire? | Non. Rien n’est envoyé aux familles depuis cet écran. Elles voient simplement les options de paiement activées.

## Écrans connexes

[[open: #payment-run | Cycle de paiement]] [[open: #refunds | Remboursements]] [[open: #billing-settings | Paramètres de facturation]] [[open: #educator-rewards | Récompenses des éducateurs]]

Voir aussi : [Frais de paiement](payment-surcharges) · [Rembourser un paiement](refunds) · [Configuration et paramètres de facturation](billing-setup) · [Cycle de paiement](autopay)
