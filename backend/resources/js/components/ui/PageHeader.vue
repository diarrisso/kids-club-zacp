<script setup lang="ts">
defineProps<{ title: string }>()
</script>

<template>
  <div class="flex justify-between items-center mb-6">
    <h1 class="text-3xl font-bold">{{ title }}</h1>
    <slot name="action" />
  </div>
</template>
