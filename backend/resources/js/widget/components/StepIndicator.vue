<script setup lang="ts">
import { computed } from 'vue'
import type { Step } from '../useWizard'

const props = defineProps<{ currentStep: Step }>()

const STEPS = [
  { key: 'termin',  label: 'Termin' },
  { key: 'kind',    label: 'Kind' },
  { key: 'form',    label: 'Elternteil' },
  { key: 'confirm', label: 'Bestätigen' },
]

const currentIndex = computed(() => STEPS.findIndex(s => s.key === props.currentStep))

type StepState = 'done' | 'active' | 'future'
const stateOf = (i: number): StepState => {
  if (i < currentIndex.value) return 'done'
  if (i === currentIndex.value) return 'active'
  return 'future'
}

// Fill: 0% / 33% / 67% / 100% (4 nodes = 3 intervals)
const fillWidth = computed(() => {
  const pct = [0, 33, 67, 100]
  return `${pct[currentIndex.value] ?? 0}%`
})
</script>

<template>
  <div class="px-1 py-3" role="list" aria-label="Buchungsfortschritt">
    <div class="relative flex items-center justify-between">
      <!-- Background track -->
      <div class="absolute inset-x-5 top-[18px] h-1.5 -translate-y-1/2 rounded-full bg-slate-100" aria-hidden="true">
        <!-- Filled progress overlay -->
        <div
          class="h-full rounded-full transition-all duration-600 ease-in-out"
          :style="{ width: fillWidth, background: 'linear-gradient(90deg, var(--masinga-primary) 0%, var(--masinga-primary-to) 100%)' }"
          aria-hidden="true"
        ></div>
      </div>

      <!-- Step nodes -->
      <div
        v-for="(step, i) in STEPS"
        :key="step.key"
        :data-step="step.key"
        :data-state="stateOf(i)"
        class="relative z-10 flex flex-col items-center gap-2"
        role="listitem"
      >
        <!-- Node circle -->
        <div
          class="flex h-9 w-9 items-center justify-center rounded-full transition-all duration-300"
          :class="{
            'text-white shadow-[0_4px_12px_-4px_rgb(var(--masinga-primary-rgb)_/_0.55)]': stateOf(i) === 'done',
            'text-white shadow-[0_6px_18px_-4px_rgb(var(--masinga-primary-rgb)_/_0.60)] scale-110': stateOf(i) === 'active',
            'bg-white border-2 border-slate-200 text-slate-400': stateOf(i) === 'future',
          }"
          :style="stateOf(i) === 'done'
            ? { background: 'linear-gradient(135deg, var(--masinga-primary) 0%, var(--masinga-primary-to) 100%)' }
            : stateOf(i) === 'active'
              ? { background: 'linear-gradient(135deg, var(--masinga-accent) 0%, var(--masinga-primary) 100%)', boxShadow: '0 0 0 4px rgb(var(--masinga-primary-rgb) / 0.18), 0 6px 18px -4px rgb(var(--masinga-primary-rgb) / 0.60)' }
              : {}"
          :aria-current="stateOf(i) === 'active' ? 'step' : undefined"
        >
          <!-- Checkmark for done steps -->
          <svg
            v-if="stateOf(i) === 'done'"
            class="h-4 w-4 text-white"
            viewBox="0 0 16 16"
            fill="none"
            aria-hidden="true"
          >
            <path d="M3.5 8.5 6.5 11.5 12.5 5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <!-- Step number for non-done steps -->
          <span v-else class="text-[11px] font-bold leading-none" aria-hidden="true">{{ i + 1 }}</span>
        </div>

        <!-- Label -->
        <span
          class="leading-none transition-all duration-200"
          :class="{
            'text-[11px] font-semibold text-accent': stateOf(i) === 'done',
            'text-[12px] font-bold text-accent': stateOf(i) === 'active',
            'text-[11px] font-medium text-slate-300': stateOf(i) === 'future',
          }"
        >{{ step.label }}</span>
      </div>
    </div>
  </div>
</template>
