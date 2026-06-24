<script setup lang="ts">
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { Hourglass } from 'lucide-vue-next'

// Laravel paginator labels carry a fixed set of HTML entities (&laquo; &raquo; &hellip;).
// We decode them with a static map — no DOM, no innerHTML, no XSS surface.
const HTML_ENTITIES: Record<string, string> = {
    '&laquo;': '«', '&raquo;': '»', '&hellip;': '…',
    '&amp;': '&', '&lt;': '<', '&gt;': '>',
}
const decodeLabel = (s: string): string =>
    s.replace(/&[a-z]+;/g, (e) => HTML_ENTITIES[e] ?? e)

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

// Status metadata — display order, German labels, badge tone classes.
// The `statusOptions` prop from the backend is not used for rendering;
// STATUS_META and STATUS_ORDER are the single source of truth on the frontend.
const STATUS_META: Record<string, { label: string; classes: string }> = {
    pending:   { label: 'Wartet',      classes: 'bg-[color-mix(in_srgb,#F7E29D_55%,white)] text-yellow-800' },
    contacted: { label: 'Kontaktiert', classes: 'bg-[color-mix(in_srgb,#98ACBA_25%,white)] text-slate-700' },
    booked:    { label: 'Gebucht',     classes: 'bg-green-100 text-green-700' },
    cancelled: { label: 'Abgesagt',    classes: 'bg-white text-slate-600 ring-1 ring-inset ring-slate-200' },
}
const STATUS_ORDER = ['pending', 'contacted', 'booked', 'cancelled'] as const

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

        <!-- Header: title + total count -->
        <div class="flex items-baseline justify-between flex-wrap gap-3 mb-2">
            <h1 class="text-3xl font-bold text-slate-900">Warteliste</h1>
            <span class="text-sm text-slate-500">
                {{ entries.meta.total }}
                {{ entries.meta.total === 1 ? 'Eintrag' : 'Einträge' }}
            </span>
        </div>

        <!-- Intro paragraph -->
        <p class="text-sm text-slate-500 max-w-[560px] mb-6">
            Kinder, die auf einen freien Termin warten. Sobald ein Platz frei wird,
            kontaktieren Sie die Eltern und buchen den Termin.
        </p>

        <!-- Filter bar -->
        <div class="flex flex-wrap items-end gap-3 mb-4">
            <label class="flex flex-col gap-1.5">
                <span class="text-[13px] font-semibold text-slate-500">Status</span>
                <select
                    v-model="statusFilter"
                    @change="applyFilter"
                    class="border border-slate-200 rounded-[8px] px-3 py-2 text-sm bg-white text-slate-700"
                    aria-label="Nach Status filtern"
                >
                    <option value="">Alle</option>
                    <option v-for="v in STATUS_ORDER" :key="v" :value="v">
                        {{ STATUS_META[v].label }}
                    </option>
                </select>
            </label>
        </div>

        <!-- Table card -->
        <DataTable>
            <template #head>
                <th class="px-4 py-2.5 text-xs font-semibold text-slate-500 border-b border-slate-200 whitespace-nowrap text-left">Datum</th>
                <th class="px-4 py-2.5 text-xs font-semibold text-slate-500 border-b border-slate-200 whitespace-nowrap text-left">Kind</th>
                <th class="px-4 py-2.5 text-xs font-semibold text-slate-500 border-b border-slate-200 whitespace-nowrap text-left">Elternteil</th>
                <th class="px-4 py-2.5 text-xs font-semibold text-slate-500 border-b border-slate-200 whitespace-nowrap text-left">Telefon / E-Mail</th>
                <th class="px-4 py-2.5 text-xs font-semibold text-slate-500 border-b border-slate-200 whitespace-nowrap text-left">Leistung</th>
                <th class="px-4 py-2.5 text-xs font-semibold text-slate-500 border-b border-slate-200 whitespace-nowrap text-left">Notiz</th>
                <th class="px-4 py-2.5 text-xs font-semibold text-slate-500 border-b border-slate-200 whitespace-nowrap text-left">Status</th>
            </template>

            <!-- Empty state -->
            <tr v-if="entries.data.length === 0">
                <td colspan="7" class="px-4 py-16 text-center">
                    <div class="flex flex-col items-center gap-3 text-slate-400">
                        <Hourglass class="h-8 w-8 opacity-40" :stroke-width="1.5" aria-hidden="true" />
                        <p class="text-sm font-semibold text-slate-500">Keine Einträge</p>
                        <p class="text-xs text-slate-400 max-w-[280px]">
                            Für diese Auswahl wartet gerade niemand. Ein ruhiger Tag im Kids Club. 🦷
                        </p>
                    </div>
                </td>
            </tr>

            <!-- Data rows -->
            <tr v-for="entry in entries.data" :key="entry.id">
                <!-- Datum -->
                <td class="px-4 py-3 text-sm text-slate-700 border-b border-slate-100 align-top whitespace-nowrap text-slate-500">
                    {{ fmtDate(entry.created_at) }}
                </td>
                <!-- Kind -->
                <td class="px-4 py-3 text-sm text-slate-700 border-b border-slate-100 align-top font-semibold text-slate-900">
                    {{ entry.patient_first_name }} {{ entry.patient_last_name }}
                </td>
                <!-- Elternteil -->
                <td class="px-4 py-3 text-sm text-slate-700 border-b border-slate-100 align-top">
                    {{ entry.parent_first_name }} {{ entry.parent_last_name }}
                </td>
                <!-- Telefon / E-Mail -->
                <td class="px-4 py-3 text-sm text-slate-700 border-b border-slate-100 align-top">
                    <div class="whitespace-nowrap tabular-nums">{{ entry.parent_phone }}</div>
                    <div v-if="entry.parent_email" class="text-xs text-slate-400">{{ entry.parent_email }}</div>
                </td>
                <!-- Leistung -->
                <td class="px-4 py-3 text-sm text-slate-700 border-b border-slate-100 align-top">
                    {{ entry.service?.name ?? '—' }}
                </td>
                <!-- Notiz -->
                <td class="px-4 py-3 text-sm text-slate-700 border-b border-slate-100 align-top text-slate-500 max-w-[200px]">
                    {{ entry.notes ?? '—' }}
                </td>
                <!-- Status: badge + inline select -->
                <td class="px-4 py-3 text-sm text-slate-700 border-b border-slate-100 align-top">
                    <div class="inline-flex items-center gap-2.5">
                        <!-- Status badge pill -->
                        <span
                            class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium"
                            :class="STATUS_META[entry.status]?.classes ?? 'bg-slate-100 text-slate-600'"
                        >
                            {{ STATUS_META[entry.status]?.label ?? entry.status }}
                        </span>
                        <!-- Inline status select -->
                        <select
                            :value="entry.status"
                            @change="updateStatus(entry, ($event.target as HTMLSelectElement).value)"
                            :aria-label="`Status von ${entry.patient_first_name} ${entry.patient_last_name} ändern`"
                            class="border border-slate-200 rounded-[8px] px-2 py-1 text-xs text-slate-500 bg-white cursor-pointer"
                        >
                            <option v-for="v in STATUS_ORDER" :key="v" :value="v">
                                {{ STATUS_META[v].label }}
                            </option>
                        </select>
                    </div>
                </td>
            </tr>
        </DataTable>

        <!-- Pagination -->
        <nav
            v-if="entries.links?.length > 3"
            class="mt-4 flex flex-wrap gap-1"
            aria-label="Seitennavigation"
        >
            <component
                :is="link.url ? 'button' : 'span'"
                v-for="(link, i) in entries.links"
                :key="i"
                @click="link.url && router.get(link.url, {}, { preserveState: true, replace: true, preserveScroll: true })"
                :class="link.active
                    ? 'bg-kids-blue text-white rounded-[8px] px-3 py-1 text-sm'
                    : 'text-slate-500 px-3 py-1 text-sm'"
            >{{ decodeLabel(link.label) }}</component>
        </nav>

    </div>
</template>
