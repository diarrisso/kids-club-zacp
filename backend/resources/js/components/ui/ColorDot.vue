<script setup lang="ts">
withDefaults(defineProps<{ color: string; size?: 'sm' | 'md' }>(), { size: 'md' })
</script>

<template>
  <span
    class="inline-block rounded-full"
    :class="size === 'sm' ? 'w-4 h-4' : 'w-6 h-6'"
    :style="{ background: color }"
  ></span>
</template>
