---
title: Tableau de bord de conformité
category: Administration
order: 56
---
# Tableau de bord de conformité

Un seul écran avec les trois signaux « ce qui demande de l’attention » que tout directeur de centre et tout administrateur d’agence veut voir d’un coup d’œil. Ouvrez-le depuis **Administration → Conformité**.

## Bandeau d’indicateurs

Cinq tuiles en haut de l’écran :

- **Certificats expirés** — certifications du personnel dont la date d’expiration est passée
- **Expirent (30 j)** — certifications qui expirent dans les 30 prochains jours
- **MFA non activé** — directeurs et administrateurs d’agence sans double authentification
- **À/au-delà de la capacité** — centres à 100 % d’inscription ou plus
- **Serré (95 %+)** — centres proches de la capacité

## Trois cartes

### Certifications du personnel

Liste chaque certification expirée ou qui expire d’ici 30 jours. Chaque ligne indique le membre du personnel, le type de certification (RECE / Premiers soins / RCR / etc.) et le délai relatif (p. ex. « expire dans 4 jours »).

État vide : « ✅ Toutes les certifications sont à jour. »

### MFA non activé

Liste les directeurs et administrateurs d’agence dont `two_factor_secret` n’est pas défini. Chaque ligne indique le nom, le courriel et l’horodatage de la dernière connexion. Invitez-les à aller à **Paramètres → Double authentification (MFA)** pour l’activer.

État vide : « ✅ Tous les directeurs et administrateurs ont activé la MFA. »

### Capacité du centre

Pour chaque centre de l’agence : nombre d’inscrits / capacité autorisée, pourcentage et barre de progression en couleur (vert < 80 %, ambre 80-95 %, rouge ≥ 95 %).

## Portée de l’agence

Limité par `getAgencyId()`. Les administrateurs de plateforme voient la tranche de l’agence qu’ils ont choisie avec le sélecteur d’agence.
