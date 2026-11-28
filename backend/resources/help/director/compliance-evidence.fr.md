---
title: Preuves de conformité (préparation SOC 2)
---
# Preuves de conformité (préparation SOC 2)

**Platform › Compliance evidence** regroupe ce qu'un auditeur demande en premier quand KiddieTrac se prépare à un rapport SOC 2 : les politiques écrites et la matrice de contrôles, et des preuves en direct que les contrôles existent et fonctionnent (qui a un accès administrateur, événements de sécurité, sauvegardes, tâches planifiées et changements de code). La revue trimestrielle des accès s'y approuve aussi. Le rapport SOC 2 lui-même est émis par un cabinet de CPA indépendant.

[[open: #compliance-evidence | Ouvrir Compliance evidence]]

## Lire les tuiles des contrôles

1. Ouvrez **Platform › 🛡️ Compliance evidence**.
2. Lisez les tuiles du haut. Vert, c'est en ordre; orangé, à surveiller; rouge, une lacune :
   - **Admins with two-factor** (et combien utilisent aussi une clé d'accès) : devrait être à 100 %.
   - **All users with two-factor**.
   - **Admins never signed in** : des accès inutilisés à retirer lors de la revue.
   - **Sign-ins, last 30 days** : connexions réussies et échouées, et échecs de la double authentification.
   - **Latest backup** et le nombre de copies conservées.
   - **TLS certificate (app / api)** : jours restants; les certificats se renouvellent seuls.
   - **Audit log** : combien d'événements sont conservés et depuis quand.
   - **Access review** : la date de la dernière approbation, ou **Due now (quarterly)**.

## Télécharger des preuves pour un auditeur

1. Allez à **Evidence exports**.
2. Appuyez sur l'export voulu : **👥 Access review (CSV)**, **🔐 Security events, 90 days (CSV)**, **🗄️ Backups (CSV)**, **⏱️ Scheduled jobs** ou **🧩 Code changes, 90 days**.
3. Le fichier se télécharge. Il est produit à partir des données en direct à ce moment-là.

> ℹ️ Chaque téléchargement est inscrit au journal d'audit : qui a téléchargé quoi, et quand.

## Télécharger les politiques

1. Allez à **Policies and control matrix**.
2. Commencez par le README, la matrice de contrôles et le plan de correction.
3. Appuyez sur **Download** à côté de chaque document voulu. La colonne **Updated** donne la date de chaque document.

## Approuver la revue trimestrielle des accès

Faites-le chaque trimestre. L'approbation garde un instantané de chaque compte administrateur tel qu'il était ce jour-là.

1. Sous **Evidence exports**, appuyez sur **👥 Access review (CSV)** et ouvrez le fichier.
2. Vérifiez chaque compte administrateur : est-il encore nécessaire, et a-t-il la double authentification?
3. Corrigez ce qui ne va pas (retirez les accès inutilisés, demandez aux gens d'activer la double authentification).
4. Sous **Quarterly access review**, écrivez **What did you find?** (par exemple combien de comptes vous avez revus et ce qui clochait).
5. Écrivez **What did you change?**
6. Cochez **I reviewed every admin-level account listed in the access review.**
7. Appuyez sur **✍️ Sign off access review**. Le message **Access review signed off** s'affiche et la revue s'ajoute à la liste au-dessus du formulaire (**Date**, **Reviewer**, **Accounts**, **Findings**, **Changes**).

[[show: #compliance-evidence @ Sign off access review | Montrez-moi]]

## Qui peut faire quoi

| Rôle | Accès |
|---|---|
| Administrateur de la plateforme | Tout cet écran. |
| Administrateurs d'agence, directeurs, auditeurs | Aucun accès : ce sont les preuves propres à KiddieTrac. La conformité d'une agence se trouve à ses écrans Conformité et Conservation des données. |

## Questions fréquentes et problèmes

??? « Write down what you found first. » | La case What did you find? est vide. Décrivez ce que la revue a trouvé avant d'approuver.
??? « Tick the box to confirm you reviewed every account. » | Cochez la case de confirmation au-dessus du bouton d'approbation.
??? « Download failed (…) ». | L'export n'a pas pu être produit. Actualisez et réessayez; seuls les administrateurs de la plateforme peuvent télécharger.
??? « No access review has been recorded yet. » | Personne n'en a encore approuvé. Faites la première revue maintenant.
??? La tuile Access review est rouge. | La dernière revue date de plus d'un trimestre, ou il n'y en a pas. Faites une revue et approuvez-la.
??? L'approbation envoie-t-elle un courriel? | Non. Elle est enregistrée ici et conservée comme preuve.

## Écrans liés

[[open: #audit-logs | Journal d'audit]] [[open: #backups | Sauvegardes]] [[open: #security-alerts | Alertes de sécurité]] [[open: #server-health | État du serveur]] [[open: #data-retention | Conservation des données et conformité]]
