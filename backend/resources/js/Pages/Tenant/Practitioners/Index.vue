<script setup lang="ts">
import { Link, Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
defineOptions({ layout: TenantLayout })

defineProps<{ practitioners: Array<{
    id: number; first_name: string; last_name: string; title: string;
    email: string; color: string; is_active: boolean;
}> }>()

const destroy = (id: number) => {
    if (confirm('Wirklich löschen?')) router.delete(`/behandler/${id}`)
}
</script>

<template>
    <Head title="Behandler" />
    <div class="p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">Behandler</h1>
            <Link href="/behandler/create"
                  class="bg-blue-700 text-white px-4 py-2 rounded hover:bg-blue-800">
                + Neuer Behandler
            </Link>
        </div>
        <table class="w-full bg-white rounded shadow">
            <thead class="bg-slate-100">
                <tr>
                    <th class="p-3 text-left">Name</th>
                    <th class="p-3 text-left">E-Mail</th>
                    <th class="p-3">Farbe</th>
                    <th class="p-3">Status</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in practitioners" :key="p.id" class="border-t">
                    <td class="p-3">{{ p.title }} {{ p.first_name }} {{ p.last_name }}</td>
                    <td class="p-3">{{ p.email }}</td>
                    <td class="p-3 text-center">
                        <span class="inline-block w-6 h-6 rounded-full"
                              :style="{ background: p.color }"></span>
                    </td>
                    <td class="p-3 text-center">
                        <span v-if="p.is_active" class="text-green-600">Aktiv</span>
                        <span v-else class="text-slate-400">Inaktiv</span>
                    </td>
                    <td class="p-3 text-right">
                        <Link :href="`/behandler/${p.id}/edit`" class="text-blue-600 mr-3">Bearbeiten</Link>
                        <button @click="destroy(p.id)" class="text-red-600">Löschen</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
