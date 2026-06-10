<script setup lang="ts">
import { reactive, computed, watch } from 'vue'
import type { Service, Slot, PatientData } from '../types'

const props = withDefaults(
    defineProps<{
        initialValues?: PatientData | null
        selection?: { service?: Service; slot?: Slot }
        serverErrors?: Record<string, string[]>
    }>(),
    { initialValues: null, selection: undefined, serverErrors: () => ({}) },
)
const emit = defineEmits<{ advance: [payload: PatientData]; back: [] }>()

const form = reactive({
    patient_first_name: '',
    patient_last_name: '',
    patient_birthdate: '',
})

// Pre-fill when navigating back from a later step.
if (props.initialValues) Object.assign(form, props.initialValues)
watch(() => props.initialValues, v => { if (v) Object.assign(form, v) })

// A birthdate can never be in the future — cap the date picker at yesterday so
// today/future dates can't be selected (mirrors the server's `before:today` rule).
const maxBirthdate = (() => {
    const d = new Date()
    d.setDate(d.getDate() - 1)
    return d.toISOString().slice(0, 10)
})()

// Client-side echo of the server rule, so the "Weiter" button stays disabled
// (and an inline hint shows) instead of letting the user hit a silent 422.
const birthdateInFuture = computed(
    () => !!form.patient_birthdate && form.patient_birthdate > maxBirthdate,
)

const valid = computed(() =>
    !!form.patient_first_name && !!form.patient_last_name &&
    !!form.patient_birthdate && !birthdateInFuture.value,
)

const advance = () => {
    if (!valid.value) return
    emit('advance', { ...form })
}

// Recap helpers — tz-safe: slice the offset-aware ISO string directly.
const recapTime = computed(() => props.selection?.slot?.starts_at?.slice(11, 16) ?? '')
const recapDate = computed(() => {
    const iso = props.selection?.slot?.starts_at
    if (!iso) return ''
    return new Date(iso.slice(0, 10) + 'T12:00:00').toLocaleDateString('de-DE', {
        weekday: 'short', day: 'numeric', month: 'long', year: 'numeric',
    })
})

// Shared field styling — matches FormStep.
const field =
    'w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-[#26257F] ' +
    'placeholder:text-slate-300 shadow-sm transition ' +
    'focus:border-[#EC0A8C] focus:ring-4 focus:ring-[#FBB9C4]/15 focus:outline-none'
</script>

<template>
    <div>
        <h2 class="text-[1.35rem] font-bold tracking-tight text-[#211F66]">Kind</h2>
        <p class="mt-1 text-sm text-slate-400">Angaben zum Kind, das den Termin wahrnimmt.</p>

        <!-- Recap card -->
        <div v-if="selection?.slot"
             class="mt-4 flex items-center gap-3 rounded-2xl px-4 py-3.5 ring-1 ring-[#EC0A8C]/20"
             style="background: linear-gradient(135deg, rgba(152,172,186,0.12) 0%, rgba(90,122,145,0.06) 100%);">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
                 style="background: linear-gradient(135deg, #6B8FA3 0%, #C40C78 100%);" aria-hidden="true">
                <svg class="h-4 w-4 text-white" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.75 2a.75.75 0 01.75.75V4h7V2.75a.75.75 0 011.5 0V4h.25A2.75 2.75 0 0118 6.75v8.5A2.75 2.75 0 0115.25 18H4.75A2.75 2.75 0 012 15.25v-8.5A2.75 2.75 0 014.75 4H5V2.75A.75.75 0 015.75 2zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div>
                <p class="font-semibold text-[#26257F] text-sm leading-tight">
                    {{ selection.service?.name }}<template v-if="selection.service"> · {{ selection.service.duration_minutes }} Min.</template>
                </p>
                <p class="mt-0.5 text-xs text-[#5A5996]">{{ recapDate }} · {{ recapTime }}</p>
            </div>
        </div>

        <!-- Kind section -->
        <fieldset class="mt-5 rounded-2xl bg-[#FFF4F7] p-4 ring-1 ring-slate-100/80">
            <legend class="flex items-center gap-2 px-1">
                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full shrink-0"
                      style="background: linear-gradient(135deg, #FBB9C4 0%, #7A95A8 100%);" aria-hidden="true">
                    <svg class="h-3 w-3 text-white" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M8 8a3 3 0 100-6 3 3 0 000 6zM3 14a5 5 0 0110 0H3z"/>
                    </svg>
                </span>
                <span class="text-[11px] font-bold uppercase tracking-[0.15em] text-[#5A5996]">Kind</span>
            </legend>
            <div class="mt-3 space-y-3">
                <!-- Vorname + Nachname nebeneinander -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-400 mb-1.5 uppercase tracking-[0.08em]" aria-hidden="true">Vorname</label>
                        <input name="patient_first_name" aria-label="Vorname des Kindes" v-model="form.patient_first_name" placeholder="z.B. Lena"
                               :class="field">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-400 mb-1.5 uppercase tracking-[0.08em]" aria-hidden="true">Nachname</label>
                        <input name="patient_last_name" aria-label="Nachname des Kindes" v-model="form.patient_last_name" placeholder="Nachname"
                               :class="field">
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-400 mb-1.5 uppercase tracking-[0.08em]" aria-hidden="true">Geburtsdatum</label>
                    <input name="patient_birthdate" aria-label="Geburtsdatum des Kindes" v-model="form.patient_birthdate" type="date"
                           :max="maxBirthdate"
                           :aria-invalid="birthdateInFuture || !!serverErrors.patient_birthdate"
                           :class="field">
                    <p v-if="birthdateInFuture || serverErrors.patient_birthdate"
                       class="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-rose-600">
                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M8 1.5a6.5 6.5 0 100 13 6.5 6.5 0 000-13zM8 4a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 018 4zm0 7a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                        </svg>
                        {{ serverErrors.patient_birthdate?.[0] ?? 'Bitte ein Geburtsdatum in der Vergangenheit wählen.' }}
                    </p>
                </div>
            </div>
        </fieldset>

        <div class="mt-6 flex items-center gap-3">
            <button data-kind-back type="button" @click="emit('back')"
                    class="flex-1 rounded-2xl border border-slate-200 bg-white py-3 text-sm font-semibold text-[#5A5996] transition-all duration-200 hover:-translate-y-0.5 hover:bg-slate-50 hover:border-slate-300 active:translate-y-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-300 focus-visible:ring-offset-2">
                ← Zurück
            </button>

            <button data-kind-advance type="button" :disabled="!valid" @click="advance"
                    class="flex-[2] inline-flex items-center justify-center gap-2 rounded-2xl py-3 text-sm font-bold text-white shadow-[0_14px_26px_-12px_rgba(74,107,126,0.65)] transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_18px_32px_-12px_rgba(74,107,126,0.80)] active:translate-y-0 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:translate-y-0 disabled:shadow-none focus:outline-none focus-visible:ring-2 focus-visible:ring-[#EC0A8C]/60 focus-visible:ring-offset-2"
                    style="background: linear-gradient(135deg, #6B8FA3 0%, #C40C78 100%);">
                Weiter
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>
    </div>
</template>
