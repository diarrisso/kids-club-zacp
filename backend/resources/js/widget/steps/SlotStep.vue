<script setup lang="ts">
import { computed } from 'vue'
import type { Slot } from '../types'

const props = defineProps<{ slots: Slot[] }>()
defineEmits<{ select: [slot: Slot] }>()

const groups = computed(() => {
    const map = new Map<string, Slot[]>()
    for (const s of props.slots) {
        const date = s.starts_at.slice(0, 10) // YYYY-MM-DD from ISO
        if (!map.has(date)) map.set(date, [])
        map.get(date)!.push(s)
    }
    return Array.from(map, ([date, items]) => ({ date, items }))
})

const time = (iso: string) => iso.slice(11, 16) // HH:MM
const dateLabel = (d: string) =>
    new Date(d + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: 'long' })
</script>

<template>
    <div>
        <h2 class="text-xl font-bold tracking-tight text-slate-800">Termin wählen</h2>
        <p class="mt-1 text-sm text-slate-500">Such dir eine passende Uhrzeit aus.</p>

        <div
            v-if="groups.length === 0"
            class="mt-5 flex flex-col items-center gap-2 rounded-2xl bg-kids-peach/40 px-6 py-8 text-center"
        >
            <span class="text-3xl" aria-hidden="true">🗓️</span>
            <p class="font-semibold text-slate-700">Keine freien Termine in diesem Zeitraum.</p>
            <p class="text-sm text-slate-500">Bitte versuche es später noch einmal.</p>
        </div>

        <div v-for="g in groups" :key="g.date" data-date-group class="mt-5">
            <h3 class="mb-2.5 flex items-center gap-2 text-sm font-bold text-slate-700">
                <span class="h-2 w-2 rounded-full bg-kids-blue" aria-hidden="true"></span>
                {{ dateLabel(g.date) }}
            </h3>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="s in g.items"
                    :key="s.starts_at"
                    type="button"
                    data-slot
                    @click="$emit('select', s)"
                    class="rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition-all duration-150 hover:-translate-y-0.5 hover:border-kids-blue hover:bg-kids-blue/15 hover:text-slate-900 active:translate-y-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-kids-blue/60"
                >
                    {{ time(s.starts_at) }}
                </button>
            </div>
        </div>
    </div>
</template>
