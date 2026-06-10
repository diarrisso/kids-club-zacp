<script setup lang="ts">
import { reactive, computed, watch } from 'vue'
import type { Service, Slot, ParentData } from '../types'

const props = withDefaults(
    defineProps<{
        selection: { service?: Service; slot?: Slot }
        serverErrors: Record<string, string[]>
        initialValues?: ParentData | null
    }>(),
    { initialValues: null },
)
const emit = defineEmits<{ advance: [payload: ParentData]; back: [] }>()

const form = reactive({
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
    { value: 'blue', color: '#FBB9C4', label: 'Blaues Zimmer' },
    { value: 'purple', color: '#CCC8CE', label: 'Lila Zimmer' },
]

const valid = computed(() =>
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
    'w-full rounded-xl border border-slate-200 bg-widget-bg px-4 py-2.5 text-sm text-widget-text ' +
    'placeholder:text-slate-300 shadow-sm transition ' +
    'focus:border-accent focus:ring-4 focus:ring-accent/15 focus:outline-none'
</script>

<template>
    <form @submit.prevent="advance">
        <h2 class="text-[1.35rem] font-bold tracking-tight text-widget-text">Elternteil</h2>
        <p class="mt-1 text-sm text-slate-400">Ihre Kontaktdaten für die Terminbestätigung.</p>

        <!-- Recap card -->
        <div v-if="selection.slot"
             class="mt-4 flex items-center gap-3 rounded-2xl px-4 py-3.5 ring-1 ring-accent/20"
             style="background: linear-gradient(135deg, rgb(var(--masinga-primary-rgb) / 0.12) 0%, rgb(var(--masinga-primary-rgb) / 0.06) 100%);">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
                 style="background: var(--masinga-gradient);" aria-hidden="true">
                <svg class="h-4 w-4 text-white" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.75 2a.75.75 0 01.75.75V4h7V2.75a.75.75 0 011.5 0V4h.25A2.75 2.75 0 0118 6.75v8.5A2.75 2.75 0 0115.25 18H4.75A2.75 2.75 0 012 15.25v-8.5A2.75 2.75 0 014.75 4H5V2.75A.75.75 0 015.75 2zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div>
                <p class="font-semibold text-widget-text text-sm leading-tight">
                    {{ selection.service?.name }}<template v-if="selection.service"> · {{ selection.service.duration_minutes }} Min.</template>
                </p>
                <p class="mt-0.5 text-xs text-widget-text/70">{{ recapDate }} · {{ recapTime }}</p>
            </div>
        </div>

        <!-- Eltern section -->
        <fieldset class="mt-5 rounded-2xl bg-tint-soft p-4 ring-1 ring-slate-100/80">
            <legend class="flex items-center gap-2 px-1">
                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full shrink-0"
                      style="background: var(--masinga-gradient);" aria-hidden="true">
                    <svg class="h-3 w-3 text-white" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M8 8a3 3 0 100-6 3 3 0 000 6zM3 14a5 5 0 0110 0H3z"/>
                    </svg>
                </span>
                <span class="text-[11px] font-bold uppercase tracking-[0.15em] text-widget-text/70">Elternteil</span>
            </legend>
            <div class="mt-3 space-y-3">
                <!-- Vorname + Nachname nebeneinander -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-400 mb-1.5 uppercase tracking-[0.08em]" aria-hidden="true">Vorname</label>
                        <input name="parent_first_name" aria-label="Vorname des Elternteils" v-model="form.parent_first_name" placeholder="Vorname"
                               :class="field">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-400 mb-1.5 uppercase tracking-[0.08em]" aria-hidden="true">Nachname</label>
                        <input name="parent_last_name" aria-label="Nachname des Elternteils" v-model="form.parent_last_name" placeholder="Nachname"
                               :class="field">
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-400 mb-1.5 uppercase tracking-[0.08em]" aria-hidden="true">E-Mail-Adresse</label>
                    <input name="parent_email" aria-label="E-Mail" v-model="form.parent_email" type="email" placeholder="name@beispiel.de"
                           :class="field">
                    <p v-if="serverErrors.parent_email" class="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-rose-600">
                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8 1a7 7 0 100 14A7 7 0 008 1zm0 3a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 018 4zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                        {{ serverErrors.parent_email[0] }}
                    </p>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-400 mb-1.5 uppercase tracking-[0.08em]" aria-hidden="true">
                        Telefon <span class="normal-case font-normal text-slate-300">(optional)</span>
                    </label>
                    <input name="parent_phone" aria-label="Telefon" v-model="form.parent_phone" placeholder="+49 …"
                           :class="field">
                </div>
            </div>
        </fieldset>

        <!-- Notes -->
        <div class="mt-3">
            <label class="block text-[11px] font-semibold text-slate-400 mb-1.5 uppercase tracking-[0.08em]" aria-hidden="true">
                Notiz <span class="normal-case font-normal text-slate-300">(optional)</span>
            </label>
            <textarea name="notes_parent" aria-label="Notiz" v-model="form.notes_parent" placeholder="Weitere Hinweise …"
                      :class="field" rows="2"></textarea>
        </div>

        <!-- Room picker -->
        <fieldset class="mt-3">
            <legend class="text-[11px] font-bold uppercase tracking-[0.15em] text-slate-400">
                Zimmer <span class="normal-case font-normal tracking-normal text-slate-300">(optional)</span>
            </legend>
            <div class="mt-2.5 flex flex-wrap gap-3" role="group" aria-label="Zimmerfarbe">
                <button v-for="r in rooms" :key="r.value" type="button"
                        :title="r.label" :aria-label="r.label" :aria-pressed="form.room === r.value"
                        class="group relative grid h-11 w-11 place-items-center rounded-2xl shadow-sm transition-all duration-200 hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 focus-visible:ring-offset-2"
                        :class="form.room === r.value
                            ? 'scale-110 ring-2 ring-accent ring-offset-2 shadow-md'
                            : 'ring-1 ring-inset ring-black/6 hover:ring-black/12 hover:shadow-md'"
                        :style="{ backgroundColor: r.color }"
                        @click="form.room = form.room === r.value ? null : r.value">
                    <span
                        class="text-sm font-bold text-widget-text/70 transition-all duration-200"
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
                    class="flex-1 rounded-2xl border border-slate-200 bg-widget-bg py-3 text-sm font-semibold text-widget-text/70 transition-all duration-200 hover:-translate-y-0.5 hover:bg-slate-50 hover:border-slate-300 active:translate-y-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-300 focus-visible:ring-offset-2">
                ← Zurück
            </button>

            <button data-advance type="button" :disabled="!valid" @click="advance"
                    class="flex-[2] inline-flex items-center justify-center gap-2 rounded-2xl py-3 text-sm font-bold text-white shadow-[0_14px_26px_-12px_rgb(var(--masinga-primary-rgb)_/_0.65)] transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_18px_32px_-12px_rgb(var(--masinga-primary-rgb)_/_0.80)] active:translate-y-0 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:translate-y-0 disabled:shadow-none focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 focus-visible:ring-offset-2"
                    style="background: var(--masinga-gradient);">
                Weiter
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>
    </form>
</template>
