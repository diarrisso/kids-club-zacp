<script setup lang="ts">
defineProps<{
  label: string
  error?: string
  required?: boolean
}>()
</script>

<template>
  <div>
    <label class="block text-sm font-medium mb-1">
      {{ label }}<span v-if="required"> *</span>
    </label>
    <slot />
    <div v-if="error" class="text-red-600 text-sm">{{ error }}</div>
  </div>
</template>
