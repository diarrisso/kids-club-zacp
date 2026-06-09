<script setup lang="ts">
import { ref, onMounted, nextTick } from 'vue'
import type { Api } from './api'
import type { Service, Slot, BookingResult } from './types'
import { useWizard } from './useWizard'
import StepIndicator from './components/StepIndicator.vue'
import TerminStep from './steps/TerminStep.vue'
import FormStep from './steps/FormStep.vue'
import ConfirmStep from './steps/ConfirmStep.vue'
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
const pendingForm = ref<Record<string, unknown> | null>(null)

let daysReq = 0
let slotsReq = 0

onMounted(async () => {
    try { services.value = await props.api.services() }
    catch { banner.value = NET_ERR }
})

function onServiceSelect(s: Service) {
    banner.value = ''
    w.chooseService(s)
    selectedDate.value = undefined
    slots.value = []
    availableDates.value = []
    // The calendar mounts when a service is selected and emits month-change → loads dates.
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
        const r = await props.api.slots(w.selection.service.id, date, date)
        if (req === slotsReq) slots.value = r
    } catch {
        if (req === slotsReq) banner.value = NET_ERR
    } finally {
        if (req === slotsReq) loadingSlots.value = false
    }
}

function onFormAdvance(data: Record<string, unknown>) {
    pendingForm.value = data
    serverErrors.value = {}
    w.advance() // form → confirm
}

async function onSubmit() {
    if (loading.value || !pendingForm.value) return
    serverErrors.value = {}
    banner.value = ''
    loading.value = true
    try {
        result.value = await props.api.book({
            ...(pendingForm.value as any),
            consent: true, // confirmed via the ConfirmStep checkbox
            practitioner_id: w.selection.slot!.practitioner.id,
            service_id: w.selection.service!.id,
            starts_at: w.selection.slot!.starts_at,
        })
        if (result.value?.cancellation_token) w.complete()
    } catch (e: any) {
        if (e.kind === 'validation') {
            serverErrors.value = e.errors
            w.back() // confirm → form, so field errors show on the (pre-filled) form
        } else if (e.kind === 'slot_taken') {
            w.backToTermin()
            if (selectedDate.value) onPickDate(selectedDate.value)
            await nextTick()
            banner.value = 'Termin nicht mehr verfügbar.'
        } else if (e.kind === 'rate_limited') {
            banner.value = 'Zu viele Versuche, bitte später erneut.'
        } else {
            banner.value = NET_ERR
        }
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
    <div class="font-sans text-slate-800 max-w-md mx-auto bg-white rounded-[26px] shadow-[0_24px_70px_-28px_rgba(30,41,59,0.30)] ring-1 ring-slate-100/80 p-6 sm:p-7 space-y-4">
        <StepIndicator v-if="w.step.value !== 'success'" :current-step="w.step.value" />

        <div v-if="banner" role="alert" aria-live="assertive"
             class="flex items-start gap-2.5 rounded-2xl bg-amber-50 text-amber-800 ring-1 ring-amber-200/80 px-4 py-3 text-sm shadow-sm">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
            </svg>
            <span>{{ banner }}</span>
        </div>

        <TerminStep v-if="w.step.value === 'termin'"
                    :services="services" :selected-service="w.selection.service"
                    :available-dates="availableDates" :slots="slots"
                    :loading-slots="loadingSlots" :selected-date="selectedDate"
                    @service-select="onServiceSelect"
                    @month-change="onMonthChange" @pick-date="onPickDate" @select="w.chooseSlot" />

        <FormStep v-else-if="w.step.value === 'form'"
                  :selection="w.selection" :server-errors="serverErrors" :initial-values="pendingForm"
                  @advance="onFormAdvance" @back="() => w.back()" />

        <ConfirmStep v-else-if="w.step.value === 'confirm'"
                     :selection="w.selection" :form-data="pendingForm ?? {}" :loading="loading"
                     @submit="onSubmit" @back="() => w.back()" />

        <SuccessStep v-else-if="w.step.value === 'success' && result" :result="result"
                     :cancelled="cancelled" @cancel="onCancel" />
    </div>
</template>
