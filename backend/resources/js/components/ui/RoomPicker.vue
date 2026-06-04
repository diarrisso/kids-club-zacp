<script setup lang="ts">
interface RoomOption { value: string; color: string; label: string }

const props = defineProps<{
  rooms: RoomOption[]
  modelValue: string | null
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>()

/** Select a room, or clear it when the already-active swatch is clicked (optional → neutral). */
function pick(value: string) {
  emit('update:modelValue', props.modelValue === value ? null : value)
}
</script>

<template>
  <div class="flex gap-2" role="group" aria-label="Zimmerfarbe">
    <button
      v-for="room in rooms"
      :key="room.value"
      type="button"
      :data-room="room.value"
      :title="room.label"
      :aria-label="room.label"
      :aria-pressed="modelValue === room.value"
      class="h-9 w-9 rounded-full border border-slate-300 transition focus:outline-none focus:ring-2 focus:ring-slate-400"
      :class="modelValue === room.value ? 'ring-2 ring-offset-2 ring-slate-700' : ''"
      :style="{ backgroundColor: room.color }"
      @click="pick(room.value)"
    >
      <span v-if="modelValue === room.value" class="text-slate-800 text-sm">✓</span>
    </button>
  </div>
</template>
