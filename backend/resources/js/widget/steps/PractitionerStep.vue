<script setup lang="ts">
import type { Practitioner } from '../types'

const props = defineProps<{ practitioners: Practitioner[] }>()
defineEmits<{ select: [practitioner: Practitioner] }>()

const palette = ['#BDCCC2', '#F7E29D', '#FCE8E1', '#98ACBA', '#CCC8CE']
const accent = (p: Practitioner, i: number) => p.color ?? palette[i % palette.length]
const initials = (p: Practitioner) =>
    `${p.first_name?.[0] ?? ''}${p.last_name?.[0] ?? ''}`.toUpperCase()
</script>

<template>
    <div>
        <h2 class="text-xl font-bold tracking-tight text-slate-800">Behandler wählen</h2>
        <p class="mt-1 text-sm text-slate-500">Wer soll sich um euch kümmern?</p>

        <ul class="mt-4 space-y-2.5">
            <li v-for="(p, i) in props.practitioners" :key="p.id">
                <button
                    type="button"
                    @click="$emit('select', p)"
                    class="group flex w-full items-center gap-3 rounded-2xl border border-slate-100 bg-white p-3.5 text-left shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-transparent hover:shadow-[0_12px_28px_-12px_rgba(86,103,120,0.5)] focus:outline-none focus-visible:ring-2 focus-visible:ring-kids-blue/60"
                >
                    <span
                        class="grid h-11 w-11 shrink-0 place-items-center rounded-full text-sm font-bold text-slate-700 ring-4 ring-inset ring-white/60 transition-transform duration-200 group-hover:scale-105"
                        :style="{ backgroundColor: accent(p, i) }"
                        aria-hidden="true"
                    >{{ initials(p) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold text-slate-800">
                            <span v-if="p.title">{{ p.title }} </span>{{ p.first_name }} {{ p.last_name }}
                        </span>
                        <span class="mt-0.5 block text-xs font-medium text-slate-500">Kinderzahnheilkunde</span>
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
