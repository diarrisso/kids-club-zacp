<script setup lang="ts">
interface RoomOption { value: string; color: string; label: string }
defineProps<{ rooms: RoomOption[] }>()
</script>

<template>
  <ul class="flex flex-wrap gap-x-4 gap-y-2 text-sm text-slate-600">
    <li v-for="room in rooms" :key="room.value" class="flex items-center gap-2">
      <span class="inline-block h-3 w-3 rounded-full border border-slate-300" :style="{ backgroundColor: room.color }"></span>
      {{ room.label }}
    </li>
  </ul>
</template>
