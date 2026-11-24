---
title: Paliers de rabais fratrie
category: Billing
order: 25
roles: agency_admin, platform_admin
---
# Paliers de rabais fratrie

Lorsqu’une famille a plus d’un enfant inscrit, vous accordez généralement un rabais pour le deuxième enfant et les suivants. Kiddietrac automatise cela dans le lot de factures mensuel — aucun calcul par famille n’est nécessaire.

## Comment fonctionnent les rabais fratrie

L’enfant inscrit **le plus tôt** dans une famille est de rang 1 — il paie toujours le plein tarif. Le deuxième plus ancien est de rang 2, le troisième de rang 3, et ainsi de suite. Chaque rang supérieur à 1 reçoit le palier de rabais correspondant le plus élevé que vous avez configuré.

Exemple de tableau de paliers :
- Rang 2 -> 10 % de rabais
- Rang 3 -> 15 % de rabais
- Rang 4 -> 20 % de rabais

Une famille de 4 enfants inscrits verrait :
- Enfant 1 : plein tarif
- Enfant 2 : 10 % de rabais (correspond au palier rang=2)
- Enfant 3 : 15 % de rabais (correspond au palier rang=3)
- Enfant 4 : 20 % de rabais (correspond au palier rang=4)

Une famille de 5 enfants : le cinquième enfant obtient aussi 20 % de rabais — le palier le plus élevé défini s’étend aux rangs supérieurs.

## Configurer les paliers

Barre latérale → **Paramètres → Rabais fratrie**. L’éditeur affiche les paliers actuels sous forme de lignes : rang + pourcentage + bouton Supprimer.

Cliquez sur **+ Ajouter un palier** pour ajouter une ligne. La suggestion par défaut est le prochain rang disponible avec 10 %. Modifiez le rang et le pourcentage selon vos besoins.

Cliquez sur **Enregistrer** pour confirmer. Les changements s’appliquent au PROCHAIN lot de factures — les factures déjà générées ne sont pas ajustées rétroactivement.

Configurations courantes :
- Prudente : rang 2 -> 5 %, rang 3 -> 10 %
- Standard : rang 2 -> 10 %, rang 3 -> 15 %, rang 4 -> 20 %
- Généreuse : rang 2 -> 15 %, rang 3 -> 25 %, rang 4 -> 35 %

## Désactiver les rabais

Supprimez toutes les lignes de paliers et cliquez sur Enregistrer avec une liste vide. Le système enregistre les rabais comme désactivés — les lots de factures suivants facturent le plein tarif pour chaque enfant.

## Sur la facture

Lorsque des rabais s’appliquent, vous verrez une ligne par enfant bénéficiant d’un rabais sur le PDF de la facture :

```
Tuition - Sienna Patel (Sunflower Room) ............. $1,400.00
Tuition - Theo Patel (Sunflower Room) ............... $1,400.00
Sibling discount (10%) - Theo ....................... -$140.00
```

Le `discount_amount` de l’en-tête de la facture reflète la somme de toutes les lignes de rabais, et le `total` indique le montant dû après rabais.

## Paramètre par agence

Chaque agence configure ses propres paliers indépendamment. Si vous changez d’agence avec le sélecteur et modifiez les paliers, seul le calcul des factures de cette agence est touché.
