<script setup lang="ts">
import { Head } from '@inertiajs/vue3'

defineProps<{
    tenants: Array<{
        id: string; name: string; slug: string; status: string;
        plan: { name: string } | null;
    }>;
}>()
</script>

<template>
    <Head title="Praxen — Masinga Booking" />
    <div class="min-h-screen bg-slate-50 p-8">
        <h1 class="text-3xl font-bold mb-6">Praxen</h1>
        <table class="w-full bg-white rounded shadow">
            <thead class="bg-slate-100">
                <tr>
                    <th class="p-3 text-left">Praxis</th>
                    <th class="p-3 text-left">Slug</th>
                    <th class="p-3 text-left">Status</th>
                    <th class="p-3 text-left">Tarif</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="t in tenants" :key="t.id" class="border-t">
                    <td class="p-3">{{ t.name }}</td>
                    <td class="p-3">{{ t.slug }}</td>
                    <td class="p-3">{{ t.status }}</td>
                    <td class="p-3">{{ t.plan?.name ?? '—' }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
