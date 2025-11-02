=== A11y Widget – Module d’accessibilité (mini) ===
Contributors: chatgpt
Tags: accessibility, a11y, widget
Requires at least: 5.0
Tested up to: 6.6
Stable tag: 1.4.1
License: GPLv2 or later

Un bouton flottant ouvre un module d’accessibilité avec des interrupteurs **placeholders** (rien n’est appliqué par défaut). Vous pouvez brancher vos propres styles/scripts via `data-*`, l’API JS et les CustomEvents.

== Nouveautés 1.4.1 ==
- Retrait du mode « Monophtalmie » du widget a11y.

== Nouveautés 1.4.0 ==
- Arrivée du mode « Monophtalmie » intégrant une loupe activable, des indicateurs de profondeur, un champ visuel adaptable et un mode vision basse dédié.

== Nouveautés 1.3.0 ==
- Nouveau mode « Soulagement migraines » avec filtre ambré, suppression des motifs répétitifs, espacement augmenté et 4 presets rapides.

== Nouveautés 1.2.0 ==
- Nouvelle page d’administration « Accessibilité » permettant de masquer certaines fonctionnalités du module pour les utilisateurs finaux.
- Interrupteurs désactivés côté admin retirés automatiquement du widget côté visiteurs.

== Nouveautés 1.1.0 ==
- Sections du widget désormais générées dynamiquement (plus besoin de modifier le template pour ajouter vos options).
- Ajout d’un répertoire `features/` qui accepte des fichiers Markdown (`.md`). Chaque fichier peut définir une section et des interrupteurs supplémentaires sans écraser ceux fournis par défaut.
- Les slugs déjà utilisés sont ignorés pour éviter les collisions avec les fonctionnalités existantes.

== Utilisation ==
1. Uploadez le dossier `a11y-widget` dans `wp-content/plugins/`.
2. Activez le plugin.
3. Par défaut, le widget s’affiche en bas à droite de toutes les pages (injection via `wp_footer`).

- Shortcode: `[a11y_widget]` pour l’afficher où vous voulez.
- Désactiver l’injection auto (dans functions.php):
  `add_filter( 'a11y_widget_enable_auto', '__return_false' );`

== Ajouter des fonctionnalités via Markdown ==
1. Créez (si besoin) le dossier `wp-content/plugins/a11y-widget/features/`.
2. Ajoutez un fichier `mon-profil.md` en respectant le format suivant :

```
# Titre de ma section
- `mon-slug` **Nom lisible** : Indice/description optionnel.
- `autre-slug` **Autre fonctionnalité** : Texte d’aide.
```

Chaque fichier peut contenir plusieurs sections (`#`). Les slugs déjà utilisés par le widget de base ou par un autre fichier sont ignorés pour éviter les doublons. Vous pouvez ensuite brancher vos scripts/styles sur les attributs `data-a11y*` comme auparavant.

== API ==
- `window.A11yWidget.registerFeature('mode-nuit', fn)` – écoute les bascules.
- `window.A11yWidget.get('mode-nuit')` – lit l’état.
- `window.A11yWidget.set('mode-nuit', true)` – définit l’état (+ persistance).

Chaque toggle applique/retire `data-*` sur `<html>`, p. ex. `data-a11yModeNuit="on"`.
Branchez vos règles CSS globales selon ces attributs, ou réagissez en JS.

== Sécurité/Accessibilité ==
- Rôle `dialog`, `aria-modal`, piège du focus, Échap pour fermer.
- Préférences persistées via localStorage.
