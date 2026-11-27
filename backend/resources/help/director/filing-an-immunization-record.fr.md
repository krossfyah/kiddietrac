---
title: Classer un carnet de vaccination
---
# Classer un carnet de vaccination

L’écran **Vaccinations** montre quels enfants ont une dose en retard ou bientôt due, les
dossiers remis par les familles et le personnel, et le calendrier auquel chaque enfant est
comparé. Classer un carnet et consigner ce qu’il indique se fait en une seule étape : les
doses que vous cochez retirent l’enfant de la liste des retards. Ouvrez l’écran dans le menu
latéral : **Opérations** → **Vaccinations**.

[[open: #immunizations | Ouvrir les vaccinations]]

## Les quatre onglets

- **Dû à l’âge de** — tous les enfants, avec un statut calculé à partir de leur date de naissance selon votre calendrier.
- **En retard seulement** — la même liste, limitée aux enfants qui ont une dose en retard.
- **Dossiers reçus** — chaque dossier remis, marqué **Parent** ou **Personnel**, avec **Envoyé par**.
- **Calendrier par défaut** — ce à quoi chaque enfant est comparé. Changer une ligne recalcule le statut de chaque enfant. Voir [Calendrier de vaccination « Dû à l’âge de »](immunization-due-age).

## Classer un carnet pour un enfant

1. Appuyez sur **↑ Téléverser un dossier**. Le bouton est dans chaque onglet, et aussi dans le dossier de l’enfant → onglet **Vaccination**, où l’enfant est déjà choisi.
2. Choisissez l’enfant.
3. Ajoutez **le document** — un PDF ou une photo du carnet, d’au plus 10 Mo. Une photo prise avec un téléphone est normalement bien en dessous.
4. Sous **What does this record cover?**, cochez chaque dose indiquée sur le carnet, selon le calendrier de votre agence. La date est facultative : laissez-la vide si le carnet n’est pas clair, la dose compte quand même comme consignée.
5. Si le carnet indique un vaccin qui n’est pas dans votre calendrier (une famille qui a déménagé d’une autre province, un vaccin de voyage), ajoutez-le en dessous : saisissez le vaccin et le nom de la dose.
6. Ajoutez des **Notes** pour ce que le document ne dit pas : une ligne effacée, une dose que la clinique doit confirmer.
7. Appuyez sur **Classer le carnet**. Le statut de l’enfant se met à jour tout de suite.

Une dose déjà inscrite au dossier de l’enfant est **ignorée, pas dédoublée** : classer de
nouveau une photo plus claire du même carnet ne double jamais l’historique.

## Ce qui se passe quand vous le classez

- Le document est classé au dossier de l’enfant; il apparaît donc aussi dans son onglet **Documents**.
- Chaque dose cochée est consignée pour l’enfant, **liée au document d’où elle a été lue**.
- Si les doses cochées couvrent tout ce que le calendrier prévoit pour son âge, l’enfant n’est plus en retard.
- **Le bureau reçoit un courriel** : vos autres directeurs et les administrateurs de l’agence sont informés de l’enfant, de la personne qui a classé le carnet, des doses consignées, de celles ignorées parce que déjà connues, de vos notes et de **ce qui reste à recevoir ensuite**. Vous ne recevez pas de courriel pour votre propre classement.
- L’équipe est avisée dans l’application, et une entrée d’audit est créée **en nommant chaque dose**.

## Ajouter les détails d’un dossier reçu sans rien de consigné

La plupart des carnets viennent des parents. Un parent peut cocher ce qu’il pense que le carnet
indique, mais c’est seulement une piste pour vous — aucune dose n’est consignée. Tant que
quelqu’un au centre ne l’a pas lu, le dossier affiche **Détails en attente**, avec ce que la
personne qui l’a envoyé a indiqué, marqué *non confirmé*.

1. Trouvez-le sous **📄 Dossiers conservés** dans le dossier de l’enfant, ou sous **Immunizations → Records received** pour tous les enfants.
2. Appuyez sur **➕ Ajouter les détails**.
3. Choisissez de quel document il s’agit : **Doses administrées** (un carnet de vaccination ou un relevé de la clinique) ou **Formulaire d’exemption** (une déclaration de conviction ou une exemption médicale — voir [Exemptions de vaccination](immunization-exemptions)).
4. Pour des doses, cochez chaque dose indiquée, avec une date si le carnet en a une. Ce que la personne qui l’a envoyé a coché est précoché et marqué *uploader ticked this*; vérifiez chaque dose.
5. Appuyez sur **Enregistrer les doses** (ou **Enregistrer les exemptions**).

Une fois qu’un dossier a des doses consignées, **Ajouter les détails** n’est plus offert;
corrigez plutôt la dose dans le dossier de l’enfant.

[[show: #immunizations @ Records received | Me montrer les dossiers reçus]]

## L’onglet Vaccination de l’enfant

L’onglet Vaccination d’un enfant a deux sous-onglets : **💉 Ce qui est dû** (le calendrier et
ce qui reste à recevoir) et **📄 Dossiers conservés** (les carnets eux-mêmes, avec les doses
lues sur chacun et les notes). **Voir** ouvre un dossier dans le panneau de documents du
portail, avec **Imprimer** dans son en-tête — sans ouvrir de nouvel onglet. Voir
[Ouvrir et imprimer des documents](opening-and-printing-documents).

## Qui peut faire quoi

- Les **administrateurs de l’agence et les directeurs du centre** de l’enfant peuvent classer un carnet, consigner les doses qu’il indique, ajouter les détails d’un dossier reçu vide, et modifier ou supprimer une dose.
- Les **parents peuvent envoyer un dossier** et indiquer ce qu’ils pensent qu’il montre, sans plus. Lire une ligne floue comme « DCaT-VPI-Hib, 2e dose » est un jugement qui revient au centre.
- Les **éducateurs** ne gèrent pas les vaccinations; ils voient « Immunizations are managed by the centre director. »

## FAQ et dépannage

??? Un parent a envoyé un carnet, mais l’enfant est encore en retard. | Seules les doses consignées à partir du carnet retirent le signalement. Ouvrez-le sous Dossiers reçus, appuyez sur ➕ Ajouter les détails et consignez les doses.
??? Le message « No children to file against » s’affiche. | Aucun enfant inscrit n’a été trouvé pour cette agence. Vérifiez que les enfants sont inscrits et que vous êtes dans la bonne agence.
??? Le téléversement a échoué. | Le fichier doit être un PDF ou une photo d’au plus 10 Mo. Essayez une photo plus petite, ou actualisez la page et réessayez.
??? Le message « Vaccine name required » s’affiche. | Quand vous ajoutez un vaccin qui n’est pas dans votre calendrier, saisissez son nom avant d’enregistrer.
??? Puis-je supprimer un dossier ? | Un parent ne le peut pas. Le personnel retire un dossier classé dans l’onglet Documents de l’enfant, ce qui est audité. Corrigez une dose dans le dossier de l’enfant; le rapport de vaccination ne fait que lire.

## Liens utiles

[[open: #immunizations | Vaccinations]]
[[open: #reports | Rapports]]

- [Exemptions de vaccination](immunization-exemptions)
- [Rappels de vaccination](immunization-reminders)
- [Rapport de vaccination](immunization-report)
- [Calendrier de vaccination « Dû à l’âge de »](immunization-due-age)
- [Le dossier de l’enfant](child-record-tabs)
- [Ouvrir et imprimer des documents](opening-and-printing-documents)
