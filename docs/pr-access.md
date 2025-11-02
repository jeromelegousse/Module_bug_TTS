# Vérifier ses droits pour créer une pull request

Cette procédure aide à confirmer que votre compte possède les droits nécessaires pour pousser une branche et ouvrir une pull request sur le dépôt GitHub cible.

## 1. Vérifier la provenance du dépôt local

```bash
git remote -v
```

* Assurez-vous que `origin` pointe bien vers l'URL HTTPS ou SSH du dépôt attendu.
* Si vous travaillez sur un fork, confirmez que l'URL correspond à **votre** fork (ex: `https://github.com/votre-compte/Module_bug_TTS.git`).

## 2. Tester les droits en écriture sur le dépôt distant

### Avec HTTPS

```bash
git push --dry-run origin HEAD
```

* La commande ne pousse rien réellement mais renvoie les mêmes erreurs d'authentification qu'un push normal.
* Un message `remote: Permission to ... denied to ...` signifie que votre compte n'a pas les droits nécessaires.

### Avec SSH

```bash
ssh -T git@github.com
```

* GitHub devrait répondre `Hi <votre-compte>! You've successfully authenticated...`.
* Si vous recevez `Permission denied (publickey)`, votre clé publique n'est pas enregistrée sur GitHub.

## 3. Vérifier l'accès via l'outil GitHub CLI (facultatif)

Si vous utilisez `gh`, vérifiez l'authentification et les scopes accordés :

```bash
gh auth status
```

* Assurez-vous que l'hôte (`github.com`) affiche `Logged in to github.com as <votre-compte> (https://github.com/<votre-compte>)`.
* Vérifiez que la colonne « Token » contient le scope `repo` pour pouvoir créer des pull requests.

## 4. Confirmer les droits directement sur GitHub

Dans l'interface web du dépôt :

1. Ouvrez l'onglet **Settings → Collaborators & teams** (si vous y avez accès) pour vérifier votre rôle.
2. À défaut, demandez à un administrateur de vérifier que votre compte dispose d'au moins le rôle **Write** ou fait partie d'une équipe avec ce rôle.

## 5. Cas des pull requests depuis un fork

Même sans droits d'écriture sur le dépôt principal, vous pouvez :

1. Pousser votre branche sur votre fork (`git push origin ma-branche`).
2. Ouvrir une PR via l'interface GitHub ou `gh pr create` en ciblant le dépôt amont (`base repository`).
3. Si l'ouverture échoue avec `permission denied`, vérifiez que vous tentez bien de créer la PR depuis **votre** fork et non directement sur le dépôt amont.

## 6. Lecture des messages d'erreur fréquents

| Message | Cause probable | Solution |
| --- | --- | --- |
| `remote: Permission to ... denied to ...` | Compte sans droits d'écriture | Demander un accès Write ou utiliser un fork |
| `Could not resolve host: github.com` | Problème réseau ou proxy | Vérifier la connexion Internet/Proxy |
| `gh: HTTP 403: Resource not accessible by integration` | Token GitHub CLI sans scope `repo` | Ré-authentifier `gh auth login` avec le scope correct |

---

En suivant ces étapes, vous pouvez diagnostiquer précisément si l'échec de création d'une pull request provient d'un manque de droits ou d'un problème de configuration locale.
