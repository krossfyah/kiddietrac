---
title: Paramètres des opérateurs — Twilio et Telnyx
---
# Paramètres des opérateurs — Twilio et Telnyx

**Paramètres → Paramètres des opérateurs** contient les comptes par lesquels votre agence envoie des textos et fait des appels. Deux opérateurs sont pris en charge (Twilio et Telnyx) et deux canaux passent par eux : les textos et les appels vocaux d'annonce. Ouvrez cet écran une fois pour configurer les textos, puis de nouveau quand un texto ou un appel n'est pas arrivé.

[[open: #sms-settings | Ouvrir les paramètres des opérateurs]]

Chaque agence configure ici son propre opérateur. Les numéros, les clés, le solde et les choix d'une agence ne sont jamais visibles par une autre, et seul un **administrateur de cette agence** peut les enregistrer. L'écran a deux onglets, **Messages texte** et **Appels vocaux**, et se souvient de celui où vous étiez.

## Configurer les textos, du début à la fin

1. Ouvrez **Paramètres des opérateurs** sur l'onglet **Messages texte**.
2. Sous **Configurer le fournisseur**, choisissez la page **Twilio** ou **Telnyx**.
3. Pour Twilio, entrez le **SID du compte** (commence par AC) et le **Jeton d’authentification**, ou mieux un **API key SID** et un **API key secret**. Pour Telnyx, entrez la **Clé d’API** (commence par KEY).
4. Entrez **Envoyer depuis** : le numéro au format international complet (par exemple +1 et dix chiffres).
5. Pour Telnyx, collez la **Clé publique du webhook** (Mission Control → Keys & Credentials → **Public Key**), pour que les réponses et les rapports de livraison soient reconnus.
6. Copiez le **Replies and STOP webhook** (Twilio) ou l'**Inbound message webhook** (Telnyx) dans le portail de l'opérateur.
7. Appuyez sur **Enregistrer**. L'enregistrement conserve les deux opérateurs en même temps, peu importe la page ouverte.
8. Appuyez sur **Test Twilio** ou **Test Telnyx**. Le test vérifie les identifiants auprès de l'opérateur, ne coûte rien et n'envoie rien. Le résultat reste dans le journal des tests plus bas.

> ℹ️ Les identifiants sont en **écriture seule**. Une fois enregistrés, l'écran indique seulement si un secret est conservé. Pour en changer un, tapez la nouvelle valeur; pour le garder, laissez la case vide. Prenez les noms des champs à la lettre : une clé d'API Telnyx n'est pas un SID de compte Twilio, et un identifiant qui commence par `OQ…` n'en est pas un non plus.

## Choisir l'opérateur qui envoie

1. Repérez la carte **Fournisseur qui envoie**, au-dessus des pages des opérateurs. **Both can be set up at once. Only the one chosen here sends.**
2. Choisissez **Twilio** ou **Telnyx**. La page de l'opérateur qui envoie est marquée **sending**.
3. Cochez **If that carrier refuses, try the other one.** si les deux sont configurés. L'autre opérateur sert seulement quand le premier refuse un message, et un message déjà accepté n'est jamais envoyé deux fois.
4. Activez **Send text messages for this agency**.
5. Appuyez sur **Enregistrer**.

[[show: #sms-settings @ Which carrier sends | Me montrer le choix de l'opérateur]]

## Choisir les types de textos envoyés par l'agence

1. Dans l'onglet **Messages texte**, repérez **Types de textos envoyés par cette agence**.
2. Cochez les types voulus :

| Type | Ce que c'est |
|---|---|
| Arrivée et départ | Quand un enfant arrive ou part, et avec qui |
| Rappels d'arrivée | Un enfant n'a pas été inscrit à l'arrivée, ou jamais au départ |
| Annonces | Les annonces, quand elles sont aussi envoyées par texto |
| Diffusions par texto | Les messages envoyés à un groupe, une salle ou tout le monde à partir de SMS broadcast |
| Réponses du personnel | La réponse d'un membre du personnel à un texto envoyé au numéro de l'agence |

3. Appuyez sur **Enregistrer**.

Décochez un type et l'agence n'envoie plus aucun texto de ce type, peu importe ce que chacun a choisi. Seules les personnes qui ont accepté les textos en reçoivent, et chacune peut couper des types dans son profil — voir [Textos et appels](texts-and-phone-calls). Les confirmations de consentement et les réponses STOP partent toujours.

![Types de textos envoyés par cette agence, dans l'onglet Messages texte](https://api.kiddietrac.com/help-img/carrier-kinds-of-texts.png)

## Envoyer un vrai texto ou appel de test

1. Sous **Envoyer un vrai test**, tapez un numéro que vous contrôlez.
2. Cochez **I control this number, or its owner is expecting this.**
3. Appuyez sur **💬 Send text** ou **📞 Place call**.

> ⚠️ Ce test envoie vraiment. Il est facturé par votre opérateur, dit qu'il s'agit d'un test, nomme votre agence et comprend STOP. Réservé aux administrateurs d'agence, cinq par heure par agence.

## Configurer les appels vocaux d'annonce

1. Ouvrez l'onglet **Appels vocaux**. La voix utilise la clé d'API Telnyx de l'onglet Messages texte.
2. Entrez le **Call Control connection id** (Mission Control → Voice → **Call Control** → votre application) et **Appeler depuis**, le numéro que verront les parents. **Caller name** est facultatif.
3. Choisissez une voix dans **Choisir une voix…** et réglez la **Langue** (par exemple `en-US`, `en-GB`, `fr-CA`). Les voix neuronales sont beaucoup plus naturelles et facturées un peu plus cher.
4. Sous **Motifs pouvant déclencher des appels**, cochez les motifs pour lesquels un appel d'annonce peut partir : *Fermeture, Évacuation, Confinement, Maladie au centre, Autre urgence* et *Pas une urgence*. Les cinq urgences sont activées au départ; *Pas une urgence* ne l'est pas.
5. Activez **Place announcement calls for this agency**. C'est désactivé tant que vous ne l'activez pas.
6. Appuyez sur **Enregistrer**.

[[show: #sms-settings @ Voice calls > Reasons that may place calls | Me montrer les motifs d'appel]]

![Motifs pouvant déclencher des appels — les cinq urgences sont activées, « Pas une urgence » ne l'est pas](https://api.kiddietrac.com/help-img/carrier-voice-reasons.png)

L'écran SMS broadcast offre seulement les motifs cochés ici.

## Tester un appel

1. Dans l'onglet **Appels vocaux**, tapez le **Message de test** (jusqu'à 600 caractères).
2. Laissez **Numéro à appeler** vide pour faire sonner le numéro de votre profil, ou tapez un autre numéro et cochez **I control this number**.
3. Appuyez sur **Appeler mon numéro**. Le test joue la voix choisie, enregistrée ou non, pour vous permettre d'essayer des voix.
4. Suivez le **Journal des tests** : chaque test y avance (composé, répondu, terminé, sans réponse) et, quand un test est refusé, la raison est indiquée.

Le test fonctionne **même quand les appels vocaux sont désactivés**, et compte dans la limite de **cinq par heure** par agence.

[[show: #sms-settings @ Voice calls > Call my own number | Me montrer le bouton d'appel de test]]

![L'appel de test : votre message, un numéro facultatif et le journal des tests en dessous](https://api.kiddietrac.com/help-img/carrier-voice-test.png)

## Surveiller le solde (Telnyx)

Quand Telnyx est configuré, la carte **Telnyx balance & usage** montre votre **Balance**, les **Texts (30 days)** et **Calls (30 days)**, leur coût et à peu près combien de temps le solde durera. Elle devient rouge quand le solde baisse. Appuyez sur **Actualiser** pour les derniers chiffres. Rechargez le compte, ou activez la recharge automatique dans Telnyx, pour qu'une série d'appels d'urgence ne s'arrête pas en cours de route.

[[show: #sms-settings @ Balance | Me montrer la carte du solde]]

## Deux interrupteurs, pas un seul

Rien ne part tant que les deux ne sont pas activés, et ils sont à deux endroits différents :

- **Un opérateur configuré et choisi ici.**
- **Les notifications activées pour l'agence**, dans **Paramètres → Email settings** (**📬 Mail & notifications**). Si elles sont coupées, rien ne part ni ne sonne, même avec un opérateur bien configuré. Cet écran l'indique en rouge.

## Qui peut faire quoi

| Rôle | Accès |
|---|---|
| Administrateur d'agence | Tout cet écran, pour son agence, y compris les vrais tests |
| Administrateur de plateforme | La même chose; l'écran s'intitule **Phone & carriers** et ajoute un onglet **☎️ Toll-free line** pour la ligne de KiddieTrac |
| Directeur de centre, personnel | Aucun accès. Les directeurs envoient textos et appels à partir de **SMS broadcast** |

## Questions fréquentes et problèmes

??? Une diffusion a indiqué « envoyé », mais personne ne l'a reçue. | Vérifiez d'abord les deux interrupteurs : un opérateur choisi ici, et les notifications activées dans Paramètres → Email settings. Vérifiez ensuite que le type de texto est coché et que les personnes ont accepté de recevoir des textos.
??? Un avertissement rouge dit que la clé publique du webhook est vide. | Telnyx signe chaque message qu'il renvoie (réponses, STOP, rapports de livraison). Tant que la clé n'est pas enregistrée, les réponses et les STOP des familles ne sont pas consignés. Collez la **Public Key** et appuyez sur **Enregistrer**.
??? Mon appel de test indique « Not placed ». | Lisez la ligne du journal des tests — elle donne la raison. Le plus souvent, le numéro appartient à un compte suspendu ou archivé, ou la personne a choisi « Ne m'appelez jamais ».
??? « Tick "I control this number" first. » | Cochez la case sous le numéro avant d'appuyer sur le bouton.
??? Une autre agence peut-elle voir notre solde ou nos clés? | Non. Chaque agence a ses propres paramètres d'opérateur, et seuls ses administrateurs peuvent les ouvrir ou les modifier.
??? Pourquoi un motif manque-t-il dans l'écran SMS broadcast? | Il est décoché sous Motifs pouvant déclencher des appels. Cochez-le ici et enregistrez.
??? Quelque chose semble anormal à l'écran. | Lisez d'abord la ligne **Screen build** sous le titre : une vieille copie de l'application en cache ressemble beaucoup à un bogue. Rechargez la page pour obtenir la dernière version.

## Écrans connexes

[[open: #sms | Diffusion SMS]] [[open: #email-settings | Paramètres de courriel]]

Voir aussi : [Diffusion SMS](sms-broadcast), [Appels d'annonce](voice-announcements), [Textos et appels](texts-and-phone-calls), [Paramètres de courriel](email-settings).
