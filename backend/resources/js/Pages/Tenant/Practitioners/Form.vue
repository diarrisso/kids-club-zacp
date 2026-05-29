<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'

const props = defineProps<{
    practitioner: null | {
        id: number; first_name: string; last_name: string; title: string;
        email: string; color: string; is_active: boolean;
    }
}>()

const form = useForm({
    first_name: props.practitioner?.first_name ?? '',
    last_name: props.practitioner?.last_name ?? '',
    title: props.practitioner?.title ?? '',
    email: props.practitioner?.email ?? '',
    color: props.practitioner?.color ?? '#0a6cb3',
    is_active: props.practitioner?.is_active ?? true,
})

const submit = () => {
    if (props.practitioner) form.put(`/behandler/${props.practitioner.id}`)
    else form.post('/behandler')
}
</script>

<template>
    <Head :title="practitioner ? 'Behandler bearbeiten' : 'Neuer Behandler'" />
    <div class="p-8 max-w-2xl">
        <h1 class="text-3xl font-bold mb-6">
            {{ practitioner ? 'Behandler bearbeiten' : 'Neuer Behandler' }}
        </h1>
        <form @submit.prevent="submit" class="bg-white p-6 rounded shadow space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">Anrede</label>
                <input v-model="form.title" type="text" class="w-full p-2 border rounded"
                       placeholder="Dr., Zahnärztin, ...">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Vorname *</label>
                    <input v-model="form.first_name" required class="w-full p-2 border rounded">
                    <div v-if="form.errors.first_name" class="text-red-600 text-sm">{{ form.errors.first_name }}</div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Nachname *</label>
                    <input v-model="form.last_name" required class="w-full p-2 border rounded">
                    <div v-if="form.errors.last_name" class="text-red-600 text-sm">{{ form.errors.last_name }}</div>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">E-Mail</label>
                <input v-model="form.email" type="email" class="w-full p-2 border rounded">
                <div v-if="form.errors.email" class="text-red-600 text-sm">{{ form.errors.email }}</div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Farbe im Kalender</label>
                <input v-model="form.color" type="color" class="h-10 w-20 border rounded">
            </div>
            <label class="flex items-center gap-2">
                <input v-model="form.is_active" type="checkbox"> Aktiv
            </label>
            <button type="submit" :disabled="form.processing"
                    class="bg-blue-700 text-white px-6 py-2 rounded hover:bg-blue-800">
                Speichern
            </button>
        </form>
    </div>
</template>
