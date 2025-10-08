# Presets graphiques inspirés de bibliothèques populaires

Ce document rassemble plusieurs propositions de presets (combinaisons de couleurs, de typos et d'effets d'interaction) inspirés d'écosystèmes UI répandus tels que Headless UI, Shadcn UI, Radix UI, Bootstrap, Semantic UI et Anime.js. Chaque preset comprend :

- **Palette** : couleurs principales, secondaires et d'accent.
- **Typographie** : familles et usages recommandés.
- **Composants clés** : orientations pour buttons, cartes, formulaires, modales.
- **Interactions** : principes d'animations, micro-interactions et transitions.

👉 **Nouveau : un prototype HTML/CSS accompagnant ces presets est disponible dans [`docs/ui-presets-demo.html`](./ui-presets-demo.html)
et la feuille de style associée [`docs/ui-presets.css`](./ui-presets.css).**

Les variables CSS sont structurées autour de l’attribut `data-preset` pour faciliter l’intégration dans Storybook, WordPress ou
des design systems basés sur les custom properties. Les composants du prototype (boutons, cartes, formulaires, modales) se
stylisent automatiquement via ces tokens.

## 1. Preset « Minimal Focus » (inspiration Headless UI)

- **Palette**
  - Fond : `#F9FAFB`
  - Texte primaire : `#111827`
  - Accent : `#2563EB`
  - Neutres : `#D1D5DB`, `#6B7280`
- **Typographie**
  - Titres : Inter SemiBold, capitalisation minimaliste.
  - Corps : Inter Regular, 16 px avec leading généreux (1.6).
- **Composants clés**
  - Boutons : apparence ghost par défaut, remplissage léger sur hover.
  - Cartes : bordure 1 px `#E5E7EB`, coins légèrement arrondis (6 px).
  - Formulaires : champs à fond blanc, focus state très visible (`ring-2` accent).
  - Modales : centrées, large padding, header discret.
- **Interactions**
  - Transitions en `ease-in-out` 150 ms.
  - Apparition des overlays en fade/scale très léger (1.02).

## 2. Preset « Modern Contrast » (inspiration Shadcn UI)

- **Palette**
  - Fond : `#0F172A`
  - Surface secondaire : `#1E293B`
  - Accent : `#A855F7`
  - Texte primaire : `#F8FAFC`
- **Typographie**
  - Titres : Sora Bold, espacements de lettres resserrés.
  - Corps : Neue Haas Grotesk Regular, 15 px.
- **Composants clés**
  - Boutons : versions solides avec shadow douce + variantes outline contrastées.
  - Cartes : gradient subtil (`rgba(168,85,247,0.12)` à `rgba(14,165,233,0.08)`).
  - Formulaires : champs à bordure fluorescente, label flottant.
  - Modales : navigation latérale avec header collant.
- **Interactions**
  - Hover states luminescents (drop shadow colorée).
  - Reveal animations en slide-up 250 ms, overshoot léger (`cubic-bezier(0.16, 1, 0.3, 1)`).

## 3. Preset « Systemic Elevation » (inspiration Radix UI)

- **Palette**
  - Fond : `#FFFFFF`
  - Accent principal : `#0EA5E9`
  - Accent secondaire : `#10B981`
  - Fond contrasté : `#F1F5F9`
- **Typographie**
  - Titres : IBM Plex Sans Medium.
  - Corps : Work Sans Regular, 16 px.
- **Composants clés**
  - Boutons : focus rings multi-couleurs (bleu + vert) pour l'accessibilité.
  - Cartes : composables, structure en slots, elevation par shadow `0 10px 30px -12px rgba(15, 23, 42, 0.2)`.
  - Formulaires : emphasise sur états (error = rouge `#F87171`, success = accent secondaire).
  - Modales : segmentation claire entre header, body, footer.
- **Interactions**
  - Animations orientées accessibilité : transitions de 100 à 200 ms maximum.
  - Utilisation d'animations discrètes en `transform` plutôt que `opacity` pure.

## 4. Preset « Classy Utility » (inspiration Bootstrap)

- **Palette**
  - Primaire : `#0D6EFD`
  - Secondaire : `#6C757D`
  - Success : `#198754`
  - Warning : `#FFC107`
  - Danger : `#DC3545`
- **Typographie**
  - Titres : Public Sans SemiBold.
  - Corps : Public Sans Regular, 1 rem.
- **Composants clés**
  - Boutons : déclinables en 6 variantes chromatiques, radius 4 px.
  - Cartes : header accentué, corps à fond blanc, footer optionnel.
  - Formulaires : labels alignés, helper text pour validation.
  - Modales : top header coloré selon contexte (primary, warning, etc.).
- **Interactions**
  - Transitions standard 200 ms en `ease`.
  - Accordion, tabs et tooltips inspirés des patterns bootstrap.

## 5. Preset « Vibrant Clarity » (inspiration Semantic UI)

- **Palette**
  - Primaire : `#2185D0`
  - Secondary : `#1B1C1D`
  - Accent : `#6435C9`
  - Positive : `#21BA45`
  - Negative : `#DB2828`
- **Typographie**
  - Titres : Lato Black.
  - Corps : Lato Regular, 15-16 px.
- **Composants clés**
  - Boutons : large padding, uppercase optionnel.
  - Cartes : segments modulables, header avec dividing line.
  - Formulaires : inline validation, messages colorés.
  - Modales : slide-down depuis le top avec fond semi-transparent.
- **Interactions**
  - Transitions fluides `ease-in-out` 300 ms.
  - Menus déroulants avec `fade + slide`.

## 6. Preset « Motion Canvas » (inspiration Anime.js)

- **Palette**
  - Fond : `#0B0D17`
  - Accent dynamique : `#FF6B6B`
  - Accent secondaire : `#5FAD56`
  - Highlights : `#F7B801`
- **Typographie**
  - Titres : Space Grotesk Bold, tailles XXL pour hero/landing.
  - Corps : Manrope Regular, 16 px.
- **Composants clés**
  - Boutons : grands CTA avec gradient animé.
  - Cartes : mise en avant par animations vectorielles (lignes, particules).
  - Formulaires : transitions progressives champ par champ.
  - Modales : plein écran, background animé en boucle lente.
- **Interactions**
  - Effets `motion path`, `stagger` et `spring` (Anime.js) sur les listes.
  - Scroll-triggered animations : parallax léger sur sections.
  - Prévoir un mode réduit (réduction des animations pour accessibilité).

## Recommandations générales d'implémentation

1. **Systèmes de tokens** : centraliser les couleurs, typographies et radii dans un fichier de variables (CSS custom properties ou design tokens JSON) pour permettre la dérivation rapide de chaque preset.
2. **Thématisation** : adopter une architecture CSS modulable (CSS-in-JS, Tailwind config, ou design tokens + utilitaires) afin de basculer d'un preset à l'autre en surchargeant les variables.
3. **Accessibilité** : vérifier les contrastes (WCAG AA minimum), prévoir des focus visibles et des options pour réduire les animations.
4. **Documentation** : fournir pour chaque preset un Storybook ou une page de démonstration avec exemples de composants clefs et instructions d'intégration.
