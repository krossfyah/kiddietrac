---
title: Forfaits et tarifs (liste de prix des ventes)
---
# Forfaits et tarifs (liste de prix des ventes)

**Forfaits et tarifs** est la liste de prix de KiddieTrac pour les propositions : les forfaits, modules complémentaires,
services ponctuels et rabais qu'un représentant ajoute à une proposition avec **+ Ajouter un forfait ou un rabais prédéfini…**, et les
conditions avec lesquelles chaque nouvelle proposition démarre. Les superadministrateurs l'ouvrent dans
**Sales › Plans & pricing** du menu de la plateforme; les représentants, à partir de la tuile **Forfaits et tarifs** de
leur écran d'accueil. Changer un prix ici ne change jamais une proposition déjà faite.

[[open: #sales-plans | Ouvrir les forfaits et tarifs]]

## Les cinq onglets

- **📦 Forfaits** — le forfait du client détermine les fonctions qu'il obtient (son **Niveau de fonctionnalités**).
- **🧩 Modules complémentaires** — vendus en plus d'un forfait; chacun active les fonctions que vous cochez.
- **🛠️ Services ponctuels** — installation, formation, importation de données : facturés une fois, sans licence.
- **🏷️ Discounts** — pourcentage ou montant de rabais, ajouté à une proposition à partir du même menu.
- **📄 Conditions de la proposition** — validité, contact « Prepared by », conditions, taxes et probabilités par étape.

Chaque article reçoit un numéro de pièce la première fois qu'il est enregistré (KT-PLN forfaits, KT-ADD modules,
KT-SVC services, KT-DSC rabais). Les numéros de pièce paraissent sur les propositions, les licences et les factures, et
les ventes sont rapportées selon ces numéros.

## Ajouter ou modifier un forfait, un module ou un service

1. Ouvrez l'onglet et appuyez sur **➕ Ajouter un forfait** (ou **➕ Ajouter un module**, **➕ Ajouter un service**). Une nouvelle carte paraît en haut.
2. Tapez le nom et le prix, et choisissez **/mois** ou **/an** (un service est toujours **unique**).
3. Pour un forfait, choisissez le **Niveau de fonctionnalités** (**Fonctionnalités Démarrage**, **Fonctionnalités Croissance** ou **Agence (toutes les fonctionnalités)**).
4. Pour un module, cochez ce qu'il active dans **Active** (par exemple **Messages texte (SMS)**, **Paie**, **Marque blanche**).
5. Réglez **Centres included (blank = no centre cap)** si l'article comprend un nombre de centres.
6. Écrivez la **Description** qui paraît sur les propositions et appuyez sur **Enregistrer**. Un message **✅ Enregistré** paraît et le numéro de pièce s'affiche sur la carte.

## Ajouter un rabais

1. Ouvrez **🏷️ Discounts** et appuyez sur **➕ Ajouter un rabais**.
2. Nommez-le (par exemple « Rabais OSBL »), entrez le montant et choisissez **% rabais** ou **$ rabais**.
3. Choisissez à quoi il s'applique dans **S’applique à** : **toute la proposition**, **éléments récurrents (mensuels / annuels)** ou **éléments uniques**. La ligne « Shows as: » montre la ligne de la proposition.
4. Appuyez sur **Enregistrer**.

## Régler les valeurs par défaut des propositions

1. Ouvrez **📄 Conditions de la proposition**.
2. Réglez **Les propositions sont valides pendant** … **days**, ainsi que **Titre / équipe**, **Courriel des ventes** et **Téléphone des ventes** affichés comme contact « Prepared by » (les réponses vont à ce courriel).
3. Modifiez **Conditions de la proposition (courtes, sous les prix)** et **Conditions générales (page distincte, avant les signatures)**. `{days}` est remplacé par le nombre de jours.
4. Ajoutez des **Taxes par défaut** avec **+ Ajouter une taxe** (jusqu'à 5), et réglez la **Probabilité de conclusion par étape** de chaque étape pour la prévision pondérée du Pipeline.
5. Appuyez sur **Enregistrer** (« Proposal defaults saved »).

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Représentant des ventes | Ajouter, modifier et supprimer forfaits, modules, services et rabais, et modifier les valeurs par défaut des propositions. |
| Administrateur de plateforme (superadministrateur) | La même chose, à partir du menu Sales de la plateforme. |
| Personnel des agences | Ne voit jamais cet écran : le forfait d'une agence se trouve dans ses paramètres, pas ici. |

## FAQ et quand quelque chose ne va pas

??? « Give it a name » / « Give the discount a name » | Chaque article doit avoir un nom avant que **Enregistrer** fonctionne.
??? « Choose the feature level this plan gives » | Un forfait doit avoir un **Niveau de fonctionnalités**; choisissez-en un et enregistrez de nouveau.
??? « Enter how much it takes off » ou « A percentage can be at most 100 » | Entrez un rabais supérieur à 0, et pas plus de 100 pour **% rabais**.
??? J'ai changé un prix, mais une ancienne proposition affiche encore l'ancien. | C'est voulu : chaque proposition garde sa propre copie. Modifiez la ligne de la proposition, ou faites-en une nouvelle.
??? Que se passe-t-il quand je supprime un article? | Il est retiré définitivement de cette liste. Les propositions qui l'utilisent déjà gardent leurs lignes.

## Écrans connexes

[[open: #sales-quotes | Propositions]] [[open: #sales | Pipeline]]

Voir aussi [[sales-proposals]] et [[sales-pipeline]].
