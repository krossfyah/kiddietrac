---
title: Utilisation de l'IA, crédits et connexion Claude
---
# Utilisation de l'IA, crédits et connexion Claude

**Platform › AI usage** montre chaque appel d'IA que fait KiddieTrac et ce qu'il coûte, une estimation du crédit d'IA restant, la connexion Claude (clé et modèle) et les agences dont l'IA est désactivée. Ouvrez-le pour suivre les dépenses, trouver quelle fonctionnalité, agence ou personne utilise le plus l'IA, inscrire une recharge de crédit, ou changer la clé ou le modèle.

[[open: #ai-usage | Ouvrir AI usage]]

L'écran a cinq onglets : **📊 Overview**, **🧩 Breakdown**, **💳 Credits**, **🤖 Connection** et **🚫 Switched off**. Il se rouvre sur le dernier onglet utilisé.

## Voir l'état d'un coup d'œil

1. Ouvrez **Platform › 🤖 AI usage** et appuyez sur **📊 Overview**.
2. Lisez la pastille d'état : **✅ Connected**, **⛔ No key set**, **⛔ Out of credit**, **⚠️** avec la dernière erreur, ou **Not used yet**. À côté : le modèle, environ combien de crédit il reste (et pour combien de jours) et combien d'agences sont désactivées.
3. Lisez les quatre tuiles **Today**, **Last 7 days**, **Last 30 days** et **This month** : coût estimé, requêtes, jetons en entrée et en sortie.
4. Regardez **📈 Estimated cost per day** pour les 30 derniers jours. Survolez une barre pour voir le coût et les requêtes de la journée.

## Trouver qui et quoi utilise l'IA

1. Appuyez sur **🧩 Breakdown**.
2. Choisissez une **Period** (**Last 7 days**, **Last 30 days**, **This month**, **Last month**, **Last 90 days**) ou tapez les dates **From** et **To** pour **Custom dates**.
3. Lisez les tuiles : **Estimated cost**, **AI requests** (dont combien automatiques), **Tokens** et **People using AI**.
4. Choisissez une vue : **🧩 By feature**, **🏢 By agency**, **🧠 By model** ou **👤 By user**. Chacune est un tableau normal : cherchez-y, triez par n'importe quelle colonne et exportez en CSV, Excel ou PDF.
5. Dans **👤 By user**, appuyez sur le nom d'une personne pour voir ses **Recent calls** : ce qui a demandé, quand, le modèle, les jetons et le coût. Appuyez sur **Close** à la fin.

> ℹ️ Les appels sans personne connectée (résumés de nuit, robot de clavardage du site Web) s'affichent comme **Automatic** (« System »). Une tâche en arrière-plan compte pour la personne qui l'a lancée. Les questions et les réponses ne sont jamais conservées, seulement ce qui a demandé, quand, le modèle, la taille et le coût.

## Inscrire une recharge de crédit

Anthropic ne donne pas le solde prépayé à une clé d'API; le crédit restant est donc une **estimation** : les recharges que vous inscrivez, moins ce que KiddieTrac a dépensé depuis.

1. Appuyez sur **💳 Credits**.
2. Tapez le montant dans **Credits added ($)**.
3. Vérifiez la date dans **On** et ajoutez une **Note** au besoin.
4. Appuyez sur **+ Record top-up**. Le message **Top-up recorded** s'affiche et l'estimation se met à jour.
5. Pour retirer une entrée erronée, appuyez sur **Remove** à côté.

> 💡 Vérifiez de temps en temps le solde exact dans la console Anthropic (le lien est dans l'onglet), et inscrivez chaque recharge le jour de l'achat.

## Changer la clé ou le modèle

1. Appuyez sur **🤖 Connection**. Vous voyez les 4 derniers caractères de la clé, d'où elle vient, le modèle et la dernière réponse ou erreur.
2. Choisissez un **Model** et, seulement si vous remplacez la clé, collez-la dans **New API key** (laissez vide pour garder l'actuelle).
3. Appuyez sur **Save**. Le bouton affiche **Testing…** : la nouvelle clé ou le nouveau modèle est d'abord essayé auprès d'Anthropic, et gardé seulement s'il fonctionne (**Saved and tested**).
4. Appuyez sur **Test connection** en tout temps pour vérifier que la clé actuelle répond encore.

## Désactiver l'IA pour une agence

1. Appuyez sur **🚫 Switched off**.
2. Cochez chaque agence qui ne doit utiliser aucun crédit d'IA (agences de test et de démonstration, par exemple).
3. Appuyez sur **Save**.

Les agences cochées perdent les fonctionnalités de l'AI Bundle, et « Ask AI » de l'aide, le robot de clavardage et la lecture de cartes se comportent comme si l'IA n'était pas disponible.

## Qui peut faire quoi

| Rôle | Accès |
|---|---|
| Administrateur de la plateforme | Tout cet écran. |
| Administrateurs d'agence et tous les autres | Aucun accès. Les agences voient seulement leurs fonctionnalités d'IA, jamais les coûts ni les clés. |

## Questions fréquentes et problèmes

??? La pastille indique « Out of credit ». | Le dernier appel a échoué parce que le compte Anthropic n'a plus de crédit. Achetez du crédit dans la console Anthropic, puis inscrivez la recharge dans Credits.
??? « Nothing to change » quand j'appuie sur Save. | La case de la clé est vide et le modèle est le même. Choisissez un autre modèle ou collez une nouvelle clé.
??? « Not saved » ou un autre message rouge après Save. | Anthropic a refusé la nouvelle clé ou le nouveau modèle; l'ancien est donc conservé. Vérifiez que la clé a été copiée au complet.
??? « Enter the amount of credits added ». | Tapez un nombre plus grand que zéro dans Credits added ($) avant d'appuyer sur + Record top-up.
??? Un modèle indique « flat rate: not on the price list ». | Ce modèle n'a pas encore de prix inscrit; son coût utilise donc les tarifs d'IA de Usage & spend.
??? « No AI calls in this period. » | Rien n'a utilisé l'IA aux dates choisies. Élargissez la Period.
??? Quelqu'un est-il avisé? | Non. Les changements faits ici n'envoient ni courriel ni notification.

## Écrans liés

[[open: #usage-spend | Utilisation et dépenses]] [[open: #admin-features | Indicateurs de fonctionnalités]] [[open: #server-health | État du serveur]]
