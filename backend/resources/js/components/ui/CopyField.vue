<script setup lang="ts">
import { ref } from 'vue'

const props = defineProps<{ value: string }>()

const copied = ref(false)

async function copy() {
  try {
    await navigator.clipboard.writeText(props.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
  } catch {
    // Clipboard API unavailable (non-secure context) — fail quietly; the field stays selectable.
  }
}
</script>

<template>
  <div class="flex gap-2">
    <input :value="value" readonly class="w-full rounded border px-3 py-2 bg-gray-50" />
    <button
      type="button"
      class="rounded border px-3 py-2 whitespace-nowrap hover:bg-slate-50"
      @click="copy"
    >
      {{ copied ? 'Kopiert ✓' : 'Kopieren' }}
    </button>
  </div>
</template>
