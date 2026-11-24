---
roles: agency_admin, platform_admin
title: Campagnes automatisées
category: Marketing
order: 65
---
# Campagnes automatisées

Envoi automatique de courriels de suivi N jours après un événement déclencheur. Utile pour accompagner les familles qui ont réservé une visite et pour relancer celles qui sont devenues silencieuses.

## Créer une campagne

1. Barre latérale → **Campagnes automatisées**.
2. Touchez **+ Nouvelle campagne automatisée**.
3. Choisissez un déclencheur :
   - **Après une visite réservée** (p. ex. envoyer un courriel « merci de votre visite » 1 jour après)
   - **Après une inscription terminée** (p. ex. trousse de bienvenue 3 jours après)
   - **À l’anniversaire** (p. ex. suggestion de programme adaptée à l’âge)
   - **Après 30 jours d’inactivité** (relance)
4. Choisissez le délai en jours (0 à 365).
5. Rédigez l’objet et le corps en HTML.
6. Enregistrez.

## Comment fonctionne l’envoi

Une tâche cron s’exécute chaque heure. Elle recherche les événements déclencheurs qui correspondent au `(trigger_event, trigger_delay_days)` de chaque campagne active. Les événements correspondants sont ajoutés à la file `drip_sends` et envoyés immédiatement.

Chaque (campagne, source) n’est envoyé qu’une seule fois — les rejeux sont idempotents.

## Suivi

La page de la campagne affiche : nb de campagnes · nb envoyés · nb en file · nb échoués.

## Modèles de courriel

Utilisez les variables standard d’EmailTemplate dans le HTML du corps :
- `{{first_name}}` — le prénom du destinataire
- `{{family_name}}` — le nom de la famille
- `{{centre_name}}` — le nom du centre
- `{{agency_name}}` — le nom de votre agence

## Bonnes pratiques

- Commencez avec 1 ou 2 campagnes; mesurez les réponses avant d’en ajouter
- Variez le ton : visite réservée = accueil chaleureux, inactivité = relance en douceur
- N’envoyez pas trop souvent — la qualité plutôt que la quantité
- Utilisez la réécriture par IA (point d’accès `/marketing/ai-rewrite`) pour peaufiner le texte
