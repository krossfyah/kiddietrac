---
title: Campagnes automatisées (goutte-à-goutte)
---
# Campagnes automatisées (goutte-à-goutte)

**Engagement › Drip campaigns** envoie automatiquement un courriel de suivi un certain nombre de jours après un
événement : la visite d'une famille, l'inscription d'un enfant ou son anniversaire. Réglez-la une fois et chaque
famille qui arrive à ce moment reçoit le courriel, sans oubli : un mot de remerciement le lendemain d'une visite, une
trousse d'accueil trois jours après l'inscription.

[[open: #drip-campaigns | Ouvrir les campagnes automatisées]]

## Créer une campagne automatisée

1. Ouvrez **Engagement › Drip campaigns**. La bannière indique **💧 Drip campaigns**.
2. Appuyez sur **+ New drip campaign**. Une fenêtre s'ouvre.
3. Inscrivez un **Title** que votre équipe reconnaîtra (« Merci pour la visite »).
4. Choisissez **Starts when** : **After a tour is booked**, **After enrolment is complete** ou **On a child's birthday**.
5. Inscrivez **Days after (0 = the same day)**.
6. Choisissez le **Centre**. Un administrateur d'agence peut choisir **All centres**; un directeur choisit un de ses propres centres.
7. Rédigez l'**Email subject** et l'**Email text**. Tapez **{first_name}** là où vous voulez le prénom du parent.
8. Appuyez sur **Create**. La campagne apparaît dans la liste avec le statut **Running**.

> 💡 Pour **After a tour is booked**, les jours comptent à partir de la date de la visite : 1 envoie le lendemain.

## Modifier, mettre en pause ou supprimer une campagne

Ouvrez le menu **⋮** sur la ligne de la campagne.

1. **View** montre le statut, ce qui la déclenche, le centre, le nombre d'envois et le courriel lui-même.
2. **Edit** ouvre la même fenêtre. Changez le titre, les jours, le centre, l'objet ou le texte et appuyez sur **Save**.
3. **⏸ Pause** arrête les envois. **▶ Resume** les reprend.
4. **🗑 Delete** demande une confirmation, puis la campagne s'arrête et quitte la liste.

> ℹ️ Un changement ne touche que les personnes à venir. Personne qui a déjà reçu le courriel ne le reçoit de nouveau,
> même après une modification. Une fois qu'une campagne a envoyé, **Starts when** ne peut plus changer : créez-en une nouvelle.

> ℹ️ Pendant une pause, rien n'est rattrapé ensuite : les familles dont le moment est passé pendant la pause ne la reçoivent pas.

## Vérifier qu'elle fonctionne

1. Regardez les tuiles du haut : **Running**, **Sent (all time)**, **Waiting to send** et **Failed**.
2. Chaque ligne montre **Sent**, **Failed** et **Last sent** pour cette campagne.
3. Si **Failed** augmente, vérifiez les adresses courriel aux dossiers des familles.

KiddieTrac vérifie chaque heure les familles qui ont atteint un déclencheur et envoie chaque campagne une seule fois à chaque personne.

## Qui la reçoit

- **After a tour is booked** : l'adresse courriel donnée à la réservation de la visite.
- **After enrolment is complete** et **On a child's birthday** : le courriel principal de la famille, pour les enfants encore inscrits.
- Une campagne pour un centre ne joint que les familles de ce centre.

Les règles habituelles s'appliquent : les familles parties et les comptes fermés ne reçoivent rien, et les agences de test n'envoient rien.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d'agence | Créer, modifier, mettre en pause et supprimer toutes les campagnes, y compris **All centres**. |
| Directeur de centre | Créer, modifier, mettre en pause et supprimer les campagnes de ses propres centres; voir celles de toute l'agence. |
| Super administrateur | L'agence choisie dans le sélecteur d'agence. |
| Éducatrice ou éducateur | Non disponible. |

## Questions fréquentes et problèmes

??? Comment arrêter une campagne un moment? | Ouvrez le menu **⋮** de la ligne et choisissez **⏸ Pause**. Choisissez **▶ Resume** pour la relancer.
??? J'ai supprimé une campagne par erreur. | La campagne et son historique d'envois sont conservés. Ouvrez un billet à partir de l'Aide et le soutien KiddieTrac pourra la rétablir.
??? Une famille n'a rien reçu. | Vérifiez la date du déclencheur (par exemple la date de la visite ou de l'inscription au dossier), les jours, que la campagne est **Running** et que la famille a un courriel au dossier. Les envois se font chaque heure, attendez donc une heure.
??? Est-ce que {first_name} fonctionne? | Oui. Il devient le prénom de la personne quand l'adresse appartient à un compte, sinon « there ».

## Écrans connexes

[[open: #marketing-campaigns | Campagnes]] [[open: #tours | Visites]] [[open: #engagement | Score d'engagement]]

Voir aussi [[marketing-campaigns]] et [[tour-bookings]].
