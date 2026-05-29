<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
defineOptions({ layout: TenantLayout })

const props = defineProps<{
    exception: null | {
        id: number; practitioner_id: number; starts_at: string;
        ends_at: string; type: string; reason: string | null;
    };
    practitioners: Array<{ id: number; first_name: string; last_name: string; title: string }>;
}>()

const types = [
    { value: 'vacation', label: 'Urlaub' },
    { value: 'sick', label: 'Krankheit' },
    { value: 'block', label: 'Blockierung' },
]

const form = useForm({
    practitioner_id: props.exception?.practitioner_id ?? props.practitioners[0]?.id ?? null,
    starts_at: props.exception?.starts_at ?? '',
    ends_at: props.exception?.ends_at ?? '',
    type: props.exception?.type ?? 'vacation',
    reason: props.exception?.reason ?? '',
})

const submit = () => {
    if (props.exception) form.put(`/abwesenheiten/${props.exception.id}`)
    else form.post('/abwesenheiten')
}
</script>

<template>
    <Head :title="exception ? 'Abwesenheit bearbeiten' : 'Neue Abwesenheit'" />
    <div class="p-8 max-w-2xl">
        <h1 class="text-3xl font-bold mb-6">
            {{ exception ? 'Abwesenheit bearbeiten' : 'Neue Abwesenheit' }}
        </h1>
        <form @submit.prevent="submit" class="bg-white p-6 rounded shadow space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">Behandler *</label>
                <select v-model.number="form.practitioner_id" class="w-full p-2 border rounded">
                    <option v-for="p in practitioners" :key="p.id" :value="p.id">
                        {{ p.title }} {{ p.first_name }} {{ p.last_name }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Typ *</label>
                <select v-model="form.type" class="w-full p-2 border rounded">
                    <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Von *</label>
                    <input v-model="form.starts_at" type="datetime-local" required class="w-full p-2 border rounded">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Bis *</label>
                    <input v-model="form.ends_at" type="datetime-local" required class="w-full p-2 border rounded">
                    <div v-if="form.errors.ends_at" class="text-red-600 text-sm">{{ form.errors.ends_at }}</div>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Grund</label>
                <input v-model="form.reason" type="text" class="w-full p-2 border rounded">
            </div>
            <button type="submit" :disabled="form.processing"
                    class="bg-blue-700 text-white px-6 py-2 rounded hover:bg-blue-800">
                Speichern
            </button>
        </form>
    </div>
</template>
