<script setup lang="ts">
import type { BookingResult } from '../types'
defineProps<{ result: BookingResult; cancelled: boolean }>()
defineEmits<{ cancel: [] }>()
</script>

<template>
    <div class="py-6 text-center">
        <template v-if="!cancelled">
            <!-- Check badge -->
            <div
                class="mx-auto grid h-20 w-20 place-items-center rounded-full text-4xl shadow-[inset_0_2px_8px_rgba(90,122,145,0.15)]"
                style="background: linear-gradient(135deg, #C5D4DC 0%, #98ACBA 100%);"
                aria-hidden="true"
            >
                <span class="masinga-pop">✅</span>
            </div>
            <h2 class="mt-5 text-2xl font-bold tracking-tight text-slate-900">Termin bestätigt!</h2>
            <p class="mt-1.5 text-sm text-slate-400">Sie erhalten in Kürze eine Bestätigung per E-Mail.</p>

            <!-- Token card -->
            <div class="mx-auto mt-5 max-w-xs rounded-2xl bg-gradient-to-br from-[#98ACBA]/10 to-[#98ACBA]/5 px-4 py-3.5 ring-1 ring-[#98ACBA]/20">
                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Stornierungs-Referenz</p>
                <p class="mt-1.5 break-all font-mono text-sm font-semibold text-[#5A7A91]">{{ result.cancellation_token }}</p>
            </div>

            <button
                type="button"
                @click="$emit('cancel')"
                class="mt-5 inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-500 shadow-sm transition-all duration-200 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-600 hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-300 focus-visible:ring-offset-2"
            >
                <span aria-hidden="true">✕</span> Termin stornieren
            </button>
        </template>

        <template v-else>
            <div
                class="mx-auto grid h-20 w-20 place-items-center rounded-full bg-slate-100 text-4xl shadow-inner"
                aria-hidden="true"
            >🗓️</div>
            <h2 class="mt-5 text-2xl font-bold tracking-tight text-slate-700">Termin storniert</h2>
            <p class="mt-1.5 text-sm text-slate-400">Ihr Termin wurde abgesagt. Bis bald!</p>
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
