---
title: Alertes de sécurité et surveillance
category: Compliance
order: 32
roles: agency_admin, platform_admin
---

# Alertes de sécurité et surveillance

KiddieTrac surveille le journal d’audit pour repérer toute activité de connexion suspecte et l’enregistre sous **Paramètres → Alertes de sécurité** (administrateurs de la plateforme).

## Ce qui est détecté

- **Force brute** — de nombreuses connexions échouées depuis une même adresse IP en peu de temps.
- **Martèlement de la MFA** — tentatives répétées et échouées de double authentification sur un même compte.
- **Bourrage d’identifiants** — de nombreuses connexions échouées visant un seul compte.

Chaque détection est enregistrée avec une gravité, le sujet (IP ou compte) et une description en langage clair, et peut être marquée comme **résolue** une fois que vous l’avez examinée.

## En coulisses

Une tâche planifiée analyse le journal d’audit toutes les 15 minutes. Chaque connexion échouée, échec de MFA et changement de mot de passe est inscrit dans une piste d’audit inviolable — les mêmes preuves que recherche un auditeur de sécurité.
