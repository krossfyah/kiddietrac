---
title: Modèles de courriel — modifier le texte
category: Settings
order: 42
roles: agency_admin, platform_admin
---
# Modèles de courriel — modifier le texte

**Paramètres → Modèles d’e-mail** vous permet de réécrire les courriels que KiddieTrac envoie au nom de votre
agence, avec votre propre voix. Votre logo, vos couleurs et la mise en page sont appliqués
automatiquement; vous ne changez que les mots.

[[open: #email-templates | Ouvrir les modèles d’e-mail]]

## Les modèles que vous pouvez modifier

| Modèle | Qui le reçoit, et quand |
|---|---|
| Bienvenue du prestataire | Les familles, pour leur présenter leur prestataire ou éducateur. Son texte **Nos soins** sert aussi de valeurs de l’agence en matière de soins dans la bienvenue du personnel |
| Résumé quotidien pour les parents | Les parents, chaque soir, pour résumer la journée de leur enfant |
| Bienvenue après l’intégration | Toute personne qui termine l’intégration (sauf le personnel, qui reçoit la bienvenue du personnel) |
| Bienvenue du personnel (éducateurs et prestataires) | Un nouvel éducateur, directeur ou visiteur à domicile à la fin de son intégration, avec ses directeurs et les administrateurs de l’agence en copie |
| Invitation / définir le mot de passe | Une personne que vous invitez, avec ses informations de connexion |
| Annonce | Une mise en page d’annonce réutilisable |

## En modifier un

- [ ] Choisissez-le dans **Modèle à modifier**.
- [ ] Changez le texte dans chaque zone. Les mots entre doubles accolades, comme `{{name}}` ou `{{agency_name}}`, sont remplis pour chaque personne — conservez-les.
- [ ] Appuyez sur **👁️ Aperçu** pour le voir tel qu’il arrivera.
- [ ] Appuyez sur **M’envoyer un test** pour le recevoir dans votre propre boîte de réception.
- [ ] Appuyez sur **Enregistrer le modèle**.

[[show: #email-templates @ Template to edit | Montrez-moi le sélecteur de modèle]]

![Le modèle de bienvenue du personnel, avec ses balises de fusion et ses zones modifiables](https://api.kiddietrac.com/help-img/email-template-staff-welcome.png)

**Rétablir la valeur par défaut** remet le texte d’origine de KiddieTrac si vous voulez recommencer.

## Bienvenue du personnel — ce qui est automatique

Vous rédigez le titre, la bienvenue, les premières étapes et la formule de politesse. KiddieTrac ajoute le reste pour
chaque personne :

- vos **valeurs en matière de soins**, tirées du texte **Nos soins** de la bienvenue du prestataire — modifiez-les
  là et les deux courriels changent;
- une courte visite des fonctions du portail qu’elle utilisera;
- un lien vers **Aide et guides**;
- à qui s’adresser — le nom et les coordonnées de son directeur.

## Questions

??? J’ai enregistré une modification, mais un courriel affiche encore l’ancien texte. | Les courriels déjà envoyés conservent les mots avec lesquels ils ont été envoyés. Le prochain courriel utilisera votre nouveau texte.
??? Chaque centre peut-il avoir son propre texte? | Non. Les modèles sont propres à l’agence.
??? Où changer le logo et les couleurs? | Sur l’écran **🎨 Image de marque**. Les modèles les utilisent automatiquement.

Voir aussi : [Paramètres de messagerie et résumés](email-and-digests),
[Inviter des parents et du personnel](inviting-parents-and-staff),
[Image de marque en marque blanche](white-label-branding).
