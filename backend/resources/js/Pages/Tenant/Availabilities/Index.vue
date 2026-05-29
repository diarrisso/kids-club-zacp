<script setup lang="ts">
import { Link, Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
defineOptions({ layout: TenantLayout })

const days: Record<number, string> = {
    1: 'Mo', 2: 'Di', 3: 'Mi', 4: 'Do', 5: 'Fr', 6: 'Sa', 7: 'So',
}

defineProps<{
    availabilities: Array<{
        id: number; day_of_week: number; start_time: string; end_time: string;
        practitioner: { first_name: string; last_name: string; color: string };
    }>;
}>()

const destroy = (id: number) => {
    if (confirm('Wirklich löschen?')) router.delete(`/sprechzeiten/${id}`)
}
</script>

<template>
    <Head title="Sprechzeiten" />
    <div class="p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">Sprechzeiten</h1>
            <Link href="/sprechzeiten/create"
                  class="bg-blue-700 text-white px-4 py-2 rounded hover:bg-blue-800">+ Neue Sprechzeit</Link>
        </div>
        <table class="w-full bg-white rounded shadow">
            <thead class="bg-slate-100">
                <tr>
                    <th class="p-3 text-left">Behandler</th>
                    <th class="p-3 text-left">Wochentag</th>
                    <th class="p-3 text-left">Zeit</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="a in availabilities" :key="a.id" class="border-t">
                    <td class="p-3">
                        <span class="inline-block w-4 h-4 rounded-full mr-2"
                              :style="{ background: a.practitioner.color }"></span>
                        {{ a.practitioner.first_name }} {{ a.practitioner.last_name }}
                    </td>
                    <td class="p-3">{{ days[a.day_of_week] }}</td>
                    <td class="p-3">{{ a.start_time }} – {{ a.end_time }}</td>
                    <td class="p-3 text-right">
                        <Link :href="`/sprechzeiten/${a.id}/edit`" class="text-blue-600 mr-3">Bearbeiten</Link>
                        <button @click="destroy(a.id)" class="text-red-600">Löschen</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
