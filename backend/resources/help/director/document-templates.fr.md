---
title: Modèles de documents
---
# Modèles de documents

**Paramètres → Modèles de documents** contrôle l’apparence des bulletins de paie et des
factures quand ils sont imprimés ou envoyés par courriel. Importez une mise en page que vous
utilisez déjà, prévisualisez-la avec des chiffres d’exemple, et activez-la quand elle vous
convient. D’ici là, les documents utilisent la mise en page intégrée.

[[open: #document-templates | Ouvrir Modèles de documents]]

## Importer une mise en page que vous utilisez déjà

1. Ouvrez **Modèles de documents** et appuyez sur **⤵ Importer un modèle**.
2. Sous **Document**, choisissez **Bulletin de paie** ou **Facture**.
3. Dans **Nommez-le**, donnez un nom que votre équipe reconnaîtra, par exemple « Notre bulletin de paie 2026 ».
4. Collez la mise en page dans **Modèle**. Un fichier Blade ou du HTML simple conviennent.
5. Appuyez sur **Traduire**. La mise en page est traduite, jamais exécutée.
6. Lisez le résultat. Une boîte verte indique **✓ Translated. Nothing executable was carried across.** Une boîte orange liste ce qui n’a pas pu être repris, pour que vous sachiez quoi terminer à la main.
7. Appuyez sur **Le prévisualiser** pour la voir remplie avec des chiffres d’exemple, puis sur **Enregistrer le modèle**.

Le modèle s’ajoute à la liste comme **Inutilisé**. L’enregistrement ne change aucun document
à lui seul.

## Modifier un modèle

1. Appuyez sur **✏️ Modifier** sur sa ligne.
2. Changez le **Nom** ou la **Mise en page**.
3. Sous **Fields you can use — click to insert:**, cliquez sur un nom de champ pour l’insérer là où se trouve votre curseur. Seuls ces champs existent; tout autre reste vide.
4. Appuyez sur **Aperçu** pour la voir remplie avec des chiffres d’exemple et le nom de votre agence.
5. Appuyez sur **Enregistrer**.

## Activer un modèle

1. Appuyez une dernière fois sur **👁 Aperçu** sur sa ligne.
2. Appuyez sur **✅ Utiliser celui-ci**.
3. Confirmez « Use this template for every document of that kind from now on? ».

Le message **Ce modèle est maintenant utilisé** apparaît et le statut devient **En usage**. Un
seul modèle par document est utilisé à la fois; le précédent redevient donc **Inutilisé**.

## Comment fonctionne le langage de mise en page

Un modèle, c’est du HTML avec des espaces réservés :

- `{{ name }}` insère une valeur, rendue sûre pour la page.
- `{{{ name }}}` insère une valeur telle quelle, pour un bloc de balises fourni par le système.
- `{{#if x}} … {{#else}} … {{/if}}` n’affiche une section que s’il y a quelque chose à montrer.
- `{{#each rows}} … {{/each}}` répète une section pour chaque ligne, comme chaque ligne de paie.

Le modèle n’exécute aucun code et ne fait aucun calcul. Chaque total, date et montant est
calculé et mis en forme avant d’arriver au modèle; un modèle ne peut donc jamais fausser les
chiffres d’un bulletin de paie ni montrer quelque chose qu’on ne lui a pas donné.

## Lire la liste

| Colonne | Signification |
|---|---|
| **Nom** | Le nom que vous lui avez donné. « imported with notes » signifie que certaines parties n’ont pas pu être reprises. |
| **Document** | Bulletin de paie ou Facture. |
| **Portée** | **Cette agence** pour les vôtres, **Par défaut de la plateforme** pour la mise en page de départ partagée. |
| **Source** | D’où il vient. |
| **Statut** | **En usage** ou **Inutilisé**. |
| **Mis à jour** | Dernier enregistrement. |

## Qui peut faire quoi

Les administrateurs de l’agence (et de la plateforme) utilisent cet écran. La direction des
services de garde, les éducatrices et les parents ne le voient jamais; ils ne voient que les
bulletins de paie et les factures qu’il produit.

## FAQ et quand quelque chose ne va pas

??? La liste dit « No templates yet. » | Les bulletins de paie et les factures utilisent la mise en page intégrée tant que vous n’en ajoutez pas. Appuyez sur ⤵ Importer un modèle.
??? Il n’y a pas de bouton Supprimer sur une ligne. | Un modèle en usage, ou un modèle par défaut de la plateforme, ne peut pas être supprimé. Activez-en un autre d’abord.
??? Supprimer un modèle change-t-il les bulletins de paie déjà envoyés? | Non. Les documents déjà émis gardent leur propre copie.
??? Comment revenir à la mise en page intégrée? | Si votre liste a une ligne Par défaut de la plateforme, activez-la avec ✅ Utiliser celui-ci. Sinon, communiquez avec le soutien KiddieTrac pour désactiver votre modèle.
??? « Could not translate ». | Le texte collé n’a pas pu être lu comme une mise en page. Vérifiez que vous avez collé le fichier au complet, puis appuyez de nouveau sur Traduire.
??? Un champ est vide dans l’aperçu. | Le nom n’est pas un des champs listés sous Fields you can use. Cliquez sur le champ dans cette liste pour insérer le bon nom.

## Écrans liés

[[open: #payroll | Paie]] [[open: #email-templates | Modèles de courriel]]

- [Modèles de courriel — changer le texte](email-templates)
