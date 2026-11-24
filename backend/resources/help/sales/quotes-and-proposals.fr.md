---
title: Créer des soumissions et des propositions
category: Proposals
order: 1
---

# Créer des soumissions et des propositions

Les propositions se trouvent sur la page du prospect, dans l’onglet **📄 Propositions**. N’importe qui dans l’équipe des ventes peut
les créer, les modifier et les envoyer. Chaque proposition reçoit son propre numéro, par exemple *KT-Q-000123*.

## Créer une proposition

1. Ouvrez le prospect et allez à l’onglet **📄 Propositions**.
2. Appuyez sur **+ Nouveau**. Le générateur de propositions s’ouvre, déjà rempli avec vos
   [valeurs par défaut des propositions](proposal-defaults).
3. Donnez-lui un **Titre** (au départ : *Proposition KiddieTrac*).
4. Vérifiez les cases **Préparé pour** et **Préparé par** (voir ci-dessous).
5. Choisissez la **Devise** et la **Facturation** (**Mensuelle** ou **Annuelle**).
6. Ajoutez vos lignes, rabais et taxes.
7. Définissez **Valide jusqu’au** et ajoutez des **Notes** au besoin.
8. Appuyez sur **Créer la proposition**.

Le **total** se met à jour pendant que vous saisissez; vous voyez donc toujours le montant que verra le prospect.

## Préparé pour et Préparé par

- **Préparé pour** se remplit à partir du prospect : organisation, nom du contact, titre,
  adresse, courriel et téléphone. Modifiez tout ce qui doit figurer autrement sur cette proposition.
- **Préparé par** commence par **votre nom** et le **titre, le courriel et le téléphone** des ventes de KiddieTrac
  tirés des valeurs par défaut des propositions. Il n’utilise jamais votre courriel de connexion personnel; les réponses
  arrivent donc dans la boîte de réception partagée des ventes. Vous pouvez le modifier pour une seule proposition au besoin.

## Devise et facturation

- Choisissez parmi 14 devises (CAD, USD, GBP, EUR et plus). Les prix sont saisis dans cette devise;
  **rien n’est converti**. Votre dernier choix est mémorisé pour votre prochaine proposition.
- **Mensuelle** ou **Annuelle** détermine l’affichage du total (*par mois* ou *par an*). Dans une proposition
  annuelle, les forfaits mensuels prédéfinis sont calculés **×12**. Si vous passez de Mensuelle
  à Annuelle, les lignes de forfaits prédéfinis sont recalculées pour vous.

## Lignes

Ajoutez des lignes de deux façons :

- **+ Ajouter un forfait prédéfini ou un rabais…** — choisissez un forfait dans **Forfaits et tarifs**. Le nom,
  la description et le prix se remplissent pour vous.
- **+ Ligne vide** — une ligne libre pour tout ce qui est sur mesure. Définissez la quantité et le prix.

Les longues descriptions passent à la ligne. Appuyez sur **×** pour supprimer une ligne.

## Rabais

Il y a deux types :

- **Forfaits de rabais** — configurés une fois dans **Forfaits et tarifs** sous **🏷️ Rabais**, puis
  choisis dans le même menu **Ajouter un forfait prédéfini ou un rabais…** (ils commencent par 🏷️). Un
  rabais peut être un pourcentage ou un montant fixe, et s’appliquer à **l’ensemble de la proposition**, seulement aux **éléments récurrents (mensuels / annuels)**
  ou seulement aux **éléments ponctuels**. Il
  s’affiche sur sa propre ligne avec un signe moins, et un pourcentage se met à jour quand vous modifiez les autres lignes.
- **Rabais supplémentaire (montant)** — un montant ponctuel déduit du sous-total de cette proposition.

> **Astuce :** Pour ajouter un forfait de rabais, allez à Forfaits et tarifs → **➕ Ajouter un rabais**, donnez-lui un nom, le montant, **% de rabais** ou **$ de rabais**, et ce à quoi il **S’applique à**.

[[open: #sales-plans | Ouvrir Forfaits et tarifs]]

## Taxes

Sous **Taxes (sur le total après rabais)**, appuyez sur **+ Ajouter une taxe** et saisissez un nom (par
exemple *TVH*) et un taux. Vous pouvez en ajouter jusqu’à 5. Les nouvelles propositions démarrent avec vos taxes par défaut.

## Notes, conditions et conditions générales

- **Notes** — tout ce que vous voulez dire au client. Affichées sur la proposition sous les prix.
- **Conditions de la proposition** — les conditions courtes sous les prix, par exemple la durée pendant laquelle le prix est garanti.
- **▸ Conditions générales** — appuyez dessus pour ouvrir les conditions complètes. Elles s’impriment sur leur propre page,
  avant les signatures. Commencez une ligne par `##` pour un titre ou par `-` pour une puce. Laissez-les
  vides pour omettre la page.

Chaque proposition conserve sa propre copie; modifier les valeurs par défaut plus tard ne change donc jamais une proposition
déjà créée.

## Le menu ⋮ de chaque proposition

Chaque carte de proposition a un menu **⋮** :

- **👁 Voir** — voyez la proposition exactement comme elle s’imprime. Cliquer sur la carte fait la même chose.
- **✏️ Modifier** — rouvrez le générateur.
- **⬇ Télécharger le PDF** — enregistrez le PDF à l’image de la marque.
- **📧 Envoyer par courriel au prospect** — envoyez-la (voir ci-dessous).
- **🔗 Copier le lien de signature** — copiez le lien privé où le prospect peut signer en ligne.
- **✍️ Contresigner** — apparaît une fois que le prospect a signé.
- **🗑 Supprimer** — supprime la proposition. L’historique du prospect conserve une note indiquant qu’elle a été supprimée.

Une fois que le prospect a signé, **Modifier**, **Envoyer par courriel** et **Copier le lien de signature** ne sont plus
offerts : ce qu’il a signé ne peut pas changer. Voir [Signer les propositions en ligne](signing-proposals-online).

## Le PDF

Le PDF affiche le logo KiddieTrac et indique le numéro de la proposition, la date d’émission et
la date de validité, les renseignements Préparé pour et Préparé par, la facturation et la devise, les
lignes, les totaux, les notes et les conditions de la proposition. Viennent ensuite :

- la page des **Conditions générales**, et
- une section **Acceptation** avec des lignes pour la signature, le nom et le titre, et la date, pour le client
  et pour KiddieTrac.

Chaque page indique *Page n de m* et *Privé et confidentiel. Ne pas distribuer.*

## L’envoyer par courriel au prospect

1. Ouvrez le menu **⋮** et choisissez **📧 Envoyer par courriel au prospect**.
2. Ajoutez des destinataires en **Cc** ou **Cci** si vous le souhaitez (séparez les adresses par des virgules, jusqu’à 10 chacun).
3. Appuyez sur **Envoyer le courriel**.

Le courriel est envoyé à l’adresse courriel du prospect; ajoutez-en donc une au prospect d’abord. Une proposition au
statut brouillon passe à **Envoyée**, et l’envoi est consigné dans l’historique du prospect.

Ce que reçoit le prospect :

- Un courriel à l’image de la marque qui le remercie de son intérêt et lui présente KiddieTrac.
- **Aucun prix dans le courriel.** Tous les chiffres figurent seulement dans le PDF en pièce jointe.
- Le PDF de la proposition en pièce jointe, avec les conditions générales.
- Un bouton **Lire et signer en ligne** qui ouvre sa page de signature privée. Toute personne qui ouvre
  le courriel, y compris les personnes en copie, peut l’utiliser.
- Un avis **privé et confidentiel** lui demandant de ne pas partager la proposition.

Lorsque le prospect répond, la réponse est envoyée au courriel **Préparé par** (le contact des ventes),
et non à une adresse sans réponse.

## Suivre le résultat

Chaque carte de proposition affiche son statut : **Brouillon**, **Envoyée**, **✍️ Signée — à contresigner**,
**✅ Signée par les deux parties** ou **Refusée**. En dessous, vous voyez aussi quand le prospect l’a ouverte ou
refusée en ligne.

Vous pouvez toujours définir le **Statut** à la main dans le générateur (**Brouillon**, **Envoyée**, **Acceptée**,
**Refusée**) pour une proposition traitée sur papier.

??? Puis-je modifier une proposition après l’avoir envoyée? | Oui, tant que le prospect ne l’a pas signée. Modifiez-la et envoyez-la de nouveau par courriel. Une fois qu’il a signé, créez plutôt une nouvelle proposition.
??? Pourquoi le courriel Préparé par n’est-il pas le mien? | Les propositions utilisent le courriel et le téléphone des ventes partagés définis dans Conditions et valeurs par défaut des propositions, afin que les réponses parviennent à toute l’équipe.
??? Le courriel affiche-t-il le prix? | Non. Les prix, les totaux et les dates figurent seulement dans le PDF en pièce jointe.
