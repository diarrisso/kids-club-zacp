<script setup lang="ts">
import { ref, computed, inject } from 'vue'
import type { Service } from '../types'
import type { Api } from '../api'
import { WIDGET_CONFIG_KEY } from '../useTheme'

const props = defineProps<{
    api: Api
    services: Service[]
    preselectedServiceId?: number
}>()

const emit = defineEmits<{ (e: 'back'): void }>()

const widgetConfig = inject(WIDGET_CONFIG_KEY, { config: null })
const datenschutzUrl = computed(() => widgetConfig.config?.datenschutzUrl ?? null)

const form = ref({
    patient_first_name: '',
    patient_last_name: '',
    parent_first_name: '',
    parent_last_name: '',
    parent_phone: '',
    parent_email: '',
    service_id: props.preselectedServiceId ?? null as number | null,
    notes: '',
    consent: false,
    website: '', // honeypot — doit rester vide
})

const saving = ref(false)
const done = ref(false)
const errors = ref<Record<string, string[]>>({})

const canSubmit = computed(() => form.value.consent && !saving.value)

const submit = async () => {
    saving.value = true
    errors.value = {}
    try {
        await props.api.waitlist({
            patient_first_name: form.value.patient_first_name,
            patient_last_name: form.value.patient_last_name,
            parent_first_name: form.value.parent_first_name,
            parent_last_name: form.value.parent_last_name,
            parent_phone: form.value.parent_phone,
            parent_email: form.value.parent_email || null,
            service_id: form.value.service_id ?? null,
            notes: form.value.notes || null,
            consent: form.value.consent,
            website: form.value.website || undefined,
        })
        done.value = true
    } catch (e: unknown) {
        if (e && typeof e === 'object' && 'kind' in e && (e as { kind: string }).kind === 'validation') {
            errors.value = (e as { kind: 'validation'; errors: Record<string, string[]> }).errors
        }
    } finally {
        saving.value = false
    }
}

const fieldError = (field: string) => errors.value[field]?.[0]
</script>

<template>
    <!-- Success state -->
    <div v-if="done" class="text-center py-6 space-y-3">
        <p class="text-2xl">✓</p>
        <p class="font-semibold text-widget-text">Auf der Warteliste eingetragen!</p>
        <p class="text-sm text-widget-text/70">Wir melden uns, sobald ein Termin frei wird.</p>
    </div>

    <!-- Form -->
    <div v-else class="space-y-3">
        <h2 class="text-base font-semibold text-widget-text">Auf die Warteliste eintragen</h2>
        <p class="text-xs text-widget-text/70">Wir kontaktieren Sie, sobald ein Termin frei wird.</p>

        <!-- Honeypot — caché des humains, visible pour les bots -->
        <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off"
               style="position:absolute;left:-9999px;opacity:0;pointer-events:none;" aria-hidden="true" />

        <div class="grid grid-cols-2 gap-2">
            <div>
                <input v-model="form.patient_first_name" type="text" placeholder="Vorname Kind *"
                       class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text placeholder:text-widget-text/40" />
                <p v-if="fieldError('patient_first_name')" class="text-xs text-red-500 mt-0.5">{{ fieldError('patient_first_name') }}</p>
            </div>
            <div>
                <input v-model="form.patient_last_name" type="text" placeholder="Nachname Kind *"
                       class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text placeholder:text-widget-text/40" />
                <p v-if="fieldError('patient_last_name')" class="text-xs text-red-500 mt-0.5">{{ fieldError('patient_last_name') }}</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <div>
                <input v-model="form.parent_first_name" type="text" placeholder="Vorname Elternteil *"
                       class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text placeholder:text-widget-text/40" />
                <p v-if="fieldError('parent_first_name')" class="text-xs text-red-500 mt-0.5">{{ fieldError('parent_first_name') }}</p>
            </div>
            <div>
                <input v-model="form.parent_last_name" type="text" placeholder="Nachname Elternteil *"
                       class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text placeholder:text-widget-text/40" />
                <p v-if="fieldError('parent_last_name')" class="text-xs text-red-500 mt-0.5">{{ fieldError('parent_last_name') }}</p>
            </div>
        </div>

        <div>
            <input v-model="form.parent_phone" type="tel" placeholder="Telefon *"
                   class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text placeholder:text-widget-text/40" />
            <p v-if="fieldError('parent_phone')" class="text-xs text-red-500 mt-0.5">{{ fieldError('parent_phone') }}</p>
        </div>

        <input v-model="form.parent_email" type="email" placeholder="E-Mail (optional)"
               class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text placeholder:text-widget-text/40" />

        <select v-model="form.service_id"
                class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text">
            <option :value="null">Keine Präferenz</option>
            <option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>

        <textarea v-model="form.notes" placeholder="Notiz (optional)"
                  rows="2"
                  class="w-full rounded-xl border px-3 py-2 text-sm bg-widget-bg text-widget-text placeholder:text-widget-text/40 resize-none" />

        <!-- DSGVO consent -->
        <label class="flex cursor-pointer items-start gap-3 rounded-2xl p-3 ring-1 ring-slate-100 transition-all duration-150 hover:ring-accent/30"
               :style="form.consent ? 'background: linear-gradient(135deg, rgb(var(--masinga-primary-rgb) / 0.06) 0%, rgba(255,255,255,1) 100%); box-shadow: 0 0 0 2px rgb(var(--masinga-primary-rgb) / 0.18);' : 'background-color: rgba(248,250,251,0.8);'">
            <input
                data-consent
                v-model="form.consent"
                type="checkbox"
                class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 focus:ring-accent/15"
                :style="form.consent ? { accentColor: 'var(--masinga-accent)' } : {}"
            >
            <span class="text-xs leading-relaxed text-widget-text/70">
                Ich willige in die Verarbeitung meiner Daten zur Aufnahme auf die Warteliste ein.
                <template v-if="datenschutzUrl">
                    Weitere Informationen in der
                    <a :href="datenschutzUrl" target="_blank" rel="noopener noreferrer" data-datenschutz-link
                       class="font-semibold text-accent underline underline-offset-2" @click.stop>Datenschutzerklärung</a>.
                </template>
            </span>
        </label>

        <div class="flex gap-2 pt-1">
            <button type="button" @click="emit('back')"
                    class="px-4 py-2 rounded-xl border text-sm text-widget-text/70 hover:bg-tint">
                ← Zurück
            </button>
            <button type="button" @click="submit" :disabled="!canSubmit"
                    class="flex-1 rounded-xl bg-accent text-white text-sm font-semibold py-2 disabled:opacity-50">
                {{ saving ? 'Wird eingetragen…' : 'Auf die Warteliste' }}
            </button>
        </div>
    </div>
</template>
