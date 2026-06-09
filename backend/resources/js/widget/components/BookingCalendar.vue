<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'

const props = defineProps<{ availableDates: string[]; selectedDate?: string }>()
const emit = defineEmits<{ 'month-change': [{ from: string; to: string }]; select: [date: string] }>()

// today/ymd() use the browser's local wall-clock by design: this widget targets German
// users and the backend runs in Europe/Berlin, so they agree in practice. The backend
// re-validates every booking, so the calendar is a UX affordance, not a correctness
// boundary. A future cross-timezone hardening could anchor on Intl timeZone:'Europe/Berlin'.
const today = new Date()
const todayStr = ymd(today)
const viewYear = ref(today.getFullYear())
const viewMonth = ref(today.getMonth()) // 0-11

function ymd(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const isCurrentMonth = computed(
    () => viewYear.value === today.getFullYear() && viewMonth.value === today.getMonth(),
)

const monthLabel = computed(() =>
    new Date(viewYear.value, viewMonth.value, 1).toLocaleDateString('de-DE', { month: 'long', year: 'numeric' }),
)

const weekdayLabels = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']

// Cells: leading blanks (Mon-first) then each day of the month.
const cells = computed(() => {
    const first = new Date(viewYear.value, viewMonth.value, 1)
    const daysInMonth = new Date(viewYear.value, viewMonth.value + 1, 0).getDate()
    const lead = (first.getDay() + 6) % 7 // JS Sun=0 → Mon-first offset
    const out: Array<{ key: string; day: number | null; date: string | null; available: boolean }> = []

    for (let i = 0; i < lead; i++) out.push({ key: `b${i}`, day: null, date: null, available: false })

    for (let day = 1; day <= daysInMonth; day++) {
        const date = ymd(new Date(viewYear.value, viewMonth.value, day))
        const available = props.availableDates.includes(date) && date >= todayStr
        out.push({ key: date, day, date, available })
    }
    return out
})

function emitMonthChange() {
    const start = new Date(viewYear.value, viewMonth.value, 1)
    const end = new Date(viewYear.value, viewMonth.value + 1, 0)
    const startStr = ymd(start)
    emit('month-change', { from: startStr < todayStr ? todayStr : startStr, to: ymd(end) })
}

function prevMonth() {
    if (viewMonth.value === 0) { viewMonth.value = 11; viewYear.value-- }
    else viewMonth.value--
    emitMonthChange()
}

function nextMonth() {
    if (viewMonth.value === 11) { viewMonth.value = 0; viewYear.value++ }
    else viewMonth.value++
    emitMonthChange()
}

onMounted(emitMonthChange)
</script>

<template>
    <div data-calendar class="rounded-2xl bg-white ring-1 ring-slate-100 p-4 shadow-sm">
        <!-- Month navigation -->
        <div class="flex items-center justify-between mb-4">
            <button type="button" data-prev-month @click="prevMonth" :disabled="isCurrentMonth"
                    class="h-8 w-8 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 text-lg font-medium transition hover:bg-[#EEF3F6] hover:border-[#98ACBA] hover:text-[#5A7A91] disabled:opacity-30 disabled:cursor-default focus:outline-none focus-visible:ring-2 focus-visible:ring-[#98ACBA]/60"
                    aria-label="Vorheriger Monat">‹</button>
            <span class="text-sm font-semibold text-slate-700 capitalize">{{ monthLabel }}</span>
            <button type="button" data-next-month @click="nextMonth"
                    class="h-8 w-8 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 text-lg font-medium transition hover:bg-[#EEF3F6] hover:border-[#98ACBA] hover:text-[#5A7A91] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#98ACBA]/60"
                    aria-label="Nächster Monat">›</button>
        </div>

        <!-- Weekday header -->
        <div class="grid grid-cols-7 gap-1 text-center mb-2">
            <span v-for="w in weekdayLabels" :key="w"
                  class="text-[11px] font-semibold text-slate-400 py-1">{{ w }}</span>
        </div>

        <!-- Day grid -->
        <div class="grid grid-cols-7 gap-1 text-center text-sm">
            <template v-for="cell in cells" :key="cell.key">
                <span v-if="cell.day === null"></span>
                <button v-else type="button"
                        :data-day="cell.date ?? undefined"
                        :data-available="cell.available || undefined"
                        :aria-current="cell.date === selectedDate ? 'date' : undefined"
                        :disabled="!cell.available"
                        @click="cell.date && cell.available && $emit('select', cell.date)"
                        class="rounded-xl py-2 text-sm font-medium transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#98ACBA]/60"
                        :class="[
                            cell.date === selectedDate
                                ? 'text-white shadow-sm font-semibold'
                                : cell.available
                                    ? 'text-[#5A7A91] hover:-translate-y-0.5 hover:shadow-sm'
                                    : 'text-slate-300 cursor-default',
                        ]"
                        :style="cell.date === selectedDate
                            ? { background: 'linear-gradient(135deg, #6B8FA3 0%, #4A6B7E 100%)' }
                            : cell.available
                                ? { backgroundColor: '#EEF3F6' }
                                : {}">
                    {{ cell.day }}
                </button>
            </template>
        </div>
    </div>
</template>
