<script setup lang="ts">
import type { Practitioner } from '../types'
defineProps<{ practitioners: Practitioner[] }>()
defineEmits<{ select: [practitioner: Practitioner] }>()
</script>

<template>
    <div>
        <h2 class="text-lg font-bold mb-4">Behandler wählen</h2>
        <ul class="space-y-2">
            <li v-for="p in practitioners" :key="p.id">
                <button type="button" @click="$emit('select', p)"
                        class="w-full text-left p-3 border rounded hover:bg-blue-50">
                    <span v-if="p.title">{{ p.title }} </span>{{ p.first_name }} {{ p.last_name }}
                </button>
            </li>
        </ul>
    </div>
</template>
