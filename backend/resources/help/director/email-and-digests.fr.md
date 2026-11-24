---
title: Paramètres de messagerie et résumés
category: Settings
order: 65
roles: agency_admin, platform_admin
---
# Paramètres de messagerie et résumés

Chaque agence cliente de Kiddietrac peut utiliser son propre courriel sortant afin que les **résumés, campagnes de marketing, factures, réinitialisations de mot de passe et courriels de bienvenue arrivent de votre domaine** — et non de l’adresse de la plateforme Kiddietrac. Les paramètres se trouvent dans la fenêtre de modification de l’agence sous **Toutes les agences → Modifier → Paramètres de messagerie**.

## Champs SMTP

| Champ | Quoi indiquer | Remarques |
|---|---|---|
| Hôte SMTP | `smtp.gmail.com` / `smtp.office365.com` / `mail.yourcentre.com` | L’hôte sortant de votre fournisseur |
| Port | `587` (TLS) / `465` (SSL) / `25` (aucun) | Selon le chiffrement choisi |
| Nom d’utilisateur | `noreply@yourcentre.com` | L’identifiant complet de la boîte courriel |
| Mot de passe | mot de passe de la boîte ou jeton d’application | Nous le stockons chiffré; laissez vide lors de la modification pour conserver l’existant |
| Chiffrement | TLS / SSL / Aucun | TLS (port 587) est le choix sûr par défaut |
| Adresse d’expédition | `noreply@yourcentre.com` | Ce que les destinataires voient dans l’en-tête De |
| Nom d’expéditeur | « Sunshine Childcare » | Nom convivial à côté de l’adresse d’expédition |

**Laissez l’un de ces champs vide pour revenir à la valeur par défaut de la plateforme** (`MAIL_*` dans notre `.env`). C’est le bon réglage si vous êtes un sous-locataire qui n’a pas acheté la marque blanche.

Pour les utilisateurs de **Gmail / Workspace**, générez un *mot de passe d’application* (et non le mot de passe de votre compte). La validation en deux étapes doit d’abord être activée. Même chose pour Outlook.

Pour les boîtes **GoDaddy / cPanel**, l’hôte est généralement `mail.<your-domain>`, port 465 avec SSL.

## Résumé quotidien

Chaque matin à **7 h, heure de Toronto**, les directeurs et les administrateurs de l’agence reçoivent un courriel de résumé quotidien pour chaque agence à laquelle ils appartiennent. Contenu :

- Nombre de personnes présentes aujourd’hui par rapport au total des inscrits (avec % de présence)
- Solde et nombre de factures ouvertes
- Médicaments administrés jusqu’à maintenant aujourd’hui
- Observations consignées hier
- Une bannière d’avertissement si du personnel ne s’est pas inscrit à l’AMF

Le résumé passe par le SMTP de cette agence s’il est configuré, sinon par la valeur par défaut de la plateforme. Les agences suspendues sont ignorées.

## Résumé hebdomadaire

Chaque lundi à **7 h 05, heure de Toronto**, les directeurs et les administrateurs de l’agence reçoivent un résumé hebdomadaire couvrant la **semaine précédente** (lun.–dim.). Contenu :

- Variation nette des inscriptions (nouvelles et retraits)
- Total facturé la semaine dernière + payé à ce jour
- Observations consignées
- Incidents enregistrés

## Tester votre configuration

Deux façons de vérifier vos paramètres :

1. **Essai à blanc par SSH** — `php artisan kiddietrac:digest-daily --dry-run` affiche ce que dirait le courriel et à qui, sans rien envoyer.
2. **Exécution réelle par SSH** — `php artisan kiddietrac:digest-daily` envoie à tous les directeurs et administrateurs. À utiliser avec prudence.

## État du cron

Le planificateur s’exécute à partir d’une entrée cron au niveau du serveur qui lance `php artisan schedule:run` chaque minute. Les commandes de résumé ne s’exécutent qu’aux heures prévues (7 h 00 tous les jours, lundi 7 h 05). La sortie est écrite dans `backend/storage/logs/scheduler.log` sur le serveur. Si vous voyez « no scheduled commands ready », c’est normal — la plupart des minutes, rien n’est à exécuter.

## Confidentialité

Les mots de passe SMTP sont stockés en texte brut dans la base de données (chiffrés au repos par le chiffrement de disque de GoDaddy). Ils ne sont jamais renvoyés dans les réponses de l’API — seulement un indicateur `email_smtp_pass_set: true` — de sorte qu’une fuite d’un vidage de `/platform/agencies` ne les expose pas. Si vous soupçonnez qu’un mot de passe est compromis, changez-le dans la fenêtre Paramètres de messagerie (ou changez le mot de passe de la boîte elle-même).
