<script setup lang="ts">
defineProps<{ title: string; subtitle?: string }>()
</script>

<template>
  <div class="flex items-start justify-between">
    <div>
      <h1 class="text-3xl font-bold tracking-tight text-slate-900">{{ title }}</h1>
      <p v-if="subtitle" class="mt-0.5 text-sm text-slate-400">{{ subtitle }}</p>
    </div>
    <div class="flex items-center gap-2">
      <slot name="action" />
    </div>
  </div>
</template>
