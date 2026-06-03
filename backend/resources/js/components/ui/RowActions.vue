<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
defineProps<{ editHref: string }>()
defineEmits<{ delete: [] }>()
</script>

<template>
  <div class="text-right">
    <Link :href="editHref" class="text-blue-600 mr-3">Bearbeiten</Link>
    <button class="text-red-600" @click="$emit('delete')">Löschen</button>
  </div>
</template>
