<script setup lang="ts">
import { ROOM_OPTIONS, type RoomOption } from '@/lib/rooms'

const props = withDefaults(
    defineProps<{ rooms?: RoomOption[] }>(),
    { rooms: () => ROOM_OPTIONS },
)
</script>

<template>
  <ul class="flex flex-wrap gap-x-4 gap-y-2 text-sm text-slate-600">
    <li v-for="room in props.rooms" :key="room.value" class="flex items-center gap-2">
      <span
        class="inline-block h-3 w-3 shrink-0 rounded-full border border-slate-200/80"
        :style="{ backgroundColor: room.color }"
      ></span>
      {{ room.label }}
    </li>
  </ul>
</template>
