---
title: Rappels de vaccination
category: Compliance
order: 35
roles: agency_admin, platform_admin
---
# Rappels de vaccination

**Paramètres → Paramètres de messagerie → Vaccinations.** Un courriel planifié envoyé à votre équipe et listant les enfants dont le dossier de vaccination est manquant ou périmé — et, si vous l’activez, un rappel à chaque famille au sujet de son propre enfant.

Les deux sont **désactivés** tant que quelqu’un ne les active pas. On ne commence pas à envoyer des courriels sur le dossier de santé d’un enfant simplement parce qu’une valeur par défaut l’a décidé.

[[show: #email-settings @ Immunization > Send these reminders | Afficher l’interrupteur]]

## Configuration

- [ ] Cochez **Envoyer ces rappels**. Rien n’est envoyé tant que cette option est désactivée.
- [ ] Choisissez la **Fréquence** — **Hebdomadaire** un **Jour de la semaine**, ou **Mensuel** un **Jour du mois** (de 1 à 28, pour que chaque mois en compte un).
- [ ] Réglez l’**Heure de la journée**. Il s’agit de l’heure locale de votre agence, et l’envoi se fait dans cette heure.
- [ ] Sous **Destinataires**, cochez **Administrateurs d’agence**, **Directeurs de centre** et/ou **Éducateurs**.
- [ ] Sous **Ce qui est considéré en souffrance**, indiquez si les **Enfants sans aucun dossier** comptent, et si les dossiers **plus anciens que** 6, 12, 18 ou 24 mois comptent comme périmés (ou **Jamais considéré comme périmé**).
- [ ] Ajoutez au besoin une **Ligne supplémentaire dans le courriel**.
- [ ] **Enregistrer**.

Le panneau indique combien d’enfants sont en souffrance en ce moment, ce qui vous permet de voir la taille de la liste avant de l’activer. Vous ne pouvez pas activer les rappels s’il n’y a personne à qui les envoyer : cochez au moins un groupe, ou **Envoyer un courriel aux parents au sujet de leur propre enfant**.

## Le rappel à l’équipe

Il liste chaque enfant inscrit **sans dossier de vaccination et sans dose consignée** (lorsque **Enfants sans aucun dossier** est coché), ou dont la dose la plus récente est plus ancienne que le délai choisi, avec son centre, sa salle et la raison. Il porte sur les dossiers, pas sur les doses : il vous dit à qui demander un carnet.

Chaque personne ne reçoit que **les enfants dont elle a la charge**. Un éducateur est informé des enfants de son propre centre; un administrateur d’agence voit toute l’agence. Une personne sans affectation à un centre ne reçoit rien, et une personne dont tous les enfants sont à jour ne reçoit aucun courriel.

Si rien n’est en souffrance, rien n’est envoyé. Un courriel hebdomadaire qui annonce zéro apprend aux gens à ignorer l’expéditeur.

## Informer aussi la famille

La case **Informer aussi la famille** ajoute un second rappel, distinct, envoyé selon le même calendrier. Il n’est envoyé que si **Envoyer ces rappels** est aussi activé.

- **Envoyer un courriel aux parents au sujet de leur propre enfant** — un courriel par famille, même avec deux enfants.
- **Mettre le bureau en copie conforme invisible** — le personnel coché sous **Destinataires** est en copie conforme invisible, mais seulement pour les familles dont il s’occupe des enfants.
- **Envoyer aussi une notification dans l’application** — une notification dans l’application et une notification push, en plus du courriel.
- **Vaccins obligatoires seulement** — exclut les doses que votre calendrier marque comme facultatives.
- **Les informer des doses à venir dans les** — 1, 2, 3 ou 6 mois à l’avance.

Seuls les comptes de parents acceptés et actifs reçoivent un courriel.

### Ce que la famille reçoit

Le contenu du courriel dépend de ce que le centre a réellement consigné :

- **Certaines doses consignées** — il nomme les doses **En retard** et **À venir**, et demande d’envoyer le dossier mis à jour si certaines ont déjà été administrées.
- **Rien de consigné et aucun dossier au dossier** — il demande le dossier. Il ne dit pas que l’enfant est en retard pour une liste de vaccins, car personne n’a encore lu son carnet.
- **Rien de consigné, mais un dossier est au dossier** — la famille ne reçoit aucun courriel. Elle l’a déjà envoyé; il attend qu’une personne du centre le lise.

> **Important :** Un carnet au dossier dont rien n’a été consigné exclut cette famille du rappel : lisez donc rapidement les dossiers que les familles envoient (**Détails en attente**).

Les doses faisant l’objet d’une exemption ne sont jamais incluses. Voir [Exemptions de vaccination](immunization-exemptions).

## Questions fréquentes

??? Pourquoi une famille n’a-t-elle pas reçu de rappel? | Soit rien n’est en retard ni à venir pour son enfant, soit son dossier est au dossier en attente d’être lu, soit son compte n’a pas été accepté.
??? Un directeur de centre peut-il modifier ces paramètres? | Cet écran se trouve dans le menu Paramètres de l’administrateur d’agence. Les directeurs reçoivent le rappel si Directeurs de centre est coché.
??? Un éducateur voit-il les enfants des autres centres? | Non. La liste de chaque destinataire est établie à partir des enfants dont il a la charge.

Voir aussi : [Classer un dossier de vaccination](filing-an-immunization-record),
[Vaccination exigée selon l’âge](immunization-due-age),
[Exemptions de vaccination](immunization-exemptions).
