---
title: Vue d'ensemble de la plateforme
---
# Vue d'ensemble de la plateforme

**Platform › 🌐 Platform overview** est le tableau de bord de toutes les agences pour les administrateurs de la plateforme KiddieTrac : chaque centre, chaque famille et chaque dollar de toute la plateforme sur une seule page. Ouvrez-le en premier le matin pour voir si l'entreprise grandit, si les courriels et le système vont bien, et si quelque chose a planté. Il ne montre que des totaux et des événements récents ; les changements se font dans les autres écrans **Platform**.

[[open: #platform-overview | Ouvrir la vue d'ensemble]]

## Faire la vérification quotidienne

1. Ouvrez **Platform › 🌐 Platform overview**. La bannière indique **Cross-agency overview**.
2. Lisez les tuiles du haut : **Agencies** (actives), **Centres**, **Children** (inscrits), **Families**, **Staff** (utilisateurs actifs), **MRR** (revenu mensuel récurrent) et **Sessions** (actives dans les dernières 24 heures).
3. Vérifiez **Recent 30 days** : **New agencies**, **Cancellations** et **Net change**.
4. Faites défiler jusqu'à **Platform performance** et cherchez ce qui est en rouge : **Failed sends** au-dessus de 2 % des courriels, **API status** à **Down**, ou des agences **Dormant**.
5. En bas, vérifiez **💥 Crash reports**. **✓ No server errors recorded. All clear.** veut dire que rien n'a échoué ; sinon, chaque ligne montre **When**, **Who** et **Error**.

## Lire les graphiques de croissance

1. **MRR trend** montre les six derniers mois de revenu mensuel récurrent en dollars canadiens, avec le montant au-dessus de chaque barre.
2. **Agency growth** montre les inscriptions par rapport aux annulations sur six mois. Violet = **Net positive month**, ambre = **Had cancellations**, rouge = **Net negative**.
3. **Top agencies by enrolment** liste les cinq plus grandes agences selon le nombre d'enfants inscrits, avec une barre par rapport à la plus grande.
4. **Recent platform activity** montre les 10 derniers événements dans toutes les agences : ce qui a été fait, par qui et quand.

## Lire les indicateurs d'affaires

La bande **📊 Business metrics** est calculée à partir du MRR actuel et de sa tendance :

| Tuile | Signification |
|---|---|
| **ARR** | Revenu récurrent annualisé |
| **ARPA** | Revenu moyen par agence active, par mois |
| **ARPU** | Revenu par enfant inscrit, par mois |
| **MRR growth** | Variation par rapport au mois dernier, en % et en dollars |
| **Churn (30d)** | Agences perdues par rapport au nombre de départ ; rouge à 5 % ou plus |
| **LTV** | Valeur à vie estimée par agence |
| **NRR (6m)** | MRR d'aujourd'hui par rapport à il y a six mois ; plus de 100 % veut dire que les agences existantes dépensent davantage |
| **Capacity** | Enfants inscrits par rapport aux places sous licence |

## Lire la performance de la plateforme

- **Operational health** : **Email delivered** et **Open rate** (7 derniers jours), **Failed sends**, **Suppressed** (retenus par l'interrupteur d'urgence des courriels), taille de la **Database** et **API status**.
- **Usage & engagement** : **Logins today**, **Logins (7d)**, **Active users** (24 heures), **Active agencies** (30 jours, sur le total) et **Dormant** (aucune activité depuis 30 jours).

> 💡 Une agence inactive a souvent cessé d'utiliser KiddieTrac sans annuler. Un appel de l'équipe des ventes en vaut la peine.

## Qui peut faire quoi

- **Administrateurs de la plateforme** : voient cet écran. Il couvre toutes les agences.
- **Administrateurs d'agence et directeurs de service de garde** : ne voient jamais les chiffres de toutes les agences. Ils utilisent leur propre **Dashboard**, limité à leur agence.

## Questions fréquentes et problèmes

??? « Could not load » apparaît sous la bannière. | La connexion a coupé ou votre session a pris fin. Actualisez la page.
??? Le MRR ici diffère de celui du tableau MRR ou des rapports de ventes. | Chaque écran mesure à un moment et sur une période différents. Cette tuile est le total mensuel récurrent actuel ; les rapports de ventes comptent ce qui a été facturé dans la période choisie.
??? Crash reports affiche une erreur. | Notez qui et quoi, et vérifiez **Server health**. Une erreur après une mise à jour est souvent sans conséquence ; si elle se répète, il faut la corriger.
??? Failed sends est en rouge. | Plus de 2 % des courriels ont échoué dans les 7 derniers jours. Ouvrez **Mail settings** et le journal des erreurs de courriel pour savoir pourquoi.
??? Pourquoi certains chiffres sont-ils à zéro? | Une plateforme toute neuve ou une période calme. Les totaux se remplissent à mesure que les agences utilisent KiddieTrac.

## Écrans liés

[[open: #platform-agencies | Toutes les agences]] · [[open: #server-health | État du serveur]] · [[open: #admin-mrr | Tableau MRR]] · [[open: #platform-sales | Rapports de ventes]] · [[open: #usage-spend | Utilisation et dépenses]]

Voir aussi : [[platform-admin-overview]] · [[platform-sales-reports]]
