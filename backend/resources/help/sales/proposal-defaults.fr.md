---
title: Conditions et valeurs par défaut des propositions
category: Proposals
order: 3
---

# Conditions et valeurs par défaut des propositions

Définissez une seule fois ce avec quoi chaque nouvelle proposition démarre : sa durée de validité, les conditions, les
taxes et le contact des ventes. N’importe qui dans l’équipe des ventes peut les modifier.

**Où :** barre latérale → **Forfaits et tarifs** → la carte **📄 Conditions et valeurs par défaut des propositions**, sous
vos forfaits et rabais.

[[open: #sales-plans | Ouvrir Forfaits et tarifs]]

## Ce que vous pouvez définir

- **Les propositions sont valides pendant … jours** — la date **Valide jusqu’au** d’une nouvelle proposition correspond à aujourd’hui plus
  ce nombre de jours (90 au départ).
- **Contact « Préparé par »** — le **Titre / équipe**, le **Courriel des ventes** et le **Téléphone des ventes**
  affichés sous le nom du représentant sur chaque proposition. Les réponses au courriel de la proposition sont envoyées à ce
  courriel; utilisez donc la boîte de réception partagée des ventes.
- **Conditions de la proposition (courtes, sous les prix)** — saisissez `{days}` et il sera remplacé par le
  nombre de jours de validité, par exemple *Les prix sont valides pendant {days} jours.*
- **Conditions générales (page distincte, avant les signatures)** — les conditions complètes. Commencez une
  ligne par `##` pour un titre ou par `-` pour une puce.
- **Taxes par défaut** — appuyez sur **+ Ajouter une taxe** pour chaque taxe que la plupart des propositions comportent (nom et taux,
  jusqu’à 5).

Appuyez sur **Enregistrer** lorsque vous avez terminé.

## Comment les valeurs par défaut sont utilisées

1. Un représentant appuie sur **+ Nouveau** dans l’onglet **📄 Propositions** d’un prospect.
2. Le générateur démarre avec ces valeurs par défaut, plus le nom du représentant dans **Préparé par**.
3. Le représentant peut modifier tout cela pour cette seule proposition.

Chaque proposition conserve sa propre copie. Modifier les valeurs par défaut ne change jamais une proposition déjà
existante; ce qu’un prospect a signé reste donc exactement tel qu’il l’a vu.

> **Astuce :** Gardez pour courriel des ventes une boîte de réception partagée plutôt que l’adresse d’une seule personne. Les réponses des prospects et les copies signées y sont envoyées.

??? J’ai modifié les conditions. Pourquoi une ancienne proposition affiche-t-elle encore l’ancien libellé? | Les propositions conservent le libellé avec lequel elles ont été créées. Modifiez cette proposition pour le changer, à moins qu’elle n’ait déjà été signée.
??? Une proposition peut-elle avoir des conditions différentes? | Oui. Modifiez les Conditions de la proposition ou les Conditions générales dans le générateur de cette proposition.
