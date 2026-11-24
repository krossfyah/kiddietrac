---
title: Paramètres des opérateurs — Twilio et Telnyx
category: Settings
order: 41
roles: agency_admin, platform_admin
---
# Paramètres des opérateurs — Twilio et Telnyx

**Paramètres → Paramètres des opérateurs.** Les comptes par lesquels votre agence envoie des textos et passe des appels téléphoniques. Deux opérateurs sont pris en charge et deux canaux passent par eux — les SMS et les appels d’annonce vocaux — c’est pourquoi l’écran ne porte plus le nom de l’un ou de l’autre.

[[open: #sms-settings | Ouvrir les paramètres des opérateurs]]

Chaque agence configure son propre opérateur ici. Les numéros, les clés, le solde et les choix d’une agence ne sont jamais visibles pour une autre, et seul un **administrateur de cette agence** peut les enregistrer.

## La configuration, du début à la fin

- [ ] Saisissez les identifiants de l’opérateur et le numéro d’envoi, puis **Enregistrer**.
- [ ] Appuyez sur **Tester la connexion** — cela vérifie les identifiants et n’envoie rien.
- [ ] Choisissez l’opérateur sous **Opérateur d’envoi**.
- [ ] Cochez les **types de textos envoyés par cette agence** (ci-dessous).
- [ ] Pour Telnyx, collez la **clé publique du webhook** afin que les réponses et les rapports de livraison soient considérés comme fiables.
- [ ] Envoyez-vous un vrai texto de test, puis passez un appel de test dans l’onglet **Appels vocaux**.
- [ ] Vérifiez **Paramètres → Paramètres de messagerie** : les notifications doivent être activées pour l’agence.

## Deux commutateurs, pas un seul

Rien ne part tant que les deux ne sont pas activés, et ils se trouvent à des endroits différents. Un administrateur qui l’ignore passe beaucoup de temps à se demander pourquoi une diffusion indiquait « envoyé » sans qu’aucun téléphone ne bouge.

1. **Un opérateur est configuré et sélectionné ici.**
2. **Les notifications sont activées pour l’agence**, sous **Paramètres → Paramètres de messagerie**. C’est un commutateur général multicanal : s’il est désactivé, rien ne part ni ne sonne, même avec un opérateur entièrement configuré. L’écran l’indique en rouge lorsqu’il est désactivé.

## Choisir l’opérateur d’envoi

La carte **Opérateur d’envoi** se trouve au-dessus des onglets des opérateurs, car c’est une décision qui concerne la paire et n’appartient à aucun des deux. Choisissez Twilio ou Telnyx; celui qui envoie actuellement est marqué sur son propre onglet.

[[show: #sms-settings @ Which carrier sends | Montrez-moi le choix de l’opérateur]]

Les deux pages sont enregistrées ensemble. Remplir Telnyx ne peut pas écarter discrètement un numéro saisi sur la page Twilio — mais vous pouvez configurer Telnyx avec soin et oublier de passer à celui-ci, alors vérifiez le marquage de l’onglet avant de présumer.

## Onglets Messages texte et Appels vocaux

L’écran comporte deux onglets, **Messages texte** et **Appels vocaux**. Il mémorise celui où vous étiez, de sorte qu’enregistrer ou tester dans l’onglet vocal ne vous renvoie plus aux textos.

[[show: #sms-settings @ Voice calls | Montrez-moi l’onglet Appels vocaux]]

## Types de textos envoyés par cette agence

Dans l’onglet **Messages texte**, **Types de textos envoyés par cette agence** liste chaque raison pour laquelle KiddieTrac peut envoyer un texto à quelqu’un :

| Type | Ce que c’est |
|---|---|
| Arrivées et départs | Lorsqu’un enfant arrive ou part, et par qui |
| Rappels d’arrivée | Un enfant n’a pas été enregistré à l’arrivée, ou son départ n’a jamais été enregistré |
| Annonces | Les annonces, lorsqu’elles sont aussi envoyées par texto |
| Diffusions par texto | Les messages envoyés à un groupe, à une salle ou à tout le monde depuis la diffusion SMS |
| Réponses du personnel | Une réponse du personnel à un texto envoyé au numéro de l’agence |

Décochez un type et plus rien de ce type n’est envoyé par texto par cette agence, quel que soit le choix de chacun. Chaque personne peut ensuite désactiver les types qu’elle ne veut pas — voir [Textos et appels téléphoniques](texts-and-phone-calls). Le réglage de l’agence l’emporte toujours sur le réglage personnel : une personne ne peut pas réactiver ce que l’agence a désactivé.

![Types de textos envoyés par cette agence, dans l’onglet Messages texte](https://api.kiddietrac.com/help-img/carrier-kinds-of-texts.png)

## Motifs pouvant déclencher des appels

Dans l’onglet **Appels vocaux**, **Motifs pouvant déclencher des appels** détermine pour quels motifs un appel d’annonce peut être envoyé : *Fermeture, Évacuation, Confinement, Maladie au centre, Autre urgence* et *Pas une urgence*. Les cinq urgences sont activées par défaut; *Pas une urgence* est désactivé, de sorte qu’un avis courant n’est jamais lu au téléphone à moins que vous ne décidiez qu’il le soit.

[[show: #sms-settings @ Voice calls > Reasons that may place calls | Montrez-moi les motifs d’appel]]

![Motifs pouvant déclencher des appels — les cinq urgences sont activées, « Pas une urgence » est désactivé](https://api.kiddietrac.com/help-img/carrier-voice-reasons.png)

L’écran de diffusion SMS n’offre que les motifs cochés ici.

## Choisir une voix

Choisissez une voix dans **Choisir une voix…** et définissez la **Langue** (par exemple `en-US`, `en-GB`, `fr-CA`). Les voix neuronales sonnent beaucoup plus naturelles et sont facturées à un tarif légèrement supérieur. L’appel de test ci-dessous joue la voix actuellement choisie, **enregistrée ou non**, ce qui vous permet d’écouter des voix avant de vous engager.

## Tester un appel

- **Message de test** — ce que l’appel de test énonce, jusqu’à 600 caractères.
- **Numéro à appeler** — laissez-le vide pour appeler le numéro de votre propre profil. Saisir un autre numéro vous demande de cocher **Je contrôle ce numéro**; l’appel annonce quelle agence appelle.
- **Appeler mon propre numéro** lance l’appel.

Le test fonctionne **même lorsque les appels vocaux sont désactivés**, ce qui vous permet de l’entendre avant d’activer le canal. Les tests comptent pour **cinq par heure** et par agence.

[[show: #sms-settings @ Voice calls > Call my own number | Montrez-moi le bouton d’appel de test]]

![L’appel de test : votre propre message, un numéro facultatif et le journal des tests en dessous](https://api.kiddietrac.com/help-img/carrier-voice-test.png)

Sous le bouton, le **Journal des tests** montre chaque test au fil de son déroulement — composé, répondu, terminé, sans réponse — et, lorsqu’un test a été refusé, **pourquoi** (par exemple, le numéro appartient à un compte supprimé, ou a indiqué *ne m’appelez pas*).

## Solde et utilisation (Telnyx)

Lorsque Telnyx est sélectionné, une carte d’utilisation affiche votre **solde**, les textos et appels des 30 derniers jours, leur coût, et la durée approximative pendant laquelle le solde durera à ce rythme. Elle devient rouge lorsque le solde est bas. Les chiffres proviennent du propre journal de KiddieTrac de ce qui a été envoyé, au tarif courant de Telnyx, de sorte que la carte est juste même lorsque les rapports de Telnyx accusent quelques heures de retard.

[[show: #sms-settings @ Balance | Montrez-moi la carte du solde]]

## L’avertissement du webhook

Telnyx signe chaque message qu’il nous renvoie — réponses, STOP, rapports de livraison, progression des appels. Si la **clé publique du webhook** est vide, ou si le dernier appel de Telnyx a été refusé parce que sa signature ne correspondait pas, un avertissement rouge s’affiche en haut de la page. Tant que ce n’est pas corrigé, les réponses et les STOP des familles ne sont pas enregistrés. Copiez la clé publique depuis le portail Telnyx (**Account settings → Keys & Credentials → Public key**) et enregistrez-la ici.

## Secrets

Les identifiants sont en **écriture seule**. Une fois enregistré, l’écran vous indique si un secret est stocké — il ne l’affiche jamais. Pour en changer un, saisissez la nouvelle valeur; pour le laisser tel quel, laissez la case vide.

Prenez les noms de champs au pied de la lettre. Une clé d’API Telnyx n’est pas un Account SID Twilio, et un identifiant commençant par `OQ…` n’est pas non plus un Account SID.

## Tester un texto

- **Tester la connexion** vérifie les identifiants auprès de l’opérateur. Cela ne coûte rien et n’envoie rien. Le résultat reste affiché à l’écran, de sorte qu’on peut répondre demain à « est-ce que ça a déjà fonctionné ».
- **Envoyer un vrai test** envoie un **vrai** texto, ou passe un **vrai** appel, à un numéro que vous saisissez. C’est facturé, cela nomme votre agence et comporte STOP, et c’est réservé aux administrateurs d’agence et limité à cinq par heure et par agence. Personne d’autre n’est contacté.

## Estampille de version

Une date de compilation est imprimée sous le titre de la page. Une copie périmée de l’application en cache est autrement impossible à distinguer d’un bogue; donc, quand quelque chose semble anormal, lisez d’abord cette ligne.

## Questions

??? Une diffusion indiquait « envoyé » mais personne ne l’a reçue. | Vérifiez d’abord les deux commutateurs : un opérateur sélectionné ici, et les notifications activées sous Paramètres → Paramètres de messagerie. Vérifiez ensuite que le type de texto est coché, et que les personnes avaient accepté de recevoir des textos.
??? Mon appel de test indique « non effectué ». | Lisez la ligne du Journal des tests sous le bouton — elle en donne la raison. Les causes les plus courantes sont un numéro appartenant à un compte supprimé ou archivé, ou une personne ayant choisi « Ne pas m’appeler du tout ».
??? Une autre agence peut-elle voir notre solde ou nos clés? | Non. Chaque agence a ses propres paramètres d’opérateur, et seuls les administrateurs de cette agence peuvent les ouvrir ou les modifier.
??? Pourquoi un motif manque-t-il à l’écran de diffusion SMS? | Il est décoché sous Motifs pouvant déclencher des appels. Cochez-le ici et enregistrez.

Voir aussi : [Diffusion SMS](sms-broadcast), [Appels d’annonce](voice-announcements),
[Textos et appels téléphoniques](texts-and-phone-calls), [Courriels et résumés](email-and-digests).
