<script setup lang="ts">
import type { Service } from '../types'
defineProps<{ services: Service[] }>()
defineEmits<{ select: [service: Service] }>()
</script>

<template>
    <div>
        <h2 class="text-lg font-bold mb-4">Leistung wählen</h2>
        <ul class="space-y-2">
            <li v-for="s in services" :key="s.id">
                <button type="button" @click="$emit('select', s)"
                        class="w-full text-left p-3 border rounded hover:bg-blue-50">
                    <span class="font-medium">{{ s.name }}</span>
                    <span class="text-sm text-slate-500"> · {{ s.duration_minutes }} Min.</span>
                </button>
            </li>
        </ul>
    </div>
</template>
