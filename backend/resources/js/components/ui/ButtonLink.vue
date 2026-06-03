<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
defineProps<{ href: string }>()
</script>

<template>
  <Link :href="href" class="bg-blue-700 text-white px-4 py-2 rounded hover:bg-blue-800">
    <slot />
  </Link>
</template>
