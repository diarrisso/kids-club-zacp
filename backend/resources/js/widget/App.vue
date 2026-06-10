<script setup lang="ts">
import { ref, onMounted, nextTick, provide } from 'vue'
import type { Api } from './api'
import type { Service, Slot, BookingResult, PatientData, ParentData } from './types'
import { useWizard } from './useWizard'
import { useTheme, WIDGET_CONFIG_KEY } from './useTheme'
import StepIndicator from './components/StepIndicator.vue'
import TerminStep from './steps/TerminStep.vue'
import KindStep from './steps/KindStep.vue'
import FormStep from './steps/FormStep.vue'
import ConfirmStep from './steps/ConfirmStep.vue'
import SuccessStep from './steps/SuccessStep.vue'

const props = defineProps<{ api: Api; apiBase?: string }>()
const w = useWizard()

const rootEl = ref<HTMLElement | null>(null)
const theme = useTheme(props.api, props.apiBase ?? '')
provide(WIDGET_CONFIG_KEY, theme.state)

const NET_ERR = 'Verbindungsfehler. Bitte erneut versuchen.'

const services = ref<Service[]>([])
const availableDates = ref<string[]>([])
const slots = ref<Slot[]>([])
const selectedDate = ref<string | undefined>(undefined)
const loadingSlots = ref(false)
const result = ref<BookingResult | null>(null)
const cancelled = ref(false)
const cancelling = ref(false)
const serverErrors = ref<Record<string, string[]>>({})
const banner = ref<string>('')
const loading = ref(false)
const pendingForm = ref<ParentData | null>(null)
const kindData = ref<PatientData | null>(null)

let daysReq = 0
let slotsReq = 0

onMounted(async () => {
    if (rootEl.value) theme.load(rootEl.value) // fire-and-forget; defaults already painted
    try { services.value = await props.api.services() }
    catch { banner.value = NET_ERR }
})

function onServiceSelect(s: Service) {
    banner.value = ''
    const changing = w.selection.service?.id !== s.id
    w.chooseService(s)
    selectedDate.value = undefined
    slots.value = []
    if (changing) {
        availableDates.value = [] // only clear when switching service; calendar remounts via :key and refetches
        w.clearSlot() // stale slot from previous service must not linger
    }
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
    w.clearSlot() // stale slot from previous day must not linger
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

function onKindAdvance(data: PatientData) {
    kindData.value = data
    serverErrors.value = {} // drop any stale patient_* error once the step is corrected
    w.advance() // kind → form (elternteil)
}

function onFormAdvance(data: ParentData) {
    pendingForm.value = data
    serverErrors.value = {}
    w.advance() // form → confirm
}

async function onSubmit() {
    if (loading.value || !pendingForm.value || !kindData.value) return
    serverErrors.value = {}
    banner.value = ''
    loading.value = true
    try {
        result.value = await props.api.book({
            ...kindData.value,    // patient_* fields (PatientData)
            ...pendingForm.value, // parent_* fields (ParentData)
            consent: true, // confirmed via the ConfirmStep checkbox
            practitioner_id: w.selection.slot!.practitioner.id,
            service_id: w.selection.service!.id,
            starts_at: w.selection.slot!.starts_at,
        })
        if (result.value?.cancellation_token) w.complete()
    } catch (e: any) {
        if (e.kind === 'validation') {
            serverErrors.value = e.errors
            // Route to the step that owns the failing field so the error is actually
            // visible: patient_* lives on KindStep, everything else on FormStep.
            const hasPatientError = Object.keys(e.errors).some(k => k.startsWith('patient_'))
            w.go(hasPatientError ? 'kind' : 'form')
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
    if (!result.value || cancelling.value) return
    banner.value = '' // a previous failed attempt must not linger next to the success state
    cancelling.value = true
    try {
        await props.api.cancel(result.value.cancellation_token)
        cancelled.value = true
    } catch {
        banner.value = 'Stornierung fehlgeschlagen.'
    } finally {
        cancelling.value = false
    }
}

function onRestart() {
    daysReq++ // invalidate any in-flight availabilityDays response
    slotsReq++ // invalidate any in-flight slots response
    result.value = null
    cancelled.value = false
    kindData.value = null
    pendingForm.value = null
    serverErrors.value = {}
    banner.value = ''
    slots.value = []
    selectedDate.value = undefined
    availableDates.value = []
    w.reset()
}
</script>

<template>
    <!-- font-body/rounded-widget consume the runtime theme vars — font-sans or a
         hardcoded radius here would silently pin the DEFAULT look and make the
         staff Erscheinungsbild settings a no-op on the real widget. -->
    <div ref="rootEl" class="font-body text-widget-text max-w-md mx-auto bg-widget-bg rounded-widget shadow-[0_24px_70px_-28px_rgba(30,41,59,0.30)] ring-1 ring-slate-100/80 p-6 sm:p-7 space-y-4">
        <img v-if="theme.state.config?.logoUrl" :src="theme.state.config.logoUrl" alt=""
             class="mx-auto mb-1 max-h-12 w-auto" data-widget-logo>
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
                    :selected-slot="w.selection.slot"
                    @service-select="onServiceSelect"
                    @month-change="onMonthChange" @pick-date="onPickDate"
                    @select="w.chooseSlot" @continue="() => w.confirmSlot()" />

        <KindStep v-else-if="w.step.value === 'kind'"
                  :selection="w.selection" :initial-values="kindData" :server-errors="serverErrors"
                  @advance="onKindAdvance" @back="() => w.backToTermin()" />

        <FormStep v-else-if="w.step.value === 'form'"
                  :selection="w.selection" :server-errors="serverErrors" :initial-values="pendingForm"
                  @advance="onFormAdvance" @back="() => w.back()" />

        <ConfirmStep v-else-if="w.step.value === 'confirm'"
                     :selection="w.selection" :form-data="pendingForm ?? {}" :kind-data="kindData ?? {}" :loading="loading"
                     @submit="onSubmit" @back="() => w.back()" />

        <SuccessStep v-else-if="w.step.value === 'success' && result" :result="result"
                     :cancelled="cancelled" :cancelling="cancelling"
                     @cancel="onCancel" @restart="onRestart" />
    </div>
</template>
