<script setup lang="ts">
import { ref, onMounted, nextTick } from 'vue'
import type { Api } from './api'
import type { Service, Slot, BookingResult } from './types'
import { useWizard } from './useWizard'
import ServiceStep from './steps/ServiceStep.vue'
import TerminStep from './steps/TerminStep.vue'
import FormStep from './steps/FormStep.vue'
import SuccessStep from './steps/SuccessStep.vue'

const props = defineProps<{ api: Api; apiBase?: string }>()
const w = useWizard()

const NET_ERR = 'Verbindungsfehler. Bitte erneut versuchen.'

const services = ref<Service[]>([])
const availableDates = ref<string[]>([])
const slots = ref<Slot[]>([])
const selectedDate = ref<string | undefined>(undefined)
const loadingSlots = ref(false)
const result = ref<BookingResult | null>(null)
const cancelled = ref(false)
const serverErrors = ref<Record<string, string[]>>({})
const banner = ref<string>('')
const loading = ref(false)

let daysReq = 0
let slotsReq = 0

onMounted(async () => {
    try { services.value = await props.api.services() }
    catch { banner.value = NET_ERR }
})

function onService(s: Service) {
    banner.value = ''
    w.chooseService(s)
    selectedDate.value = undefined
    slots.value = []
    availableDates.value = []
    // Availability for the visible month is loaded via the calendar's
    // month-change event, emitted on mount of TerminStep.
}

async function onMonthChange(win: { from: string; to: string }) {
    if (!w.selection.service) return
    banner.value = ''
    const req = ++daysReq
    try {
        const dates = await props.api.availabilityDays(w.selection.service.id, win.from, win.to)
        if (req === daysReq) availableDates.value = dates
    } catch {
        if (req === daysReq) banner.value = NET_ERR
    }
}

async function onPickDate(date: string) {
    if (!w.selection.service) return
    banner.value = ''
    selectedDate.value = date
    loadingSlots.value = true
    slots.value = []
    const req = ++slotsReq
    try {
        const result = await props.api.slots(w.selection.service.id, date, date)
        if (req === slotsReq) slots.value = result
    } catch {
        if (req === slotsReq) banner.value = NET_ERR
    } finally {
        if (req === slotsReq) loadingSlots.value = false
    }
}

async function onSubmit(formData: Record<string, unknown>) {
    if (loading.value) return
    serverErrors.value = {}
    banner.value = ''
    loading.value = true
    try {
        result.value = await props.api.book({
            ...(formData as any),
            practitioner_id: w.selection.slot!.practitioner.id,
            service_id: w.selection.service!.id,
            starts_at: w.selection.slot!.starts_at,
        })
        if (result.value?.cancellation_token) w.complete()
    } catch (e: any) {
        if (e.kind === 'validation') serverErrors.value = e.errors
        else if (e.kind === 'slot_taken') {
            w.back() // back to 'termin'; TerminStep remounts and re-emits month-change (which clears the banner)
            if (selectedDate.value) onPickDate(selectedDate.value) // refresh the day's slots (drops the taken one)
            // Set the banner after the remount's month-change/onPickDate have cleared it,
            // so the "no longer available" notice survives on the calendar.
            await nextTick()
            banner.value = 'Termin nicht mehr verfügbar.'
        }
        else if (e.kind === 'rate_limited') banner.value = 'Zu viele Versuche, bitte später erneut.'
        else banner.value = NET_ERR
    } finally {
        loading.value = false
    }
}

async function onCancel() {
    if (!result.value) return
    if (typeof window !== 'undefined' && !window.confirm('Termin wirklich stornieren?')) return
    try {
        await props.api.cancel(result.value.cancellation_token)
        cancelled.value = true
    } catch {
        banner.value = 'Stornierung fehlgeschlagen.'
    }
}
</script>

<template>
    <div class="font-sans text-slate-800 max-w-md mx-auto p-4">
        <div v-if="banner" class="bg-amber-100 text-amber-800 p-2 rounded mb-3 text-sm">{{ banner }}</div>

        <ServiceStep v-if="w.step.value === 'service'" :services="services" @select="onService" />
        <TerminStep v-else-if="w.step.value === 'termin'"
                    :available-dates="availableDates" :slots="slots"
                    :loading-slots="loadingSlots" :selected-date="selectedDate"
                    @month-change="onMonthChange" @pick-date="onPickDate" @select="w.chooseSlot" />
        <FormStep v-else-if="w.step.value === 'form'" :server-errors="serverErrors" :loading="loading" @submit="onSubmit" />
        <SuccessStep v-else-if="w.step.value === 'success' && result" :result="result"
                     :cancelled="cancelled" @cancel="onCancel" />

        <button v-if="w.step.value !== 'service' && w.step.value !== 'success'" @click="w.back()"
                class="text-sm text-blue-600 mt-3">← Zurück</button>
    </div>
</template>
