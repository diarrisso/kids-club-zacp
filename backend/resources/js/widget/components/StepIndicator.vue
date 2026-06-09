<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ currentStep: string }>()

const STEPS = [
  { key: 'termin',  label: 'Termin' },
  { key: 'form',    label: 'Angaben' },
  { key: 'confirm', label: 'Bestätigen' },
]

const currentIndex = computed(() => STEPS.findIndex(s => s.key === props.currentStep))

type StepState = 'done' | 'active' | 'future'
const stateOf = (i: number): StepState => {
  if (i < currentIndex.value) return 'done'
  if (i === currentIndex.value) return 'active'
  return 'future'
}

// The fill width of the connecting line: 0% / 50% / 100%
const fillWidth = computed(() => {
  const pct = [0, 50, 100]
  return `${pct[currentIndex.value] ?? 0}%`
})
</script>

<template>
  <div class="px-1 py-2" role="list" aria-label="Buchungsfortschritt">
    <!-- Track: positioned so the line runs through the node centres -->
    <div class="relative flex items-center justify-between">
      <!-- Background track (full width between first and last node) -->
      <div class="absolute inset-x-5 top-1/2 h-1 -translate-y-1/2 rounded-full bg-slate-200" aria-hidden="true">
        <!-- Filled progress overlay -->
        <div
          class="h-full rounded-full transition-all duration-500"
          style="background-color: #5A7A91;"
          :style="{ width: fillWidth, backgroundColor: '#5A7A91' }"
          aria-hidden="true"
        ></div>
      </div>

      <!-- Step nodes -->
      <div
        v-for="(step, i) in STEPS"
        :key="step.key"
        :data-step="step.key"
        :data-state="stateOf(i)"
        class="relative z-10 flex flex-col items-center gap-1.5"
        role="listitem"
      >
        <!-- Node circle -->
        <div
          class="flex h-8 w-8 items-center justify-center rounded-full transition-all duration-300"
          :class="{
            // done — kids-blue deep fill
            'text-white shadow-[0_4px_10px_-4px_rgba(90,122,145,0.55)]': stateOf(i) === 'done',
            // active — kids-blue deep fill + halo ring
            'text-white ring-4 ring-kids-blue/30 shadow-[0_4px_14px_-4px_rgba(90,122,145,0.50)] scale-110': stateOf(i) === 'active',
            // future — white + light border
            'bg-white border-2 border-slate-200 text-slate-400': stateOf(i) === 'future',
          }"
          :style="stateOf(i) !== 'future' ? { backgroundColor: '#5A7A91' } : {}"
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
            <path d="M3.5 8.5 6.5 11.5 12.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <!-- Step number for non-done steps -->
          <span v-else class="text-[11px] font-bold leading-none" aria-hidden="true">{{ i + 1 }}</span>
        </div>

        <!-- Label -->
        <span
          class="text-[11px] font-semibold leading-none transition-colors duration-200"
          :class="{
            'text-[#5A7A91]': stateOf(i) === 'done' || stateOf(i) === 'active',
            'text-slate-300': stateOf(i) === 'future',
          }"
        >{{ step.label }}</span>
      </div>
    </div>
  </div>
</template>
