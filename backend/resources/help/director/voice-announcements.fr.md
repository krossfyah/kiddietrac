---
title: Appels d’annonce
category: Communications
order: 41
roles: agency_admin, centre_director, platform_admin
---
# Appels d’annonce

Un téléphone qui sonne et annonce à voix haute un court message. Conçu pour le seul type d’avis dont on ne peut pas présumer que quelqu’un l’a lu — une fermeture, une évacuation, un confinement, une maladie au centre. Les parents qui n’ouvrent jamais l’appli répondent à leur téléphone.

**Diffusion SMS** est le même écran. Le commutateur **Envoyer par** en haut — **💬 Message texte** ou **📞 Appel vocal** — choisit le canal; tout ce qui se trouve en dessous — le sélecteur de destinataires, la zone de message, l’historique de ce qui a été envoyé — fonctionne de la même façon pour les deux.

[[open: #sms | Ouvrir la diffusion SMS]]

## Avant que cela fonctionne

- [ ] **Telnyx** configuré et sélectionné dans **Paramètres → Paramètres des opérateurs**.
- [ ] **Passer des appels d’annonce pour cette agence** coché dans l’onglet **Appels vocaux** de cet écran. C’est désactivé au départ : exploiter un site n’est pas la même chose que décider que l’agence se met à téléphoner aux gens.
- [ ] Les **motifs** pour lesquels vous voulez appeler sont cochés sous **Motifs pouvant déclencher des appels**.
- [ ] Notifications activées pour l’agence, sous **Paramètres → Paramètres de messagerie**.
- [ ] Un appel de test passé à votre propre téléphone, pour avoir entendu la voix.

La configuration des paramètres de l’opérateur relève d’un **administrateur de l’agence**, pas d’un directeur — donc si le commutateur de canal est là mais que rien ne sonne, demandez-lui plutôt que de chercher un réglage que vous ne voyez pas. Voir [Paramètres des opérateurs](carrier-settings).

## En envoyer un

1. Barre latérale → **Diffusion SMS**, puis réglez **Envoyer par** sur **📞 Appel vocal**.
2. Choisissez un **motif** — *Fermeture, Évacuation, Confinement barricadé, Maladie au centre, Autre urgence* ou *Pas une urgence*. Seuls les motifs que votre agence autorise sont proposés. Ce n’est pas une étiquette : il détermine qui peut être appelé (voir plus bas).
3. Choisissez le public : par rôle, par centre, par salle, par famille ou toute l’agence. Pour *par centre* et *par salle*, vous devez choisir lequel — un public qui ne peut pas être restreint est refusé plutôt que discrètement élargi à tout le monde.
4. Rédigez le message. Jusqu’à 800 caractères — assez long pour un avis de fermeture lu deux fois, assez court pour que personne ne raccroche. Il est lu par une voix synthétique : écrivez-le comme vous le diriez.
5. **Passer les appels**. Un appel n’est ni silencieux ni annulable, alors une confirmation est demandée d’abord.

[[show: #sms @ Voice call | Montrez-moi le commutateur Appel vocal]]

![Diffusion SMS avec Envoyer par réglé sur Appel vocal — choisissez le public et le motif, puis Passer les appels](https://api.kiddietrac.com/help-img/sms-broadcast-voice.png)

La ligne de résultat indique **appels en cours / ignorés / total**.

Vous pouvez aussi appeler des gens à partir d’une **Annonce** : cochez **📞 Appel vocal** en la publiant, et l’annonce est lue à voix haute au même public — voir [Annonces](announcements).

## Pour quels motifs votre agence appelle

Un administrateur de l’agence choisit sous **Paramètres → Paramètres des opérateurs → Appels vocaux → Motifs pouvant déclencher des appels**. Les cinq urgences sont activées par défaut; *Pas une urgence* est désactivé, de sorte qu’un avis courant ne peut pas être lu au téléphone tant que quelqu’un n’a pas décidé qu’il le devrait.

[[show: #sms-settings @ Voice calls > Reasons that may place calls | Montrez-moi où les motifs sont définis]]

## Qui est réellement appelé

Moins de gens qu’avec un texto, volontairement. Une personne est ignorée lorsque :

- elle n’a **aucun numéro de téléphone** dans son dossier;
- le motif est **désactivé pour l’agence**;
- elle a **désactivé les appels pour ce motif** dans ses propres choix — voir [Textos et appels téléphoniques](texts-and-phone-calls);
- elle, ou **quiconque partageant le même téléphone**, a choisi **Ne jamais m’appeler**. Cela couvre tous les motifs, urgences comprises; une personne qui l’a dit l’a généralement dit pour une raison;
- l’appel est **Pas une urgence** et personne sur ce téléphone n’a accepté d’être joint pour ce motif — le même consentement que pour un texto;
- la même annonce **est déjà allée à ce téléphone** il y a un instant. Les comptes qui partagent un numéro reçoivent un seul appel, pas un chacun.

Chaque exclusion est listée avec son motif, de sorte que « pourquoi n’a-t-elle pas été appelée » a une réponse.

## Ce qui se passe après l’envoi

Un appel est un échange avec l’opérateur étalé sur une minute, pas un événement unique. La liste sous le formulaire indique l’évolution de chaque appel :

- **en file** — composé.
- **répondu** — quelqu’un a décroché, et c’est seulement alors que l’annonce est prononcée. Rien n’est dit tant que le téléphone sonne.
- **terminé / sans réponse / occupé** — comment l’appel s’est terminé.

Actualisez la liste pour voir la situation se stabiliser. Un appel indiqué *sans réponse* n’a réellement pas été décroché.

## Faites d’abord un essai sur vous-même

Dans **Paramètres → Paramètres des opérateurs → Appels vocaux** :

1. Saisissez ce que le test doit dire dans **Message de test**, et choisissez une voix — le test utilise la voix actuellement choisie, même avant l’enregistrement.
2. Laissez **Numéro à appeler** vide pour appeler le numéro de votre propre profil, ou saisissez un autre numéro que vous contrôlez et cochez **Je contrôle ce numéro**.
3. Appuyez sur **Appeler mon propre numéro** et répondez.

Le **Journal des tests** sous le bouton suit l’appel et indique pourquoi lorsqu’un appel est refusé. Les tests fonctionnent même si les appels vocaux sont encore désactivés, et comptent dans la limite de cinq par heure.

[[show: #sms-settings @ Voice calls > Test log | Montrez-moi le journal des tests]]

## Bon à savoir

- Un directeur peut en envoyer un, parce qu’une fermeture ou une évacuation est une décision de site prise par la personne qui se trouve dans l’immeuble. Les paramètres de l’opérateur qui la sous-tendent restent du ressort des administrateurs de l’agence.
- Gardez la formulation simple et la phrase importante en premier. Les gens répondent en pleine phrase.
- Le solde et l’utilisation sur 30 jours affichés à l’écran des paramètres de l’opérateur couvrent les appels comme les textos.

## Questions

??? Pourquoi « Pas une urgence » n’est-il pas dans les motifs? | Votre agence ne l’a pas autorisé. Un administrateur de l’agence peut le cocher sous Paramètres des opérateurs → Appels vocaux → Motifs pouvant déclencher des appels.
??? Un parent dit qu’il ne veut jamais d’appel. | Il peut choisir « Ne jamais m’appeler » dans ses propres paramètres de Textos et appels téléphoniques. Cela s’applique à tout son téléphone, urgences comprises.
??? Deux parents partagent un téléphone. Sonnera-t-il deux fois? | Non. Un téléphone reçoit un appel par annonce.

Voir aussi : [Diffusion SMS](sms-broadcast), [Paramètres des opérateurs](carrier-settings),
[Textos et appels téléphoniques](texts-and-phone-calls), [Rappels de fermeture](closure-reminders),
[Annonces](announcements).
