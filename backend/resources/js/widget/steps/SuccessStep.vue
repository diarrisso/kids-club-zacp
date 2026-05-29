<script setup lang="ts">
import type { BookingResult } from '../types'
defineProps<{ result: BookingResult; cancelled: boolean }>()
defineEmits<{ cancel: [] }>()
</script>

<template>
    <div class="text-center py-6">
        <template v-if="!cancelled">
            <h2 class="text-xl font-bold text-green-700 mb-2">Termin bestätigt!</h2>
            <p class="text-slate-600 mb-4">Sie erhalten in Kürze eine Bestätigung.</p>
            <p class="text-xs text-slate-400 mb-3">Stornierungs-Referenz: {{ result.cancellation_token }}</p>
            <button type="button" @click="$emit('cancel')" class="text-sm text-blue-600 underline">Termin stornieren</button>
        </template>
        <template v-else>
            <h2 class="text-xl font-bold text-slate-700 mb-2">Termin storniert</h2>
            <p class="text-slate-600">Ihr Termin wurde abgesagt.</p>
        </template>
    </div>
</template>
