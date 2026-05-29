<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
defineOptions({ layout: TenantLayout })

const props = defineProps<{
    service: null | {
        id: number; name: string; duration_minutes: number;
        color: string; description: string; is_active: boolean;
        practitioners?: Array<{ id: number }>;
    };
    practitioners: Array<{ id: number; first_name: string; last_name: string; title: string }>;
}>()

const form = useForm({
    name: props.service?.name ?? '',
    duration_minutes: props.service?.duration_minutes ?? 30,
    color: props.service?.color ?? '#0a6cb3',
    description: props.service?.description ?? '',
    is_active: props.service?.is_active ?? true,
    practitioner_ids: props.service?.practitioners?.map(p => p.id) ?? [],
})

const submit = () => {
    if (props.service) form.put(`/leistungen/${props.service.id}`)
    else form.post('/leistungen')
}
</script>

<template>
    <Head :title="service ? 'Leistung bearbeiten' : 'Neue Leistung'" />
    <div class="p-8 max-w-2xl">
        <h1 class="text-3xl font-bold mb-6">
            {{ service ? 'Leistung bearbeiten' : 'Neue Leistung' }}
        </h1>
        <form @submit.prevent="submit" class="bg-white p-6 rounded shadow space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">Bezeichnung *</label>
                <input v-model="form.name" required class="w-full p-2 border rounded">
                <div v-if="form.errors.name" class="text-red-600 text-sm">{{ form.errors.name }}</div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Dauer (Minuten) *</label>
                <input v-model.number="form.duration_minutes" type="number" min="5" max="480" required
                       class="w-full p-2 border rounded">
                <div v-if="form.errors.duration_minutes" class="text-red-600 text-sm">{{ form.errors.duration_minutes }}</div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Beschreibung</label>
                <textarea v-model="form.description" rows="3" class="w-full p-2 border rounded"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Farbe im Kalender</label>
                <input v-model="form.color" type="color" class="h-10 w-20 border rounded">
            </div>
            <div>
                <label class="block text-sm font-medium mb-2">Wird ausgeführt von</label>
                <div class="space-y-2">
                    <label v-for="p in practitioners" :key="p.id" class="flex items-center gap-2">
                        <input type="checkbox" :value="p.id" v-model="form.practitioner_ids">
                        {{ p.title }} {{ p.first_name }} {{ p.last_name }}
                    </label>
                </div>
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
