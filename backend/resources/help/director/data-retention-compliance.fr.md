---
title: Conservation des données et conformité
---
# Conservation des données et conformité

**Settings › Data retention & compliance** est l'endroit où un administrateur d'agence note combien de temps chaque
type de dossier est conservé, choisit si KiddieTrac efface automatiquement les vieux dossiers et règle l'avis de
confidentialité que les parents doivent lire. Ensemble, c'est la politique de conservation de votre agence — ce dont
un vérificateur ou un parent qui demande « combien de temps gardez-vous ceci? » a besoin. Les règles des services de
garde fixent souvent des minimums (par exemple la LGEPE en Ontario) : vérifiez les vôtres avant de changer une durée.

[[open: #data-retention | Ouvrir Conservation des données et conformité]]

## Régler les durées de conservation

1. Ouvrez **Settings › Data retention & compliance**. L'onglet **🗓 Retention periods** s'ouvre en premier.
2. Pour chaque ligne, entrez la durée de conservation, en années ou en mois selon l'unité affichée : **Child & enrolment records (after departure)**, **Uploaded documents**, **Attendance & daily logs**, **Parent–educator messages (chat)**, **Announcements & news**, **Suspended families**, **Call recordings & transcripts**, **Walk & field trip GPS trails**, **Log of texts sent**, **Log of emails sent** et **Security & audit trail**.
3. Lisez le badge de chaque ligne : **Enforced** veut dire que le nettoyage de nuit s'y applique (une fois l'application automatique activée); **Policy only** veut dire que c'est noté comme politique et que rien n'est supprimé automatiquement.
4. Appuyez sur **Save changes**. Un message vert **Saved** apparaît.

> 💡 Pour les lignes marquées « 0 = keep », 0 veut dire garder pour toujours. Pour un enregistrement d'appel, seuls l'audio et la transcription sont retirés; pour une sortie, seuls les points de localisation — l'appel et la sortie restent au dossier.

## Activer le nettoyage automatique

1. Ouvrez l'onglet **⚙️ Automatic enforcement**.
2. Choisissez **When enforcing, records are** : **Anonymised** (le contenu est effacé, la ligne et les statistiques restent) ou **Permanently deleted** (supprimé; impossible à annuler).
3. Activez **Automatically enforce retention**.
4. Appuyez sur **Save changes**.

Dès la nuit suivante, KiddieTrac efface seulement les lignes marquées **Enforced** : messages de clavardage, annonces,
familles suspendues, enregistrements d'appels, traces GPS et journaux des textos et des courriels, une fois plus vieux
que votre durée.

> ⚠️ La suppression est définitive. Convenez des durées avec votre équipe et vérifiez-les deux fois avant d'activer cette option.

## Demander aux parents de lire votre avis de confidentialité

1. Ouvrez l'onglet **🔒 Privacy & consent**.
2. Activez **Ask parents to read our privacy notice**. Chaque parent voit l'avis une fois dans le portail et appuie sur « J'ai lu »; la date est notée.
3. Entrez votre **Privacy policy URL** et le **Data-protection contact email** où les parents envoient leurs demandes d'accès ou de suppression.
4. Appuyez sur **Save changes**. Sous l'interrupteur, vous voyez qui a lu ou non l'avis actuel.
5. Si vous avez changé le texte de l'avis en gardant le même lien, appuyez sur **Ask parents again** et confirmez avec **Ask again**. Chaque parent le reverra à sa prochaine ouverture du portail.

> ℹ️ Changer le lien redemande automatiquement à tout le monde. Désactiver l'interrupteur est inscrit au journal d'audit.

Utilisez l'onglet **📝 Notes** pour vos notes internes sur vos obligations; seuls vos administrateurs les voient.

## Qui peut faire quoi

| Rôle | Peut |
|---|---|
| Administrateur d'agence | Modifier tous les réglages de l'agence active. |
| Directeur de centre, éducatrice | Non disponible. |
| Administrateur de plateforme | L'agence choisie dans le sélecteur d'agence. |

## Questions fréquentes et problèmes

??? Rien n'a été supprimé après avoir enregistré une durée plus courte. | Soit **Automatically enforce retention** est désactivé, soit la ligne est **Policy only**. Ces dossiers ne sont jamais supprimés automatiquement.
??? Puis-je récupérer des dossiers supprimés définitivement? | Non. Choisissez **Anonymised** si vous pourriez avoir besoin des lignes plus tard.
??? « Could not save » | Vérifiez que les nombres sont entiers et pas négatifs, puis réessayez. Si cela continue, ouvrez un billet à partir de l'Aide.
??? Les parents reçoivent-ils un courriel quand je redemande? | Non. Ils voient l'avis à leur prochaine ouverture du portail.

## Écrans connexes

[[open: #privacy-requests | Demandes relatives à la vie privée]] [[open: #privacy-breaches | Atteintes à la vie privée]] [[open: #audit-logs | Journal d'audit]]

Voir aussi [[privacy-requests]] et [[privacy-breaches]].
