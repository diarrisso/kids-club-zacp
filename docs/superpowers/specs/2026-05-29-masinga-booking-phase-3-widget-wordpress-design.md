# Spec — Masinga Booking · Phase 3 : Widget embarquable + Plugin WordPress

**Date :** 2026-05-29
**Statut :** Validé (brainstorming)
**Dépend de :** Phase 2 Booking Core (API publique `/api/v1/widget/{slug}/*`) — PR #2, **à merger avant l'implémentation**.
**Spec parente :** `docs/superpowers/specs/2026-05-20-masinga-booking-saas-design.md` (§3, §6.1, §7).

---

## 1. Résumé

Le parcours public visible : un **widget Vue 3 autonome**, monté en **Shadow DOM** (isolation CSS), embarqué sur le site WordPress du cabinet via un **micro-plugin**. Le widget consomme l'API publique de la Phase 2 et fait réserver un parent pour son enfant en ~60 s.

**Inclus :** widget Vue (build Vite séparé, Shadow DOM, wizard de réservation, client API, gestion d'erreurs) · plugin WordPress (shortcode + bloc Gutenberg + page de réglages) · tests Vitest + vérif Chrome.

**Exclus (plans ultérieurs) :** calendrier dashboard admin · notifications email · DSGVO/production · multilingue (UI **allemand** seulement en MVP).

## 2. Décisions figées (brainstorming)

| Décision | Choix |
|---|---|
| Distribution | **Même repo**, entrée Vite séparée → `public/widget/masinga-widget.js` (IIFE autonome) servi depuis le domaine SaaS ; le plugin WP charge `<script src>`. |
| Isolation CSS | **Shadow DOM** ; la CSS Tailwind du widget est injectée dans le shadow root. |
| Tests | **Vitest** (logique/composants) + vérification **Chrome** manuelle du parcours complet. |
| Langue UI | **Allemand** uniquement (MVP). |
| Choix praticien | **Praticien précis** (cohérent Phase 2 ; pas de « sans préférence »). |
| Reschedule | Hors scope (annuler + re-réserver). |
| Nom de fichier widget | stable `masinga-widget.js` (cache-bust via `?v=` au besoin). |
| Scope | **Un seul plan** (widget + plugin thin). |

## 3. Architecture & composants

```
backend/resources/js/widget/           ← code source du widget (séparé de l'app Inertia)
├── main.ts                            ← point d'entrée : boot Shadow DOM + montage Vue
├── App.vue                            ← orchestrateur du wizard (état de l'étape courante)
├── api.ts                             ← client fetch typé vers /api/v1/widget/{slug}/*
├── steps/
│   ├── ServiceStep.vue                ← étape 1 : choisir la Leistung
│   ├── PractitionerStep.vue           ← étape 2 : choisir le Behandler
│   ├── SlotStep.vue                   ← étape 3 : choisir le Termin (liste par date)
│   ├── FormStep.vue                   ← étape 4 : formulaire enfant+parent+consentement
│   └── SuccessStep.vue                ← étape 5 : confirmation + lien d'annulation
└── widget.css                         ← Tailwind (entrée CSS du widget, injectée dans le shadow root)

backend/vite.config.js                 ← + 2ᵉ build (lib mode) pour l'entrée widget
backend/public/widget/masinga-widget.js (+ .css)  ← artefacts buildés (gitignored, build local)

wordpress-plugin/masinga-booking/      ← le plugin (repo/dossier dédié, versionné)
├── masinga-booking.php                ← header + enqueue du script SaaS
├── includes/class-shortcode.php       ← [masinga_booking]
├── includes/class-block.php           ← bloc Gutenberg
├── includes/class-settings.php        ← page de réglages (slug + URL API)
└── readme.txt

backend/tests/widget/                  ← tests Vitest (*.test.ts)
```

Unités à responsabilité unique : `api.ts` (I/O réseau), chaque `*Step.vue` (une étape), `App.vue` (machine à états du wizard), `main.ts` (bootstrap Shadow DOM). Chacune testable isolément (les steps reçoivent leurs données en props et émettent des events ; `api.ts` est mockable).

## 4. Build & embed

- **Entrée Vite séparée** (library/IIFE) construit `public/widget/masinga-widget.js` + `masinga-widget.css`. Format autoexécutable : au chargement, le script cherche tous les `[data-masinga-booking]`, crée un shadow root, injecte la CSS, monte l'app Vue.
- **Embed** (généré par le plugin WP) :
  ```html
  <div data-masinga-booking data-tenant="kidsclub" data-api="https://app.masinga-booking.de"></div>
  <script src="https://app.masinga-booking.de/widget/masinga-widget.js" defer></script>
  ```
- Le widget lit `data-tenant` (slug → segment de chemin API) et `data-api` (base URL) sur l'élément de montage. Tous les appels : `${dataApi}/api/v1/widget/${dataTenant}/...`.
- **Build local + rsync** (règle projet) : `npm run build:widget` en local, artefacts copiés sur le serveur SaaS. `public/widget/` reste gitignored.
- **Sécurité du chargement** : le `<script src>` pointe vers le domaine **first-party** du SaaS, en **HTTPS strict uniquement**. On n'utilise PAS de Subresource Integrity (`integrity=...`) : le filename est stable et le widget est mis à jour souvent (« update une fois, tous les cabinets l'ont »), donc un hash SRI figé casserait à chaque build. Si un durcissement est requis plus tard, on passera à des filenames **versionnés par release** (`masinga-widget.<version>.js`) + SRI par version + en-tête `Content-Security-Policy` côté cabinet listant le domaine SaaS.

## 5. Shadow DOM + styling

`main.ts` : `const root = el.attachShadow({mode:'open'})` → injecte `<style>{widgetCss}</style>` dans `root` → monte l'app Vue dans un conteneur du shadow root. Tailwind est scopé au shadow root (le reset n'affecte pas la page hôte ; les styles du thème WP n'affectent pas le widget). La CSS est importée comme chaîne dans le bundle (Vite `?inline`) pour une injection sans requête séparée.

## 6. Parcours utilisateur (wizard linéaire, allemand)

| Étape | Écran | Appel API |
|---|---|---|
| 1 | **Leistung wählen** (liste des prestations + durée) | `GET /services` |
| 2 | **Behandler wählen** (praticiens du service) | `GET /services/{id}/practitioners` |
| 3 | **Termin wählen** (créneaux groupés par date, navigation semaine) | `GET /slots?practitioner_id&service_id&from&to` |
| 4 | **Ihre Angaben** (Kind: Vorname, Nachname, Geburtsdatum ; Eltern: Vorname, Nachname, E-Mail, Telefon ; Notiz ; ☐ Einwilligung DSGVO ; champ honeypot `website` caché) | — |
| 5 | **Bestätigt** (récap + lien d'annulation `{cancellation_token}`) | `POST /appointments` (étape 4→5) |

Navigation **arrière** possible à chaque étape (l'état est conservé dans `App.vue`). Bouton désactivé tant que l'étape n'est pas valide. Case de consentement **décochée par défaut**, obligatoire pour soumettre.

## 7. Gestion d'erreurs (client)

| Réponse API | Comportement widget |
|---|---|
| `409` (créneau pris) | message « Termin nicht mehr verfügbar », re-fetch des créneaux, retour étape 3 |
| `422` (validation/`isBookable`) | erreurs affichées sous les champs concernés ; si slot invalide, retour étape 3 |
| `429` (rate-limit) | message « Zu viele Versuche, bitte später erneut » |
| réseau / 5xx | message générique + bouton « Erneut versuchen » |
| honeypot rempli | l'API renvoie 200 sans créer → on affiche l'écran de succès (le bot ne sait rien) |

Spinners de chargement entre étapes. Aucune donnée patient stockée côté client au-delà de la session widget.

## 8. Plugin WordPress (thin client, ~80 lignes)

- `masinga-booking.php` : header WP, enqueue conditionnel du `<script src>` (URL SaaS depuis les réglages) sur les pages contenant le shortcode/bloc.
- **Shortcode** `[masinga_booking tenant="..." api="..."]` → rend le `<div data-masinga-booking ...>`. Sans attributs, utilise les réglages globaux.
- **Bloc Gutenberg** : wrapper simple autour du shortcode (sélection visible dans l'éditeur).
- **Page de réglages** (Settings API WP) : `tenant slug` + `URL de l'API`. Échappement/sanitisation des sorties (`esc_attr`).
- Aucune logique métier, aucune donnée patient ne transite par WordPress (tout va directement du navigateur du parent à l'API SaaS — point DSGVO clé, cf. spec parente §7.5).

## 9. Tests

- **Vitest** (`backend/tests/widget/`) :
  - `api.ts` : construit les bonnes URLs (`{api}/api/v1/widget/{tenant}/...`) ; parse réponses ; mappe les codes d'erreur (fetch mocké).
  - navigation du wizard : avance/recule, état conservé, bouton désactivé si étape invalide.
  - `FormStep` : validation (champs requis, e-mail, consentement obligatoire) ; honeypot émis.
  - `SlotStep` : rend les créneaux groupés par date à partir d'une réponse mockée.
- **Chrome manuel** : `npm run build:widget`, page HTML de test embarquant le widget pointant vers l'app locale + tenant `kidsclub` (seedé) → parcours complet jusqu'à la confirmation, vérif de l'isolation Shadow DOM.

## 10. Non-objectifs (rappel)

Calendrier dashboard · emails · multilingue · DSGVO/prod · « sans préférence » praticien · reschedule · paiement. Tous hors Phase 3.

## 11. Critères d'acceptation

Embarqué via le plugin sur une page WordPress (ou la page de test HTML), un parent peut accomplir le parcours complet — service → praticien → créneau → formulaire enfant+parent+consentement → réservation confirmée — contre l'API d'un cabinet, avec une UI allemande isolée en Shadow DOM, des erreurs gérées (409/422/429/réseau), et zéro fuite de style entre le widget et la page hôte. Tests Vitest verts + parcours validé en Chrome.
