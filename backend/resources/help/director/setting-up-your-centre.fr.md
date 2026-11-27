---
title: Configurer votre centre
---
# Configurer votre centre

**Centres** est l’écran où l’administrateur d’agence ajoute et gère chaque emplacement de l’agence : un centre, une responsable de service de garde en milieu familial ou une salle, selon le nom que votre agence leur donne. Chaque centre contient son adresse, son prestataire (propriétaire), sa capacité, ses heures d’ouverture, son image de marque, sa borne et ses réglages de courriel. Ouvrez-le depuis le menu latéral : **Aperçu > Centres**.

[[open: #admin-centres | Ouvrir Centres]]

## Ajouter un centre

1. Ouvrez **Centres** et appuyez sur **+ Ajouter un centre** (en haut à droite). Le formulaire **Nouveau centre** s’ouvre.
2. Tapez le **Nom du centre**, par exemple « Centre d’apprentissage Starlight ».
3. Remplissez **Prénom du prestataire (propriétaire)** et **Nom de famille du prestataire (propriétaire)**. C’est la personne qui dirige le milieu ou le centre, pas sa visiteuse à domicile.
4. Au besoin, ajoutez le **Numéro de permis**, **Maximum children enrolled (capacity)** et **Nombre maximal d’enfants en même temps**. Les inscriptions peuvent dépasser ce deuxième nombre; les présences, non.
5. Commencez à taper dans **Trouver l’adresse** et choisissez la bonne suggestion. Les champs **Adresse**, **Ville**, **Province / State** et **Code postal** se remplissent; vérifiez-les, ou tapez-les vous-même.
6. Cochez les **Jours d’ouverture** et indiquez **Ouvre** et **Ferme**. L’heure de fermeture sert à mesurer les départs tardifs; laissez-la vide seulement si le prestataire n’a pas d’heure de fermeture fixe.
7. Appuyez sur **Créer le centre**. Le nouveau centre apparaît dans la liste.

> 💡 C’est l’adresse qui place le centre sur la **Carte des prestataires** : une adresse complète vous évite de revenir ici.

## Modifier un centre

1. Trouvez le centre dans la liste et ouvrez son menu **⋮**.
2. Appuyez sur **Modifier**. Le même formulaire s’ouvre, déjà rempli.
3. Changez ce qu’il faut. Sous **Image de marque**, vous pouvez **Téléverser un logo** (2 Mo maximum), choisir une **Couleur principale** et une **Couleur d’accent**, et écrire un **Tagline (shown on the centre card)**.
4. Mettez à jour la **Biographie du prestataire** si les familles doivent voir une nouvelle présentation. Une fois remplie, elle ne peut pas être vidée, car elle est envoyée aux familles à leur arrivée.
5. Utilisez l’interrupteur de courriel pour activer ou désactiver les courriels de ce centre.
6. Appuyez sur **Enregistrer les modifications**. La ligne se met à jour aussitôt.

Pour consulter un centre sans risquer de le modifier, utilisez plutôt **⋮ > Voir**.

## Activer la borne

1. Ouvrez **⋮ > Modifier** sur le centre et descendez jusqu’à **🛡 Mode borne**.
2. Cochez **Activer la borne pour ce centre**. Les parents peuvent alors faire l’arrivée et le départ de leurs enfants sur une tablette à l’entrée, avec leur NIP de 4 à 6 chiffres.
3. Appuyez sur **Générer un jeton** s’il n’y a pas encore de lien, puis sur **Copier l’URL**, et ouvrez ce lien sur la tablette.
4. Si une tablette est perdue ou volée, appuyez sur **Sign out all kiosks**. L’ancien lien cesse de fonctionner sur toutes les tablettes en même temps; ouvrez le nouveau lien sur celles que vous utilisez encore.

## Nommer un superviseur par intérim

1. Ouvrez **⋮ > Superviseur par intérim** sur le centre.
2. Choisissez qui remplace le superviseur quand il n’est pas sur place, puis enregistrez. Voir [[headcounts-and-acting-supervisor]].

## Fermer un prestataire

1. Ouvrez **⋮ > 🚪 Close this provider…**.
2. Choisissez le **Dernier jour d’exploitation**.
3. Pour chaque famille, choisissez où elle va : une salle dans un autre centre, **Retrait — départ du service de garde**, ou **Waitlist — place with a new educator later**.
4. Appuyez sur **Aperçu** pour vérifier le plan, puis sur **Fermer le prestataire**. Les transferts et les retraits sont appliqués, les parents reçoivent un courriel et le prestataire passe dans **Archivé**.

Les administrateurs d’agence peuvent basculer entre **Actif** et **Archivé**, sous la bannière, pour voir ou restaurer les centres fermés.

## Qui peut faire quoi

| Rôle | Ce qu’il peut faire |
|---|---|
| Administrateur d’agence | Tout sur cet écran : ajouter, modifier, borne, verrouillage de connexion, fermer et restaurer des centres |
| Administrateur de la plateforme | La même chose, dans l’agence qu’il consulte |
| Directeur de centre | N’a pas l’écran **Centres**. Les directeurs voient leurs propres centres dans le **Tableau de bord** et demandent les changements à un administrateur d’agence |

## FAQ et dépannage

??? J’ai appuyé sur Créer le centre et rien n’a été enregistré. | Le formulaire affiche la raison en rouge. « Name is required. » veut dire que le **Nom du centre** est vide. À l’ajout d’un centre, le nom du prestataire et l’adresse sont aussi obligatoires.
??? L’écran affiche « Could not load centres ». | La liste n’a pas pu être chargée. Actualisez la page; si le problème revient, vérifiez votre connexion ou communiquez avec le soutien KiddieTrac.
??? Pourquoi je ne peux pas effacer la biographie du prestataire? | « The provider bio cannot be removed — families are sent it when they join. » Modifiez plutôt le texte.
??? Les départs tardifs ne sont jamais signalés pour un centre. | Il n’a pas d’heure **Ferme**. Sans heure de fermeture, rien ne peut être compté comme tardif. Ajoutez-en une sous **Jours d’ouverture**.
??? Un centre affiche Courriel désactivé. | Les courriels de ce centre sont désactivés : ses familles ne reçoivent aucun courriel de KiddieTrac. Réactivez l’interrupteur dans **⋮ > Modifier**.
??? Je ne vois pas Centres dans mon menu. | Seuls les administrateurs d’agence l’ont. Un directeur de centre s’adresse à son administrateur d’agence.

## Voir aussi

[[open: #dashboard | Aperçu de l’agence]] [[open: #provider-map | Carte des prestataires]] [[open: #provider-day | Aperçu quotidien]]

- [[kiosk-mode]]
- [[closing-a-provider-or-educator]]
- [[centres-or-rooms]]
- [[archived-records]]
- [[first-time-setup]]
