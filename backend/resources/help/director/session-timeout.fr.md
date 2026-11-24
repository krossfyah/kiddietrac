---
title: Déconnexion automatique pour la sécurité
category: Settings
order: 60
---
# Pourquoi Kiddietrac vous déconnecte

Les portails Kiddietrac contiennent des renseignements sensibles sur les enfants, les familles, la facturation et les médicaments. Pour répondre aux exigences de la **LPRPS / LPRPDE** et protéger les appareils partagés (bornes, tablettes, postes de la réception), le portail vous déconnecte automatiquement dans deux cas :

## Délai d’inactivité (30 minutes)

Si vous ne cliquez pas, ne tapez pas, ne faites pas défiler et ne touchez pas l’écran pendant **30 minutes**, le portail vous déconnecte et vous ramène à la page de connexion. Cinq minutes avant, un avertissement s’affiche :

> *⏱️ Vous serez bientôt déconnecté*
> [Rester connecté]   [Se déconnecter maintenant]

Cliquez sur **Rester connecté** pour réinitialiser le minuteur. Cliquez sur **Se déconnecter maintenant** pour mettre fin à la session immédiatement.

## Délai absolu (12 heures)

Même si vous êtes actif tout ce temps, chaque connexion est limitée à **12 heures**. Cela couvre le cas d’un onglet laissé ouvert toute la nuit sur un ordinateur partagé. Une fois les 12 heures atteintes, vous serez ramené à l’écran de connexion — reconnectez-vous pour continuer.

## Qu’arrive-t-il à mon travail?

Le portail enregistre les modifications lorsque vous cliquez sur **Enregistrer**, et non lorsque vous restez sur un écran. Si vous étiez en train de saisir dans un formulaire au moment où le minuteur s’est déclenché, ce texte non enregistré est perdu — vous devrez le ressaisir après vous être reconnecté. (Nous recommandons d’enregistrer toutes les quelques minutes pour les longs formulaires.)

## Réglage par appareil

Si un directeur ou un administrateur a besoin d’un délai d’inactivité différent pour un appareil en particulier (par exemple une tablette murale qui doit rester active pendant la journée), ouvrez la console de développement du navigateur sur cet appareil et exécutez :

    KT.SessionTimeout.setIdleMinutes(60);    // change idle to 60 minutes
    KT.SessionTimeout.setAbsoluteHours(8);   // change absolute cap to 8 hours

Les réglages sont stockés sur l’appareil — les autres appareils conservent les valeurs par défaut.

## Pourquoi le message sur l’écran de connexion

Lorsque vous êtes déconnecté automatiquement, la page de connexion affiche une petite bannière ambre indiquant *« Vous avez été déconnecté après une période d’inactivité »* (ou *« limitée à 12 heures »*). C’est pour que vous sachiez que rien n’a mal tourné — vous n’avez pas été bloqué, votre session s’est simplement terminée comme prévu.
