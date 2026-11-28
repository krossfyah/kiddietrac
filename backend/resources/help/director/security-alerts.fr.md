---
title: Alertes de sécurité et surveillance
---

# Alertes de sécurité et surveillance

**🛡️ Security alerts** liste les activités de connexion suspectes sur toute la plateforme. Toutes les 15 minutes, KiddieTrac lit le journal d'audit et enregistre tout ce qui ressemble à une attaque contre des comptes. Un administrateur de la plateforme peut ainsi l'examiner, décider si c'est sérieux et garder un dossier propre pour les audits de sécurité. Ouvrez-le depuis **Platform › 🚨 Security alerts**.

[[open: #security-alerts | Ouvrir les alertes de sécurité]]

## Ce qui est signalé

Chaque vérification regarde les 20 dernières minutes de connexions :

| Type | Déclenché quand | La colonne Subject montre |
|---|---|---|
| **🌐 Brute force (IP)** | 8 connexions échouées ou plus depuis une même adresse Internet | l'adresse IP |
| **🔑 MFA hammering** | 5 codes de vérification en deux étapes échoués ou plus sur un même compte | le compte |
| **🎯 Credential stuffing** | 10 connexions échouées ou plus visant un même courriel ou nom d'utilisateur | l'identifiant essayé |

Un même type et un même sujet ne sont enregistrés qu'une fois dans cette période : une longue attaque ne remplit donc pas la liste de copies. Toutes les alertes sont de gravité **high**.

## Examiner une alerte

1. Ouvrez **Platform › 🚨 Security alerts**.
2. Regardez les deux compteurs en haut : **Open alerts** (en rouge quand quelque chose est à vérifier) et **Total recorded**.
3. Lisez la ligne : **Type**, **Severity**, **Subject**, **Detail** (par exemple « 35 failed logins from IP … in the last 20 min ») et **When**.
4. Ouvrez **Administration › Audit log** dans un autre onglet et cherchez l'adresse ou le compte indiqué dans **Subject** pour voir exactement ce qui a été tenté, et quand.
5. Décidez : un membre du personnel qui a oublié son mot de passe ressemble beaucoup à une attaque. Si vous ne le reconnaissez pas, bloquez la source et faites changer le mot de passe du compte visé.
6. Appuyez sur **Marquer comme résolu** sur la ligne. Elle s'estompe et affiche **✓ Resolved**.

> 💡 Si plusieurs alertes sont ouvertes et que vous les avez toutes examinées, appuyez sur **Acknowledge all (n)** et confirmez avec **Tout confirmer**. Elles restent dans le journal et restent lisibles ; elles sont seulement marquées comme vues.

## Vider le journal

Les alertes servent de preuves pour les revues de sécurité : les effacer se fait donc en deux temps.

1. Résolvez ou confirmez d'abord les alertes (voir plus haut).
2. Appuyez sur **Clear log**.
3. Lisez la confirmation **Clear n acknowledged alerts?** et appuyez sur **Clear log**. Un message **Log cleared** indique combien ont été supprimées.

> ⚠️ Seules les alertes confirmées sont supprimées, et elles le sont définitivement. Les alertes ouvertes ne sont jamais effacées. L'effacement lui-même est inscrit au journal d'audit, avec les types et le nombre.

Si rien n'est encore confirmé, **Clear log** affiche **Nothing to clear** et vous rappelle de confirmer d'abord les alertes ouvertes.

## Qui est averti

Quand une nouvelle alerte est enregistrée, chaque administrateur de la plateforme actif qui a une adresse courriel reçoit un courriel de **surveillance de sécurité** qui résume ce qui a été trouvé et quoi faire ensuite. Le personnel des agences et les familles ne reçoivent jamais de courriel sur ces alertes, et rien n'apparaît dans leur cloche.

## Qui peut faire quoi

- **Administrateurs de la plateforme** : voient toutes les alertes, les résolvent, les confirment et vident le journal.
- **Administrateurs d'agence, directeurs de service de garde et tous les autres rôles** : ne peuvent pas ouvrir cet écran. Un administrateur d'agence qui y arrive voit **This page is available to platform administrators only.** L'historique de connexion de son agence se trouve dans **Administration › Audit log**.

## Questions fréquentes et problèmes

??? La liste dit « No security alerts — no anomalous authentication activity detected. » La surveillance fonctionne-t-elle? | Oui. Ce message veut dire que les dernières vérifications n'ont rien trouvé au-dessus des limites. Une personne qui se trompe de mot de passe quelques fois ne les atteint jamais.
??? Je vois « This page is available to platform administrators only. » | Vous êtes connecté avec un rôle d'agence. Seul un administrateur de la plateforme peut lire ces alertes.
??? Clear log n'a rien fait et a affiché « Nothing to clear ». | Aucune alerte n'est encore confirmée. Appuyez sur **Marquer comme résolu** sur chaque ligne examinée, ou sur **Acknowledge all**, puis videz le journal.
??? « Could not acknowledge » ou « Could not clear » est apparu. | La connexion a coupé ou votre session a pris fin. Actualisez la page et réessayez ; rien n'a été fait à moitié.
??? La même adresse IP revient après que je l'ai résolue. | L'attaque a continué après la période de 20 minutes, alors une nouvelle alerte a été enregistrée. Bloquez la source si elle continue.
??? Je n'ai pas reçu le courriel d'alerte. | Vérifiez que votre compte d'administrateur de la plateforme est actif et a une adresse courriel. Les alertes ne sont jamais envoyées à une adresse « no-reply ».
??? Résoudre une alerte débloque-t-il ou bloque-t-il quelqu'un? | Non. Cela indique seulement que vous l'avez examinée. Les verrouillages de compte et les changements de mot de passe se font depuis le compte de la personne.

## Écrans liés

[[open: #audit-logs | Journal d'audit]] · [[open: #social-settings | Méthodes de connexion]] · [[open: #server-health | État du serveur]]

Voir aussi : [[audit-log]] · [[single-sign-on]]
