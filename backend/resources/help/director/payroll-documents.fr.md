---
title: Cycles de paie, heures et talons de paie
category: Staff Management
order: 30
roles: agency_admin, platform_admin, centre_director
---
# Cycles de paie, heures et talons de paie

L’écran **Paie** répond à une seule question : qu’a travaillé chaque personne pendant une période, et combien a-t-elle été payée pour ce travail. Il montre les heures pointées à côté des talons de paie émis pour les mêmes dates, vous permet d’ouvrir, d’imprimer, d’envoyer par courriel ou de marquer chaque talon, et c’est là qu’un administrateur de l’agence fait un traitement de paie. Ouvrez-le à partir de **Ressources humaines > Paie**.

[[open: #payroll | Ouvrir Paie]]

L’écran a deux vues : **⏱️ Hours & payslips** (ce qui a été travaillé, avec les talons de chaque personne sur sa ligne) et **📅 Pay periods** (une ligne par période de paie, avec les totaux et une vérification des heures).

## Voir les heures et les talons d’une période

1. Ouvrez **Ressources humaines > Paie**. La vue **⏱️ Hours & payslips** s’ouvre sur les 30 derniers jours.
2. Appuyez sur une période rapide : **Cette période de paie**, **Dernière période de paie**, **This month** ou **Last month**. Ou saisissez les dates **From** et **To** et appuyez sur **Exécuter**.
3. Choisissez un onglet : **🧑‍🏫 Éducateurs**, **👥 Autre personnel** ou **📄 Entrepreneurs**. L’écran s’ouvre sur le premier onglet qui compte quelqu’un.
4. Lisez chaque ligne : **Staff**, **Role**, **Centre**, **Punches**, **Hours** et **Payslips**. Chaque groupe a son sous-total, et le total général reste sous les onglets.
5. Appuyez sur le lien **Payslips** d’une ligne (par exemple « 2 payslips · 1 850,00 $ ») pour lister les documents de la personne pour la période.

Seuls les pointages fermés comptent. Une personne encore pointée est nommée sur sa ligne pour que vous voyiez ce qui a été laissé de côté. Les personnes payées sans pointer (un gestionnaire salarié, une visiteuse à domicile payée à la visite) apparaissent quand même, avec 0 pointage et leurs talons.

> ℹ️ Les entrepreneurs ne pointent pas et n’ont pas de compte; leur onglet montre donc ce qui leur a été **payé** pendant la période plutôt que des heures.

## Ouvrir, envoyer ou marquer un talon de paie

1. Ouvrez les talons de la personne à partir du lien **Payslips**.
2. Appuyez sur **👁 View** pour lire le PDF, **⬇️ Download** pour l’enregistrer ou **🖨 Print** pour l’imprimer.
3. Appuyez sur **✉️ Resend payslip** pour l’envoyer par courriel. Confirmez l’**Email address** (elle est préremplie à partir du dossier, mais rien ne part sans votre confirmation), ajoutez au besoin une copie ou une note, puis envoyez.
4. Appuyez sur **Marquer comme payé** une fois l’argent parti. **Marquer comme non payé** annule ce choix.
5. Appuyez sur **Envoyer par Interac** pour payer un talon par Interac. Le montant et la personne sont nommés et on vous demande de confirmer, puisque de l’argent réel est transféré.

**Envoyer par Interac** ne s’affiche que pour une personne qui a un compte; les entrepreneurs sont payés à la main puis marqués payés.

## Revoir toute une période de paie

1. Appuyez sur **📅 Pay periods**.
2. Choisissez l’**Year** (année), ou **All years**.
3. Lisez les cartes : **Net paid**, **People paid**, **Average per person**, **Outstanding** (talons pas encore marqués payés) et **Hours check**.
4. Appuyez sur **Open period ›** sur une ligne.
5. Comparez **Payslip hrs** et **Clock hrs** pour chaque personne. Une ligne **⚠ to review** a un écart; **✓ all match** veut dire aucun.
6. Utilisez **Voir le talon de paie**, **Télécharger le talon de paie**, **✉️ Resend payslip** ou **Mark paid** sur une ligne, ou **✉️ Resend all payslips** pour toute la période.

## Faire un traitement de paie (administrateurs de l’agence)

1. Appuyez sur **＋ Generate payroll**.
2. Saisissez **Period start** et **Period end**, puis appuyez sur **Read the clock**.
3. Dans les onglets **Éducateurs**, **Autre personnel** et **Entrepreneurs**, cochez les personnes à payer.
4. Tapez le **Rate** (taux) de chaque personne. **Pay** se remplit avec heures × taux comme point de départ; vous pouvez le modifier.
5. Appuyez sur **Lignes et notes** sur une ligne pour **+ Add a line** (kilométrage, supplément, retenue) ou écrire des **Notes on this payslip**.
6. Appuyez sur **Approuver et créer les bulletins de paie**.

Le taux n’est jamais rempli pour vous : tapez-le à chaque traitement. Une personne qui a déjà un talon pour la même période est sautée, et le traitement vous dit combien l’ont été et pourquoi, pour que personne ne soit payé deux fois. Si les feuilles de temps de la période ne sont pas encore approuvées, le traitement vous invite à les approuver dans **Pointages et feuilles de temps > Approuver et verrouiller**.

## Exporter les heures

Appuyez sur **⤓ CSV** pour télécharger le rapport des heures pour les dates affichées. C’est un fichier d’heures : les personnes payées sans pointage sont à l’écran, pas dans le fichier. Voir [Exportation CSV prête pour la paie](payroll-csv).

## Qui peut faire quoi

| Rôle | Ce qu’il peut faire |
|---|---|
| Administrateur de l’agence | Tout ce qui est ici, y compris **＋ Generate payroll** et **Envoyer par Interac**. |
| Directeur de centre | Voir les heures et les talons de ses propres centres, ouvrir, imprimer, envoyer et marquer les talons. Les directeurs ne font pas de traitement de paie. |
| Personnel | Voir ses propres talons dans **Mes RH**, pas cet écran. |

## FAQ et en cas de problème

??? L’écran indique « No punches in range. » | Personne n’a pointé entre ces dates. Essayez **Dernière période de paie** ou des dates plus larges; le personnel salarié n’apparaît que s’il a un talon.
??? Les heures d’une personne semblent fausses. | Les heures viennent de l’horodateur. Corrigez le quart dans **Pointages et feuilles de temps**, puis appuyez de nouveau sur **Exécuter**.
??? « No payroll documents for the agency you are viewing. » | Aucun talon n’a encore été émis pour cette agence. Ils apparaissent après un traitement de paie ou quand ils arrivent de votre système de paie.
??? J’ai appuyé sur ＋ Generate payroll et rien ne s’ouvre. | Les traitements de paie sont réservés aux administrateurs de l’agence. Demandez à un administrateur de le faire.
??? Une personne a été sautée dans le traitement. | Elle a déjà un talon pour la même période. Ouvrez-le plutôt à partir du lien **Payslips**.
??? D’où viennent les onglets Pay runs et Remittances? | Ils appartiennent au moteur de paie, affiché seulement quand votre agence l’a activé. Voir [Moteur de paie : traitements, talons et versements](payroll-engine).

## Écrans liés

[[open: #timesheets | Pointages et feuilles de temps]] [[open: #payroll?tab=claims | Notes de frais et kilométrage]]

- [Feuilles de temps et correction des heures](timesheets)
- [RH : notes de frais et kilométrage](hr-claims)
- [Exportation CSV prête pour la paie](payroll-csv)
