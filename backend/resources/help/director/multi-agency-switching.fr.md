---
title: Mon agence et changer d’agence
---
# Mon agence et changer d’agence

**Mon agence** montre l’agence dans laquelle vous êtes connecté : son adresse Web, son forfait, son nombre de centres et d’utilisateurs, et son statut de facturation. De là, l’administrateur d’agence gère les coordonnées de l’agence, son adresse, l’expéditeur des courriels et les interrupteurs d’automatisation. Si vous vous occupez de plus d’une agence, le sélecteur d’agence du menu latéral vous fait passer de l’une à l’autre sans vous déconnecter. Ouvrez-le depuis le menu latéral : **Aperçu > Mon agence**.

[[open: #agencies | Ouvrir Mon agence]]

## Lire la carte de l’agence

L’écran affiche une carte pour votre agence, avec :

- le nom de l’agence et une étiquette de couleur pour le statut de facturation (par exemple **ACTIVE** ou **TRIAL**);
- 🌐 l’adresse Web où les gens se connectent, quand l’agence a son propre sous-domaine ou domaine;
- 📦 le forfait, 🏫 le nombre de centres et 👥 le nombre d’utilisateurs;
- un bouton **Gérer** à droite.

## Modifier les coordonnées de l’agence

1. Appuyez sur **Gérer** sur la carte de l’agence. La fenêtre **Manage: (nom de l’agence)** s’ouvre sur l’onglet **Détails**.
2. Vérifiez ou changez **Nom**, **Raison sociale** (seulement si elle diffère du nom commercial), **Site web** et **Fuseau horaire**. Toutes les heures que l’agence enregistre ou affiche (arrivées, horaires, rapports) utilisent ce fuseau horaire.
3. Ouvrez l’onglet **Adresse** et remplissez **Adresse, ligne 1**, **Ville**, **Province / State**, **Code postal** et **Pays**. Cette adresse est imprimée sur les factures et les reçus et détermine le calendrier des jours fériés.
4. Ouvrez l’onglet **Courriel**. **Courriel de contact** et **Téléphone de contact** servent à KiddieTrac pour écrire à l’agence elle-même. **Nom d’expéditeur** et **Adresse d’expéditeur** déterminent comment vos courriels apparaissent aux familles.
5. Appuyez sur **Enregistrer**. Un **✓ Enregistré** vert s’affiche et la fenêtre se ferme.

> ⚠️ Changer le **Fuseau horaire** change l’affichage de toutes les heures pour toute l’agence. Ne le changez que s’il est vraiment erroné, et demandez d’abord au soutien KiddieTrac en cas de doute.

## Activer ou désactiver l’envoi et l’automatisation

1. Appuyez sur **Gérer** et ouvrez l’onglet **Forfait et automatisation**.
2. **Envoyer les notifications et les courriels** est l’interrupteur principal. Désactivé, rien n’est envoyé au personnel ni aux familles : aucun courriel, aucun texto, aucune notification poussée, aucune alerte dans l’application. Tout est quand même enregistré.
3. **Envoyer les résumés quotidiens et hebdomadaires** contrôle les courriels de résumé aux administrateurs, aux directeurs, aux parents et aux éducateurs.
4. **Remplir automatiquement les horaires du personnel** crée chaque nuit à 04 h 30 l’horaire de tous les centres 28 jours à l’avance, à partir des heures d’ouverture de chaque centre. Les jours de fermeture sont sautés, un jour déjà planifié n’est jamais écrasé et un quart supprimé reste supprimé.
5. Appuyez sur **View my licence →** pour voir votre forfait, vos modules complémentaires et votre date de renouvellement.
6. Appuyez sur **Enregistrer**.

## Passer à une autre agence

1. Regardez en bas du menu latéral, juste au-dessus de votre nom. Quand vous avez accès à plus d’une agence, une pastille affiche le nom de l’agence active.
2. Cliquez dessus. Toutes les agences que vous pouvez gérer sont listées, avec un crochet sur l’agence active.
3. Choisissez une autre agence. Le portail affiche « ⏳ Switching… » et se recharge; chaque écran ne montre plus que cette agence.

Rien n’est copié d’une agence à l’autre : centres, familles, personnel, factures, rôles, image de marque et paramètres restent séparés. Votre connexion, votre authentification à deux facteurs et vos conversations vous appartiennent et vous suivent d’une agence à l’autre.

## Qui peut faire quoi

- **Administrateur d’agence** – voit **Mon agence**, modifie les coordonnées, l’adresse, les courriels et l’automatisation, et peut passer d’une agence à l’autre parmi celles où il a été invité comme administrateur d’agence.
- **Administrateur de la plateforme** – n’a pas **Mon agence** dans le menu; il utilise plutôt **Toutes les agences**, qui offre aussi **+ Nouvelle agence**, **Suspendre** / **Reprendre** et l’épinglage du statut de facturation.
- **Directeur de centre** – ne voit pas cet écran.

## FAQ et dépannage

??? Comment obtenir l’accès à une deuxième agence? | Un administrateur de cette agence vous invite comme administrateur d’agence depuis son écran de gestion des utilisateurs. L’agence apparaît dans votre sélecteur au prochain chargement de la page.
??? Je ne vois pas le sélecteur d’agence. | Il n’apparaît que si vous avez plus d’une agence.
??? Le sélecteur affiche « Could not switch agency. You may not have access. » | Votre accès à cette agence a été retiré ou n’a pas encore été configuré. Adressez-vous à l’administrateur de cette agence.
??? J’ai appuyé sur Supprimer et j’ai reçu « Only platform admins can delete agencies. » | Seuls les administrateurs de la plateforme KiddieTrac peuvent supprimer une agence. Communiquez avec le soutien KiddieTrac si une agence doit fermer.
??? Où changer le forfait ou les modules complémentaires? | Pas ici. Appuyez sur **View my licence →** dans l’onglet **Forfait et automatisation**.
??? Où régler le logo et les couleurs? | Dans **Image de marque**; les serveurs de courriel et les modèles sont dans **Paramètres de messagerie**.

## Voir aussi

[[open: #dashboard | Aperçu de l’agence]] [[open: #admin-centres | Centres]] [[open: #email-settings | Paramètres de messagerie]]

- [[plan-and-add-ons]]
- [[white-label-branding]]
- [[email-and-digests]]
