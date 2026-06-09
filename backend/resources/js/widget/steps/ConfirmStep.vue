<script setup lang="ts">
import { ref, computed } from 'vue'
import type { Service, Slot } from '../types'

const props = defineProps<{
  selection: { service?: Service; slot?: Slot }
  formData: Record<string, unknown>
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

const patientName = computed(() =>
  `${props.formData.patient_first_name ?? ''} ${props.formData.patient_last_name ?? ''}`.trim() || '—'
)

const onSubmit = () => {
  if (canSubmit.value) emit('submit')
}
</script>

<template>
  <div>
    <!-- Heading -->
    <h2 class="text-xl font-bold tracking-tight text-slate-800">Bestätigen</h2>
    <p class="mt-1 text-sm text-slate-500">Alles korrekt? Dann bestätige deinen Termin.</p>

    <!-- Summary card -->
    <div class="mt-4 rounded-2xl border border-slate-100 bg-white p-4 shadow-[0_12px_28px_-12px_rgba(86,103,120,0.35)]">
      <dl class="space-y-3">
        <!-- Leistung -->
        <div class="flex items-start gap-3">
          <dt class="w-24 shrink-0 text-xs font-medium text-slate-400 pt-0.5">Leistung</dt>
          <dd class="flex-1 text-sm font-semibold text-slate-800">{{ serviceLabel }}</dd>
        </div>
        <!-- Datum & Zeit -->
        <div class="flex items-start gap-3">
          <dt class="w-24 shrink-0 text-xs font-medium text-slate-400 pt-0.5">Datum</dt>
          <dd class="flex-1 text-sm font-semibold text-slate-800">{{ formattedDate }}</dd>
        </div>
        <!-- Behandler -->
        <div class="flex items-start gap-3">
          <dt class="w-24 shrink-0 text-xs font-medium text-slate-400 pt-0.5">Behandler</dt>
          <dd class="flex-1 text-sm font-semibold text-slate-800">{{ formattedPractitioner }}</dd>
        </div>
        <!-- Kind -->
        <div class="flex items-start gap-3">
          <dt class="w-24 shrink-0 text-xs font-medium text-slate-400 pt-0.5">Kind</dt>
          <dd class="flex-1 text-sm font-semibold text-slate-800">{{ patientName }}</dd>
        </div>
        <!-- E-Mail -->
        <div class="flex items-start gap-3">
          <dt class="w-24 shrink-0 text-xs font-medium text-slate-400 pt-0.5">E-Mail</dt>
          <dd class="flex-1 break-all text-sm font-semibold text-slate-800">{{ formData.parent_email }}</dd>
        </div>
      </dl>
    </div>

    <!-- Amber info banner -->
    <div class="mt-3.5 flex items-start gap-2.5 rounded-xl bg-amber-50 p-3.5 ring-1 ring-amber-200">
      <span class="mt-0.5 text-base" aria-hidden="true">📧</span>
      <p class="text-xs leading-relaxed text-amber-800">
        Bestätigungsmail wird an <strong>{{ formData.parent_email }}</strong> gesendet.
      </p>
    </div>

    <!-- GDPR consent checkbox -->
    <label
      class="mt-3.5 flex cursor-pointer items-start gap-2.5 rounded-2xl bg-slate-50 p-3.5 ring-1 ring-slate-100 transition hover:bg-slate-100/70"
    >
      <input
        data-consent
        v-model="consent"
        type="checkbox"
        class="mt-0.5 h-4 w-4 rounded-md border-slate-300 text-indigo-500 focus:ring-indigo-500/50"
      >
      <span class="text-xs leading-relaxed text-slate-600">
        Ich willige in die Verarbeitung der angegebenen Daten zur Terminbuchung ein.
      </span>
    </label>

    <!-- Action buttons -->
    <div class="mt-5 flex items-center gap-3">
      <!-- Back button -->
      <button
        data-back
        type="button"
        @click="emit('back')"
        class="flex-1 rounded-xl border border-slate-200 bg-white py-3 text-sm font-semibold text-slate-600 transition-all duration-200 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-sm active:translate-y-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 focus-visible:ring-offset-2"
      >
        ← Zurück
      </button>

      <!-- Submit button -->
      <button
        data-submit
        type="button"
        :disabled="!canSubmit"
        @click="onSubmit"
        class="flex-[2] inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 py-3 text-sm font-bold text-white shadow-[0_10px_24px_-8px_rgba(99,102,241,0.7)] transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_14px_30px_-8px_rgba(99,102,241,0.85)] active:translate-y-0 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0 disabled:hover:shadow-[0_10px_24px_-8px_rgba(99,102,241,0.7)] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 focus-visible:ring-offset-2"
      >
        <!-- Inline spinner when loading -->
        <span
          v-if="loading"
          class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"
          aria-hidden="true"
        ></span>
        <span aria-hidden="true">{{ loading ? '' : '🎉' }}</span>
        Termin buchen
      </button>
    </div>
  </div>
</template>
