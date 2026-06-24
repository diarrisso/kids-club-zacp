<script setup lang="ts">
import { ref } from 'vue'
import type { Service } from '../types'

const props = defineProps<{
    api: { services(): Promise<Service[]> }
    services: Service[]
    preselectedServiceId?: number
}>()

const emit = defineEmits<{ (e: 'back'): void; (e: 'done'): void }>()

const form = ref({
    patient_first_name: '',
    patient_last_name: '',
    parent_first_name: '',
    parent_last_name: '',
    parent_phone: '',
    parent_email: '',
    service_id: props.preselectedServiceId ?? null as number | null,
    notes: '',
})

const saving = ref(false)
const done = ref(false)
const errors = ref<Record<string, string[]>>({})

const submit = async () => {
    saving.value = true
    errors.value = {}
    try {
        await window.axios.post('/api/v1/widget/warteliste', {
            ...form.value,
            parent_email: form.value.parent_email || null,
            service_id: form.value.service_id || null,
            notes: form.value.notes || null,
        })
        done.value = true
    } catch (e: any) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors ?? {}
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

        <div class="flex gap-2 pt-1">
            <button type="button" @click="emit('back')"
                    class="px-4 py-2 rounded-xl border text-sm text-widget-text/70 hover:bg-tint">
                ← Zurück
            </button>
            <button type="button" @click="submit" :disabled="saving"
                    class="flex-1 rounded-xl bg-accent text-white text-sm font-semibold py-2 disabled:opacity-50">
                {{ saving ? 'Wird eingetragen…' : 'Auf die Warteliste' }}
            </button>
        </div>
    </div>
</template>
