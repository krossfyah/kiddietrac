---
title: Inviter des parents et du personnel
category: Enrolment
order: 2
---

# Inviter des parents et du personnel

Lorsque vous invitez quelqu’un dans KiddieTrac, cette personne reçoit un courriel d’invitation. La plupart des invitations comportent un bouton **Définir mon mot de passe**, valide 7 jours; quelques écrans plus anciens envoient plutôt un mot de passe temporaire, qu’elle doit changer à sa première connexion. Dans les deux cas, personne d’autre ne voit jamais son mot de passe.

## Inviter un parent

Un parent doit être lié à une **famille**. Son enfant doit déjà être inscrit dans cette famille.

1. Cliquez sur **💌 Inviter un parent** dans AJOUT RAPIDE
2. Choisissez la famille dans la liste déroulante
3. Saisissez le prénom, le nom et le courriel du parent
4. Indiquez son lien (mère / père / tuteur ou tutrice / etc.)
5. Choisissez ses autorisations :
   - **Contact principal** — principal point de contact de la famille. Habituellement un seul par famille.
   - **Peut venir chercher l’enfant** — autorisé à signer le départ de l’enfant. La plupart des membres de la famille ont oui; les contacts d’urgence peuvent avoir non.
   - **Reçoit les courriels de facturation** — reçoit le courriel de la facture mensuelle. Souvent, un seul parent par famille coche cette case.
6. Laissez **Envoyer le courriel de bienvenue** coché
7. Cliquez sur **Envoyer l’invitation**

Le parent reçoit un courriel comme celui-ci :

> Bonjour [Nom], [Centre] vous a invité à utiliser KiddieTrac.
>
> Courriel : [son courriel]
> Mot de passe temporaire : aBc123dEf

Il suit le lien, choisit un mot de passe et est connecté directement. Si son courriel indique plutôt un mot de passe temporaire, il lui sera demandé de le changer à sa première connexion.

## Inviter un membre du personnel (éducateur ou directeur)

1. Cliquez sur **👨‍🏫 Inviter du personnel** dans AJOUT RAPIDE
2. Saisissez son prénom, son nom et son courriel
3. Choisissez le rôle :
   - **Éducateur** — utilise la vue tablette, peut consigner des activités, prendre des photos et écrire aux parents
   - **Directeur** — accès administrateur complet, y compris la facturation et la conformité
4. Cliquez sur **Envoyer l’invitation**

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

## Trois listes, pas une

**Administration → Gestion des utilisateurs** les sépare, avec un compte sur chacune :

- **👥 Utilisateurs actifs** — tous ceux qui se connectent.
- **✉️ Invité** — invités qui n’ont jamais réclamé leur compte. Si quelqu’un dit n’avoir jamais reçu de courriel, cet onglet vous dit si un compte l’attend.
- **🗄️ Retirés / désactivés** — comptes fermés. Ils sont refusés à la porte de connexion; réinitialiser le mot de passe de l’un d’eux produit donc un mot de passe que personne ne peut utiliser. Regardez ici avant de dépanner un verrouillage.

## Réinviter quelqu’un

**Renvoyer le message de bienvenue** ne fait plus la même chose pour tout le monde, et la différence compte.

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
