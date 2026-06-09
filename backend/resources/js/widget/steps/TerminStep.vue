<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import type { Service, Slot } from '../types'
import BookingCalendar from '../components/BookingCalendar.vue'

const props = defineProps<{
    services: Service[]
    selectedService?: Service
    availableDates: string[]
    slots: Slot[]
    loadingSlots: boolean
    selectedDate?: string
}>()
const emit = defineEmits<{
    'service-select': [service: Service]
    'month-change': [{ from: string; to: string }]
    'pick-date': [date: string]
    select: [slot: Slot]
}>()

const filterId = ref<number | null>(null) // null = Alle Behandler

const practitioners = computed(() => {
    const map = new Map<number, Slot['practitioner']>()
    for (const s of props.slots) map.set(s.practitioner.id, s.practitioner)
    return Array.from(map.values())
})

const visibleSlots = computed(() =>
    filterId.value == null ? props.slots : props.slots.filter(s => s.practitioner.id === filterId.value),
)

// Keep the client-side filter coherent if the parent replaces the day's slots
// without going through onPickDate (e.g. a same-day refetch).
watch(() => props.slots, () => { filterId.value = null })

const time = (iso: string) => iso.slice(11, 16) // HH:MM (backend ISO carries the +02:00 clinic offset)
const docLabel = (p: Slot['practitioner']) => `${p.title ? p.title + ' ' : ''}${p.first_name} ${p.last_name}`

function onPickDate(date: string) {
    filterId.value = null // reset the doctor filter when the day changes
    emit('pick-date', date)
}
</script>

<template>
    <div>
        <h2 class="text-[1.35rem] font-bold tracking-tight text-slate-900">Termin wählen</h2>
        <p class="mt-1 text-sm text-slate-400">Wählen Sie eine Leistung und einen freien Termin.</p>

        <div class="mt-5">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400 mb-2">Leistung</p>
            <div class="flex flex-col gap-2.5">
                <button v-for="s in services" :key="s.id" type="button" data-service :data-service-id="s.id"
                        @click="$emit('service-select', s)"
                        :class="['flex items-center justify-between rounded-2xl border px-4 py-4 text-sm text-left transition-all duration-200',
                                 selectedService?.id === s.id
                                   ? 'border-[#5A7A91] bg-[#EEF3F6] ring-2 ring-[#98ACBA]/40 shadow-sm'
                                   : 'border-slate-100 bg-white shadow-sm hover:-translate-y-0.5 hover:shadow-md hover:border-slate-200']">
                    <span class="flex items-center gap-2.5">
                        <span class="inline-block w-2 h-2 rounded-full shrink-0" :style="{ backgroundColor: s.color || '#98ACBA' }" aria-hidden="true"></span>
                        <span class="font-semibold text-slate-800">{{ s.name }}</span>
                    </span>
                    <span class="text-xs text-slate-400 shrink-0 ml-2">{{ s.duration_minutes }} Min.</span>
                </button>
            </div>
        </div>

        <template v-if="selectedService">
        <div class="mt-5">
            <BookingCalendar
                :available-dates="availableDates"
                :selected-date="selectedDate"
                @month-change="$emit('month-change', $event)"
                @select="onPickDate" />

            <p v-if="availableDates.length === 0" class="text-slate-500 mt-3 text-sm">Kein freier Termin verfügbar.</p>
        </div>

        <div v-if="selectedDate" class="mt-4">
            <div v-if="practitioners.length > 1" data-filters class="flex flex-wrap gap-2 mb-3">
                <button type="button" data-filter :data-filter-id="''"
                        @click="filterId = null"
                        :aria-pressed="filterId === null"
                        :class="['px-3.5 py-1.5 rounded-full border text-sm font-medium transition-all duration-150',
                                 filterId === null
                                   ? 'border-[#5A7A91] bg-[#5A7A91] text-white shadow-sm'
                                   : 'border-slate-200 bg-white text-slate-600 hover:border-[#98ACBA] hover:bg-[#EEF3F6]']">
                    Alle Behandler
                </button>
                <button v-for="p in practitioners" :key="p.id" type="button" data-filter :data-filter-id="p.id"
                        @click="filterId = p.id"
                        :aria-pressed="filterId === p.id"
                        :style="filterId === p.id ? { backgroundColor: p.color, borderColor: p.color, color: '#1E293B' } : {}"
                        :class="['px-3.5 py-1.5 rounded-full border text-sm font-medium transition-all duration-150',
                                 filterId !== p.id ? 'border-slate-200 bg-white text-slate-600 hover:border-[#98ACBA] hover:bg-[#EEF3F6]' : '']">
                    {{ docLabel(p) }}
                </button>
            </div>

            <p v-if="loadingSlots" class="text-slate-500 text-sm py-2">Lädt …</p>
            <p v-else-if="visibleSlots.length === 0" class="text-slate-500 text-sm py-2">Keine freien Termine an diesem Tag.</p>
            <div v-else class="flex flex-wrap gap-2 mt-1">
                <button v-for="s in visibleSlots" :key="s.starts_at + '-' + s.practitioner.id" type="button" data-slot
                        @click="$emit('select', s)"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-sm transition-all duration-150 hover:border-[#98ACBA] hover:bg-[#EEF3F6] hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#98ACBA]/60">
                    <span class="inline-block w-2 h-2 rounded-full shrink-0" :style="{ backgroundColor: s.practitioner.color }" aria-hidden="true"></span>
                    {{ time(s.starts_at) }} · {{ docLabel(s.practitioner) }}
                </button>
            </div>
        </div>
        </template>
    </div>
</template>
