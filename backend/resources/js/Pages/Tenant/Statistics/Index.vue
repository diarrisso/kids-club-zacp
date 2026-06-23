<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { CheckCircle2, XCircle, Percent, HelpCircle } from 'lucide-vue-next'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import StatCard from '@/components/ui/StatCard.vue'

defineOptions({ layout: TenantLayout })

interface PractitionerRow {
    id: number
    name: string
    color: string
    arrived: number
    noShow: number
    rate: number | null
}

const props = defineProps<{
    kpis: { arrived: number; noShow: number; notRecorded: number; rate: number | null }
    perPractitioner: PractitionerRow[]
    filters: { from: string; to: string }
    scoped: boolean
}>()

const from = ref(props.filters.from)
const to = ref(props.filters.to)

// Keep the date inputs in sync when Inertia updates props in place
// (preserveState filter navigation / browser back-forward).
watch(
    () => props.filters,
    (f) => {
        from.value = f.from
        to.value = f.to
    },
)

// German percent formatting; "—" when there is nothing recorded (rate null).
const fmtRate = (rate: number | null) =>
    rate === null ? '—' : `${rate.toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`

const applyPeriod = () => {
    router.get('/statistiken', {
        from: from.value || undefined,
        to: to.value || undefined,
    }, { preserveState: true, replace: true, preserveScroll: true })
}

const hasData = computed(
    () => props.kpis.arrived + props.kpis.noShow + props.kpis.notRecorded > 0,
)
</script>

<template>
    <Head title="Statistiken" />
    <div class="p-8">
        <h1 class="text-3xl font-bold mb-6">Statistiken</h1>

        <div class="flex flex-wrap items-end gap-3 mb-6">
            <label class="text-sm">Von
                <input v-model="from" type="date" class="block border rounded px-3 py-2 text-sm" />
            </label>
            <label class="text-sm">Bis
                <input v-model="to" type="date" class="block border rounded px-3 py-2 text-sm" />
            </label>
            <button type="button" @click="applyPeriod"
                    class="rounded bg-kids-blue px-4 py-2 text-sm font-semibold text-white">Anzeigen</button>
        </div>

        <template v-if="hasData">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <StatCard :icon="CheckCircle2" :value="kpis.arrived" label="Erschienen" color="bg-kids-green" />
                <StatCard :icon="XCircle" :value="kpis.noShow" label="Nicht erschienen" color="bg-rose-100" />
                <StatCard :icon="Percent" :value="fmtRate(kpis.rate)" label="No-Show-Quote" color="bg-kids-blue" />
                <StatCard :icon="HelpCircle" :value="kpis.notRecorded" label="Nicht erfasst" color="bg-slate-100" />
            </div>

            <div v-if="!scoped">
                <h2 class="text-lg font-semibold mb-3">Nach Behandler</h2>
                <table class="w-full text-sm">
                    <thead class="text-left text-slate-500 border-b">
                        <tr>
                            <th class="py-2 pr-4">Behandler</th>
                            <th class="py-2 pr-4">Erschienen</th>
                            <th class="py-2 pr-4">Nicht erschienen</th>
                            <th class="py-2 pr-4">No-Show-Quote</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in perPractitioner" :key="row.id" class="border-b">
                            <td class="py-2 pr-4">
                                <span class="inline-block w-2.5 h-2.5 rounded-full mr-2 align-middle"
                                      :style="{ backgroundColor: row.color }"></span>
                                {{ row.name }}
                            </td>
                            <td class="py-2 pr-4">{{ row.arrived }}</td>
                            <td class="py-2 pr-4">{{ row.noShow }}</td>
                            <td class="py-2 pr-4 font-semibold">{{ fmtRate(row.rate) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <p v-else class="py-12 text-center text-slate-400">Keine Termine im gewählten Zeitraum.</p>
    </div>
</template>
