---
title: Indicateurs de fonctionnalités et forfaits
---
# Indicateurs de fonctionnalités et forfaits

**Platform › Feature flags** permet à un administrateur de la plateforme de choisir le forfait d'une agence et d'activer ou de désactiver une fonctionnalité pour cette seule agence. Servez-vous-en pour donner à une agence une fonctionnalité que son forfait n'inclut pas, pour cacher une fonctionnalité qu'elle ne doit pas encore utiliser, ou pour voir quels écrans une fonctionnalité contrôle.

[[open: #admin-features | Ouvrir Feature flags]]

## Choisir l'agence

1. Ouvrez **Platform › 🚩 Feature flags**. L'écran s'ouvre sur l'agence où vous vous trouvez (celle du sélecteur d'agence), pas sur la première de la liste.
2. Vérifiez la liste **Agency** en haut. Si elle affiche une autre agence, choisissez la bonne avant de changer quoi que ce soit : **Save changes** enregistre toujours pour l'agence choisie ici.
3. Attendez la fin de **Loading agency settings…**. Le forfait, la bannière de région et toutes les cartes de fonctionnalités de l'agence s'affichent.

> ⚠️ Regardez le nom dans **Agency** avant chaque enregistrement. Les indicateurs et les forfaits sont propres à chaque agence, et un changement s'applique tout de suite à tout le monde dans cette agence.

## Lire la page

- **Plan & billing** : la liste **Plan** (chaque forfait avec son prix mensuel) et **Billing status**. Le statut de facturation est en lecture seule et indique **set by the licence** : il suit la licence de l'agence, alors changez-le à l'écran **Licences**, pas ici.
- Bannière **Platform** : la région de l'agence (pays et province ou État) et ses caractéristiques. Si elle indique *Estimated from the time zone*, l'agence et ses centres n'ont pas encore de province ni d'État enregistré.
- Tuiles des inclusions : ce que toute licence comprend (par exemple le soutien, le Message Centre et One-Tap), chacune marquée **Included** ou **Off**.
- **Feature overrides** : une carte par fonctionnalité, regroupées et colorées par domaine. Chaque carte montre le nom, le code, le forfait minimum qui l'inclut (**plan ≥ …** ou **add-on only**) et, en petites pastilles, les écrans du menu qu'elle contrôle. *No menu item — gates the API only* signifie que la fonctionnalité n'a pas d'écran à elle.
- Les compteurs en haut à droite indiquent combien de fonctionnalités sont **forced on**, **forced off** et **auto**.

## Changer une fonctionnalité pour une agence

1. Trouvez la carte de la fonctionnalité sous **Feature overrides**.
2. Appuyez sur l'un de ses trois boutons :
   - **Auto** : suivre le forfait (le réglage normal).
   - **✓ On** : l'activer de force, même si le forfait ne l'inclut pas.
   - **✗ Off** : cacher ses écrans à tout le monde dans cette agence et refuser ses appels à l'API.
3. La carte devient verte pour **✓ On**, rosée pour **✗ Off**, et reprend la couleur de son groupe pour **Auto**. Rien n'est encore enregistré.
4. Recommencez pour les autres fonctionnalités.
5. Appuyez sur **Save changes**. **Saving…** s'affiche, puis **✓ Saved**, et la page se recharge avec les nouveaux réglages.

[[show: #admin-features @ Save changes | Montrez-moi]]

> 💡 Vous changez d'idée avant d'enregistrer? Appuyez sur **Cancel** pour recharger les réglages enregistrés de l'agence.

## Changer le forfait de l'agence

1. Choisissez le nouveau forfait dans la liste **Plan**.
2. Appuyez sur **Save changes**.
3. Toutes les fonctionnalités en **Auto** suivent maintenant le nouveau forfait. Celles que vous avez forcées en **✓ On** ou **✗ Off** gardent votre choix.

> ℹ️ Les modules payants et la facturation d'une agence viennent de sa licence. Pour une agence qui paie, changez le forfait ou les modules à l'écran **Licences** pour que la facture corresponde; utilisez cet écran pour les exceptions.

## Qui peut faire quoi

| Rôle | Ce qu'il peut faire |
|---|---|
| Administrateur de la plateforme | Voit **Feature flags** dans le menu Platform, change les forfaits et les exceptions de n'importe quelle agence. |
| Administrateur d'agence, directeur de centre | Ne voient pas cet écran. Ils voient ce que leur forfait permet; pour plus, ils passent par les invitations à mettre à niveau ou communiquent avec KiddieTrac. |
| Tout le monde dans l'agence | Voit simplement apparaître ou disparaître les écrans d'une fonctionnalité, au prochain chargement de la page. |

## Questions fréquentes et problèmes

??? J'ai changé un indicateur, mais les gens de l'agence voient encore l'écran. | Le menu est lu au chargement de la page. Demandez-leur d'actualiser ou de se reconnecter. Vérifiez que vous avez enregistré la bonne agence dans la liste Agency.
??? Le Billing status ne change pas. | Il est en lecture seule ici et suit la licence. Changez-le à l'écran Licences.
??? Un message rouge s'affiche au lieu des cartes. | La liste des fonctionnalités ou l'agence n'a pas pu être chargée (par exemple « Agency not found » ou « Forbidden »). Actualisez; seuls les administrateurs de la plateforme peuvent ouvrir cet écran.
??? La liste Agency indique « No agencies found ». | Aucune agence n'a pu être affichée pour vous. Actualisez et vérifiez que vous êtes connecté comme administrateur de la plateforme.
??? Que fait une fonctionnalité mise à Off? | Ses écrans quittent le menu pour tout le monde dans l'agence et le serveur refuse ses requêtes. Aucune donnée n'est supprimée : remettez-la à Auto ou On et tout revient.
??? Quelqu'un reçoit-il un courriel ou une notification? | Non. Changer un forfait ou un indicateur ici n'envoie rien à l'agence.

## Écrans liés

[[open: #licences | Licences]] [[open: #platform-agencies | Toutes les agences]] [[open: #admin-mrr | Tableau MRR]] [[open: #sales-plans | Forfaits et prix]]
