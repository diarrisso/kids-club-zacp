<script setup lang="ts">
import { Link, Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
defineOptions({ layout: TenantLayout })

const labels: Record<string, string> = {
    vacation: 'Urlaub', sick: 'Krankheit', block: 'Blockierung',
}

defineProps<{
    exceptions: Array<{
        id: number; starts_at: string; ends_at: string; type: string; reason: string | null;
        practitioner: { first_name: string; last_name: string; color: string };
    }>;
}>()

const destroy = (id: number) => {
    if (confirm('Wirklich löschen?')) router.delete(`/abwesenheiten/${id}`)
}
</script>

<template>
    <Head title="Abwesenheiten" />
    <div class="p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">Abwesenheiten</h1>
            <Link href="/abwesenheiten/create"
                  class="bg-blue-700 text-white px-4 py-2 rounded hover:bg-blue-800">+ Neue Abwesenheit</Link>
        </div>
        <table class="w-full bg-white rounded shadow">
            <thead class="bg-slate-100">
                <tr>
                    <th class="p-3 text-left">Behandler</th>
                    <th class="p-3 text-left">Typ</th>
                    <th class="p-3 text-left">Von</th>
                    <th class="p-3 text-left">Bis</th>
                    <th class="p-3 text-left">Grund</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="e in exceptions" :key="e.id" class="border-t">
                    <td class="p-3">
                        <span class="inline-block w-4 h-4 rounded-full mr-2"
                              :style="{ background: e.practitioner.color }"></span>
                        {{ e.practitioner.first_name }} {{ e.practitioner.last_name }}
                    </td>
                    <td class="p-3">{{ labels[e.type] ?? e.type }}</td>
                    <td class="p-3">{{ e.starts_at }}</td>
                    <td class="p-3">{{ e.ends_at }}</td>
                    <td class="p-3">{{ e.reason }}</td>
                    <td class="p-3 text-right">
                        <Link :href="`/abwesenheiten/${e.id}/edit`" class="text-blue-600 mr-3">Bearbeiten</Link>
                        <button @click="destroy(e.id)" class="text-red-600">Löschen</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
