<script setup lang="ts">
import { ref, reactive, watch } from 'vue'
import type { AppointmentDto } from '@/lib/calendar'
import RoomPicker from '@/components/ui/RoomPicker.vue'

const rooms = [
    { value: 'green', color: '#BDCCC2', label: 'Grünes Zimmer' },
    { value: 'yellow', color: '#F7E29D', label: 'Gelbes Zimmer' },
    { value: 'peach', color: '#FCE8E1', label: 'Oranges Zimmer' },
    { value: 'blue', color: '#98ACBA', label: 'Blaues Zimmer' },
    { value: 'purple', color: '#CCC8CE', label: 'Lila Zimmer' },
]

const props = defineProps<{
    open: boolean
    practitioners: Array<{ id: number; name: string; color: string }>
    services: Array<{ id: number; name: string; duration_minutes: number }>
    // edit mode: existing appointment; create mode: a prefilled {starts_at, practitioner_id?}
    appointment: AppointmentDto | null
    prefill: { starts_at?: string; practitioner_id?: number } | null
}>()

const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>()

const errors = ref<Record<string, string[]>>({})
const saving = ref(false)
const confirmCancel = ref(false)

const form = reactive({
    practitioner_id: 0, service_id: 0, starts_at: '',
    patient_first_name: '', patient_last_name: '', patient_birthdate: '',
    parent_first_name: '', parent_last_name: '', parent_phone: '', parent_email: '',
    notes_internal: '',
    room: null as string | null,
})

const isEdit = ref(false)

watch(() => props.open, (open) => {
    if (!open) return
    errors.value = {}
    confirmCancel.value = false
    if (props.appointment) {
        isEdit.value = true
        const a = props.appointment
        Object.assign(form, {
            practitioner_id: a.practitioner.id, service_id: a.service.id,
            starts_at: a.starts_at.slice(0, 16),
            patient_first_name: a.patient_first_name, patient_last_name: a.patient_last_name,
            patient_birthdate: a.patient_birthdate ?? '',
            parent_first_name: a.parent_first_name, parent_last_name: a.parent_last_name,
            parent_phone: a.parent_phone ?? '', parent_email: a.parent_email ?? '',
            notes_internal: a.notes_internal ?? '',
            room: a.room ?? null,
        })
    } else {
        isEdit.value = false
        Object.assign(form, {
            practitioner_id: props.prefill?.practitioner_id ?? (props.practitioners[0]?.id ?? 0),
            service_id: props.services[0]?.id ?? 0,
            starts_at: (props.prefill?.starts_at ?? '').slice(0, 16),
            patient_first_name: '', patient_last_name: '', patient_birthdate: '',
            parent_first_name: '', parent_last_name: '', parent_phone: '', parent_email: '', notes_internal: '',
            room: null,
        })
    }
})

const submit = async () => {
    saving.value = true
    errors.value = {}
    const payload = { ...form, parent_email: form.parent_email || null }
    try {
        if (isEdit.value && props.appointment) {
            await window.axios.patch(`/termine/${props.appointment.id}`, payload)
        } else {
            await window.axios.post('/termine', payload)
        }
        emit('saved')
    } catch (e: any) {
        if (e.response?.status === 422) errors.value = e.response.data.errors ?? {}
        else if (e.response?.status === 409) errors.value = { starts_at: [e.response.data.message ?? 'Überschneidung.'] }
        else errors.value = { _: ['Ein Fehler ist aufgetreten.'] }
    } finally {
        saving.value = false
    }
}

// Inline two-step confirmation (no native confirm() dialog).
const cancelAppointment = async () => {
    if (!props.appointment) return
    if (!confirmCancel.value) {
        confirmCancel.value = true
        return
    }
    saving.value = true
    errors.value = {}
    try {
        await window.axios.delete(`/termine/${props.appointment.id}`)
        emit('saved')
    } catch (e: any) {
        errors.value = { _: [e.response?.data?.message ?? 'Stornierung fehlgeschlagen.'] }
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <div v-if="open" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" @click.self="emit('close')">
        <div class="bg-white rounded-lg shadow-xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <h2 class="text-xl font-bold mb-4">{{ isEdit ? 'Termin bearbeiten' : 'Neuer Termin' }}</h2>

            <p v-if="errors._" class="text-red-600 text-sm mb-2">{{ errors._[0] }}</p>

            <div class="grid grid-cols-2 gap-3">
                <label class="col-span-2 text-sm">Behandler
                    <select v-model.number="form.practitioner_id" class="w-full border rounded p-2">
                        <option v-for="p in practitioners" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </label>
                <label class="col-span-2 text-sm">Leistung
                    <select v-model.number="form.service_id" class="w-full border rounded p-2">
                        <option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }} ({{ s.duration_minutes }} Min.)</option>
                    </select>
                </label>
                <label class="col-span-2 text-sm">Termin (Beginn)
                    <input v-model="form.starts_at" type="datetime-local" class="w-full border rounded p-2" />
                    <span v-if="errors.starts_at" class="text-red-600 text-xs">{{ errors.starts_at[0] }}</span>
                </label>

                <label class="text-sm">Kind Vorname
                    <input v-model="form.patient_first_name" class="w-full border rounded p-2" />
                </label>
                <label class="text-sm">Kind Nachname
                    <input v-model="form.patient_last_name" class="w-full border rounded p-2" />
                </label>
                <label class="col-span-2 text-sm">Geburtsdatum
                    <input v-model="form.patient_birthdate" type="date" class="w-full border rounded p-2" />
                </label>

                <label class="text-sm">Eltern Vorname
                    <input v-model="form.parent_first_name" class="w-full border rounded p-2" />
                </label>
                <label class="text-sm">Eltern Nachname
                    <input v-model="form.parent_last_name" class="w-full border rounded p-2" />
                </label>
                <label class="text-sm">Telefon
                    <input v-model="form.parent_phone" class="w-full border rounded p-2" />
                </label>
                <label class="text-sm">E-Mail (optional)
                    <input v-model="form.parent_email" type="email" class="w-full border rounded p-2" />
                </label>
                <label class="col-span-2 text-sm">Interne Notiz
                    <textarea v-model="form.notes_internal" class="w-full border rounded p-2" rows="2"></textarea>
                </label>
                <div class="col-span-2 text-sm">
                    <span class="block mb-1">Zimmer (optional)</span>
                    <RoomPicker v-model="form.room" :rooms="rooms" />
                </div>
            </div>

            <div class="flex justify-between items-center mt-5">
                <button v-if="isEdit" @click="cancelAppointment" :disabled="saving"
                        class="text-red-600 text-sm">
                    {{ confirmCancel ? 'Wirklich stornieren?' : 'Termin stornieren' }}
                </button>
                <span v-else></span>
                <div class="flex gap-2">
                    <button @click="emit('close')" :disabled="saving" class="px-4 py-2 rounded border">Abbrechen</button>
                    <button @click="submit" :disabled="saving" class="px-4 py-2 rounded bg-blue-700 text-white">Speichern</button>
                </div>
            </div>
        </div>
    </div>
</template>
