# Widget Booking Flow Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the widget booking flow from 4 steps (`service → termin → form → success`) to 3 steps (`termin → form → confirm → success`), absorbing service selection into Step 1 and adding a dedicated confirmation step with GDPR consent.

**Architecture:** Widget-only change (no backend routes affected). Rewrite `useWizard.ts` state machine, create `StepIndicator.vue` and `ConfirmStep.vue`, absorb `ServiceStep.vue` into `TerminStep.vue`, move submit from `FormStep` to `ConfirmStep`. State bridging via `pendingForm` ref in `App.vue`.

**Tech Stack:** Vue 3 `<script setup lang="ts">`, Vitest + @vue/test-utils, built as standalone IIFE via `vite.widget.config.js`.

---

## File Map

| File | Action |
|---|---|
| `resources/js/widget/useWizard.ts` | Modify — remove `service` step, add `confirm`, update methods |
| `resources/js/widget/components/StepIndicator.vue` | Create — 3-node indigo line stepper |
| `resources/js/widget/steps/TerminStep.vue` | Rewrite — absorb service pills + calendar + slots |
| `resources/js/widget/steps/FormStep.vue` | Modify — remove submit, add recap card, add `initialValues` prop |
| `resources/js/widget/steps/ConfirmStep.vue` | Create — recap + consent + submit |
| `resources/js/widget/App.vue` | Modify — `pendingForm` ref, new handlers, `StepIndicator` |
| `resources/js/widget/steps/ServiceStep.vue` | **Delete** |
| `tests/widget/wizard.test.ts` | Rewrite — new 3-step flow |
| `tests/widget/steps.test.ts` | Rewrite — remove ServiceStep tests, add TerminStep + ConfirmStep |

---

### Task 1 — useWizard.ts rewrite

**Files:**
- Modify: `resources/js/widget/useWizard.ts`

`★ Insight ─────────────────────────────────────`
`useWizard` is a Vue composable acting as a **state machine** — it owns the canonical source of truth for which step the user is on and what they have selected. Keeping step navigation centralized here (rather than scattered across components) means we have one place to test all transition logic.
`─────────────────────────────────────────────────`

- [ ] **Step 1: Write the failing tests**

Replace `tests/widget/wizard.test.ts` entirely:

```typescript
import { describe, it, expect, beforeEach } from 'vitest'
import { useWizard } from '../../resources/js/widget/useWizard'

const mockService = { id: 1, name: 'Erstuntersuchung', duration_minutes: 45, color: '#4a9d6f' }
const mockPractitioner = { id: 1, first_name: 'Anna', last_name: 'Müller', title: 'Dr.' }
const mockSlot = {
  starts_at: '2026-06-10T10:30:00+02:00',
  ends_at: '2026-06-10T11:15:00+02:00',
  practitioner: mockPractitioner,
}

describe('useWizard', () => {
  let w: ReturnType<typeof useWizard>
  beforeEach(() => { w = useWizard() })

  it('starts on termin step', () => {
    expect(w.step.value).toBe('termin')
  })

  it('chooseService stores service and stays on termin', () => {
    w.chooseService(mockService)
    expect(w.selection.service).toEqual(mockService)
    expect(w.step.value).toBe('termin')
  })

  it('chooseSlot stores slot and advances to form', () => {
    w.chooseService(mockService)
    w.chooseSlot(mockSlot)
    expect(w.selection.slot).toEqual(mockSlot)
    expect(w.step.value).toBe('form')
  })

  it('advance moves from form to confirm', () => {
    w.chooseService(mockService)
    w.chooseSlot(mockSlot)
    expect(w.step.value).toBe('form')
    w.advance()
    expect(w.step.value).toBe('confirm')
  })

  it('back from confirm returns to form', () => {
    w.chooseService(mockService)
    w.chooseSlot(mockSlot)
    w.advance()
    expect(w.step.value).toBe('confirm')
    w.back()
    expect(w.step.value).toBe('form')
  })

  it('back from form returns to termin', () => {
    w.chooseService(mockService)
    w.chooseSlot(mockSlot)
    expect(w.step.value).toBe('form')
    w.back()
    expect(w.step.value).toBe('termin')
  })

  it('backToTermin jumps to termin from any step', () => {
    w.chooseService(mockService)
    w.chooseSlot(mockSlot)
    w.advance()
    expect(w.step.value).toBe('confirm')
    w.backToTermin()
    expect(w.step.value).toBe('termin')
  })

  it('goSuccess advances to success', () => {
    w.chooseService(mockService)
    w.chooseSlot(mockSlot)
    w.advance()
    w.goSuccess()
    expect(w.step.value).toBe('success')
  })

  it('isVisible returns true for termin|form|confirm but not success', () => {
    expect(w.isVisible.value).toBe(true) // on termin
    w.chooseService(mockService)
    w.chooseSlot(mockSlot)
    w.advance()
    w.goSuccess()
    expect(w.isVisible.value).toBe(false)
  })
})
```

- [ ] **Step 2: Run tests to confirm failure**

```bash
npm run test:widget -- --reporter=verbose 2>&1 | head -40
```

Expected: multiple FAIL (step starts on 'service', `chooseService` advances, `advance`/`confirm`/`backToTermin` not implemented).

- [ ] **Step 3: Rewrite useWizard.ts**

```typescript
import { ref, computed } from 'vue'

export type Step = 'termin' | 'form' | 'confirm' | 'success'
const ORDER: Step[] = ['termin', 'form', 'confirm', 'success']

export interface Service {
  id: number
  name: string
  duration_minutes: number
  color: string
}

export interface Practitioner {
  id: number
  first_name: string
  last_name: string
  title: string
}

export interface Slot {
  starts_at: string
  ends_at: string
  practitioner: Practitioner
}

export interface Selection {
  service: Service | null
  slot: Slot | null
}

export function useWizard() {
  const step = ref<Step>('termin')
  const selection = ref<Selection>({ service: null, slot: null })

  const isVisible = computed(() => step.value !== 'success')

  function chooseService(service: Service) {
    selection.value.service = service
    // deliberately stays on termin — calendar loads after service is chosen
  }

  function chooseSlot(slot: Slot) {
    selection.value.slot = slot
    go('form')
  }

  function advance() {
    const idx = ORDER.indexOf(step.value)
    if (idx < ORDER.length - 1) step.value = ORDER[idx + 1]
  }

  function back() {
    const idx = ORDER.indexOf(step.value)
    if (idx > 0) step.value = ORDER[idx - 1]
  }

  function backToTermin() {
    step.value = 'termin'
  }

  function goSuccess() {
    step.value = 'success'
  }

  function go(target: Step) {
    step.value = target
  }

  return { step, selection, isVisible, chooseService, chooseSlot, advance, back, backToTermin, goSuccess }
}
```

- [ ] **Step 4: Run tests**

```bash
npm run test:widget -- --reporter=verbose
```

Expected: all wizard tests PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/widget/useWizard.ts tests/widget/wizard.test.ts
git commit -m "feat(widget): rewrite useWizard — 3-step flow (termin/form/confirm/success)"
```

---

### Task 2 — StepIndicator.vue (Style C — indigo line)

**Files:**
- Create: `resources/js/widget/components/StepIndicator.vue`

`★ Insight ─────────────────────────────────────`
Le stepper utilise du **CSS pur** plutôt qu'une bibliothèque. La ligne de progression est une `div` absolue dont la `width` est calculée via `computed` en fonction du step actuel. Cela garde le composant zero-dependency — crucial pour un IIFE widget embarqué où chaque KB compte.
`─────────────────────────────────────────────────`

- [ ] **Step 1: Create StepIndicator.vue**

```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { Step } from '../useWizard'

const props = defineProps<{ currentStep: Step }>()

const STEPS: Array<{ key: Step; label: string }> = [
  { key: 'termin',  label: 'Termin'     },
  { key: 'form',    label: 'Angaben'    },
  { key: 'confirm', label: 'Bestätigen' },
]

const currentIndex = computed(() =>
  STEPS.findIndex(s => s.key === props.currentStep)
)

const progressPercent = computed(() => {
  if (currentIndex.value === 0) return '0%'
  if (currentIndex.value === 1) return '50%'
  return '100%'
})

function nodeState(index: number): 'done' | 'active' | 'future' {
  if (index < currentIndex.value) return 'done'
  if (index === currentIndex.value) return 'active'
  return 'future'
}
</script>

<template>
  <div class="mb-6 px-4">
    <div class="relative flex justify-between items-start">
      <!-- Track -->
      <div class="absolute top-[10px] left-0 right-0 h-[2px] bg-slate-200">
        <div
          class="h-full bg-indigo-500 transition-all duration-500"
          :style="{ width: progressPercent }"
        />
      </div>

      <!-- Nodes -->
      <div
        v-for="(s, i) in STEPS"
        :key="s.key"
        class="relative flex flex-col items-center gap-1.5 z-10"
      >
        <!-- Done -->
        <div
          v-if="nodeState(i) === 'done'"
          class="w-5 h-5 rounded-full bg-indigo-500 flex items-center justify-center"
        >
          <svg class="w-3 h-3 text-white" viewBox="0 0 12 12" fill="none">
            <path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>

        <!-- Active -->
        <div
          v-else-if="nodeState(i) === 'active'"
          class="w-5 h-5 rounded-full bg-indigo-500 ring-[3px] ring-indigo-200"
        />

        <!-- Future -->
        <div
          v-else
          class="w-5 h-5 rounded-full bg-white border-2 border-slate-300"
        />

        <span
          class="text-[11px] font-semibold whitespace-nowrap"
          :class="{
            'text-indigo-600': nodeState(i) !== 'future',
            'text-slate-400':  nodeState(i) === 'future',
          }"
        >
          {{ s.label }}
        </span>
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/widget/components/StepIndicator.vue
git commit -m "feat(widget): create StepIndicator — 3-node indigo line stepper"
```

---

### Task 3 — TerminStep.vue rewrite (absorbs ServiceStep)

**Files:**
- Modify: `resources/js/widget/steps/TerminStep.vue`

`★ Insight ─────────────────────────────────────`
Le chargement **séquentiel** des sections (pills → calendrier → slots) est une UX *progressive disclosure* : chaque section n'apparaît que si la précédente a une valeur. Cela guide l'utilisateur sans écran dédié et garde tout dans la même vue scrollable.
`─────────────────────────────────────────────────`

- [ ] **Step 1: Write the failing tests**

Replace the TerminStep section of `tests/widget/steps.test.ts`:

```typescript
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import TerminStep from '../../resources/js/widget/steps/TerminStep.vue'

const services = [
  { id: 1, name: 'Erstuntersuchung', duration_minutes: 45, color: '#4a9d6f' },
  { id: 2, name: 'Prophylaxe', duration_minutes: 30, color: '#f59e0b' },
]
const availableDates = ['2026-06-08', '2026-06-09', '2026-06-10']
const slots = [
  { starts_at: '2026-06-10T09:00:00+02:00', ends_at: '2026-06-10T09:45:00+02:00',
    practitioner: { id: 1, first_name: 'Anna', last_name: 'Müller', title: 'Dr.' } },
]

describe('TerminStep', () => {
  it('shows service pills and no calendar before service chosen', () => {
    const w = mount(TerminStep, {
      props: { services, availableDates, slots: [], loadingDates: false, loadingSlots: false, selectedDate: null },
    })
    expect(w.findAll('[data-service-pill]')).toHaveLength(2)
    expect(w.find('[data-calendar]').exists()).toBe(false)
  })

  it('emits service-select when a service pill is clicked', async () => {
    const w = mount(TerminStep, {
      props: { services, availableDates, slots: [], loadingDates: false, loadingSlots: false, selectedDate: null },
    })
    await w.find('[data-service-pill]').trigger('click')
    expect(w.emitted('service-select')).toBeTruthy()
    expect(w.emitted('service-select')![0][0]).toMatchObject({ service: services[0] })
  })

  it('shows calendar after service is chosen', async () => {
    const w = mount(TerminStep, {
      props: {
        services, availableDates, slots: [], loadingDates: false,
        loadingSlots: false, selectedDate: null,
        selectedService: services[0],
      },
    })
    expect(w.find('[data-calendar]').exists()).toBe(true)
  })

  it('shows slots after date is chosen', async () => {
    const w = mount(TerminStep, {
      props: {
        services, availableDates, slots, loadingDates: false,
        loadingSlots: false, selectedDate: '2026-06-10',
        selectedService: services[0],
      },
    })
    expect(w.find('[data-slots]').exists()).toBe(true)
  })

  it('emits select when a slot is clicked', async () => {
    const w = mount(TerminStep, {
      props: {
        services, availableDates, slots, loadingDates: false,
        loadingSlots: false, selectedDate: '2026-06-10',
        selectedService: services[0],
      },
    })
    await w.find('[data-slot-btn]').trigger('click')
    expect(w.emitted('select')).toBeTruthy()
    expect(w.emitted('select')![0][0]).toEqual(slots[0])
  })
})
```

- [ ] **Step 2: Run tests to confirm failure**

```bash
npm run test:widget -- --reporter=verbose 2>&1 | grep -A5 "TerminStep"
```

Expected: FAIL (old TerminStep has different props/behavior).

- [ ] **Step 3: Rewrite TerminStep.vue**

```vue
<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import type { Service, Slot } from '../useWizard'
import BookingCalendar from '../components/BookingCalendar.vue'

const props = defineProps<{
  services: Service[]
  selectedService: Service | null
  availableDates: string[]
  loadingDates: boolean
  selectedDate: string | null
  slots: Slot[]
  loadingSlots: boolean
}>()

const emit = defineEmits<{
  'service-select': [{ service: Service; monthWindow: { from: string; to: string } }]
  'month-change':   [{ from: string; to: string }]
  'pick-date':      [date: string]
  'select':         [slot: Slot]
}>()

const calendarRef = ref<HTMLElement | null>(null)
const slotsRef    = ref<HTMLElement | null>(null)

function onServiceClick(service: Service) {
  const now  = new Date()
  const from = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
  const next = new Date(now.getFullYear(), now.getMonth() + 2, 0)
  const to   = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-${String(next.getDate()).padStart(2, '0')}`
  emit('service-select', { service, monthWindow: { from, to } })
  nextTick(() => calendarRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}

watch(() => props.selectedDate, (d) => {
  if (d) nextTick(() => slotsRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
})

const practitionerFilter = ref<number | null>(null)
const uniquePractitioners = computed(() => {
  const seen = new Set<number>()
  return props.slots
    .map(s => s.practitioner)
    .filter(p => { if (seen.has(p.id)) return false; seen.add(p.id); return true })
})

const filteredSlots = computed(() =>
  practitionerFilter.value
    ? props.slots.filter(s => s.practitioner.id === practitionerFilter.value)
    : props.slots
)

function formatTime(isoString: string) {
  return new Date(isoString).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div class="space-y-5">

    <!-- Section A: Service pills (always visible) -->
    <div>
      <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">① Leistung</p>
      <div class="flex flex-col gap-2">
        <button
          v-for="s in services" :key="s.id"
          data-service-pill
          @click="onServiceClick(s)"
          :class="[
            'flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-semibold text-left transition',
            selectedService?.id === s.id
              ? 'bg-slate-800 text-white shadow-md'
              : 'bg-slate-50 text-slate-600 border border-slate-200 hover:bg-slate-100'
          ]"
        >
          <span>{{ s.name }}</span>
          <span class="text-xs font-normal opacity-70">{{ s.duration_minutes }} Min.</span>
        </button>
      </div>
    </div>

    <!-- Section B: Calendar (appears after service chosen) -->
    <Transition name="fade-up">
      <div v-if="selectedService" ref="calendarRef" data-calendar>
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">② Datum</p>
        <BookingCalendar
          :available-dates="availableDates"
          :selected-date="selectedDate"
          :loading="loadingDates"
          @pick-date="emit('pick-date', $event)"
          @month-change="emit('month-change', $event)"
        />
      </div>
    </Transition>

    <!-- Section C: Slots (appears after date chosen) -->
    <Transition name="fade-up">
      <div v-if="selectedService && selectedDate" ref="slotsRef" data-slots>
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">③ Uhrzeit</p>

        <!-- Practitioner filter (only if >1 practitioner) -->
        <div v-if="uniquePractitioners.length > 1" class="flex flex-wrap gap-1.5 mb-3">
          <button
            v-for="p in uniquePractitioners" :key="p.id"
            @click="practitionerFilter = practitionerFilter === p.id ? null : p.id"
            :class="[
              'px-2 py-1 rounded-full text-xs font-semibold transition',
              practitionerFilter === p.id
                ? 'bg-slate-800 text-white'
                : 'bg-slate-100 text-slate-500 hover:bg-slate-200'
            ]"
          >
            {{ p.first_name }}
          </button>
        </div>

        <div v-if="loadingSlots" class="flex justify-center py-4">
          <div class="w-5 h-5 border-2 border-indigo-400 border-t-transparent rounded-full animate-spin" />
        </div>
        <div v-else-if="filteredSlots.length === 0" class="text-sm text-slate-400 py-2">
          Keine Termine verfügbar.
        </div>
        <div v-else class="flex flex-wrap gap-2">
          <button
            v-for="slot in filteredSlots" :key="slot.starts_at"
            data-slot-btn
            @click="emit('select', slot)"
            class="flex items-center gap-1.5 px-3 py-2 bg-white border border-slate-200 rounded-xl text-sm font-semibold hover:border-indigo-400 hover:bg-indigo-50 transition"
          >
            <span
              class="w-2 h-2 rounded-full flex-shrink-0"
              :style="{ background: slot.practitioner.id % 2 === 0 ? '#4a9d6f' : '#98ACBA' }"
            />
            {{ formatTime(slot.starts_at) }}
          </button>
        </div>
      </div>
    </Transition>

  </div>
</template>

<style scoped>
.fade-up-enter-active { transition: opacity 200ms, transform 200ms; }
.fade-up-enter-from   { opacity: 0; transform: translateY(4px); }
</style>
```

- [ ] **Step 4: Run tests**

```bash
npm run test:widget -- --reporter=verbose 2>&1 | grep -A3 "TerminStep"
```

Expected: TerminStep tests PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/widget/steps/TerminStep.vue tests/widget/steps.test.ts
git commit -m "feat(widget): rewrite TerminStep — service pills + calendar + slots in one step"
```

---

### Task 4 — FormStep.vue modifications

**Files:**
- Modify: `resources/js/widget/steps/FormStep.vue`

`★ Insight ─────────────────────────────────────`
Le pattern `initialValues` + `watch` est la façon idiomatique de **pré-remplir un formulaire Vue** quand on navigue en arrière : `watch(props.initialValues, (v) => { if (v) form.value = {...v} }, { immediate: true })`. Sans ce watch, le formulaire serait vide à chaque retour, dégradant l'UX.
`─────────────────────────────────────────────────`

- [ ] **Step 1: Write test for FormStep changes**

Add to `tests/widget/steps.test.ts`:

```typescript
import FormStep from '../../resources/js/widget/steps/FormStep.vue'

describe('FormStep', () => {
  const selection = {
    service: { id: 1, name: 'Erstuntersuchung', duration_minutes: 45, color: '#4a9d6f' },
    slot: {
      starts_at: '2026-06-10T10:30:00+02:00',
      ends_at:   '2026-06-10T11:15:00+02:00',
      practitioner: { id: 1, first_name: 'Anna', last_name: 'Müller', title: 'Dr.' },
    },
  }

  it('shows recap card with service name and date', () => {
    const w = mount(FormStep, { props: { selection, initialValues: null } })
    expect(w.text()).toContain('Erstuntersuchung')
    expect(w.text()).toContain('45 Min.')
  })

  it('emits advance with form data when Weiter clicked', async () => {
    const w = mount(FormStep, { props: { selection, initialValues: null } })
    await w.find('[data-child-first]').setValue('Max')
    await w.find('[data-child-last]').setValue('Müller')
    await w.find('[data-child-dob]').setValue('2020-01-01')
    await w.find('[data-parent-first]').setValue('Hans')
    await w.find('[data-parent-email]').setValue('hans@example.com')
    await w.find('[data-parent-phone]').setValue('+49 123 456')
    await w.find('form').trigger('submit')
    expect(w.emitted('advance')).toBeTruthy()
    const data = w.emitted('advance')![0][0] as Record<string, unknown>
    expect(data.child_first_name).toBe('Max')
    expect(data.parent_email).toBe('hans@example.com')
  })

  it('pre-fills form with initialValues', () => {
    const initialValues = {
      child_first_name: 'Lena', child_last_name: 'Bauer', child_dob: '2019-05-15',
      parent_first_name: 'Petra', parent_email: 'petra@test.de', parent_phone: '+49 789',
    }
    const w = mount(FormStep, { props: { selection, initialValues } })
    expect((w.find('[data-child-first]').element as HTMLInputElement).value).toBe('Lena')
    expect((w.find('[data-parent-email]').element as HTMLInputElement).value).toBe('petra@test.de')
  })
})
```

- [ ] **Step 2: Run to confirm failure**

```bash
npm run test:widget -- --reporter=verbose 2>&1 | grep -A5 "FormStep"
```

Expected: FAIL (no recap card, no `advance` emit, no `initialValues`).

- [ ] **Step 3: Update FormStep.vue**

Replace `<script setup>` and `<template>`. Keep existing field validation logic:

```vue
<script setup lang="ts">
import { ref, watch } from 'vue'
import type { Selection } from '../useWizard'

const props = defineProps<{
  selection: Selection
  initialValues: Record<string, string> | null
}>()

const emit = defineEmits<{
  advance: [data: Record<string, string>]
  back: []
}>()

const form = ref({
  child_first_name: '',
  child_last_name: '',
  child_dob: '',
  parent_first_name: '',
  parent_email: '',
  parent_phone: '',
  honeypot: '',
})

const errors = ref<Record<string, string>>({})

watch(() => props.initialValues, (v) => {
  if (v) form.value = { ...form.value, ...v }
}, { immediate: true })

function formatDate(isoString: string) {
  return new Date(isoString).toLocaleDateString('de-DE', {
    weekday: 'short', day: 'numeric', month: 'long', year: 'numeric',
  })
}

function formatTime(isoString: string) {
  return new Date(isoString).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
}

function validate(): boolean {
  errors.value = {}
  if (!form.value.child_first_name.trim()) errors.value.child_first_name = 'Pflichtfeld'
  if (!form.value.child_last_name.trim())  errors.value.child_last_name  = 'Pflichtfeld'
  if (!form.value.child_dob)               errors.value.child_dob        = 'Pflichtfeld'
  if (!form.value.parent_first_name.trim()) errors.value.parent_first_name = 'Pflichtfeld'
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.parent_email))
    errors.value.parent_email = 'Ungültige E-Mail'
  if (!form.value.parent_phone.trim()) errors.value.parent_phone = 'Pflichtfeld'
  return Object.keys(errors.value).length === 0
}

function onSubmit() {
  if (form.value.honeypot) return
  if (!validate()) return
  emit('advance', { ...form.value })
}
</script>

<template>
  <div class="space-y-4">

    <!-- Recap card -->
    <div class="rounded-xl bg-sky-50 border border-sky-200 px-3 py-2.5 text-sm">
      <div class="font-bold text-sky-800">
        {{ selection.service?.name }}
        <span class="text-sky-600 font-normal">· {{ selection.service?.duration_minutes }} Min.</span>
      </div>
      <div class="text-sky-700 text-xs mt-0.5" v-if="selection.slot">
        {{ formatDate(selection.slot.starts_at) }} · {{ formatTime(selection.slot.starts_at) }} Uhr
      </div>
    </div>

    <form @submit.prevent="onSubmit" class="space-y-4" novalidate>
      <!-- Honeypot -->
      <input v-model="form.honeypot" type="text" name="website" style="display:none" tabindex="-1" autocomplete="off" />

      <!-- Child -->
      <div>
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Kind</p>
        <div class="flex flex-col gap-2">
          <div>
            <input v-model="form.child_first_name" data-child-first
              placeholder="Vorname" type="text"
              :class="['w-full px-3 py-2 rounded-xl border text-sm focus:outline-none focus:border-indigo-400 transition',
                errors.child_first_name ? 'border-red-400 bg-red-50' : 'border-slate-200 bg-slate-50']"
            />
            <p v-if="errors.child_first_name" class="text-red-500 text-xs mt-0.5">{{ errors.child_first_name }}</p>
          </div>
          <div>
            <input v-model="form.child_last_name" data-child-last
              placeholder="Nachname" type="text"
              :class="['w-full px-3 py-2 rounded-xl border text-sm focus:outline-none focus:border-indigo-400 transition',
                errors.child_last_name ? 'border-red-400 bg-red-50' : 'border-slate-200 bg-slate-50']"
            />
          </div>
          <div>
            <input v-model="form.child_dob" data-child-dob
              type="date"
              :class="['w-full px-3 py-2 rounded-xl border text-sm focus:outline-none focus:border-indigo-400 transition',
                errors.child_dob ? 'border-red-400 bg-red-50' : 'border-slate-200 bg-slate-50']"
            />
          </div>
        </div>
      </div>

      <!-- Parent -->
      <div>
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Elternteil</p>
        <div class="flex flex-col gap-2">
          <input v-model="form.parent_first_name" data-parent-first
            placeholder="Vorname" type="text"
            :class="['w-full px-3 py-2 rounded-xl border text-sm focus:outline-none focus:border-indigo-400 transition',
              errors.parent_first_name ? 'border-red-400 bg-red-50' : 'border-slate-200 bg-slate-50']"
          />
          <div>
            <input v-model="form.parent_email" data-parent-email
              placeholder="E-Mail" type="email"
              :class="['w-full px-3 py-2 rounded-xl border text-sm focus:outline-none focus:border-indigo-400 transition',
                errors.parent_email ? 'border-red-400 bg-red-50' : 'border-slate-200 bg-slate-50']"
            />
            <p v-if="errors.parent_email" class="text-red-500 text-xs mt-0.5">{{ errors.parent_email }}</p>
          </div>
          <input v-model="form.parent_phone" data-parent-phone
            placeholder="Telefon" type="tel"
            :class="['w-full px-3 py-2 rounded-xl border text-sm focus:outline-none focus:border-indigo-400 transition',
              errors.parent_phone ? 'border-red-400 bg-red-50' : 'border-slate-200 bg-slate-50']"
          />
        </div>
      </div>

      <div class="flex gap-2 pt-1">
        <button type="button" @click="emit('back')"
          class="px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition">
          ← Zurück
        </button>
        <button type="submit"
          class="flex-1 py-2.5 bg-slate-800 text-white rounded-xl text-sm font-bold hover:bg-slate-700 transition">
          Weiter →
        </button>
      </div>
    </form>

  </div>
</template>
```

- [ ] **Step 4: Run tests**

```bash
npm run test:widget -- --reporter=verbose 2>&1 | grep -A5 "FormStep"
```

Expected: FormStep tests PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/widget/steps/FormStep.vue tests/widget/steps.test.ts
git commit -m "feat(widget): update FormStep — recap card, Weiter→advance, initialValues pre-fill"
```

---

### Task 5 — ConfirmStep.vue (new)

**Files:**
- Create: `resources/js/widget/steps/ConfirmStep.vue`

- [ ] **Step 1: Write tests**

Add to `tests/widget/steps.test.ts`:

```typescript
import ConfirmStep from '../../resources/js/widget/steps/ConfirmStep.vue'

describe('ConfirmStep', () => {
  const selection = {
    service: { id: 1, name: 'Erstuntersuchung', duration_minutes: 45, color: '#4a9d6f' },
    slot: {
      starts_at: '2026-06-10T10:30:00+02:00',
      ends_at:   '2026-06-10T11:15:00+02:00',
      practitioner: { id: 1, first_name: 'Anna', last_name: 'Müller', title: 'Dr.' },
    },
  }
  const formData = {
    child_first_name: 'Max', child_last_name: 'Müller', child_dob: '2020-01-01',
    parent_first_name: 'Hans', parent_email: 'hans@example.com', parent_phone: '+49 123',
  }

  it('shows all recap data', () => {
    const w = mount(ConfirmStep, { props: { selection, formData, loading: false } })
    expect(w.text()).toContain('Erstuntersuchung')
    expect(w.text()).toContain('Max Müller')
    expect(w.text()).toContain('hans@example.com')
    expect(w.text()).toContain('hans@example.com') // email notification banner
  })

  it('submit button is disabled when consent unchecked', () => {
    const w = mount(ConfirmStep, { props: { selection, formData, loading: false } })
    const btn = w.find('[data-submit-btn]')
    expect((btn.element as HTMLButtonElement).disabled).toBe(true)
  })

  it('submit button is enabled after consent checked', async () => {
    const w = mount(ConfirmStep, { props: { selection, formData, loading: false } })
    await w.find('[data-consent-checkbox]').setValue(true)
    const btn = w.find('[data-submit-btn]')
    expect((btn.element as HTMLButtonElement).disabled).toBe(false)
  })

  it('emits submit when button clicked with consent', async () => {
    const w = mount(ConfirmStep, { props: { selection, formData, loading: false } })
    await w.find('[data-consent-checkbox]').setValue(true)
    await w.find('[data-submit-btn]').trigger('click')
    expect(w.emitted('submit')).toBeTruthy()
  })

  it('emits back when Zurück clicked', async () => {
    const w = mount(ConfirmStep, { props: { selection, formData, loading: false } })
    await w.find('[data-back-btn]').trigger('click')
    expect(w.emitted('back')).toBeTruthy()
  })
})
```

- [ ] **Step 2: Run to confirm failure**

```bash
npm run test:widget -- --reporter=verbose 2>&1 | grep -A5 "ConfirmStep"
```

Expected: FAIL (file does not exist).

- [ ] **Step 3: Create ConfirmStep.vue**

```vue
<script setup lang="ts">
import { ref } from 'vue'
import type { Selection } from '../useWizard'

const props = defineProps<{
  selection: Selection
  formData: Record<string, string>
  loading: boolean
}>()

const emit = defineEmits<{
  submit: []
  back: []
}>()

const consent = ref(false)

function formatDate(isoString: string) {
  return new Date(isoString).toLocaleDateString('de-DE', {
    weekday: 'short', day: 'numeric', month: 'long', year: 'numeric',
  })
}

function formatTime(isoString: string) {
  return new Date(isoString).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div class="space-y-4">

    <!-- Summary card -->
    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Zusammenfassung</p>
    <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 text-sm space-y-2">
      <div class="flex justify-between">
        <span class="text-slate-500">Leistung</span>
        <span class="font-semibold">
          {{ selection.service?.name }}
          <span class="text-slate-400 font-normal text-xs">· {{ selection.service?.duration_minutes }} Min.</span>
        </span>
      </div>
      <div class="flex justify-between" v-if="selection.slot">
        <span class="text-slate-500">Datum</span>
        <span class="font-semibold text-right">
          {{ formatDate(selection.slot.starts_at) }}
          <span class="text-slate-500 font-normal"> · {{ formatTime(selection.slot.starts_at) }}</span>
        </span>
      </div>
      <div class="flex justify-between" v-if="selection.slot">
        <span class="text-slate-500">Behandler</span>
        <span class="font-semibold">
          {{ selection.slot.practitioner.title }}
          {{ selection.slot.practitioner.first_name }}
          {{ selection.slot.practitioner.last_name }}
        </span>
      </div>
      <div class="border-t border-slate-200 pt-2 mt-2 space-y-1.5">
        <div class="flex justify-between">
          <span class="text-slate-500">Kind</span>
          <span class="font-semibold">{{ formData.child_first_name }} {{ formData.child_last_name }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-slate-500">E-Mail</span>
          <span class="font-semibold text-xs truncate max-w-[160px]">{{ formData.parent_email }}</span>
        </div>
      </div>
    </div>

    <!-- Email notification banner -->
    <div class="rounded-xl bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800">
      📧 Bestätigungsmail wird an <strong>{{ formData.parent_email }}</strong> gesendet
    </div>

    <!-- Consent -->
    <label class="flex items-start gap-2.5 cursor-pointer select-none">
      <input
        v-model="consent"
        type="checkbox"
        data-consent-checkbox
        class="mt-0.5 w-4 h-4 rounded accent-indigo-600 flex-shrink-0"
      />
      <span class="text-xs text-slate-600 leading-relaxed">
        Ich stimme der Verarbeitung meiner Daten gemäß der
        <span class="underline text-slate-700">Datenschutzerklärung</span> zu.
      </span>
    </label>

    <!-- Actions -->
    <div class="flex gap-2 pt-1">
      <button
        type="button"
        data-back-btn
        @click="emit('back')"
        class="px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition"
      >
        ← Zurück
      </button>
      <button
        type="button"
        data-submit-btn
        :disabled="!consent || loading"
        @click="emit('submit')"
        :class="[
          'flex-1 py-2.5 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2',
          consent && !loading
            ? 'bg-gradient-to-br from-indigo-500 to-indigo-700 text-white shadow-md hover:shadow-lg'
            : 'bg-slate-200 text-slate-400 cursor-not-allowed'
        ]"
      >
        <span v-if="loading" class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
        <span>Termin buchen ✓</span>
      </button>
    </div>

  </div>
</template>
```

- [ ] **Step 4: Run tests**

```bash
npm run test:widget -- --reporter=verbose 2>&1 | grep -A5 "ConfirmStep"
```

Expected: all ConfirmStep tests PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/widget/steps/ConfirmStep.vue tests/widget/steps.test.ts
git commit -m "feat(widget): create ConfirmStep — recap + consent + book button"
```

---

### Task 6 — App.vue rewrite

**Files:**
- Modify: `resources/js/widget/App.vue`

`★ Insight ─────────────────────────────────────`
`pendingForm` dans `App.vue` joue le rôle d'un **mini-store de session** : il survit à la navigation inter-steps mais est réinitialisé à `null` quand l'utilisateur arrive sur `success`. C'est plus léger que Pinia/Vuex pour un widget IIFE où chaque KB compte.
`─────────────────────────────────────────────────`

- [ ] **Step 1: Read the current App.vue to understand its full structure**

```bash
cat backend/resources/js/widget/App.vue
```

Note: this step is to understand the current API call structure (`onMonthChange`, `onSubmit`, etc.) before rewriting.

- [ ] **Step 2: Update App.vue — key changes**

The changes to App.vue are surgical. Apply these modifications:

**a) Import ConfirmStep and StepIndicator, remove ServiceStep:**

```typescript
// Remove:
import ServiceStep from './steps/ServiceStep.vue'
// Add:
import ConfirmStep from './steps/ConfirmStep.vue'
import StepIndicator from './components/StepIndicator.vue'
```

**b) Add pendingForm ref:**

```typescript
const pendingForm = ref<Record<string, string> | null>(null)
```

**c) Update useWizard usage — chooseService no longer advances:**

The `onServiceSelect` handler now calls `w.chooseService()` and immediately loads dates, but does NOT call `w.go('termin')` (it's already on termin):

```typescript
function onServiceSelect({ service, monthWindow }: { service: Service; monthWindow: { from: string; to: string } }) {
  w.chooseService(service)
  onMonthChange(monthWindow)
}
```

**d) Add form advance handler:**

```typescript
function onFormAdvance(data: Record<string, string>) {
  pendingForm.value = data
  w.advance()
}
```

**e) Update onSubmit — reads pendingForm, called from ConfirmStep:**

```typescript
async function onSubmit() {
  if (!pendingForm.value || !w.selection.value.slot || !w.selection.value.service) return
  submitLoading.value = true
  try {
    await api.book({
      service_id:       w.selection.value.service.id,
      practitioner_id:  w.selection.value.slot.practitioner.id,
      starts_at:        w.selection.value.slot.starts_at,
      ...pendingForm.value,
    })
    w.goSuccess()
    pendingForm.value = null
  } catch (e: unknown) {
    const err = e as { code?: string }
    if (err?.code === 'slot_taken') {
      w.backToTermin()
      slotTakenError.value = true
    }
  } finally {
    submitLoading.value = false
  }
}
```

**f) Add `submitLoading` and `slotTakenError` refs if not already present:**

```typescript
const submitLoading = ref(false)
const slotTakenError = ref(false)
```

**g) Update template — add StepIndicator, route new steps:**

```vue
<template>
  <div ...existing wrapper...>

    <!-- Stepper (hidden on success) -->
    <StepIndicator
      v-if="w.step.value !== 'success'"
      :current-step="w.step.value"
    />

    <!-- Step: termin -->
    <TerminStep
      v-if="w.step.value === 'termin'"
      :services="services"
      :selected-service="w.selection.value.service"
      :available-dates="availableDates"
      :loading-dates="loadingDates"
      :selected-date="selectedDate"
      :slots="slots"
      :loading-slots="loadingSlots"
      @service-select="onServiceSelect"
      @month-change="onMonthChange"
      @pick-date="onPickDate"
      @select="onSlotSelect"
    />

    <!-- Step: form -->
    <FormStep
      v-else-if="w.step.value === 'form'"
      :selection="w.selection.value"
      :initial-values="pendingForm"
      @advance="onFormAdvance"
      @back="w.back()"
    />

    <!-- Step: confirm -->
    <ConfirmStep
      v-else-if="w.step.value === 'confirm'"
      :selection="w.selection.value"
      :form-data="pendingForm ?? {}"
      :loading="submitLoading"
      @submit="onSubmit"
      @back="w.back()"
    />

    <!-- Step: success — existing SuccessStep unchanged -->
    <SuccessStep
      v-else-if="w.step.value === 'success'"
      ...existing props...
    />

  </div>
</template>
```

- [ ] **Step 3: Delete ServiceStep.vue**

```bash
rm backend/resources/js/widget/steps/ServiceStep.vue
```

- [ ] **Step 4: Build the widget to check for TypeScript/compilation errors**

```bash
cd backend && npm run build:widget 2>&1 | tail -20
```

Expected: no errors, `public/widget/masinga-widget.js` updated.

- [ ] **Step 5: Run the full widget test suite**

```bash
npm run test:widget
```

Expected: all tests PASS (wizard + FormStep + ConfirmStep + TerminStep).

- [ ] **Step 6: Commit**

```bash
git add resources/js/widget/App.vue
git rm resources/js/widget/steps/ServiceStep.vue
git commit -m "feat(widget): wire App.vue — 3-step flow, pendingForm, StepIndicator, delete ServiceStep"
```

---

### Task 7 — Visual browser check

**Files:** none (testing only)

- [ ] **Step 1: Start the dev server**

```bash
cd backend && composer dev
```

- [ ] **Step 2: Open the widget test page**

Navigate to `http://localhost:8000/widget-test` (or wherever the widget embed is served). If no test page exists, go to a page that embeds the widget or test via the widget HTML directly.

Alternatively, build the widget and open it in isolation:

```bash
npm run build:widget
# open public/widget/masinga-widget.js in a test HTML harness
```

- [ ] **Step 3: Test the golden path**

1. Step 1 — Termin: Click a service pill → calendar appears. Click a date → slots appear. Click a slot → jumps to Step 2.
2. Step 2 — Angaben: Verify recap card shows correct service + time. Fill form → click Weiter → jumps to Step 3.
3. Step 3 — Bestätigen: Check recap is complete. Button disabled before consent. Check consent → button enables. Click "Termin buchen ✓".
4. Success screen appears.

- [ ] **Step 4: Test the back navigation**

1. From Step 3: click ← Zurück → returns to Step 2 with form pre-filled.
2. From Step 2: click ← Zurück → returns to Step 1 with service still selected.

- [ ] **Step 5: Verify stepper transitions**

Stepper shows correct node states (active/done/future) and the indigo line extends as you advance.

- [ ] **Step 6: Run final test suite**

```bash
cd backend
composer test
npm run test:widget
```

Expected: all green — PHP backend unaffected, widget tests all pass.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "chore(widget): post-visual-check cleanup"
```

---

**Plan complet — Widget Flow Redesign.** 7 tâches, TDD sur toutes les pièces unitaires testables.
