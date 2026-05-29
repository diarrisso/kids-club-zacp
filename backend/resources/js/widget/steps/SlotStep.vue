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
        <h2 class="text-lg font-bold mb-4">Termin wählen</h2>
        <p v-if="groups.length === 0" class="text-slate-500">Keine freien Termine in diesem Zeitraum.</p>
        <div v-for="g in groups" :key="g.date" data-date-group class="mb-4">
            <h3 class="font-medium mb-2">{{ dateLabel(g.date) }}</h3>
            <div class="flex flex-wrap gap-2">
                <button v-for="s in g.items" :key="s.starts_at" type="button" data-slot
                        @click="$emit('select', s)"
                        class="px-3 py-2 border rounded hover:bg-blue-50">
                    {{ time(s.starts_at) }}
                </button>
            </div>
        </div>
    </div>
</template>
