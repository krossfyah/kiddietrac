---
roles: agency_admin, platform_admin, centre_director
title: Points et récompenses des éducateurs (avec cartes-cadeaux Giftbit et Tango Card)
category: Staff Management
order: 20
---
# Points et récompenses des éducateurs

Les éducateurs gagnent des **points** chaque mois en utilisant KiddieTrac. À la fin du mois, vous pouvez
transformer ces points en récompense : un **boni dans la prochaine paie**, un **versement** ou une **carte-cadeau**.

Ouvrez **Ressources humaines > Récompenses des éducateurs**. L’écran a trois onglets : **Prix et points**, **Reconnaissance** et **Paramètres des récompenses**.

[[open: #educator-rewards | Ouvrir Récompenses des éducateurs]]

## Comment les points sont gagnés

Les points proviennent de ce que chaque personne a consigné dans KiddieTrac. Personne n’a à tenir de décompte.

| Activité | Points | Limite quotidienne |
|---|---|---|
| Observations | 10 | 10 |
| Plans de leçon | 10 | 3 |
| Promenades et sorties | 5 | 4 |
| Rapports d’incident | 5 | 5 |
| Photos et vidéos partagées | 3 | 10 |
| Journaux quotidiens | 1 | 40 |

Ce sont les valeurs de départ. Un **administrateur de l’agence** peut les modifier sous **Ressources humaines > Récompenses des éducateurs > Paramètres des récompenses > ⚙️ Valeurs des points et récompenses**,
de même que les limites quotidiennes (au-delà de la limite, une activité compte toujours, mais n’ajoute plus de points
pour la journée) et un **taux points-dollars** facultatif qui suggère un montant pour chaque récompense.

Les éducateurs, les visiteurs à domicile et les directeurs gagnent des points. Les éducateurs voient leurs propres points, ce qui les a
fait gagner et un classement des points de leur centre sous **⭐ Mes points**. L’argent n’est jamais affiché
dans le classement.

## Donner une récompense

1. Choisissez le **mois** en haut de **Points et récompenses**.
2. Cliquez sur **🎁 Récompenser** sur la ligne d’une personne.
3. Saisissez le montant et choisissez le mode :
   - **Boni dans la prochaine paie.** Ajouté comme une ligne sur son talon de paie la prochaine fois que vous
     traitez sa paie, et marqué comme payé à ce moment-là. Vous pouvez l’annuler tant que cela ne s’est pas produit.
   - **Versement.** Payé en dehors de KiddieTrac (virement Interac, chèque). Ajoutez une référence si vous le souhaitez.
   - **Carte-cadeau que vous avez achetée.** Collez le code ou le lien de la carte. Seul l’éducateur peut le voir,
     dans Mes points.
   - **Envoyer une carte-cadeau par Giftbit ou Tango Card.** Voir ci-dessous.
4. Ajoutez une note si vous le souhaitez, puis cliquez sur **Donner la récompense**. L’éducateur reçoit une notification et un
   court courriel. Une carte-cadeau n’est jamais placée dans le courriel; elle l’attend dans l’application.

Un **directeur** récompense le personnel de ses propres centres. Les récompenses destinées aux directeurs sont accordées par un
administrateur de l’agence.

## Cartes-cadeaux avec Giftbit

KiddieTrac s’intègre à **Giftbit** pour envoyer des cartes-cadeaux numériques (Amazon, Tim Hortons et
des centaines d’autres marques) directement depuis la boîte de dialogue Récompenser.

**Chaque agence utilise son propre compte Giftbit** et paie ses propres cartes.

1. Créez un compte d’entreprise sur **giftbit.com**. Commencez par un compte **testbed**
   (gratuit, sans vraies cartes) pour essayer.
2. Dans Giftbit, allez à **Account → API keys** et créez un jeton.
3. Dans KiddieTrac, un administrateur de l’agence ouvre **Paramètres → 💳 Prestataires de paiement → 🎁 Cartes-cadeaux**,
   choisit **Testbed** ou **En direct**, colle le jeton, coche **Utiliser Giftbit pour les récompenses des
   éducateurs** et clique sur **Enregistrer**. **Tester la connexion** confirme le jeton et affiche votre solde.
4. Pour de vraies cartes, **ajoutez des fonds** dans votre compte Giftbit.

Ensuite, dans la boîte de dialogue Récompenser, choisissez **Envoyer une carte-cadeau Giftbit**, sélectionnez une marque (ou **Les laisser
choisir**, pour que l’éducateur choisisse à l’ouverture), et saisissez un montant accepté par la marque.
KiddieTrac vérifie le montant par rapport à la marque et à votre solde Giftbit avant de commander.

- **En préparation** signifie que Giftbit crée encore la carte. Elle apparaît sous peu dans Mes
  points de l’éducateur. **Vérifier l’état de la carte-cadeau** interroge Giftbit immédiatement.
- **En attente de fonds Giftbit** signifie que votre solde était insuffisant. Ajoutez des fonds dans Giftbit et la carte
  est envoyée automatiquement.
- **Annuler la carte-cadeau** fonctionne jusqu’à ce que l’éducateur l’utilise. La valeur est reversée dans votre
  solde Giftbit.

Le jeton est stocké chiffré et n’est plus jamais affiché en entier.

## Cartes-cadeaux avec Tango Card

KiddieTrac s’intègre aussi à **Tango Card**. Cela fonctionne de la même façon, à partir du compte
Tango propre à votre agence :

1. Obtenez une plateforme Tango (communiquez avec Tango Card pour obtenir un compte). Vous recevez un **bac à sable** pour tester
   (sans vraies cartes) et une plateforme de production.
2. Dans le portail Tango, ouvrez **API keys → Create credentials** pour obtenir le **nom de la plateforme** et la
   **clé API**. Notez l’**identifiant du client** et l’**identifiant du compte** qui paieront
   les cartes.
3. Dans KiddieTrac : **Paramètres → 💳 Prestataires de paiement → 🎁 Cartes-cadeaux**, section Tango Card : choisissez **Bac à sable** ou
   **Production**, remplissez les quatre champs, cochez **Utiliser Tango Card pour les récompenses des éducateurs** et
   **Enregistrer**. **Tester la connexion** affiche le compte et son solde.
4. Approvisionnez le compte dans Tango.

Dans la boîte de dialogue Récompenser, si Giftbit et Tango Card sont tous deux connectés, choisissez par lequel **envoyer**,
puis sélectionnez la carte. Les cartes Tango sont des cartes précises (pas de « Les laisser choisir »), et le
montant doit lui convenir. La carte (un lien, ou un numéro de carte et un NIP) apparaît uniquement dans Mes points
de l’éducateur, avec les instructions d’utilisation de Tango.

**Annuler la carte-cadeau** ne fonctionne pour Tango que dans le cadre des règles de Tango : un lien de choix non utilisé, dans les
5 jours suivant l’envoi. Sinon, KiddieTrac affiche la raison indiquée par Tango.

> KiddieTrac n’est affilié ni à Giftbit ni à Tango Card. Les conditions des cartes-cadeaux sont fixées par chaque marque.

## Historique des récompenses

**Ressources humaines > Récompenses des éducateurs > Prix et points > 🗂 Historique des récompenses** liste toutes les récompenses accordées, mois par mois (les plus récentes d’abord) : qui, quoi, les points et le montant à ce moment-là, le mode, qui l’a approuvée, la date, et si elle était automatique. Filtrez par mois, centre et éducateur, et exportez-la avec les boutons CSV, Excel ou PDF sous le tableau. Les directeurs voient leurs propres centres.

## Où se trouvent les paramètres

Tous les paramètres des prix des éducateurs se trouvent dans l’onglet **Paramètres des récompenses** de **Ressources humaines > Récompenses des éducateurs** (**🏅 Prix automatiques** et **⚙️ Valeurs des points et récompenses**) : les prix de remerciement automatiques (et les brouillons en attente de révision), les valeurs des points et la récompense suggérée, ainsi que le mode proposé par défaut dans la boîte de dialogue Récompenser. Les directeurs peuvent les consulter; les administrateurs de l’agence les modifient.

Les comptes de cartes-cadeaux (Giftbit et Tango Card, testbed/bac à sable ou en direct) se trouvent avec les autres comptes de paiement dans **Paramètres → 💳 Prestataires de paiement → 🎁 Cartes-cadeaux** (administrateurs de l’agence).
