<script setup lang="ts">
withDefaults(defineProps<{ as?: 'div' | 'form' }>(), { as: 'div' })
</script>

<template>
  <component :is="as" class="bg-white p-6 rounded shadow space-y-4">
    <slot />
  </component>
</template>
