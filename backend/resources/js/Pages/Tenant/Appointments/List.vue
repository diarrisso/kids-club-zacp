<script setup lang="ts">
import { ref } from 'vue'
import { Head, router, Link } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import type { AppointmentDto } from '@/lib/calendar'

defineOptions({ layout: TenantLayout })

interface Paginator<T> {
    data: T[]
    links: Array<{ url: string | null; label: string; active: boolean }>
}

const props = defineProps<{
    appointments: Paginator<AppointmentDto>
    filters: { q: string | null; from: string | null; to: string | null; attendance: string | null }
}>()

const q = ref(props.filters.q ?? '')
const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')
const attendance = ref(props.filters.attendance ?? '')

// Re-query the server with the current filters (server is the source of truth).
const applyFilters = () => {
    // Mirror the backend's max:100 so an over-long term can't trigger a silent 422.
    const safeQ = q.value.trim().slice(0, 100)
    router.get('/termine/liste', {
        q: safeQ || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
        attendance: attendance.value || undefined,
    }, { preserveState: true, replace: true, preserveScroll: true })
}

const setAttendance = async (a: AppointmentDto, value: 'arrived' | 'no_show') => {
    const next = a.attendance === value ? null : value
    try {
        await window.axios.patch(`/termine/${a.id}`, { attendance: next })
        a.attendance = next // optimistic local update
    } catch {
        router.reload({ only: ['appointments'] }) // rollback by refetching
    }
}

const fmt = (iso: string) =>
    new Date(iso).toLocaleString('de-DE', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
        timeZone: 'Europe/Berlin',
    })

// Laravel paginator labels carry a fixed set of HTML entities (&laquo; &raquo; &hellip;).
// We decode them with a static map — no DOM, no innerHTML, no XSS surface.
const HTML_ENTITIES: Record<string, string> = {
    '&laquo;': '«', '&raquo;': '»', '&hellip;': '…',
    '&amp;': '&', '&lt;': '<', '&gt;': '>',
}
const decodeLabel = (s: string): string =>
    s.replace(/&[a-z]+;/g, (e) => HTML_ENTITIES[e] ?? e)
</script>

<template>
    <Head title="Terminliste" />
    <div class="p-8">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-3xl font-bold">Terminliste</h1>
            <Link href="/termine" class="text-sm font-medium text-kids-blue underline">Zum Kalender</Link>
        </div>

        <div class="flex flex-wrap gap-3 mb-4">
            <input v-model="q" @keyup.enter="applyFilters" type="search" maxlength="100"
                   placeholder="Suche: Name Kind / Eltern…"
                   class="border rounded px-3 py-2 text-sm w-64" />
            <label class="flex items-center gap-1 text-sm text-slate-600">
                Von
                <input v-model="from" type="date" class="border rounded px-2 py-2 text-sm" />
            </label>
            <label class="flex items-center gap-1 text-sm text-slate-600">
                Bis
                <input v-model="to" type="date" class="border rounded px-2 py-2 text-sm" />
            </label>
            <select v-model="attendance" @change="applyFilters" class="border rounded px-3 py-2 text-sm">
                <option value="">Alle</option>
                <option value="arrived">Erschienen</option>
                <option value="no_show">Nicht erschienen</option>
            </select>
            <button type="button" @click="applyFilters"
                    class="rounded bg-kids-blue px-4 py-2 text-sm font-semibold text-white">Suchen</button>
        </div>

        <table class="w-full text-sm">
            <thead class="text-left text-slate-500 border-b">
                <tr>
                    <th class="py-2 pr-4">Datum/Zeit</th>
                    <th class="py-2 pr-4">Kind</th>
                    <th class="py-2 pr-4">Behandler</th>
                    <th class="py-2 pr-4">Leistung</th>
                    <th class="py-2 pr-4">Anwesenheit</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="a in appointments.data" :key="a.id" class="border-b hover:bg-slate-50">
                    <td class="py-2 pr-4 whitespace-nowrap">{{ fmt(a.starts_at) }}</td>
                    <td class="py-2 pr-4">{{ a.patient_first_name }} {{ a.patient_last_name }}</td>
                    <td class="py-2 pr-4">{{ a.practitioner.name }}</td>
                    <td class="py-2 pr-4">{{ a.service.name }}</td>
                    <td class="py-2 pr-4">
                        <div class="flex gap-1">
                            <button type="button" @click="setAttendance(a, 'arrived')"
                                    :class="a.attendance === 'arrived' ? 'bg-green-600 text-white' : 'bg-slate-100 text-slate-600'"
                                    class="rounded-full px-2 py-1 text-xs font-semibold" title="Erschienen">✓</button>
                            <button type="button" @click="setAttendance(a, 'no_show')"
                                    :class="a.attendance === 'no_show' ? 'bg-rose-600 text-white' : 'bg-slate-100 text-slate-600'"
                                    class="rounded-full px-2 py-1 text-xs font-semibold" title="Nicht erschienen">✗</button>
                        </div>
                    </td>
                </tr>
                <tr v-if="appointments.data.length === 0">
                    <td colspan="5" class="py-6 text-center text-slate-400">Keine Termine gefunden.</td>
                </tr>
            </tbody>
        </table>

        <nav class="mt-4 flex flex-wrap gap-1">
            <component :is="link.url ? 'button' : 'span'" v-for="(link, i) in appointments.links" :key="i"
                       @click="link.url && router.get(link.url, {}, { preserveState: true, replace: true, preserveScroll: true })"
                       :class="link.active ? 'bg-kids-blue text-white' : 'text-slate-600'"
                       class="rounded px-3 py-1 text-sm">{{ decodeLabel(link.label) }}</component>
        </nav>
    </div>
</template>
