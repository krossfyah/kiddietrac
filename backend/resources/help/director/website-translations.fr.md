---
title: Traductions du site Web (français et espagnol)
category: Marketing
order: 31
roles: agency_admin, platform_admin
---
# Traductions du site Web (français et espagnol)

> **Remarque :** Cet écran s’adresse à l’équipe de la plateforme KiddieTrac. Les administrateurs d’agence n’y ont pas accès.

**Site web → Traductions.** Vous y révisez le texte français et espagnol que
www.kiddietrac.com a traduit lui-même.

[[show: #marketing-site @ Translations | Show me the Translations tab]]

## Comment le site se traduit lui-même

Le site public est livré avec un dictionnaire français et espagnol vérifié à la main. Le texte ajouté
par la suite, comme un nouvel article de blogue ou un titre modifié, n’a pas encore de traduction.

- La première fois qu’un visiteur francophone ou hispanophone voit ce texte, la page le signale.
- Le serveur vérifie que le texte figure bien sur le site publié, le traduit
  **une seule fois** et l’enregistre.
- La traduction **est mise en ligne immédiatement** pour tous les visiteurs suivants. Elle
  n’attend pas de révision. Cet onglet sert à la vérifier après coup.

Le français est rédigé en français canadien (fr-CA). L’espagnol est rédigé en espagnol
latino-américain neutre.

> **Remarque :** Cet onglet n’affiche pas le dictionnaire vérifié à la main livré avec le site. Il n’affiche que le texte traduit automatiquement par la suite.

## La bannière en haut

La bannière colorée indique si la traduction automatique fonctionne en ce moment. Lisez-la
en premier.

- **Verte, « La traduction automatique est activée. »** Le nouveau texte est traduit à l’arrivée
  des visiteurs.
- **Rouge.** La traduction automatique ne fonctionne pas. La bannière en indique la **raison**, par exemple aucune
  clé d’API définie sur le serveur ou échec de la dernière traduction, ainsi que le moment où cela s’est produit.
  Tant que ce n’est pas corrigé, le **nouveau** texte s’affiche en **anglais** pour les visiteurs francophones et hispanophones.
  Les traductions que vous avez déjà continuent de fonctionner.

La vérification derrière la bannière est un véritable test auprès du service de traduction, et non le souvenir
de la dernière tentative. Elle est actualisée environ toutes les 10 minutes; après une correction,
la bannière peut donc rester rouge quelques minutes.

> **Astuce :** Si des visiteurs francophones ou hispanophones voient de l’anglais, ouvrez d’abord cet onglet. La bannière vous indique si la traduction fonctionne et, sinon, pourquoi.

## Trouver une traduction

Trois filtres se trouvent au-dessus de la liste :

- **À réviser — automatique** (par défaut), **Modifié** ou **Tout**. Le nombre à côté de chacun
  en est le total.
- **Toutes les langues**, **Français** ou **Español**.
- **Rechercher dans l’anglais ou la traduction** cherche dans les deux colonnes.

Chaque entrée indique la langue, le type de texte (**Texte de page**, **Étiquette** ou
**Article de blogue**), s’il est **Automatique** ou **Modifié**, et la date de la dernière modification.
L’anglais se trouve au-dessus, et la traduction se trouve dans une zone modifiable en dessous.

La liste affiche les 500 entrées les plus récentes. Utilisez la recherche pour restreindre les résultats.

## Approuver ou corriger une entrée

- **Approuver** (sur une entrée automatique) : appuyez dessus pour accepter la traduction telle quelle, ou
  corrigez d’abord la zone de texte, puis appuyez dessus. Dans les deux cas, l’entrée devient **Modifié**
  et quitte la file de révision.
- **Enregistrer** (sur une entrée modifiée) : enregistre les changements supplémentaires.

Une modification enregistrée est **en ligne en quelques minutes**. Une traduction **Modifié** n’est jamais
écrasée par le traducteur automatique. Votre correction est définitive.

> **Important :** Conservez exactement, comme dans l’anglais, les marqueurs `{0}…{/0}` et les balises HTML. Ils représentent les liens, les mots en gras et les icônes. Un enregistrement qui les modifie est refusé, et la raison vous est indiquée.

## Supprimer une entrée

**Supprimer** retire la traduction. Le site demande alors une **nouvelle traduction automatique**
la prochaine fois qu’un visiteur voit ce texte. Utilisez cette option lorsqu’une traduction est si erronée
que repartir de zéro est plus rapide que la corriger.

Pendant que la traduction automatique est suspendue (bannière rouge), supprimer une entrée signifie que ce texte
s’affiche en anglais jusqu’au rétablissement de la traduction.

## Questions courantes

??? Pourquoi un nouvel article de blogue est-il encore en anglais pour les visiteurs francophones? | Soit personne ne l’a encore consulté en français, soit la bannière est rouge et la traduction est suspendue.
??? Ma correction sera-t-elle remplacée plus tard? | Non. Une traduction modifiée n’est jamais écrasée.
??? Puis-je traduire un texte qui n’est pas encore sur le site? | Non. Seul le texte qui figure sur le site publié est traduit.

Voir aussi : [Abonnés du site Web](website-subscribers).
