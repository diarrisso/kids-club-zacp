<script setup lang="ts">
import { ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
import type { BookingResult } from '../types'
const props = defineProps<{ result: BookingResult; cancelled: boolean; cancelling?: boolean }>()
const emit = defineEmits<{ cancel: []; restart: []; close: [] }>()
const confirmingCancel = ref(false)
const done = ref(false)

const cancelOpenBtn = ref<HTMLButtonElement | null>(null)
const confirmBtn = ref<HTMLButtonElement | null>(null)

// After a confirmed booking, auto-close the host modal: a passive user who walked
// away shouldn't leave a popup open forever. We only EMIT 'close' — App turns it
// into a composed `masinga:close` DOM event the embedding page listens for. The
// timer is killed the moment the user interacts (cancel flow, restart, done) so we
// never yank the modal away mid-action, and never runs on the cancelled state.
const AUTO_CLOSE_SECONDS = 20
const secondsLeft = ref(AUTO_CLOSE_SECONDS)
const autoClosing = ref(!props.cancelled) // reactive + correct on first render (no tick needed)
let timer: ReturnType<typeof setInterval> | undefined

function stopAutoClose() {
    autoClosing.value = false
    if (timer) {
        clearInterval(timer)
        timer = undefined
    }
}

function closeNow() {
    stopAutoClose()
    emit('close')
}

onMounted(() => {
    if (props.cancelled) return
    timer = setInterval(() => {
        secondsLeft.value -= 1
        if (secondsLeft.value <= 0) closeNow()
    }, 1000)
})

onBeforeUnmount(stopAutoClose)

// Opening the confirm row removes the trigger button from the DOM — without an
// explicit focus move, keyboard focus silently drops to <body>. Same on close.
function openConfirm() {
    stopAutoClose() // user is deciding to cancel — don't auto-close under them
    confirmingCancel.value = true
    nextTick(() => confirmBtn.value?.focus())
}

function closeConfirm() {
    confirmingCancel.value = false
    nextTick(() => cancelOpenBtn.value?.focus())
}

function onRestart() {
    stopAutoClose()
    emit('restart')
}

function markDone() {
    stopAutoClose()
    done.value = true
}
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

            <!-- Booking reference — human-friendly, NON-secret (the cancellation
                 token must never be rendered: it is a bearer secret). -->
            <div class="mx-auto mt-5 max-w-xs rounded-xl bg-slate-50 px-3.5 py-2.5 ring-1 ring-slate-200/80">
                <p class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400">Buchungsnummer</p>
                <p data-reference class="mt-1 font-mono text-base font-bold tracking-wider text-widget-text">{{ result.reference }}</p>
            </div>
            <p class="mt-2 text-xs text-slate-400">Den Stornierungslink finden Sie in Ihrer Bestätigungs-E-Mail.</p>

            <template v-if="!done">
                <div class="mt-5 flex items-center justify-center gap-3">
                    <button type="button" data-restart @click="onRestart"
                            class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-xs font-bold text-white shadow-md transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
                            style="background: var(--masinga-gradient);">
                        Neuen Termin buchen
                    </button>
                    <button type="button" data-done @click="markDone"
                            class="inline-flex items-center rounded-full border border-slate-200 bg-widget-bg px-4 py-2 text-xs font-semibold text-widget-text/70 shadow-sm transition hover:bg-slate-50">
                        Fertig
                    </button>
                </div>

                <!-- Auto-close countdown — only while the timer is live and the user
                     isn't mid-cancellation. "Jetzt schließen" closes immediately. -->
                <div v-if="autoClosing && !confirmingCancel" class="mt-4" aria-live="polite">
                    <p data-autoclose-countdown class="text-xs text-slate-400">
                        Dieses Fenster schließt automatisch in {{ secondsLeft }} Sekunden.
                    </p>
                    <button type="button" data-close-now @click="closeNow"
                            class="mt-1.5 text-xs font-semibold text-accent underline underline-offset-2 hover:text-accent/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/50 rounded">
                        Jetzt schließen
                    </button>
                </div>

                <div v-if="!confirmingCancel" class="mt-4">
                    <button type="button" data-cancel-open ref="cancelOpenBtn" @click="openConfirm"
                            class="text-xs font-semibold text-slate-400 underline underline-offset-2 hover:text-rose-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-300 rounded">
                        Termin stornieren
                    </button>
                </div>
                <div v-else role="group" aria-label="Stornierung bestätigen" aria-live="assertive"
                     @keydown.esc="closeConfirm"
                     class="mt-4 flex flex-wrap items-center justify-center gap-2 rounded-xl bg-rose-50 px-3 py-2.5 ring-1 ring-rose-200/80">
                    <p class="text-xs font-medium text-rose-700">Termin wirklich stornieren?</p>
                    <button type="button" data-cancel-confirm ref="confirmBtn" :disabled="cancelling" @click="$emit('cancel')"
                            class="rounded-full bg-rose-600 px-3 py-1 text-xs font-bold text-white hover:bg-rose-700 disabled:opacity-50">Ja, stornieren</button>
                    <button type="button" data-cancel-keep @click="closeConfirm"
                            class="rounded-full border border-slate-200 bg-widget-bg px-3 py-1 text-xs font-semibold text-widget-text/70">Behalten</button>
                </div>
            </template>
            <p v-else class="mt-5 text-sm text-slate-400">Vielen Dank! Sie können diese Seite jetzt schließen.</p>
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
