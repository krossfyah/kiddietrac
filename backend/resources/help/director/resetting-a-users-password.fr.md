---
title: Réinitialiser le mot de passe d’un utilisateur
category: Troubleshooting
order: 2
roles: agency_admin, centre_director
---
# Réinitialiser le mot de passe d’un utilisateur

Vous pouvez le faire vous-même. Le soutien technique n’intervient pas.

## D’abord, laissez la personne le faire

**Oublié ?** sur la page de connexion envoie un **code à 6 chiffres**. La personne saisit son courriel, son nom d’utilisateur ou son numéro de cellulaire, et choisit de recevoir le code par **courriel**, par **texto** ou par **appel téléphonique**. Le
code est valide 10 minutes et permet 5 essais; le bon code ouvre la page où elle définit
un nouveau mot de passe. Le courriel contient aussi un lien de réinitialisation, valide 60 minutes. C’est la
bonne voie pour presque tout le monde, et elle ne demande rien de votre part. Dirigez-la vers
[Mot de passe oublié?](forgot-password).

Un code par texto ou par appel est envoyé au numéro de cellulaire de son compte, depuis la propre ligne de textos de votre agence. Il n’arrive que si votre agence envoie des textos et des appels. Comme la personne l’a demandé à l’instant, il est envoyé même si elle a désactivé d’autres textos ou appels.

**Si plusieurs comptes partagent une même adresse courriel ou un même numéro de téléphone**, le formulaire accepte aussi un
**nom d’utilisateur** facultatif. Avec celui-ci, la réinitialisation va exactement à ce compte. Sans lui, un
code est tout de même envoyé et, après l’avoir saisi, la personne choisit le compte à réinitialiser, car
quelqu’un qui a perdu l’accès à son mot de passe ne connaît très souvent pas non plus son nom d’utilisateur, et
en exiger un rebâtit le mur que ce mécanisme sert à abattre.

Rien n’est divulgué dans un cas comme dans l’autre. Une adresse ou un numéro inexistant, un nom d’utilisateur
qui ne correspond à rien et un nom d’utilisateur appartenant à l’adresse d’une autre personne reçoivent tous
la même réponse.

## Le réinitialiser à sa place

**Administration → Gestion des utilisateurs**, trouvez la personne, **🔑 Réinitialiser le mot de passe**. Ce qui se passe
dépend du compte :

**Un compte actif qui s’est déjà connecté reçoit un lien de réinitialisation.** Son mot de passe actuel
continue de fonctionner jusqu’à ce qu’elle en choisisse un nouveau; appuyer sur ce bouton pour un compte qui fonctionne
ne peut donc enfermer personne dehors. C’est plus important qu’il n’y paraît : l’ancien comportement créait un nouveau
mot de passe temporaire chaque fois, ce qui signifiait qu’appuyer sur Réinitialiser pour quelqu’un qui venait de choisir son propre mot de passe
le remplaçait en silence — et, de son côté, la réinitialisation qu’il avait terminée
dix minutes plus tôt avait simplement cessé de fonctionner.

**Un compte qui ne s’est jamais connecté reçoit un mot de passe temporaire**, car il n’y a aucun
mot de passe à préserver et souvent aucune boîte de réception confirmée. L’écran l’affiche une seule fois, avec
un bouton **Copier les détails** couvrant l’adresse du portail, le nom d’utilisateur à employer et le
mot de passe.

## Un mot de passe envoyé par courriel est une clé, pas un identifiant

Un mot de passe qui a transité en clair par une boîte de réception est une **clé à usage unique**.
KiddieTrac le traite comme tel : le compte ne peut rien faire d’autre que se connecter et choisir un vrai
mot de passe. Tous les autres écrans sont refusés tant que ce n’est pas fait.

Ainsi, « dites-lui de le changer immédiatement » n’est plus un conseil que vous devez donner : il est
appliqué d’office. Transmettez le mot de passe temporaire, et il sera demandé à la personne de le remplacer dès son
arrivée.

## Lorsque quelqu’un dit « je n’arrive pas à me connecter »

Parcourez cette liste avant de réinitialiser quoi que ce soit :

1. Est-elle bien à **https://app.kiddietrac.com**?
2. Utilise-t-elle l’adresse à laquelle l’invitation a été envoyée?
3. **Cette adresse a-t-elle plus d’un compte?** Vérifiez dans Gestion des utilisateurs. Si c’est le cas,
   la personne doit indiquer le **nom d’utilisateur** dans le formulaire de connexion — le formulaire le demande quand
   il en a besoin.
4. Le compte a-t-il été retiré ou désactivé? Un compte retiré est refusé dès l’entrée, et une réinitialisation ne ferait que produire un mot de passe que personne ne peut utiliser. Consultez
   l’onglet **Retirés / désactivés**.
5. A-t-elle fait une actualisation forcée (Ctrl+Maj+R)?
6. Voit-elle **« Nous n’avons pas pu joindre KiddieTrac »** ou **« Vous êtes hors ligne »**? Alors la connexion
   n’a jamais atteint KiddieTrac, et une réinitialisation n’aidera pas. La connexion réessaie déjà d’elle-même deux fois en cas de
   coupure. Demandez-lui de vérifier son Wi-Fi ou ses données mobiles et de désactiver tout RPV ou économiseur de données.

## Si le courriel n’arrive jamais

Chaque tentative est consignée, réussie ou non, y compris un refus et sa raison.

- **Administration → Journal d’audit** affiche la tentative de réinitialisation.
- **Paramètres → Paramètres de messagerie** comporte l’interrupteur des notifications pour toute l’agence. S’il est désactivé,
  rien ne s’envoie.
- Consultez le journal des courriels pour repérer un message supprimé ou rejeté avant de supposer qu’il a été envoyé.
- Son dossier de pourriels, et si son fournisseur bloque `kiddietrac.com`.

Si le courriel ne peut pas passer, la personne peut demander le code par **texto** ou par **appel** à la place.
Si rien ne passe du tout, réinitialisez avec un mot de passe temporaire et dictez-le-lui
— la barrière de connexion décrite plus haut garantit qu’il est sûr de le lui transmettre.

Voir aussi : [Mot de passe oublié?](forgot-password),
[Inviter des parents et du personnel](inviting-parents-and-staff),
[Authentification à deux facteurs](mfa-two-factor), [Journal d’audit](audit-log).
