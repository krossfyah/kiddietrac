---
title: Médicaments
---
# Médicaments

L’écran Médicaments regroupe toutes les ordonnances permanentes des enfants de vos centres : le médicament, la dose et la fréquence, l’autorisation du parent et chaque dose donnée. Une dose ne peut être consignée qu’une fois l’autorisation parentale enregistrée. Utilisez-le quand une famille apporte un médicament, quand un parent envoie une demande depuis son portail, et pour vérifier l’historique des doses.

[[open: #medications | Ouvrir Médicaments]]

## Ajouter un nouveau médicament

1. Ouvrez **Opérations > Médicaments** et appuyez sur **+ Nouveau médicament**.
2. Choisissez l’**Enfant**. Seuls les enfants inscrits en ce moment sont listés.
3. Tapez le **Nom du médicament** (par exemple « Tylenol pour enfants ») et sa **Concentration** (par exemple « 160mg/5mL »).
4. Choisissez la **Route** (voie d’administration) : **Oral**, **Topical**, **Inhaled**, **Injection** ou **Other**.
5. Remplissez la **Posologie** (par exemple « 5 mL ») et la **Fréquence** (par exemple « aux 4 heures au besoin »).
6. Ajoutez le **Motif** (par exemple « fièvre / douleur »).
7. Vérifiez **Débute le** (la date du jour est inscrite) et, si l’ordonnance prend fin, indiquez **Expire le (facultatif)**.
8. Ajoutez des **Special instructions (optional)**, comme « À donner avec de la nourriture ».
9. Pour un médicament d’ordonnance, cochez **Médicament sur ordonnance** et tapez le nom du médecin prescripteur.
10. Appuyez sur **Save (status: pending parent auth)**. Le médicament paraît dans la liste avec l’insigne orange **PENDING PARENT AUTH**.

## Enregistrer l’autorisation parentale

Un parent doit donner son consentement écrit avant qu’une dose soit donnée.

1. Trouvez le médicament qui porte l’insigne **PENDING PARENT AUTH**.
2. Appuyez sur **Enregistrer l’autorisation parentale**.
3. Dans **Parent or guardian who authorized it**, choisissez le parent qui a signé. La liste ne contient que les parents et tuteurs de la famille de cet enfant; s'il y en a plus d'un, elle commence par **Choose a parent…**. Si la famille n'a pas encore de compte parent, la fenêtre le dit à la place.
4. Appuyez sur **Marquer comme autorisé**. L’insigne devient vert (**ACTIVE**) et les boutons **Consigner une dose** et **Cesser** apparaissent.

> ℹ️ Quand un parent demande un médicament depuis son portail (**💊 Ask the centre to administer a medication**), la demande arrive dans cette même liste avec **PENDING PARENT AUTH**. Vérifiez les détails avec la famille, puis autorisez-la.

## Consigner une dose

1. Sur un médicament **ACTIVE**, appuyez sur **Consigner une dose**.
2. Tapez la **Dose donnée** (par exemple « 5 mL »).
3. Choisissez le **Résultat** : **Donnée selon l’ordonnance**, **Dose partielle**, **Refusée par l’enfant** ou **Missed (not given)**.
4. Ajoutez des **Notes**. Si votre centre utilise un témoin (recommandé pour les narcotiques), choisissez-le dans **Witness (optional, recommended for narcotics)** : la liste contient le personnel qui travaille au centre de l’enfant, sans vous. Sinon, laissez **No witness**.
5. Appuyez sur **Enregistrer la dose**. La fiche affiche une dose de plus et l’heure de la dernière dose.

Les éducatrices et éducateurs consignent les doses de la même façon depuis leur propre écran **Medications to administer**.

## Voir l’historique des doses

1. Appuyez sur **View dose log** sur la fiche du médicament (le chiffre indique combien de doses il y a).
2. La fenêtre **Historique des doses** présente **When**, **Dose**, **Outcome**, **By** et **Notes** pour chaque dose.
3. Appuyez sur **Fermer** quand vous avez terminé.

## Cesser un médicament

1. Appuyez sur **Cesser** sur un médicament actif.
2. Confirmez dans la fenêtre **Discontinue medication**. Aucune nouvelle dose ne peut être consignée, et l’historique des doses est conservé.

L’insigne affiche ensuite **DISCONTINUED**. Une ordonnance dont la date de fin est passée affiche **EXPIRED**.

## Qui peut faire quoi

- Les administrateurs d’agence et les directeurs de centre ajoutent des médicaments, enregistrent l’autorisation, consignent les doses, consultent l’historique et cessent un médicament. Un administrateur de la plateforme qui travaille dans une agence a la même vue.
- Les éducatrices et éducateurs voient les médicaments actifs de leur centre, consignent les doses et consultent l’historique. Ils ne peuvent pas ajouter, autoriser ni cesser un médicament.
- Les parents voient les médicaments de leur enfant en lecture seule et peuvent demander au centre d’en donner un.
- L’écran exige que la fonction Médicaments fasse partie du forfait de votre agence.

## Questions fréquentes et dépannage

??? Pourquoi n’y a-t-il pas de bouton Consigner une dose? | Le médicament n’est pas encore actif. Enregistrez d’abord l’autorisation du parent. Un médicament cessé ou expiré ne peut plus recevoir de doses.
??? J’ai appuyé sur Marquer comme autorisé et j’ai vu « Choose the parent who authorized it. » | Aucun parent n’a été choisi. Choisissez-en un dans **Parent or guardian who authorized it** et appuyez de nouveau sur **Marquer comme autorisé**.
??? La fenêtre dit « This child's family has no parent account yet. Add a parent to the family first. » | Seul un parent de la famille de l’enfant peut autoriser. Ajoutez le parent à la famille, puis revenez enregistrer l’autorisation.
??? Je vois « Choose one of this child's parents or guardians. » ou « Choose a witness who works at this centre (not yourself). » | Le serveur accepte seulement les parents de l’enfant comme personne qui autorise, et seulement le personnel du centre de l’enfant, autre que la personne qui donne la dose, comme témoin. Choisissez de nouveau dans la liste.
??? Une fenêtre affiche un message en rouge. | L’enregistrement n’a pas fonctionné et la fenêtre reste ouverte. Lisez le message, corrigez le champ indiqué et réessayez.
??? Je vois « Medications could not be loaded. Please refresh and try again. » | La liste ne s’est pas chargée. Actualisez la page; si cela se reproduit, vérifiez votre connexion ou ouvrez un billet.
??? La page affiche « No medications recorded yet. » | Personne n’a encore ajouté de médicament dans vos centres. Appuyez sur **+ Nouveau médicament** pour ajouter le premier.
??? Le parent est-il avisé quand une dose est consignée? | La dose est inscrite dans l’historique du médicament. Les parents voient l’ordonnance et le nombre de doses consignées dans leur propre écran Médicaments.

## Voir aussi

[[open: #allergy-alerts | Alertes allergies]] [[open: #immunizations | Vaccins]]

- [Alertes d’allergies et alimentaires](allergy-alerts)
- [Onglets du dossier de l’enfant](child-record-tabs)
