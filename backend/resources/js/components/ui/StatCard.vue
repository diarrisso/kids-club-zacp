<script setup lang="ts">
import type { Component } from 'vue'

defineProps<{
  icon: Component
  value: string | number
  label: string
  // Tailwind background class for the icon pill, e.g. 'bg-kids-blue'
  color: string
}>()
</script>

<template>
  <div
    class="group relative overflow-hidden rounded-3xl border border-slate-200/70 bg-white p-5
           shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-all duration-300 ease-out
           hover:-translate-y-0.5 hover:shadow-[0_12px_28px_-12px_rgba(15,23,42,0.18)]
           motion-reduce:transition-none motion-reduce:hover:translate-y-0"
  >
    <!-- soft pastel glow that echoes the icon color, only on hover -->
    <span
      aria-hidden="true"
      class="pointer-events-none absolute -right-6 -top-8 h-24 w-24 rounded-full opacity-0
             blur-2xl transition-opacity duration-500 group-hover:opacity-60"
      :class="color"
    ></span>

    <div class="relative flex items-center gap-4">
      <div
        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl
               ring-1 ring-inset ring-white/50 transition-transform duration-300
               group-hover:scale-105 group-hover:rotate-[-4deg]
               motion-reduce:transition-none motion-reduce:group-hover:scale-100 motion-reduce:group-hover:rotate-0"
        :class="color"
      >
        <component :is="icon" class="h-6 w-6 text-slate-700" :stroke-width="1.75" />
      </div>
      <div class="min-w-0">
        <div class="truncate text-[1.35rem] font-bold leading-tight tracking-tight text-slate-800">
          {{ value }}
        </div>
        <div class="text-sm font-medium text-slate-500">{{ label }}</div>
      </div>
    </div>
  </div>
</template>
