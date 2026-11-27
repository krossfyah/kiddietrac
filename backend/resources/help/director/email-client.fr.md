---
title: Client de courriel — lire et envoyer vos courriels dans KiddieTrac
---
# Client de courriel — lire et envoyer vos courriels dans KiddieTrac

**Opérations → Email Client** vous permet de lire votre boîte de courriel (Microsoft 365, Outlook.com, Gmail ou un autre fournisseur) et d'y répondre sans quitter le portail. L'écran ressemble à Outlook : les boîtes et les dossiers à gauche, la liste des messages au centre et le courriel ouvert à droite. Sur un téléphone, vous voyez un volet à la fois, avec **Back** pour revenir à la liste.

[[open: #email | Ouvrir le client de courriel]]

## Brancher votre boîte de courriel

1. Appuyez sur **＋ Add account** au bas du volet de gauche. La fenêtre **✉️ Add an email account** s'ouvre.
2. Tapez votre adresse courriel et appuyez sur **Next →**. KiddieTrac trouve qui gère la boîte. Vous pouvez aussi choisir votre fournisseur parmi les tuiles sous **or choose your provider**.
3. Pour Microsoft 365, Outlook ou Hotmail, appuyez sur **Se connecter avec Microsoft**. Pour Gmail ou Google Workspace, appuyez sur **Se connecter avec Google**. Vous vous connectez sur la page du fournisseur : KiddieTrac ne voit jamais votre mot de passe.
4. Acceptez les autorisations demandées. Vous revenez au client de courriel et la boîte apparaît à gauche avec ses dossiers et le nombre de messages non lus.

> 💡 Pour une boîte partagée (par exemple bureau@), connectez-vous avec **votre propre compte** si la boîte n'a pas de mot de passe à elle; connectez-vous avec la boîte seulement si elle a sa propre connexion.

> ℹ️ Les autres fournisseurs (Yahoo, serveurs IMAP) se branchent avec **Other mail provider**, mais ils sont en lecture seule ici : vous y répondez dans leur propre application. L'envoi fonctionne à partir des boîtes connectées avec Microsoft ou Google.

## Lire et classer vos courriels

1. Cliquez sur un dossier (par exemple **Inbox**) dans le volet de gauche.
2. Utilisez **Rechercher dans ce dossier** pour trouver un message, et **Tous** / **Non lu** pour réduire la liste. **Charger les messages plus anciens**, au bas, en affiche davantage.
3. Cliquez sur un message pour l'ouvrir dans le volet de lecture.
4. Utilisez la barre d'outils au-dessus : **Marquer comme non lu**, **Flag**, **Déplacer**, **Archiver** (ou **Pas du courrier indésirable** dans le dossier Junk) et **Supprimer**. Dans les éléments supprimés, le bouton devient **Delete permanently**.
5. Pour traiter plusieurs messages, cochez-les (ou **Select all**) et utilisez la barre qui apparaît au-dessus de la liste.

## Écrire, répondre et transférer

1. Appuyez sur **✏️ New mail**, ou ouvrez un message et appuyez sur **Répondre**, **Répondre à tous** ou **Transférer**.
2. Vérifiez **From** si vous avez plus d'une boîte. Une réponse part de la boîte qui a reçu le message.
3. Ajoutez les destinataires dans **To**. Appuyez sur **Cc** ou **Cci** pour afficher ces lignes.
4. Écrivez votre message. Votre signature est déjà en place et vous pouvez encore la modifier.
5. Appuyez sur **📎 Attach** pour joindre des fichiers, ou glissez-les sur le message (jusqu'à 10 fichiers et 25 Mo par courriel).
6. Appuyez sur **👁 Preview** pour voir exactement ce qui sera envoyé, puis sur **Envoyer** (ou **Ctrl+Entrée**).

> ⚠️ **Discard this message?** s'affiche si vous fermez un message commencé. Choisissez **Discard** seulement si vous n'en voulez plus.

## Signature, absence du bureau et alertes

1. Appuyez sur **⚙️ Manage accounts** au bas du volet de gauche.
2. Appuyez sur **Settings** à côté de la boîte.
3. Collez ou écrivez votre **Signature**. Un collage depuis Outlook ou Word garde votre mise en forme et votre logo.
4. Activez **Réponses automatiques**, choisissez qui reçoit une réponse et, au besoin, les heures de début et de fin.
5. Cochez **Alert me when new mail arrives in this inbox (bell, phone and sound)** pour recevoir une notification dans la cloche avec un léger carillon, et choisissez le **Sound for this inbox**.
6. Appuyez sur **Enregistrer les paramètres**.

## Classer un courriel au dossier d'une famille ou d'un membre du personnel

Quand votre agence a activé **Link emails to people** (**Paramètres → Email settings → Mailbox**), la boîte d'un administrateur d'agence affiche **🔗 Link to person** sur chaque message ouvert.

1. Ouvrez le courriel et appuyez sur **🔗 Link to person**.
2. Cherchez le parent, le membre du personnel ou le contact par nom ou par courriel, puis choisissez-le.
3. Le courriel paraît maintenant sous **✉️ Emails** dans le dossier de cette personne. Chaque lien ajouté ou retiré est inscrit au journal d'audit.

[[show: #email @ ＋ Add account | Me montrer Add account]]

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Administrateur d'agence | Brancher ses boîtes (celles de l'agence), envoyer, et classer les courriels aux dossiers quand c'est activé |
| Administrateur de plateforme | La même chose, pour les agences qu'il gère |
| Directeur de centre | Brancher **sa propre** boîte seulement. Elle reste personnelle : personne d'autre ne la voit et ses courriels ne sont pas classés aux dossiers |

Chaque boîte appartient à la personne qui l'a branchée. Personne d'autre dans l'agence ne peut la lire.

## Questions fréquentes et problèmes

??? Une bannière rouge dit que ma boîte doit se reconnecter. | Microsoft ou Google ne fait plus confiance à l'ancienne connexion (mot de passe changé ou accès retiré par un administrateur). Appuyez sur **🔌 Reconnect** et reconnectez-vous. Vos courriels et vos réglages sont conservés.
??? Je peux lire mes courriels mais Répondre est grisé ou l'envoi échoue. | La boîte est en lecture seule (autre fournisseur), ou elle a été branchée avant l'ajout de l'envoi. Appuyez sur **🔌 Reconnect** (ou **Se connecter avec Microsoft** une fois de plus) pour permettre l'envoi, ou répondez depuis l'application de la boîte.
??? Je ne reçois pas d'alerte de nouveau courriel. | Les alertes fonctionnent pour les boîtes connectées avec Microsoft, et seulement si la case d'alerte est cochée dans les **Settings** de cette boîte. Vérifiez aussi le son de notification dans **Mes paramètres**.
??? Je ne vois pas 🔗 Link to person. | Le bouton paraît seulement sur la boîte d'un administrateur d'agence, une fois que **Link emails to people** est activé sous **Paramètres → Email settings → Mailbox**. La boîte d'un directeur est personnelle et n'est jamais liée.
??? La liste est vide. | Aucune boîte n'est encore branchée, ou le dossier est vide. Appuyez sur **＋ Add account**, ou choisissez un autre dossier.
??? Qui voit mes courriels? | Vous seul. Les courriels liés sont visibles dans le dossier de la personne par les administrateurs d'agence (et ceux que votre agence autorise); le reste de votre boîte demeure privé.

## Écrans connexes

[[open: #email-settings | Paramètres de courriel]] [[open: #settings | Mes paramètres]]

Voir aussi : [Paramètres de courriel](email-settings), [Courriels pour les messages manqués](missed-chat-emails), [Centre de messages](message-centre-rules).
