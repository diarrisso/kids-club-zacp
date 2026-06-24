<script setup lang="ts">
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'

defineOptions({ layout: TenantLayout })

interface WaitlistEntry {
    id: string
    patient_first_name: string
    patient_last_name: string
    parent_first_name: string
    parent_last_name: string
    parent_phone: string
    parent_email: string | null
    service: { id: number; name: string } | null
    notes: string | null
    status: string
    created_at: string
}

interface StatusOption { value: string; label: string }

const props = defineProps<{
    entries: { data: WaitlistEntry[]; links: any[]; meta: any }
    filters: { status: string }
    statusOptions: StatusOption[]
}>()

const statusFilter = ref(props.filters.status)

const applyFilter = () => {
    router.get('/warteliste', { status: statusFilter.value }, { preserveState: true, replace: true })
}

const updateStatus = (entry: WaitlistEntry, newStatus: string) => {
    router.patch(`/warteliste/${entry.id}`, { status: newStatus }, {
        preserveState: true,
        preserveScroll: true,
    })
}

const fmtDate = (dt: string) =>
    new Date(dt).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
</script>

<template>
    <Head title="Warteliste" />
    <div class="p-8">
        <h1 class="text-3xl font-bold mb-6">Warteliste</h1>

        <!-- Filter -->
        <div class="flex flex-wrap items-end gap-3 mb-6">
            <label class="text-sm">Status
                <select v-model="statusFilter" @change="applyFilter"
                        class="block border rounded px-3 py-2 text-sm mt-1">
                    <option value="">Alle</option>
                    <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </label>
        </div>

        <template v-if="entries.data.length > 0">
            <table class="w-full text-sm">
                <thead class="text-left text-slate-500 border-b">
                    <tr>
                        <th class="py-2 pr-4">Datum</th>
                        <th class="py-2 pr-4">Kind</th>
                        <th class="py-2 pr-4">Elternteil</th>
                        <th class="py-2 pr-4">Telefon / E-Mail</th>
                        <th class="py-2 pr-4">Leistung</th>
                        <th class="py-2 pr-4">Notiz</th>
                        <th class="py-2 pr-4">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries.data" :key="entry.id" class="border-b">
                        <td class="py-2 pr-4 whitespace-nowrap">{{ fmtDate(entry.created_at) }}</td>
                        <td class="py-2 pr-4">{{ entry.patient_first_name }} {{ entry.patient_last_name }}</td>
                        <td class="py-2 pr-4">{{ entry.parent_first_name }} {{ entry.parent_last_name }}</td>
                        <td class="py-2 pr-4">
                            <div>{{ entry.parent_phone }}</div>
                            <div v-if="entry.parent_email" class="text-slate-400 text-xs">{{ entry.parent_email }}</div>
                        </td>
                        <td class="py-2 pr-4">{{ entry.service?.name ?? '—' }}</td>
                        <td class="py-2 pr-4 text-slate-500 max-w-xs truncate">{{ entry.notes ?? '—' }}</td>
                        <td class="py-2 pr-4">
                            <select :value="entry.status"
                                    @change="updateStatus(entry, ($event.target as HTMLSelectElement).value)"
                                    class="border rounded px-2 py-1 text-xs">
                                <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="flex gap-2 mt-4 text-sm">
                <component v-for="link in entries.links" :key="link.label"
                           :is="link.url ? 'a' : 'span'"
                           :href="link.url ?? undefined"
                           v-html="link.label"
                           class="px-3 py-1 rounded border"
                           :class="link.active ? 'bg-kids-blue text-white border-kids-blue' : 'text-slate-500'" />
            </div>
        </template>

        <p v-else class="py-12 text-center text-slate-400">Keine Einträge.</p>
    </div>
</template>
