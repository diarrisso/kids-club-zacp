<script setup lang="ts">
import type { BookingResult } from '../types'
defineProps<{ result: BookingResult; cancelled: boolean }>()
defineEmits<{ cancel: [] }>()
</script>

<template>
    <div class="py-4 text-center">
        <template v-if="!cancelled">
            <div
                class="mx-auto grid h-20 w-20 place-items-center rounded-full bg-kids-green/30 text-4xl shadow-inner"
                aria-hidden="true"
            >
                <span class="masinga-pop">✅</span>
            </div>
            <h2 class="mt-4 text-2xl font-bold tracking-tight text-slate-800">Termin bestätigt!</h2>
            <p class="mt-1.5 text-sm text-slate-500">Sie erhalten in Kürze eine Bestätigung per E-Mail.</p>

            <div class="mx-auto mt-5 max-w-xs rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-slate-100">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Stornierungs-Referenz</p>
                <p class="mt-1 break-all font-mono text-sm font-semibold text-slate-700">{{ result.cancellation_token }}</p>
            </div>

            <button
                type="button"
                @click="$emit('cancel')"
                class="mt-5 inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-semibold text-slate-500 transition hover:bg-rose-50 hover:text-rose-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-300"
            >
                <span aria-hidden="true">✕</span> Termin stornieren
            </button>
        </template>

        <template v-else>
            <div
                class="mx-auto grid h-20 w-20 place-items-center rounded-full bg-kids-peach/50 text-4xl shadow-inner"
                aria-hidden="true"
            >🗓️</div>
            <h2 class="mt-4 text-2xl font-bold tracking-tight text-slate-700">Termin storniert</h2>
            <p class="mt-1.5 text-sm text-slate-500">Ihr Termin wurde abgesagt. Bis bald!</p>
        </template>
    </div>
</template>

<style scoped>
.masinga-pop {
    display: inline-block;
    animation: masinga-pop 0.45s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}
@keyframes masinga-pop {
    0% { transform: scale(0); }
    100% { transform: scale(1); }
}
@media (prefers-reduced-motion: reduce) {
    .masinga-pop { animation: none; }
}
</style>
