---
title: RH : fermetures déduites des congés, congés à vérifier, prorata temps partiel
category: Staff
order: 49
roles: agency_admin, platform_admin, centre_director
---
# RH : fermetures déduites des congés, congés à vérifier, prorata temps partiel

Fait partie du module RH de KiddieTrac. Tout se trouve dans **Ressources humaines > Congés > Fermetures et vérifications**. Un solde ne change jamais tout seul : chaque changement est une nouvelle ligne dans l’historique du solde de la personne, avec la raison.

## Solde après toutes les demandes en attente

Quand vous approuvez un congé, la demande affiche le solde actuel, le solde après cette demande et, si la même personne a d’autres demandes de ce type en attente, le solde **après toutes les demandes en attente**. Il passe au rouge s’il devient négatif. L’onglet **Soldes** affiche le même chiffre sous chaque montant en attente.

## Déduire une fermeture des congés (par exemple une fermeture d’hiver)

Les fermetures viennent de **Paramètres > Fermetures**. Une fermeture de type congé, urgence ou autre peut déduire les jours ouvrables fermés des congés du personnel. Un jour férié légal, jamais.

1. Trouvez la fermeture sous **Fermetures : déduire les jours des congés ?** et choisissez **Déduire des congés…** dans son menu ⋮.
2. Choisissez le type de congé (Vacances est choisi pour vous). L’aperçu liste chaque personne qui travaille à ce centre, les jours ouvrables fermés pour elle, ce qui sera déduit, et son solde avant et après.
3. Décochez les personnes à exclure. Les fins de semaine, les jours fériés légaux, les jours avant l’embauche et les jours déjà déduits par une autre fermeture ne comptent jamais. Une personne qui travaille dans deux centres ne perd un jour que si les deux sont fermés.
4. Appuyez sur **Déduire des congés**.

Ou choisissez **Ne pas déduire des congés** pour noter que la fermeture n’est pas déduite.

Rien n’est jamais déduit automatiquement, et une fermeture déjà passée demande de cocher une case d’abord. **Annuler** rend tout (avec une raison) ; vous pouvez ensuite choisir à nouveau. Seuls les administrateurs d’agence décident. Les directeurs voient les fermetures de leurs centres et qui elles concernent.

Si une fermeture déjà déduite change de dates, elle affiche **Dates modifiées - à vérifier** et apparaît sous **Congés à vérifier**.

## Congés à vérifier

Ajouter, déplacer ou retirer une fermeture ou un jour férié légal change le nombre de jours ouvrables couverts par un congé réservé. KiddieTrac ne déduit rien de nouveau. Chaque réservation dont le montant serait maintenant différent est listée sous **Congés à vérifier**, avec le montant réservé, ce qu’elle compte maintenant et pourquoi. Les directeurs et administrateurs de ce centre reçoivent une notification.

- **Utiliser le nouveau montant** : une nouvelle ligne est ajoutée à l’historique du solde pour la différence (ou, pour une demande encore en attente, elle prendra le nouveau montant à l’approbation).
- **Garder tel que réservé** : rien ne change.

Personne ne vérifie ses propres congés. La demande affiche aussi « Le montant est peut-être faux » dans **Demandes**, et la fenêtre **Approuver** permet d’utiliser le nouveau montant en approuvant. Les administrateurs peuvent appuyer sur **Vérifier les congés réservés** pour comparer chaque réservation de la dernière année au calendrier actuel.

## Prorata temps partiel (administrateurs d’agence)

Activez **Prorata des nouveaux octrois pour le personnel à temps partiel**, indiquez la semaine à temps plein de l’entreprise (40 heures au départ), et choisissez les types de congé et qui compte comme temps partiel (temps partiel, occasionnel, étudiant). Ensuite, chaque NOUVEL octroi (nouvelle année de congé ou nouvelle période de paie) d’une personne à temps partiel suit ses heures par semaine sur la semaine à temps plein : 20 heures sur 40 donnent la moitié.

- Les heures viennent du profil RH de la personne (**Personnel > Voir le profil RH > Historique de la paie et du poste**, heures par semaine). Sans heures au dossier, la personne reçoit le montant complet.
- Les octrois déjà dans l’historique du solde ne changent jamais.
- **Aperçu** montre le droit annuel de chaque personne à temps partiel avec et sans prorata avant d’enregistrer.
