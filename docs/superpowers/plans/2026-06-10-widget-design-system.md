# Widget Design System + Booking-Flow Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Practice-configurable widget theming (colors, logo, fonts, radius) served by a public config endpoint and applied as CSS variables in the Shadow DOM, plus five booking-flow fixes (service combobox, slot select+Weiter, KIND legend overflow, DSGVO link, non-secret booking reference).

**Architecture:** Settings live in the existing `Setting` key-value model (cache-forever). A new anonymous `GET /api/v1/widget/config` returns theme+logo+legal URLs with hardcoded defaults equal to today's look. The widget gets a dedicated Tailwind config whose semantic colors resolve to `--masinga-*` CSS variables; `useTheme.ts` fetches the config and overrides the variables at runtime. A staff Inertia page `/erscheinungsbild` edits everything with a live token preview.

**Tech Stack:** Laravel 13 · Pest 4 · PostgreSQL · Vue 3 `<script setup lang="ts">` · Tailwind 3 · Vitest + @vue/test-utils · IIFE widget build (`vite.widget.config.js`).

**Branch:** `feature/widget-design-system` (already created from `origin/main`; spec committed).
**All backend commands run from `backend/`.** Spec: `docs/superpowers/specs/2026-06-10-widget-design-system-design.md`.

**Conventions that bind every task:**
- German URLs, English route names; never hardcode paths in app code — use `route('name')`.
- TDD: write the failing test, watch it fail, implement, watch it pass, commit.
- `composer test` = full backend suite. `npm run test:widget` = widget Vitest suite. `vendor/bin/pint --dirty` before each commit of PHP.
- The widget test hooks (`data-service`, `data-slot`, `data-consent`, `data-kind-advance`, …) are load-bearing — preserve them.

---

## Phase A — Backend foundation

### Task 1: Non-secret booking reference (`publicReference()`)

**Files:**
- Modify: `backend/app/Models/Tenant/Appointment.php` (add method)
- Modify: `backend/app/Http/Controllers/Widget/AppointmentController.php` (response)
- Modify: `backend/resources/views/emails/confirmation.blade.php`, `reminder.blade.php`, `cancelled-parent.blade.php` (add Referenz line)
- Test: `backend/tests/Feature/TenantSchema/AppointmentReferenceTest.php` (new)
- Modify test: `backend/tests/Feature/TenantSchema/WidgetBookingTest.php:51` (assert `reference` in structure)

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/TenantSchema/AppointmentReferenceTest.php`:

```php
<?php

use App\Models\Tenant\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('derives a short uppercase reference from the uuid id', function () {
    $appointment = Appointment::factory()->create();

    expect($appointment->publicReference())
        ->toMatch('/^KC-[0-9A-F]{6}$/')
        ->and($appointment->publicReference())
        ->toBe('KC-'.strtoupper(substr(str_replace('-', '', (string) $appointment->id), 0, 6)));
});

it('is stable across reads and differs from the cancellation token', function () {
    $appointment = Appointment::factory()->create();
    $fresh = Appointment::findOrFail($appointment->id);

    expect($fresh->publicReference())->toBe($appointment->publicReference())
        ->and($appointment->publicReference())->not->toContain($appointment->cancellation_token);
});
```

- [ ] **Step 2: Run it — must fail**

Run: `php artisan test --filter=AppointmentReferenceTest`
Expected: FAIL — `Call to undefined method ... publicReference()`.

- [ ] **Step 3: Implement the accessor**

In `backend/app/Models/Tenant/Appointment.php`, add after `service()`:

```php
    /**
     * Human-friendly booking reference, derived from the RANDOM TAIL of the
     * UUID v7 primary key. NOT the cancellation_token secret — safe to show
     * on screen, in emails, and to quote on the phone.
     *
     * ⚠️ uuid7's prefix is a millisecond timestamp (HasUuids default) — using
     * substr(0,6) gives every same-~4.7h-window booking the SAME reference
     * (caught in code review, fixed in 404d443). Only the tail is random.
     */
    public function publicReference(): string
    {
        return 'KC-'.strtoupper(substr(str_replace('-', '', (string) $this->id), -6));
    }
```

- [ ] **Step 4: Run it — must pass**

Run: `php artisan test --filter=AppointmentReferenceTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Failing test for the booking response**

In `backend/tests/Feature/TenantSchema/WidgetBookingTest.php`, line 51, extend the structure assertion:

```php
    ]))->assertCreated()->assertJsonStructure(['reference', 'cancellation_token', 'starts_at', 'ends_at']);
```

Run: `php artisan test --filter=WidgetBookingTest`
Expected: FAIL — missing `reference`.

- [ ] **Step 6: Add `reference` to the booking response**

In `backend/app/Http/Controllers/Widget/AppointmentController.php`, the final `return`:

```php
        return response()->json([
            'reference' => $appointment->publicReference(),
            'cancellation_token' => $appointment->cancellation_token,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
        ], 201);
```

Run: `php artisan test --filter=WidgetBookingTest` → PASS.

- [ ] **Step 7: Add the Referenz line to the parent-facing emails**

In `backend/resources/views/emails/confirmation.blade.php`, `reminder.blade.php`, and `cancelled-parent.blade.php`, add as the FIRST bullet of the existing `-` list (each file has a `- **Datum:** …` list):

```blade
- **Referenz:** {{ $appointment->publicReference() }}
```

(`cancelled.blade.php` is the cabinet-internal alert — leave it; the cabinet works with names/times.)

Run: `php artisan test` (mail render tests must stay green).

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat(booking): non-secret public reference KC-XXXXXX in API response and parent emails"
```

---

### Task 2: Public widget config endpoint

**Files:**
- Create: `backend/app/Http/Controllers/Widget/ConfigController.php`
- Modify: `backend/routes/api.php` (one route in the `widget-read` group)
- Test: `backend/tests/Feature/TenantSchema/WidgetConfigTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/TenantSchema/WidgetConfigTest.php`:

```php
<?php

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the documented defaults when nothing is configured', function () {
    $this->getJson('/api/v1/widget/config')
        ->assertOk()
        ->assertJson([
            'theme' => [
                'colorPrimary' => '#6B8FA3',
                'colorPrimaryTo' => '#C40C78',
                'colorAccent' => '#EC0A8C',
                'colorBackground' => '#FFFFFF',
                'colorText' => '#26257F',
                'fontHeading' => 'Fredoka',
                'fontBody' => 'Nunito',
                'radius' => '26px',
            ],
            'logoUrl' => null,
            'datenschutzUrl' => null,
            'impressumUrl' => null,
        ]);
});

it('returns configured values merged over the defaults', function () {
    Setting::put('widget_theme', json_encode(['colorPrimary' => '#123456', 'radius' => '8px']));
    Setting::put('datenschutz_url', 'https://praxis.example/datenschutz');

    $this->getJson('/api/v1/widget/config')
        ->assertOk()
        ->assertJsonPath('theme.colorPrimary', '#123456')
        ->assertJsonPath('theme.radius', '8px')
        ->assertJsonPath('theme.colorAccent', '#EC0A8C') // default survives partial config
        ->assertJsonPath('datenschutzUrl', 'https://praxis.example/datenschutz');
});

it('reflects an update immediately (Setting::put invalidates its cache)', function () {
    Setting::put('widget_theme', json_encode(['colorPrimary' => '#111111']));
    $this->getJson('/api/v1/widget/config')->assertJsonPath('theme.colorPrimary', '#111111');

    Setting::put('widget_theme', json_encode(['colorPrimary' => '#222222']));
    $this->getJson('/api/v1/widget/config')->assertJsonPath('theme.colorPrimary', '#222222');
});

it('builds the public logo url from widget_logo_path', function () {
    Setting::put('widget_logo_path', 'widget/logo.png');

    $this->getJson('/api/v1/widget/config')
        ->assertJsonPath('logoUrl', \Illuminate\Support\Facades\Storage::disk('public')->url('widget/logo.png'));
});
```

- [ ] **Step 2: Run it — must fail**

Run: `php artisan test --filter=WidgetConfigTest`
Expected: FAIL — 404 (route does not exist).

- [ ] **Step 3: Implement controller + route**

Create `backend/app/Http/Controllers/Widget/ConfigController.php`:

```php
<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ConfigController extends Controller
{
    /**
     * The current hardcoded widget look, verbatim. An unconfigured practice
     * MUST render exactly this — the widget also declares the same values as
     * CSS defaults for the first paint before this endpoint resolves.
     */
    public const DEFAULT_THEME = [
        'colorPrimary' => '#6B8FA3',
        'colorPrimaryTo' => '#C40C78',
        'colorAccent' => '#EC0A8C',
        'colorBackground' => '#FFFFFF',
        'colorText' => '#26257F',
        'fontHeading' => 'Fredoka',
        'fontBody' => 'Nunito',
        'radius' => '26px',
    ];

    public function show(): JsonResponse
    {
        $stored = json_decode(Setting::get('widget_theme') ?? '', true);
        $logoPath = Setting::get('widget_logo_path');

        return response()->json([
            'theme' => array_merge(self::DEFAULT_THEME, is_array($stored) ? $stored : []),
            'logoUrl' => $logoPath ? Storage::disk('public')->url($logoPath) : null,
            'datenschutzUrl' => Setting::get('datenschutz_url'),
            'impressumUrl' => Setting::get('impressum_url'),
        ]);
    }
}
```

In `backend/routes/api.php`: add `use App\Http\Controllers\Widget\ConfigController;` and, inside the `throttle:widget-read` group (after the `/availability/days` line):

```php
        Route::get('/config', [ConfigController::class, 'show']);
```

- [ ] **Step 4: Run — must pass**

Run: `php artisan test --filter=WidgetConfigTest` → PASS (4 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat(widget): public GET /api/v1/widget/config with server-side theme defaults"
```

---

## Phase B — Widget theming layer

### Task 3: Widget Tailwind config bound to CSS variables + variable defaults

**Files:**
- Create: `backend/tailwind.widget.config.js`
- Modify: `backend/vite.widget.config.js` (point its PostCSS at the widget config)
- Modify: `backend/vitest.config.ts` (same PostCSS so component tests compile)
- Modify: `backend/resources/js/widget/widget.css` (declare `--masinga-*` defaults on `:host`)

No behaviour change yet — this task only creates the token vocabulary. Gate = builds and suites stay green.

- [ ] **Step 1: Create `backend/tailwind.widget.config.js`**

```js
/**
 * Tailwind config for the STANDALONE WIDGET BUILD ONLY (vite.widget.config.js
 * + vitest). Kept separate from tailwind.config.js so widget tokens (CSS
 * variables set at runtime from /api/v1/widget/config) never collide with the
 * main app's shadcn `primary`/`accent` hsl tokens.
 *
 * Colors use `rgb(var(--…-rgb) / <alpha-value>)` so opacity modifiers like
 * `ring-accent/20` keep working; useTheme sets BOTH the hex var (gradients,
 * inline styles) and the rgb-triplet var (Tailwind alpha).
 */
export default {
    content: ['./resources/js/widget/**/*.{vue,ts}'],
    theme: {
        extend: {
            colors: {
                primary: 'rgb(var(--masinga-primary-rgb) / <alpha-value>)',
                'primary-to': 'rgb(var(--masinga-primary-to-rgb) / <alpha-value>)',
                accent: 'rgb(var(--masinga-accent-rgb) / <alpha-value>)',
                'widget-bg': 'rgb(var(--masinga-bg-rgb) / <alpha-value>)',
                'widget-text': 'rgb(var(--masinga-text-rgb) / <alpha-value>)',
            },
            borderRadius: {
                widget: 'var(--masinga-radius)',
            },
            fontFamily: {
                heading: 'var(--masinga-font-heading)',
                body: 'var(--masinga-font-body)',
            },
        },
    },
}
```

- [ ] **Step 2: Wire the widget build and vitest to it**

In `backend/vite.widget.config.js`, add the imports and a `css` block:

```js
import tailwindcss from 'tailwindcss'
import autoprefixer from 'autoprefixer'
```

and inside `defineConfig({ … })` (sibling of `plugins`):

```js
    css: {
        postcss: {
            plugins: [tailwindcss({ config: './tailwind.widget.config.js' }), autoprefixer()],
        },
    },
```

Add the **identical `css` block** to `backend/vitest.config.ts` (same imports) so mounted components resolve the same utilities.

- [ ] **Step 3: Declare the variable defaults in `widget.css`**

Replace the `:host` block in `backend/resources/js/widget/widget.css` with:

```css
/* ──────────────────────────────────────────────────────────────────
   Theme tokens — defaults = the historical pastel look. Overridden at
   runtime by useTheme from GET /api/v1/widget/config. Each color has a
   hex var (gradients/inline styles) and an rgb-triplet var (Tailwind
   alpha modifiers via tailwind.widget.config.js).
   ────────────────────────────────────────────────────────────────── */
:host {
    --masinga-primary: #6B8FA3;        --masinga-primary-rgb: 107 143 163;
    --masinga-primary-to: #C40C78;     --masinga-primary-to-rgb: 196 12 120;
    --masinga-accent: #EC0A8C;         --masinga-accent-rgb: 236 10 140;
    --masinga-bg: #FFFFFF;             --masinga-bg-rgb: 255 255 255;
    --masinga-text: #26257F;           --masinga-text-rgb: 38 37 127;
    --masinga-radius: 26px;
    --masinga-font-heading: 'Fredoka', system-ui, sans-serif;
    --masinga-font-body: 'Nunito', system-ui, -apple-system, sans-serif;

    /* Derived soft tints — only the master colors are configurable. */
    --masinga-tint-soft: color-mix(in srgb, var(--masinga-accent) 7%, white);   /* was #FFF4F7 */
    --masinga-tint: color-mix(in srgb, var(--masinga-accent) 13%, white);       /* was #FCE3E9 */
    --masinga-gradient: linear-gradient(135deg, var(--masinga-primary) 0%, var(--masinga-primary-to) 100%);

    font-family: var(--masinga-font-body);
}

h1, h2, h3 {
    font-family: var(--masinga-font-heading);
    letter-spacing: 0;
}
```

(The old hardcoded `:host { font-family: 'Nunito' … }` and `h1,h2,h3 { font-family: 'Fredoka' … }` blocks are replaced by the above; keep the reduced-motion block untouched.)

- [ ] **Step 4: Verify everything still builds and passes**

```bash
npm run build:widget    # must succeed
npm run test:widget     # all existing Vitest tests still green
```

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(widget): dedicated tailwind config + --masinga-* CSS variable tokens with pastel defaults"
```

---

### Task 4: `useTheme.ts` — fetch config, apply variables, expose logo/legal URLs

**Files:**
- Create: `backend/resources/js/widget/useTheme.ts`
- Modify: `backend/resources/js/widget/api.ts` (add `config()`)
- Modify: `backend/resources/js/widget/types.ts` (add `WidgetTheme`, `WidgetConfig`, extend `BookingResult` with `reference`)
- Modify: `backend/resources/js/widget/App.vue` (call it; render logo; provide config)
- Test: `backend/tests/widget/useTheme.test.ts` (new)

- [ ] **Step 1: Types + api method**

In `backend/resources/js/widget/types.ts`, change `BookingResult` and append the config types:

```ts
export interface BookingResult { reference: string; cancellation_token: string; starts_at: string; ends_at: string }

export interface WidgetTheme {
    colorPrimary: string
    colorPrimaryTo: string
    colorAccent: string
    colorBackground: string
    colorText: string
    fontHeading: string
    fontBody: string
    radius: string
}

export interface WidgetConfig {
    theme: WidgetTheme
    logoUrl: string | null
    datenschutzUrl: string | null
    impressumUrl: string | null
}
```

In `backend/resources/js/widget/api.ts`, add to the returned object (after `services:`):

```ts
        config: () => request<WidgetConfig>('/config'),
```

and add `WidgetConfig` to the type-only import list.

- [ ] **Step 2: Write the failing test**

Create `backend/tests/widget/useTheme.test.ts`:

```ts
import { describe, it, expect, vi } from 'vitest'
import { applyTheme, hexToRgbTriplet, useTheme, DEFAULT_THEME } from '@widget/useTheme'
import type { WidgetConfig } from '@widget/types'

const cfg: WidgetConfig = {
    theme: { ...DEFAULT_THEME, colorPrimary: '#112233', colorAccent: '#445566', radius: '8px' },
    logoUrl: 'https://x.test/storage/widget/logo.png',
    datenschutzUrl: 'https://praxis.test/datenschutz',
    impressumUrl: null,
}

describe('hexToRgbTriplet', () => {
    it('converts #RRGGBB to a space-separated triplet', () => {
        expect(hexToRgbTriplet('#112233')).toBe('17 34 51')
        expect(hexToRgbTriplet('#FFFFFF')).toBe('255 255 255')
    })
})

describe('applyTheme', () => {
    it('sets hex and rgb variables plus radius and fonts on the element', () => {
        const el = document.createElement('div')
        applyTheme(el, cfg.theme)
        expect(el.style.getPropertyValue('--masinga-primary')).toBe('#112233')
        expect(el.style.getPropertyValue('--masinga-primary-rgb')).toBe('17 34 51')
        expect(el.style.getPropertyValue('--masinga-accent')).toBe('#445566')
        expect(el.style.getPropertyValue('--masinga-radius')).toBe('8px')
        expect(el.style.getPropertyValue('--masinga-font-heading')).toContain('Fredoka')
    })
})

describe('useTheme', () => {
    it('loads the config, applies it, and exposes the urls', async () => {
        const api = { config: vi.fn().mockResolvedValue(cfg) }
        const el = document.createElement('div')
        const t = useTheme(api as any)
        await t.load(el)
        expect(el.style.getPropertyValue('--masinga-primary')).toBe('#112233')
        expect(t.state.config?.datenschutzUrl).toBe('https://praxis.test/datenschutz')
        expect(t.state.config?.logoUrl).toContain('logo.png')
    })

    it('keeps the CSS defaults silently when the fetch fails', async () => {
        const api = { config: vi.fn().mockRejectedValue({ kind: 'network' }) }
        const el = document.createElement('div')
        const t = useTheme(api as any)
        await t.load(el) // must not throw
        expect(el.style.getPropertyValue('--masinga-primary')).toBe('') // untouched → :host defaults rule
        expect(t.state.config).toBeNull()
    })
})
```

Run: `npm run test:widget -- useTheme` → FAIL (module not found).

- [ ] **Step 3: Implement `useTheme.ts`**

Create `backend/resources/js/widget/useTheme.ts`:

```ts
import { reactive } from 'vue'
import type { Api } from './api'
import type { WidgetConfig, WidgetTheme } from './types'

export const DEFAULT_THEME: WidgetTheme = {
    colorPrimary: '#6B8FA3',
    colorPrimaryTo: '#C40C78',
    colorAccent: '#EC0A8C',
    colorBackground: '#FFFFFF',
    colorText: '#26257F',
    fontHeading: 'Fredoka',
    fontBody: 'Nunito',
    radius: '26px',
}

/**
 * ⚠️ SUPERSEDED IN REVIEW (commits f0ec64f + c430c85): Google Fonts is a GDPR
 * violation for the embedding German practice site (LG München I, 20.01.2022 —
 * visitor IPs sent to Google without consent). The shipped implementation
 * SELF-HOSTS the fonts: woff2 vendored in resources/fonts/, served by
 * Widget\FontController at /api/v1/widget/fonts/{file} (whitelist, immutable
 * cache, CORS via api/*, dedicated `widget-font` rate limiter), injected as
 * @font-face <style> built from `${apiBase}/api/v1/widget/fonts/…`.
 * The original Google-Fonts FONT_SOURCES map below is kept ONLY as the
 * historical plan text — do NOT reintroduce it. See useTheme.ts FONT_FACES.
 */
const FONT_SOURCES: Record<string, string | null> = {
    Fredoka: '/* superseded — self-hosted, see note above */',
    Nunito: '/* superseded */',
    Inter: '/* superseded */',
    Poppins: '/* superseded */',
    System: null,
}

export function hexToRgbTriplet(hex: string): string {
    const h = hex.replace('#', '')
    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)).join(' ')
}

function fontStack(name: string): string {
    return name === 'System' ? 'system-ui, -apple-system, sans-serif' : `'${name}', system-ui, sans-serif`
}

function ensureFontLoaded(name: string) {
    const href = FONT_SOURCES[name]
    if (!href) return
    const id = `masinga-font-${name.toLowerCase()}`
    if (document.getElementById(id)) return
    const link = document.createElement('link')
    link.id = id
    link.rel = 'stylesheet'
    link.href = href
    document.head.appendChild(link)
}

export function applyTheme(el: HTMLElement, theme: WidgetTheme) {
    const set = (k: string, v: string) => el.style.setProperty(k, v)
    set('--masinga-primary', theme.colorPrimary)
    set('--masinga-primary-rgb', hexToRgbTriplet(theme.colorPrimary))
    set('--masinga-primary-to', theme.colorPrimaryTo)
    set('--masinga-primary-to-rgb', hexToRgbTriplet(theme.colorPrimaryTo))
    set('--masinga-accent', theme.colorAccent)
    set('--masinga-accent-rgb', hexToRgbTriplet(theme.colorAccent))
    set('--masinga-bg', theme.colorBackground)
    set('--masinga-bg-rgb', hexToRgbTriplet(theme.colorBackground))
    set('--masinga-text', theme.colorText)
    set('--masinga-text-rgb', hexToRgbTriplet(theme.colorText))
    set('--masinga-radius', theme.radius)
    set('--masinga-font-heading', fontStack(theme.fontHeading))
    set('--masinga-font-body', fontStack(theme.fontBody))
}

export function useTheme(api: Pick<Api, 'config'>) {
    const state = reactive<{ config: WidgetConfig | null }>({ config: null })

    async function load(el: HTMLElement) {
        try {
            const cfg = await api.config()
            state.config = cfg
            const theme = { ...DEFAULT_THEME, ...cfg.theme }
            applyTheme(el, theme)
            ensureFontLoaded(theme.fontHeading)
            ensureFontLoaded(theme.fontBody)
        } catch {
            // CSS :host defaults already painted — a failed config fetch
            // must never affect the booking flow.
        }
    }

    return { state, load }
}
```

Run: `npm run test:widget -- useTheme` → PASS.

- [ ] **Step 4: Wire into App.vue (load + logo + provide)**

In `backend/resources/js/widget/App.vue` `<script setup>`: add imports and setup —

```ts
import { useTheme } from './useTheme'
import { provide } from 'vue'   // merge into the existing vue import

const rootEl = ref<HTMLElement | null>(null)
const theme = useTheme(props.api)
provide('widgetConfig', theme.state)
```

In `onMounted`, before loading services:

```ts
    if (rootEl.value) theme.load(rootEl.value) // fire-and-forget; defaults already painted
```

In the template: put `ref="rootEl"` on the root `<div>`, swap its hardcoded look for tokens —

```html
    <div ref="rootEl" class="font-body text-widget-text max-w-md mx-auto bg-widget-bg rounded-widget shadow-[0_24px_70px_-28px_rgba(30,41,59,0.30)] ring-1 ring-slate-100/80 p-6 sm:p-7 space-y-4">
```

and render the logo as the first child (before `<StepIndicator>`):

```html
        <img v-if="theme.state.config?.logoUrl" :src="theme.state.config.logoUrl" alt=""
             class="mx-auto mb-1 max-h-12 w-auto" data-widget-logo>
```

Also fix the fakeApi in `backend/tests/widget/app.test.ts` `beforeEach` — add the stub (and `reference` to `book`):

```ts
        config: vi.fn().mockResolvedValue({ theme: {}, logoUrl: null, datenschutzUrl: null, impressumUrl: null }),
        book: vi.fn().mockResolvedValue({ reference: 'KC-0BBAD2', cancellation_token: 'tok-123', starts_at: `${today}T09:00:00+02:00`, ends_at: `${today}T09:30:00+02:00` }),
```

- [ ] **Step 5: Full widget suite green + build**

```bash
npm run test:widget && npm run build:widget
```

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(widget): useTheme runtime theming — config fetch, CSS vars, fonts, logo"
```

---

### Task 5: Color refactor — zero hardcoded theme hex in widget components

**Files (modify all):**
- `backend/resources/js/widget/App.vue` (banner area only — root done in Task 4)
- `backend/resources/js/widget/components/StepIndicator.vue`
- `backend/resources/js/widget/components/BookingCalendar.vue`
- `backend/resources/js/widget/steps/TerminStep.vue`, `KindStep.vue`, `FormStep.vue`, `ConfirmStep.vue`, `SuccessStep.vue`

This is a mechanical recolor. **The verification gate is absolute:** after this task the grep below returns nothing. Apply the mapping table exactly; do not redesign anything.

**Mapping table (old → new):**

| Old (hardcoded) | New (token) |
|---|---|
| `text-[#26257F]`, `text-[#211F66]` | `text-widget-text` |
| `text-[#4B4A9E]`, `text-[#5A5996]` | `text-widget-text/70` |
| `text-[#EC0A8C]`, `text-[#C40C78]` | `text-accent` |
| `bg-[#FFF4F7]` | `style="background-color: var(--masinga-tint-soft)"` (or class `bg-tint-soft` — see step 1) |
| `bg-[#FCE3E9]`, `hover:bg-[#FCE3E9]`, `hover:bg-[#FFF4F7]` | tint classes (step 1) |
| `border-[#EC0A8C]…`, `ring-[#EC0A8C]/N`, `focus-visible:ring-[#EC0A8C]/N` | `border-accent…`, `ring-accent/N`, `focus-visible:ring-accent/N` |
| `border-[#FBB9C4]`, `ring-[#FBB9C4]/N`, `focus:ring-[#FBB9C4]/15` | `border-accent/40`, `ring-accent/30` (the pink `#FBB9C4` was a lighter accent — use accent at reduced alpha) |
| inline `linear-gradient(135deg, #6B8FA3 0%, #C40C78 100%)` | `background: var(--masinga-gradient)` |
| inline `linear-gradient(90deg, #6B8FA3 0%, #C40C78 100%)` (StepIndicator fill) | `linear-gradient(90deg, var(--masinga-primary) 0%, var(--masinga-primary-to) 100%)` |
| StepIndicator active gradient `#EC0A8C → #3D5F72` | `linear-gradient(135deg, var(--masinga-accent) 0%, var(--masinga-primary) 100%)` |
| `accentColor: '#EC0A8C'` (ConfirmStep checkbox) | `accentColor: 'var(--masinga-accent)'` |
| skeleton `linear-gradient(90deg, #FCE3E9 25%, #F8D3DE 50%, #FCE3E9 75%)` | `linear-gradient(90deg, var(--masinga-tint) 25%, color-mix(in srgb, var(--masinga-accent) 20%, white) 50%, var(--masinga-tint) 75%)` |
| rgba shadows/tints `rgba(90,122,145,…)`, `rgba(74,107,126,…)` | `rgb(var(--masinga-primary-rgb) / 0.NN)` (same alpha) |
| `rgba(248,250,251,…)`, `rgba(238,243,246,…)` neutral greys | keep as-is (neutral, not theme) |
| slot fallback colors `s.color \|\| '#FBB9C4'` (service dot), `p.color` | keep — data-driven from staff CRUD, not theme |
| `bg-white` on inputs/cards inside the widget | `bg-widget-bg` |
| SuccessStep cancelled grey gradient `#e2e8ec → #cbd5db` | keep (neutral state, not theme) |

- [ ] **Step 1: Add tint utility classes to `widget.css`** (Tailwind can't alpha-mix two tokens, so tints are plain CSS):

```css
.bg-tint-soft { background-color: var(--masinga-tint-soft); }
.bg-tint { background-color: var(--masinga-tint); }
.hover\:bg-tint:hover { background-color: var(--masinga-tint); }
.hover\:bg-tint-soft:hover { background-color: var(--masinga-tint-soft); }
```

- [ ] **Step 2: Apply the mapping file by file.** Work through the 8 files; after each file run `npm run test:widget` (tests assert behaviour/hooks, not colors — they must stay green).

- [ ] **Step 3: The absolute gate — grep must return ZERO lines:**

```bash
grep -rnE '#(26257F|211F66|4B4A9E|5A5996|EC0A8C|C40C78|6B8FA3|FCE3E9|FFF4F7|F8D3DE|3D5F72)' resources/js/widget --include='*.vue'
```

(`useTheme.ts`/`widget.css` defaults are the single allowed home of these hex values. `FBB9C4` is deliberately absent from the pattern: the `s.color || '#FBB9C4'` service-dot fallbacks are data-driven, not theme — every OTHER `#FBB9C4` usage, e.g. in `ring`/`border`/`hover` utilities, maps to `accent` per the table and must disappear.)

- [ ] **Step 4: Build + visual sanity**

```bash
npm run test:widget && npm run build:widget
```

Then load `public/widget-preview.html` (exists locally) via `php artisan serve` in Chrome — the widget must look IDENTICAL to before (defaults). Then in the console of the page, prove runtime theming works:

```js
document.querySelector('[data-masinga-booking]').shadowRoot
  .querySelector('[class*=max-w-md]').style.setProperty('--masinga-accent', '#0E7C3A')
```

→ accents must flip to green without reload.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "refactor(widget): replace all hardcoded theme hex with --masinga-* tokens"
```

---

## Phase D — Booking-flow fixes

### Task 6: `ServiceSelect.vue` — accessible Leistung combobox

**Files:**
- Create: `backend/resources/js/widget/components/ServiceSelect.vue`
- Modify: `backend/resources/js/widget/steps/TerminStep.vue` (replace the stacked buttons)
- Test: `backend/tests/widget/ServiceSelect.test.ts` (new)
- Modify test: `backend/tests/widget/TerminStep.test.ts` + `app.test.ts` (open the listbox before clicking `[data-service]`)

- [ ] **Step 1: Write the failing test**

Create `backend/tests/widget/ServiceSelect.test.ts`:

```ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ServiceSelect from '@widget/components/ServiceSelect.vue'

const services = [
    { id: 1, name: 'Erstuntersuchung Kind', duration_minutes: 45, color: '#EC0A8C' },
    { id: 2, name: 'Notfall', duration_minutes: 60, color: '#C40C78' },
    { id: 3, name: 'Prophylaxe', duration_minutes: 30, color: '#98ACBA' },
]

describe('ServiceSelect', () => {
    it('renders a closed combobox with a placeholder', () => {
        const w = mount(ServiceSelect, { props: { services } })
        const btn = w.get('[data-service-trigger]')
        expect(btn.attributes('aria-expanded')).toBe('false')
        expect(btn.text()).toContain('Leistung wählen')
        expect(w.find('[role="listbox"]').exists()).toBe(false)
    })

    it('opens on click and lists every service with duration', async () => {
        const w = mount(ServiceSelect, { props: { services } })
        await w.get('[data-service-trigger]').trigger('click')
        expect(w.get('[data-service-trigger]').attributes('aria-expanded')).toBe('true')
        const opts = w.findAll('[data-service]')
        expect(opts).toHaveLength(3)
        expect(opts[0].text()).toContain('45 Min.')
    })

    it('emits select and closes when an option is clicked', async () => {
        const w = mount(ServiceSelect, { props: { services } })
        await w.get('[data-service-trigger]').trigger('click')
        await w.get('[data-service][data-service-id="2"]').trigger('click')
        expect(w.emitted('select')?.[0]?.[0]).toMatchObject({ id: 2 })
        expect(w.find('[role="listbox"]').exists()).toBe(false)
    })

    it('shows the chosen service on the trigger', async () => {
        const w = mount(ServiceSelect, { props: { services, modelValue: services[1] } })
        expect(w.get('[data-service-trigger]').text()).toContain('Notfall')
    })

    it('full keyboard: ArrowDown navigates, Enter selects, Escape closes', async () => {
        const w = mount(ServiceSelect, { props: { services } })
        const btn = w.get('[data-service-trigger]')
        await btn.trigger('keydown', { key: 'ArrowDown' }) // opens + highlights first
        expect(w.find('[role="listbox"]').exists()).toBe(true)
        await w.get('[role="listbox"]').trigger('keydown', { key: 'ArrowDown' })
        await w.get('[role="listbox"]').trigger('keydown', { key: 'Enter' })
        expect(w.emitted('select')?.[0]?.[0]).toMatchObject({ id: 2 })
        await btn.trigger('keydown', { key: 'ArrowDown' })
        await w.get('[role="listbox"]').trigger('keydown', { key: 'Escape' })
        expect(w.find('[role="listbox"]').exists()).toBe(false)
    })
})
```

Run: `npm run test:widget -- ServiceSelect` → FAIL (component missing).

- [ ] **Step 2: Implement the component**

Create `backend/resources/js/widget/components/ServiceSelect.vue`:

```vue
<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import type { Service } from '../types'

const props = defineProps<{ services: Service[]; modelValue?: Service }>()
const emit = defineEmits<{ select: [service: Service] }>()

const open = ref(false)
const highlighted = ref(0)
const listEl = ref<HTMLElement | null>(null)

const label = computed(() =>
    props.modelValue ? `${props.modelValue.name}` : 'Leistung wählen',
)

async function toggle(toOpen = !open.value) {
    open.value = toOpen
    if (toOpen) {
        highlighted.value = Math.max(0, props.services.findIndex(s => s.id === props.modelValue?.id))
        await nextTick()
        listEl.value?.focus()
    }
}

function choose(s: Service) {
    emit('select', s)
    open.value = false
}

function onTriggerKeydown(e: KeyboardEvent) {
    if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
        e.preventDefault()
        toggle(true)
    }
}

function onListKeydown(e: KeyboardEvent) {
    if (e.key === 'ArrowDown') { e.preventDefault(); highlighted.value = Math.min(highlighted.value + 1, props.services.length - 1) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlighted.value = Math.max(highlighted.value - 1, 0) }
    else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(props.services[highlighted.value]) }
    else if (e.key === 'Escape') { e.preventDefault(); open.value = false }
}
</script>

<template>
    <div class="relative">
        <button type="button" data-service-trigger
                :aria-expanded="open ? 'true' : 'false'" aria-haspopup="listbox"
                @click="toggle()" @keydown="onTriggerKeydown"
                class="flex w-full items-center justify-between rounded-2xl border border-slate-200 bg-widget-bg px-4 py-3.5 text-sm shadow-sm transition-all duration-200 hover:border-accent/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/50 focus-visible:ring-offset-2">
            <span class="flex items-center gap-3 min-w-0">
                <span v-if="modelValue" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl"
                      :style="{ backgroundColor: (modelValue.color || '#FBB9C4') + '28' }" aria-hidden="true">
                    <span class="inline-block h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: modelValue.color || '#FBB9C4' }"></span>
                </span>
                <span class="truncate font-semibold" :class="modelValue ? 'text-widget-text' : 'text-slate-400'">{{ label }}</span>
            </span>
            <span class="flex items-center gap-2 shrink-0 ml-2">
                <span v-if="modelValue" class="inline-flex items-center rounded-full bg-tint px-2.5 py-1 text-[11px] font-semibold text-widget-text/70">
                    {{ modelValue.duration_minutes }} Min.
                </span>
                <svg class="h-4 w-4 text-slate-400 transition-transform" :class="open ? 'rotate-180' : ''"
                     viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
        </button>

        <ul v-if="open" ref="listEl" role="listbox" tabindex="-1" aria-label="Leistung"
            @keydown="onListKeydown"
            class="absolute z-20 mt-2 w-full overflow-hidden rounded-2xl border border-slate-100 bg-widget-bg shadow-xl focus:outline-none">
            <li v-for="(s, i) in services" :key="s.id" role="option"
                data-service :data-service-id="s.id"
                :aria-selected="modelValue?.id === s.id ? 'true' : 'false'"
                @click="choose(s)" @mousemove="highlighted = i"
                class="flex cursor-pointer items-center justify-between px-4 py-3 text-sm transition-colors"
                :class="i === highlighted ? 'bg-tint' : ''">
                <span class="flex items-center gap-3 min-w-0">
                    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg"
                          :style="{ backgroundColor: (s.color || '#FBB9C4') + '28' }" aria-hidden="true">
                        <span class="inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: s.color || '#FBB9C4' }"></span>
                    </span>
                    <span class="truncate font-semibold text-widget-text">{{ s.name }}</span>
                </span>
                <span class="ml-2 shrink-0 rounded-full bg-tint px-2.5 py-1 text-[11px] font-semibold text-widget-text/70">
                    {{ s.duration_minutes }} Min.
                </span>
            </li>
        </ul>
    </div>
</template>
```

(`#FBB9C4` here is the data-driven fallback for a service without a color — allowed, it's not a theme token. Add `FBB9C4` to the Task 5 grep exceptions only for the two `s.color || '#FBB9C4'` fallbacks: concretely, keep the Task 5 gate but allow lines containing `s.color ||` / `modelValue.color ||`.)

Run: `npm run test:widget -- ServiceSelect` → PASS.

- [ ] **Step 3: Replace the stacked list in `TerminStep.vue`**

Remove the whole `<div class="flex flex-col gap-2">…</div>` service-buttons block and the `v-for` buttons; render instead:

```html
        <div class="mt-5">
            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400 mb-3">Leistung</p>
            <ServiceSelect :services="services" :model-value="selectedService"
                           @select="$emit('service-select', $event)" />
        </div>
```

with `import ServiceSelect from '../components/ServiceSelect.vue'`.

- [ ] **Step 4: Update the touched tests**

`TerminStep.test.ts` — the first two tests now need the listbox opened first:

```ts
    it('renders an option per service and emits service-select', async () => {
        const wrapper = mount(TerminStep, { props: { ...base, selectedService: undefined, slots: [] } })
        await wrapper.get('[data-service-trigger]').trigger('click')
        expect(wrapper.findAll('[data-service]')).toHaveLength(1)
        await wrapper.get('[data-service]').trigger('click')
        expect(wrapper.emitted('service-select')?.[0]?.[0]).toMatchObject({ id: 1 })
    })
```

`app.test.ts` `reachKind()` — open the combobox before choosing:

```ts
    await wrapper.get('[data-service-trigger]').trigger('click')
    await wrapper.get('[data-service]').trigger('click') // choose the only service (stays on termin)
```

- [ ] **Step 5: Full suite + commit**

```bash
npm run test:widget
git add -A && git commit -m "feat(widget): accessible Leistung combobox (ServiceSelect) replaces stacked cards"
```

---

### Task 7: Slot selection state + explicit Weiter

**Files:**
- Modify: `backend/resources/js/widget/useWizard.ts` (`chooseSlot` no longer advances; add `confirmSlot`)
- Modify: `backend/resources/js/widget/steps/TerminStep.vue` (selected state, recap, Weiter button)
- Modify: `backend/resources/js/widget/App.vue` (pass `selected-slot`, handle `continue`)
- Modify tests: `backend/tests/widget/wizard.test.ts`, `TerminStep.test.ts`, `app.test.ts`

- [ ] **Step 1: Failing wizard test**

In `backend/tests/widget/wizard.test.ts`, rewrite the `chooseSlot` expectations (3 call sites at lines 19-45): choosing a slot must **stay** on `termin`; a new `confirmSlot()` advances:

```ts
    it('chooseSlot records the slot but stays on termin; confirmSlot advances to kind', () => {
        const w = useWizard()
        w.chooseService(service)
        w.chooseSlot(slot)
        expect(w.step.value).toBe('termin')
        expect(w.selection.slot).toEqual(slot)
        w.confirmSlot()
        expect(w.step.value).toBe('kind')
    })
```

(The other two tests that call `w.chooseSlot(slot)` to *reach* later steps each add `w.confirmSlot()` right after.)

Run: `npm run test:widget -- wizard` → FAIL.

- [ ] **Step 2: Implement in `useWizard.ts`**

```ts
        // Selecting a slot records it but stays on the termin step — the user
        // confirms with an explicit "Weiter" (confirmSlot) and can change their
        // mind freely before that.
        chooseSlot(slot: Slot) { selection.slot = slot },
        confirmSlot() { if (selection.slot) go('kind') },
```

Run: `npm run test:widget -- wizard` → PASS.

- [ ] **Step 3: Failing TerminStep test**

Append to `TerminStep.test.ts`:

```ts
    it('highlights the selected slot, shows a recap and a Weiter button that emits continue', async () => {
        const wrapper = mount(TerminStep, { props: { ...base, slots, selectedSlot: slots[0] } })
        expect(wrapper.get('[data-slot][aria-pressed="true"]').text()).toContain('09:00')
        const recap = wrapper.get('[data-slot-recap]')
        expect(recap.text()).toContain('09:00')
        expect(recap.text()).toContain('Anna')
        await wrapper.get('[data-termin-weiter]').trigger('click')
        expect(wrapper.emitted('continue')).toHaveLength(1)
    })

    it('shows no Weiter button before a slot is chosen', () => {
        const wrapper = mount(TerminStep, { props: { ...base, slots } })
        expect(wrapper.find('[data-termin-weiter]').exists()).toBe(false)
    })
```

Run → FAIL.

- [ ] **Step 4: Implement in `TerminStep.vue`**

Props gain `selectedSlot?: Slot`; emits gain `continue: []`. The slot button gets a selected state:

```html
                <button v-for="s in visibleSlots" :key="s.starts_at + '-' + s.practitioner.id" type="button" data-slot
                        @click="$emit('select', s)"
                        :aria-pressed="isSelected(s) ? 'true' : 'false'"
                        :class="['flex flex-col items-center justify-center gap-0.5 rounded-xl border py-2.5 px-2 transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/40 active:translate-y-0',
                                 isSelected(s)
                                   ? 'border-accent bg-tint ring-2 ring-accent/20 shadow-md'
                                   : 'border-slate-200 bg-widget-bg hover:border-accent/50 hover:bg-tint hover:-translate-y-0.5 hover:shadow-sm']">
```

with the helper + recap pieces in the script:

```ts
const isSelected = (s: Slot) =>
    props.selectedSlot?.starts_at === s.starts_at && props.selectedSlot?.practitioner.id === s.practitioner.id

const recapLabel = computed(() => {
    const s = props.selectedSlot
    if (!s) return ''
    const d = new Date(s.starts_at.slice(0, 10) + 'T12:00:00').toLocaleDateString('de-DE', { weekday: 'short', day: 'numeric', month: 'short' })
    return `${d} · ${time(s.starts_at)} · ${s.practitioner.first_name}`
})
```

and after the slot grid (inside the `v-if="selectedDate"` block):

```html
            <div v-if="selectedSlot" class="mt-4 flex items-center justify-between gap-3 rounded-2xl bg-tint-soft px-4 py-3 ring-1 ring-accent/20">
                <p data-slot-recap class="text-sm font-semibold text-widget-text">{{ recapLabel }}</p>
                <button type="button" data-termin-weiter @click="$emit('continue')"
                        class="inline-flex shrink-0 items-center gap-2 rounded-2xl px-5 py-2.5 text-sm font-bold text-white shadow-md transition-all duration-200 hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 focus-visible:ring-offset-2"
                        style="background: var(--masinga-gradient);">
                    Weiter
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
```

- [ ] **Step 5: Wire App.vue**

```html
        <TerminStep v-if="w.step.value === 'termin'"
                    :services="services" :selected-service="w.selection.service"
                    :selected-slot="w.selection.slot"
                    :available-dates="availableDates" :slots="slots"
                    :loading-slots="loadingSlots" :selected-date="selectedDate"
                    @service-select="onServiceSelect"
                    @month-change="onMonthChange" @pick-date="onPickDate"
                    @select="w.chooseSlot" @continue="() => w.confirmSlot()" />
```

- [ ] **Step 6: Update `app.test.ts` `reachKind()`** — the slot click no longer advances:

```ts
    await wrapper.get('[data-slot]').trigger('click')         // select slot (stays on termin)
    await wrapper.get('[data-termin-weiter]').trigger('click') // explicit Weiter → kind
```

- [ ] **Step 7: Full suite + commit**

```bash
npm run test:widget
git add -A && git commit -m "feat(widget): slot click selects; explicit Weiter confirms (no more auto-jump)"
```

---

### Task 8: KIND/Eltern group-header fix (legend straddle)

**Files:**
- Modify: `backend/resources/js/widget/steps/KindStep.vue:90-99`
- Modify: `backend/resources/js/widget/steps/FormStep.vue:84-93` and `:137-140` (same pattern)

- [ ] **Step 1: Replace fieldset/legend in `KindStep.vue`**

A native `<legend>` straddles the fieldset's top border, so the icon clips above the rounded, filled box. Swap to a `div[role=group]` with an in-flow header:

```html
        <!-- Kind section -->
        <div role="group" aria-labelledby="kind-group-label" class="mt-5 rounded-2xl bg-tint-soft p-4 ring-1 ring-slate-100/80">
            <p id="kind-group-label" class="flex items-center gap-2">
                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full shrink-0"
                      style="background: var(--masinga-gradient);" aria-hidden="true">
                    <svg class="h-3 w-3 text-white" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M8 8a3 3 0 100-6 3 3 0 000 6zM3 14a5 5 0 0110 0H3z"/>
                    </svg>
                </span>
                <span class="text-[11px] font-bold uppercase tracking-[0.15em] text-widget-text/70">Kind</span>
            </p>
            <!-- (inner fields unchanged) -->
        </div>
```

(Closing `</fieldset>` → `</div>`.)

- [ ] **Step 2: Apply the same swap to both fieldsets in `FormStep.vue`** (`:84` the Elternteil box, `:137` the inner group): `fieldset` → `div role="group" aria-labelledby="<unique-id>"`, `legend` → `p id="<unique-id>"`, keeping each header's existing inner markup.

- [ ] **Step 3: Suite green (KindStep/FormStep tests must still pass — they target `[name=…]` inputs, untouched), then commit**

```bash
npm run test:widget
git add -A && git commit -m "fix(widget): group headers no longer straddle their boxes (fieldset/legend → div[role=group])"
```

---

### Task 9: DSGVO — Datenschutz/Impressum links in the consent

**Files:**
- Modify: `backend/resources/js/widget/steps/ConfirmStep.vue` (consent text + injected config)
- Test: extend `backend/tests/widget/ConfirmStep.test.ts`

- [ ] **Step 1: Failing test**

Append to `backend/tests/widget/ConfirmStep.test.ts` (match the file's existing `mount` props pattern — pass the same `selection`/`formData` base used by its other tests):

```ts
    it('links the Datenschutzerklärung when a url is provided', () => {
        const wrapper = mount(ConfirmStep, {
            props: baseProps,
            global: { provide: { widgetConfig: { config: { theme: {}, logoUrl: null, datenschutzUrl: 'https://praxis.test/datenschutz', impressumUrl: null } } } },
        })
        const link = wrapper.get('[data-datenschutz-link]')
        expect(link.attributes('href')).toBe('https://praxis.test/datenschutz')
        expect(link.attributes('target')).toBe('_blank')
        expect(link.attributes('rel')).toContain('noopener')
    })

    it('falls back to the plain sentence without a url', () => {
        const wrapper = mount(ConfirmStep, {
            props: baseProps,
            global: { provide: { widgetConfig: { config: null } } },
        })
        expect(wrapper.find('[data-datenschutz-link]').exists()).toBe(false)
        expect(wrapper.text()).toContain('Ich willige in die Verarbeitung')
    })
```

(If the existing tests mount without the provide, give the inject a default — see step 2 — so they stay green.)

Run → FAIL.

- [ ] **Step 2: Implement**

In `ConfirmStep.vue` script:

```ts
import { inject } from 'vue'
import type { WidgetConfig } from '../types'

const widgetConfig = inject<{ config: WidgetConfig | null }>('widgetConfig', { config: null })
const datenschutzUrl = computed(() => widgetConfig.config?.datenschutzUrl ?? null)
const impressumUrl = computed(() => widgetConfig.config?.impressumUrl ?? null)
```

Replace the consent `<span>` (line 156-158):

```html
      <span class="text-xs leading-relaxed text-widget-text/70">
        Ich willige in die Verarbeitung der angegebenen Daten zur Terminbuchung ein.
        <template v-if="datenschutzUrl">
          Weitere Informationen in der
          <a :href="datenschutzUrl" target="_blank" rel="noopener noreferrer" data-datenschutz-link
             class="font-semibold text-accent underline underline-offset-2" @click.stop>Datenschutzerklärung</a>.
        </template>
        <template v-if="impressumUrl">
          <a :href="impressumUrl" target="_blank" rel="noopener noreferrer" data-impressum-link
             class="text-widget-text/60 underline underline-offset-2" @click.stop>Impressum</a>
        </template>
      </span>
```

(`@click.stop` keeps a link tap from toggling the wrapping checkbox `<label>`.)

- [ ] **Step 3: Suite + commit**

```bash
npm run test:widget
git add -A && git commit -m "feat(widget): DSGVO-konform — Datenschutz/Impressum links in the consent text"
```

---

### Task 10: Success screen — reference, no secret, restart/done, in-widget cancel confirm

**Files:**
- Modify: `backend/resources/js/widget/steps/SuccessStep.vue`
- Modify: `backend/resources/js/widget/App.vue` (`onCancel` loses `window.confirm`; add `onRestart`)
- Modify: `backend/resources/js/widget/useWizard.ts` (add `reset()`)
- Modify tests: `backend/tests/widget/app.test.ts` (+ any SuccessStep assertions in it), `wizard.test.ts`

- [ ] **Step 1: Failing tests**

In `backend/tests/widget/app.test.ts`, find the success-screen test(s) (they assert `tok-123` visibility today) and replace/extend:

```ts
    it('shows the booking reference, never the cancellation token', async () => {
        const wrapper = mount(App, { props: { api: fakeApi } })
        await reachKind(wrapper); await fillKindAndAdvance(wrapper); await fillFormAndAdvance(wrapper); await confirmAndSubmit(wrapper)
        expect(wrapper.text()).toContain('KC-0BBAD2')
        expect(wrapper.text()).not.toContain('tok-123')
    })

    it('cancels via the in-widget confirmation (no window.confirm)', async () => {
        const confirmSpy = vi.spyOn(window, 'confirm')
        const wrapper = mount(App, { props: { api: fakeApi } })
        await reachKind(wrapper); await fillKindAndAdvance(wrapper); await fillFormAndAdvance(wrapper); await confirmAndSubmit(wrapper)
        await wrapper.get('[data-cancel-open]').trigger('click')   // shows inline confirm
        await wrapper.get('[data-cancel-confirm]').trigger('click') // actually cancels
        await flushPromises()
        expect(fakeApi.cancel).toHaveBeenCalledWith('tok-123')
        expect(confirmSpy).not.toHaveBeenCalled()
        expect(wrapper.text()).toContain('Termin storniert')
    })

    it('Neuer Termin resets the wizard back to a clean termin step', async () => {
        const wrapper = mount(App, { props: { api: fakeApi } })
        await reachKind(wrapper); await fillKindAndAdvance(wrapper); await fillFormAndAdvance(wrapper); await confirmAndSubmit(wrapper)
        await wrapper.get('[data-restart]').trigger('click')
        expect(wrapper.find('[data-service-trigger]').exists()).toBe(true) // back on termin
        expect(wrapper.find('[data-restart]').exists()).toBe(false)
    })
```

And in `wizard.test.ts`:

```ts
    it('reset clears the selection and returns to termin', () => {
        const w = useWizard()
        w.chooseService(service); w.chooseSlot(slot); w.confirmSlot()
        w.reset()
        expect(w.step.value).toBe('termin')
        expect(w.selection.service).toBeUndefined()
        expect(w.selection.slot).toBeUndefined()
    })
```

Run → FAIL.

- [ ] **Step 2: `useWizard.reset()`**

```ts
        reset() {
            selection.service = undefined
            selection.slot = undefined
            go('termin')
        },
```

- [ ] **Step 3: Rewrite the confirmed-state block of `SuccessStep.vue`**

Props/emits become:

```ts
defineProps<{ result: BookingResult; cancelled: boolean; cancelling?: boolean }>()
const emit = defineEmits<{ cancel: []; restart: [] }>()
const confirmingCancel = ref(false)
const done = ref(false)
```

Template, confirmed branch — replace the token card + cancel button with:

```html
            <!-- Booking reference — human-friendly, NON-secret (the cancellation
                 token must never be rendered: it is a bearer secret). -->
            <div class="mx-auto mt-5 max-w-xs rounded-xl bg-slate-50 px-3.5 py-2.5 ring-1 ring-slate-200/80">
                <p class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400">Buchungsnummer</p>
                <p data-reference class="mt-1 font-mono text-base font-bold tracking-wider text-widget-text">{{ result.reference }}</p>
            </div>
            <p class="mt-2 text-xs text-slate-400">Den Stornierungslink finden Sie in Ihrer Bestätigungs-E-Mail.</p>

            <template v-if="!done">
                <div class="mt-5 flex items-center justify-center gap-3">
                    <button type="button" data-restart @click="$emit('restart')"
                            class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-xs font-bold text-white shadow-md transition-all duration-200 hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
                            style="background: var(--masinga-gradient);">
                        Neuen Termin buchen
                    </button>
                    <button type="button" data-done @click="done = true"
                            class="inline-flex items-center rounded-full border border-slate-200 bg-widget-bg px-4 py-2 text-xs font-semibold text-widget-text/70 shadow-sm transition hover:bg-slate-50">
                        Fertig
                    </button>
                </div>

                <div v-if="!confirmingCancel" class="mt-4">
                    <button type="button" data-cancel-open @click="confirmingCancel = true"
                            class="text-xs font-semibold text-slate-400 underline underline-offset-2 hover:text-rose-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-300 rounded">
                        Termin stornieren
                    </button>
                </div>
                <div v-else class="mt-4 flex items-center justify-center gap-2 rounded-xl bg-rose-50 px-3 py-2.5 ring-1 ring-rose-200/80">
                    <p class="text-xs font-medium text-rose-700">Termin wirklich stornieren?</p>
                    <button type="button" data-cancel-confirm :disabled="cancelling" @click="$emit('cancel')"
                            class="rounded-full bg-rose-600 px-3 py-1 text-xs font-bold text-white hover:bg-rose-700 disabled:opacity-50">Ja, stornieren</button>
                    <button type="button" data-cancel-keep @click="confirmingCancel = false"
                            class="rounded-full border border-slate-200 bg-widget-bg px-3 py-1 text-xs font-semibold text-widget-text/70">Behalten</button>
                </div>
            </template>
            <p v-else class="mt-5 text-sm text-slate-400">Vielen Dank! Sie können diese Seite jetzt schließen.</p>
```

(`import { ref } from 'vue'` joins the script.)

- [ ] **Step 4: App.vue — drop `window.confirm`, add restart, pass `cancelling`**

```ts
const cancelling = ref(false)

async function onCancel() {
    if (!result.value || cancelling.value) return
    cancelling.value = true
    try {
        await props.api.cancel(result.value.cancellation_token)
        cancelled.value = true
    } catch {
        banner.value = 'Stornierung fehlgeschlagen.'
    } finally {
        cancelling.value = false
    }
}

function onRestart() {
    result.value = null
    cancelled.value = false
    kindData.value = null
    pendingForm.value = null
    serverErrors.value = {}
    banner.value = ''
    slots.value = []
    selectedDate.value = undefined
    availableDates.value = []
    w.reset()
}
```

```html
        <SuccessStep v-else-if="w.step.value === 'success' && result" :result="result"
                     :cancelled="cancelled" :cancelling="cancelling"
                     @cancel="onCancel" @restart="onRestart" />
```

- [ ] **Step 5: Full suite + build + commit**

```bash
npm run test:widget && npm run build:widget
git add -A && git commit -m "feat(widget): success screen — KC reference, secret token removed, restart/done, in-widget cancel confirm"
```

---

## Phase C — Staff settings page

### Task 11: Appearance backend — routes, controller, validation, logo upload

**Files:**
- Create: `backend/app/Http/Controllers/Tenant/AppearanceController.php`
- Create: `backend/app/Http/Requests/Tenant/StoreAppearanceRequest.php`
- Modify: `backend/routes/web.php` (two routes in the staff group, after the QR block)
- Test: `backend/tests/Feature/TenantSchema/AppearanceSettingTest.php` (new)

- [ ] **Step 1: Failing tests**

Create `backend/tests/Feature/TenantSchema/AppearanceSettingTest.php`:

```php
<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('requires authentication', function () {
    $this->get(route('tenant.appearance.index'))->assertRedirect();
});

it('renders the appearance page with the current settings', function () {
    Setting::put('widget_theme', json_encode(['colorPrimary' => '#123456']));
    Setting::put('datenschutz_url', 'https://praxis.test/ds');

    $this->actingAs(User::factory()->create())
        ->get(route('tenant.appearance.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Appearance')
            ->where('theme.colorPrimary', '#123456')
            ->where('theme.colorAccent', '#EC0A8C')   // default fills the gap
            ->where('datenschutzUrl', 'https://praxis.test/ds')
            ->where('logoUrl', null)
            ->has('fontOptions'));
});

it('persists a valid theme payload', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('tenant.appearance.update'), [
            'colorPrimary' => '#0E7C3A', 'colorPrimaryTo' => '#222222',
            'colorAccent' => '#AA00BB', 'colorBackground' => '#FAFAFA', 'colorText' => '#111111',
            'fontHeading' => 'Poppins', 'fontBody' => 'Inter', 'radius' => 12,
            'datenschutz_url' => 'https://praxis.test/datenschutz',
            'impressum_url' => null,
        ])->assertRedirect();

    $theme = json_decode(Setting::get('widget_theme'), true);
    expect($theme['colorPrimary'])->toBe('#0E7C3A')
        ->and($theme['radius'])->toBe('12px')
        ->and(Setting::get('datenschutz_url'))->toBe('https://praxis.test/datenschutz');
});

it('rejects malformed colors, unknown fonts, out-of-range radius and bad urls', function () {
    $valid = [
        'colorPrimary' => '#0E7C3A', 'colorPrimaryTo' => '#222222', 'colorAccent' => '#AA00BB',
        'colorBackground' => '#FAFAFA', 'colorText' => '#111111',
        'fontHeading' => 'Fredoka', 'fontBody' => 'Nunito', 'radius' => 26,
    ];
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('tenant.appearance.update'), ['colorPrimary' => 'red'] + $valid)
        ->assertSessionHasErrors('colorPrimary');
    $this->actingAs($user)->post(route('tenant.appearance.update'), ['fontHeading' => 'Comic Sans'] + $valid)
        ->assertSessionHasErrors('fontHeading');
    $this->actingAs($user)->post(route('tenant.appearance.update'), ['radius' => 99] + $valid)
        ->assertSessionHasErrors('radius');
    $this->actingAs($user)->post(route('tenant.appearance.update'), ['datenschutz_url' => 'not-a-url'] + $valid)
        ->assertSessionHasErrors('datenschutz_url');
});

it('stores an uploaded logo on the public disk and replaces the old one', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $payload = [
        'colorPrimary' => '#6B8FA3', 'colorPrimaryTo' => '#C40C78', 'colorAccent' => '#EC0A8C',
        'colorBackground' => '#FFFFFF', 'colorText' => '#26257F',
        'fontHeading' => 'Fredoka', 'fontBody' => 'Nunito', 'radius' => 26,
    ];

    $this->actingAs($user)->post(route('tenant.appearance.update'),
        $payload + ['logo' => UploadedFile::fake()->image('logo.png', 200, 80)])->assertRedirect();
    $first = Setting::get('widget_logo_path');
    Storage::disk('public')->assertExists($first);

    $this->actingAs($user)->post(route('tenant.appearance.update'),
        $payload + ['logo' => UploadedFile::fake()->image('logo2.png', 200, 80)])->assertRedirect();
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists(Setting::get('widget_logo_path'));
});

it('removes the logo when remove_logo is set', function () {
    Storage::fake('public');
    Storage::disk('public')->put('widget/old.png', 'x');
    Setting::put('widget_logo_path', 'widget/old.png');

    $this->actingAs(User::factory()->create())->post(route('tenant.appearance.update'), [
        'colorPrimary' => '#6B8FA3', 'colorPrimaryTo' => '#C40C78', 'colorAccent' => '#EC0A8C',
        'colorBackground' => '#FFFFFF', 'colorText' => '#26257F',
        'fontHeading' => 'Fredoka', 'fontBody' => 'Nunito', 'radius' => 26,
        'remove_logo' => true,
    ])->assertRedirect();

    expect(Setting::get('widget_logo_path'))->toBeNull();
    Storage::disk('public')->assertMissing('widget/old.png');
});
```

Run: `php artisan test --filter=AppearanceSettingTest` → FAIL (route not defined).

- [ ] **Step 2: Routes**

In `backend/routes/web.php`, inside the `['auth', 'two-factor.enrolled']` group (after the QR-code pair), plus the import `use App\Http\Controllers\Tenant\AppearanceController;`:

```php
    // Widget-Erscheinungsbild (Theme, Logo, Rechtslinks)
    Route::get('/erscheinungsbild', [AppearanceController::class, 'index'])->name('tenant.appearance.index');
    Route::post('/erscheinungsbild', [AppearanceController::class, 'update'])->name('tenant.appearance.update');
```

- [ ] **Step 3: Form request**

Create `backend/app/Http/Requests/Tenant/StoreAppearanceRequest.php`:

```php
<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppearanceRequest extends FormRequest
{
    /** Curated fonts — must match FONT_SOURCES in resources/js/widget/useTheme.ts. */
    public const FONTS = ['Fredoka', 'Nunito', 'Inter', 'Poppins', 'System'];

    public function authorize(): bool
    {
        return true; // single-tenant: any authenticated user is staff
    }

    public function rules(): array
    {
        $hex = ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'colorPrimary' => $hex,
            'colorPrimaryTo' => $hex,
            'colorAccent' => $hex,
            'colorBackground' => $hex,
            'colorText' => $hex,
            'fontHeading' => ['required', Rule::in(self::FONTS)],
            'fontBody' => ['required', Rule::in(self::FONTS)],
            'radius' => ['required', 'integer', 'between:0,40'],
            // svg dropped in review (9f9604a): the `image` rule excludes it anyway
            // (dead mime) and SVG logos are an XSS surface on the public disk.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512'],
            'remove_logo' => ['nullable', 'boolean'],
            'datenschutz_url' => ['nullable', 'url', 'max:2048'],
            'impressum_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
```

- [ ] **Step 4: Controller**

Create `backend/app/Http/Controllers/Tenant/AppearanceController.php`:

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Widget\ConfigController;
use App\Http\Requests\Tenant\StoreAppearanceRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    public function index(): Response
    {
        $stored = json_decode(Setting::get('widget_theme') ?? '', true);
        $logoPath = Setting::get('widget_logo_path');

        return Inertia::render('Tenant/Appearance', [
            'theme' => array_merge(ConfigController::DEFAULT_THEME, is_array($stored) ? $stored : []),
            'logoUrl' => $logoPath ? Storage::disk('public')->url($logoPath) : null,
            'datenschutzUrl' => Setting::get('datenschutz_url'),
            'impressumUrl' => Setting::get('impressum_url'),
            'fontOptions' => StoreAppearanceRequest::FONTS,
        ]);
    }

    public function update(StoreAppearanceRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Setting::put('widget_theme', json_encode([
            'colorPrimary' => $data['colorPrimary'],
            'colorPrimaryTo' => $data['colorPrimaryTo'],
            'colorAccent' => $data['colorAccent'],
            'colorBackground' => $data['colorBackground'],
            'colorText' => $data['colorText'],
            'fontHeading' => $data['fontHeading'],
            'fontBody' => $data['fontBody'],
            'radius' => $data['radius'].'px',
        ]));

        $oldLogo = Setting::get('widget_logo_path');

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('widget', 'public');
            Setting::put('widget_logo_path', $path);
            if ($oldLogo && $oldLogo !== $path) {
                Storage::disk('public')->delete($oldLogo);
            }
        } elseif ($request->boolean('remove_logo') && $oldLogo) {
            Storage::disk('public')->delete($oldLogo);
            Setting::put('widget_logo_path', null);
        }

        Setting::put('datenschutz_url', $data['datenschutz_url'] ?? null);
        Setting::put('impressum_url', $data['impressum_url'] ?? null);

        return back()->with('success', 'Erscheinungsbild gespeichert.');
    }
}
```

- [ ] **Step 5: Run — must pass**

`php artisan test --filter=AppearanceSettingTest` → PASS (6 tests). Then the full backend suite: `composer test`.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat(staff): /erscheinungsbild backend — theme/logo/legal settings with validation"
```

---

### Task 12: Appearance page — pickers, live preview, nav, DSGVO warning

**Files:**
- Create: `backend/resources/js/Pages/Tenant/Appearance.vue`
- Modify: `backend/resources/js/Layouts/TenantLayout.vue` (nav entry)

No new automated UI test here (staff Inertia pages are render-tested in Task 11's `assertInertia`); the gate is the Chrome walkthrough in Task 13.

- [ ] **Step 1: Create `backend/resources/js/Pages/Tenant/Appearance.vue`**

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import FormField from '@/components/ui/FormField.vue'
import PrimaryButton from '@/components/ui/PrimaryButton.vue'

defineOptions({ layout: TenantLayout })

interface ThemeProps {
    colorPrimary: string; colorPrimaryTo: string; colorAccent: string
    colorBackground: string; colorText: string
    fontHeading: string; fontBody: string; radius: string
}

const props = defineProps<{
    theme: ThemeProps
    logoUrl: string | null
    datenschutzUrl: string | null
    impressumUrl: string | null
    fontOptions: string[]
}>()

const DEFAULTS: ThemeProps = {
    colorPrimary: '#6B8FA3', colorPrimaryTo: '#C40C78', colorAccent: '#EC0A8C',
    colorBackground: '#FFFFFF', colorText: '#26257F',
    fontHeading: 'Fredoka', fontBody: 'Nunito', radius: '26px',
}

const form = useForm({
    colorPrimary: props.theme.colorPrimary,
    colorPrimaryTo: props.theme.colorPrimaryTo,
    colorAccent: props.theme.colorAccent,
    colorBackground: props.theme.colorBackground,
    colorText: props.theme.colorText,
    fontHeading: props.theme.fontHeading,
    fontBody: props.theme.fontBody,
    radius: parseInt(props.theme.radius, 10),
    logo: null as File | null,
    remove_logo: false,
    datenschutz_url: props.datenschutzUrl ?? '',
    impressum_url: props.impressumUrl ?? '',
})

const logoPreview = ref<string | null>(props.logoUrl)

function onLogoChange(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null
    form.logo = file
    form.remove_logo = false
    if (file) logoPreview.value = URL.createObjectURL(file)
}

function removeLogo() {
    form.logo = null
    form.remove_logo = true
    logoPreview.value = null
}

function resetDefaults() {
    Object.assign(form, { ...DEFAULTS, radius: 26 })
}

// Live preview style — bound to the UNSAVED form state, mirroring the
// widget's --masinga-* token contract.
const previewVars = computed(() => ({
    '--masinga-primary': form.colorPrimary,
    '--masinga-primary-to': form.colorPrimaryTo,
    '--masinga-accent': form.colorAccent,
    '--masinga-bg': form.colorBackground,
    '--masinga-text': form.colorText,
    '--masinga-radius': `${form.radius}px`,
    '--masinga-tint': `color-mix(in srgb, ${form.colorAccent} 13%, white)`,
    '--masinga-tint-soft': `color-mix(in srgb, ${form.colorAccent} 7%, white)`,
    '--masinga-gradient': `linear-gradient(135deg, ${form.colorPrimary} 0%, ${form.colorPrimaryTo} 100%)`,
    fontFamily: form.fontBody === 'System' ? 'system-ui, sans-serif' : `'${form.fontBody}', system-ui, sans-serif`,
}))

const colorFields = [
    { key: 'colorPrimary', label: 'Primärfarbe (Verläufe, Buttons)' },
    { key: 'colorPrimaryTo', label: 'Verlaufsfarbe (zweiter Verlaufston)' },
    { key: 'colorAccent', label: 'Akzentfarbe (Auswahl, Fokus, Badges)' },
    { key: 'colorBackground', label: 'Hintergrund der Karte' },
    { key: 'colorText', label: 'Textfarbe' },
] as const

function submit() {
    form.post('/erscheinungsbild', { preserveScroll: true, forceFormData: true })
}
</script>

<template>
    <Head title="Erscheinungsbild" />
    <div class="max-w-6xl mx-auto p-8">
        <h1 class="text-3xl font-bold">Erscheinungsbild des Buchungs-Widgets</h1>
        <p class="mt-1 text-slate-500 text-sm">Farben, Logo, Schrift und Form — Änderungen wirken nach dem Speichern sofort auf der Praxis-Website.</p>

        <div v-if="!form.datenschutz_url" data-dsgvo-warning
             class="mt-4 rounded-xl bg-amber-50 ring-1 ring-amber-200 px-4 py-3 text-sm text-amber-800">
            <strong>DSGVO-Hinweis:</strong> Es ist keine Datenschutzerklärung verlinkt. Die Einwilligung im
            Widget ist ohne diesen Link nicht vollständig informiert. Bitte URL unten eintragen.
        </div>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- ============ Settings form ============ -->
            <form class="space-y-5" @submit.prevent="submit">
                <section class="space-y-3">
                    <h2 class="font-semibold text-slate-700">Farben</h2>
                    <FormField v-for="f in colorFields" :key="f.key" :label="f.label" :label-for="f.key" :error="(form.errors as any)[f.key]">
                        <div class="flex items-center gap-3">
                            <input :id="f.key" type="color" v-model="(form as any)[f.key]" class="h-10 w-14 rounded border p-1" />
                            <input type="text" v-model="(form as any)[f.key]" pattern="#[0-9A-Fa-f]{6}"
                                   class="w-28 p-2 border rounded font-mono text-sm" />
                        </div>
                    </FormField>
                </section>

                <section class="space-y-3">
                    <h2 class="font-semibold text-slate-700">Schrift &amp; Form</h2>
                    <FormField label="Schrift Überschriften" label-for="fontHeading" :error="form.errors.fontHeading">
                        <select id="fontHeading" v-model="form.fontHeading" class="w-full p-2 border rounded">
                            <option v-for="f in fontOptions" :key="f" :value="f">{{ f }}</option>
                        </select>
                    </FormField>
                    <FormField label="Schrift Fließtext" label-for="fontBody" :error="form.errors.fontBody">
                        <select id="fontBody" v-model="form.fontBody" class="w-full p-2 border rounded">
                            <option v-for="f in fontOptions" :key="f" :value="f">{{ f }}</option>
                        </select>
                    </FormField>
                    <FormField :label="`Eckenradius — ${form.radius}px`" label-for="radius" :error="form.errors.radius">
                        <input id="radius" type="range" min="0" max="40" v-model.number="form.radius" class="w-full" />
                    </FormField>
                </section>

                <section class="space-y-3">
                    <h2 class="font-semibold text-slate-700">Logo</h2>
                    <FormField label="Logo (PNG, JPG, SVG, WebP — max. 512 KB)" label-for="logo" :error="form.errors.logo">
                        <input id="logo" type="file" accept=".png,.jpg,.jpeg,.webp" @change="onLogoChange"
                               class="w-full text-sm" />
                    </FormField>
                    <div v-if="logoPreview" class="flex items-center gap-3">
                        <img :src="logoPreview" alt="Logo-Vorschau" class="h-12 w-auto rounded border bg-white p-1" />
                        <button type="button" class="text-sm text-rose-600 underline" @click="removeLogo">Logo entfernen</button>
                    </div>
                </section>

                <section class="space-y-3">
                    <h2 class="font-semibold text-slate-700">Rechtliches</h2>
                    <FormField label="URL der Datenschutzerklärung" label-for="datenschutz_url" :error="form.errors.datenschutz_url">
                        <input id="datenschutz_url" v-model="form.datenschutz_url" type="url"
                               placeholder="https://praxis.de/datenschutz" class="w-full p-2 border rounded" />
                    </FormField>
                    <FormField label="URL des Impressums (optional)" label-for="impressum_url" :error="form.errors.impressum_url">
                        <input id="impressum_url" v-model="form.impressum_url" type="url"
                               placeholder="https://praxis.de/impressum" class="w-full p-2 border rounded" />
                    </FormField>
                </section>

                <div class="flex items-center gap-3 pt-2">
                    <PrimaryButton :disabled="form.processing">Speichern</PrimaryButton>
                    <button type="button" class="rounded border px-3 py-2 text-sm hover:bg-slate-50" @click="resetDefaults">
                        Auf Standard zurücksetzen
                    </button>
                </div>
            </form>

            <!-- ============ Live preview (token mirror, not the real widget) ============ -->
            <div>
                <h2 class="font-semibold text-slate-700 mb-3">Live-Vorschau</h2>
                <p class="text-xs text-slate-400 mb-3">Vorschau der Design-Token — das echte Widget übernimmt diese Werte nach dem Speichern.</p>
                <div data-preview :style="previewVars"
                     class="mx-auto max-w-md p-6 shadow-[0_24px_70px_-28px_rgba(30,41,59,0.30)] ring-1 ring-slate-100"
                     :class="''"
                     style="background-color: var(--masinga-bg); border-radius: var(--masinga-radius); color: var(--masinga-text);">
                    <img v-if="logoPreview" :src="logoPreview" alt="" class="mx-auto mb-3 max-h-12 w-auto" />
                    <!-- Step indicator dots -->
                    <div class="flex items-center justify-center gap-2 mb-4">
                        <span class="h-7 w-7 rounded-full flex items-center justify-center text-[11px] font-bold text-white" style="background: var(--masinga-gradient);">1</span>
                        <span class="h-1 w-8 rounded" style="background: var(--masinga-tint);"></span>
                        <span class="h-7 w-7 rounded-full flex items-center justify-center text-[11px] font-bold" style="background: var(--masinga-tint); color: var(--masinga-text);">2</span>
                    </div>
                    <h3 class="text-xl font-bold" :style="{ fontFamily: form.fontHeading === 'System' ? 'system-ui' : `'${form.fontHeading}', system-ui, sans-serif` }">
                        Termin wählen
                    </h3>
                    <!-- Selected card -->
                    <div class="mt-3 rounded-2xl px-4 py-3 text-sm font-semibold ring-2"
                         style="background-color: var(--masinga-tint); --tw-ring-color: var(--masinga-accent);">
                        Erstuntersuchung Kind · 45 Min.
                    </div>
                    <!-- Slot tiles -->
                    <div class="mt-3 grid grid-cols-3 gap-2">
                        <span class="rounded-xl border border-slate-200 py-2 text-center text-sm font-bold" style="background-color: var(--masinga-bg);">09:00</span>
                        <span class="rounded-xl py-2 text-center text-sm font-bold text-white" style="background: var(--masinga-gradient);">09:30</span>
                        <span class="rounded-xl border border-slate-200 py-2 text-center text-sm font-bold" style="background-color: var(--masinga-bg);">10:00</span>
                    </div>
                    <!-- Primary button -->
                    <button type="button" class="mt-4 w-full rounded-2xl py-3 text-sm font-bold text-white" style="background: var(--masinga-gradient);">
                        Weiter
                    </button>
                    <p class="mt-3 text-xs" style="color: color-mix(in srgb, var(--masinga-text) 60%, transparent);">
                        Ich willige in die Verarbeitung … <span class="underline font-semibold" style="color: var(--masinga-accent);">Datenschutzerklärung</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Nav entry**

In `backend/resources/js/Layouts/TenantLayout.vue`: add `Palette` to the lucide import list and to the nav array (before the QR entry):

```ts
    { href: '/erscheinungsbild', label: 'Erscheinungsbild', icon: Palette },
```

- [ ] **Step 3: Build + backend page test still green**

```bash
npm run build           # main app assets compile
php artisan test --filter=AppearanceSettingTest
```

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat(staff): Erscheinungsbild page — color pickers, fonts, radius, logo, legal urls, live token preview"
```

---

## Final — Task 13: End-to-end verification, suites, visual check

- [ ] **Step 1: Full suites**

```bash
composer test          # entire backend suite
npm run test:widget    # entire widget suite
vendor/bin/pint --test # style clean
npm run build && npm run build:widget
```

All green, no warnings.

- [ ] **Step 2: Hex gate (final)**

```bash
grep -rnE '#(26257F|211F66|4B4A9E|5A5996|EC0A8C|C40C78|6B8FA3|FCE3E9|FFF4F7|F8D3DE|3D5F72)' resources/js/widget --include='*.vue'
grep -rn 'window.confirm' resources/js/widget
grep -rn 'cancellation_token' resources/js/widget/steps/SuccessStep.vue
```

First two: zero output. Third: zero output (the token must not appear in SuccessStep at all).

- [ ] **Step 3: Chrome walkthrough** (browser automation — `php artisan serve` + `npm run dev`):

1. Log in (2FA), open `/erscheinungsbild`: change accent to green, radius to 8, upload a logo → preview updates live → Speichern.
2. Open the widget preview page: the widget shows green accents, 8px-ish corners, the logo; complete a booking → Leistung combobox works, slot click highlights + Weiter, KIND header inside its box, consent shows the Datenschutz link (after setting the URL), Success shows `KC-…` and NOT a UUID, "Neuer Termin" restarts, cancel uses the inline confirm.
3. Reset to defaults on `/erscheinungsbild`, save → widget back to pastel.
4. Capture a GIF of the booking flow for the PR.

- [ ] **Step 4: Final commit + push + PR**

```bash
git push -u origin feature/widget-design-system
gh pr create --title "feat: widget design system (theming) + 5 booking-flow fixes" --body "<summary per template>

🤖 Generated with [Claude Code](https://claude.com/claude-code)"
```

Then: final code-reviewer agent on the full branch diff (mandatory before merge), CodeRabbit autofix loop. **No deploy without an explicit "deploy" from the owner.**

---

## Plan self-review notes

- Spec coverage: A→Task 1-2, B→Task 3-5, C→Task 11-12, D fixes 1-5→Tasks 6,7,8,9,10. Defaults invariant enforced in Task 2 (controller constant), Task 3 (`:host` defaults) and Task 5 step 4 (visual identity check). DSGVO fallback → Task 9 step 2 + Task 12 warning banner.
- The Task 5 hex gate excludes `FBB9C4` lines kept as the documented data-driven service-color fallback (`s.color || '#FBB9C4'`); Task 13's final gate therefore omits `FBB9C4` from the pattern.
- Ops follow-ups (NOT in this PR): `php artisan storage:link` on the VPS before the logo feature is used in prod. (Fonts are SELF-HOSTED from the backend — no Google Fonts dependency; see the Task 4 review note.)
- PR #27 touches the same email blades (`timezone('Europe/Berlin')` → `clinicStartsAt()`); merging both will conflict trivially on the bullet list — resolve by keeping both changes (Referenz line + clinic accessor).
