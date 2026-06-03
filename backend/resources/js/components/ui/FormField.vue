<script setup lang="ts">
defineProps<{
  label: string
  error?: string
  required?: boolean
  // Optional id of the control in the slot; when set, the label is associated to it.
  labelFor?: string
}>()
</script>

<template>
  <div>
    <label class="block text-sm font-medium mb-1" :for="labelFor">
      {{ label }}<span v-if="required"> *</span>
    </label>
    <slot />
    <div v-if="error" class="text-red-600 text-sm">{{ error }}</div>
  </div>
</template>
