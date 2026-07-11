<script setup lang="ts">
/**
 * Idle timeout monitor — automatische Abmeldung bei Inaktivität (Anti-Session-Diebstahl).
 *
 * Ablauf:
 *   - Aktivitäts-Events (mousemove, keydown, click, touchstart, scroll) setzen den Timer zurück.
 *   - Bei T − 60s: Warnhinweis-Modal + Button "Angemeldet bleiben".
 *   - Bei T: POST /logout → Weiterleitung zur Anmeldung.
 *
 * Quelle der Wahrheit: die Schwelle kommt über die Inertia-Prop auth.idle_timeout_minutes
 * (in HandleInertiaRequests geteilt, aus config/session_idle.php).
 *
 * Kurze Schwelle (< 75s, nur Dev): das Modal wird übersprungen (sonst würde sich der
 * Countdown sofort wieder öffnen) und direkt bei T abgemeldet.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

interface AuthProps {
    user?: { id: number } | null
    idle_timeout_minutes?: number
}

const page = usePage()
const auth = computed<AuthProps>(() => (page.props.auth as AuthProps) ?? {})

const seuilMinutes = computed(() => auth.value.idle_timeout_minutes ?? 15)
const SEUIL_MS = computed(() => seuilMinutes.value * 60 * 1000)
const WARNING_AVANT_MS = 60 * 1000
const SEUIL_MINIMUM_POUR_WARNING_MS = 75 * 1000

const ACTIVITY_EVENTS = ['mousemove', 'keydown', 'click', 'touchstart', 'scroll'] as const

const showWarning = ref(false)
const secondesRestantes = ref(60)

let warningTimerId: ReturnType<typeof setTimeout> | null = null
let logoutTimerId: ReturnType<typeof setTimeout> | null = null
let countdownIntervalId: ReturnType<typeof setInterval> | null = null

const clearAll = () => {
    if (warningTimerId) clearTimeout(warningTimerId)
    if (logoutTimerId) clearTimeout(logoutTimerId)
    if (countdownIntervalId) clearInterval(countdownIntervalId)
    warningTimerId = null
    logoutTimerId = null
    countdownIntervalId = null
}

const resetTimers = () => {
    clearAll()
    showWarning.value = false

    logoutTimerId = setTimeout(deconnecterAuto, SEUIL_MS.value)

    // Zu kurze Schwelle → kein Modal (Countdown-Fenster wäre 0 oder negativ).
    if (SEUIL_MS.value < SEUIL_MINIMUM_POUR_WARNING_MS) return

    warningTimerId = setTimeout(afficherWarning, SEUIL_MS.value - WARNING_AVANT_MS)
}

const afficherWarning = () => {
    showWarning.value = true
    secondesRestantes.value = 60

    countdownIntervalId = setInterval(() => {
        secondesRestantes.value -= 1
        if (secondesRestantes.value <= 0 && countdownIntervalId) clearInterval(countdownIntervalId)
    }, 1000)
}

const resterConnecte = () => resetTimers()

const deconnecterAuto = () => {
    clearAll()
    showWarning.value = false

    router.post('/logout', {}, {
        preserveScroll: false,
        // Fallback bei fehlgeschlagenem POST (z. B. 419 CSRF abgelaufen) → harte Weiterleitung.
        onError: () => {
            window.location.href = '/login'
        },
    })
}

const handleActivity = () => {
    if (showWarning.value) return // Der Nutzer muss "Angemeldet bleiben" klicken.
    resetTimers()
}

onMounted(() => {
    if (!auth.value.user) return // Auf öffentlichen Seiten inaktiv.

    ACTIVITY_EVENTS.forEach((evt) => window.addEventListener(evt, handleActivity, { passive: true }))
    resetTimers()
})

onUnmounted(() => {
    ACTIVITY_EVENTS.forEach((evt) => window.removeEventListener(evt, handleActivity))
    clearAll()
})
</script>

<template>
    <div
        v-if="showWarning"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4"
        role="alertdialog"
        aria-labelledby="idle-warning-title"
        aria-describedby="idle-warning-desc"
    >
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-2xl">
            <h2 id="idle-warning-title" class="mb-3 text-lg font-bold text-slate-900">
                ⏰ Inaktivität erkannt
            </h2>
            <p id="idle-warning-desc" class="mb-4 text-sm text-slate-700">
                Sie werden aus Sicherheitsgründen in
                <strong class="text-rose-600">{{ secondesRestantes }} Sekunde(n)</strong>
                automatisch abgemeldet.
            </p>
            <p class="mb-6 text-xs text-slate-500">
                Jede Aktion (Klick, Scrollen, Taste) bricht den Countdown ab.
            </p>
            <div class="flex justify-end">
                <button
                    type="button"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-slate-800"
                    @click="resterConnecte"
                >
                    Angemeldet bleiben
                </button>
            </div>
        </div>
    </div>
</template>
