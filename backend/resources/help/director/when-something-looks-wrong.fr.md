---
title: Quand quelque chose semble anormal
category: Troubleshooting
order: 1
---

# Quand quelque chose semble anormal

Un guide rapide pour les situations « tiens, c’est bizarre » les plus courantes.

## « Le personnel sur place est à 0 mais mes éducateurs sont là »

Vos éducateurs ne sont pas pointés. Ils doivent :

1. Ouvrir Kiddietrac sur la tablette
2. Depuis la vue de la salle, toucher leur nom ou le bouton **Pointer l’arrivée**

Vous pouvez aussi les pointer à leur place depuis votre compte de directeur — allez à **Personnel → [nom de l’éducateur] → Pointer l’arrivée**.

## « Une salle affiche BREACH mais mes chiffres semblent corrects »

Vérifiez qui est réellement pointé. Consultez **Personnel → Horaire** pour aujourd’hui. Si vous voyez BREACH :

- Le ratio cible est peut-être trop strict (vous pouvez ajuster le ratio de la salle s’il ne correspond pas aux normes de l’Ontario)
- Un éducateur est peut-être affecté à une autre salle par un quart de travail
- Des enfants sont peut-être enregistrés dans une salle où leur éducateur ne se trouve pas

## « J’ai ajouté un enfant mais il n’apparaît pas dans la salle »

Quelques points à vérifier :

- Le **statut d’inscription** de l’enfant est-il « enrolled »? (Pas « waitlist » ni « withdrawn »)
- La **date de début** est-elle aujourd’hui ou antérieure? (Une date de début future n’apparaît qu’à cette date)
- La **salle** est-elle la bonne? Consultez le profil de l’enfant.

## « Un parent dit qu’il ne voit pas son enfant »

Vérifiez que :

- Le parent est lié à la bonne famille
- L’enfant est inscrit dans cette même famille
- Le statut du compte du parent est **active** (et non **invited** — il doit s’être connecté une première fois)

Vous pouvez le vérifier dans **Familles → [nom de la famille]** — l’enfant doit figurer dans la famille, et le parent dans la liste des tuteurs.

## « J’ai généré des factures mais une famille n’en a pas reçu »

Soit :

- Elle n’a aucun enfant inscrit avec des frais mensuels définis — vérifiez que l’inscription comporte un monthly_fee
- Une facture existe déjà pour ce mois — vérifiez **Familles → Factures** filtré par famille
- Le courriel n’est pas parti — vérifiez votre configuration SMTP et le courriel de la famille

## « Le résumé IA affiche “Loading...” depuis des heures »

Le résumé n’est généré qu’après 16 h. Avant 16 h, les parents voient un message indiquant « Le résumé quotidien sera disponible après 16 h. » C’est voulu — nous voulons qu’une bonne partie de la journée soit saisie avant de le générer.

S’il est plus de 16 h et que le chargement se poursuit, l’API d’Anthropic connaît peut-être une panne. Consultez `https://status.anthropic.com`. Le système bascule vers un résumé à partir d’un modèle si l’API est inaccessible.

## « Une photo que j’ai téléversée n’apparaît pas »

- Patientez 30 secondes — les photos téléversées prennent quelques instants à être traitées
- Actualisez complètement l’application du parent : **Ctrl+Maj+R** sur un ordinateur
- Vérifiez que la photo a été associée au bon enfant (ou aux bons enfants) lors du téléversement
- Le fichier est peut-être trop volumineux (> 8 Mo) ou dans un format non pris en charge

## « Un éducateur ne peut pas se connecter »

Son compte est peut-être au statut **invited**, ce qui signifie qu’une invitation lui a été envoyée mais qu’il n’a pas terminé sa première connexion. Demandez-lui de :

1. Retrouver le courriel de bienvenue d’origine
2. Utiliser le mot de passe temporaire de ce courriel
3. Se connecter une fois avec succès — cela active son compte
4. Changer son mot de passe aussitôt

S’il a perdu le courriel de bienvenue, vous pouvez réinitialiser son mot de passe (voir **Réinitialiser le mot de passe d’un utilisateur**).

## En cas de doute

Cliquez sur **Demander** en haut de n’importe quelle page d’aide et décrivez ce qui se passe. L’assistant IA a accès à toute cette documentation et peut vous donner une réponse plus précise.

Si l’IA ne sait pas, écrivez à support@kiddietrac.com en indiquant :

- Ce que vous faisiez
- Ce que vous attendiez
- Ce qui s’est réellement produit
- Une capture d’écran, si possible
