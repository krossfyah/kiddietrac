---
title: Demander des fichiers
category: Administration
order: 67
roles: agency_admin, platform_admin
---
# Demander des fichiers

**Engagement → Gestionnaire de formulaires → Demander des fichiers.** Demandez à un parent ou à un membre du personnel des
documents qu’il possède déjà : une carte d’immunisation, les deux côtés d’une pièce d’identité, un feuillet fiscal. Il les
téléverse à partir d’un lien, sans mot de passe, et tout se range dans son propre dossier.

Une trousse de formulaires envoie un document que quelqu’un doit remplir. Une demande de fichiers fonctionne à l’inverse : elle
réclame quelque chose qu’il détient. Chacune est suivie ligne par ligne, de sorte que la question
« ont-ils tout envoyé? » a une réponse sans qu’il faille relire un fil de courriels.

[[show: #forms-manager @ Request files > + Request files | Montrez-moi le bouton Demander des fichiers]]

## Faire une demande

1. Appuyez sur **+ Demander des fichiers**.
2. **Demander à** : choisissez une personne, ou saisissez son adresse. L’adresse doit appartenir à quelqu’un
   qui a un compte dans votre agence.
3. Définissez une **Priorité** (Faible, Normale, Élevée, Urgente) et, si c’est important, une date **Requis avant le**.
4. Ajoutez une **Note** si la personne doit savoir où trouver quelque chose ou pourquoi vous en avez besoin.
5. Sous **De quoi avez-vous besoin?**, ajoutez une ligne par document. Chaque ligne comporte une description,
   un type (**Tout fichier**, **PDF**, **Photo/numérisation**, **Document Word**) et le nombre de fichiers
   attendus, jusqu’à 20. Utilisez **+ Ajouter une autre** pour d’autres lignes.
6. Laissez **Leur envoyer un courriel maintenant** coché et appuyez sur **Envoyer la demande**.

La personne reçoit un courriel avec un lien et un bouton pour chaque élément demandé. Le lien
fonctionne pendant 21 jours et n’accepte que les documents énumérés. Chaque fichier peut peser jusqu’à 15 Mo.

## Ce qui se passe à l’arrivée des fichiers

- Chaque fichier est classé au dossier de la personne; il apparaît donc dans ses **Documents** et dans
  sa fiche d’utilisateur. Il n’y a pas d’autre endroit où chercher.
- Lorsque le dernier élément arrive, la demande est marquée comme terminée. La personne qui l’a demandée
  reçoit un courriel à cet effet, et l’expéditeur reçoit un accusé de réception.

## Faire le suivi

Le tableau présente chaque demande avec la part déjà reçue (**2 sur 3**, ou **✓ Les 3**),
sa priorité et sa date d’échéance. Une échéance dépassée est marquée **en retard** en rouge.

Le menu **⋮** de chaque demande :

- **Voir les fichiers** : ce qui a été demandé, ce qui est arrivé, et **Voir** ou **Télécharger** pour
  chaque fichier.
- **Ouvrir le lien de téléversement** ouvre le même lien que celui envoyé par courriel et le copie dans votre
  presse-papiers. Utilisez-le lorsqu’une famille dit n’avoir jamais reçu le courriel, ou lorsqu’elle est au
  comptoir avec les documents sur son téléphone. Le courriel d’origine continue de fonctionner. La récupération
  du lien est consignée dans le journal d’audit.
- **Télécharger n fichier(s)** enregistre tout ce qu’elle a envoyé, un fichier après l’autre.
- **Envoyer un rappel** lui écrit au sujet des éléments encore manquants.
- **Supprimer la demande** supprime la demande elle-même. Les fichiers déjà envoyés restent
  à son dossier.

> **Important :** Le lien de téléversement est une véritable clé donnant accès à la page de téléversement de cette personne. Ne le partagez qu’avec la personne pour qui il a été créé.

## Qui peut le faire

Les administrateurs d’agence et les directeurs de votre agence. Les fichiers s’ouvrent par le portail, jamais
à partir d’une adresse publique, car il s’agit de pièces d’identité et de dossiers de santé.

Voir aussi : [Gestionnaire de formulaires et trousses de formulaires](forms-manager),
[Réviser, contresigner et renvoyer des formulaires](reviewing-and-counter-signing-forms).
