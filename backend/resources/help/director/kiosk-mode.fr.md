---
title: Mode kiosque pour l’enregistrement par tablette
category: Daily Operations
order: 30
---
# Mode kiosque pour l’enregistrement par tablette

Le mode kiosque transforme une tablette placée à l’entrée de votre centre en écran libre-service d’arrivée et de départ pour les parents. Les parents touchent la carte de leur enfant, saisissent un NIP de 4 à 6 chiffres, et Kiddietrac enregistre l’événement de présence avec l’indicateur de source kiosque : aucun temps du personnel n’est requis à l’arrivée ni au départ.

## Activer le kiosque pour un centre

1. Barre latérale → **Administration → Centres**.
2. Cliquez sur **Modifier** pour le centre qui doit utiliser un kiosque.
3. Dans la section **Mode kiosque**, cochez **Activer le kiosque pour ce centre**.
4. Kiddietrac génère une URL de kiosque unique et l’affiche dans le champ URL. Cliquez sur **Copier l’URL**.

## Configurer la tablette

Ouvrez l’URL copiée dans le navigateur de la tablette. Ajoutez-la à l’écran d’accueil comme PWA pour qu’elle s’ouvre en plein écran, sans barre de navigateur. Le kiosque affiche tous les enfants inscrits au centre avec leur photo (ou leur initiale), leur nom, leur salle et leur statut actuel (AU CENTRE ou ABSENT).

La tablette n’exige aucune connexion : le jeton unique de l’URL donne un accès en lecture seule à la liste. La saisie du NIP est obligatoire pour toute écriture (arrivée / départ).

## Définir les NIP des parents

Chaque parent qui vient chercher un enfant a besoin de son propre NIP de 4 à 6 chiffres. Pour en définir un :

1. Barre latérale → **Administration → Familles** → cliquez sur la carte de la famille.
2. Dans la section **Tuteurs**, repérez le tuteur (**can_pickup** doit être activé).
3. Cliquez sur **Définir le NIP du kiosque**.
4. Saisissez un nombre de 4 à 6 chiffres → Enregistrer.
5. Communiquez son NIP au parent par un autre canal (texto, courriel, en personne).

Répétez l’opération pour chaque tuteur qui utilisera le kiosque. Il est impossible de définir un NIP pour un parent sans droit de reprise de l’enfant.

## Comment se déroule le parcours du parent

1. Touchez la carte de l’enfant → une fenêtre s’ouvre avec un clavier numérique.
2. Saisissez le NIP à 4 chiffres → Kiddietrac le vérifie auprès des tuteurs de la famille qui ont can_pickup.
3. Si le NIP correspond, Kiddietrac enregistre une arrivée (ou un départ : l’action s’inverse selon l’état actuel de l’enfant).
4. La carte passe à AU CENTRE (vert) pour une arrivée, ou revient à ABSENT (gris) pour un départ.

## Changer l’URL du kiosque

Si l’URL fuit (par exemple, si quelqu’un a partagé une capture d’écran de la page d’accueil de la tablette), cliquez sur **Renouveler le jeton** dans la section Kiosque du centre. L’ancienne URL cesse de fonctionner immédiatement. Ouvrez le navigateur du kiosque et collez-y la nouvelle URL.

## Désactiver le kiosque

Décochez **Activer le kiosque** dans la fenêtre de modification du centre. L’URL cesse de fonctionner immédiatement. Les NIP restent enregistrés : réactivez le kiosque pour tout retrouver.
