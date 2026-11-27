---
title: RH : configurer les types de congé
---
# RH : configurer les types de congé

**Ressources humaines > Congés > Types de congé** est l'endroit où un administrateur de l'agence configure les sortes de congé de votre équipe (vacances, congés de maladie, congés personnels…), combien chaque personne en reçoit, comment ils s'accumulent et ce qui est reporté. Ce sont les règles de votre agence : KiddieTrac n'applique aucune loi du travail provinciale ou d'un État à votre place. Faites-le une fois en commençant avec les RH, puis chaque fois que votre politique de congés change.

[[open: #time-off?tab=types | Ouvrir Types de congé]]

## Partir de l'exemple

1. Ouvrez **Ressources humaines > Congés > Types de congé**.
2. Appuyez sur **Charger l'exemple**.
3. Lisez le message **Load the example set?** et appuyez sur **Load examples**. Vacances, Congés de maladie, Congés personnels, Deuil et Congé sans solde sont ajoutés avec des montants d'exemple, chacun marqué **Exemple**. Les noms que vous utilisez déjà sont sautés.
4. Appuyez sur **⋮ > Edit** pour chacun et remplacez chaque valeur par votre propre politique.

> ⚠️ Les montants d'exemple ne sont les minimums d'aucune loi. Rien ne s'applique jamais tout seul.

## Ajouter un type de congé

1. Appuyez sur **Ajouter un type de congé**.
2. Inscrivez le **Nom** (par exemple Vacances) et choisissez une **Couleur**.
3. Choisissez **Compté en** : **Jours** ou **Heures**.
4. Choisissez **Paie** : **Payé** ou **Impayé**.
5. Choisissez **Staff can see and request it** : **Oui**, ou **No, managers only**.
6. Choisissez **Approbation** : **Approbation requise**, ou **No approval: recorded at once, manager reviews it**.
7. Choisissez **Payé au départ** (par défaut, seuls les vacances et la banque d'heures supplémentaires sont payés).
8. Choisissez le **Mode d'accumulation** (voir plus bas) et ce qui arrive **At the end of the leave year** (voir Report).
9. Si votre équipe utilisait l'ancien formulaire de congé, réglez **Anciennes demandes classées comme** (par exemple Vacances ou Maladie).
10. Appuyez sur **Enregistrer**. La fenêtre **Règles d'admissibilité** s'ouvre ensuite pour indiquer combien chacun reçoit.

> ℹ️ Un type sans approbation est enregistré et retiré du solde dès la demande. Les gestionnaires le voient quand même **À examiner** dans **Congés** jusqu'à ce que l'un d'eux appuie sur **Révisé**, ce qui ne change rien au solde.

## Comment les congés s'accumulent

- **Yearly, all at once** : au début de l'année de congé.
- **Each pay period** : choisissez **Pay periods a year** (12, 24, 26 ou 52).
- **By hours worked** : selon le pointage; réglez **Hours worked to earn 1 unit**, jusqu'au montant annuel.
- **Non suivi** : les demandes sont enregistrées, mais il n'y a pas de solde.

Une personne embauchée en cours d'année de congé reçoit une part de l'octroi annuel pour les jours restants, arrondie à la demi-journée (au quart d'heure pour les types en heures).

## Régler les règles d'admissibilité

1. Sur la ligne du type, appuyez sur **⋮ > Règles d'admissibilité**.
2. Dans la première ligne, choisissez le **Type d'emploi** (ou **Tout le monde**), le **After years of service** et le **Amount a year**.
3. Appuyez sur **Add a rule** pour chaque palier, par exemple 10 jours pour tout le monde, 15 jours après 5 ans.
4. Appuyez sur **Supprimer** sur une ligne inutile.
5. Appuyez sur **Enregistrer les règles**.
6. Appuyez sur **Appliquer les octrois et cumuls maintenant** pour les appliquer aujourd'hui. Ils roulent aussi chaque nuit.

La règle avec le plus d'années de service atteintes s'applique, et une règle pour le type d'emploi de la personne l'emporte sur une règle pour **Tout le monde**.

## Report

1. Dans **⋮ > Edit**, choisissez **At the end of the leave year** : **Aucun**, **Tout** ou **Up to a limit**.
2. Pour **Up to a limit**, inscrivez la **Carry-over limit**.
3. Pour que les congés reportés expirent, réglez **Carried balance expires after (months)**. Laissez vide pour **Never**.
4. Appuyez sur **Enregistrer**.

## Comment les jours sont comptés

Les demandes comptent les jours ouvrables. Les fins de semaine sont sautées, tout comme les jours fériés et les fermetures au calendrier du centre de la personne. Une fermeture créée pour le congé de la personne elle-même, ou une journée pédagogique, compte quand même. Pour déduire une fermeture d'hiver des congés, voir [[hr-closures-leave]].

## Compter les demandes faites avant les RH

1. Réglez **Anciennes demandes classées comme** sur le type et appuyez sur **Enregistrer**.
2. Configurez ses règles d'admissibilité.
3. Sur la ligne, appuyez sur **⋮ > Count this year's approved requests**, puis sur **Count them**. Les demandes déjà approuvées cette année de congé sont retirées des soldes, une seule fois. Les demandes elles-mêmes ne changent jamais.

## Archiver un type

Appuyez sur **⋮ > Archiver**. Le type n'est plus offert, et les soldes sont gardés. **⋮ > Restaurer** le ramène.

## Qui peut faire quoi

- **Administrateurs de l'agence** : configurent les types et les règles. Cet onglet n'est montré qu'à eux.
- **Directeurs de centre** : voient le résultat dans **Soldes** et approuvent les demandes, mais ne peuvent pas changer les types.
- **Personnel** : choisit les types marqués visibles sur son formulaire de congé et voit son solde dans **Mes RH**.

## FAQ et quand quelque chose ne va pas

??? J'ai ajouté une règle, mais les soldes n'ont pas changé. | Appuyez sur **Appliquer les octrois et cumuls maintenant**, ou attendez la mise à jour de la nuit. Les octrois déjà donnés ne changent jamais; ajoutez un ajustement dans **Soldes** pour une différence passée.
??? Le personnel ne voit pas un type de congé sur son formulaire. | Modifiez le type et réglez **Staff can see and request it** à **Oui**. Les types archivés ne sont jamais offerts.
??? « Give it a name. » | Le champ **Nom** est vide. Inscrivez un nom et appuyez de nouveau sur **Enregistrer**.
??? Où régler le début de l'année de congé, ou si les demandes peuvent passer sous zéro? | Dans **Ressources humaines > Paramètres RH** : **Début de l'année de congé** et **Demandes qui passent sous zéro** (**Refuse them** ou **Allow them, with a warning**).
??? Le personnel est-il avisé quand je change un type? | Non. Aucun courriel n'est envoyé. Chacun voit son nouveau solde dans **Mes RH**.

## Écrans liés

[[open: #time-off?tab=balances | Soldes]] [[open: #time-off | Demandes de congé]] [[open: #hr-settings | Paramètres RH]]

- [[hr]] — personnes, dossiers et soldes
- [[hr-closures-leave]] — fermetures déduites des congés et prorata à temps partiel
- [[time-off]] — demander et approuver un congé
