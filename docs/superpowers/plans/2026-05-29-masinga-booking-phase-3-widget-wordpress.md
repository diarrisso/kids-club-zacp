# Phase 3 — Widget + WordPress Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An embeddable Vue 3 booking widget (separate Vite build, mounted in a Shadow DOM for CSS isolation) that drives a parent through service → practitioner → slot → child/parent form + consent → confirmation against the Phase 2 public API, plus a thin WordPress plugin to embed it.

**Architecture:** A standalone widget under `backend/resources/js/widget/` built by a dedicated Vite config to a self-executing `public/widget/masinga-widget.js`. On load it finds `[data-masinga-booking]` elements, attaches a shadow root, injects its Tailwind CSS into the shadow root, and mounts a Vue wizard. A typed `api.ts` client talks to `{data-api}/api/v1/widget/{data-tenant}/*`. Frontend tested with Vitest + @vue/test-utils; full flow verified manually in Chrome. A ~80-line WordPress plugin emits the embed markup.

**Tech Stack:** Vue 3 (`<script setup lang="ts">`), Vite (IIFE library build), Tailwind 3, Vitest + @vue/test-utils + jsdom, PHP (WordPress plugin). Builds on Phase 2 (must be merged first).

**Spec:** `docs/superpowers/specs/2026-05-29-masinga-booking-phase-3-widget-wordpress-design.md`

**PREREQUISITE:** Phase 2 (PR #2) merged to `main`, and this branch rebased onto it (the widget needs the `/api/v1/widget/{slug}/*` endpoints).

---

## File Structure

```
backend/
├── vite.widget.config.js                         ← dedicated widget build (IIFE → public/widget/)
├── vitest.config.ts                              ← jsdom test env for the widget
├── package.json                                  ← + build:widget, test:widget scripts + dev deps
├── resources/js/widget/
│   ├── main.ts                                   ← Shadow DOM bootstrap + Vue mount (reads data-attrs)
│   ├── App.vue                                    ← wizard orchestrator (current step + shared state)
│   ├── api.ts                                     ← typed fetch client + error mapping
│   ├── types.ts                                   ← Service/Practitioner/Slot/BookingPayload types
│   ├── widget.css                                 ← Tailwind entry for the widget (injected in shadow root)
│   └── steps/
│       ├── ServiceStep.vue
│       ├── PractitionerStep.vue
│       ├── SlotStep.vue
│       ├── FormStep.vue
│       └── SuccessStep.vue
└── tests/widget/                                 ← *.test.ts (Vitest)
    ├── api.test.ts
    ├── wizard.test.ts
    ├── FormStep.test.ts
    └── SlotStep.test.ts

wordpress-plugin/masinga-booking/
├── masinga-booking.php
├── includes/class-settings.php
├── includes/class-shortcode.php
├── includes/class-block.php
└── readme.txt

backend/public/widget/test.html                   ← manual Chrome harness (gitignored build dir excepted)
```

---

### Task 1: Vitest + dedicated widget Vite build

**Files:**
- Modify: `backend/package.json`
- Create: `backend/vitest.config.ts`, `backend/vite.widget.config.js`
- Create: `backend/resources/js/widget/main.ts` (temporary smoke export)
- Test: `backend/tests/widget/smoke.test.ts`

- [ ] **Step 1: Install dev dependencies**

Run:
```bash
cd backend
npm install -D vitest @vue/test-utils jsdom @vitejs/plugin-vue
```
(`@vitejs/plugin-vue` is already present from Phase 1; npm will no-op it.)

- [ ] **Step 2: Add scripts to `package.json`**

In the `"scripts"` block add:
```json
"build:widget": "vite build --config vite.widget.config.js",
"test:widget": "vitest run --config vitest.config.ts"
```

- [ ] **Step 3: Write the Vitest config**

Create `backend/vitest.config.ts`:
```ts
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
    plugins: [vue()],
    resolve: { alias: { '@widget': path.resolve(__dirname, 'resources/js/widget') } },
    test: {
        environment: 'jsdom',
        include: ['tests/widget/**/*.test.ts'],
    },
})
```

- [ ] **Step 4: Write a failing smoke test**

Create `backend/tests/widget/smoke.test.ts`:
```ts
import { describe, it, expect } from 'vitest'
import { widgetVersion } from '@widget/main'

describe('widget tooling', () => {
    it('exposes a version constant', () => {
        expect(widgetVersion()).toBe('phase-3')
    })
})
```

- [ ] **Step 5: Run, expect fail**

Run: `npm run test:widget`
Expected: FAIL (`main.ts` has no `widgetVersion` export).

- [ ] **Step 6: Create the widget entry (temporary)**

Create `backend/resources/js/widget/main.ts`:
```ts
export function widgetVersion(): string {
    return 'phase-3'
}
```

- [ ] **Step 7: Run, expect pass**

Run: `npm run test:widget`
Expected: PASS (1 test).

- [ ] **Step 8: Write the widget build config**

Create `backend/vite.widget.config.js`:
```js
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

// Standalone IIFE build of the embeddable widget → public/widget/masinga-widget.js
export default defineConfig({
    plugins: [vue()],
    resolve: { alias: { '@widget': path.resolve(__dirname, 'resources/js/widget') } },
    build: {
        outDir: 'public/widget',
        emptyOutDir: true,
        lib: {
            entry: path.resolve(__dirname, 'resources/js/widget/main.ts'),
            name: 'MasingaWidget',
            formats: ['iife'],
            fileName: () => 'masinga-widget.js',
        },
        rollupOptions: {
            output: { assetFileNames: 'masinga-widget.[ext]' },
        },
    },
})
```

- [ ] **Step 9: Verify the build runs**

Run: `npm run build:widget`
Expected: produces `public/widget/masinga-widget.js`. (Content is trivial for now.)

- [ ] **Step 10: Commit**

```bash
cd /Users/mdiarrisso/PhpstormProjects/kids-club-zacp
git add backend/package.json backend/package-lock.json backend/vitest.config.ts backend/vite.widget.config.js backend/resources/js/widget/main.ts backend/tests/widget/smoke.test.ts
git commit -m "build: widget Vitest harness + dedicated IIFE Vite build"
```

---

### Task 2: Types + typed API client (`api.ts`)

**Files:**
- Create: `backend/resources/js/widget/types.ts`, `backend/resources/js/widget/api.ts`
- Test: `backend/tests/widget/api.test.ts`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/widget/api.test.ts`:
```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createApi } from '@widget/api'

const api = createApi('https://app.test', 'kidsclub')

beforeEach(() => { vi.restoreAllMocks() })

function mockFetch(status: number, body: unknown) {
    return vi.spyOn(globalThis, 'fetch').mockResolvedValue(
        new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
    )
}

describe('api client', () => {
    it('builds the tenant-scoped services URL', async () => {
        const spy = mockFetch(200, [{ id: 1, name: 'Prophylaxe', duration_minutes: 30 }])
        const services = await api.services()
        expect(spy).toHaveBeenCalledWith('https://app.test/api/v1/widget/kidsclub/services', expect.anything())
        expect(services[0].name).toBe('Prophylaxe')
    })

    it('maps a 409 conflict to a SlotTaken error', async () => {
        mockFetch(409, { message: 'taken' })
        await expect(api.book({} as any)).rejects.toMatchObject({ kind: 'slot_taken' })
    })

    it('maps a 422 to a validation error carrying field errors', async () => {
        mockFetch(422, { errors: { consent: ['required'] } })
        await expect(api.book({} as any)).rejects.toMatchObject({ kind: 'validation', errors: { consent: ['required'] } })
    })

    it('maps a 429 to a rate_limited error', async () => {
        mockFetch(429, {})
        await expect(api.book({} as any)).rejects.toMatchObject({ kind: 'rate_limited' })
    })
})
```

- [ ] **Step 2: Run, expect fail**

Run: `npm run test:widget`
Expected: FAIL (`api.ts` missing).

- [ ] **Step 3: Write the types**

Create `backend/resources/js/widget/types.ts`:
```ts
export interface Service { id: number; name: string; duration_minutes: number; color?: string; description?: string }
export interface Practitioner { id: number; first_name: string; last_name: string; title?: string; color?: string }
export interface Slot { starts_at: string; ends_at: string }

export interface BookingPayload {
    practitioner_id: number
    service_id: number
    starts_at: string
    patient_first_name: string
    patient_last_name: string
    patient_birthdate: string
    parent_first_name: string
    parent_last_name: string
    parent_email: string
    parent_phone?: string
    notes_parent?: string
    consent: boolean
    website?: string // honeypot
}

export interface BookingResult { cancellation_token: string; starts_at: string; ends_at: string }

export type ApiError =
    | { kind: 'slot_taken' }
    | { kind: 'rate_limited' }
    | { kind: 'validation'; errors: Record<string, string[]> }
    | { kind: 'network' }
```

- [ ] **Step 4: Write the API client**

Create `backend/resources/js/widget/api.ts`:
```ts
import type { Service, Practitioner, Slot, BookingPayload, BookingResult, ApiError } from './types'

export function createApi(base: string, tenant: string) {
    const root = `${base.replace(/\/$/, '')}/api/v1/widget/${tenant}`

    async function request<T>(path: string, init?: RequestInit): Promise<T> {
        let res: Response
        try {
            res = await fetch(`${root}${path}`, {
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                ...init,
            })
        } catch {
            throw { kind: 'network' } satisfies ApiError
        }

        if (res.status === 409) throw { kind: 'slot_taken' } satisfies ApiError
        if (res.status === 429) throw { kind: 'rate_limited' } satisfies ApiError
        if (res.status === 422) {
            const body = await res.json().catch(() => ({}))
            throw { kind: 'validation', errors: body.errors ?? {} } satisfies ApiError
        }
        if (!res.ok) throw { kind: 'network' } satisfies ApiError

        return res.json() as Promise<T>
    }

    return {
        services: () => request<Service[]>('/services'),
        practitioners: (serviceId: number) => request<Practitioner[]>(`/services/${serviceId}/practitioners`),
        slots: (practitionerId: number, serviceId: number, from: string, to: string) =>
            request<Slot[]>(`/slots?practitioner_id=${practitionerId}&service_id=${serviceId}&from=${from}&to=${to}`),
        book: (payload: BookingPayload) =>
            request<BookingResult>('/appointments', { method: 'POST', body: JSON.stringify(payload) }),
    }
}

export type Api = ReturnType<typeof createApi>
```

- [ ] **Step 5: Run, expect pass**

Run: `npm run test:widget`
Expected: PASS (smoke + 4 api tests).

- [ ] **Step 6: Commit**

```bash
git add backend/resources/js/widget/types.ts backend/resources/js/widget/api.ts backend/tests/widget/api.test.ts
git commit -m "feat: widget typed API client with error mapping (409/422/429)"
```

---

### Task 3: Wizard state machine (`useWizard` composable)

**Files:**
- Create: `backend/resources/js/widget/useWizard.ts`
- Test: `backend/tests/widget/wizard.test.ts`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/widget/wizard.test.ts`:
```ts
import { describe, it, expect } from 'vitest'
import { useWizard } from '@widget/useWizard'

describe('useWizard', () => {
    it('starts on the service step and advances with selections', () => {
        const w = useWizard()
        expect(w.step.value).toBe('service')

        w.chooseService({ id: 1, name: 'Prophylaxe', duration_minutes: 30 })
        expect(w.step.value).toBe('practitioner')
        expect(w.selection.service?.id).toBe(1)

        w.choosePractitioner({ id: 2, first_name: 'Anna', last_name: 'M' })
        expect(w.step.value).toBe('slot')

        w.chooseSlot({ starts_at: '2026-09-07T09:00:00+02:00', ends_at: '2026-09-07T09:30:00+02:00' })
        expect(w.step.value).toBe('form')
    })

    it('goes back without losing earlier selections', () => {
        const w = useWizard()
        w.chooseService({ id: 1, name: 'Prophylaxe', duration_minutes: 30 })
        w.choosePractitioner({ id: 2, first_name: 'Anna', last_name: 'M' })
        w.back()
        expect(w.step.value).toBe('service')
        expect(w.selection.service?.id).toBe(1) // retained
    })

    it('moves to success after booking', () => {
        const w = useWizard()
        w.complete()
        expect(w.step.value).toBe('success')
    })
})
```

- [ ] **Step 2: Run, expect fail**

Run: `npm run test:widget`
Expected: FAIL (`useWizard` missing).

- [ ] **Step 3: Implement the composable**

Create `backend/resources/js/widget/useWizard.ts`:
```ts
import { ref, reactive } from 'vue'
import type { Service, Practitioner, Slot } from './types'

export type Step = 'service' | 'practitioner' | 'slot' | 'form' | 'success'
const ORDER: Step[] = ['service', 'practitioner', 'slot', 'form', 'success']

export function useWizard() {
    const step = ref<Step>('service')
    const selection = reactive<{ service?: Service; practitioner?: Practitioner; slot?: Slot }>({})

    const go = (s: Step) => { step.value = s }

    return {
        step,
        selection,
        chooseService(s: Service) { selection.service = s; go('practitioner') },
        choosePractitioner(p: Practitioner) { selection.practitioner = p; go('slot') },
        chooseSlot(slot: Slot) { selection.slot = slot; go('form') },
        complete() { go('success') },
        back() {
            const i = ORDER.indexOf(step.value)
            if (i > 0) go(ORDER[i - 1])
        },
    }
}
```

- [ ] **Step 4: Run, expect pass**

Run: `npm run test:widget`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/resources/js/widget/useWizard.ts backend/tests/widget/wizard.test.ts
git commit -m "feat: widget wizard state machine (step navigation + retained selection)"
```

---

### Task 4: ServiceStep + PractitionerStep components

**Files:**
- Create: `backend/resources/js/widget/steps/ServiceStep.vue`, `backend/resources/js/widget/steps/PractitionerStep.vue`
- Test: `backend/tests/widget/steps.test.ts`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/widget/steps.test.ts`:
```ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ServiceStep from '@widget/steps/ServiceStep.vue'
import PractitionerStep from '@widget/steps/PractitionerStep.vue'

describe('ServiceStep', () => {
    it('renders services and emits select on click', async () => {
        const wrapper = mount(ServiceStep, {
            props: { services: [{ id: 1, name: 'Prophylaxe', duration_minutes: 30 }] },
        })
        expect(wrapper.text()).toContain('Prophylaxe')
        await wrapper.get('button').trigger('click')
        expect(wrapper.emitted('select')?.[0][0]).toMatchObject({ id: 1 })
    })
})

describe('PractitionerStep', () => {
    it('renders practitioners and emits select', async () => {
        const wrapper = mount(PractitionerStep, {
            props: { practitioners: [{ id: 2, first_name: 'Anna', last_name: 'Müller', title: 'Dr.' }] },
        })
        expect(wrapper.text()).toContain('Anna')
        await wrapper.get('button').trigger('click')
        expect(wrapper.emitted('select')?.[0][0]).toMatchObject({ id: 2 })
    })
})
```

- [ ] **Step 2: Run, expect fail** — `npm run test:widget` → FAIL (components missing).

- [ ] **Step 3: Create ServiceStep**

`backend/resources/js/widget/steps/ServiceStep.vue`:
```vue
<script setup lang="ts">
import type { Service } from '../types'
defineProps<{ services: Service[] }>()
defineEmits<{ select: [service: Service] }>()
</script>

<template>
    <div class="mb-space-y-3">
        <h2 class="text-lg font-bold mb-4">Leistung wählen</h2>
        <ul class="space-y-2">
            <li v-for="s in services" :key="s.id">
                <button type="button" @click="$emit('select', s)"
                        class="w-full text-left p-3 border rounded hover:bg-blue-50">
                    <span class="font-medium">{{ s.name }}</span>
                    <span class="text-sm text-slate-500"> · {{ s.duration_minutes }} Min.</span>
                </button>
            </li>
        </ul>
    </div>
</template>
```

- [ ] **Step 4: Create PractitionerStep**

`backend/resources/js/widget/steps/PractitionerStep.vue`:
```vue
<script setup lang="ts">
import type { Practitioner } from '../types'
defineProps<{ practitioners: Practitioner[] }>()
defineEmits<{ select: [practitioner: Practitioner] }>()
</script>

<template>
    <div>
        <h2 class="text-lg font-bold mb-4">Behandler wählen</h2>
        <ul class="space-y-2">
            <li v-for="p in practitioners" :key="p.id">
                <button type="button" @click="$emit('select', p)"
                        class="w-full text-left p-3 border rounded hover:bg-blue-50">
                    {{ p.title }} {{ p.first_name }} {{ p.last_name }}
                </button>
            </li>
        </ul>
    </div>
</template>
```

- [ ] **Step 5: Run, expect pass** — `npm run test:widget` → PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/resources/js/widget/steps/ServiceStep.vue backend/resources/js/widget/steps/PractitionerStep.vue backend/tests/widget/steps.test.ts
git commit -m "feat: widget ServiceStep + PractitionerStep selection components"
```

---

### Task 5: SlotStep component (slots grouped by date)

**Files:**
- Create: `backend/resources/js/widget/steps/SlotStep.vue`
- Test: `backend/tests/widget/SlotStep.test.ts`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/widget/SlotStep.test.ts`:
```ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SlotStep from '@widget/steps/SlotStep.vue'

const slots = [
    { starts_at: '2026-09-07T09:00:00+02:00', ends_at: '2026-09-07T09:30:00+02:00' },
    { starts_at: '2026-09-07T09:30:00+02:00', ends_at: '2026-09-07T10:00:00+02:00' },
    { starts_at: '2026-09-08T11:00:00+02:00', ends_at: '2026-09-08T11:30:00+02:00' },
]

describe('SlotStep', () => {
    it('groups slots by date and emits the chosen slot', async () => {
        const wrapper = mount(SlotStep, { props: { slots } })
        // two date groups
        expect(wrapper.findAll('[data-date-group]')).toHaveLength(2)
        // first slot button shows its time and emits on click
        const first = wrapper.get('button[data-slot]')
        await first.trigger('click')
        expect(wrapper.emitted('select')?.[0][0]).toMatchObject({ starts_at: slots[0].starts_at })
    })

    it('shows an empty message when there are no slots', () => {
        const wrapper = mount(SlotStep, { props: { slots: [] } })
        expect(wrapper.text()).toContain('Keine freien Termine')
    })
})
```

- [ ] **Step 2: Run, expect fail** — `npm run test:widget` → FAIL.

- [ ] **Step 3: Create SlotStep**

`backend/resources/js/widget/steps/SlotStep.vue`:
```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { Slot } from '../types'

const props = defineProps<{ slots: Slot[] }>()
defineEmits<{ select: [slot: Slot] }>()

const groups = computed(() => {
    const map = new Map<string, Slot[]>()
    for (const s of props.slots) {
        const date = s.starts_at.slice(0, 10) // YYYY-MM-DD from ISO
        if (!map.has(date)) map.set(date, [])
        map.get(date)!.push(s)
    }
    return Array.from(map, ([date, items]) => ({ date, items }))
})

const time = (iso: string) => iso.slice(11, 16) // HH:MM
const dateLabel = (d: string) =>
    new Date(d + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: 'long' })
</script>

<template>
    <div>
        <h2 class="text-lg font-bold mb-4">Termin wählen</h2>
        <p v-if="groups.length === 0" class="text-slate-500">Keine freien Termine in diesem Zeitraum.</p>
        <div v-for="g in groups" :key="g.date" data-date-group class="mb-4">
            <h3 class="font-medium mb-2">{{ dateLabel(g.date) }}</h3>
            <div class="flex flex-wrap gap-2">
                <button v-for="s in g.items" :key="s.starts_at" type="button" data-slot
                        @click="$emit('select', s)"
                        class="px-3 py-2 border rounded hover:bg-blue-50">
                    {{ time(s.starts_at) }}
                </button>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 4: Run, expect pass** — `npm run test:widget` → PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/resources/js/widget/steps/SlotStep.vue backend/tests/widget/SlotStep.test.ts
git commit -m "feat: widget SlotStep grouping slots by date"
```

---

### Task 6: FormStep component (patient + parent + consent + honeypot)

**Files:**
- Create: `backend/resources/js/widget/steps/FormStep.vue`
- Test: `backend/tests/widget/FormStep.test.ts`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/widget/FormStep.test.ts`:
```ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FormStep from '@widget/steps/FormStep.vue'

function fill(wrapper: any) {
    return wrapper.get('form')
}

describe('FormStep', () => {
    it('disables submit until required fields + consent are set', async () => {
        const wrapper = mount(FormStep, { props: { serverErrors: {} } })
        const submit = wrapper.get('button[type="submit"]')
        expect((submit.element as HTMLButtonElement).disabled).toBe(true)

        await wrapper.get('[name="patient_first_name"]').setValue('Lina')
        await wrapper.get('[name="patient_last_name"]').setValue('Müller')
        await wrapper.get('[name="patient_birthdate"]').setValue('2019-04-12')
        await wrapper.get('[name="parent_first_name"]').setValue('Anna')
        await wrapper.get('[name="parent_last_name"]').setValue('Müller')
        await wrapper.get('[name="parent_email"]').setValue('anna@example.de')
        await wrapper.get('[name="consent"]').setValue(true)

        expect((submit.element as HTMLButtonElement).disabled).toBe(false)
    })

    it('emits submit payload including an (empty) honeypot field', async () => {
        const wrapper = mount(FormStep, { props: { serverErrors: {} } })
        await wrapper.get('[name="patient_first_name"]').setValue('Lina')
        await wrapper.get('[name="patient_last_name"]').setValue('Müller')
        await wrapper.get('[name="patient_birthdate"]').setValue('2019-04-12')
        await wrapper.get('[name="parent_first_name"]').setValue('Anna')
        await wrapper.get('[name="parent_last_name"]').setValue('Müller')
        await wrapper.get('[name="parent_email"]').setValue('anna@example.de')
        await wrapper.get('[name="consent"]').setValue(true)
        await wrapper.get('form').trigger('submit.prevent')

        const payload = wrapper.emitted('submit')?.[0][0] as any
        expect(payload).toMatchObject({ patient_first_name: 'Lina', consent: true, website: '' })
    })

    it('shows a server field error', () => {
        const wrapper = mount(FormStep, { props: { serverErrors: { parent_email: ['ungültig'] } } })
        expect(wrapper.text()).toContain('ungültig')
    })
})
```

- [ ] **Step 2: Run, expect fail** — `npm run test:widget` → FAIL.

- [ ] **Step 3: Create FormStep**

`backend/resources/js/widget/steps/FormStep.vue`:
```vue
<script setup lang="ts">
import { reactive, computed } from 'vue'

defineProps<{ serverErrors: Record<string, string[]> }>()
const emit = defineEmits<{ submit: [payload: Record<string, unknown>] }>()

const form = reactive({
    patient_first_name: '', patient_last_name: '', patient_birthdate: '',
    parent_first_name: '', parent_last_name: '', parent_email: '', parent_phone: '',
    notes_parent: '', consent: false, website: '', // website = honeypot
})

const valid = computed(() =>
    !!form.patient_first_name && !!form.patient_last_name && !!form.patient_birthdate &&
    !!form.parent_first_name && !!form.parent_last_name && /\S+@\S+\.\S+/.test(form.parent_email) &&
    form.consent === true,
)

const submit = () => { if (valid.value) emit('submit', { ...form }) }
</script>

<template>
    <form @submit.prevent="submit">
        <h2 class="text-lg font-bold mb-4">Ihre Angaben</h2>

        <fieldset class="mb-4">
            <legend class="font-medium mb-2">Kind</legend>
            <input name="patient_first_name" v-model="form.patient_first_name" placeholder="Vorname"
                   class="w-full p-2 border rounded mb-2">
            <input name="patient_last_name" v-model="form.patient_last_name" placeholder="Nachname"
                   class="w-full p-2 border rounded mb-2">
            <input name="patient_birthdate" v-model="form.patient_birthdate" type="date"
                   class="w-full p-2 border rounded">
        </fieldset>

        <fieldset class="mb-4">
            <legend class="font-medium mb-2">Eltern</legend>
            <input name="parent_first_name" v-model="form.parent_first_name" placeholder="Vorname"
                   class="w-full p-2 border rounded mb-2">
            <input name="parent_last_name" v-model="form.parent_last_name" placeholder="Nachname"
                   class="w-full p-2 border rounded mb-2">
            <input name="parent_email" v-model="form.parent_email" type="email" placeholder="E-Mail"
                   class="w-full p-2 border rounded mb-1">
            <div v-if="serverErrors.parent_email" class="text-red-600 text-sm mb-2">{{ serverErrors.parent_email[0] }}</div>
            <input name="parent_phone" v-model="form.parent_phone" placeholder="Telefon (optional)"
                   class="w-full p-2 border rounded">
        </fieldset>

        <textarea name="notes_parent" v-model="form.notes_parent" placeholder="Notiz (optional)"
                  class="w-full p-2 border rounded mb-3" rows="2"></textarea>

        <!-- Honeypot: hidden from humans, bots fill it -->
        <input name="website" v-model="form.website" tabindex="-1" autocomplete="off"
               style="position:absolute;left:-9999px" aria-hidden="true">

        <label class="flex items-start gap-2 mb-4">
            <input name="consent" v-model="form.consent" type="checkbox" class="mt-1">
            <span class="text-sm">Ich willige in die Verarbeitung der angegebenen Daten zur Terminbuchung ein.</span>
        </label>

        <button type="submit" :disabled="!valid"
                class="w-full bg-blue-700 text-white py-3 rounded disabled:opacity-50">
            Termin buchen
        </button>
    </form>
</template>
```

- [ ] **Step 4: Run, expect pass** — `npm run test:widget` → PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/resources/js/widget/steps/FormStep.vue backend/tests/widget/FormStep.test.ts
git commit -m "feat: widget FormStep (child/parent fields, consent, honeypot, validation)"
```

---

### Task 7: SuccessStep + App.vue orchestrator (data loading, booking, error handling)

**Files:**
- Create: `backend/resources/js/widget/steps/SuccessStep.vue`, `backend/resources/js/widget/App.vue`
- Test: `backend/tests/widget/app.test.ts`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/widget/app.test.ts`:
```ts
import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import App from '@widget/App.vue'

const fakeApi = {
    services: vi.fn().mockResolvedValue([{ id: 1, name: 'Prophylaxe', duration_minutes: 30 }]),
    practitioners: vi.fn().mockResolvedValue([{ id: 2, first_name: 'Anna', last_name: 'Müller' }]),
    slots: vi.fn().mockResolvedValue([{ starts_at: '2026-09-07T09:00:00+02:00', ends_at: '2026-09-07T09:30:00+02:00' }]),
    book: vi.fn().mockResolvedValue({ cancellation_token: 'tok-123', starts_at: '2026-09-07T09:00:00+02:00', ends_at: '2026-09-07T09:30:00+02:00' }),
}

describe('App', () => {
    it('walks the full flow to success', async () => {
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await flushPromises() // services loaded

        await wrapper.get('button').trigger('click') // choose service
        await flushPromises()
        await wrapper.get('button').trigger('click') // choose practitioner
        await flushPromises()
        await wrapper.get('button[data-slot]').trigger('click') // choose slot

        // fill the form
        await wrapper.get('[name="patient_first_name"]').setValue('Lina')
        await wrapper.get('[name="patient_last_name"]').setValue('Müller')
        await wrapper.get('[name="patient_birthdate"]').setValue('2019-04-12')
        await wrapper.get('[name="parent_first_name"]').setValue('Anna')
        await wrapper.get('[name="parent_last_name"]').setValue('Müller')
        await wrapper.get('[name="parent_email"]').setValue('anna@example.de')
        await wrapper.get('[name="consent"]').setValue(true)
        await wrapper.get('form').trigger('submit.prevent')
        await flushPromises()

        expect(fakeApi.book).toHaveBeenCalled()
        expect(wrapper.text()).toContain('tok-123')
    })
})
```

- [ ] **Step 2: Run, expect fail** — `npm run test:widget` → FAIL.

- [ ] **Step 3: Create SuccessStep**

`backend/resources/js/widget/steps/SuccessStep.vue`:
```vue
<script setup lang="ts">
import type { BookingResult } from '../types'
const props = defineProps<{ result: BookingResult; apiBase: string; tenant: string }>()
const cancelUrl = `${props.apiBase.replace(/\/$/, '')}/api/v1/widget/${props.tenant}/appointments/${props.result.cancellation_token}`
</script>

<template>
    <div class="text-center py-6">
        <h2 class="text-xl font-bold text-green-700 mb-2">Termin bestätigt!</h2>
        <p class="text-slate-600 mb-4">Sie erhalten in Kürze eine Bestätigung.</p>
        <p class="text-xs text-slate-400">Stornierungs-Referenz: {{ result.cancellation_token }}</p>
        <a :href="cancelUrl" class="text-sm text-blue-600 underline">Termin stornieren</a>
    </div>
</template>
```

- [ ] **Step 4: Create App.vue**

`backend/resources/js/widget/App.vue`:
```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import type { Api } from './api'
import type { Service, Practitioner, Slot, BookingResult } from './types'
import { useWizard } from './useWizard'
import ServiceStep from './steps/ServiceStep.vue'
import PractitionerStep from './steps/PractitionerStep.vue'
import SlotStep from './steps/SlotStep.vue'
import FormStep from './steps/FormStep.vue'
import SuccessStep from './steps/SuccessStep.vue'

const props = defineProps<{ api: Api; apiBase?: string; tenant?: string }>()
const w = useWizard()

const services = ref<Service[]>([])
const practitioners = ref<Practitioner[]>([])
const slots = ref<Slot[]>([])
const result = ref<BookingResult | null>(null)
const serverErrors = ref<Record<string, string[]>>({})
const banner = ref<string>('')
const loading = ref(false)

onMounted(async () => { services.value = await props.api.services() })

async function onService(s: Service) {
    w.chooseService(s)
    practitioners.value = await props.api.practitioners(s.id)
}

async function onPractitioner(p: Practitioner) {
    w.choosePractitioner(p)
    const from = new Date().toISOString().slice(0, 10)
    const to = new Date(Date.now() + 60 * 864e5).toISOString().slice(0, 10)
    slots.value = await props.api.slots(p.id, w.selection.service!.id, from, to)
}

async function onSubmit(formData: Record<string, unknown>) {
    serverErrors.value = {}
    banner.value = ''
    loading.value = true
    try {
        result.value = await props.api.book({
            ...(formData as any),
            practitioner_id: w.selection.practitioner!.id,
            service_id: w.selection.service!.id,
            starts_at: w.selection.slot!.starts_at,
        })
        w.complete()
    } catch (e: any) {
        if (e.kind === 'validation') serverErrors.value = e.errors
        else if (e.kind === 'slot_taken') { banner.value = 'Termin nicht mehr verfügbar.'; w.back() }
        else if (e.kind === 'rate_limited') banner.value = 'Zu viele Versuche, bitte später erneut.'
        else banner.value = 'Verbindungsfehler. Bitte erneut versuchen.'
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <div class="font-sans text-slate-800 max-w-md mx-auto p-4">
        <div v-if="banner" class="bg-amber-100 text-amber-800 p-2 rounded mb-3 text-sm">{{ banner }}</div>
        <button v-if="w.step.value !== 'service' && w.step.value !== 'success'" @click="w.back()"
                class="text-sm text-blue-600 mb-3">← Zurück</button>

        <ServiceStep v-if="w.step.value === 'service'" :services="services" @select="onService" />
        <PractitionerStep v-else-if="w.step.value === 'practitioner'" :practitioners="practitioners" @select="onPractitioner" />
        <SlotStep v-else-if="w.step.value === 'slot'" :slots="slots" @select="w.chooseSlot" />
        <FormStep v-else-if="w.step.value === 'form'" :server-errors="serverErrors" @submit="onSubmit" />
        <SuccessStep v-else-if="w.step.value === 'success' && result" :result="result"
                     :api-base="apiBase ?? ''" :tenant="tenant ?? ''" />
    </div>
</template>
```

- [ ] **Step 5: Run, expect pass** — `npm run test:widget` → PASS (all widget tests).

- [ ] **Step 6: Commit**

```bash
git add backend/resources/js/widget/App.vue backend/resources/js/widget/steps/SuccessStep.vue backend/tests/widget/app.test.ts
git commit -m "feat: widget App orchestrator (data loading, booking, error handling) + SuccessStep"
```

---

### Task 8: Shadow DOM bootstrap (`main.ts`) + Tailwind injection + build

**Files:**
- Modify: `backend/resources/js/widget/main.ts`
- Create: `backend/resources/js/widget/widget.css`
- Modify: `backend/tailwind.config.js` (add the widget dir to `content`)

- [ ] **Step 1: Create the widget Tailwind entry**

`backend/resources/js/widget/widget.css`:
```css
@tailwind base;
@tailwind components;
@tailwind utilities;
```

- [ ] **Step 2: Ensure Tailwind scans the widget**

In `backend/tailwind.config.js`, confirm `content` includes `./resources/**/*.vue` and `./resources/**/*.ts` (Phase 1 already added these globs — if the widget dir isn't covered, add `'./resources/js/widget/**/*.{vue,ts}'`).

- [ ] **Step 3: Replace `main.ts` with the Shadow DOM bootstrap**

`backend/resources/js/widget/main.ts`:
```ts
import { createApp } from 'vue'
import App from './App.vue'
import { createApi } from './api'
import widgetCss from './widget.css?inline'

export function widgetVersion(): string {
    return 'phase-3'
}

function mountWidget(el: HTMLElement) {
    const tenant = el.dataset.tenant ?? ''
    const apiBase = el.dataset.api ?? ''
    if (!tenant || !apiBase) {
        console.error('[masinga] data-tenant and data-api are required')
        return
    }

    const shadow = el.attachShadow({ mode: 'open' })
    const style = document.createElement('style')
    style.textContent = widgetCss
    shadow.appendChild(style)

    const container = document.createElement('div')
    shadow.appendChild(container)

    createApp(App, { api: createApi(apiBase, tenant), apiBase, tenant }).mount(container)
}

function boot() {
    document.querySelectorAll<HTMLElement>('[data-masinga-booking]').forEach((el) => {
        if (!el.dataset.masingaMounted) {
            el.dataset.masingaMounted = '1'
            mountWidget(el)
        }
    })
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot)
} else {
    boot()
}
```

- [ ] **Step 4: Verify the existing Vitest suite still passes**

Run: `npm run test:widget`
Expected: PASS (the `?inline` import is Vite-specific; if Vitest errors on `widget.css?inline`, add to `vitest.config.ts` test block: `server: { deps: { inline: [/\?inline$/] } }` — but jsdom + the vue plugin usually handle it; if it fails, stub via `vi.mock`). The smoke test still imports `widgetVersion`.

- [ ] **Step 5: Build the widget**

Run: `npm run build:widget`
Expected: `public/widget/masinga-widget.js` produced with the full app, CSS inlined.

- [ ] **Step 6: Commit**

```bash
git add backend/resources/js/widget/main.ts backend/resources/js/widget/widget.css backend/tailwind.config.js
git commit -m "feat: widget Shadow DOM bootstrap with injected Tailwind CSS"
```

---

### Task 9: WordPress plugin (thin embed client)

**Files:**
- Create: `wordpress-plugin/masinga-booking/masinga-booking.php`, `includes/class-settings.php`, `includes/class-shortcode.php`, `includes/class-block.php`, `readme.txt`

- [ ] **Step 1: Main plugin file**

`wordpress-plugin/masinga-booking/masinga-booking.php`:
```php
<?php
/**
 * Plugin Name: Masinga Booking
 * Description: Bindet das Masinga-Booking-Widget per Shortcode/Block ein.
 * Version: 1.0.0
 * Requires PHP: 8.0
 */
if (! defined('ABSPATH')) {
    exit;
}

define('MASINGA_BOOKING_PATH', plugin_dir_path(__FILE__));

require_once MASINGA_BOOKING_PATH . 'includes/class-settings.php';
require_once MASINGA_BOOKING_PATH . 'includes/class-shortcode.php';
require_once MASINGA_BOOKING_PATH . 'includes/class-block.php';

add_action('init', function () {
    (new Masinga_Booking_Settings())->register();
    (new Masinga_Booking_Shortcode())->register();
    (new Masinga_Booking_Block())->register();
});
```

- [ ] **Step 2: Settings page**

`wordpress-plugin/masinga-booking/includes/class-settings.php`:
```php
<?php
if (! defined('ABSPATH')) { exit; }

class Masinga_Booking_Settings
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'fields']);
    }

    public function menu(): void
    {
        add_options_page('Masinga Booking', 'Masinga Booking', 'manage_options', 'masinga-booking', [$this, 'page']);
    }

    public function fields(): void
    {
        register_setting('masinga_booking', 'masinga_booking_tenant');
        register_setting('masinga_booking', 'masinga_booking_api');
    }

    public function page(): void
    {
        ?>
        <div class="wrap">
            <h1>Masinga Booking</h1>
            <form method="post" action="options.php">
                <?php settings_fields('masinga_booking'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="masinga_booking_tenant">Tenant-Slug</label></th>
                        <td><input name="masinga_booking_tenant" id="masinga_booking_tenant" type="text"
                                   value="<?php echo esc_attr(get_option('masinga_booking_tenant', '')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="masinga_booking_api">API-URL</label></th>
                        <td><input name="masinga_booking_api" id="masinga_booking_api" type="url"
                                   value="<?php echo esc_attr(get_option('masinga_booking_api', '')); ?>" class="regular-text"
                                   placeholder="https://app.masinga-booking.de"></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
```

- [ ] **Step 3: Shortcode**

`wordpress-plugin/masinga-booking/includes/class-shortcode.php`:
```php
<?php
if (! defined('ABSPATH')) { exit; }

class Masinga_Booking_Shortcode
{
    public function register(): void
    {
        add_shortcode('masinga_booking', [$this, 'render']);
    }

    public function render($atts): string
    {
        $atts = shortcode_atts([
            'tenant' => get_option('masinga_booking_tenant', ''),
            'api' => get_option('masinga_booking_api', ''),
        ], $atts, 'masinga_booking');

        $tenant = esc_attr($atts['tenant']);
        $api = esc_url($atts['api']);
        if (! $tenant || ! $api) {
            return '<!-- masinga-booking: tenant/api not configured -->';
        }

        $src = esc_url(rtrim($atts['api'], '/') . '/widget/masinga-widget.js');

        return sprintf(
            '<div data-masinga-booking data-tenant="%s" data-api="%s"></div>' .
            '<script src="%s" defer></script>',
            $tenant, $api, $src
        );
    }
}
```

- [ ] **Step 4: Gutenberg block (server-rendered wrapper)**

`wordpress-plugin/masinga-booking/includes/class-block.php`:
```php
<?php
if (! defined('ABSPATH')) { exit; }

class Masinga_Booking_Block
{
    public function register(): void
    {
        if (! function_exists('register_block_type')) {
            return;
        }
        register_block_type('masinga/booking', [
            'render_callback' => fn () => do_shortcode('[masinga_booking]'),
        ]);
    }
}
```

- [ ] **Step 5: readme**

`wordpress-plugin/masinga-booking/readme.txt`:
```
=== Masinga Booking ===
Bindet das Masinga-Booking-Widget ein.

1. Unter Einstellungen → Masinga Booking den Tenant-Slug und die API-URL eintragen.
2. Auf einer Seite den Shortcode [masinga_booking] oder den Block "Masinga Booking" einfügen.

Es werden keine Patientendaten über WordPress verarbeitet — der Browser des Elternteils
kommuniziert direkt mit der Masinga-API.
```

- [ ] **Step 6: Commit**

```bash
git add wordpress-plugin/
git commit -m "feat: thin WordPress plugin (settings + shortcode + Gutenberg block)"
```

---

### Task 10: Manual Chrome verification harness + README

**Files:**
- Create: `backend/public/widget/test.html`
- Modify: `backend/README.md`

- [ ] **Step 1: Create a standalone test page**

`backend/public/widget/test.html` (served at `/widget/test.html`):
```html
<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><title>Widget Test</title></head>
<body style="font-family: sans-serif; padding: 2rem; background:#eee">
    <h1>Host page (styles should NOT leak into the widget)</h1>
    <div data-masinga-booking data-tenant="kidsclub" data-api="http://localhost:8000"></div>
    <script src="/widget/masinga-widget.js" defer></script>
</body>
</html>
```

- [ ] **Step 2: Build + serve + verify in Chrome (manual)**

```bash
npm run build:widget
php artisan serve --host=127.0.0.1 --port=8000   # ensure kidsclub is seeded (php artisan migrate:fresh --seed)
```
Open `http://localhost:8000/widget/test.html` in Chrome and confirm:
1. Widget renders inside a Shadow DOM (inspect: `#shadow-root` on the div); host page's grey background / heading styles do not affect the widget and vice-versa.
2. Full flow: Leistung → Behandler → Termin → Formular (consent required to enable the button) → "Termin bestätigt!" with a cancellation reference.
3. Re-booking the same slot in a second tab shows "Termin nicht mehr verfügbar".

Note: the widget calls `http://localhost:8000/api/v1/widget/kidsclub/*`; CORS is `*` (Phase 2) so the cross-origin case works when embedded elsewhere too.

- [ ] **Step 3: Update README**

Append a "Widget (Phase 3)" section to `backend/README.md`:
```markdown
## Widget (Phase 3)

Embeddable Vue widget (Shadow DOM) for the public booking flow.

- Source: `resources/js/widget/` — build with `npm run build:widget` → `public/widget/masinga-widget.js`.
- Tests: `npm run test:widget` (Vitest + @vue/test-utils).
- Embed (via the WordPress plugin or directly):
  ```html
  <div data-masinga-booking data-tenant="kidsclub" data-api="https://app.masinga-booking.de"></div>
  <script src="https://app.masinga-booking.de/widget/masinga-widget.js" defer></script>
  ```
- Manual check: `/widget/test.html` against a seeded tenant.

WordPress plugin lives in `wordpress-plugin/masinga-booking/` (settings → shortcode `[masinga_booking]` / Gutenberg block). No patient data passes through WordPress.
```

- [ ] **Step 4: Commit**

```bash
git add backend/public/widget/test.html backend/README.md
git commit -m "docs: widget Chrome test harness + README Phase 3 section"
```

(Note: `public/build/` is gitignored, but `public/widget/test.html` is a source file — confirm `.gitignore` doesn't exclude `public/widget/*.html`; if it ignores all of `public/widget`, add `!public/widget/test.html`.)

---

## End-of-Plan Acceptance Criteria

- `npm run test:widget` green (api, wizard, steps, SlotStep, FormStep, app).
- `npm run build:widget` produces `public/widget/masinga-widget.js`.
- Manual Chrome: full booking flow works inside a Shadow DOM against a seeded tenant; styles isolated; 409 handled.
- WordPress plugin embeds the widget via shortcode/block using the configured tenant + API URL.

## Self-Review

- Spec §3 components → T2 (api/types), T3 (wizard), T4–T7 (steps + App), T8 (main/Shadow DOM), T9 (plugin). ✓
- Spec §4 build/embed → T1 (vite.widget.config), T8 (data-attrs), T9 (script src). ✓
- Spec §5 Shadow DOM + CSS injection → T8. ✓
- Spec §6 flow steps → T4–T7. §7 error handling → T2 (mapping) + T7 (banner/serverErrors). ✓
- Spec §8 plugin → T9. §9 tests → every widget task + T10. ✓
- Type consistency: `createApi(base, tenant)` → `{services, practitioners, slots, book}`; `Slot.starts_at/ends_at`; `useWizard()` → `{step, selection, chooseService, choosePractitioner, chooseSlot, complete, back}`; `App` props `{api, apiBase, tenant}`. Used consistently across T2/T3/T7/T8. ✓
- No placeholders: every code step is complete. ✓
- Known risk flagged inline (T8 step 4): Vitest handling of `?inline` CSS import — mitigation given.
