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
        <h2 class="text-lg font-bold mb-4">Termin wählen</h2>

        <div class="mb-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2">Leistung</p>
            <div class="flex flex-col gap-2">
                <button v-for="s in services" :key="s.id" type="button" data-service :data-service-id="s.id"
                        @click="$emit('service-select', s)"
                        :class="['flex items-center justify-between rounded-xl px-3.5 py-2.5 text-sm text-left transition-all duration-200',
                                 selectedService?.id === s.id
                                   ? 'bg-slate-800 text-white shadow-md'
                                   : 'bg-white border border-slate-100 text-slate-700 shadow-sm hover:-translate-y-0.5']">
                    <span class="font-semibold">{{ s.name }}</span>
                    <span class="text-xs opacity-70">{{ s.duration_minutes }} Min.</span>
                </button>
            </div>
        </div>

        <template v-if="selectedService">
        <BookingCalendar
            :available-dates="availableDates"
            :selected-date="selectedDate"
            @month-change="$emit('month-change', $event)"
            @select="onPickDate" />

        <p v-if="availableDates.length === 0" class="text-slate-500 mt-3">Kein freier Termin verfügbar.</p>

        <div v-if="selectedDate" class="mt-4">
            <div v-if="practitioners.length > 1" data-filters class="flex flex-wrap gap-2 mb-3">
                <button type="button" data-filter :data-filter-id="''"
                        @click="filterId = null"
                        :aria-pressed="filterId === null"
                        :class="['px-3 py-1 rounded-full border text-sm',
                                 filterId === null ? 'bg-slate-800 text-white border-slate-800' : 'bg-white']">
                    Alle Behandler
                </button>
                <button v-for="p in practitioners" :key="p.id" type="button" data-filter :data-filter-id="p.id"
                        @click="filterId = p.id"
                        :aria-pressed="filterId === p.id"
                        :style="filterId === p.id ? { backgroundColor: p.color, color: '#1E293B', borderColor: p.color } : {}"
                        class="px-3 py-1 rounded-full border text-sm bg-white">
                    {{ docLabel(p) }}
                </button>
            </div>

            <p v-if="loadingSlots" class="text-slate-500">Lädt …</p>
            <p v-else-if="visibleSlots.length === 0" class="text-slate-500">Keine freien Termine an diesem Tag.</p>
            <div v-else class="flex flex-wrap gap-2">
                <button v-for="s in visibleSlots" :key="s.starts_at + '-' + s.practitioner.id" type="button" data-slot
                        @click="$emit('select', s)"
                        class="px-3 py-2 border rounded hover:bg-blue-50 flex items-center gap-2">
                    <span class="inline-block w-2 h-2 rounded-full" :style="{ backgroundColor: s.practitioner.color }" aria-hidden="true"></span>
                    {{ time(s.starts_at) }} · {{ docLabel(s.practitioner) }}
                </button>
            </div>
        </div>
        </template>
    </div>
</template>
