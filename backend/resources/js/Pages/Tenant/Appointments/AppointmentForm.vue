<script setup lang="ts">
import { ref, reactive, watch, computed } from 'vue'
import type { AppointmentDto } from '@/lib/calendar'
import RoomPicker from '@/components/ui/RoomPicker.vue'
import { ROOM_OPTIONS as rooms } from '@/lib/rooms'

const props = defineProps<{
    open: boolean
    practitioners: Array<{ id: number; name: string; color: string }>
    services: Array<{ id: number; name: string; duration_minutes: number }>
    // edit mode: existing appointment; create mode: a prefilled {starts_at, practitioner_id?}
    appointment: AppointmentDto | null
    prefill: { starts_at?: string; practitioner_id?: number } | null
}>()

const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void; (e: 'refresh'): void }>()

const errors = ref<Record<string, string[]>>({})
const saving = ref(false)
const confirmCancel = ref(false)

const form = reactive({
    practitioner_id: 0, service_id: 0, starts_at: '',
    patient_first_name: '', patient_last_name: '', patient_birthdate: '',
    parent_first_name: '', parent_last_name: '', parent_phone: '', parent_email: '',
    notes_internal: '',
    room: null as string | null,
    attendance: null as 'arrived' | 'no_show' | null,
})

const isEdit = ref(false)

// Stepper state — only active in create mode
const step = ref(1)
const STEPS = [
    { n: 1, label: 'Termin' },
    { n: 2, label: 'Kind' },
    { n: 3, label: 'Kontakt' },
]
// Which backend error keys belong to which step
const STEP_FIELDS: Record<number, string[]> = {
    1: ['practitioner_id', 'service_id', 'starts_at'],
    2: ['patient_first_name', 'patient_last_name', 'patient_birthdate'],
    3: ['parent_first_name', 'parent_last_name', 'parent_phone', 'parent_email', 'notes_internal'],
}

const firstErrorStep = computed(() => {
    const keys = Object.keys(errors.value)
    for (let s = 1; s <= 3; s++) {
        if (STEP_FIELDS[s].some(f => keys.includes(f))) return s
    }
    return null
})

const stepHasError = (n: number) =>
    STEP_FIELDS[n].some(f => errors.value[f])

watch(() => props.open, (open) => {
    if (!open) return
    errors.value = {}
    confirmCancel.value = false
    step.value = 1
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
            attendance: a.attendance ?? null,
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
            attendance: null,
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
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors ?? {}
            // Jump to first step that has validation errors
            if (firstErrorStep.value) step.value = firstErrorStep.value
        } else if (e.response?.status === 409) {
            errors.value = { starts_at: [e.response.data.message ?? 'Überschneidung.'] }
            step.value = 1
        } else {
            errors.value = { _: ['Ein Fehler ist aufgetreten.'] }
        }
    } finally {
        saving.value = false
    }
}

const setAttendance = async (value: 'arrived' | 'no_show') => {
    if (!props.appointment) return
    // Toggle: clicking the active state clears it back to null.
    const next = form.attendance === value ? null : value
    saving.value = true
    errors.value = {}
    try {
        await window.axios.patch(`/termine/${props.appointment.id}`, { attendance: next })
        form.attendance = next
        emit('refresh')
    } catch (e: any) {
        errors.value = { _: ['Anwesenheit konnte nicht gespeichert werden.'] }
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
        <div class="bg-white rounded-ds-card shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">

            <!-- Modal header -->
            <div class="px-6 pt-6 pb-4 border-b border-slate-100">
                <h2 class="text-xl font-bold text-slate-900">
                    {{ isEdit ? 'Termin bearbeiten' : 'Neuer Termin' }}
                </h2>

                <!-- Step indicator — create mode only -->
                <nav v-if="!isEdit" class="mt-4 flex items-center" aria-label="Schritte">
                    <template v-for="(s, i) in STEPS" :key="s.n">
                        <!-- Step circle -->
                        <div class="flex flex-col items-center gap-1 min-w-[56px]">
                            <div
                                class="h-8 w-8 rounded-full flex items-center justify-center text-sm font-semibold transition-colors"
                                :class="{
                                    'bg-kids-blue text-white':   step === s.n,
                                    'bg-green-500 text-white':   step > s.n,
                                    'bg-slate-100 text-slate-400 ring-1 ring-slate-200': step < s.n,
                                }"
                            >
                                <svg v-if="step > s.n" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                                <span v-else>{{ s.n }}</span>
                            </div>
                            <span
                                class="text-[11px] font-medium transition-colors"
                                :class="step === s.n ? 'text-kids-blue' : 'text-slate-400'"
                            >{{ s.label }}</span>
                        </div>
                        <!-- Connector line between steps -->
                        <div v-if="i < STEPS.length - 1"
                             class="flex-1 h-px mx-1 mb-4 transition-colors"
                             :class="step > s.n ? 'bg-green-400' : 'bg-slate-200'"
                        />
                    </template>
                </nav>
            </div>

            <!-- Form body -->
            <div class="px-6 py-5">
                <p v-if="errors._" class="text-red-600 text-sm mb-3">{{ errors._[0] }}</p>

                <!-- ── STEP 1 : Termin ── -->
                <div v-show="isEdit || step === 1" class="grid grid-cols-2 gap-3">
                    <label class="col-span-2 text-sm font-medium text-slate-700">Behandler
                        <select v-model.number="form.practitioner_id" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm bg-white">
                            <option v-for="p in practitioners" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </label>
                    <label class="col-span-2 text-sm font-medium text-slate-700">Leistung
                        <select v-model.number="form.service_id" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm bg-white">
                            <option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }} ({{ s.duration_minutes }} Min.)</option>
                        </select>
                    </label>
                    <label class="col-span-2 text-sm font-medium text-slate-700">Termin (Beginn)
                        <input v-model="form.starts_at" type="datetime-local" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        <span v-if="errors.starts_at" class="text-red-500 text-xs mt-0.5 block">{{ errors.starts_at[0] }}</span>
                    </label>
                    <div class="col-span-2 text-sm font-medium text-slate-700">
                        <span class="block mb-1">Zimmer (optional)</span>
                        <RoomPicker v-model="form.room" :rooms="rooms" />
                    </div>
                </div>

                <!-- ── STEP 2 : Kind ── -->
                <div v-show="!isEdit && step === 2" class="grid grid-cols-2 gap-3">
                    <label class="text-sm font-medium text-slate-700">Kind Vorname
                        <input v-model="form.patient_first_name" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        <span v-if="errors.patient_first_name" class="text-red-500 text-xs mt-0.5 block">{{ errors.patient_first_name[0] }}</span>
                    </label>
                    <label class="text-sm font-medium text-slate-700">Kind Nachname
                        <input v-model="form.patient_last_name" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        <span v-if="errors.patient_last_name" class="text-red-500 text-xs mt-0.5 block">{{ errors.patient_last_name[0] }}</span>
                    </label>
                    <label class="col-span-2 text-sm font-medium text-slate-700">Geburtsdatum
                        <input v-model="form.patient_birthdate" type="date" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        <span v-if="errors.patient_birthdate" class="text-red-500 text-xs mt-0.5 block">{{ errors.patient_birthdate[0] }}</span>
                    </label>
                </div>

                <!-- ── STEP 3 : Kontakt ── -->
                <div v-show="!isEdit && step === 3" class="grid grid-cols-2 gap-3">
                    <label class="text-sm font-medium text-slate-700">Eltern Vorname
                        <input v-model="form.parent_first_name" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        <span v-if="errors.parent_first_name" class="text-red-500 text-xs mt-0.5 block">{{ errors.parent_first_name[0] }}</span>
                    </label>
                    <label class="text-sm font-medium text-slate-700">Eltern Nachname
                        <input v-model="form.parent_last_name" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        <span v-if="errors.parent_last_name" class="text-red-500 text-xs mt-0.5 block">{{ errors.parent_last_name[0] }}</span>
                    </label>
                    <label class="text-sm font-medium text-slate-700">Telefon
                        <input v-model="form.parent_phone" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        <span v-if="errors.parent_phone" class="text-red-500 text-xs mt-0.5 block">{{ errors.parent_phone[0] }}</span>
                    </label>
                    <label class="text-sm font-medium text-slate-700">E-Mail (optional)
                        <input v-model="form.parent_email" type="email" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        <span v-if="errors.parent_email" class="text-red-500 text-xs mt-0.5 block">{{ errors.parent_email[0] }}</span>
                    </label>
                    <label class="col-span-2 text-sm font-medium text-slate-700">Interne Notiz
                        <textarea v-model="form.notes_internal" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" rows="2"></textarea>
                    </label>
                </div>

                <!-- Edit-only: Anwesenheit -->
                <div v-if="isEdit" class="mt-4">
                    <!-- Edit mode: all fields already shown above in step 1 block -->
                    <div class="grid grid-cols-2 gap-3 mt-3">
                        <label class="text-sm font-medium text-slate-700">Kind Vorname
                            <input v-model="form.patient_first_name" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        </label>
                        <label class="text-sm font-medium text-slate-700">Kind Nachname
                            <input v-model="form.patient_last_name" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        </label>
                        <label class="col-span-2 text-sm font-medium text-slate-700">Geburtsdatum
                            <input v-model="form.patient_birthdate" type="date" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        </label>
                        <label class="text-sm font-medium text-slate-700">Eltern Vorname
                            <input v-model="form.parent_first_name" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        </label>
                        <label class="text-sm font-medium text-slate-700">Eltern Nachname
                            <input v-model="form.parent_last_name" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        </label>
                        <label class="text-sm font-medium text-slate-700">Telefon
                            <input v-model="form.parent_phone" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        </label>
                        <label class="text-sm font-medium text-slate-700">E-Mail (optional)
                            <input v-model="form.parent_email" type="email" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" />
                        </label>
                        <label class="col-span-2 text-sm font-medium text-slate-700">Interne Notiz
                            <textarea v-model="form.notes_internal" class="block w-full border border-slate-200 rounded-[8px] px-3 py-2 mt-1 text-sm" rows="2"></textarea>
                        </label>
                    </div>

                    <div class="mt-4">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Anwesenheit</label>
                        <div class="flex gap-2">
                            <button type="button"
                                    @click="setAttendance('arrived')"
                                    :disabled="saving"
                                    :class="form.attendance === 'arrived' ? 'bg-green-600 text-white' : 'bg-slate-100 text-slate-700'"
                                    class="rounded-full px-3 py-1.5 text-sm font-semibold disabled:opacity-50">
                                ✓ Erschienen
                            </button>
                            <button type="button"
                                    @click="setAttendance('no_show')"
                                    :disabled="saving"
                                    :class="form.attendance === 'no_show' ? 'bg-rose-600 text-white' : 'bg-slate-100 text-slate-700'"
                                    class="rounded-full px-3 py-1.5 text-sm font-semibold disabled:opacity-50">
                                ✗ Nicht erschienen
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal footer -->
            <div class="px-6 pb-6 flex justify-between items-center border-t border-slate-100 pt-4">
                <!-- Left: cancel appointment (edit only) -->
                <button v-if="isEdit" @click="cancelAppointment" :disabled="saving"
                        class="text-red-500 text-sm hover:text-red-700">
                    {{ confirmCancel ? 'Wirklich stornieren?' : 'Termin stornieren' }}
                </button>
                <span v-else></span>

                <!-- Right: nav buttons -->
                <div class="flex gap-2">
                    <!-- Edit mode: simple close + save -->
                    <template v-if="isEdit">
                        <button @click="emit('close')" :disabled="saving"
                                class="px-4 py-2 rounded-[8px] border border-slate-200 text-sm text-slate-700 hover:bg-slate-50">
                            Abbrechen
                        </button>
                        <button @click="submit" :disabled="saving"
                                class="px-4 py-2 rounded-[8px] bg-kids-blue text-white text-sm font-medium hover:opacity-90 disabled:opacity-50">
                            {{ saving ? 'Speichern…' : 'Speichern' }}
                        </button>
                    </template>

                    <!-- Create mode: stepper navigation -->
                    <template v-else>
                        <!-- Abbrechen / Zurück -->
                        <button v-if="step === 1" @click="emit('close')" :disabled="saving"
                                class="px-4 py-2 rounded-[8px] border border-slate-200 text-sm text-slate-700 hover:bg-slate-50">
                            Abbrechen
                        </button>
                        <button v-else @click="step--" :disabled="saving"
                                class="px-4 py-2 rounded-[8px] border border-slate-200 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-1">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                            </svg>
                            Zurück
                        </button>

                        <!-- Weiter / Speichern -->
                        <button v-if="step < 3" @click="step++" :disabled="saving"
                                class="px-4 py-2 rounded-[8px] bg-kids-blue text-white text-sm font-medium hover:opacity-90 flex items-center gap-1">
                            Weiter
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                        <button v-else @click="submit" :disabled="saving"
                                class="px-4 py-2 rounded-[8px] bg-kids-blue text-white text-sm font-medium hover:opacity-90 disabled:opacity-50">
                            {{ saving ? 'Speichern…' : 'Speichern' }}
                        </button>
                    </template>
                </div>
            </div>

        </div>
    </div>
</template>
