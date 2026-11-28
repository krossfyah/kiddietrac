---
title: État du serveur et garde anti-fuite
---
# État du serveur et garde anti-fuite

**Platform › Server health** montre sur une seule page si le portail va bien : la machine (processeur, mémoire, disque), la base de données, les tâches en arrière-plan, le planificateur, les sauvegardes, ce que les gens ont vécu dans les dernières 24 heures (erreurs, requêtes lentes ou échouées), la vitesse du portail sur leurs appareils, les mots de recherche, les vérifications **Leak guard** et les certificats Web. Ouvrez-la quand on vous dit que le portail est lent, après un déploiement, ou comme vérification quotidienne.

[[open: #server-health | Ouvrir Server health]]

La page s'actualise d'elle-même toutes les 30 secondes tant qu'elle est ouverte. Elle a deux onglets : **🖥️ This server** (les chiffres du portail lui-même) et **☁️ Cloudways · new server** (les graphiques du compte d'hébergement).

## Faire la vérification quotidienne

1. Ouvrez **Platform › 🖥️ Server health** et appuyez sur **🖥️ This server**.
2. Lisez les lignes d'alerte du haut. **✅ Everything looks healthy.** veut dire qu'aucune limite n'est dépassée; sinon, chaque problème s'affiche en mots simples sur une ligne 🟠 (avertissement) ou 🔴 (urgent).
3. Lisez les cartes : **Processor**, **Memory**, **Disk** (avec les téléversements, sauvegardes et journaux), **Database** (temps de réponse et connexions), **Background jobs** (en attente, plus longue attente, échecs aujourd'hui), **Scheduled jobs** (**Running** ou **Not running**, dernier signe de vie), **Newest backup** (âge, chiffrée ou non, copies conservées) et **Last 24 hours** (erreurs, requêtes lentes, requêtes jamais arrivées, limitées). Vert, c'est correct; orangé, à regarder; rouge, à corriger.
4. Pour remonter dans le temps, choisissez une période (**Last 6 hours** à **Last 14 days**) et regardez les graphiques **History**, remplis toutes les 5 minutes.
5. Appuyez sur **↻ Refresh** pour tout relire immédiatement.

## Vérifier la garde anti-fuite

La carte **Leak guard** montre les vérifications automatiques qui garantissent qu'aucune agence ne peut voir les données d'une autre.

1. Descendez jusqu'à **Leak guard**.
2. Lisez le **Result** de chaque ligne :
   - **Route probe (latest)** : le test, chaque heure, d'une partie des requêtes du portail pour chaque rôle.
   - **Route probe (full, nightly)** : le même test sur toutes les routes, chaque nuit.
   - **Data check** : des vérifications sur les données enregistrées elles-mêmes.
   - **Live sentinel** : un échantillon de vraies réponses sur les **Last 7 days**.
3. **Clean** (vert) : rien n'a été trouvé. **… finding(s)** ou **… hit(s)** (rouge) : fuite possible, les détails sont listés en rouge sous le tableau. **Blind: … role(s)** (orangé) : le test n'a pas pu se connecter avec certains rôles, il a donc moins vérifié qu'il aurait dû. **Not run yet** : la vérification n'a pas encore roulé depuis sa mise en place.

> ⚠️ Traitez tout résultat rouge du Leak guard comme urgent : lisez les lignes sous le tableau et corrigez la cause le jour même.

## Trouver pourquoi le portail semble lent

1. Lisez **Connections (devices, last …)** : **Sessions measured**, **On a slow connection** (pourcentage en 2G/3G ou avec plus de 2 s d'attente), **Typical wait per request** et **Requests that failed**. Un tableau liste les agences aux sessions les plus lentes.
2. Lisez **Where problems happened (24 h)** : chaque requête **Server error**, **Slow** ou **Never arrived**, l'écran ou la requête touché, et le nombre de **Times**.
3. Vérifiez la section **Database** pour les requêtes lentes et les plus grosses tables.
4. Appuyez sur **☁️ Cloudways · new server**, choisissez une période et regardez les graphiques d'hébergement (processeur, mémoire, disque, bande passante, espace disque par application). Appuyez sur **↻ Cloudways** pour les relire.

## Autres sections

- **Search words** : combien de mots la recherche du portail connaît par rôle, quand un écran a été signalé pour la dernière fois, ce qui a été appris et refusé cette semaine. **Last screen reported** en rouge veut dire que la recherche a cessé d'apprendre.
- **Certificates** : chaque adresse Web, la date **Expires** de son certificat, les **Days left** et l'**Issuer**. Les certificats se renouvellent seuls; peu de **Days left** veut dire que le renouvellement a échoué.

## Relier le compte d'hébergement

1. Appuyez sur **☁️ Cloudways · new server**.
2. Suivez les instructions de l'onglet pour créer un jeton d'accès dans le compte d'hébergement.
3. Collez-le dans la case et appuyez sur **Connect Cloudways**.
4. Pour le retirer, appuyez sur **Disconnect** et confirmez. Le jeton est supprimé de KiddieTrac; vous pouvez le relier de nouveau en tout temps.

## Qui peut faire quoi

| Rôle | Accès |
|---|---|
| Administrateur de la plateforme | Toute la page. |
| Tous les autres | La page indique qu'elle est réservée aux **platform administrators**. |

## Questions fréquentes et problèmes

??? « Scheduled jobs: Not running ». | Le planificateur qui fait rouler toutes les tâches à heure fixe (sauvegardes, rappels, résumés) s'est arrêté. C'est urgent : rien de planifié ne se fait tant qu'il n'est pas redémarré.
??? « Newest backup » est rouge ou indique NOT encrypted. | Aucune bonne sauvegarde dans les 30 dernières heures, ou la copie n'est pas chiffrée. Ouvrez Backups pour voir la dernière erreur.
??? « The server could not be read just now. » | Les chiffres n'ont pas pu être chargés. Appuyez sur ↻ Refresh dans une minute.
??? « Collecting — the history fills in every 5 minutes. » | L'historique est nouveau ou a été vidé. Attendez quelques minutes.
??? Le Leak guard indique « Blind ». | La sonde n'a pas pu agir avec un ou plusieurs rôles; sa vérification est incomplète. Ne la considérez pas encore comme propre.
??? Cette page avise-t-elle quelqu'un? | La page elle-même n'envoie rien; elle ne fait qu'afficher. Une sauvegarde échouée déclenche aussi une alerte dans Security alerts.

## Écrans liés

[[open: #backups | Sauvegardes]] [[open: #security-alerts | Alertes de sécurité]] [[open: #ai-usage | Utilisation de l'IA]] [[open: #maintenance | Maintenance]] [[open: #compliance-evidence | Preuves de conformité]]
