---
title: Tableau de bord de conformité
---
# Tableau de bord de conformité

**Administration › Compliance** est une seule page qui répond à « qu'est-ce qui doit être réglé avant qu'un inspecteur
le voie? » : les certificats du personnel échus ou sur le point de l'être, les responsables qui n'ont pas activé la
connexion à deux facteurs et les centres pleins ou presque. Ouvrez-la au début de chaque semaine. La page ne fait que
lire vos dossiers : corrigez les choses à l'écran où se trouve le dossier, puis revenez, et le total baisse.

[[open: #compliance | Ouvrir le tableau de bord de conformité]]

## Lire la page

1. Ouvrez **Administration › Compliance**. La bannière indique **What needs attention**.
2. Regardez les cinq tuiles du haut : **Expired certs**, **Expiring (30d)**, **MFA not enrolled**, **At/over capacity** et **Tight (95%+)**. Le rouge veut dire d'agir tout de suite, l'ambre de le prévoir.
3. Faites défiler jusqu'aux cartes sous les tuiles pour voir les noms derrière chaque chiffre.

## Renouveler les certificats du personnel

La carte **Staff certifications** liste chaque certificat (par exemple premiers soins, RCR ou inscription EPE) échu
ou qui expire d'ici 30 jours, avec la personne, le type et « Expires … (in 4 days) » ou « (3 days ago) ».

1. Notez qui figure sur la liste.
2. Demandez le certificat renouvelé à la personne, ou ouvrez son dossier d'employé et téléversez-le avec sa nouvelle date d'expiration.
3. Revenez à **Administration › Compliance**. Une fois la nouvelle date enregistrée, la personne disparaît de la liste.

Quand personne n'est en retard, la carte affiche **✅ Every cert is current.**

## Faire activer la connexion à deux facteurs

La carte **MFA not enrolled** liste les directeurs de centre et administrateurs d'agence qui se connectent avec un mot
de passe seulement, avec leur courriel et leur dernière connexion.

1. Envoyez un message à chaque personne pour lui demander d'activer la connexion à deux facteurs.
2. La personne ouvre l'écran de deux facteurs, numérise le code avec une application d'authentification et tape le code à 6 chiffres pour confirmer.
3. Actualisez le tableau de bord : elle quitte la liste. Quand tout le monde est inscrit, la carte affiche **✅ Every director + admin has MFA enrolled.**

[[open: #mfa | Ouvrir la connexion à deux facteurs]]

## Surveiller la capacité des centres

La carte **Centre capacity** montre, pour chaque centre, les enfants inscrits par rapport aux places du permis, en
pourcentage et en barre : vert sous 80 %, ambre dès 80 %, rouge à 95 % ou plus.

1. Repérez les centres en rouge.
2. Avant d'y inscrire un autre enfant, vérifiez le permis et la liste d'attente.
3. Si la capacité du permis est mauvaise, corrigez-la dans les paramètres du centre pour que la barre soit calculée sur le bon nombre.

Si les règles de votre province prévoient des formations et vérifications du personnel, une autre carte montre qui n'a pas quel élément.

## Qui peut faire quoi

| Rôle | Voit |
|---|---|
| Administrateur d'agence | Tous les centres de l'agence active. |
| Directeur de centre | Son ou ses centres seulement. |
| Vérificateur | La même page, en lecture seule. |
| Administrateur de plateforme | L'agence choisie dans le sélecteur d'agence. |

## Questions fréquentes et problèmes

??? Un certificat a été renouvelé, mais la personne est encore sur la liste. | Le nouveau certificat n'a pas encore été enregistré avec sa nouvelle date d'expiration. Téléversez-le avec la date dans le dossier de l'employé.
??? La page affiche « Could not load ». | Actualisez la page. Si cela continue, ouvrez un billet à partir de l'Aide.
??? La carte de capacité affiche « No centres in this agency. » | L'agence n'a pas encore de centre ouvert. Un administrateur d'agence en ajoute un à l'écran des centres.
??? Cette page envoie-t-elle des courriels? | Non. Elle ne fait qu'afficher la liste; c'est vous qui décidez qui contacter.

## Écrans connexes

[[open: #inspection | Liste de vérification d'inspection]] [[open: #audit-logs | Journal d'audit]]

Voir aussi [[audit-log]] et [[compliance-and-reporting]].
