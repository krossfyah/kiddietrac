---
title: Réviser, contresigner et renvoyer des formulaires
category: Administration
order: 68
roles: agency_admin, platform_admin
---
# Réviser, contresigner et renvoyer des formulaires

**Engagement → Gestionnaire de formulaires → Terminé.** Tous les formulaires que quelqu’un a signés, et ce que
l’agence en a fait. Un formulaire terminé appelle deux réponses possibles : l’**accepter** en le contresignant, ou
le **renvoyer** avec une note indiquant ce qui doit être corrigé.

[[show: #forms-manager @ Completed > Counter-signed | Montrez-moi la colonne Contresigné]]

## Lire le tableau

À côté du formulaire, le signataire et l’heure de signature (à l’heure de l’agence) :

- **Copie envoyée par courriel** : si la copie terminée est arrivée à l’adresse définie sur le formulaire.
  **Non configuré** signifie que le formulaire n’a pas d’adresse d’envoi.
- **Renvoyé** : **En attente du signataire** pendant que vous attendez une correction (avec un nombre
  s’il a été retourné plus d’une fois), **Soumis de nouveau** une fois qu’il en a envoyé un nouveau.
- **Contresigné** : la date et le nom du signataire, ou **En attente de révision**. « En attente de révision »
  est un rappel : c’est un formulaire que personne à l’agence n’a encore examiné.
- **Synchronisé** : si le formulaire terminé figure au dossier du signataire. **✓ Classé**
  indique le dossier. **Non classé** signifie généralement que le compte n’existait pas à la signature
  du formulaire. Appuyez sur **Synchroniser maintenant** dans la barre rouge au-dessus du tableau pour corriger. **Orphelin**
  signifie qu’il a été classé dans un compte qui n’existe plus; la synchronisation n’y changera rien.

## Réviser et contresigner

Choisissez **⋮ → Réviser et contresigner**. Cela se fait en deux étapes, dans cet ordre, afin qu’une
contre-signature signifie toujours que quelqu’un a réellement lu le formulaire.

1. **Réviser.** Le formulaire terminé s’ouvre en pleine taille. Sous **Ajouter de l’information**, vous pouvez saisir
   une note. Elle apparaît sur la page de contre-signature et dans le courriel. Pour modifier le document lui-même :
   - sur un formulaire à remplir, **✎ Modifier le formulaire** le rouvre avec les réponses du signataire, afin que vous
     puissiez corriger ou compléter les mêmes champs que lui.
   - sur tout autre PDF, **✎ Ajouter de l’information au formulaire** vous permet d’écrire sur la page.

   Chaque champ modifié est énuméré par son nom sur la contre-signature, de sorte qu’on voit clairement ce que
   l’agence a changé. La copie originale du signataire n’est jamais écrasée.
2. **Contresigner.** Appuyez sur **Suivant : contresigner →**, vérifiez votre note, puis
   **✍️ Ajouter votre signature**. Votre signature s’affiche avant tout envoi. Laissez
   **Envoyer la copie contresignée par courriel aux deux parties** coché pour l’envoyer au signataire et à
   l’adresse figurant sur le formulaire. Appuyez sur **Terminer la contre-signature**.

Votre signature est apposée sur une nouvelle page finale. Rien de ce que le signataire a signé n’est modifié. Ensuite,
le menu propose plutôt **Voir le PDF contresigné**. La confirmation vous indique qui
a réellement reçu le courriel. Si elle indique que rien n’a pu être envoyé par courriel, vérifiez les paramètres
de livraison de votre agence.

> **Remarque :** Un formulaire ne peut être contresigné qu’une seule fois.

## Renvoyer pour correction

Lorsque quelque chose manque ou est erroné, choisissez **⋮ → Renvoyer au signataire**, ou appuyez sur
**↩️ Renvoyer pour correction** pendant la révision.

1. Sous **Ce qui doit être modifié**, indiquez exactement ce qu’il faut corriger. La note est obligatoire, et c’est
   la seule chose qui lui est communiquée; soyez donc précis.
2. Appuyez sur **Le renvoyer**.

Ce qui se passe ensuite :

- Le formulaire redevient en attente pour la personne, **avec ses réponses toujours à l’intérieur**. Votre
  note s’affiche sur le formulaire dans sa liste **Formulaires à signer**, sous « Renvoyé pour correction ».
- Elle reçoit un courriel et une notification dans l’application. Si son compte n’a pas d’adresse courriel,
  la boîte de dialogue vous en avise avant l’envoi, et le résultat indique **Consigné, non envoyé par courriel**.
- La soumission que vous avez renvoyée est conservée telle quelle. Sa correction arrive comme une
  nouvelle soumission, et la colonne **Renvoyé** passe à **Soumis de nouveau**.

Vous pouvez renvoyer un formulaire autant de fois que nécessaire pour une correction.

> **Important :** Renvoyer et contresigner s’excluent mutuellement; vous ne pouvez faire qu’un seul des deux à la fois. Un formulaire contresigné ne peut pas être renvoyé, et un formulaire en attente du signataire ne peut pas être contresigné tant qu’il n’a pas été soumis de nouveau.

## Autres actions sur un formulaire terminé

- **Voir le formulaire** et **Télécharger le formulaire terminé**.
- **Envoyer la copie par courriel à …** envoie la copie terminée à l’adresse définie sur le formulaire,
  ou la renvoie.
- **Supprimer cette signature** retire la signature. Le PDF terminé et la copie au dossier du signataire
  sont supprimés, et le formulaire redevient en attente pour lui. Utilisez-la
  lorsque la mauvaise personne a signé, ou que quelqu’un a signé le mauvais document. L’action est consignée dans le journal
  d’audit et ne peut pas être annulée.

## Questions courantes

??? Où se trouve le formulaire corrigé? | Il arrive sous la forme d’une nouvelle ligne terminée. La ligne que vous avez renvoyée passe à Soumis de nouveau.
??? Pourquoi un formulaire terminé ne figure-t-il pas dans les Documents de la famille? | Sa colonne Synchronisé indiquera Non classé. Appuyez sur Synchroniser maintenant au-dessus du tableau.
??? Pourquoi ne puis-je pas contresigner ce formulaire? | Il a été renvoyé et attend le signataire. Contresignez plutôt sa nouvelle soumission.

Voir aussi : [Gestionnaire de formulaires et trousses de formulaires](forms-manager), [Demander des fichiers](requesting-files),
[Flux de travail documentaires](doc-workflows).

## Formulaires sans contre-signature

Tous les formulaires n’ont pas besoin d’être signés par le bureau. Dans le **Gestionnaire de formulaires**, lorsque vous ajoutez ou modifiez un formulaire
(y compris dans la bibliothèque de formulaires et les trousses multiformulaires), désactivez **Exige une contre-signature**.
Ses copies signées affichent **Non requis** dans Terminé et ne sont jamais incluses dans les rappels.

## Rappels quotidiens de contre-signature

Onglet **Paramètres → Notifications → ✍️ Contre-signature**. Les administrateurs et les directeurs reçoivent un
courriel énumérant les formulaires signés qui les attendent encore, selon votre horaire : tous les jours, les jours de semaine,
chaque semaine ou tous les quelques jours, à l’heure que vous choisissez. Les directeurs ne voient que les formulaires de leur propre
prestataire; si un prestataire n’a pas de directeur, ses formulaires vont aux administrateurs. Vous pouvez aussi activer une notification
par cloche ou une notification push, utiliser **Aperçu** pour voir le courriel, et **Envoyer un test…** à une adresse que vous saisissez.
