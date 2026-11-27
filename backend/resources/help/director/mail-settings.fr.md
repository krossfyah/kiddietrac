---
title: Paramètres d'envoi — comment KiddieTrac envoie les courriels
---
# Paramètres d'envoi — comment KiddieTrac envoie les courriels

**Plateforme → Mail settings** est l'endroit où un administrateur de la plateforme KiddieTrac choisit comment toute la plateforme envoie ses courriels, et configure les applications de connexion Microsoft et Google qu'utilisent les agences pour brancher leurs boîtes. Les agences ne voient jamais cet écran : leur nom et leur adresse d'expéditeur se trouvent dans **Paramètres → Email settings**.

[[open: #mail-settings | Ouvrir Mail settings]]

## Choisir la méthode d'envoi et l'expéditeur

1. Ouvrez **Mail settings**. La première carte concerne les courriels sortants de la plateforme.
2. Sous **Méthode d’envoi**, choisissez **Microsoft Graph (recommended — with sendmail fallback)**. **Local sendmail only** sert en dépannage : le courriel part, mais tombe plus souvent dans les indésirables.
3. Entrez l'**Adresse d’expéditeur** et le **Nom d’expéditeur** utilisés quand une agence n'a pas défini les siens.
4. Sous **🔐 Microsoft Graph credentials**, remplissez **Directory (tenant) ID**, **Application (client) ID** et **Boîte aux lettres d’expédition Graph**.
5. Collez le **Secret client** (laissez vide pour garder celui qui est enregistré) et indiquez dans **Le secret expire le** la date affichée dans Azure, pour être averti avant qu'il n'expire.
6. Appuyez sur **Enregistrer les paramètres**. **✓ Saved.** s'affiche à côté du bouton.

> ⚠️ L'hébergeur bloque le SMTP ordinaire; les courriels partent donc par Microsoft Graph, en HTTPS. Quand le secret expire, l'envoi se rabat sur sendmail et la livraison se dégrade. Renouvelez le secret dans Azure avant la date inscrite.

## Tester la livraison

1. Allez à **📤 Send a test**, au bas de l'écran.
2. Tapez une adresse que vous pouvez consulter et appuyez sur **Envoyer un test →**.
3. Lisez la ligne de résultat : **✓ Sent via** nomme le service utilisé, et **Graph credentials** indique si la connexion Graph a fonctionné. La ligne **Last test** garde le résultat pour plus tard.

## Connexion Microsoft 365 pour les boîtes de courriel

La carte **🟦 Microsoft 365 sign-in (mailboxes and "Sign in with Microsoft")** est l'application par laquelle les gens se connectent quand ils branchent une boîte dans **Email** ou **Sales email**, et pour **Se connecter avec Microsoft** sur la page de connexion.

1. Dans Microsoft Entra, ouvrez **App registrations** et votre application. Elle a besoin des autorisations déléguées Mail.ReadWrite, Mail.ReadWrite.Shared, offline_access et User.Read.
2. Copiez l'**Application (client) ID** à partir de la page Overview de l'application.
3. Créez un secret sous Certificates & secrets et collez sa valeur (Value) dans **Client secret value**.
4. Appuyez sur **Copier** à côté de **Redirect URI** et ajoutez-la sous Authentication → Web dans l'application.
5. Appuyez sur **Enregistrer les paramètres Microsoft**. KiddieTrac enregistre et vérifie auprès de Microsoft; le résultat s'affiche à côté du bouton. **Vérifier auprès de Microsoft** refait la vérification plus tard.

## Connexion Gmail pour les expéditeurs RSVP

La carte **📧 Gmail sign-in for RSVP senders** contient le client Google qui permet à une agence de brancher un compte Gmail sous **Event RSVPs → Sender accounts**.

1. Dans Google Cloud Console, ouvrez Google Auth Platform → Clients et créez un client Web.
2. Appuyez sur **Copier** à côté de **Authorized redirect URI (paste this into the Google client)** et collez-la dans le client.
3. Entrez ici l'**ID client** et le **Secret client**.
4. Appuyez sur **Enregistrer les paramètres Gmail**, puis sur **Vérifier avec Google** chaque fois que vous voulez confirmer que tout fonctionne encore.

## Qui peut faire quoi

Seuls les administrateurs de la plateforme ouvrent cet écran. Les administrateurs d'agence définissent leur nom et leur adresse d'expéditeur dans **Paramètres → Email settings**; les directeurs de centre et les autres rôles n'ont aucun paramètre d'envoi à modifier.

## Questions fréquentes et problèmes

??? La carte Microsoft indique « Using the server file (.env) ». | L'application est encore lue dans le fichier du serveur, qu'on ne peut pas vérifier d'ici. Entrez l'ID client et le secret, puis appuyez sur **Enregistrer les paramètres Microsoft** pour la gérer à partir de cet écran.
??? Que se passe-t-il si j'appuie sur Supprimer? | Une confirmation est demandée. Pour Microsoft, la connexion revient au fichier du serveur et les boîtes déjà branchées continuent de fonctionner jusqu'à ce que leur connexion doive être renouvelée. Pour Gmail, les agences ne peuvent plus brancher d'expéditeur Gmail, et ceux déjà branchés cessent d'envoyer.
??? Le test indique « ✗ Send failed ». | Lisez la raison en rouge qui suit. Le plus souvent, le secret client est expiré ou la boîte d'expédition est erronée. Corrigez, appuyez sur **Enregistrer les paramètres** et refaites un test.
??? « Enter an address to test. » | Tapez l'adresse dans la case avant d'appuyer sur **Envoyer un test →**.
??? Les agences voient-elles ces secrets? | Non. Les secrets sont en écriture seule : une fois enregistrés, l'écran indique seulement qu'un secret est conservé.

## Écrans connexes

[[open: #email-settings | Paramètres de courriel]] [[open: #email | Client de courriel]]

Voir aussi : [Paramètres de courriel](email-settings), [Client de courriel](email-client).
