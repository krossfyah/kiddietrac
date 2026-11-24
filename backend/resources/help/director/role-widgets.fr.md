---
title: Widgets du tableau de bord selon le rôle
category: Getting Started
order: 12
---
# Les widgets de rôle sur votre tableau de bord

Lorsque vous chargez le tableau de bord, une rangée de cartes d’indicateurs apparaît en haut de la vue d’accueil de votre rôle (juste sous la bannière). Les cartes dépendent du rôle : elles affichent les chiffres les plus pertinents pour votre travail de tous les jours.

## Ce que voit chaque rôle

**Tuteur / parent (4 cartes) :**
- État de l’arrivée d’aujourd’hui (arrivé / parti / pas encore)
- Solde de la famille en souffrance
- Observations partagées avec vous cette semaine
- Nombre d’enfants au dossier

**Éducateur (4 cartes) :**
- Enfants présents maintenant / total des inscrits à votre centre
- Médicaments administrés aujourd’hui
- Observations que vous avez consignées cette semaine
- Total des inscrits à votre centre

**Directeur de centre (4 cartes) :**
- Capacité en % aujourd’hui (codée par couleur : vert / ambre / rouge)
- Montant des factures ouvertes + nombre
- Enfants non arrivés à 9 h 30 (masqué avant l’heure limite)
- Total des inscrits

**Administrateur d’agence / administrateur de la plateforme** : vous avez déjà un tableau de bord plus riche couvrant toute l’agence; les widgets de rôle sont donc ignorés en silence.

## Comment ils se mettent à jour

Les cartes se rechargent depuis `/widgets/me` chaque fois que vous chargez un hash de type tableau de bord (`#dashboard`, `#today`, etc.). Elles ne s’actualisent pas automatiquement pendant que vous restez sur la page : actualisez le navigateur pour obtenir des chiffres à jour.

## Pourquoi certaines cartes affichent « — »

Agence vide, aucun enfant inscrit, ou aucune attribution de rôle. Les cartes s’affichent toujours; le tiret de remplacement signifie « aucune donnée, pas un bogue ».
