---
title: Sauvegardes de la base de données
category: Settings
order: 45
roles: agency_admin, platform_admin
---
# Sauvegardes de la base de données

**Paramètres → Sauvegardes.** Indique si la copie nocturne de la base de données de la plateforme est
bien effectuée, quand a eu lieu la dernière copie réussie et combien de copies sont conservées.

[[open: #backups | Ouvrir Sauvegardes]]

## Qui peut l’utiliser

**Les admins de la plateforme seulement.** Une seule base de données sert toutes les agences de la plateforme, donc une
sauvegarde contient les données de tout le monde, pas celles d’une seule agence. Un administrateur de l’agence ne voit pas l’élément
de menu, et le serveur refuse tout autre utilisateur.

## La carte d’état

La carte du haut répond à « sommes-nous couverts ? » en une ligne :

- **En bonne santé** : une bonne copie a été faite au cours de la dernière journée environ.
- **Plus d’une journée** : l’exécution de la nuit dernière n’en a pas produit.
- **Périmée — plus de 2 jours** : considérez-le comme un échec.
- **Dernière exécution échouée** : l’erreur est affichée en rouge en dessous.
- **Aucune sauvegarde enregistrée pour l’instant**.

En dessous : **Dernière bonne copie** (il y a combien de temps, et l’heure exacte), le nombre de **copies conservées**,
le total de **Mo sur disque**, et la taille de la plus récente.

**Planificateur vu pour la dernière fois** est la ligne à vérifier quand quelque chose semble anormal. Toutes les tâches planifiées
de la plateforme, pas seulement les sauvegardes, dépendent d’un planificateur qui s’exécute chaque minute. S’il
indique *jamais — le cron ne s’exécute peut-être pas*, rien de planifié ne se produit. Un avertissement s’affiche aussi
si le dossier de sauvegarde n’est pas accessible en écriture.

## Paramètres

- **Sauvegarde nocturne** : activée ou désactivée. Désactivée signifie qu’aucune copie de la base de données n’est effectuée.
- **Heure** : l’heure d’exécution de la copie nocturne, selon l’**heure du serveur**. Choisissez une heure creuse. La copie
  ne verrouille rien, mais elle lit toutes les tables. Par défaut : 03:30.
- **Conserver** : le nombre de copies quotidiennes à conserver, de 1 à 90. Par défaut : 14. Les copies plus anciennes
  sont supprimées après chaque exécution.

Appuyez sur **Enregistrer**. Désactiver les sauvegardes s’enregistre avec un avertissement orange, *Enregistré — les sauvegardes nocturnes
sont maintenant DÉSACTIVÉES*, plutôt qu’une coche verte.

> **Important :** Lorsque les sauvegardes sont désactivées, il n’y a aucune copie nocturne de cette base de données. La désactivation est consignée dans le journal de vérification exactement en ces termes.

## Sauvegarder maintenant

**Sauvegarder maintenant** effectue une copie immédiatement et attend le résultat; vous voyez donc si elle a
**réussi**, et pas seulement qu’elle a été demandée. Elle lit toutes les tables, alors patientez un moment.

[[show: #backups @ Back up now | Montrez-moi le bouton Sauvegarder maintenant]]

## Copies sur disque

La carte du bas énumère jusqu’à 30 des copies les plus récentes, avec l’heure de chacune,
son nom de fichier et sa taille.

## Pourquoi vous ne pouvez pas en télécharger une

**Les sauvegardes ne peuvent pas être téléchargées depuis le portail, et c’est voulu.** Un seul fichier contient
tous les enfants, tuteurs, adresses et notes médicales de la plateforme. Les copies sont stockées
hors de la racine web, et une restauration est effectuée par une personne, via SSH. L’écran affiche la commande de restauration
pour la personne qui s’en charge.

## Quand quelque chose ne va pas

- Chaque copie est vérifiée pour son intégrité après son écriture. Une copie échouée ou corrompue est
  jetée plutôt que conservée.
- Une exécution échouée s’affiche comme **Dernière exécution échouée** ici et déclenche une alerte de gravité élevée sous
  **Paramètres → Alertes de sécurité**.
- Désactiver volontairement les sauvegardes n’est pas considéré comme un échec et ne déclenche donc pas
  d’alerte chaque nuit.
- La modification des paramètres et l’exécution manuelle d’une sauvegarde sont toutes deux consignées dans le journal de vérification,
  avec les valeurs avant et après.

Voir aussi : [Alertes de sécurité et surveillance](security-alerts),
[Admin de la plateforme (aperçu)](platform-admin-overview),
[Conservation des données et conformité](data-retention-compliance).
