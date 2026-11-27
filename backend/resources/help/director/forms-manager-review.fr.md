---
title: Gestionnaire de formulaires pour la direction et les auditeurs
---
# Gestionnaire de formulaires pour la direction et les auditeurs

Le **Gestionnaire de formulaires** contient les formulaires PDF de l’agence : qui doit les
signer, ce qui a été envoyé, et chaque copie remplie. Une **direction de service de garde**
s’en sert pour les personnes de ses propres services de garde : téléverser des formulaires,
les envoyer, réviser et contresigner ce qui revient. Un **auditeur** voit les mêmes dossiers
en lecture seule, pour vérifier que les formulaires ont bien été envoyés, signés et révisés.

[[open: #forms-manager | Ouvrir le Gestionnaire de formulaires]]

L’écran a quatre onglets : **📚 Bibliothèque**, **📦 Plusieurs formulaires**,
**📥 Demander des fichiers** et **✅ Terminé**.

## Trouver un formulaire signé (direction et auditeurs)

1. Ouvrez le **Gestionnaire de formulaires** et appuyez sur l’onglet **✅ Terminé**.
2. Tapez un nom ou un titre de formulaire dans la boîte de recherche au-dessus du tableau pour réduire la liste. Cliquez sur un en-tête de colonne pour trier.
3. Lisez la ligne : **Formulaire**, **Description**, **Signé par**, **Signé (heure de l’agence)**, puis ce qui s’est passé ensuite.
4. Appuyez sur le **⋮** de la ligne et choisissez **Voir le formulaire** pour ouvrir la copie remplie, ou **Télécharger le formulaire rempli** pour l’enregistrer.

Les colonnes d’état répondent aux questions qu’une inspection pose d’habitude :

| Colonne | Ce qu’elle vous dit |
|---|---|
| **Copie envoyée par courriel** | Un crochet et une heure quand la copie remplie a atteint l’adresse inscrite sur le formulaire. **Non envoyé** : il y a une adresse, mais rien n’est encore parti; **Non configuré** : le formulaire n’en a pas. |
| **Renvoyé** | **En attente du signataire** tant que l’agence attend une correction, **Soumis de nouveau** quand une nouvelle copie est arrivée. |
| **Contresigné** | La date et qui a contresigné, **En attente de révision** si personne ne l’a fait, ou **Non requis** pour un formulaire qui n’a pas besoin de contre-signature. |
| **Synchronisée** | **✓ Filed** avec le nom du dossier où il se trouve. **Non classé** : il n’est pas encore au dossier de la personne; **Orphelin** : le compte où il a été classé n’existe plus. |

[[show: #forms-manager @ Completed > Counter-signed | Me montrer la colonne Contresigné]]

## Vérifier ce qui a été envoyé, et à qui

1. Appuyez sur l’onglet **📦 Plusieurs formulaires**.
2. L’historique liste chaque trousse envoyée, qui l’a envoyée et quand.
3. Lisez **Signé** (combien de signatures sont revenues), **Envoyé par courriel** et **Lu**. **Non livré** sous Lu est celui qui doit vous inquiéter : le courriel n’est jamais arrivé.
4. Appuyez sur **⋮ > View forms** pour voir qui a reçu quel formulaire et quand chaque personne a signé. **⋮ > Download PDF** réunit tous les formulaires remplis de la trousse en un seul PDF pour votre dossier. La direction a aussi **⋮ > Print summary**.

## Réviser et contresigner (direction)

1. Dans **✅ Terminé**, trouvez une ligne qui indique **En attente de révision**.
2. Appuyez sur **⋮ > Examiner et contresigner**. Le formulaire rempli s’ouvre en grand.
3. Lisez-le. Ajoutez une note sous **Ajouter de l’information** si quelque chose doit être noté.
4. Appuyez sur **Next: counter-sign →**, puis sur **✍️ Add your signature**.
5. Laissez **Envoyer la copie contresignée par courriel aux deux parties** coché, et appuyez sur **Terminer la contre-signature**.

S’il manque quelque chose ou si une réponse est erronée, appuyez plutôt sur
**⋮ > Renvoyer au signataire**, indiquez sous **Ce qui doit être modifié** exactement quoi
corriger, et appuyez sur **Le renvoyer**. Toutes les étapes sont dans
[Réviser, contresigner et renvoyer des formulaires](reviewing-and-counter-signing-forms).

## Téléverser et envoyer des formulaires (direction)

1. Dans **📚 Bibliothèque**, appuyez sur **+ Upload a form**.
2. Si vous dirigez plus d’un service de garde, choisissez d’abord le service de garde dans **🏫 Centre for new forms and new parents**.
3. Remplissez le titre et la description, choisissez **Who must sign it?** ou des personnes sous **Ou envoyer à des personnes précises**, choisissez le PDF et appuyez sur **Téléverser le formulaire**.
4. Pour envoyer plusieurs formulaires par courriel d’un coup, allez dans **📦 Plusieurs formulaires** et appuyez sur **+ Send multiple forms**.

Les étapes complètes de téléversement et d’envoi de trousses sont dans
[Gestionnaire de formulaires et trousses de formulaires](forms-manager).

## Qui peut faire quoi

| | Direction de service de garde | Auditeur |
|---|---|---|
| Voir les formulaires, les envois et les copies remplies | Les personnes de ses services de garde | En lecture seule, l’agence auditée |
| Voir et télécharger les formulaires | Oui | Oui |
| Téléverser, modifier, activer ou désactiver, supprimer un formulaire | Pour ses services de garde seulement | Non |
| Envoyer des trousses, des rappels et des demandes de fichiers | Oui | Non |
| Contresigner, renvoyer, supprimer une signature | Oui | Non |
| **Synchroniser maintenant** et formulaires de toute l’agence | Non (administrateurs de l’agence seulement) | Non |

Un auditeur voit en haut l’étiquette grise
**🔒 Read-only (auditor) — you can view and download, not change**. Tous les boutons qui
changeraient quelque chose sont cachés, et les menus **⋮** n’offrent que voir, télécharger
et ouvrir.

## FAQ et quand quelque chose ne va pas

??? Je suis à la direction et un formulaire n’a pas de bouton modifier ni supprimer. | C’est un formulaire de toute l’agence, ou créé pour un autre service de garde. Seul un administrateur de l’agence peut le changer. Vous pouvez quand même l’envoyer et réviser ce qui revient.
??? Pourquoi n’ai-je pas le bouton Synchroniser maintenant? | La synchronisation touche toute l’agence; elle est donc réservée aux administrateurs. Demandez à l’un d’eux d’appuyer sur Synchroniser maintenant dans l’onglet Terminé.
??? Terminé affiche « No forms have been signed yet. » | Personne que vous pouvez voir n’a encore signé. Une direction ne voit que les personnes de ses propres services de garde.
??? Comme auditeur, puis-je tout exporter? | Utilisez ⋮ > Download PDF sur une trousse dans Plusieurs formulaires pour obtenir tous ses formulaires remplis en un seul PDF, ou téléchargez les formulaires un à un dans Terminé.
??? J’ai cliqué et j’ai reçu « Auditor accounts are read-only. » | Les comptes d’auditeur peuvent consulter, mais jamais rien changer. Demandez à un administrateur de l’agence si un changement est nécessaire.
??? Consulter un formulaire avertit-il quelqu’un? | Non. Voir et télécharger n’envoient rien. Contresigner et renvoyer envoient un courriel au signataire.

## Écrans liés

[[open: #forms-manager | Gestionnaire de formulaires]] [[open: #compliance | Conformité]]

- [Réviser, contresigner et renvoyer des formulaires](reviewing-and-counter-signing-forms)
- [Gestionnaire de formulaires et trousses de formulaires](forms-manager)
- [Demander des fichiers](requesting-files)
