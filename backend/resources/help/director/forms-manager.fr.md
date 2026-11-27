---
title: Gestionnaire de formulaires et trousses de formulaires
category: Administration
order: 66
roles: agency_admin, platform_admin
---
# Gestionnaire de formulaires et trousses de formulaires

**Engagement → Gestionnaire de formulaires.** Téléversez un formulaire PDF, envoyez-le aux personnes qui doivent le signer et voyez ce qui est revenu. Il comporte quatre onglets : **Bibliothèque**, **Plusieurs formulaires**, **Demander des fichiers** et **Terminé**.

[[open: #forms-manager | Ouvrir le Gestionnaire de formulaires]]

## Bibliothèque : les formulaires eux-mêmes

La Bibliothèque est l’endroit où l’on téléverse et conserve un formulaire. Au téléversement, vous lui donnez :

- Un **titre** et une **description**. La description indique à quoi sert le formulaire; elle est affichée à côté du titre partout où le formulaire apparaît.
- **Qui doit le signer?** Les parents, les éducateurs, les visiteurs à domicile ou les directeurs. Vous pouvez plutôt choisir des personnes précises sous **Ou envoyer à des personnes précises**, et il n’ira alors qu’à elles.
- **Permettre aux destinataires de remplir ce formulaire** : cochez cette option lorsque le PDF comporte des champs à remplir. Les personnes saisissent alors les données à l’écran et le signent directement. Laissez-la décochée pour un avis à lire et signer.
- **Permettre la réutilisation de ce formulaire** : garde le formulaire ouvert après sa soumission, afin qu’il puisse être rempli de nouveau, une fois par enfant ou semaine après semaine. Chaque soumission est conservée comme un dossier distinct.
- **Envoyer les formulaires remplis par courriel à** (facultatif) : chaque fois que quelqu’un signe, le PDF rempli est envoyé par courriel à cette adresse.

Le tableau de la Bibliothèque a une colonne **Réutilisable** : **Oui** signifie que le formulaire reste dans la liste après sa signature et revient chaque jour (fiches quotidiennes comme une fiche de sieste) ; **Non** signifie qu’il s’agit d’un formulaire unique, terminé une fois signé.

> **Remarque :** Attribuer un formulaire depuis la Bibliothèque n’envoie de courriel à personne. Pour avertir les gens, envoyez-le depuis **Plusieurs formulaires**.

## Plusieurs formulaires : envoyer une trousse

L’intégration d’une famille exige généralement plusieurs formulaires à la fois. Une trousse attribue chaque formulaire que vous cochez à chaque personne que vous choisissez, et envoie à chacune **un seul** courriel qui les énumère tous.

[[show: #forms-manager @ Multiple forms > + Send multiple forms | Montrez-moi le bouton Envoyer plusieurs formulaires]]

1. Appuyez sur **+ Envoyer plusieurs formulaires**.
2. Cochez les formulaires. Chacun est marqué **📝 Remplir et signer** ou **✍️ Signer seulement**, afin que vous voyiez ce qu’on demandera réellement au destinataire. Seuls les formulaires actifs sont listés. **+ Téléverser un formulaire** vous mène à la Bibliothèque pour en ajouter un, puis vous ramène ici.
3. Choisissez des personnes dans la liste, ou saisissez des adresses courriel séparées par des virgules.
4. Ajoutez une note si vous le souhaitez. Elle figure dans le courriel.
5. Laissez **Les aviser par courriel maintenant** activé pour envoyer le courriel. Décochez-le pour attribuer discrètement : elles verront tout de même les formulaires dans le portail.
6. Appuyez sur **Envoyer la trousse**.

**Un parent qui n’est pas encore dans le système peut tout de même recevoir des formulaires.** Si une adresse saisie n’a pas de compte à votre agence, un compte provisoire est créé et les formulaires sont envoyés. Le lien du courriel lui permet de remplir et de signer sans mot de passe. Il ne l’invite pas au portail : cela reste une étape distincte.

> **Remarque :** Une adresse saisie qui est partagée par plus d’un compte à votre agence est associée à l’un d’eux, et le résultat l’indique. Pour choisir exactement qui, sélectionnez la personne dans la liste.

### L’historique des envois

L’onglet liste chaque trousse envoyée, avec la personne qui l’a envoyée et le moment. Servez-vous-en pour répondre à « est-ce déjà parti? ». Pour chaque envoi, vous voyez :

- **Signé** indique combien de signatures sont revenues (formulaires × personnes).
- **Envoyé par courriel** indique **Non envoyé** si vous avez attribué discrètement, **Aucun envoi** si personne n’a pu être joint par courriel, et sinon le nombre de courriels envoyés.
- **Lu** indique **Non livré** si le courriel n’est jamais parti, **Non ouvert** s’il a été livré mais que personne ne l’a ouvert, et **✓ Lu** une fois qu’il a été ouvert.

> **Astuce :** « Non ouvert » est un signal faible. Certaines applications de courriel ne signalent jamais l’ouverture; vérifiez donc d’abord **Non livré** : celui-là est un vrai problème.

Le menu **⋮** de chaque envoi offre :

- **Voir les formulaires** : qui a reçu quoi, et quand chaque personne a signé. Cliquez sur une date de signature pour ouvrir ce formulaire rempli. Utilisez **Renvoyer** dans une cellule non signée pour renvoyer un formulaire à une personne.
- **Imprimer le sommaire** et **Télécharger le PDF**. Le téléchargement fusionne tous les formulaires remplis en un seul PDF. Si rien n’a encore été signé, il vous donne plutôt les formulaires vierges.
- **Envoyer un rappel** renvoie la trousse par courriel. Les personnes qui ont déjà tout signé sont ignorées.
- **Renvoyer les copies remplies** envoie chaque formulaire terminé aux administrateurs et directeurs de votre agence, avec une copie à la personne qui l’a signé.
- **Supprimer de l’historique** retire la trace de l’envoi. Les formulaires, les attributions et les signatures restent exactement tels quels.

## Demander des fichiers et Terminé

- **Demander des fichiers** demande à quelqu’un des documents qu’il possède déjà, comme une pièce d’identité ou une carte de vaccination. Voir [Demander des fichiers](requesting-files).
- **Terminé** liste chaque formulaire signé. De là, vous examinez et contresignez un formulaire, ou le renvoyez pour correction. Voir [Examiner, contresigner et renvoyer des formulaires](reviewing-and-counter-signing-forms).

## Questions courantes

??? Pourquoi une trousse affiche-t-elle « 0 de 7 signés »? | Vérifiez d’abord la colonne Lu : si elle indique Non livré, la famille n’a jamais reçu le courriel.
??? Supprimer un envoi retire-t-il l’attribution des formulaires? | Non. Supprimer de l’historique retire seulement la ligne d’historique; les attributions et les signatures ne sont pas touchées.
??? Puis-je envoyer un formulaire à un nouveau parent sans identifiant de connexion? | Oui. Saisissez son adresse; un compte provisoire est créé et il signe depuis le lien reçu par courriel.

Voir aussi : [Demander des fichiers](requesting-files),
[Examiner, contresigner et renvoyer des formulaires](reviewing-and-counter-signing-forms),
[Créateur de formulaires personnalisés](custom-forms), [Flux de travail des documents](doc-workflows).
