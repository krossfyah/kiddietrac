---
title: Circuits de documents en plusieurs étapes
---
# Circuits de documents en plusieurs étapes

**Administration → Flux de documents** sert pour une courte entente que plusieurs personnes
doivent signer **dans l’ordre** : un parent signe, puis la direction contresigne, par
exemple. Vous tapez le texte une seule fois, vous indiquez qui signe et dans quel ordre, et
chaque personne signe à l’écran quand c’est son tour. Chaque signature est horodatée et le
document terminé est verrouillé.

[[open: #doc-workflows | Ouvrir Flux de documents]]

> 💡 Pour faire signer un **PDF** téléversé, suivre qui l’a signé, et le réviser ou le renvoyer, utilisez plutôt le **Gestionnaire de formulaires**. Les flux de documents servent pour un texte tapé qui passe d’un signataire au suivant.

## Démarrer un circuit

1. Ouvrez **Flux de documents** et appuyez sur **+ Nouveau flux**.
2. Dans **Nouveau circuit de documents**, tapez le **Titre** (par exemple « Consentement pour la sortie »).
3. Tapez le **Type de document**, un mot court pour regrouper, comme `consent` ou `contract`.
4. Tapez ou collez le texte complet dans **Document text (what signers agree to)**.
5. Appuyez sur **Continuer**.
6. Dans **Ordre de signature**, inscrivez les rôles des signataires dans l’ordre, séparés par des virgules, dans **Signataires dans l’ordre** : `guardian`, `educator`, `centre_director` ou `agency_admin`. Par exemple `guardian, centre_director`. Six au maximum.
7. Confirmez. Le message **Circuit créé** apparaît et le circuit s’ajoute à la liste à l’**Étape 1**.

## Signer votre étape

1. Ouvrez **Flux de documents** et appuyez sur **Ouvert** à côté du circuit.
2. Lisez le texte en haut, puis la liste **Signataires** dessous : **✓ signed** avec une date, **… awaiting** pour l’étape en cours, **· pending** pour les étapes à venir.
3. Quand c’est votre tour, une section **Signer maintenant** apparaît. Tapez votre nom complet dans **Votre nom complet**.
4. Dessinez votre signature dans la case. **Effacer la signature** permet de recommencer.
5. Appuyez sur **Signer et passer à la suite**. Le message **Signé** apparaît et le circuit passe à l’étape suivante.

Quand la dernière personne signe, le statut devient **complete** et la personne qui a
démarré le circuit reçoit une notification : « Document fully signed ».

## Lire la liste

| Colonne | Signification |
|---|---|
| **Titre** | Le nom que vous lui avez donné. |
| **Type** | Le type de document tapé. |
| **Étape actuelle** | Le signataire attendu : **Étape 1**, **Étape 2** … |
| **Statut** | **in_progress** tant qu’il manque des signatures, **complete** quand elles y sont toutes. |
| **Créé** | Quand il a été démarré. |

## Ce que chaque signature enregistre

Sous chaque étape signée, l’écran affiche le nom du signataire et son estampille : la date et
l’heure dans le fuseau horaire de votre agence, l’adresse IP et l’emplacement approximatif de
cette adresse. Le serveur garde aussi une empreinte (un hachage) du texte avec chaque
signature, ce qui permet de démontrer plus tard que le texte n’a pas changé après la
signature.

## Qui peut faire quoi

- Les **administrateurs de l’agence** démarrent les circuits et voient tous ceux de leur agence.
- **Toute personne qui a le rôle de l’étape en cours** dans votre agence peut signer cette étape : un parent pour une étape `guardian`, une direction pour une étape `centre_director`.
- Personne ne peut signer une étape qui n’est pas encore la sienne, et un circuit terminé ne peut plus être modifié.

## FAQ et quand quelque chose ne va pas

??? Le prochain signataire est-il avisé que c’est son tour? | Pas pour les étapes définies par rôle, et c’est ainsi que cet écran les crée. Dites au prochain signataire d’ouvrir Flux de documents, ou utilisez le Gestionnaire de formulaires, qui envoie des courriels.
??? Je ne vois pas Signer maintenant. | Ce n’est pas encore votre tour, ou votre rôle n’est pas celui que demande l’étape. Regardez la liste Signataires pour savoir à qui c’est le tour.
??? « Name required » ou « Please sign first ». | Tapez votre nom complet et dessinez votre signature avant d’appuyer sur Signer et passer à la suite.
??? J’ai mal tapé les rôles. | Un circuit ne se modifie pas. Démarrez-en un nouveau dans le bon ordre et laissez l’ancien non signé.
??? Puis-je joindre un PDF? | Non. Un circuit contient du texte tapé. Pour un PDF, utilisez le Gestionnaire de formulaires ou les Documents électroniques.
??? La liste dit « No workflows yet. » | Personne dans votre agence n’en a démarré. Appuyez sur + Nouveau flux.

## Écrans liés

[[open: #forms-manager | Gestionnaire de formulaires]] [[open: #edocuments | Documents électroniques]]

- [Gestionnaire de formulaires et trousses de formulaires](forms-manager)
- [Réviser, contresigner et renvoyer des formulaires](reviewing-and-counter-signing-forms)
