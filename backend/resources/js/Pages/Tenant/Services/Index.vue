<script setup lang="ts">
import { Link, Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
defineOptions({ layout: TenantLayout })

defineProps<{ services: Array<{
    id: number; name: string; duration_minutes: number;
    color: string; is_active: boolean;
}> }>()

const destroy = (id: number) => {
    if (confirm('Wirklich löschen?')) router.delete(`/leistungen/${id}`)
}
</script>

<template>
    <Head title="Leistungen" />
    <div class="p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">Leistungen</h1>
            <Link href="/leistungen/create"
                  class="bg-blue-700 text-white px-4 py-2 rounded hover:bg-blue-800">
                + Neue Leistung
            </Link>
        </div>
        <table class="w-full bg-white rounded shadow">
            <thead class="bg-slate-100">
                <tr>
                    <th class="p-3 text-left">Bezeichnung</th>
                    <th class="p-3">Dauer</th>
                    <th class="p-3">Farbe</th>
                    <th class="p-3">Status</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="s in services" :key="s.id" class="border-t">
                    <td class="p-3">{{ s.name }}</td>
                    <td class="p-3 text-center">{{ s.duration_minutes }} min</td>
                    <td class="p-3 text-center">
                        <span class="inline-block w-6 h-6 rounded-full"
                              :style="{ background: s.color }"></span>
                    </td>
                    <td class="p-3 text-center">
                        <span v-if="s.is_active" class="text-green-600">Aktiv</span>
                        <span v-else class="text-slate-400">Inaktiv</span>
                    </td>
                    <td class="p-3 text-right">
                        <Link :href="`/leistungen/${s.id}/edit`" class="text-blue-600 mr-3">Bearbeiten</Link>
                        <button @click="destroy(s.id)" class="text-red-600">Löschen</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
