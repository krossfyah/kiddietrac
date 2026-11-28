---
title: Toutes les agences : ajouter et gérer des agences
---
# Toutes les agences : ajouter et gérer des agences

**Toutes les agences** est la liste, pour l'équipe de la plateforme, de chaque agence cliente sur KiddieTrac, avec son statut, son forfait et sa taille. D'ici, vous ajoutez une nouvelle agence avec un assistant en quatre étapes, ouvrez n'importe quelle agence pour y travailler, modifiez ses renseignements, renvoyez l'invitation de son administrateur, la suspendez ou la réactivez, et bloquez la connexion pour toute l'agence.

[[open: #platform-agencies | Ouvrir Toutes les agences]]

## Lire la liste

1. Ouvrez **Platform › Toutes les agences**.
2. Lisez le tableau : **Name**, **Status** (par exemple essai, active ou suspendue), **Plan** (avec son montant mensuel), **Centres**, **Families**, **Children**, **Created** et **Actions**.
3. Appuyez sur **Open** sur une ligne pour passer à cette agence. Vous arrivez sur son écran d'accueil et son menu reflète le forfait et les modules de cette agence.

## Ajouter une nouvelle agence

1. Appuyez sur **+ Créer une agence** (en haut à droite). L'assistant **🌐 Create new agency** s'ouvre à « Step 1 of 4 ».
2. **Plan & logo** : choisissez le **Plan** (**Starter — $49/mo**, **Growth — $149/mo (white-label included)** ou **Enterprise — $349/mo (white-label included)**). Au besoin, appuyez sur **Téléverser un logo** (2 Mo au maximum) et réglez la **Primary colour**. Appuyez sur **Next →**.
3. **Agency details** : remplissez **Agency name**, **Address line 1**, **Address line 2** (facultatif), **City**, **Province**, **Postal code**, **Country (residence — sets currency & compliance)**, **Default language**, **Contact email** et **Contact phone**. Appuyez sur **Next →**.
4. **Admin** : remplissez **First name**, **Last name**, **Email** et **Phone** du premier administrateur de l'agence. Appuyez sur **Next →**.
5. **Réviser** : vérifiez tout (utilisez **← Back** pour corriger une étape), puis appuyez sur **✓ Create agency**.
6. Lisez la confirmation : « … is ready. Provisioned on a 30-day trial with a default centre. » Elle montre aussi le lien d'invitation de l'administrateur au cas où le courriel tarderait. Appuyez sur **Terminé**.

> ℹ️ Le centre par défaut porte le nom de l'agence et utilise son adresse. L'administrateur de l'agence le renomme ou ajoute d'autres centres sous **Centres**.

## Modifier une agence

1. Appuyez sur **✏️** (**Modifier l’agence**) sur la ligne.
2. Utilisez les onglets : **Détails** (nom, contact, propriétaire), **Address** (raison sociale, site Web, adresse, pays, langue par défaut), **Courriel** (le serveur de courriel sortant propre à l'agence, avec **✉️ Send test**), **Plan** et **Marque blanche** (logo, couleur principale, courriel d'assistance, **Enable**).
3. Dans **Plan**, lisez la licence et les modules de l'agence. Appuyez sur **Manage in Licences →** pour changer le forfait, les modules ou la durée; on ne les change pas ici.
4. Appuyez sur **Save changes**.

## Renvoyer l'invitation de l'administrateur

1. Appuyez sur **✉️** (**Renvoyer l'invitation**) sur la ligne.
2. La boîte **✉ Invite sent** indique à qui le courriel a été envoyé et montre le lien de création du mot de passe.
3. Appuyez sur **Copier le lien** si vous voulez l'envoyer autrement, puis sur **Terminé**.

## Suspendre ou bloquer une agence

1. Appuyez sur **⏸️** (**Suspend**) et confirmez « Users will lose dashboard access immediately. » Appuyez sur **▶️** (**Resume**) pour redonner l'accès.
2. Appuyez sur **🚷** (**Sign-in lock**), ajoutez une note et appuyez sur **Lock sign-in** pour empêcher tout le personnel et tous les parents de cette agence de se connecter. **Unlock sign-in**, dans la même boîte, lève le blocage.

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Administrateur de la plateforme | Tout ce qui se trouve sur cet écran. |
| Revendeur | Ajoute des agences sous sa propre marque dans **Intégrer une agence**, pas ici. |
| Administrateur d'agence | Ne voit pas cet écran; voit seulement sa propre agence sous **My agency**. |

## FAQ et quand quelque chose ne va pas

??? L'assistant indique « Required: … ». | Un champ obligatoire de cette étape est vide. Remplissez les champs nommés et appuyez de nouveau sur **Next →**.
??? « Could not create the agency » avec un message. | Lisez le message (par exemple le courriel de l'administrateur est déjà utilisé), appuyez sur **← Back to review**, corrigez et appuyez de nouveau sur **✓ Create agency**.
??? L'administrateur n'a jamais reçu l'invitation. | Appuyez sur **✉️** (**Renvoyer l'invitation**) et envoyez-lui le lien vous-même avec **Copier le lien**. Vérifiez **Audit log › 📧 Email log** pour confirmer que le courriel est parti.
??? « Upload failed » ou « Max 2 MB » sous le logo. | Utilisez un PNG, JPG ou WebP de moins de 2 Mo. Vous pouvez aussi ajouter le logo plus tard dans l'onglet **Marque blanche**.
??? Pourquoi ne puis-je pas changer le forfait en modifiant l'agence? | Le forfait et les modules viennent de la licence de l'agence. Utilisez **Manage in Licences →**.
??? Que reçoivent les familles et le personnel quand une agence est suspendue? | Aucun courriel n'est envoyé; ils ne peuvent simplement plus accéder au tableau de bord jusqu'à la réactivation.

## Écrans liés

[[open: #licences | Licences]] [[open: #resellers | Revendeurs]] [[open: #audit-logs | Journal d'audit]]

- [[managing-resellers]]
- [[white-label-branding]]
- [[plan-and-add-ons]]
