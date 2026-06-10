<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'

const form = useForm({ password: '' })

const submit = () => form.post('/user/confirm-password')
</script>

<template>
    <Head title="Passwort bestätigen" />
    <div class="min-h-screen flex items-center justify-center bg-slate-50">
        <form @submit.prevent="submit" class="w-full max-w-md bg-white p-8 rounded shadow">
            <h1 class="text-2xl font-bold mb-2">Passwort bestätigen</h1>
            <p class="text-sm text-slate-500 mb-4">Bitte bestätigen Sie zur Sicherheit Ihr Passwort.</p>
            <input v-model="form.password" type="password" autocomplete="current-password"
                   autofocus placeholder="Passwort" class="w-full p-3 border rounded mb-3">
            <div v-if="form.errors.password" class="text-red-600 text-sm mb-3">{{ form.errors.password }}</div>
            <button type="submit" :disabled="form.processing"
                    class="w-full bg-blue-700 text-white py-3 rounded hover:bg-blue-800">
                Bestätigen
            </button>
        </form>
    </div>
</template>
