<script setup lang="ts">
import { ref } from 'vue'
import { useForm, Head } from '@inertiajs/vue3'

const useRecovery = ref(false)
const form = useForm({ code: '', recovery_code: '' })

const submit = () => form.post('/two-factor-challenge')

const toggle = () => {
    useRecovery.value = !useRecovery.value
    form.code = ''
    form.recovery_code = ''
}
</script>

<template>
    <Head title="Bestätigung" />
    <div class="min-h-screen flex items-center justify-center bg-slate-50">
        <form @submit.prevent="submit" class="w-full max-w-md bg-white p-8 rounded shadow">
            <h1 class="text-2xl font-bold mb-2">Zwei-Faktor-Bestätigung</h1>

            <template v-if="!useRecovery">
                <p class="text-sm text-slate-500 mb-4">Geben Sie den Code aus Ihrer Authentifizierungs-App ein.</p>
                <input v-model="form.code" inputmode="numeric" autocomplete="one-time-code"
                       autofocus placeholder="6-stelliger Code"
                       class="w-full p-3 border rounded mb-3">
                <div v-if="form.errors.code" class="text-red-600 text-sm mb-3">{{ form.errors.code }}</div>
            </template>

            <template v-else>
                <p class="text-sm text-slate-500 mb-4">Geben Sie einen Ihrer Wiederherstellungscodes ein.</p>
                <input v-model="form.recovery_code" autocomplete="off"
                       placeholder="Wiederherstellungscode"
                       class="w-full p-3 border rounded mb-3">
                <div v-if="form.errors.recovery_code" class="text-red-600 text-sm mb-3">{{ form.errors.recovery_code }}</div>
            </template>

            <button type="submit" :disabled="form.processing"
                    class="w-full bg-blue-700 text-white py-3 rounded hover:bg-blue-800">
                Bestätigen
            </button>

            <button type="button" @click="toggle"
                    class="mt-3 w-full text-sm text-slate-500 hover:text-slate-700">
                {{ useRecovery ? 'Authentifizierungs-Code verwenden' : 'Wiederherstellungscode verwenden' }}
            </button>
        </form>
    </div>
</template>
