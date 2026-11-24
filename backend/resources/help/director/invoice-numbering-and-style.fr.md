---
title: Style et numérotation des factures
category: Billing
order: 16
roles: agency_admin, platform_admin
---
# Style et numérotation des factures

**Image de marque → 🧾 Factures.** C’est ici que vous choisissez l’apparence de vos factures et leur numérotation. Cet écran se trouve à côté de votre logo et de la couleur de votre marque, car, pour une famille, la facture est votre marque.

[[open: #admin-branding?tab=invoices | Ouvrir Image de marque → Factures]]

**Pour y accéder :** les administrateurs d’agence vont à **Aperçu de l’agence → ✏️ Modifier l’agence → Ouvrir Image de marque →**. Les administrateurs de la plateforme peuvent aussi utiliser **Revendeur → Image de marque**. Dans les deux cas, l’écran s’applique à l’agence que vous consultez. Image de marque s’ouvre sur son premier onglet; choisissez **🧾 Factures** dans la rangée d’onglets en haut.

## Style de facture

Choisissez un style dans la liste **Style de facture**. Une courte description du style apparaît sous la liste, et l’**Aperçu de la facture en direct** à droite passe aussitôt à ce style, ce qui vous permet de le voir **avant** d’enregistrer.

Il y a douze styles :

- **KiddieTrac (par défaut)** : la facture KiddieTrac standard.
- **iLearn** : la mise en page iLearn, pour les agences dont les familles reçoivent déjà ce document.
- **Classic Navy**, **Minimal Mono**, **Modern Teal**, **Bold Slate**, **Warm Sand**, **Sunrise**, **Forest**, **Plum Rail**, **Compact Receipt** et **Corporate Grey**.

Ce sont des mises en page différentes, et non une même mise en page en différentes couleurs. Chaque style porte toujours **votre propre logo, vos coordonnées d’entreprise et votre adresse de soutien** : le style détermine la forme du document, et la marque qu’il contient reste la vôtre.

Cliquez sur **Enregistrer les modifications** pour l’appliquer. Le bouton enregistre ensemble les onglets **Marque blanche et logo**, **Factures** et **Coordonnées et mentions légales**.

> **Important :** Le style est appliqué lorsqu’une facture est **dessinée**, et non lorsqu’elle est émise. Après un changement, toutes les factures, passées et futures, utilisent le nouveau style la prochaine fois qu’elles sont consultées, téléchargées ou envoyées par courriel. Les copies déjà dans la boîte de réception d’une famille ne changent pas. Une copie d’une ancienne facture que vous renvoyez ne correspondra donc pas à celle que la famille a reçue au départ.

## Numérotation des factures

Vous pouvez définir le format de vos numéros de facture et le point de départ d’un numéro séquentiel. Choisissez l’un des modèles dans **Partir d’un modèle…**, ou saisissez votre propre format dans la zone.

Un format est du texte ordinaire avec des jetons entre accolades. Tout ce qui n’est pas un jeton est imprimé exactement tel que saisi; `ILH-{YYYY}-{SEQ}` est donc un format valide.

| Jeton | Devient |
|---|---|
| `{YYYY}` | l’année, p. ex. 2026 |
| `{YY}` | l’année abrégée, p. ex. 26 |
| `{MM}` | le mois, p. ex. 10 |
| `{DD}` | le jour |
| `{FAMILY}` | le numéro de compte de la famille, quatre chiffres |
| `{N}` | le numéro de versement dans un calendrier ou un plan, deux chiffres |
| `{SEQ}` | un numéro séquentiel pour votre agence |

**Le prochain numéro de facture ressemblera à** affiche un exemple construit à partir de votre format pendant que vous saisissez. Il est produit par le même code qui numérote les vraies factures, il correspond donc à ce que vous obtiendrez.

**Les dates sont celles de la période facturée, et non le jour d’envoi de la facture.** Un versement dû en décembre est une facture de décembre, même s’il est émis à la fin de novembre.

### Le numéro séquentiel, {SEQ}

- **Commencer la numérotation à** définit la première valeur de `{SEQ}`. La valeur par défaut est **1001**.
- Le nombre de chiffres de départ est conservé, sans zéros ajoutés devant. Si vous commencez à 1001, le suivant est 1002. Si vous commencez à 5000, le premier est 5000.
- Vous pouvez plus tard **augmenter** le point de départ pour faire avancer le compteur. Vous ne pouvez **jamais le réduire** : cela réémettrait des numéros que de vraies factures portent déjà.
- Le compteur n’est utilisé que si votre format comprend `{SEQ}`; un format fondé sur la date et la famille ne consomme donc pas de numéros.

### Bon à savoir

- `{FAMILY}` est un numéro de compte interne. Ce **n’est pas** l’identifiant de référence de la famille (comme ILH-F-0017), et il n’a pas changé. Chaque style de facture imprime aussi l’identifiant de référence de la famille à côté du nom du destinataire de la facture; vous n’en avez donc pas besoin dans le numéro de facture. Voir [Identifiants de référence](reference-ids).
- Le format par défaut est `INV-{YYYY}{MM}-{FAMILY}-{N}`, par exemple **INV-202610-0081-01**. Si vous ne changez jamais ce paramètre, vos numéros ressemblent exactement à ce qu’ils ont toujours été.
- Un nouveau format ne s’applique qu’aux **nouvelles** factures. Les factures existantes gardent les numéros avec lesquels elles ont été émises.
- Deux factures ne peuvent pas partager un numéro. Si un format produit un numéro qui existe déjà, un suffixe comme **-2** est ajouté.
- Un jeton mal orthographié, comme `{MONTH}`, est supprimé plutôt qu’imprimé sur la facture d’une famille. Vérifiez l’exemple avant d’enregistrer.
- Les factures synchronisées depuis un système de facturation externe conservent les numéros propres à ce système.

## Questions fréquentes

??? Quelles factures utilisent ma numérotation? | Les factures créées dans KiddieTrac : lots mensuels, versements des calendriers de paiement et factures de plans de frais.
??? Pourquoi mon nouveau numéro de départ est-il ignoré? | Le compteur ne recule jamais. S’il a déjà dépassé le numéro que vous avez saisi, il continue là où il est rendu.
??? Puis-je prévisualiser un style sans le changer pour tout le monde? | Oui. Le choix d’un style met l’aperçu à jour immédiatement. Rien ne change tant que vous ne cliquez pas sur Enregistrer les modifications.

Voir aussi : [Marque blanche, image de marque et factures](white-label-branding) · [Identifiants de référence](reference-ids) · [Configuration et paramètres de facturation](billing-setup) · [Calendriers de paiement](payment-plans)
