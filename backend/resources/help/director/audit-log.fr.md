---
title: Visionneuse du journal d’audit
category: Administration
order: 58
roles: agency_admin, platform_admin
---
# Journal d’audit

Chaque action importante du portail est consignée. La visionneuse se trouve sous **Administration → Journal d’audit**.

## Ce qui est consigné

Exemples (plus de 50 types d’action à la version v22p49) :

- `user.created` / `user.deleted` / `user.revived` / `user.role_changed`
- `centre.created` / `centre.updated` / `centre.archived`
- `agency.suspended` / `agency.resumed` / `agency.updated`
- `invoice.created` / `invoice.late_fee_applied` / `payment.recorded`
- `branding.updated`
- `campaign.email_sent` / `digest.daily_sent` / `digest.weekly_sent`
- `chat.email_notified` / `form.submitted`

## Lire l’ordre des entrées

![Le journal d’audit, horodaté à la milliseconde](https://api.kiddietrac.com/help-img/audit-log.png)

**Les horodatages comportent les millisecondes.** Les secondes entières n’offraient pas une résolution suffisante pour lire ce journal : parmi les 300 lignes les plus récentes, 241 partagent leur seconde avec une autre ligne, et une seule seconde au milieu d’une synchronisation d’intégration en contient 25. À la seconde près, elles se lisaient toutes comme un seul instant, de sorte qu’une liste pourtant bien ordonnée semblait arbitraire.

Les lignes sont classées de la plus récente à la plus ancienne, et les égalités au sein d’un même instant sont départagées selon l’ordre d’écriture. Ce que vous voyez correspond donc à l’ordre réel des événements, et l’horodatage le montre désormais.

Toutes les heures affichées à cet écran sont dans **le fuseau horaire de votre agence**, jamais en UTC ni dans celui de votre appareil.

Les lignes écrites avant septembre 2026 affichent `.000`. C’est honnête plutôt qu’esthétique : leur ordre à la sous-seconde n’a jamais été enregistré, et en inventer un serait pire que de l’admettre.

## Filtrage

Cinq filtres en haut de l’écran :

- **Action** — choisissez dans la liste déroulante (alimentée automatiquement par les types d’action observés dans votre agence)
- **Entité** — limite à un type d’entité (utilisateur / centre / facture, etc.)
- **Rechercher** — texte libre dans le nom de l’action ou dans les données JSON
- **De / À** — bornes de la plage de dates

Les filtres s’appliquent à la fois aux lignes affichées à l’écran et au téléchargement CSV.

## Exportation CSV

Cliquez sur le bouton vert **⤓ CSV** à côté de Réinitialiser. Il télécharge jusqu’à 5 000 lignes avec les colonnes Quand / Action / Entité / Auteur / Courriel / IP / Données. Il respecte tous les filtres actifs.

L’exportation est classée exactement comme l’écran, égalités comprises. Auparavant, elle était triée uniquement selon l’horodatage, ce qui laissait les lignes partageant une même seconde dans l’ordre où la base de données les retournait, et ce, dans l’unique copie du journal qu’on classe et qu’on remet à quelqu’un. La colonne **Quand** est écrite en texte afin qu’un chiffrier ne puisse pas arrondir les millisecondes et réordonner discrètement les lignes.

## Fenêtre de détail

Cliquez sur une ligne pour afficher son détail complet, y compris les données JSON mises en forme — utile pour comprendre *exactement* ce qui a changé.

## Onglet Conversations

**Journal d’audit → 💬 Conversations** (administrateurs d’agence et administrateurs de la plateforme). Toutes les conversations de votre agence, entre les familles et le personnel et entre membres du personnel, de l’activité la plus récente à la plus ancienne :

- Filtre **Type** (familles ↔ personnel, personnel ↔ personnel), **Rechercher** parmi les personnes, les familles, les centres et tout ce qui a été dit, ainsi que les dates **Active depuis / Commencée par**.
- **Voir la transcription** affiche toute la conversation jour par jour. Les messages supprimés sont barrés en rouge avec l’heure de leur suppression, les messages modifiés sont signalés et les pièces jointes sont listées par nom. **⬇️ Télécharger la transcription** l’enregistre sous forme de fichier texte.
- **L’ouverture d’une transcription est elle-même consignée** ici sous *Transcription de conversation consultée*, avec le nom de la personne qui a ouvert telle conversation.

Les directeurs de centre ne voient pas cet onglet.

## Sur un téléphone

Sur un téléphone, chaque entrée est une carte (l’heure, puis l’action, puis son détail), les onglets tiennent sur une seule ligne que vous pouvez faire défiler, et les filtres se replient derrière un bouton **⚙️ Filtres** qui indique combien sont actifs.

## Portée

Les administrateurs d’agence voient les actions des utilisateurs de leur agence (portée selon le rôle OU par le lien de tuteur). Les administrateurs de la plateforme voient toutes les lignes de la plateforme.
