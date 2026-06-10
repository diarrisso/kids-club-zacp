<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm, Head } from '@inertiajs/vue3'
import axios from 'axios'

const props = defineProps<{ twoFactorEnabled: boolean }>()

const qrSvg = ref<string>('')
const recoveryCodes = ref<string[]>([])
const showSetup = ref(false)
const acknowledged = ref(false)

const confirmForm = useForm({ code: '' })
const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' })

async function enable() {
    await axios.post('/user/two-factor-authentication')
    const [qr, codes] = await Promise.all([
        axios.get('/user/two-factor-qr-code'),
        axios.get('/user/two-factor-recovery-codes'),
    ])
    qrSvg.value = qr.data.svg
    recoveryCodes.value = codes.data
    showSetup.value = true
}

function confirm() {
    confirmForm.post('/user/confirmed-two-factor-authentication', {
        onSuccess: () => router.reload({ only: ['twoFactorEnabled'] }),
    })
}

async function disable() {
    await axios.delete('/user/two-factor-authentication')
    router.reload({ only: ['twoFactorEnabled'] })
    showSetup.value = false
}

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
