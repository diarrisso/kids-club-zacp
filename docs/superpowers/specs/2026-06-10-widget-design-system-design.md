# Widget Design System + Booking-Flow Fixes

**Date:** 2026-06-10
**Status:** Approved design — ready for implementation plan
**Scope:** Backend (config endpoint + settings + booking reference) · standalone IIFE widget (theming layer + 5 UX/security fixes) · staff Inertia settings page · tests.
**Context:** Third original owner request of the security/UX batch (after PR-A 2FA and PR #27 timezone). The practice owner wants the public booking widget brandable — "changer le style du formulaire, la couleur et les fonds, tout le système design" — plus five booking-flow issues spotted live during review.

## Problem

The embeddable widget (`backend/resources/js/widget/`, mounted in Shadow DOM) has **zero theming**: every colour is a hardcoded hex spread across eight components (`App.vue`, `StepIndicator.vue`, `KindStep.vue`, `TerminStep.vue`, `FormStep.vue`, `ConfirmStep.vue`, `SuccessStep.vue`, `BookingCalendar.vue`), e.g. `#6B8FA3`, `#C40C78`, `#EC0A8C`, `#26257F`, plus derived pink tints `#FFF4F7` / `#FCE3E9`. Fonts (Fredoka/Nunito) and corner radius are baked in too. A practice cannot change anything without a code change and a `npm run build:widget`.

Five concrete booking-flow problems were found during a live walkthrough:

1. **Leistung (service) list** is a vertical stack of full-width cards — with many services the Termin step becomes an endless scroll.
2. **Clicking a slot** calls `useWizard.chooseSlot()` which both records the slot **and** immediately `go('kind')` — the user is teleported to the child-info block with no "selected" state and no explicit confirmation.
3. **The KIND block header overflows its box.** It's a `<fieldset>` whose `<legend>` (avatar + "KIND") straddles the top border by native HTML behaviour; with a filled, rounded fieldset the round icon is clipped above the box. Tailwind preflight neutralises legend padding but not the straddle.
4. **The DSGVO consent has no privacy link.** `ConfirmStep.vue` renders a hardcoded consent sentence with no link to a Datenschutzerklärung — consent is not "informed" (DSGVO Art. 6(1)(a) + 7, Art. 13).
5. **The Success screen is a security + UX problem.** It prints `result.cancellation_token` (the opaque bearer secret that lets anyone cancel — the same value in the email `/storno/{token}` link) labelled "Stornierungs-Referenz". It is a dead-end (no "book another" / "done" action; the only way forward is reloading the page), there is no human-friendly booking number, and cancellation uses a native blocking `window.confirm()`.

## Goal

The practice configures the widget's full visual identity — primary/accent/background colours, logo, typography, corner radius — from the authenticated staff app, with a **live preview**, and the public widget renders those choices at runtime (no rebuild). The five booking-flow issues are fixed in the same effort, including a non-secret booking reference and removal of the on-screen cancellation secret.

A practice that has configured **nothing** must render byte-for-byte the current pastel look (the current hardcoded values become the documented defaults).

## Non-goals (this design)

- Multi-tenant theming. The app is single-tenant (`Setting` is global key-value). The design naturally extends to per-tenant later but does not implement it now.
- Arbitrary font uploads. Typography is a **curated allow-list** of ~5 fonts (perf + Shadow-DOM `@font-face` + supply-chain reasons).
- Preset palettes. The owner chose **custom-only** (colour pickers + "reset to defaults"); no canned palettes.
- Reworking the booking engine, slot maths, rate limits, or the email-sending pipeline.

## Design

### A. Backend — configuration foundation (single server-side source of truth)

**Settings** (stored via the existing `App\Models\Setting` key-value model — read-through cache-forever, written with `Setting::put()` which forgets the cache key):

- `widget_theme` — one JSON blob:
  ```json
  {
    "colorPrimary": "#6B8FA3",
    "colorPrimaryTo": "#C40C78",
    "colorAccent": "#EC0A8C",
    "colorBackground": "#FFFFFF",
    "colorText": "#26257F",
    "fontHeading": "Fredoka",
    "fontBody": "Nunito",
    "radius": "26px"
  }
  ```
- `widget_logo_path` — string|null, path on the `public` disk.
- `datenschutz_url` — string|null.
- `impressum_url` — string|null.

**Why one JSON blob for the theme:** the visual tokens always travel together, extend without new columns/keys, and cache as a single entry. Logo/URLs stay separate keys (they are not "theme" and have independent validation).

**Config endpoint** — `GET /api/v1/widget/config`, new `App\Http\Controllers\Widget\ConfigController`, registered in `routes/api.php` under the existing `v1/widget` group inside the `throttle:widget-read` middleware (anonymous, IP-throttled 20/min). Response shape:

```json
{
  "theme": { "colorPrimary": "#6B8FA3", "colorPrimaryTo": "#C40C78", "colorAccent": "#EC0A8C",
             "colorBackground": "#FFFFFF", "colorText": "#26257F",
             "fontHeading": "Fredoka", "fontBody": "Nunito", "radius": "26px" },
  "logoUrl": "https://host/storage/widget/logo.png",
  "datenschutzUrl": "https://praxis.example/datenschutz",
  "impressumUrl": null
}
```

The **default theme is a constant in the controller**. When `widget_theme` is unset, the controller returns these defaults verbatim — the current pastel look — so an unconfigured practice sees no change. `logoUrl` is the asset URL built from `widget_logo_path` (or `null`). The endpoint is read-through cached by the `Setting` model's own cache; no extra cache layer.

**Non-secret booking reference** — `App\Models\Tenant\Appointment` gains:

```php
public function publicReference(): string
{
    // Derived from the RANDOM TAIL of the UUID v7 primary key — NOT the
    // cancellation_token secret. Stable, no migration, safe to show/print.
    // ⚠️ uuid7's PREFIX is a millisecond timestamp (HasUuids default since
    // Laravel 11) — substr(0,6) would give every same-window booking the
    // identical reference. Only the tail is random. ~24 bits: collisions are
    // tolerable for a display-only reference (no lookup endpoint).
    return 'KC-' . strtoupper(substr(str_replace('-', '', (string) $this->id), -6));
}
```

`Widget\AppointmentController::store()` adds `reference` to its JSON response. The widget `BookingResult` type gains `reference: string`. The confirmation/reminder/cancelled e-mail Blades show `KC-XXXX` (they currently show the date/time only). The `cancellation_token` stays in the response (the widget still needs it to drive the in-flow cancel and it remains the `/storno` link secret) but is **never rendered as text** in the widget.

### B. Widget — theming layer

**`tailwind.config` (widget build) bound to CSS variables.** Add semantic colours whose values are CSS custom properties:

```js
colors: {
  primary:   'var(--masinga-primary)',
  'primary-to': 'var(--masinga-primary-to)',
  accent:    'var(--masinga-accent)',
  'widget-bg': 'var(--masinga-bg)',
  'widget-text': 'var(--masinga-text)',
}
```

So `bg-primary`, `text-widget-text`, `ring-accent` resolve at runtime. Soft tints (`#FFF4F7`, `#FCE3E9`) become **derived** in CSS with `color-mix(in srgb, var(--masinga-accent) 12%, white)` — only the master colours are configurable; the tints stay automatically coherent. Gradients use `--masinga-primary` → `--masinga-primary-to`.

**`useTheme.ts`** — a small composable: fetch `GET /config` once on mount, then apply the tokens as CSS variables on the Shadow host, and expose `logoUrl` / `datenschutzUrl` / `impressumUrl` (provided via Vue `provide`/`inject` or a returned reactive object). Variable application happens in `main.ts` right after the shadow root is created, on a wrapper element:

```ts
const setVar = (k: string, v: string) => container.style.setProperty(k, v)
// from fetched theme: --masinga-primary, --masinga-primary-to, --masinga-accent,
// --masinga-bg, --masinga-text, --masinga-radius, --masinga-font-heading, --masinga-font-body
```

Defaults are also declared in `widget.css` on `:host` so the first paint (before the fetch resolves) already shows the default look — the fetch only overrides.

**Colour refactor.** Replace hardcoded hex utilities/inline styles across the eight components with the semantic tokens (`text-[#26257F]` → `text-widget-text`, gradient inline styles → `var(--masinga-primary)`/`--masinga-primary-to`, focus rings `#EC0A8C` → `accent`, etc.). Service/practitioner dot colours (`s.color`, `p.color`) stay data-driven — they come from the staff CRUD, not the theme.

**Typography.** A curated allow-list (e.g. `Fredoka`, `Nunito`, `Inter`, `Poppins`, `system-ui`). Each maps to a known `@font-face`/stack injected into the shadow style. `--masinga-font-heading` / `--masinga-font-body` select among them. No arbitrary URLs.

**Radius.** `--masinga-radius` drives the outer card and is the base for the rounded sub-elements (via `calc()` where smaller radii are needed).

### C. Staff — settings page + live preview

- **Route** `/erscheinungsbild` → `tenant.appearance.index` (GET) + `tenant.appearance.update` (POST), in the staff group `['auth', 'two-factor.enrolled']`. German URL, English route name.
- **Controller** `App\Http\Controllers\Tenant\AppearanceController` (`index` renders the Inertia page with current settings; `update` validates + `Setting::put()` each key + stores the logo).
- **Form request** `App\Http\Requests\Tenant\StoreAppearanceRequest`:
  - colours: `regex:/^#[0-9A-Fa-f]{6}$/` each.
  - `fontHeading` / `fontBody`: `in:<allow-list>`.
  - `radius`: numeric/bounded (e.g. `0`–`40` px) or `in:` a small set.
  - `logo`: `nullable|image|mimes:png,jpg,svg,webp|max:512` (KB).
  - `datenschutz_url` / `impressum_url`: `nullable|url`.
- **Logo storage:** `public` disk under `widget/` (`storage:link` required — listed in ops). Path saved to `widget_logo_path`; replacing deletes the prior file.
- **Page** `resources/js/Pages/Tenant/Appearance.vue`: colour pickers (`<input type="color">` + hex text input kept in sync), font selects, radius slider, logo upload + thumbnail, Datenschutz/Impressum URL fields, "Auf Standard zurücksetzen" (reset to defaults) button. **DSGVO warning banner** shown when `datenschutz_url` is empty.
- **Live preview:** a side panel rendering the widget's key surfaces (logo, a primary button, a card, the step indicator, a slot tile, the consent line) styled by the **same CSS variables**, bound to the in-progress (unsaved) form state — updates instantly as colours/font/radius/logo change. Not the full booking flow (the widget is a separate IIFE build; sharing every step component across builds is out of scope) — a faithful token preview, which is what the user validates.
- **Nav:** an "Erscheinungsbild" entry (palette icon) in the data-driven nav array of `resources/js/Layouts/TenantLayout.vue`.

### D. Booking-flow fixes (applied together with the theming)

1. **Leistung → `ServiceSelect.vue`** — an accessible combobox (button + `role="listbox"`/`option`, `aria-expanded`, full keyboard: ↑/↓/Enter/Esc/type-ahead), each option showing the colour dot + "45 Min." badge. Replaces the stacked `<button data-service>` block in `TerminStep.vue`. The `data-service`/`data-service-id` hooks the widget tests rely on are preserved on the options.
2. **Slot select + Weiter** — `useWizard.chooseSlot(slot)` becomes `selection.slot = slot` only (no `go`). `TerminStep.vue` highlights the chosen slot and shows a recap ("Di 12.06 · 14:30 · Dr X") plus an explicit **Weiter** button that calls `advance()`. `App.vue` wiring updated. The parent can change the slot before advancing.
3. **KIND legend fix** — replace `<fieldset>` + `<legend>` in `KindStep.vue` with `<div role="group" :aria-labelledby="…">` + a normal flow header (keeps the accessible grouping, kills the straddle so the icon no longer clips). Audit `FormStep.vue` for the same fieldset/legend pattern and fix identically.
4. **Datenschutz link** — `ConfirmStep.vue` consent text includes a `<a :href="datenschutzUrl" target="_blank" rel="noopener">Datenschutzerklärung</a>` (+ Impressum link when present), pulled from `useTheme`. **Fallback when `datenschutzUrl` is null:** booking still works (do not break the practice's bookings) and the consent shows the plain sentence without the link; the *staff* Appearance page shows the prominent DSGVO warning so the gap is surfaced to the people who can fix it. This trade-off is deliberate and documented.
5. **Success screen** — render `result.reference` (`KC-XXXX`) instead of the token; **remove the raw `cancellation_token` text entirely**. Add "Neuer Termin buchen" (resets the wizard to the Termin step / clears state) and "Fertig". Replace the native `window.confirm('Termin wirklich stornieren?')` in `App.vue` with an in-widget confirmation (inline or a small Shadow-DOM modal) — no blocking browser dialog.

### Data flow

```text
host page embed → main.ts mounts shadow → widget.css paints DEFAULT tokens on :host
    → useTheme fetches GET /api/v1/widget/config
        → CSS vars overridden with the practice's theme; logo + datenschutzUrl available
booking POST → { reference, cancellation_token, starts_at, ends_at }
    → Success shows KC-XXXX (reference); token stays in JS memory (cancel button) + email link only
staff /erscheinungsbild → edit + live preview → save → Setting::put() (cache forgot)
    → next widget load fetches the new config
```

### Error handling

- Config fetch fails (network/429) → widget keeps the default tokens already on `:host`; the booking flow is unaffected.
- Logo URL 404 / unset → no logo rendered (graceful).
- Invalid colour submitted to the staff form → rejected by the form request before any `Setting::put()`.
- `datenschutz_url` unset → see fix #4 fallback.

## Testing

**Backend (Pest):**
- `ConfigController`: returns the documented defaults when settings are unset; returns configured values after `Setting::put()`; reflects an updated value (cache invalidation through `Setting::put`).
- `AppearanceController` / `StoreAppearanceRequest`: a malformed hex / font outside the allow-list / non-URL is rejected; a valid payload persists each key; logo upload stores a file and saves the path; the route requires `auth` + `two-factor.enrolled`.
- `Appointment::publicReference()`: matches `^KC-[0-9A-F]{6}$`, is stable across reads, and differs from `cancellation_token`. The widget booking response includes `reference`.

**Widget (Vitest + @vue/test-utils):**
- `useTheme` applies the fetched tokens as CSS variables and exposes `datenschutzUrl`.
- `ServiceSelect`: opens/closes, selects via keyboard, emits the chosen service, exposes the `data-service-id` hook, keeps dot + duration.
- Slot click sets the selection **without** advancing; the **Weiter** button advances (regression guard for fix #2).
- `ConfirmStep` renders the Datenschutz link when a URL is provided and the plain sentence when it is null.
- `SuccessStep` renders `reference` and **never** the `cancellation_token`; "Neuer Termin" resets the flow.

## Acceptance criteria

- [ ] An unconfigured practice renders the exact current pastel look (defaults).
- [ ] Changing primary/accent/background/text colour, logo, font, and radius in `/erscheinungsbild` changes the live preview and, after save, the public widget — with no rebuild.
- [ ] `GET /api/v1/widget/config` is anonymous, `widget-read`-throttled, and cache-correct.
- [ ] Leistung is an accessible combobox; choosing a slot shows a selected state and requires "Weiter"; the KIND header sits inside its box.
- [ ] The consent shows a working Datenschutz link when configured; the staff page warns when it is not.
- [ ] The Success screen shows `KC-XXXX`, never the cancellation token, offers "Neuer Termin"/"Fertig", and cancellation no longer uses `window.confirm`.
- [ ] `auth` + `two-factor.enrolled` protect the settings route; colour/url/logo inputs are validated.
- [ ] New behaviour covered by passing Pest + Vitest tests; full suite green; Pint clean.
- [ ] German URL (`/erscheinungsbild`), English route names; no hardcoded paths.

## Risks / watch-outs

- **Colour-refactor breadth.** Eight components carry hardcoded hex; a missed one renders an off-theme element. Mitigate by grepping the hex set to zero before sign-off and binding Tailwind colours centrally.
- **`color-mix()` support.** Modern browsers support it; if a target browser does not, fall back to a fixed tint variable. Verify against the practice's audience.
- **Fonts in Shadow DOM.** `@font-face` must live inside the shadow style (document-level faces don't always pierce the boundary). Keep the curated set and inject faces with the CSS.
- **Live-preview fidelity.** The preview reuses tokens, not the real step components (separate IIFE build). It demonstrates colours/logo/font/radius, not pixel-identical steps — acceptable per the owner's intent, but state it in the UI so expectations are set.
- **DSGVO fallback.** Allowing booking without a configured privacy link is a pragmatic choice (don't break live bookings) traded against strict compliance; the staff warning is the compensating control. Re-evaluate if the practice wants a hard block.
- **`storage:link`.** The logo needs the public symlink on the VPS — an ops step, not code.

## Decomposition

Sizable. The implementation plan will phase it **A (backend foundation) → B (widget theming) → D fixes that depend on A/B → C (staff page + preview)**, and may land as **one or two PRs** (foundation+theming, then UX fixes). Default per the owner: one cohesive chantier; the 1-vs-2-PR split is decided at plan time based on diff size.
