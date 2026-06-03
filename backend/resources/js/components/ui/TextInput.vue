<script setup lang="ts">
defineProps<{ modelValue: string | number | null }>()
defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <!-- Number fields should use native <input type="number" v-model.number> in the page, not this component. -->
  <input
    :value="modelValue"
    class="w-full p-2 border rounded"
    @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
  />
</template>
