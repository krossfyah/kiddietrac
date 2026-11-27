---
title: Campagnes automatisées (goutte-à-goutte)
---
# Campagnes automatisées (goutte-à-goutte)

**Engagement › Drip campaigns** envoie automatiquement un courriel de suivi un certain nombre de jours après un
événement — la visite d'une famille, l'inscription d'un enfant, son anniversaire ou une famille qui ne donne plus
signe de vie. Réglez-la une fois et chaque famille qui arrive à ce moment reçoit le courriel, sans oubli : un mot de
remerciement le lendemain d'une visite, une trousse d'accueil trois jours après l'inscription.

[[open: #drip-campaigns | Ouvrir les campagnes automatisées]]

## Créer une campagne automatisée

1. Ouvrez **Engagement › Drip campaigns**. La bannière indique **💧 Drip campaigns**.
2. Appuyez sur **+ New drip campaign**.
3. Entrez un **Title** que votre équipe reconnaîtra (« Merci pour la visite »).
4. Choisissez le **Trigger event** : **After tour booked**, **After enrollment complete**, **On child's birthday** ou **After 30 days inactive**.
5. Entrez le **Delay (days)** — combien de jours après l'événement envoyer (0 envoie le jour même).
6. Rédigez l'**Email subject** et l'**Email body (HTML allowed)**. Tapez **{first_name}** là où vous voulez le prénom du parent.
7. Appuyez sur **Create**. La campagne apparaît dans la liste comme active.

> 💡 Pour **After tour booked**, le délai compte à partir de la date de la visite : un délai de 1 envoie le lendemain.

## Vérifier que ça fonctionne

1. Regardez les tuiles du haut : **Active drip campaigns**, **Sent (all time)**, **Queued** et **Failed**.
2. Lisez la liste : le **Title**, le **Trigger**, le **Delay** et le **Status** de chaque campagne.
3. Si **Failed** augmente, vérifiez les adresses courriel dans les dossiers des familles.

KiddieTrac vérifie chaque heure quelles familles ont atteint un déclencheur, et envoie chaque campagne une seule fois
à chaque personne — jamais deux fois pour la même visite, le même enfant ou la même famille.

## Qui la reçoit

- **After tour booked** — l'adresse donnée lors de la réservation de la visite.
- **After enrollment complete** et **On child's birthday** — le courriel principal de la famille, pour les enfants encore inscrits.
- **After 30 days inactive** — les familles qui n'ont pas utilisé le portail depuis 30 jours.

Les règles habituelles s'appliquent : les familles parties et les comptes fermés ne reçoivent rien.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d'agence | Créer des campagnes automatisées et voir leurs chiffres. |
| Administrateur de plateforme | L'agence choisie dans le sélecteur d'agence. |
| Directeur de centre, éducatrice | Non disponible. |

## Questions fréquentes et problèmes

??? Comment modifier ou arrêter une campagne automatisée? | Il n'y a pas encore de bouton pour modifier, suspendre ou supprimer sur cet écran. Ouvrez un billet dans l'Aide et le soutien KiddieTrac la modifiera ou l'arrêtera pour vous.
??? Une famille n'a rien reçu. | Vérifiez la date du déclencheur (par exemple la date de visite ou d'inscription au dossier), le délai et que la famille a un courriel au dossier. L'envoi se fait chaque heure : comptez une heure.
??? Puis-je prévisualiser le courriel? | Pas ici. Rédigez-le d'abord comme campagne dans **Marketing › Campaigns**, utilisez **👁 Preview**, puis collez le texte ici.
??? Est-ce que {first_name} fonctionne? | Oui. Il devient le prénom de la personne si l'adresse appartient à un compte, sinon « there ».

## Écrans connexes

[[open: #marketing-campaigns | Campagnes]] [[open: #tours | Visites]] [[open: #engagement | Score d'engagement]]

Voir aussi [[marketing-campaigns]] et [[tour-bookings]].
