---
title: Inviter des parents et du personnel
category: Enrolment
order: 2
---

# Inviter des parents et du personnel

Lorsque vous invitez quelqu’un dans KiddieTrac, cette personne reçoit un courriel d’invitation. La plupart des invitations comportent un bouton **Définir mon mot de passe**, valide 7 jours; quelques écrans plus anciens envoient plutôt un mot de passe temporaire, qu’elle doit changer à sa première connexion. Dans les deux cas, personne d’autre ne voit jamais son mot de passe.

## Inviter un parent

Un parent doit être lié à une **famille**. Son enfant doit déjà être inscrit dans cette famille.

1. Sur un téléphone, touchez **⚡ Ajout rapide** → **💌 Inviter un parent**
2. Sous **Sélectionner une famille**, choisissez la famille et appuyez sur **Continuer**
3. Saisissez le prénom, le nom et le courriel du parent
4. Indiquez son lien (mère / père / tuteur ou tutrice / etc.)
5. Choisissez ses autorisations :
   - **Contact principal** — principal point de contact de la famille. Habituellement un seul par famille.
   - **Peut venir chercher l’enfant** — autorisé à signer le départ de l’enfant. La plupart des membres de la famille ont oui; les contacts d’urgence peuvent avoir non.
   - **Reçoit les courriels de facturation** — reçoit le courriel de la facture mensuelle. Souvent, un seul parent par famille coche cette case.
6. Laissez **Envoyer un courriel de bienvenue avec les détails de connexion** coché
7. Cliquez sur **Envoyer l’invitation**

Le parent reçoit un courriel comme celui-ci :

> Bonjour [Nom], [Centre] vous a invité à utiliser KiddieTrac.
>
> Courriel : [son courriel]
> Mot de passe temporaire : aBc123dEf

Il suit le lien, choisit un mot de passe et est connecté directement. Si son courriel indique plutôt un mot de passe temporaire, il lui sera demandé de le changer à sa première connexion.

## Inviter un membre du personnel

1. Ouvrez **Administration › Gestion des utilisateurs** et appuyez sur **+ Inviter un utilisateur**.
2. Saisissez son **First name**, son **Last name** et son **Email** (un **Phone** est facultatif).
3. Choisissez son **Role** : **Educator**, **Centre director**, **Home visitor**, **Agency admin** ou **Auditor (read-only)**. Un rôle rattaché à un seul centre demande aussi un **Centre**.
4. Appuyez sur **Créer l’utilisateur**. La personne reçoit un courriel d’invitation avec un lien sécurisé pour définir son mot de passe.

Sur un téléphone, vous pouvez aussi toucher **⚡ Ajout rapide** → **👨‍🏫 Inviter du personnel**, saisir le nom et le courriel, choisir **Educator (tablet view)** ou **Director (full admin)**, garder **Envoyer un courriel de bienvenue avec les détails de connexion** coché et appuyer sur **Envoyer l’invitation**.

> 💡 Choisir **Parent / guardian** comme rôle ne crée pas d’accès à lui seul : le bouton devient **Continue to Add Family →**, parce qu’un parent est toujours ajouté avec sa famille et son enfant. Voir [[adding-a-family]].

## Codes d’invitation pour parents

Un code permet aux parents d’inscrire eux-mêmes leur enfant : vous partagez un lien, ils remplissent le formulaire, et chaque nouvel enfant arrive dans votre liste d’attente pour que vous l’examiniez.

1. Ouvrez **Inscriptions › Codes d’invitation** et appuyez sur **+ Nouveau code**.
2. Dans **Nouveau code d’invitation**, donnez-lui une **Étiquette** si vous voulez (par exemple le local et l’année), indiquez **How many parents can use this code?** et une date d’expiration facultative.
3. Appuyez sur **Générer le code**. La boîte **Code généré** affiche le lien et le code lui-même.
4. Appuyez sur **Copier le lien** et envoyez-le au parent (courriel, texto ou note imprimée). Le bouton affiche **Copied!** un instant.
5. Appuyez sur **Terminé**.

La liste montre le **Statut** de chaque code — **ACTIVE**, **LIMITE ATTEINTE** (toutes les utilisations prises), expiré ou révoqué — avec **Utilisations**, **Expire** et **Créé par**. **Révoquer** arrête un code tout de suite : un parent en train de remplir le formulaire apprend qu’il n’est plus valide. **Supprimer** retire un code que personne n’a encore utilisé.

[[show: #invitation-codes @ + New code | Me montrer où créer un code]]

## Quand un nouveau membre du personnel termine son intégration

Dès qu’un **éducateur, directeur de centre ou visiteur à domicile** termine son intégration, il reçoit un **courriel de bienvenue du personnel**, une seule fois. Les directeurs de son centre et vos administrateurs d’agence sont en copie, afin que la direction sache que la nouvelle personne est prête.

Il contient :

- un accueil chaleureux mentionnant son rôle et son centre;
- **la façon dont votre agence prend soin des enfants** — le texte « Our care » de votre modèle **Bienvenue du prestataire**, de sorte que vous rédigez vos valeurs une seule fois et que parents et personnel lisent les mêmes mots;
- un bref survol des fonctions du portail qu’il utilisera chaque jour;
- un lien vers **Aide et guides**, et qui consulter (le nom et les coordonnées de son directeur).

Vous pouvez modifier le titre, l’accueil, les premières étapes et la formule de politesse sous **Paramètres → Modèles d’e-mail → Bienvenue du personnel (éducateurs et prestataires)**, et vous envoyer un essai à partir de là.

[[show: #email-templates @ Staff welcome | Afficher le modèle de bienvenue du personnel]]

![Modification du courriel de bienvenue du personnel](https://api.kiddietrac.com/help-img/email-template-staff-welcome.png)

??? Le message de bienvenue du personnel est-il envoyé aux parents? | Non. Les parents reçoivent le courriel distinct de bienvenue du prestataire, qui leur présente leur prestataire. Le message de bienvenue du personnel va seulement au nouveau membre du personnel, avec les directeurs et administrateurs en copie.
??? Puis-je le renvoyer? | Il est envoyé une seule fois, automatiquement. Envoyez un essai à partir de l’éditeur de modèle pour l’aperçu.

## Donner un rôle personnalisé à quelqu’un

Si votre agence a créé ses propres rôles dans [[custom-roles-and-permissions]], donnez-en un à un membre du personnel à partir de sa fiche :

1. Ouvrez **Gestion des utilisateurs** et ouvrez la personne.
2. À côté de **Ajouter / définir un rôle**, choisissez le rôle personnalisé (marqué **★**, sous **Vos rôles personnalisés**) et, pour un rôle de centre, le **Centre**.
3. Appuyez sur **Appliquer**. Une pastille **★** avec le nom du rôle apparaît à côté de son rôle intégré.
4. Pour le retirer, choisissez de nouveau le rôle intégré et appuyez sur **Appliquer**.

> ℹ️ Un rôle personnalisé limite seulement son rôle de base : la personne voit toujours les mêmes centres et les mêmes enfants, sans les écrans que le rôle désactive.

## Trois listes, pas une

**Administration → Gestion des utilisateurs** les sépare, avec un compte sur chacune :

- **👥 Utilisateurs actifs** — tous ceux qui se connectent.
- **✉️ Invité** — invités qui n’ont jamais réclamé leur compte. Si quelqu’un dit n’avoir jamais reçu de courriel, cet onglet vous dit si un compte l’attend.
- **🗄️ Retirés / désactivés** — comptes fermés. Ils sont refusés à la porte de connexion; réinitialiser le mot de passe de l’un d’eux produit donc un mot de passe que personne ne peut utiliser. Regardez ici avant de dépanner un verrouillage.

## Réinviter quelqu’un

**✉️ Renvoyer l’invitation de bienvenue** ne fait plus la même chose pour tout le monde, et la différence compte.

- **Un compte jamais réclamé** reçoit un nouveau mot de passe temporaire. C’est exactement ce que veut dire « renvoyer la bienvenue », et rien n’est retiré.
- **Un compte déjà actif** reçoit un **lien de réinitialisation**, et rien n’est modifié. Son mot de passe actuel continue de fonctionner.

Auparavant, le bouton remplaçait le mot de passe et mettait fin à toutes les sessions de n’importe qui; c’est ainsi que l’utiliser pour aider une mère qui « n’arrive pas à entrer » est devenu la raison pour laquelle elle n’y arrivait pas. Voir [Réinitialiser le mot de passe d’un utilisateur](resetting-a-users-password).

## Que faire si le courriel n’arrive pas?

- Vérifiez le **dossier de pourriels** du destinataire
- Vérifiez que l’adresse courriel est bien orthographiée
- Demandez au destinataire de vérifier que les courriels de « kiddietrac.com » ne sont pas bloqués par son fournisseur de courriel

Si vous n’êtes pas certain qu’il est bien parti, vous pouvez le réinviter — le système détectera qu’il s’agit déjà d’un utilisateur invité et ne fera que mettre à jour le mot de passe.

## Inviter les deux parents d’une famille biparentale

Invitez-les séparément. Chacun aura sa propre connexion mais verra les mêmes enfants. Un SEUL devrait être désigné « Contact principal » afin d’éviter les courriels de facturation en double. Les deux peuvent généralement avoir « Peut venir chercher l’enfant » activé.

## Et si un parent en garde partagée utilise un autre courriel?

Ajoutez-le comme tuteur distinct de la même famille. Il verra l’enfant mais aura sa propre connexion.

## Aide-mémoire des autorisations

| Autorisation | Ce qu’elle permet |
|---|---|
| Contact principal | C’est le contact par défaut pour les avis destinés à tout le centre |
| Peut venir chercher l’enfant | Il peut signer l’arrivée et le départ de l’enfant; son visage et son nom figurent sur la tablette de l’éducateur |
| Reçoit la facturation | Il reçoit le courriel de la facture mensuelle |
| % de la facturation | Si la facturation est partagée entre deux parents, détermine la part que chacun doit |
