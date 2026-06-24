<script setup lang="ts">
defineProps<{ value: string | null | undefined }>()
const emit = defineEmits<{ (e: 'change', v: string | null): void }>()

const toggle = (action: 'arrived' | 'no_show', current: string | null | undefined) => {
    // Click again on the active pill → clear (null)
    emit('change', current === action ? null : action)
}
</script>

<template>
  <div class="flex items-center gap-1.5">
    <!-- arrived pill -->
    <button
      type="button"
      :class="[
        'inline-flex h-7 w-7 items-center justify-center rounded-full text-sm transition-all',
        value === 'arrived'
          ? 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-300'
          : 'bg-slate-100 text-slate-400 hover:bg-emerald-50 hover:text-emerald-600',
      ]"
      :aria-pressed="value === 'arrived'"
      :title="value === 'arrived' ? 'Anwesend — klicken zum Zurücksetzen' : 'Als anwesend markieren'"
      @click="toggle('arrived', value)"
    >✓</button>

    <!-- no_show pill -->
    <button
      type="button"
      :class="[
        'inline-flex h-7 w-7 items-center justify-center rounded-full text-sm transition-all',
        value === 'no_show'
          ? 'bg-rose-100 text-rose-700 ring-1 ring-rose-300'
          : 'bg-slate-100 text-slate-400 hover:bg-rose-50 hover:text-rose-600',
      ]"
      :aria-pressed="value === 'no_show'"
      :title="value === 'no_show' ? 'Nicht erschienen — klicken zum Zurücksetzen' : 'Als nicht erschienen markieren'"
      @click="toggle('no_show', value)"
    >✗</button>
  </div>
</template>
