---
title: Licences (forfaits et modules des agences)
---

# Licences (forfaits et modules des agences)

**Platform › 🔑 Licences** est l'endroit où KiddieTrac décide ce que chaque agence peut utiliser. Chaque agence a une licence : un forfait (par exemple Starter, Growth ou Agency) et ses modules, chacun avec un numéro de pièce, un prix, une durée, une date de fin et un réglage de renouvellement. La licence active ou désactive les fonctions, et les renouvellements sont facturés dans **Platform › 🏦 Accounting AR/AP** quand la facturation est activée. Approuver une proposition crée la licence pour vous ; cet écran sert à ajouter, modifier, renouveler ou annuler à la main.

[[open: #licences | Ouvrir les licences]]

## Lire la liste

1. Ouvrez **Platform › 🔑 Licences**. Les tuiles affichent **Live licences**, **Monthly recurring**, **Renew within 30 days** et **In grace (needs attention)**.
2. Filtrez la liste avec **Agency** et **Status** (**Active**, **Grace**, **Renews within 30 days**, **Expired**, **Cancelled**).
3. Chaque agence forme un groupe. Appuyez sur sa ligne d'en-tête pour l'ouvrir ou la fermer, ou utilisez **▾ Expand all** / **▸ Collapse all**. Les agences qui demandent de l'attention s'ouvrent d'elles-mêmes.
4. L'en-tête montre la durée (**Renews** ou **Ends** à une date, ou **Grace until** une date), le mode de facturation, les modules, le statut, les **🏫 centres ouverts / permis**, et **Trial** ou **Complimentary** au besoin.
5. Les lignes en dessous montrent **Part #**, **Item**, **Price**, **Term** et **Status**. **Not renewing** veut dire que cette ligne se termine à sa date.

## Ajouter une licence

1. Appuyez sur **＋ Add licence**.
2. Choisissez l'**Agency** et le **Plan or add-on**. Le **Price per term** se remplit à partir du catalogue ; changez-le si vous avez convenu d'un autre prix.
3. Choisissez le **Licence type** : **Paid**, **Complimentary (billed at list, zeroed)** ou **Trial**. Un essai demande sa **Trial length** en jours, semaines, mois ou années, ne coûte rien, ne se renouvelle pas et ne crée aucune facture.
4. Réglez **Billed**, **Starts**, une **Note** facultative et **Renews automatically**.
5. Appuyez sur **Add licence**. Un message **Licence added** apparaît.

> ℹ️ Un module suit toujours la durée du forfait de l'agence. Un forfait ajouté à une agence qui en a déjà un le remplace et garde sa date de fin.

## Modifier la licence d'une agence

Ouvrez le **⋮** sur la ligne d'en-tête de l'agence :

1. **＋ Add add-on** (ou **＋ Add plan** s'il n'y en a pas) : choisissez-le et appuyez sur **Add add-on**.
2. **📅 Term & renewal** : réglez **Ends / renews on**, **Billed**, **Contract term (months)** et **Renews automatically**, puis **Save for the whole licence**. Le forfait et tous les modules partagent cette durée ; une date de fin future rouvre une licence en période de grâce ou terminée.
3. **🔁 Renew all** : renouvelle toutes les lignes ensemble jusqu'à une nouvelle date de fin. Aucune facture n'est créée ici ; la facturation crée la sienne.
4. **💳 Convert to paid** (essais seulement) : chaque ligne prend son prix et se renouvelle à partir de sa date de fin.
5. **🏫 Included centres…** : modifie combien de centres la licence permet. Laissez vide pour utiliser le nombre du produit. Les centres existants ne sont jamais fermés ; seule l'ouverture d'un nouveau est bloquée.
6. **⛔ Cancel licence** : le forfait et tous les modules s'arrêtent tout de suite, sauf la facturation et le paiement automatique, qui continuent jusqu'à la fin de la période déjà payée.

Sur une seule ligne, le **⋮** offre **✏️ Edit (price, quantity, note)** et, pour les modules, **🗑️ Remove add-on**, dont les fonctions s'arrêtent tout de suite.

> ⚠️ Après avoir retiré un module ou annulé une licence, KiddieTrac propose **Reissue invoice** pour chaque facture ouverte qui le facture encore. La réémission annule cette facture et crée une copie corrigée pour la même période ; les paiements sont transférés. Envoyez-la par courriel depuis Accounting AR/AP.

## Ventes par numéro de pièce

Choisissez l'onglet **📊 Sales by part #** pour voir chaque **Part #** avec **Live**, **Monthly recurring**, **Invoiced this year** et **Invoiced all time**.

## Qui peut faire quoi

- **Super-administrateurs de la plateforme** : tout sur cet écran.
- **Administrateurs d'agence** : voient leur propre licence, en lecture seule, sur leur page **Licence** (depuis **🏢 My agency**), et peuvent demander d'ajouter une fonction à partir de là. Voir [[plan-and-add-ons]].
- **Tous les autres** : ne voient jamais les licences. Les fonctions qui ne font pas partie du forfait sont cachées au personnel et aux familles.

## Questions fréquentes et problèmes

??? « Choose the agency and the plan or add-on » | Les deux listes doivent être remplies avant que **Add licence** fonctionne.
??? Une agence dit qu'une fonction est encore verrouillée après l'ajout. | Les écrans ouverts reçoivent le changement en quelques instants ; sinon, il apparaît dès qu'on passe à un autre écran.
??? Qu'est-ce que Grace? | La date de fin est passée sans renouvellement. L'agence garde ses fonctions pendant que la facture de renouvellement est créée. Renouvelez, changez la durée ou annulez.
??? La liste dit « No licences yet. » | Approuver une proposition les crée, ou appuyez sur **＋ Add licence**.
??? Le nombre de centres est en rouge. | L'agence a plus de centres ouverts que la licence n'en permet. Ajoutez un module de centre supplémentaire ou augmentez **🏫 Included centres…**.

## Écrans liés

[[open: #sales-invoices | Comptabilité AR/AP]] · [[open: #platform-sales | Rapports de ventes]] · [[open: #platform-agencies | Toutes les agences]] · [[open: #admin-features | Indicateurs de fonctions]]

Voir aussi : [[platform-invoices]] · [[plan-and-add-ons]] · [[platform-sales-reports]]
