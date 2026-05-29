<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'

const form = useForm({ email: '', password: '', remember: false })

const submit = () => form.post('/login')
</script>

<template>
    <Head title="Anmelden" />
    <div class="min-h-screen flex items-center justify-center bg-slate-50">
        <form @submit.prevent="submit" class="w-full max-w-md bg-white p-8 rounded shadow">
            <h1 class="text-2xl font-bold mb-6">Anmelden</h1>
            <input v-model="form.email" type="email" placeholder="E-Mail"
                   class="w-full p-3 border rounded mb-3">
            <div v-if="form.errors.email" class="text-red-600 text-sm mb-3">{{ form.errors.email }}</div>
            <input v-model="form.password" type="password" placeholder="Passwort"
                   class="w-full p-3 border rounded mb-4">
            <button type="submit" :disabled="form.processing"
                    class="w-full bg-blue-700 text-white py-3 rounded hover:bg-blue-800">
                Anmelden
            </button>
        </form>
    </div>
</template>
