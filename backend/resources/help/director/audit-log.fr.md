---
title: Journal d'audit
---
# Journal d'audit

Le journal d'audit garde la trace de qui a fait quoi, et quand, dans votre agence : connexions, changements aux dossiers
des enfants, des familles, du personnel, aux factures et aux paramètres, courriels, textos, appels et clavardage.
Servez-vous-en pour savoir « qui a modifié ceci? », vérifier ce qui a été envoyé à une famille ou remettre une preuve à
un inspecteur ou à un vérificateur. Rien ici ne peut être modifié ni supprimé.

[[open: #audit-logs | Ouvrir le journal d'audit]]

## Retrouver une action

1. Ouvrez **Administration › Audit log**. L'onglet **📜 Audit log** s'ouvre en premier, l'entrée la plus récente en haut.
2. Choisissez une **Action** (par exemple une connexion ou une modification de facture) et, au besoin, une **Entity** (le type de dossier : utilisateur, centre, facture…). **All** affiche tout.
3. Tapez un nom, un courriel ou un mot dans **Search** — la recherche porte sur l'action et ses détails.
4. Réglez **From** et **To** pour limiter les dates.
5. Cliquez sur une ligne pour voir le détail complet : qui l'a fait, à partir de quelle adresse et ce qui a changé exactement.
6. Appuyez sur **Reset** pour effacer tous les filtres.

Sur un téléphone, les filtres se replient derrière **⚙️ Filters** (le bouton indique combien sont actifs) et chaque entrée devient une carte.

> ℹ️ Toutes les heures de cet écran sont à l'heure de votre agence. Elles comptent les millisecondes : des entrées écrites dans la même seconde s'affichent quand même dans l'ordre réel.

## Exporter pour un inspecteur ou un vérificateur

1. Réglez les filtres voulus (dates, action, recherche).
2. Appuyez sur **⤓ CSV** à côté de **Reset**.
3. Ouvrez le fichier dans un tableur. Il contient jusqu'à 5 000 lignes, avec les mêmes filtres et le même ordre qu'à l'écran.

## Lire les conversations de clavardage

1. Ouvrez l'onglet **💬 Chat conversations** (administrateurs d'agence seulement).
2. Limitez avec **Search**, **Active from** et **Active until**.
3. Appuyez sur **View transcript** pour une conversation. Les messages supprimés sont barrés avec l'heure de suppression; les messages modifiés sont signalés.
4. Appuyez sur **⬇️ Download transcript** pour l'enregistrer en fichier texte.

> ⚠️ L'ouverture d'une transcription est elle-même inscrite au journal, avec votre nom et la conversation ouverte.

### Traiter un message signalé

Quand quelqu'un signale un message, une boîte **🚩 Reported messages** apparaît dans l'onglet du clavardage et les administrateurs reçoivent un avis dans la cloche.

1. Lisez le signalement : qui a signalé qui, le motif et sa note.
2. Appuyez sur **Open conversation** pour le lire dans son contexte.
3. Réglez la situation (parlez à la personne, retirez un accès), puis appuyez sur **Mark handled**.

## Vérifier les textos, appels, courriels et paiements

- **📱 Text messages** — chaque texto envoyé ou reçu, avec son statut et toute erreur. Filtrez avec **Search**, **From**, **To** et enregistrez avec **⬇️ Export CSV**.
- **📞 Voice calls** — chaque appel automatisé (annonces, vérifications Safe Arrival), sa durée et le résultat, y compris la touche appuyée.
- **📧 Email log** et **⚠️ Email errors** — chaque courriel envoyé par l'agence et ceux qui sont revenus ou ont échoué, avec la raison en mots simples.
- **💳 Payment integrations** — ce que le fournisseur de paiement a envoyé et reçu.

[[show: #audit-logs @ 📧 Email log | Me montrer le journal des courriels]]

## Qui peut faire quoi

| Rôle | Voit |
|---|---|
| Administrateur d'agence | Tous les onglets pour toute l'agence, y compris le clavardage et les courriels. |
| Directeur de centre | Les onglets d'audit, de paiements, de textos et d'appels, pour son ou ses centres seulement. Pas de clavardage ni de courriels. |
| Vérificateur | Le journal d'audit de l'agence en lecture seule, pour le consulter et l'exporter. |
| Administrateur de plateforme | Tout, plus **🌐 Platform-level** pour les événements qui n'appartiennent à aucune agence (par exemple des tentatives de connexion à des comptes inexistants). |

## Questions fréquentes et problèmes

??? Je ne vois pas l'onglet Chat conversations ou Email log. | Ces onglets sont réservés aux administrateurs d'agence. Les directeurs de centre et les vérificateurs ne les ont pas.
??? La liste est vide. | Vérifiez les filtres : une plage de dates ou une action restée de la dernière fois cache des lignes. Appuyez sur **Reset**.
??? D'anciennes entrées affichent .000 après les secondes. | Les entrées d'avant septembre 2026 ont été enregistrées sans millisecondes. Leur ordre à l'intérieur de cette seconde n'a jamais été noté.
??? Puis-je supprimer ou corriger une entrée? | Non. Le journal est permanent exprès, pour servir de preuve fiable.
??? Consulter le journal avertit-il quelqu'un? | Non, sauf que l'ouverture d'une transcription de clavardage est inscrite au journal.

## Écrans connexes

[[open: #compliance | Tableau de bord de conformité]] [[open: #privacy-breaches | Atteintes à la vie privée]]

Voir aussi [[compliance-dashboard]] et [[data-retention-compliance]].
