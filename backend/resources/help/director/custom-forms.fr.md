---
title: Créateur de formulaires personnalisés
---
# Créateur de formulaires personnalisés

**Administration → Formulaires personnalisés** vous permet de bâtir vos propres formulaires
en ligne, question par question : inscription, consentement, sondage, commentaires, et même
un formulaire qui prend un paiement. Les familles les remplissent dans leur portail parent,
et chaque réponse revient ici, prête à lire ou à télécharger.

[[open: #admin-forms | Ouvrir Formulaires personnalisés]]

> 💡 Les formulaires personnalisés sont des formulaires que vous **bâtissez** à l’écran. Pour faire signer un PDF déjà prêt, utilisez plutôt le **Gestionnaire de formulaires**.

## Bâtir un nouveau formulaire

1. Ouvrez **Formulaires personnalisés** et appuyez sur **+ Nouveau formulaire**.
2. Tapez le **Titre du formulaire**, par exemple « Autorisation de sortie éducative ».
3. Rédigez la **Description (shown to parents above the form)** : à quoi sert le formulaire et pour quand il faut le remplir.
4. Choisissez les **Destinataires** : **Toutes les familles**, **Actuellement inscrits**, **Liste d’attente**, **Clients potentiels** ou **Personnel seulement**.
5. Laissez le **Statut** à **Draft (only you see it)** pendant que vous travaillez.
6. Sous **Champs**, une première question est déjà là. Tapez son libellé dans **Field label (visible to respondent)** et choisissez son type.
7. Ajoutez d’autres questions avec les boutons sous la liste : **+ Short text**, **+ Long text**, **+ Email**, **+ Number**, **+ Date**, **+ Dropdown**, **+ Checkbox**, **+ Multiple choice**, **+ ✍️ Signature** et **+ 💳 Payment**.
8. Appuyez sur **Créer le formulaire**. Le formulaire apparaît dans la liste avec l’étiquette **DRAFT**.

### Régler chaque question

- **Requis** : cochez-le quand la question doit obligatoirement avoir une réponse.
- **Placeholder (optional)** et **Help text (optional)** : un indice dans la case et sous la case.
- **Options (one per line)** : pour **Liste déroulante** et **Choix multiple**. Il faut au moins deux options; le formulaire ne s’enregistre pas avec moins.
- **Amount to charge ($)** : pour **💳 Payment**. Quand la famille soumet le formulaire, le montant est inscrit à son compte.
- **✍️ Signature** donne à la personne un espace pour signer du doigt ou à la souris. La signature est gardée avec ses réponses.
- Les petites flèches montent ou descendent une question; le **×** rouge la retire.

### Afficher une question seulement quand elle est utile

À partir de la deuxième question, chacune a **🔀 Show only when another field has a specific value**.

1. Cochez-la.
2. Dans **— pick a field —**, choisissez la question précédente dont elle dépend.
3. Tapez la réponse qui doit la faire apparaître, ou laissez **equals (leave blank = any non-empty)** vide pour l’afficher dès que la question précédente a une réponse.

Par exemple, demandez « Pour quelle raison? » seulement quand « Votre enfant sera-t-il présent? » a la réponse « Non ».

## Publier un formulaire et prévenir les familles

1. Cliquez sur la ligne du formulaire (ou **⋮ > Modifier le formulaire**).
2. Changez le **Statut** pour **Published (live for audience)**.
3. Appuyez sur **Enregistrer les modifications**. La ligne affiche maintenant **LIVE**, et les familles visées voient le formulaire dans leur portail.
4. Pour leur envoyer le lien par courriel, appuyez sur **⋮ > Envoyer aux parents par courriel** et confirmez. Un message vert indique à combien de parents il est parti.

> ⚠️ Seul un formulaire publié peut être envoyé par courriel. Pour un brouillon, le message dit : « Publish this form first — only published forms can be emailed to parents. »

Appuyez sur **⋮ > Aperçu** en tout temps, même pour un brouillon, pour voir le formulaire
exactement comme les familles, avec votre logo et vos couleurs quand la marque blanche est
activée.

## Lire les réponses

1. Appuyez sur la pastille des réponses sur la ligne du formulaire (par exemple **3 responses**), ou sur **⋮ > View responses**.
2. Chaque réponse indique qui l’a envoyée et quand, puis chaque réponse à côté de sa question. Les signatures s’affichent en image; un paiement s’affiche comme un montant **authorised**.
3. Appuyez sur **⤓ Download CSV** pour enregistrer toutes les réponses dans un chiffrier, une colonne par question.

Utilisez la boîte **🔍 Search forms…** au-dessus de la liste pour trouver un formulaire par
son titre. Les longues listes sont découpées en pages de huit.

## Retirer ou supprimer un formulaire

- Pour cesser de recevoir des réponses en gardant le formulaire : modifiez-le, mettez le **Statut** à **Archived (hidden, kept for records)** et appuyez sur **Enregistrer les modifications**.
- Pour le retirer : **⋮ > Supprimer le formulaire**, puis confirmez. Les réponses déjà reçues sont conservées.

## Qui peut faire quoi

| Rôle | Ce qu’il peut faire |
|---|---|
| Administrateur de l’agence | Bâtir, publier, envoyer par courriel, prévisualiser et supprimer des formulaires; lire et télécharger toutes les réponses. |
| Auditeur | A une entrée **Formulaires** en lecture seule dans son menu pour les mêmes formulaires. Voir [Formulaires personnalisés pour les auditeurs](forms-auditor-view). |
| Parent | Remplit, dans son portail, les formulaires publiés destinés à sa famille. |
| Direction de service de garde, éducatrice ou éducateur | Aucun accès au créateur. |

## FAQ et quand quelque chose ne va pas

??? Une famille ne voit pas mon formulaire. | Vérifiez qu’il affiche LIVE et que les destinataires correspondent : une famille en liste d’attente ne voit pas un formulaire destiné aux familles actuellement inscrites.
??? « Field ... needs at least 2 options. » | Une question Liste déroulante ou Choix multiple a moins de deux options. Ajoutez une ligne sous Options (one per line).
??? « Title is required. » | Tapez un titre de formulaire avant d’appuyer sur Créer le formulaire.
??? Puis-je modifier un formulaire après que des gens y ont répondu? | Oui. Les réponses déjà données sont gardées avec les libellés qu’elles avaient. Évitez de retirer des questions auxquelles on a répondu si vous en avez encore besoin dans le CSV.
??? Où voir un paiement? | Le montant est inscrit au compte de la famille quand elle soumet. Vérifiez-le dans la facturation de la famille.
??? La publication envoie-t-elle un courriel? | Non. Publier rend le formulaire visible dans le portail. Seul ⋮ > Envoyer aux parents par courriel envoie un courriel.

## Écrans liés

[[open: #forms-manager | Gestionnaire de formulaires]] [[open: #edocuments | Documents électroniques]]

- [Gestionnaire de formulaires et trousses de formulaires](forms-manager)
- [Documents électroniques — les PDF que chaque famille signe](edocuments)
