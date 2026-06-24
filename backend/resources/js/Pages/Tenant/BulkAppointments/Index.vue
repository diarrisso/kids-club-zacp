<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import { CalendarPlus } from 'lucide-vue-next'

defineOptions({ layout: TenantLayout })

interface Practitioner { id: number; name: string; color: string | null }
const props = defineProps<{ practitioners: Practitioner[] }>()

function todayIso() { return new Date().toISOString().slice(0, 10) }
function plusMonths(n: number) {
  const d = new Date(dateFrom.value + 'T00:00:00')
  d.setMonth(d.getMonth() + n)
  d.setDate(d.getDate() - 1)
  return d.toISOString().slice(0, 10)
}

const selectedPrac = ref<number | null>(props.practitioners[0]?.id ?? null)
const dateFrom = ref(todayIso())
const dateTo = ref(plusMonths(1))
const selectedWeekdays = ref<number[]>([1, 2, 3, 4, 5])
const durationMin = ref(30)
const skipHolidays = ref(true)
const skipAbsences = ref(true)

const WD = [
  { i: 1, l: 'Mo' }, { i: 2, l: 'Di' }, { i: 3, l: 'Mi' },
  { i: 4, l: 'Do' }, { i: 5, l: 'Fr' }, { i: 6, l: 'Sa' }, { i: 0, l: 'So' },
]

const HOLIDAYS: Record<string, string> = {
  '2026-10-03': 'Tag der Deutschen Einheit',
  '2026-11-01': 'Allerheiligen',
  '2026-12-24': 'Heiligabend (Praxis zu)',
  '2026-12-25': '1. Weihnachtstag',
  '2026-12-26': '2. Weihnachtstag',
  '2026-12-31': 'Silvester (Praxis zu)',
  '2027-01-01': 'Neujahr',
  '2027-01-06': 'Heilige Drei Könige',
  '2027-04-02': 'Karfreitag',
  '2027-04-05': 'Ostermontag',
  '2027-05-01': 'Tag der Arbeit',
  '2027-05-13': 'Christi Himmelfahrt',
  '2027-05-24': 'Pfingstmontag',
  '2027-06-03': 'Fronleichnam',
  '2027-08-15': 'Mariä Himmelfahrt',
  '2027-10-03': 'Tag der Deutschen Einheit',
  '2027-11-01': 'Allerheiligen',
  '2027-12-24': 'Heiligabend (Praxis zu)',
  '2027-12-25': '1. Weihnachtstag',
  '2027-12-26': '2. Weihnachtstag',
}

const OPEN_SLOTS_30: Record<number, number> = { 1: 18, 2: 18, 3: 10, 4: 20, 5: 14, 6: 0, 0: 0 }

interface Plan {
  slots: number
  workdays: number
  holidayHits: { key: string; name: string }[]
  weekends: number
  closedWeekdays: number
}

const plan = computed<Plan>(() => {
  const out: Plan = { slots: 0, workdays: 0, holidayHits: [], weekends: 0, closedWeekdays: 0 }
  const start = new Date(dateFrom.value + 'T00:00:00')
  const end   = new Date(dateTo.value + 'T00:00:00')
  if (isNaN(start.getTime()) || isNaN(end.getTime()) || end < start) return out
  const slotFactor = 30 / durationMin.value
  for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
    const wd  = d.getDay()
    const key = d.toISOString().slice(0, 10)
    if (skipHolidays.value && HOLIDAYS[key]) { out.holidayHits.push({ key, name: HOLIDAYS[key] }); continue }
    if (!selectedWeekdays.value.includes(wd)) { if (wd === 0 || wd === 6) out.weekends++; continue }
    const base = OPEN_SLOTS_30[wd] ?? 0
    if (base === 0) { out.closedWeekdays++; continue }
    out.workdays++
    out.slots += Math.round(base * slotFactor)
  }
  return out
})

const toggleDay = (i: number) => {
  const idx = selectedWeekdays.value.indexOf(i)
  if (idx >= 0) selectedWeekdays.value.splice(idx, 1)
  else selectedWeekdays.value.push(i)
}

const generate = () => {
  if (!selectedPrac.value) return
  router.post('/serientermine', {
    practitioner_id: selectedPrac.value,
    date_from: dateFrom.value,
    date_to: dateTo.value,
    weekdays: selectedWeekdays.value,
    duration_min: durationMin.value,
    slot_count: plan.value.slots,
  })
}

const formatDate = (key: string) => {
  const [y, m, d] = key.split('-')
  return `${d}.${m}.${y}`
}
</script>

<template>
  <Head title="Serientermine" />

  <div class="p-8">
    <!-- Header -->
    <h1 class="text-3xl font-bold text-slate-900 tracking-tight mb-2">Serientermine generieren</h1>
    <p class="text-sm text-slate-500 mb-8 max-w-[620px]">
      Legen Sie freie Termin-Slots für einen ganzen Zeitraum auf einmal an. Feiertage, Wochenenden und Praxisschließungen werden automatisch ausgelassen. 🦷
    </p>

    <!-- Grid 2 cols -->
    <div class="grid gap-6" style="grid-template-columns: 1.3fr 1fr; align-items: start; max-width: 1000px">

      <!-- ===== LEFT: Form card ===== -->
      <div class="bg-white rounded-ds-card border border-slate-200/70 shadow-card p-6 space-y-5">

        <!-- Behandler -->
        <div>
          <div class="text-sm font-medium text-slate-800 mb-2">Behandler</div>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="p in practitioners"
              :key="p.id"
              type="button"
              @click="selectedPrac = p.id"
              class="flex items-center gap-2 px-3.5 py-2 rounded-full text-sm font-medium border transition-colors"
              :class="selectedPrac === p.id
                ? 'bg-[color-mix(in_srgb,#98ACBA_25%,white)] border-transparent text-slate-800'
                : 'bg-white border-slate-200 text-slate-700'"
            >
              <span class="w-2 h-2 rounded-full shrink-0" :style="{ background: p.color ?? '#98ACBA' }"></span>
              {{ p.name }}
            </button>
          </div>
        </div>

        <!-- Zeitraum -->
        <div>
          <div class="text-sm font-medium text-slate-800 mb-2">Zeitraum</div>
          <div class="flex gap-3 flex-wrap items-center">
            <input
              type="date"
              v-model="dateFrom"
              class="border border-slate-200 rounded-[8px] px-3 py-2 text-sm text-slate-800"
            />
            <span class="text-slate-400 text-sm">bis</span>
            <input
              type="date"
              v-model="dateTo"
              class="border border-slate-200 rounded-[8px] px-3 py-2 text-sm text-slate-800"
            />
          </div>
          <div class="flex gap-2 mt-2.5">
            <button
              v-for="m in [1, 2, 3]"
              :key="m"
              type="button"
              @click="dateTo = plusMonths(m)"
              class="border border-slate-200 bg-white rounded-full px-3.5 py-1.5 text-[13px] font-medium text-slate-700"
            >
              {{ m }} {{ m === 1 ? 'Monat' : 'Monate' }}
            </button>
          </div>
        </div>

        <!-- Wochentage -->
        <div>
          <div class="text-sm font-medium text-slate-800 mb-2">Wochentage</div>
          <div class="flex gap-2">
            <button
              v-for="w in WD"
              :key="w.i"
              type="button"
              @click="toggleDay(w.i)"
              class="w-11 h-11 rounded-xl text-sm font-semibold border transition-colors"
              :class="selectedWeekdays.includes(w.i)
                ? 'bg-slate-900 text-white border-transparent'
                : (OPEN_SLOTS_30[w.i] === 0 ? 'bg-white text-slate-400 border-slate-200 opacity-60' : 'bg-white text-slate-700 border-slate-200')"
              :title="OPEN_SLOTS_30[w.i] === 0 ? 'Praxis geschlossen' : ''"
            >
              {{ w.l }}
            </button>
          </div>
        </div>

        <!-- Slot-Dauer -->
        <div>
          <div class="text-sm font-medium text-slate-800 mb-2">Slot-Dauer</div>
          <select
            v-model="durationMin"
            class="border border-slate-200 rounded-[8px] px-3 py-2 text-sm text-slate-800 w-full"
          >
            <option v-for="m in [15, 20, 30, 45, 60]" :key="m" :value="m">{{ m }} Minuten</option>
          </select>
        </div>

        <!-- Ausschlüsse -->
        <div class="space-y-3 border-t border-slate-100 pt-4">
          <label class="flex items-start gap-2.5 cursor-pointer">
            <input type="checkbox" v-model="skipHolidays" class="mt-0.5 accent-blue-700" />
            <span>
              <span class="block text-sm font-medium text-slate-800">Feiertage ausschließen</span>
              <span class="block text-xs text-slate-500">Gesetzliche Feiertage werden übersprungen</span>
            </span>
          </label>
          <label class="flex items-start gap-2.5 cursor-pointer">
            <input type="checkbox" v-model="skipAbsences" class="mt-0.5 accent-blue-700" />
            <span>
              <span class="block text-sm font-medium text-slate-800">Abwesenheiten berücksichtigen</span>
              <span class="block text-xs text-slate-500">Urlaub &amp; Fortbildung des Behandlers auslassen</span>
            </span>
          </label>
        </div>
      </div>

      <!-- ===== RIGHT: Preview card ===== -->
      <div>
        <div class="bg-white rounded-ds-card border border-slate-200/70 shadow-card p-6">
          <div class="text-[11px] font-semibold uppercase tracking-widest text-slate-400 mb-2">Vorschau</div>
          <div class="flex items-baseline gap-2 mb-1">
            <span class="text-5xl font-bold text-slate-900 tabular-nums tracking-tight">{{ plan.slots }}</span>
            <span class="text-[15px] text-slate-500">Termine</span>
          </div>
          <div class="text-sm text-slate-500 mb-4">
            an <strong class="text-slate-700">{{ plan.workdays }}</strong> Arbeitstagen
            · {{ practitioners.find(p => p.id === selectedPrac)?.name ?? '–' }}
          </div>

          <!-- Stats rows -->
          <div class="space-y-2 mb-4">
            <div class="flex items-center justify-between text-sm">
              <span class="flex items-center gap-2 text-slate-500">
                <span class="w-2 h-2 rounded-full bg-rose-400 shrink-0"></span>
                Feiertage übersprungen
              </span>
              <span class="font-semibold text-slate-700 tabular-nums">{{ plan.holidayHits.length }}</span>
            </div>
            <div class="flex items-center justify-between text-sm">
              <span class="flex items-center gap-2 text-slate-500">
                <span class="w-2 h-2 rounded-full bg-slate-300 shrink-0"></span>
                Wochenenden
              </span>
              <span class="font-semibold text-slate-700 tabular-nums">{{ plan.weekends }}</span>
            </div>
            <div class="flex items-center justify-between text-sm">
              <span class="flex items-center gap-2 text-slate-500">
                <span class="w-2 h-2 rounded-full bg-[#F7E29D] shrink-0"></span>
                geschl. Wochentage
              </span>
              <span class="font-semibold text-slate-700 tabular-nums">{{ plan.closedWeekdays }}</span>
            </div>
          </div>

          <!-- Generate button -->
          <button
            @click="generate"
            type="button"
            class="w-full inline-flex items-center justify-center gap-2 bg-blue-700 text-white rounded-[8px] py-2.5 text-sm font-medium"
          >
            <CalendarPlus class="w-4 h-4" :stroke-width="1.75" />
            {{ plan.slots }} Termine generieren
          </button>
        </div>

        <!-- Holidays card -->
        <div
          v-if="plan.holidayHits.length > 0"
          class="bg-white rounded-ds-card border border-slate-200/70 shadow-card p-5 mt-4"
        >
          <div class="text-sm font-bold text-slate-800 mb-3">Ausgelassene Feiertage</div>
          <ul class="space-y-2">
            <li
              v-for="h in plan.holidayHits"
              :key="h.key"
              class="flex items-center justify-between text-sm"
            >
              <span class="flex items-center gap-2 text-slate-700">
                <span class="w-2 h-2 rounded-full bg-rose-400 shrink-0"></span>
                {{ h.name }}
              </span>
              <span class="text-slate-400 tabular-nums">{{ formatDate(h.key) }}</span>
            </li>
          </ul>
        </div>
      </div>

    </div>
  </div>
</template>
