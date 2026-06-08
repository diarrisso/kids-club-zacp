<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'

const props = defineProps<{ availableDates: string[]; selectedDate?: string }>()
const emit = defineEmits<{ 'month-change': [{ from: string; to: string }]; select: [date: string] }>()

const today = new Date()
const todayStr = ymd(today)
const viewYear = ref(today.getFullYear())
const viewMonth = ref(today.getMonth()) // 0-11

function ymd(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

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
    <div data-calendar>
        <div class="flex items-center justify-between mb-2">
            <button type="button" data-prev-month @click="prevMonth"
                    class="px-2 py-1 rounded hover:bg-slate-100" aria-label="Vorheriger Monat">‹</button>
            <span class="font-medium capitalize">{{ monthLabel }}</span>
            <button type="button" data-next-month @click="nextMonth"
                    class="px-2 py-1 rounded hover:bg-slate-100" aria-label="Nächster Monat">›</button>
        </div>

        <div class="grid grid-cols-7 gap-1 text-center text-xs text-slate-400 mb-1">
            <span v-for="w in weekdayLabels" :key="w">{{ w }}</span>
        </div>

        <div class="grid grid-cols-7 gap-1 text-center text-sm">
            <template v-for="cell in cells" :key="cell.key">
                <span v-if="cell.day === null"></span>
                <button v-else type="button"
                        :data-day="cell.date ?? undefined"
                        :data-available="cell.available || undefined"
                        :disabled="!cell.available"
                        @click="cell.date && cell.available && $emit('select', cell.date)"
                        :class="[
                            'py-2 rounded',
                            cell.available ? 'cursor-pointer hover:bg-blue-100' : 'text-slate-300 cursor-default',
                            cell.date === selectedDate ? 'bg-blue-500 text-white hover:bg-blue-500' : (cell.available ? 'bg-blue-50' : ''),
                        ]">
                    {{ cell.day }}
                </button>
            </template>
        </div>
    </div>
</template>
