---
title: Formation, certificats et vérifications du personnel
category: Staff
order: 34
roles: agency_admin, platform_admin, centre_director
---
# Formation, certificats et vérifications du personnel

Trois écrans gardent les qualifications du personnel en ordre. **Certifications** est la liste de tous les certificats de RCR, de premiers soins et autres détenus par le personnel que vous gérez, avec les dates d’expiration et si quelqu’un a vérifié la carte. **Mes certificats** est l’endroit où chaque personne (vous y compris) ajoute les siens. **Ma formation** liste les échéances et les heures de formation que vos règles de permis exigent, là où votre province ou votre État les fixe.

[[open: #certifications | Ouvrir Certifications]]

## Voir qui doit renouveler

1. Ouvrez **Opérations > Certifications**. La liste **Certifications du personnel** s’ouvre.
2. Lisez les compteurs du haut : **On file**, **Expired**, **Expire dans 30 jours** et **Pas encore vérifié**.
3. Réduisez la liste avec **All centres** et **All certificates**, ou tapez dans **Search staff or certificate**.
4. Lisez chaque ligne : **Staff**, **Certificate**, **Issued**, **Expires**, **Status** et **Checked**.
5. Ouvrez l’encadré rouge **⚠️ … staff have no current CPR** quand il s’affiche : il nomme le personnel sans certificat valide de ce type. Appuyez sur **＋** et un nom pour en ajouter un à cette personne.

## Ajouter un certificat pour quelqu’un

1. Appuyez sur **＋ Add certificate**.
2. Choisissez le **Staff member** (membre du personnel).
3. Choisissez le type de **Certificate**. Pour **Other**, tapez le nom du certificat.
4. Entrez **Issued by** (émis par), l’**Issue date** et l’**Expiry date** (laissez l’expiration vide pour un certificat qui n’expire jamais).
5. Joignez le **Certificate file** : un PDF ou une photo de la carte, jusqu’à 10 Mo.
6. Appuyez sur **Add certificate**. Un message **Certificate added** s’affiche et la ligne s’ajoute à la liste.

## Vérifier un certificat téléversé par le personnel

1. Repérez une ligne avec **Pas encore vérifié** dans la colonne **Checked**.
2. Appuyez sur **📎 View** pour ouvrir le fichier joint.
3. Comparez le nom, les dates et l’émetteur avec ce qui a été saisi.
4. Appuyez sur **✅** (**Marquer comme vérifié**). La ligne affiche **✓ Verified** et qui l’a vérifiée. Appuyez sur **↩️** (**Mark not verified**) pour annuler.

Utilisez le menu **⋮** d’une carte de certificat pour **✏️ Edit**, **📎 View certificate** ou **🗑️ Remove**.

## Ajouter votre propre certificat

1. Ouvrez **Conformité > Mes certificats**.
2. Appuyez sur **＋ Add certificate**.
3. Remplissez le type, les dates et le fichier comme ci-dessus, puis appuyez sur **Add certificate**.

Votre propre certificat est vérifié par un autre directeur ou un administrateur de l’agence, jamais par vous.

## Échéances et heures de formation (là où vos règles les fixent)

Là où vos règles de permis fixent des échéances pour le personnel (l’Ohio aujourd’hui), KiddieTrac les compte à partir de la date d’embauche de chaque personne : par exemple premiers soins et RCR dans les 90 jours, orientation dans les 30 jours, vérifications d’antécédents avant le premier jour puis tous les 5 ans, et heures de formation annuelles. En Ontario, ces parties n’apparaissent pas.

1. Ouvrez **Conformité**. La carte **🎓 Staff training & checks** liste chaque personne, sa date d’embauche (**Hired**), ce qui demande attention (**Needs attention**) et ses heures de formation (**Training hours**).
2. Appuyez sur **Open** à côté d’une personne.
3. Entrez la **Hire date** et le numéro de registre, puis appuyez sur **Save**. Sans date d’embauche, les éléments affichent **Set hire date** : les dates ne sont jamais devinées.
4. Lisez l’état de chaque exigence : **Done**, **Due by** (dû avant) une date, **Overdue**, **Expired** ou **Not on file**.
5. Sous **Training hours**, appuyez sur **Approve** ou **Not approve** pour les heures saisies par le personnel, ou sur **Ajouter des heures** pour en ajouter.

Le personnel voit sa propre liste dans **Conformité > Ma formation** (**Ma formation et mes vérifications**). Les heures qu’il ajoute affichent **Waiting for review** jusqu’à votre approbation; les heures ajoutées par un directeur comptent tout de suite.

## Qui peut faire quoi

| Rôle | Ce qu’il peut faire |
|---|---|
| Administrateur de l’agence | Tous les certificats et dossiers de formation de l’agence. |
| Directeur de centre | Le personnel de ses propres centres; ses propres certificats dans **Mes certificats**. |
| Éducatrice, éducateur, visiteur à domicile | Ses propres certificats et heures de formation seulement. |

## FAQ et en cas de problème

??? Ma formation n’est pas dans mon menu. | Les règles de votre agence ne fixent pas d’échéances pour le personnel (par exemple en Ontario); l’écran est donc masqué.
??? « The expiry date cannot be before the issue date. » | Vérifiez les deux dates; l’expiration doit être le jour de l’émission ou après.
??? « That file is over 10 MB. » | Prenez une photo plus légère ou enregistrez le PDF plus petit, puis joignez-le de nouveau.
??? Une personne manque dans la liste. | Elle ne fait pas partie de vos centres, ou son compte est fermé. Les administrateurs de l’agence voient tout le monde.
??? Le personnel reçoit-il des rappels? | Oui. Avant l’expiration d’un certificat, la personne, les directeurs de son centre et les administrateurs de l’agence reçoivent une cloche, une notification et un courriel, selon les étapes fixées par votre agence dans **Paramètres > Notifications**. Les échéances de formation et les heures manquantes envoient une cloche à la personne.

## Écrans liés

[[open: #renewals | Calendrier des renouvellements]] [[open: #background-checks | Vérifications d’antécédents]]

- [Calendrier des renouvellements](renewals-calendar)
- [Vérification des antécédents](background-checks)
- [RH : évaluations des risques, produits chimiques, procédures, rôles et tâches SST](hr-health-safety)
