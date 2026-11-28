---
title: Retards de départ et frais
---
# Retards de départ et frais

Tout ce qui touche un départ en retard (ou une arrivée tardive) se trouve au même endroit. Le
personnel le consigne, les frais sont calculés pour vous, et un directeur ou un administrateur
décide s’ils sont facturés. Ouvrez l’écran dans le menu latéral : **Finances** → **Retards de départ**.

[[open: #late-pickups | Ouvrir les retards de départ]]

## Comment les frais sont calculés

Les frais suggérés sont un tarif par minute après l’heure de fermeture du centre, après
quelques minutes de grâce gratuites — par défaut **1,00 $ la minute après 5 minutes de grâce**.
Le formulaire indique le tarif utilisé, par exemple « (1,00 $/min après 5 min de grâce) ».
L’heure de fermeture de chaque centre est remplie par défaut quand vous consignez un retard.
Un administrateur d’agence règle le tarif et les minutes de grâce dans **Paramètres → Facturation → ⚙️ Valeurs par défaut et frais**,
dans la carte **Late pickup fee**. Un nouveau tarif s’applique aux retards consignés après l’enregistrement.

## Consigner un retard de ramassage

1. Appuyez sur **＋ Consigner un départ tardif**. La fenêtre **Consigner un retard de ramassage** s’ouvre.
2. Sous **Quoi**, laissez **Retard de ramassage**, ou choisissez **Arrivée tardive**.
3. Choisissez l’**Enfant**.
4. Pour un retard de ramassage, réglez **Parti à** et vérifiez **Centre fermé à** (rempli à partir du centre de l’enfant). Pour une arrivée tardive, saisissez les **Minutes de retard**.
5. Ajoutez des **Notes (optional)** au besoin. Le formulaire montre les minutes de retard et les **frais suggérés**, ou « dans le délai de grâce — aucuns frais ».
6. Appuyez sur **Consigner pour examen**. Le retard attend dans l’onglet **En attente** la décision d’un directeur ou d’un administrateur, qui reçoit un courriel. C’est la seule option pour les éducateurs.
7. Ou, si vous êtes directeur ou administrateur, appuyez sur **Consigner et facturer** pour consigner le retard et facturer les frais en une seule étape. Pour une arrivée tardive, on vous demande d’abord **How much to charge for this late arrival?**

Vous voyez **Consigné — en attente de la décision d’un directeur ou d’un administrateur**,
**Logged and charged …** ou **Consigné — dans le délai de grâce, aucuns frais** (rien à
facturer : le retard est inscrit comme annulé).

## Décider d’un retard en attente

1. Ouvrez l’onglet **En attente**. La pastille indique combien de retards attendent.
2. Sur la ligne, appuyez sur **Approuver et facturer**. Vérifiez les **Frais** (remplis avec la suggestion), ajoutez une **Note (facultatif)** et appuyez sur **Facturer les frais**. Les frais sont ajoutés à la facture ouverte de la famille, ou à sa prochaine facture s’il n’y en a pas d’ouverte, et les parents sont avisés.
3. Ou appuyez sur **Annuler les frais** — « Il reste au dossier; rien n’est facturé. » Ajoutez une note et appuyez sur **Annuler les frais**.
4. Ou appuyez sur **Refuser** — « Inscrit comme non facturable — à utiliser quand il n’aurait pas dû être consigné. » Ajoutez une note et appuyez sur **Refuser**.

Une décision ne peut être prise qu’une seule fois.

## La liste

Utilisez les onglets **En attente**, **Facturé**, **Annulé (exonéré)**, **Refusé** et **Tous**.
Chaque ligne montre l’**Enfant** (avec la famille et le centre), **Quoi**, **Ramassage / arrivée**
(avec l’heure de fermeture), **En retard de**, **Suggéré**, **Statut**, **Frais facturés**,
**Consigné**, **Consigné par**, **Décidé**, la **Facture** où les frais ont été ajoutés (ou
**pas encore facturé**) et les **Notes**. Utilisez les boutons d’exportation sous le tableau
pour Excel, CSV ou PDF.

## Courriels

Les directeurs du centre et les administrateurs de l’agence reçoivent un courriel quand un
retard est consigné pour examen, et quand des frais sont consignés et facturés en une seule
étape. Les parents sont avisés quand des frais sont facturés; rien ne leur est envoyé quand un
retard est consigné, annulé ou refusé.

## Qui peut faire quoi

- Les **administrateurs de l’agence** voient tous les centres, consignent, facturent, approuvent, annulent les frais et refusent.
- Les **directeurs de centre** font de même pour leurs propres centres.
- Les **éducateurs** consignent les retards de ramassage et les arrivées tardives pour examen seulement. Ils ne voient pas **Consigner et facturer** ni les boutons de décision.
- Les **parents** voient les frais facturés sur leur facture.

## FAQ et dépannage

??? Le message « The pickup time must be after the close time. » s’affiche. | L’heure de départ doit être plus tardive que l’heure de fermeture du centre. Vérifiez les deux, y compris la date.
??? Le message « Enter a fee, or use Waive instead. » s’affiche. | Approuver et facturer exige des frais de plus de 0 $. Pour l’inscrire sans facturer, utilisez Annuler les frais.
??? Les frais ont été facturés, mais la colonne Facture indique « pas encore facturé ». | La famille n’avait pas de facture ouverte. Les frais sont consignés et iront sur sa prochaine facture.
??? Je ne vois pas Approuver et facturer. | Seuls les directeurs et les administrateurs de l’agence décident. Les éducateurs consignent pour examen.
??? L’onglet En attente était vide, alors l’écran a affiché Tous. | Quand rien n’attend, l’écran s’ouvre sur Tous pour que vous voyiez votre historique.

## Liens utiles

[[open: #late-pickups | Retards de départ]]
[[open: #safe-arrival | Safe Arrival]]

- [Frais de retard](late-fees)
- [Produire les factures mensuelles](generating-monthly-invoices)
- [Safe Arrival — chaque enfant prévu, bien arrivé](safe-arrival)
