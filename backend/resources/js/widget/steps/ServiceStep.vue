<script setup lang="ts">
import type { Service } from '../types'

const props = defineProps<{ services: Service[] }>()
defineEmits<{ select: [service: Service] }>()

// Cycle the pastel palette so each card gets a friendly, distinct accent.
const palette = ['#BDCCC2', '#F7E29D', '#FCE8E1', '#98ACBA', '#CCC8CE']
const accent = (s: Service, i: number) => s.color ?? palette[i % palette.length]
</script>

<template>
    <div>
        <h2 class="text-xl font-bold tracking-tight text-slate-800">Leistung wählen</h2>
        <p class="mt-1 text-sm text-slate-500">Wofür möchtest du einen Termin?</p>

        <ul class="mt-4 space-y-2.5">
            <li v-for="(s, i) in props.services" :key="s.id">
                <button
                    type="button"
                    @click="$emit('select', s)"
                    class="group flex w-full items-center gap-3 rounded-2xl border border-slate-100 bg-white p-3.5 text-left shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-transparent hover:shadow-[0_12px_28px_-12px_rgba(86,103,120,0.5)] focus:outline-none focus-visible:ring-2 focus-visible:ring-kids-blue/60"
                >
                    <span
                        class="h-10 w-10 shrink-0 rounded-xl ring-4 ring-inset ring-white/60 transition-transform duration-200 group-hover:scale-105"
                        :style="{ backgroundColor: accent(s, i) }"
                        aria-hidden="true"
                    ></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold text-slate-800">{{ s.name }}</span>
                        <span class="mt-0.5 inline-flex items-center gap-1 text-xs font-medium text-slate-500">
                            <span aria-hidden="true">⏱</span>{{ s.duration_minutes }} Min.
                        </span>
                    </span>
                    <span
                        class="text-slate-300 transition-all duration-200 group-hover:translate-x-0.5 group-hover:text-kids-blue"
                        aria-hidden="true"
                    >→</span>
                </button>
            </li>
        </ul>
    </div>
</template>
