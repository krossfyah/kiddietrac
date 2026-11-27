---
title: Modèles de courriel — changer le texte
---
# Modèles de courriel — changer le texte

**Paramètres → Modèles d’e-mail** liste chaque courriel que KiddieTrac envoie pour votre agence, regroupé selon le destinataire (parents, facturation, personnel, comptes, alertes aux administrateurs). Vous y voyez exactement l'allure de chaque courriel et pouvez en réécrire le texte à votre façon. Votre logo, vos couleurs, le pied de page et les détails que chaque courriel remplit (noms, dates, montants) restent automatiques : vous changez seulement les mots.

[[open: #email-templates | Ouvrir les modèles de courriel]]

## Trouver un courriel

1. Ouvrez **Modèles d’e-mail**. La ligne du haut indique le nombre de courriels, combien peuvent être reformulés et combien vous avez déjà personnalisés (**customised**).
2. Tapez dans **Find an email…** pour filtrer la liste par nom, moment d'envoi, destinataire ou objet.
3. Lisez chaque ligne : le nom du courriel, quand il est envoyé, et **To** (qui le reçoit).
4. Regardez les étiquettes : **● customised** (ce n'est plus le texte standard), **Preview only** (ne peut pas être reformulé), **Edited per event** (rédigé pour chaque événement dans Event RSVPs) et **also push / text** (la modification touche seulement le courriel; la notification push et le texto gardent leur texte standard).

## Prévisualiser un courriel

1. Appuyez sur **⋮** sur la ligne, puis sur **👁 Aperçu**.
2. Le courriel est bâti par le code qui l'envoie réellement, avec des noms et des montants fictifs. Rien n'est envoyé.
3. Fermez l'aperçu avec **×** ou **Échap**.

## Reformuler un courriel

1. Appuyez sur **⋮** sur la ligne, puis sur **✏️ Modifier**. L'éditeur s'ouvre dans une fenêtre.
2. Changez le texte dans chaque case. Laissez une case vide pour garder le texte automatique.
3. Pour ajouter un détail propre à chaque personne, placez le curseur dans une case et cliquez sur une pastille sous **Merge tags — click to add to the field you are in**, par exemple `{{agency_name}}`. Gardez les doubles accolades.
4. Utilisez la barre d'outils pour le gras, l'italique, le soulignement, les liens, les images, la police et la taille quand la case l'offre. Dans les cases simples, écrivez `**gras**` avec deux astérisques; un retour à la ligne commence une nouvelle ligne.
5. Appuyez sur **👁 Preview** pour voir le résultat avec vos changements.
6. Appuyez sur **📤 Send test…**, tapez votre adresse et appuyez sur **Envoyer un test**. Une copie marquée [Test] arrive avec des détails fictifs. Laissez **use my unsaved changes** coché pour tester avant d'enregistrer.
7. Appuyez sur **Save**. **Saved.** s'affiche et la ligne indique **● customised**.

> 💡 **↺ Reset to default**, à côté d'une case, remet le texte original de KiddieTrac dans cette case.

> ⚠️ Fermer l'éditeur avec des changements non enregistrés demande **Discard your changes?** Choisissez **Continuer la modification** pour revenir, ou **Abandonner les modifications** pour les perdre.

[[show: #email-templates @ Find an email… | Me montrer la case de recherche]]

## Accueil du personnel — ce qui est automatique

Vous rédigez le titre, le mot de bienvenue, les premiers pas et la formule de fin. KiddieTrac ajoute le reste pour chaque personne :

- vos **valeurs de soins**, tirées du texte **Our care** du courriel d'accueil du fournisseur — modifiez-les là et les deux courriels changent;
- un court tour des fonctions du portail qu'elle utilisera;
- un lien vers **Help & guides**;
- à qui s'adresser — le nom et les coordonnées de son directeur.

## Qui peut faire quoi

| Rôle | Accès |
|---|---|
| Administrateur d'agence | Prévisualiser et reformuler chaque courriel modifiable, pour son agence |
| Administrateur de plateforme | La même chose, plus les courriels marqués **KiddieTrac-wide**, dont le texte sert à toutes les agences |
| Directeur de centre, personnel, parents | Aucun accès |

## Questions fréquentes et problèmes

??? J'ai enregistré une modification, mais un courriel affiche encore l'ancien texte. | Les courriels déjà envoyés gardent le texte avec lequel ils sont partis. Le prochain courriel utilise votre nouveau texte.
??? Il n'y a pas de ✏️ Modifier pour un courriel. | Il est marqué **Preview only** (son texte est fixe), ou **Edited per event** : appuyez plutôt sur **📅 Edit in Event RSVPs**.
??? Une balise affiche « Unknown tag — it will be sent exactly as typed ». | La balise est mal orthographiée. Cliquez sur la pastille dans la liste au lieu de la taper.
??? Chaque centre peut-il avoir son propre texte? | Non. Les modèles sont propres à l'agence.
??? Où changer le logo et les couleurs? | Dans l'écran **🎨 Branding**. Les modèles les utilisent automatiquement.
??? Puis-je empêcher l'envoi d'un courriel? | Pas ici. Les interrupteurs des courriels automatiques sont dans **Paramètres → Email settings** et **Paramètres → Agency notifications**.

## Écrans connexes

[[open: #email-settings | Paramètres de courriel]] [[open: #notification-settings | Notifications de l'agence]]

Voir aussi : [Paramètres de courriel](email-settings), [Notifications de l'agence](agency-notifications), [Inviter des parents et du personnel](inviting-parents-and-staff), [Image de marque personnalisée](white-label-branding).
