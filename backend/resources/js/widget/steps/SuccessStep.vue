<script setup lang="ts">
import type { BookingResult } from '../types'
const props = defineProps<{ result: BookingResult; apiBase: string; tenant: string }>()
const cancelUrl = `${props.apiBase.replace(/\/$/, '')}/api/v1/widget/${props.tenant}/appointments/${props.result.cancellation_token}`
</script>

<template>
    <div class="text-center py-6">
        <h2 class="text-xl font-bold text-green-700 mb-2">Termin bestätigt!</h2>
        <p class="text-slate-600 mb-4">Sie erhalten in Kürze eine Bestätigung.</p>
        <p class="text-xs text-slate-400">Stornierungs-Referenz: {{ result.cancellation_token }}</p>
        <a :href="cancelUrl" class="text-sm text-blue-600 underline">Termin stornieren</a>
    </div>
</template>
