<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import { Check } from 'lucide-vue-next'

defineOptions({ layout: TenantLayout })

interface Practitioner {
    id: number
    title: string | null
    first_name: string
    last_name: string
    color: string | null
}

interface Availability {
    id: number
    practitioner_id: number
    day_of_week: number
    start_time: string
    end_time: string
    valid_from: string | null
    valid_to: string | null
}

const props = defineProps<{
    practitioners: Practitioner[]
    availabilities: Availability[]
}>()

const DAYS = [
    { n: 1, label: 'Montag' },
    { n: 2, label: 'Dienstag' },
    { n: 3, label: 'Mittwoch' },
    { n: 4, label: 'Donnerstag' },
    { n: 5, label: 'Freitag' },
    { n: 6, label: 'Samstag' },
    { n: 7, label: 'Sonntag' },
]

interface DayRow { day_of_week: number; label: string; open: boolean; start_time: string; end_time: string }

const selectedPracId = ref<number | null>(props.practitioners[0]?.id ?? null)

function buildSchedule(pracId: number | null): DayRow[] {
    return DAYS.map((d) => {
        const existing = props.availabilities.find(
            (a) => a.practitioner_id === pracId && a.day_of_week === d.n && !a.valid_from && !a.valid_to
        )
        return {
            day_of_week: d.n,
            label: d.label,
            open: !!existing,
            start_time: existing?.start_time ?? '08:00',
            end_time: existing?.end_time ?? '17:00',
        }
    })
}

const schedule = ref<DayRow[]>(buildSchedule(selectedPracId.value))

watch(selectedPracId, (id) => { schedule.value = buildSchedule(id) })

const selectedPrac = computed(() => props.practitioners.find((p) => p.id === selectedPracId.value))

const pracName = (p: Practitioner) => [p.title, p.first_name, p.last_name].filter(Boolean).join(' ')

const save = () => {
    if (!selectedPracId.value) return
    router.put('/sprechzeiten/batch', {
        practitioner_id: selectedPracId.value,
        schedule: schedule.value.map((d) => ({
            day_of_week: d.day_of_week,
            open: d.open,
            start_time: d.open ? d.start_time : null,
            end_time: d.open ? d.end_time : null,
        })),
    }, { preserveScroll: true })
}
</script>

<template>
    <Head title="Sprechzeiten" />
    <div class="p-8 max-w-[760px]">
        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-3xl font-bold text-slate-900 tracking-tight">Sprechzeiten</h1>
            <button
                type="button"
                @click="save"
                class="inline-flex items-center gap-2 bg-blue-700 text-white px-4 py-2 rounded-[8px] text-sm font-semibold"
            >
                <Check class="w-4 h-4" :stroke-width="2.5" />
                Speichern
            </button>
        </div>

        <!-- Practitioner chips -->
        <div v-if="practitioners.length > 1" class="flex flex-wrap gap-2 mb-6">
            <button
                v-for="p in practitioners"
                :key="p.id"
                type="button"
                @click="selectedPracId = p.id"
                class="flex items-center gap-2 px-3.5 py-2 rounded-full text-sm font-medium border transition-colors"
                :class="selectedPracId === p.id
                    ? 'bg-[color-mix(in_srgb,#98ACBA_25%,white)] border-transparent text-slate-800'
                    : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300'"
            >
                <span class="w-2 h-2 rounded-full shrink-0" :style="{ background: p.color ?? '#98ACBA' }" />
                {{ pracName(p) }}
            </button>
        </div>

        <!-- Weekly schedule card -->
        <div class="bg-white rounded-ds-card border border-slate-200/70 shadow-card overflow-hidden">
            <ul class="divide-y divide-slate-100">
                <li
                    v-for="(day, i) in schedule"
                    :key="day.day_of_week"
                    class="flex items-center gap-4 px-6 py-3.5"
                    :class="i === 0 ? '' : ''"
                >
                    <!-- Toggle -->
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="day.open"
                        @click="day.open = !day.open"
                        class="relative inline-flex h-6 w-10 shrink-0 items-center rounded-full transition-colors"
                        :class="day.open ? 'bg-[#98ACBA]' : 'bg-slate-200'"
                    >
                        <span
                            class="inline-block h-[18px] w-[18px] rounded-full bg-white shadow transition-transform"
                            :class="day.open ? 'translate-x-[18px]' : 'translate-x-[3px]'"
                        />
                    </button>

                    <!-- Day label -->
                    <span
                        class="w-28 text-sm font-medium select-none"
                        :class="day.open ? 'text-slate-800' : 'text-slate-400'"
                    >{{ day.label }}</span>

                    <!-- Time inputs or "Geschlossen" -->
                    <template v-if="day.open">
                        <div class="flex items-center gap-2.5 text-sm text-slate-500">
                            <input
                                type="time"
                                v-model="day.start_time"
                                class="border border-slate-200 rounded-[8px] px-2.5 py-1.5 text-sm text-slate-800 tabular-nums"
                            />
                            <span>bis</span>
                            <input
                                type="time"
                                v-model="day.end_time"
                                class="border border-slate-200 rounded-[8px] px-2.5 py-1.5 text-sm text-slate-800 tabular-nums"
                            />
                        </div>
                    </template>
                    <span v-else class="text-sm text-slate-400">Geschlossen</span>
                </li>
            </ul>
        </div>
    </div>
</template>
