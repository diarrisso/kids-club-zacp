<script setup lang="ts">
import { ref, computed } from 'vue'
import type { Service, Slot } from '../types'

const props = defineProps<{
  selection: { service?: Service; slot?: Slot }
  formData: Record<string, unknown>
  kindData?: Record<string, unknown>
  loading?: boolean
}>()

const emit = defineEmits<{ submit: []; back: [] }>()

const consent = ref(false)

const canSubmit = computed(() => consent.value && !props.loading)

// Timezone-safe helpers — work directly on the ISO string so the displayed
// time/date matches the clinic's Europe/Berlin offset, not the viewer's local TZ.
const clinicTime = (iso: string) => iso.slice(11, 16) // "HH:MM"
const clinicDate = (iso: string) =>
  new Date(iso.slice(0, 10) + 'T12:00:00').toLocaleDateString('de-DE', {
    weekday: 'short',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })

const formattedDate = computed(() => {
  const slot = props.selection.slot
  if (!slot) return '—'
  return `${clinicDate(slot.starts_at)} · ${clinicTime(slot.starts_at)}`
})

const formattedPractitioner = computed(() => {
  const p = props.selection.slot?.practitioner
  if (!p) return '—'
  return [p.title, p.first_name, p.last_name].filter(Boolean).join(' ')
})

const serviceLabel = computed(() => {
  const s = props.selection.service
  if (!s) return '—'
  return `${s.name} · ${s.duration_minutes} Min.`
})

const patientName = computed(() => {
  const src = props.kindData ?? props.formData
  return `${src.patient_first_name ?? ''} ${src.patient_last_name ?? ''}`.trim() || '—'
})

const onSubmit = () => {
  if (canSubmit.value) emit('submit')
}
</script>

<template>
  <div>
    <!-- Heading -->
    <h2 class="text-[1.35rem] font-bold tracking-tight text-widget-text">Bestätigen</h2>
    <p class="mt-1 text-sm text-slate-400">Alles korrekt? Dann bestätige deinen Termin.</p>

    <!-- Summary card -->
    <div class="mt-4 rounded-2xl ring-1 ring-accent/15 overflow-hidden"
         style="background: linear-gradient(145deg, rgba(238,243,246,0.80) 0%, rgba(255,255,255,0.95) 100%);">
      <dl>
        <!-- Leistung -->
        <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100/80">
          <dt class="flex items-center gap-2.5 text-xs font-medium text-slate-400 shrink-0">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg shrink-0"
                  style="background-color: rgb(var(--masinga-primary-rgb) / 0.12);" aria-hidden="true">
              <svg class="h-3.5 w-3.5 text-accent" viewBox="0 0 16 16" fill="currentColor">
                <path d="M2.5 2A.5.5 0 002 2.5v11a.5.5 0 00.5.5h11a.5.5 0 00.5-.5v-11a.5.5 0 00-.5-.5h-11zM8 5a1 1 0 110 2 1 1 0 010-2zm-2.5 4h5a.5.5 0 010 1h-5a.5.5 0 010-1z"/>
              </svg>
            </span>
            Leistung
          </dt>
          <dd class="text-sm font-semibold text-widget-text/70 text-right max-w-[58%] leading-snug">{{ serviceLabel }}</dd>
        </div>
        <!-- Datum & Zeit -->
        <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100/80">
          <dt class="flex items-center gap-2.5 text-xs font-medium text-slate-400 shrink-0">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg shrink-0"
                  style="background-color: rgb(var(--masinga-primary-rgb) / 0.12);" aria-hidden="true">
              <svg class="h-3.5 w-3.5 text-accent" viewBox="0 0 16 16" fill="currentColor">
                <path fill-rule="evenodd" d="M4 1.75a.75.75 0 01.75.75V4h6.5V2.5a.75.75 0 011.5 0V4H14A1.5 1.5 0 0115.5 5.5v8A1.5 1.5 0 0114 15H2A1.5 1.5 0 01.5 13.5v-8A1.5 1.5 0 012 4h1.25V2.5A.75.75 0 014 1.75zm-2 5.5v6.25c0 .138.112.25.25.25h11.5a.25.25 0 00.25-.25V7.25H2z" clip-rule="evenodd"/>
              </svg>
            </span>
            Datum
          </dt>
          <dd class="text-sm font-semibold text-widget-text/70 text-right max-w-[60%] leading-snug">{{ formattedDate }}</dd>
        </div>
        <!-- Behandler -->
        <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100/80">
          <dt class="flex items-center gap-2.5 text-xs font-medium text-slate-400 shrink-0">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg shrink-0"
                  style="background-color: rgb(var(--masinga-primary-rgb) / 0.12);" aria-hidden="true">
              <svg class="h-3.5 w-3.5 text-accent" viewBox="0 0 16 16" fill="currentColor">
                <path d="M8 8a3 3 0 100-6 3 3 0 000 6zM3.5 14.5a5.5 5.5 0 0110.998-.116.75.75 0 01-1.499.116A4 4 0 104 14.5h-.5zm0 0H3a.5.5 0 000 1h.5v-1z"/>
              </svg>
            </span>
            Behandler
          </dt>
          <dd class="text-sm font-semibold text-widget-text/70 text-right max-w-[60%] leading-snug">{{ formattedPractitioner }}</dd>
        </div>
        <!-- Kind -->
        <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100/80">
          <dt class="flex items-center gap-2.5 text-xs font-medium text-slate-400 shrink-0">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg shrink-0"
                  style="background-color: rgb(var(--masinga-primary-rgb) / 0.12);" aria-hidden="true">
              <svg class="h-3.5 w-3.5 text-accent" viewBox="0 0 16 16" fill="currentColor">
                <path d="M8 8a3 3 0 100-6 3 3 0 000 6zm-5 6a5 5 0 0110 0H3z"/>
              </svg>
            </span>
            Kind
          </dt>
          <dd class="text-sm font-semibold text-widget-text/70 text-right max-w-[60%] leading-snug">{{ patientName }}</dd>
        </div>
        <!-- E-Mail -->
        <div class="flex items-center justify-between px-4 py-3">
          <dt class="flex items-center gap-2.5 text-xs font-medium text-slate-400 shrink-0">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg shrink-0"
                  style="background-color: rgb(var(--masinga-primary-rgb) / 0.12);" aria-hidden="true">
              <svg class="h-3.5 w-3.5 text-accent" viewBox="0 0 16 16" fill="currentColor">
                <path d="M.05 3.555A2 2 0 012 2h12a2 2 0 011.95 1.555L8 8.414.05 3.555zM0 4.697v7.104l5.803-3.558L0 4.697zM6.761 8.83l-6.57 4.026A2 2 0 002 14h12a2 2 0 001.808-1.144l-6.57-4.026L8 9.586l-1.239-.757zm3.436-.586L16 11.801V4.697l-5.803 3.547z"/>
              </svg>
            </span>
            E-Mail
          </dt>
          <dd class="text-sm font-semibold text-widget-text/70 break-all text-right max-w-[62%] leading-snug">{{ formData.parent_email }}</dd>
        </div>
      </dl>
    </div>

    <!-- E-Mail info banner -->
    <div class="mt-3 flex items-center gap-2.5 rounded-xl px-3.5 py-2.5 ring-1 ring-accent/20"
         style="background-color: rgb(var(--masinga-primary-rgb) / 0.06);">
      <svg class="h-4 w-4 shrink-0 text-accent" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd"/>
      </svg>
      <p class="text-xs leading-relaxed text-accent">
        Bestätigungsmail wird an <strong>{{ formData.parent_email }}</strong> gesendet.
      </p>
    </div>

    <!-- GDPR consent -->
    <label class="mt-3 flex cursor-pointer items-start gap-3 rounded-2xl p-3.5 ring-1 ring-slate-100 transition-all duration-150 hover:ring-accent/30"
           :style="consent ? 'background: linear-gradient(135deg, rgb(var(--masinga-primary-rgb) / 0.06) 0%, rgba(255,255,255,1) 100%); box-shadow: 0 0 0 2px rgb(var(--masinga-primary-rgb) / 0.18);' : 'background-color: rgba(248,250,251,0.8);'">
      <input
        data-consent
        v-model="consent"
        type="checkbox"
        class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 focus:ring-accent/15"
        :style="consent ? { accentColor: 'var(--masinga-accent)' } : {}"
      >
      <span class="text-xs leading-relaxed text-widget-text/70">
        Ich willige in die Verarbeitung der angegebenen Daten zur Terminbuchung ein.
      </span>
    </label>

    <!-- Action buttons -->
    <div class="mt-6 flex items-center gap-3">
      <!-- Back button -->
      <button
        data-back
        type="button"
        @click="emit('back')"
        class="flex-1 rounded-2xl border border-slate-200 bg-widget-bg py-3 text-sm font-semibold text-widget-text/70 transition-all duration-200 hover:-translate-y-0.5 hover:bg-slate-50 hover:border-slate-300 active:translate-y-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-300 focus-visible:ring-offset-2"
      >
        ← Zurück
      </button>

      <!-- Submit button -->
      <button
        data-submit
        type="button"
        :disabled="!canSubmit"
        @click="onSubmit"
        class="flex-[2] inline-flex items-center justify-center gap-2.5 rounded-2xl py-3 text-sm font-bold text-white shadow-[0_14px_26px_-12px_rgb(var(--masinga-primary-rgb)_/_0.65)] transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_18px_32px_-12px_rgb(var(--masinga-primary-rgb)_/_0.80)] active:translate-y-0 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:translate-y-0 disabled:shadow-none focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 focus-visible:ring-offset-2"
        style="background: var(--masinga-gradient);"
      >
        <!-- Spinner when loading -->
        <span
          v-if="loading"
          class="h-5 w-5 animate-spin rounded-full border-[3px] border-white/30 border-t-white shrink-0"
          aria-hidden="true"
        ></span>
        <!-- Calendar icon when idle -->
        <svg v-else class="h-4 w-4 shrink-0" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
          <path fill-rule="evenodd" d="M4 1.75a.75.75 0 01.75.75V4h6.5V2.5a.75.75 0 011.5 0V4H14A1.5 1.5 0 0115.5 5.5v8A1.5 1.5 0 0114 15H2A1.5 1.5 0 01.5 13.5v-8A1.5 1.5 0 012 4h1.25V2.5A.75.75 0 014 1.75zm-2 5.5v6.25c0 .138.112.25.25.25h11.5a.25.25 0 00.25-.25V7.25H2z" clip-rule="evenodd"/>
        </svg>
        Termin buchen
      </button>
    </div>
  </div>
</template>
