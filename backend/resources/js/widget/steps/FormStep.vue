<script setup lang="ts">
import { reactive, computed, watch } from 'vue'
import type { Service, Slot } from '../types'

const props = withDefaults(
    defineProps<{
        selection: { service?: Service; slot?: Slot }
        serverErrors: Record<string, string[]>
        initialValues?: Record<string, unknown> | null
    }>(),
    { initialValues: null },
)
const emit = defineEmits<{ advance: [payload: Record<string, unknown>]; back: [] }>()

const form = reactive({
    patient_first_name: '', patient_last_name: '', patient_birthdate: '',
    parent_first_name: '', parent_last_name: '', parent_email: '', parent_phone: '',
    notes_parent: '', website: '', // website = honeypot
    room: null as string | null,
})

// Pre-fill so going back from Confirm restores the parent's entries.
if (props.initialValues) Object.assign(form, props.initialValues)
watch(() => props.initialValues, v => { if (v) Object.assign(form, v) })

const rooms = [
    { value: 'green', color: '#BDCCC2', label: 'Grünes Zimmer' },
    { value: 'yellow', color: '#F7E29D', label: 'Gelbes Zimmer' },
    { value: 'peach', color: '#FCE8E1', label: 'Oranges Zimmer' },
    { value: 'blue', color: '#98ACBA', label: 'Blaues Zimmer' },
    { value: 'purple', color: '#CCC8CE', label: 'Lila Zimmer' },
]

const valid = computed(() =>
    !!form.patient_first_name && !!form.patient_last_name && !!form.patient_birthdate &&
    !!form.parent_first_name && !!form.parent_last_name && /\S+@\S+\.\S+/.test(form.parent_email),
)

const advance = () => {
    if (!valid.value) return
    if (form.website) return // honeypot tripped — silently drop
    emit('advance', { ...form })
}

// Recap helpers — tz-safe formatting on the clinic ISO offset.
const recapTime = computed(() => props.selection.slot?.starts_at?.slice(11, 16) ?? '')
const recapDate = computed(() => {
    const iso = props.selection.slot?.starts_at
    if (!iso) return ''
    return new Date(iso.slice(0, 10) + 'T12:00:00').toLocaleDateString('de-DE', {
        weekday: 'short', day: 'numeric', month: 'long', year: 'numeric',
    })
})

// Shared field styling — elevated: white base, clear label above, refined focus ring.
const field =
    'w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-800 ' +
    'placeholder:text-slate-300 shadow-sm transition ' +
    'focus:border-[#5A7A91] focus:ring-4 focus:ring-[#98ACBA]/15 focus:outline-none'
</script>

<template>
    <form @submit.prevent="advance">
        <h2 class="text-[1.35rem] font-bold tracking-tight text-slate-900">Ihre Angaben</h2>
        <p class="mt-1 text-sm text-slate-400">Fast geschafft — nur noch ein paar Details.</p>

        <!-- Recap card -->
        <div v-if="selection.slot" class="mt-4 rounded-2xl bg-gradient-to-br from-[#98ACBA]/15 to-[#98ACBA]/5 ring-1 ring-[#98ACBA]/20 px-4 py-3.5">
            <p class="font-semibold text-slate-800 text-sm">
                {{ selection.service?.name }}<template v-if="selection.service"> · {{ selection.service.duration_minutes }} Min.</template>
            </p>
            <p class="mt-0.5 text-sm text-slate-500">{{ recapDate }} · {{ recapTime }}</p>
        </div>

        <!-- Kind section -->
        <fieldset class="mt-5 rounded-2xl bg-slate-50/60 p-4 ring-1 ring-slate-100">
            <legend class="flex items-center gap-1.5 px-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">
                <span aria-hidden="true">🧒</span> Kind
            </legend>
            <div class="mt-3 space-y-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1" aria-hidden="true">Vorname</label>
                    <input name="patient_first_name" aria-label="Vorname des Kindes" v-model="form.patient_first_name" placeholder="z.B. Lena"
                           :class="field">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1" aria-hidden="true">Nachname</label>
                    <input name="patient_last_name" aria-label="Nachname des Kindes" v-model="form.patient_last_name" placeholder="Nachname"
                           :class="field">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1" aria-hidden="true">Geburtsdatum</label>
                    <input name="patient_birthdate" aria-label="Geburtsdatum des Kindes" v-model="form.patient_birthdate" type="date"
                           :class="field">
                </div>
            </div>
        </fieldset>

        <!-- Eltern section -->
        <fieldset class="mt-3.5 rounded-2xl bg-slate-50/60 p-4 ring-1 ring-slate-100">
            <legend class="flex items-center gap-1.5 px-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">
                <span aria-hidden="true">👪</span> Elternteil
            </legend>
            <div class="mt-3 space-y-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1" aria-hidden="true">Vorname</label>
                    <input name="parent_first_name" aria-label="Vorname des Elternteils" v-model="form.parent_first_name" placeholder="Vorname"
                           :class="field">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1" aria-hidden="true">Nachname</label>
                    <input name="parent_last_name" aria-label="Nachname des Elternteils" v-model="form.parent_last_name" placeholder="Nachname"
                           :class="field">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1" aria-hidden="true">E-Mail-Adresse</label>
                    <input name="parent_email" aria-label="E-Mail" v-model="form.parent_email" type="email" placeholder="name@beispiel.de"
                           :class="field">
                    <p v-if="serverErrors.parent_email" class="mt-1.5 flex items-center gap-1 text-xs font-medium text-rose-600">
                        <span aria-hidden="true">•</span>{{ serverErrors.parent_email[0] }}
                    </p>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1" aria-hidden="true">Telefon <span class="font-normal text-slate-400">(optional)</span></label>
                    <input name="parent_phone" aria-label="Telefon" v-model="form.parent_phone" placeholder="+49 …"
                           :class="field">
                </div>
            </div>
        </fieldset>

        <!-- Notes -->
        <div class="mt-3.5">
            <label class="block text-xs font-medium text-slate-500 mb-1" aria-hidden="true">Notiz <span class="font-normal text-slate-400">(optional)</span></label>
            <textarea name="notes_parent" aria-label="Notiz" v-model="form.notes_parent" placeholder="Weitere Hinweise …"
                      :class="field" rows="2"></textarea>
        </div>

        <!-- Room picker -->
        <fieldset class="mt-3.5">
            <legend class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Zimmer <span class="normal-case font-normal tracking-normal">(optional)</span></legend>
            <div class="mt-2.5 flex flex-wrap gap-3" role="group" aria-label="Zimmerfarbe">
                <button v-for="r in rooms" :key="r.value" type="button"
                        :title="r.label" :aria-label="r.label" :aria-pressed="form.room === r.value"
                        class="group relative grid h-11 w-11 place-items-center rounded-2xl shadow-sm transition-all duration-200 hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5A7A91]/60 focus-visible:ring-offset-2"
                        :class="form.room === r.value
                            ? 'scale-110 ring-2 ring-[#5A7A91] ring-offset-2 shadow-md'
                            : 'ring-1 ring-inset ring-black/6 hover:ring-black/12 hover:shadow-md'"
                        :style="{ backgroundColor: r.color }"
                        @click="form.room = form.room === r.value ? null : r.value">
                    <span
                        class="text-sm font-bold text-slate-700 transition-all duration-200"
                        :class="form.room === r.value ? 'scale-100 opacity-100' : 'scale-50 opacity-0'"
                        aria-hidden="true"
                    >✓</span>
                </button>
            </div>
        </fieldset>

        <!-- Honeypot: hidden from humans, bots fill it -->
        <input name="website" v-model="form.website" tabindex="-1" autocomplete="off"
               style="position:absolute;left:-9999px" aria-hidden="true">

        <div class="mt-6 flex items-center gap-3">
            <button data-form-back type="button" @click="emit('back')"
                    class="flex-1 rounded-2xl border border-slate-200 bg-white py-3 text-sm font-semibold text-slate-600 transition-all duration-200 hover:-translate-y-0.5 hover:bg-slate-50 hover:border-slate-300 active:translate-y-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-300 focus-visible:ring-offset-2">
                ← Zurück
            </button>

            <button data-advance type="button" :disabled="!valid" @click="advance"
                    class="flex-[2] inline-flex items-center justify-center gap-2 rounded-2xl py-3 text-sm font-bold text-white shadow-[0_14px_26px_-12px_rgba(90,122,145,0.65)] transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_18px_32px_-12px_rgba(90,122,145,0.80)] active:translate-y-0 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5A7A91]/60 focus-visible:ring-offset-2"
                    style="background: linear-gradient(135deg, #6B8FA3 0%, #4A6B7E 100%);">
                Weiter
            </button>
        </div>
    </form>
</template>
