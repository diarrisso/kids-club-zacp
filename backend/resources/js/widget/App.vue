<script setup lang="ts">
import { ref, onMounted } from 'vue'
import type { Api } from './api'
import type { Service, Practitioner, Slot, BookingResult } from './types'
import { useWizard } from './useWizard'
import ServiceStep from './steps/ServiceStep.vue'
import PractitionerStep from './steps/PractitionerStep.vue'
import SlotStep from './steps/SlotStep.vue'
import FormStep from './steps/FormStep.vue'
import SuccessStep from './steps/SuccessStep.vue'

const props = defineProps<{ api: Api; apiBase?: string; tenant?: string }>()
const w = useWizard()

const services = ref<Service[]>([])
const practitioners = ref<Practitioner[]>([])
const slots = ref<Slot[]>([])
const result = ref<BookingResult | null>(null)
const serverErrors = ref<Record<string, string[]>>({})
const banner = ref<string>('')
const loading = ref(false)

onMounted(async () => { services.value = await props.api.services() })

async function onService(s: Service) {
    w.chooseService(s)
    practitioners.value = await props.api.practitioners(s.id)
}

async function onPractitioner(p: Practitioner) {
    w.choosePractitioner(p)
    const from = new Date().toISOString().slice(0, 10)
    const to = new Date(Date.now() + 60 * 864e5).toISOString().slice(0, 10)
    slots.value = await props.api.slots(p.id, w.selection.service!.id, from, to)
}

async function onSubmit(formData: Record<string, unknown>) {
    serverErrors.value = {}
    banner.value = ''
    loading.value = true
    try {
        result.value = await props.api.book({
            ...(formData as any),
            practitioner_id: w.selection.practitioner!.id,
            service_id: w.selection.service!.id,
            starts_at: w.selection.slot!.starts_at,
        })
        w.complete()
    } catch (e: any) {
        if (e.kind === 'validation') serverErrors.value = e.errors
        else if (e.kind === 'slot_taken') { banner.value = 'Termin nicht mehr verfügbar.'; w.back() }
        else if (e.kind === 'rate_limited') banner.value = 'Zu viele Versuche, bitte später erneut.'
        else banner.value = 'Verbindungsfehler. Bitte erneut versuchen.'
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <div class="font-sans text-slate-800 max-w-md mx-auto p-4">
        <div v-if="banner" class="bg-amber-100 text-amber-800 p-2 rounded mb-3 text-sm">{{ banner }}</div>

        <ServiceStep v-if="w.step.value === 'service'" :services="services" @select="onService" />
        <PractitionerStep v-else-if="w.step.value === 'practitioner'" :practitioners="practitioners" @select="onPractitioner" />
        <SlotStep v-else-if="w.step.value === 'slot'" :slots="slots" @select="w.chooseSlot" />
        <FormStep v-else-if="w.step.value === 'form'" :server-errors="serverErrors" @submit="onSubmit" />
        <SuccessStep v-else-if="w.step.value === 'success' && result" :result="result"
                     :api-base="apiBase ?? ''" :tenant="tenant ?? ''" />

        <button v-if="w.step.value !== 'service' && w.step.value !== 'success'" @click="w.back()"
                class="text-sm text-blue-600 mt-3">← Zurück</button>
    </div>
</template>
