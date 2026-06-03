<script setup lang="ts">
withDefaults(defineProps<{
  type?: 'submit' | 'button'
  disabled?: boolean
}>(), { type: 'submit', disabled: false })
</script>

<template>
  <button
    :type="type"
    :disabled="disabled"
    class="bg-blue-700 text-white px-6 py-2 rounded hover:bg-blue-800 disabled:opacity-50"
  >
    <slot />
  </button>
</template>
