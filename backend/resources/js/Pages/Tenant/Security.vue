<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm, Head } from '@inertiajs/vue3'
import axios from 'axios'

const props = defineProps<{ twoFactorEnabled: boolean }>()

const qrSvg = ref<string>('')
const recoveryCodes = ref<string[]>([])
const showSetup = ref(false)
const acknowledged = ref(false)
const error = ref<string>('')

// Fortify gates the 2FA endpoints behind password.confirm (confirmPassword=true),
// so a freshly-logged-in user must re-enter their password before enabling/disabling.
// We confirm inline instead of bouncing them to a separate page.
const passwordPrompt = ref(false)
const passwordInput = ref<string>('')
let pendingAction: null | (() => Promise<void>) = null

const confirmForm = useForm({ code: '' })
const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' })

async function ensurePasswordConfirmed(action: () => Promise<void>) {
    error.value = ''
    const { data } = await axios.get('/user/confirmed-password-status')
    if (data.confirmed) {
        await action()
    } else {
        pendingAction = action
        passwordInput.value = ''
        passwordPrompt.value = true
    }
}

async function submitPasswordConfirmation() {
    error.value = ''
    try {
        await axios.post('/user/confirm-password', { password: passwordInput.value })
        passwordPrompt.value = false
        passwordInput.value = ''
        const action = pendingAction
        pendingAction = null
        if (action) await action()
    } catch {
        error.value = 'Passwort ist nicht korrekt.'
    }
}

async function doEnable() {
    try {
        await axios.post('/user/two-factor-authentication')
        const [qr, codes] = await Promise.all([
            axios.get('/user/two-factor-qr-code'),
            axios.get('/user/two-factor-recovery-codes'),
        ])
        qrSvg.value = qr.data.svg
        recoveryCodes.value = codes.data
        showSetup.value = true
    } catch {
        error.value = 'Einrichtung fehlgeschlagen. Bitte erneut versuchen.'
    }
}

const enable = () => ensurePasswordConfirmed(doEnable)

function confirm() {
    confirmForm.post('/user/confirmed-two-factor-authentication', {
        onSuccess: () => router.reload({ only: ['twoFactorEnabled'] }),
    })
}

const disable = () => ensurePasswordConfirmed(async () => {
    await axios.delete('/user/two-factor-authentication')
    showSetup.value = false
    router.reload({ only: ['twoFactorEnabled'] })
})

const changePassword = () => passwordForm.put('/user/password', {
    onSuccess: () => passwordForm.reset(),
})
</script>

<template>
    <Head title="Sicherheit" />
    <div class="max-w-2xl mx-auto p-6 space-y-10">
        <section>
            <h1 class="text-2xl font-bold mb-1">Sicherheit</h1>
            <p class="text-sm text-slate-500">Zwei-Faktor-Authentifizierung und Passwort.</p>
        </section>

        <div v-if="error" class="rounded-lg bg-rose-50 ring-1 ring-rose-200 px-4 py-3 text-sm text-rose-700">
            {{ error }}
        </div>

        <!-- Inline password confirmation -->
        <div v-if="passwordPrompt" class="rounded-xl bg-white ring-1 ring-slate-200 p-6 space-y-3">
            <h2 class="text-lg font-semibold">Passwort bestätigen</h2>
            <p class="text-sm text-slate-500">Bitte bestätigen Sie zur Sicherheit Ihr Passwort.</p>
            <input v-model="passwordInput" type="password" autocomplete="current-password"
                   placeholder="Passwort" class="w-full p-3 border rounded"
                   @keyup.enter="submitPasswordConfirmation">
            <div class="flex gap-2">
                <button @click="submitPasswordConfirmation"
                        class="bg-blue-700 text-white px-4 py-2 rounded hover:bg-blue-800">
                    Bestätigen
                </button>
                <button @click="passwordPrompt = false"
                        class="px-4 py-2 rounded text-slate-500 hover:text-slate-700">
                    Abbrechen
                </button>
            </div>
        </div>

        <section class="bg-white rounded-xl ring-1 ring-slate-100 p-6">
            <h2 class="text-lg font-semibold mb-3">Zwei-Faktor-Authentifizierung</h2>

            <p v-if="props.twoFactorEnabled" class="text-sm text-green-700 mb-4">
                ✓ Aktiv. Ihr Konto ist mit einem zweiten Faktor geschützt.
            </p>
            <p v-else class="text-sm text-amber-700 mb-4">
                Erforderlich. Bitte richten Sie die Zwei-Faktor-Authentifizierung ein.
            </p>

            <button v-if="!props.twoFactorEnabled && !showSetup" @click="enable"
                    class="bg-blue-700 text-white px-4 py-2 rounded hover:bg-blue-800">
                Einrichten
            </button>

            <div v-if="showSetup && !props.twoFactorEnabled" class="space-y-4">
                <p class="text-sm text-slate-600">1. Scannen Sie diesen QR-Code mit Ihrer Authentifizierungs-App:</p>
                <div v-html="qrSvg" class="inline-block bg-white p-2 ring-1 ring-slate-200 rounded"></div>

                <div class="rounded-lg bg-amber-50 ring-1 ring-amber-200 p-4">
                    <p class="text-sm font-semibold text-amber-800 mb-2">2. Wiederherstellungscodes (einmalig anzeigen — sicher aufbewahren):</p>
                    <ul class="font-mono text-xs text-amber-900 grid grid-cols-2 gap-1">
                        <li v-for="c in recoveryCodes" :key="c">{{ c }}</li>
                    </ul>
                    <label class="mt-3 flex items-center gap-2 text-sm text-amber-800">
                        <input type="checkbox" v-model="acknowledged"> Ich habe die Codes gesichert.
                    </label>
                </div>

                <div>
                    <p class="text-sm text-slate-600 mb-1">3. Bestätigen Sie mit dem aktuellen Code:</p>
                    <input v-model="confirmForm.code" inputmode="numeric" placeholder="6-stelliger Code"
                           class="p-3 border rounded mr-2">
                    <button @click="confirm" :disabled="!acknowledged || confirmForm.processing"
                            class="bg-green-700 text-white px-4 py-2 rounded hover:bg-green-800 disabled:opacity-40">
                        Aktivieren
                    </button>
                    <div v-if="confirmForm.errors.code" class="text-red-600 text-sm mt-1">{{ confirmForm.errors.code }}</div>
                </div>
            </div>

            <button v-if="props.twoFactorEnabled" @click="disable"
                    class="text-sm text-rose-600 hover:text-rose-700">
                Deaktivieren
            </button>
        </section>

        <section class="bg-white rounded-xl ring-1 ring-slate-100 p-6">
            <h2 class="text-lg font-semibold mb-3">Passwort ändern</h2>
            <form @submit.prevent="changePassword" class="space-y-3 max-w-sm">
                <input v-model="passwordForm.current_password" type="password" placeholder="Aktuelles Passwort"
                       class="w-full p-3 border rounded">
                <input v-model="passwordForm.password" type="password" placeholder="Neues Passwort (min. 12 Zeichen)"
                       class="w-full p-3 border rounded">
                <div v-if="passwordForm.errors.password" class="text-red-600 text-sm">{{ passwordForm.errors.password }}</div>
                <input v-model="passwordForm.password_confirmation" type="password" placeholder="Neues Passwort bestätigen"
                       class="w-full p-3 border rounded">
                <button type="submit" :disabled="passwordForm.processing"
                        class="bg-blue-700 text-white px-4 py-2 rounded hover:bg-blue-800">
                    Speichern
                </button>
            </form>
        </section>
    </div>
</template>
