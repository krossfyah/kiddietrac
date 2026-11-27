---
title: Campagnes marketing
---
# Campagnes marketing

**Marketing › Campaigns** est l'endroit où vous rédigez infolettres, invitations aux portes ouvertes et promotions, et
les envoyez aux familles (ou au personnel) de votre choix — par courriel, comme publication Nouvelles dans le portail,
ou les deux. Une fois envoyée, vous voyez qui l'a reçue, qui l'a ouverte et quels courriels sont revenus, et vous
pouvez la renvoyer aux personnes dont l'adresse a été corrigée.

[[open: #marketing-campaigns | Ouvrir les campagnes]]

![Cliquez sur + New campaign à l'écran Campagnes](https://api.kiddietrac.com/help-img/campaigns.png)

## Rédiger une campagne

1. Appuyez sur **+ New campaign**.
2. Entrez un **Campaign title** (seule votre équipe le voit) et le **Subject (email)** que les familles verront dans leur boîte de réception.
3. Choisissez l'**Audience** : **All families**, **Currently enrolled**, **Waitlist**, **Prospects (no active child)** ou **Staff only**.
4. Choisissez le **Channel** : dans le portail (publication Nouvelles), par courriel, ou les deux.
5. Si elle part par courriel, vérifiez **Send from** — l'expéditeur par défaut de l'agence, ou une boîte Gmail / Google Workspace connectée si votre agence en a une.
6. Ajoutez une **Hero image (optional)** — l'image en haut du message.
7. Rédigez le texte dans l'éditeur (titres, listes, couleurs, liens, images). Pour saluer chaque famille par son prénom, tapez **{first_name}** ou appuyez sur **👤 First name** : « Bonjour {first_name}, » arrive comme « Bonjour Sarah, ». S'il n'y a pas un lecteur unique ou pas de prénom au dossier, on lit « there ».
8. Appuyez sur **Save draft**.

> 💡 Le logo, le nom, le courriel de soutien et l'adresse de votre agence s'affichent en haut automatiquement si votre forfait comprend l'image de marque personnalisée. Le pied de page montre vos liens **Privacy policy** et **Terms & conditions** tirés des paramètres d'image de marque de l'agence.

## Prévisualiser et envoyer

1. Appuyez sur **👁 Preview** pour voir la campagne exactement comme les familles la recevront, avec un prénom d'exemple. Prévisualisez toujours avant d'envoyer.
2. Pour envoyer plus tard, remplissez **Schedule** avec une date et une heure. Laissez vide pour envoyer vous-même.
3. Appuyez sur **Send now** et confirmez. Les publications Nouvelles paraissent tout de suite; les courriels partent quelques-uns à la fois.

Une campagne envoyée ne se modifie plus. Pour la consulter sans rien changer, ouvrez le **⋮** de sa ligne et choisissez **View** — View n'envoie jamais rien.

### Qui reçoit une campagne

Chaque parent ou tuteur d'une famille encore inscrite chez vous, **y compris les parents qui n'ont pas encore accepté
leur invitation à l'application**. Elle ne va pas aux familles parties ou suspendues, aux comptes fermés ni aux
personnes sans courriel au dossier, et chaque adresse reçoit une seule copie.

## Voir qui l'a reçue : Recipients & stats

1. Ouvrez le **⋮** de la campagne envoyée et choisissez **Recipients & stats**. Une page plein écran s'ouvre : « Who this campaign went to, and what happened. »
2. Lisez les tuiles, puis le tableau : **Family**, **Email**, **Status**, **Details**, **Actions**.
3. Filtrez par statut avec les pastilles : **Sent** (notre service de courriel l'a acceptée et rien n'est revenu), **Opened**, **Bounced** (le serveur du destinataire l'a renvoyée, avec la raison en mots simples), **Failed** (elle n'a pas pu être remise), **Held back** (une autre règle de courriel l'a bloquée, par exemple la personne a désactivé ces courriels ou le compte est fermé), **Waiting** (pas encore envoyée) et **Not known**.
4. Cherchez une personne, ou exportez la liste en CSV, Excel ou PDF à partir de la barre sous le tableau.
5. Appuyez sur **View the email they got** sur une ligne pour voir la copie exacte reçue par cette personne, ou sur **Copy email address**.
6. Appuyez sur **All campaigns** pour revenir.

> ℹ️ Ce que la page ne peut pas dire : les services de courriel n'envoient pas d'accusé de livraison, donc « Sent » est ce qui se rapproche le plus de « livré ». Une ouverture compte quand les images du courriel se chargent : une personne qui bloque les images paraît non ouverte. Les clics sur les liens ne sont pas suivis. Si la campagne a aussi été publiée dans Nouvelles, vous voyez combien ont ouvert la publication et lu l'avis dans l'application.

## Renvoyer aux personnes dont le courriel est revenu

1. Corrigez d'abord le courriel de la personne dans son dossier (**Open their record** dans la liste vous y mène).
2. Appuyez sur **↻ Resend to bounced** — au-dessus du filtre de statut, ou dans le **⋮** de la campagne.
3. Chaque personne est listée avec **Bounced at**, **Current email** et un **State** : **Address corrected — ready to resend**, **Same address — fix it first**, **No email on file** ou **Account deactivated**.
4. Seules les personnes prêtes peuvent être cochées; elles le sont au départ (**Tick all ready** les recoche).
5. Appuyez sur **↻ Resend** et confirmez le nombre. La campagne part à l'adresse corrigée, une fois chacune. C'est irréversible.
6. Un message **Campaign resent** apparaît et la personne passe à envoyé, avec l'adresse refusée dans **Details**. S'il y en avait beaucoup, appuyez sur **Resend again** — l'envoi se fait par lots de 80 au plus.

Elle n'est jamais envoyée deux fois à une adresse qui l'a déjà reçue, et les règles habituelles de courriel s'appliquent. Le journal d'audit nomme chaque adresse visée.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d'agence | Rédiger, envoyer, voir les statistiques et renvoyer pour tous les centres. |
| Directeur de centre | La même chose pour son ou ses centres; il ne voit que les campagnes de ses centres. |
| Administrateur de plateforme | L'agence choisie dans le sélecteur d'agence. |

Une personne qui peut voir une campagne sans pouvoir l'envoyer lit : « You can see this list, but only someone who can send this campaign can resend it. »

## Questions fréquentes et problèmes

??? Recipients & stats n'est pas dans le menu ⋮. | Il apparaît dès que quelque chose a été envoyé. Les brouillons et les campagnes planifiées n'ont pas encore de destinataires.
??? Resend to bounced dit que personne n'est prêt. | « Nobody is ready yet: correct their email on their record first. » Corrigez l'adresse, puis rouvrez.
??? Certaines personnes affichent « Not known ». | Les campagnes envoyées avant le 27 novembre 2026 ont été reconstituées à partir du journal des courriels; quelques personnes peuvent ne pas avoir de dossier.
??? Un parent dit ne jamais l'avoir reçue. | Regardez sa ligne : **Held back** donne la règle qui l'a bloquée; **Bounced** donne la raison. Si c'est **Sent**, demandez-lui de vérifier ses pourriels.
??? Puis-je supprimer une campagne? | Oui, avec **⋮ > Delete** (« Delete this campaign? This cannot be undone. »). Les courriels déjà envoyés ne sont pas rappelés.

## Écrans connexes

[[open: #tours | Visites]] [[open: #audit-logs | Journal d'audit]]

Voir aussi [[drip-campaigns]] et [[email-templates]].
