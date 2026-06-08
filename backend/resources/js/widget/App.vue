<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import type { Api } from './api'
import type { Service, Practitioner, Slot, BookingResult } from './types'
import { useWizard } from './useWizard'
import ServiceStep from './steps/ServiceStep.vue'
import PractitionerStep from './steps/PractitionerStep.vue'
import SlotStep from './steps/SlotStep.vue'
import FormStep from './steps/FormStep.vue'
import SuccessStep from './steps/SuccessStep.vue'

const props = defineProps<{ api: Api; apiBase?: string }>()
const w = useWizard()

const services = ref<Service[]>([])
const practitioners = ref<Practitioner[]>([])
const slots = ref<Slot[]>([])
const result = ref<BookingResult | null>(null)
const cancelled = ref(false)
const serverErrors = ref<Record<string, string[]>>({})
const banner = ref<string>('')
const loading = ref(false)

// Visible progress across the booking journey (Erfolg = final state).
const steps = [
    { key: 'service', label: 'Leistung', color: '#BDCCC2' },
    { key: 'practitioner', label: 'Behandler', color: '#F7E29D' },
    { key: 'slot', label: 'Termin', color: '#FCE8E1' },
    { key: 'form', label: 'Angaben', color: '#98ACBA' },
    { key: 'success', label: 'Fertig', color: '#CCC8CE' },
] as const
const currentIndex = computed(() => steps.findIndex((s) => s.key === w.step.value))

onMounted(async () => {
    try { services.value = await props.api.services() }
    catch { banner.value = 'Verbindungsfehler. Bitte erneut versuchen.' }
})

async function onService(s: Service) {
    w.chooseService(s)
    try { practitioners.value = await props.api.practitioners(s.id) }
    catch { banner.value = 'Verbindungsfehler. Bitte erneut versuchen.' }
}

async function onPractitioner(p: Practitioner) {
    w.choosePractitioner(p)
    const from = new Date().toISOString().slice(0, 10)
    const to = new Date(Date.now() + 60 * 864e5).toISOString().slice(0, 10)
    try { slots.value = await props.api.slots(p.id, w.selection.service!.id, from, to) }
    catch { banner.value = 'Verbindungsfehler. Bitte erneut versuchen.' }
}

async function onSubmit(formData: Record<string, unknown>) {
    if (loading.value) return
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
        if (result.value?.cancellation_token) w.complete()
    } catch (e: any) {
        if (e.kind === 'validation') serverErrors.value = e.errors
        else if (e.kind === 'slot_taken') { banner.value = 'Termin nicht mehr verfügbar.'; w.back() }
        else if (e.kind === 'rate_limited') banner.value = 'Zu viele Versuche, bitte später erneut.'
        else banner.value = 'Verbindungsfehler. Bitte erneut versuchen.'
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
    <div class="masinga-widget font-sans text-slate-800 max-w-md mx-auto antialiased">
        <div
            class="relative overflow-hidden rounded-[28px] bg-white shadow-[0_18px_50px_-20px_rgba(86,103,120,0.45)] ring-1 ring-slate-900/5"
        >
            <!-- Soft decorative pastel blobs behind the header -->
            <div class="pointer-events-none absolute -top-16 -right-10 h-44 w-44 rounded-full bg-kids-yellow/40 blur-2xl"></div>
            <div class="pointer-events-none absolute -top-10 -left-12 h-40 w-40 rounded-full bg-kids-peach/50 blur-2xl"></div>

            <!-- Header: identity + step progress -->
            <header class="relative px-6 pt-6 pb-5">
                <div class="flex items-center gap-3">
                    <span
                        class="grid h-11 w-11 place-items-center rounded-2xl bg-kids-blue/20 text-2xl shadow-inner"
                        aria-hidden="true"
                    >🦷</span>
                    <div class="leading-tight">
                        <p class="text-base font-bold tracking-tight text-slate-800">KidsClub</p>
                        <p class="text-xs font-medium text-slate-500">Termin online buchen</p>
                    </div>
                </div>

                <!-- Progress dots -->
                <ol class="mt-5 flex items-center gap-1.5" aria-label="Fortschritt">
                    <li
                        v-for="(s, i) in steps"
                        :key="s.key"
                        class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 transition-colors duration-500"
                    >
                        <span
                            class="block h-full rounded-full transition-all duration-500 ease-out"
                            :style="{
                                width: i <= currentIndex ? '100%' : '0%',
                                backgroundColor: s.color,
                            }"
                        ></span>
                    </li>
                </ol>
                <p class="mt-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                    Schritt {{ Math.min(currentIndex + 1, steps.length) }} von {{ steps.length }}
                    · {{ steps[currentIndex]?.label }}
                </p>
            </header>

            <!-- Body -->
            <div class="relative px-6 pb-7">
                <transition name="masinga-banner">
                    <div
                        v-if="banner"
                        class="mb-4 flex items-start gap-2 rounded-2xl bg-kids-yellow/40 px-4 py-3 text-sm font-medium text-amber-900 ring-1 ring-amber-300/40"
                        role="alert"
                    >
                        <span aria-hidden="true">⚠️</span>
                        <span>{{ banner }}</span>
                    </div>
                </transition>

                <ServiceStep v-if="w.step.value === 'service'" :services="services" @select="onService" />
                <PractitionerStep v-else-if="w.step.value === 'practitioner'" :practitioners="practitioners" @select="onPractitioner" />
                <SlotStep v-else-if="w.step.value === 'slot'" :slots="slots" @select="w.chooseSlot" />
                <FormStep v-else-if="w.step.value === 'form'" :server-errors="serverErrors" :loading="loading" @submit="onSubmit" />
                <SuccessStep v-else-if="w.step.value === 'success' && result" :result="result"
                             :cancelled="cancelled" @cancel="onCancel" />

                <button
                    v-if="w.step.value !== 'service' && w.step.value !== 'success'"
                    type="button"
                    @click="w.back()"
                    class="group mt-5 inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-kids-blue focus:outline-none focus-visible:ring-2 focus-visible:ring-kids-blue/60"
                >
                    <span class="transition-transform duration-200 group-hover:-translate-x-0.5" aria-hidden="true">←</span>
                    Zurück
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.masinga-banner-enter-active,
.masinga-banner-leave-active {
    transition: opacity 0.25s ease, transform 0.25s ease;
}
.masinga-banner-enter-from,
.masinga-banner-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}
</style>
