<script setup lang="ts">
defineProps<{ active: boolean }>()
</script>

<template>
  <span v-if="active" class="text-green-600">Aktiv</span>
  <span v-else class="text-slate-400">Inaktiv</span>
</template>
