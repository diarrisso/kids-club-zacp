<script setup lang="ts">
import type { BookingResult } from '../types'
defineProps<{ result: BookingResult; cancelled: boolean }>()
defineEmits<{ cancel: [] }>()
</script>

<template>
    <div class="py-6 text-center">
        <template v-if="!cancelled">
            <!-- Animated check badge with outer ring pulse -->
            <div class="relative mx-auto w-24 h-24 flex items-center justify-center" aria-hidden="true">
                <!-- Outer halo ring -->
                <div class="absolute inset-0 rounded-full masinga-halo"
                     style="background: radial-gradient(circle, rgb(var(--masinga-primary-rgb) / 0.15) 0%, rgb(var(--masinga-primary-rgb) / 0) 70%);"></div>
                <!-- Main badge -->
                <div
                    class="masinga-badge relative z-10 flex h-20 w-20 items-center justify-center rounded-full shadow-[0_12px_32px_-8px_rgb(var(--masinga-primary-rgb)_/_0.50)]"
                    style="background: var(--masinga-gradient);"
                >
                    <!-- SVG checkmark animated via stroke-dashoffset -->
                    <svg class="h-9 w-9" viewBox="0 0 36 36" fill="none" aria-hidden="true">
                        <path
                            class="masinga-check"
                            d="M9 18.5 15.5 25 27 13"
                            stroke="white"
                            stroke-width="3"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </div>
            </div>

            <h2 class="mt-5 text-2xl font-bold tracking-tight text-widget-text">Termin bestätigt!</h2>
            <p class="mt-1.5 text-sm text-slate-400">Sie erhalten in Kürze eine Bestätigung per E-Mail.</p>

            <!-- Token card — compact, subtle -->
            <div class="mx-auto mt-5 max-w-xs rounded-xl bg-slate-50 px-3.5 py-2.5 ring-1 ring-slate-200/80">
                <p class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400">Stornierungs-Referenz</p>
                <p class="mt-1 break-all font-mono text-[11px] font-medium text-widget-text/70">{{ result.cancellation_token }}</p>
            </div>

            <button
                type="button"
                @click="$emit('cancel')"
                class="mt-5 inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-400 shadow-sm transition-all duration-200 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-500 hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-300 focus-visible:ring-offset-2"
            >
                <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M8 15A7 7 0 108 1a7 7 0 000 14zm2.78-4.22a.75.75 0 01-1.06 1.06L8 11.06l-1.72 1.72a.75.75 0 01-1.06-1.06L6.94 10 5.22 8.28a.75.75 0 011.06-1.06L8 8.94l1.72-1.72a.75.75 0 111.06 1.06L9.06 10l1.72 1.72z" clip-rule="evenodd"/>
                </svg>
                Termin stornieren
            </button>
        </template>

        <template v-else>
            <!-- Cancelled state -->
            <div
                class="mx-auto flex h-20 w-20 items-center justify-center rounded-full shadow-inner"
                style="background: linear-gradient(135deg, #e2e8ec 0%, #cbd5db 100%);"
                aria-hidden="true"
            >
                <svg class="h-10 w-10 text-slate-400" viewBox="0 0 24 24" fill="currentColor">
                    <path fill-rule="evenodd" d="M6.75 2.25A.75.75 0 017.5 3v1.5h9V3A.75.75 0 0118 3v1.5h.75a3 3 0 013 3v11.25a3 3 0 01-3 3H5.25a3 3 0 01-3-3V7.5a3 3 0 013-3H6V3a.75.75 0 01.75-.75zm13.5 9a1.5 1.5 0 00-1.5-1.5H5.25a1.5 1.5 0 00-1.5 1.5v7.5a1.5 1.5 0 001.5 1.5h13.5a1.5 1.5 0 001.5-1.5v-7.5zm-6.97 1.72a.75.75 0 10-1.06-1.06L10.5 13.94l-1.22-1.22a.75.75 0 00-1.06 1.06l1.72 1.72-1.72 1.72a.75.75 0 101.06 1.06l1.22-1.22 1.22 1.22a.75.75 0 101.06-1.06l-1.72-1.72 1.72-1.72z" clip-rule="evenodd"/>
                </svg>
            </div>
            <h2 class="mt-5 text-2xl font-bold tracking-tight text-widget-text/70">Termin storniert</h2>
            <p class="mt-1.5 text-sm text-slate-400">Ihr Termin wurde abgesagt. Bis bald!</p>
        </template>
    </div>
</template>

<style scoped>
/* Badge entrance — scale with spring bounce */
.masinga-badge {
    animation: masinga-badge-in 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}
@keyframes masinga-badge-in {
    0%   { transform: scale(0) rotate(-10deg); opacity: 0; }
    60%  { transform: scale(1.12) rotate(2deg); opacity: 1; }
    100% { transform: scale(1) rotate(0deg); }
}

/* SVG checkmark draw */
.masinga-check {
    stroke-dasharray: 32;
    stroke-dashoffset: 32;
    animation: masinga-draw 0.4s 0.35s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}
@keyframes masinga-draw {
    to { stroke-dashoffset: 0; }
}

/* Outer halo radial pulse */
.masinga-halo {
    animation: masinga-halo-pulse 0.7s 0.3s ease-out both;
}
@keyframes masinga-halo-pulse {
    0%   { transform: scale(0.5); opacity: 0; }
    60%  { transform: scale(1.3); opacity: 1; }
    100% { transform: scale(1.0); opacity: 0.6; }
}

@media (prefers-reduced-motion: reduce) {
    .masinga-badge,
    .masinga-check,
    .masinga-halo {
        animation: none;
    }
    .masinga-check { stroke-dashoffset: 0; }
}
</style>
