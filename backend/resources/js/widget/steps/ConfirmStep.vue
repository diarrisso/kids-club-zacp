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
    <h2 class="text-[1.35rem] font-bold tracking-tight text-slate-900">Bestätigen</h2>
    <p class="mt-1 text-sm text-slate-400">Alles korrekt? Dann bestätige deinen Termin.</p>

    <!-- Summary card — accent gradient -->
    <div class="mt-4 rounded-2xl bg-gradient-to-br from-[#98ACBA]/15 to-[#98ACBA]/5 ring-1 ring-[#98ACBA]/20 p-4">
      <dl class="divide-y divide-slate-100/80">
        <!-- Leistung -->
        <div class="flex items-center justify-between py-2.5 first:pt-0 last:pb-0">
          <dt class="text-xs font-medium text-slate-400">Leistung</dt>
          <dd class="text-sm font-semibold text-slate-700 text-right max-w-[60%]">{{ serviceLabel }}</dd>
        </div>
        <!-- Datum & Zeit -->
        <div class="flex items-center justify-between py-2.5">
          <dt class="text-xs font-medium text-slate-400">Datum</dt>
          <dd class="text-sm font-semibold text-slate-700 text-right max-w-[60%]">{{ formattedDate }}</dd>
        </div>
        <!-- Behandler -->
        <div class="flex items-center justify-between py-2.5">
          <dt class="text-xs font-medium text-slate-400">Behandler</dt>
          <dd class="text-sm font-semibold text-slate-700 text-right max-w-[60%]">{{ formattedPractitioner }}</dd>
        </div>
        <!-- Kind -->
        <div class="flex items-center justify-between py-2.5">
          <dt class="text-xs font-medium text-slate-400">Kind</dt>
          <dd class="text-sm font-semibold text-slate-700 text-right max-w-[60%]">{{ patientName }}</dd>
        </div>
        <!-- E-Mail -->
        <div class="flex items-center justify-between py-2.5 last:pb-0">
          <dt class="text-xs font-medium text-slate-400">E-Mail</dt>
          <dd class="text-sm font-semibold text-slate-700 break-all text-right max-w-[65%]">{{ formData.parent_email }}</dd>
        </div>
      </dl>
    </div>

    <!-- Amber info banner -->
    <div class="mt-3.5 flex items-start gap-2.5 rounded-xl bg-amber-50 px-3.5 py-3 ring-1 ring-amber-200">
      <span class="mt-0.5 text-base shrink-0" aria-hidden="true">📧</span>
      <p class="text-xs leading-relaxed text-amber-800">
        Bestätigungsmail wird an <strong>{{ formData.parent_email }}</strong> gesendet.
      </p>
    </div>

    <!-- GDPR consent checkbox -->
    <label
      class="mt-3.5 flex cursor-pointer items-start gap-3 rounded-2xl bg-slate-50/80 p-3.5 ring-1 ring-slate-100 transition hover:bg-slate-50"
    >
      <input
        data-consent
        v-model="consent"
        type="checkbox"
        class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 focus:ring-[#98ACBA]/50"
        :style="consent ? { accentColor: '#5A7A91' } : {}"
      >
      <span class="text-xs leading-relaxed text-slate-600">
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
        class="flex-1 rounded-2xl border border-slate-200 bg-white py-3 text-sm font-semibold text-slate-600 transition-all duration-200 hover:-translate-y-0.5 hover:bg-slate-50 hover:border-slate-300 active:translate-y-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-300 focus-visible:ring-offset-2"
      >
        ← Zurück
      </button>

      <!-- Submit button -->
      <button
        data-submit
        type="button"
        :disabled="!canSubmit"
        @click="onSubmit"
        class="flex-[2] inline-flex items-center justify-center gap-2 rounded-2xl py-3 text-sm font-bold text-white shadow-[0_14px_26px_-12px_rgba(90,122,145,0.65)] transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_18px_32px_-12px_rgba(90,122,145,0.80)] active:translate-y-0 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5A7A91]/60 focus-visible:ring-offset-2"
        style="background: linear-gradient(135deg, #6B8FA3 0%, #4A6B7E 100%);"
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
