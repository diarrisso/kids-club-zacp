<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import type { Service, Slot } from '../types'
import BookingCalendar from '../components/BookingCalendar.vue'
import ServiceSelect from '../components/ServiceSelect.vue'

const props = defineProps<{
    services: Service[]
    selectedService?: Service
    availableDates: string[]
    slots: Slot[]
    loadingSlots: boolean
    selectedDate?: string
    selectedSlot?: Slot
}>()
const emit = defineEmits<{
    'service-select': [service: Service]
    'month-change': [{ from: string; to: string }]
    'pick-date': [date: string]
    select: [slot: Slot]
    continue: []
    waitlist: []
}>()

const filterId = ref<number | null>(null) // null = Alle Behandler

const practitioners = computed(() => {
    const map = new Map<number, Slot['practitioner']>()
    for (const s of props.slots) map.set(s.practitioner.id, s.practitioner)
    return Array.from(map.values())
})

// The doctor filter is view-only: a selected slot stays selected (recap shows
// the practitioner) even when filtered out of the grid.
const visibleSlots = computed(() =>
    filterId.value == null ? props.slots : props.slots.filter(s => s.practitioner.id === filterId.value),
)

// Keep the client-side filter coherent if the parent replaces the day's slots
// without going through onPickDate (e.g. a same-day refetch).
watch(() => props.slots, () => { filterId.value = null })

const time = (iso: string) => iso.slice(11, 16) // HH:MM (backend ISO carries the +02:00 clinic offset)
const docLabel = (p: Slot['practitioner']) => `${p.title ? p.title + ' ' : ''}${p.first_name} ${p.last_name}`

const isSelected = (s: Slot) =>
    props.selectedSlot?.starts_at === s.starts_at && props.selectedSlot?.practitioner.id === s.practitioner.id

const recapLabel = computed(() => {
    const s = props.selectedSlot
    if (!s) return ''
    const d = new Date(s.starts_at.slice(0, 10) + 'T12:00:00').toLocaleDateString('de-DE', { weekday: 'short', day: 'numeric', month: 'short' })
    return `${d} · ${time(s.starts_at)} · ${s.practitioner.first_name}`
})

// Bring the recap + Weiter into view when a slot is first selected — on small
// screens the recap renders below the fold of the slot grid. Optional-called so
// jsdom (no scrollIntoView) stays happy.
const recapEl = ref<HTMLElement | null>(null)
watch(() => props.selectedSlot, (slot, prev) => {
    if (slot && !prev) nextTick(() => (recapEl.value as any)?.scrollIntoView?.({
        block: 'nearest',
        behavior: (typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches) ? 'auto' : 'smooth',
    }))
})

function onPickDate(date: string) {
    filterId.value = null // reset the doctor filter when the day changes
    emit('pick-date', date)
}
</script>

<template>
    <div>
        <h2 class="text-[1.35rem] font-bold tracking-tight text-widget-text">Termin wählen</h2>
        <p class="mt-1 text-sm text-slate-400">Wählen Sie eine Leistung und einen freien Termin.</p>

        <!-- Service selection -->
        <div class="mt-5">
            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400 mb-3">Leistung</p>
            <ServiceSelect :services="services" :model-value="selectedService"
                           @select="$emit('service-select', $event)" />
        </div>

        <template v-if="selectedService">
        <div class="mt-5">
            <BookingCalendar
                :key="selectedService?.id"
                :available-dates="availableDates"
                :selected-date="selectedDate"
                @month-change="$emit('month-change', $event)"
                @select="onPickDate" />

            <p v-if="availableDates.length === 0 && !loadingSlots" class="mt-3 flex items-center gap-2 text-sm text-widget-text/70">
                <svg class="h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd"/>
                </svg>
                Kein freier Termin verfügbar.
            </p>
            <button v-if="availableDates.length === 0 && !loadingSlots" type="button" @click="$emit('waitlist')"
                    class="mt-2 text-sm font-medium text-accent hover:underline">
                Auf die Warteliste →
            </button>
        </div>

        <div v-if="selectedDate" class="mt-4">
            <!-- Doctor filter pills -->
            <div v-if="practitioners.length > 1" data-filters class="flex flex-wrap gap-1.5 mb-3">
                <button type="button" data-filter :data-filter-id="''"
                        @click="filterId = null"
                        :aria-pressed="filterId === null"
                        :class="['inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-xs font-semibold transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/50',
                                 filterId === null
                                   ? 'border-accent bg-accent text-white shadow-sm'
                                   : 'border-slate-200 bg-widget-bg text-widget-text/70 hover:border-accent/40 hover:bg-tint hover:text-accent']">
                    <svg class="h-3 w-3" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 2a6 6 0 100 12A6 6 0 008 2zM1 8a7 7 0 1114 0A7 7 0 011 8z"/></svg>
                    Alle
                </button>
                <button v-for="p in practitioners" :key="p.id" type="button" data-filter :data-filter-id="p.id"
                        @click="filterId = p.id"
                        :aria-pressed="filterId === p.id"
                        :style="filterId === p.id ? { backgroundColor: p.color, borderColor: p.color, color: '#1E293B' } : {}"
                        :class="['inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-xs font-semibold transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/50',
                                 filterId !== p.id ? 'border-slate-200 bg-widget-bg text-widget-text/70 hover:border-accent/40 hover:bg-tint hover:text-accent' : '']">
                    <span class="inline-block h-2 w-2 rounded-full shrink-0" :style="{ backgroundColor: filterId === p.id ? '#1E293B' : p.color }" aria-hidden="true"></span>
                    {{ docLabel(p) }}
                </button>
            </div>

            <!-- Skeleton loader while slots load -->
            <div v-if="loadingSlots" aria-busy="true">
                <p class="sr-only">Lädt …</p>
                <div class="grid grid-cols-3 gap-2 mt-1" aria-hidden="true">
                    <div v-for="n in 6" :key="n"
                         class="h-10 rounded-xl animate-pulse"
                         style="background: linear-gradient(90deg, var(--masinga-tint) 25%, var(--masinga-tint-soft) 50%, var(--masinga-tint) 75%); background-size: 200% 100%;"></div>
                </div>
            </div>
            <p v-else-if="visibleSlots.length === 0" class="flex items-center gap-2 text-sm text-widget-text/70 py-2">
                <svg class="h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/>
                </svg>
                Keine freien Termine an diesem Tag.
            </p>
            <!-- Slot grid -->
            <div v-else class="grid grid-cols-3 gap-2 mt-1">
                <button v-for="s in visibleSlots" :key="s.starts_at + '-' + s.practitioner.id" type="button" data-slot
                        @click="$emit('select', s)"
                        :aria-pressed="isSelected(s) ? 'true' : 'false'"
                        :class="['flex flex-col items-center justify-center gap-0.5 rounded-xl border py-2.5 px-2 transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/40 active:translate-y-0',
                                 isSelected(s)
                                   ? 'border-accent bg-tint ring-2 ring-accent/20 shadow-md'
                                   : 'border-slate-200 bg-widget-bg hover:border-accent/50 hover:bg-tint hover:-translate-y-0.5 hover:shadow-sm']">
                    <span class="text-sm font-bold text-widget-text leading-tight">{{ time(s.starts_at) }}</span>
                    <span class="inline-flex items-center gap-1">
                        <span class="inline-block h-1.5 w-1.5 rounded-full shrink-0" :style="{ backgroundColor: s.practitioner.color }" aria-hidden="true"></span>
                        <span class="text-[10px] font-medium text-slate-400 leading-tight truncate max-w-[64px]">{{ s.practitioner.first_name }}</span>
                    </span>
                </button>
            </div>

            <div v-if="selectedSlot" ref="recapEl" class="mt-4 flex items-center justify-between gap-3 rounded-2xl bg-tint-soft px-4 py-3 ring-1 ring-accent/20">
                <p data-slot-recap class="text-sm font-semibold text-widget-text">{{ recapLabel }}</p>
                <button type="button" data-termin-weiter @click="$emit('continue')"
                        class="inline-flex shrink-0 items-center gap-2 rounded-2xl px-5 py-2.5 text-sm font-bold text-white shadow-md transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 focus-visible:ring-offset-2"
                        style="background: var(--masinga-gradient);">
                    Weiter
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
        </div>
        </template>
    </div>
</template>
