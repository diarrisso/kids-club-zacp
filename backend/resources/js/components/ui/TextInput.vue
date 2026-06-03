<script setup lang="ts">
defineProps<{ modelValue: string | number | null }>()
defineEmits<{ 'update:modelValue': [value: string | number] }>()
</script>

<template>
  <input
    :value="modelValue"
    class="w-full p-2 border rounded"
    @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
  />
</template>
