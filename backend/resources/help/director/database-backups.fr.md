---
title: Sauvegardes de la base de données
---
# Sauvegardes de la base de données

**Platform › Backups** indique si la copie nocturne de la base de données de la plateforme est vraiment faite, quand date la dernière bonne copie et combien sont conservées. C'est aussi là que vous changez l'heure de la sauvegarde et la durée de conservation, et que vous faites une copie à la main avant un travail risqué. Ouvrez-le lors de la vérification quotidienne, avant un gros changement, ou quand Server health affiche la dernière sauvegarde en rouge.

[[open: #backups | Ouvrir Backups]]

## Vérifier que vous êtes couvert

1. Ouvrez **Platform › 🗄️ Backups**.
2. Lisez la ligne de couleur de la carte du haut :
   - **Healthy** : une bonne copie a été faite dans la dernière journée environ.
   - **Older than a day** : l'exécution de la nuit dernière n'a pas produit de copie.
   - **Stale — more than 2 days old** : considérez-le comme un échec.
   - **Last run failed** : l'erreur est affichée en rouge en dessous.
   - **No backup recorded yet**.
3. Lisez **Last good copy** (il y a combien de temps et l'heure exacte), le nombre de **copies kept**, les **MB on disk** et la taille de la dernière copie.
4. Vérifiez **Scheduler last seen**. Toutes les tâches planifiées de la plateforme, pas seulement les sauvegardes, dépendent d'un seul planificateur qui roule chaque minute. S'il indique **never — the cron may not be running**, rien de planifié ne se fait. Un **backup folder is not writable** en rouge veut dire que les copies ne peuvent pas être enregistrées.

## Changer l'horaire

1. Sur la deuxième carte, laissez **Nightly backup** activé. Désactivé veut dire qu'aucune copie de la base de données n'est faite.
2. Réglez **Time**, à l'heure du serveur. Choisissez une heure tranquille : la copie ne verrouille rien, mais elle lit toutes les tables. Par défaut : 03 h 30.
3. Réglez **Keep**, le nombre de copies quotidiennes à garder (1 à 90 jours; 14 par défaut). Les copies plus anciennes sont supprimées après chaque exécution.
4. Appuyez sur **Save**. Vous voyez **✓ Saved**, ou, si vous avez désactivé les sauvegardes, un **⚠ Saved — nightly backups are now OFF** orangé au lieu d'un crochet.

> ⚠️ Sans sauvegardes, il n'y a aucune copie nocturne de la base de données. La désactivation est inscrite au journal d'audit.

## Faire une sauvegarde maintenant

Faites-le avant un changement risqué, comme une grosse importation ou une mise à niveau.

1. Appuyez sur **Back up now**.
2. Attendez pendant que **Taking a backup… this reads every table, give it a moment.** s'affiche. Le bouton attend le résultat.
3. Vous voyez **✓ Backup taken** et la nouvelle copie apparaît sous **Copies on disk**, ou bien une erreur en rouge et la carte d'état indique **Last run failed**.

[[show: #backups @ Back up now | Montrez-moi]]

## Copies sur disque

La carte du bas liste les copies les plus récentes, avec l'heure de chacune, son nom de fichier et sa taille. Chaque copie est vérifiée après son écriture; une copie échouée ou corrompue est jetée plutôt que gardée. Les copies sont chiffrées pour que seule la clé hors ligne de KiddieTrac puisse les ouvrir; une copie qui n'a pas pu être chiffrée est gardée quand même et déclenche une alerte, car une sauvegarde vaut mieux qu'aucune.

> ℹ️ **Les copies ne peuvent pas être téléchargées depuis le portail, et c'est voulu.** Un seul fichier contient tous les enfants, tuteurs, adresses et notes médicales de la plateforme. Elles sont gardées hors du site Web et restaurées par une personne qui a accès au serveur et à la clé hors ligne, jamais à partir d'un navigateur.

## Qui peut faire quoi

| Rôle | Accès |
|---|---|
| Administrateur de la plateforme | Tout cet écran. |
| Administrateurs d'agence et tous les autres | Aucun accès. Une seule base de données sert toutes les agences; une sauvegarde contient donc les données de tout le monde, pas d'une seule agence. Le serveur refuse tous les autres. |

## Questions fréquentes et problèmes

??? L'état indique « Last run failed ». | Lisez l'erreur en rouge en dessous. Corrigez la cause (souvent l'espace disque), puis appuyez sur Back up now pour vérifier que ça fonctionne. Une exécution échouée déclenche aussi une alerte de gravité élevée dans Security alerts.
??? « Scheduler last seen: never — the cron may not be running ». | Le planificateur est arrêté, alors aucune tâche planifiée ne roule. C'est urgent : faites-le redémarrer sur le serveur.
??? « Backups are managed by platform administrators only. » | Vous n'êtes pas connecté comme administrateur de la plateforme.
??? J'ai désactivé les sauvegardes exprès. Vais-je recevoir des alertes chaque nuit? | Non. La désactivation n'est pas considérée comme un échec.
??? Une agence peut-elle récupérer ses propres données? | Pas à partir d'une sauvegarde de cet écran. Utilisez l'exportation et les demandes de confidentialité de l'agence.
??? Quelqu'un est-il avisé quand une sauvegarde roule? | Non. Seuls les échecs déclenchent une alerte; les changements de réglages et les exécutions manuelles vont au journal d'audit avec les valeurs avant et après.

## Écrans liés

[[open: #server-health | État du serveur]] [[open: #security-alerts | Alertes de sécurité]] [[open: #compliance-evidence | Preuves de conformité]] [[open: #maintenance | Maintenance]]

Voir aussi : [Alertes de sécurité et surveillance](security-alerts), [Conservation des données et conformité](data-retention-compliance).
