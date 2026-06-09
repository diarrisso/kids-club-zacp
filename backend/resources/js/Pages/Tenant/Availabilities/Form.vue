<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router, Head } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import Card from '@/components/ui/Card.vue'
import FormField from '@/components/ui/FormField.vue'
import PrimaryButton from '@/components/ui/PrimaryButton.vue'

defineOptions({ layout: TenantLayout })

const props = defineProps<{
  availability: null | {
    id: number
    practitioner_id: number
    day_of_week: number
    start_time: string
    end_time: string
    slot_interval_minutes?: number | null
  }
  practitioners: Array<{ id: number; first_name: string; last_name: string; title: string }>
}>()

// ─── Shared constants ───────────────────────────────────────────────────────

const DAY_LABELS: Record<number, string> = { 1: 'Mo', 2: 'Di', 3: 'Mi', 4: 'Do', 5: 'Fr', 6: 'Sa', 7: 'So' }
const DAY_FULL: Record<number, string> = {
  1: 'Montag', 2: 'Dienstag', 3: 'Mittwoch', 4: 'Donnerstag',
  5: 'Freitag', 6: 'Samstag', 7: 'Sonntag',
}
const ALL_DAYS = [1, 2, 3, 4, 5, 6, 7]

// ─── EDIT MODE ───────────────────────────────────────────────────────────────

const editForm = useForm({
  practitioner_id: props.availability?.practitioner_id ?? props.practitioners[0]?.id ?? null,
  day_of_week: props.availability?.day_of_week ?? 1,
  start_time: props.availability?.start_time ?? '09:00',
  end_time: props.availability?.end_time ?? '17:00',
  slot_interval_minutes: props.availability?.slot_interval_minutes ?? null as number | null,
})

const submitEdit = () => {
  editForm.put(`/sprechzeiten/${props.availability!.id}`)
}

// ─── CREATE MODE ─────────────────────────────────────────────────────────────

const today = new Date().toISOString().slice(0, 10)

// Day selection
const selectedDays = ref<number[]>([1, 2, 3, 4, 5])
const toggleDay = (day: number) => {
  const idx = selectedDays.value.indexOf(day)
  if (idx === -1) selectedDays.value.push(day)
  else selectedDays.value.splice(idx, 1)
  selectedDays.value.sort((a, b) => a - b)
}

// Hours mode: 'same' | 'per-day'
const hoursMode = ref<'same' | 'per-day'>('same')
const sharedStart = ref('09:00')
const sharedEnd = ref('17:00')

// Per-day hours keyed by weekday int
const perDayHours = ref<Record<number, { start: string; end: string }>>(
  Object.fromEntries(ALL_DAYS.map(d => [d, { start: '09:00', end: '17:00' }]))
)

// Period
const createPractitionerId = ref<number | null>(props.practitioners[0]?.id ?? null)
const validFrom = ref(today)

type DurationOption = '1' | '2' | '3' | '4' | '6' | '12' | 'custom' | 'unlimited'
const durationChoice = ref<DurationOption>('3')
const customEnd = ref('')

const durationOptions: Array<{ value: DurationOption; label: string }> = [
  { value: '1', label: '1 Monat' },
  { value: '2', label: '2 Monate' },
  { value: '3', label: '3 Monate' },
  { value: '4', label: '4 Monate' },
  { value: '6', label: '6 Monate' },
  { value: '12', label: '1 Jahr' },
  { value: 'custom', label: 'Benutzerdefiniert…' },
  { value: 'unlimited', label: 'Unbegrenzt' },
]

const computedValidTo = computed<string | null>(() => {
  if (durationChoice.value === 'unlimited') return null
  if (durationChoice.value === 'custom') return customEnd.value || null

  const months = parseInt(durationChoice.value, 10)
  const from = new Date(validFrom.value)
  if (isNaN(from.getTime())) return null
  const to = new Date(from)
  to.setMonth(to.getMonth() + months)
  to.setDate(to.getDate() - 1)
  return to.toISOString().slice(0, 10)
})

const validToDisplay = computed(() => {
  if (!computedValidTo.value) return null
  const [y, m, d] = computedValidTo.value.split('-')
  return `${d}.${m}.${y}`
})

// Slot interval
const createSlotInterval = ref<number | null>(null)

// Server-side validation errors
const createErrors = ref<Record<string, string>>({})

// Submit label
const sortedSelectedDays = computed(() => [...selectedDays.value].sort((a, b) => a - b))

const submitLabel = computed(() => {
  const n = selectedDays.value.length
  if (n === 0) return 'Bitte Tage auswählen'
  const names = sortedSelectedDays.value.map(d => DAY_LABELS[d]).join(', ')
  return `${n} Sprechzeit${n !== 1 ? 'en' : ''} anlegen (${names})`
})

const isCreating = ref(false)

const submitCreate = () => {
  createErrors.value = {}

  if (selectedDays.value.length === 0) {
    createErrors.value.days = 'Bitte mindestens einen Tag auswählen.'
    return
  }

  const shared = {
    practitioner_id: createPractitionerId.value,
    valid_from: validFrom.value,
    valid_to: computedValidTo.value,
    slot_interval_minutes: createSlotInterval.value,
  }

  let payload: Record<string, unknown>

  if (hoursMode.value === 'same') {
    payload = {
      ...shared,
      days: selectedDays.value,
      start_time: sharedStart.value,
      end_time: sharedEnd.value,
    }
  } else {
    const days_hours: Record<number, { start: string; end: string }> = {}
    for (const d of selectedDays.value) {
      days_hours[d] = perDayHours.value[d]
    }
    payload = { ...shared, days_hours }
  }

  isCreating.value = true
  router.post('/sprechzeiten', payload, {
    onError: (e) => {
      createErrors.value = e as Record<string, string>
      isCreating.value = false
    },
    onSuccess: () => {
      isCreating.value = false
    },
  })
}
</script>

<template>
  <Head :title="availability ? 'Sprechzeit bearbeiten' : 'Neue Sprechzeiten'" />
  <div class="p-8 max-w-2xl">
    <h1 class="text-3xl font-bold mb-6">
      {{ availability ? 'Sprechzeit bearbeiten' : 'Neue Sprechzeiten' }}
    </h1>

    <!-- ─── EDIT MODE ──────────────────────────────────────────────────── -->
    <Card v-if="availability" as="form" @submit.prevent="submitEdit">
      <FormField label="Behandler" required :error="editForm.errors.practitioner_id">
        <select v-model.number="editForm.practitioner_id" class="w-full p-2 border rounded">
          <option v-for="p in practitioners" :key="p.id" :value="p.id">
            {{ p.title }} {{ p.first_name }} {{ p.last_name }}
          </option>
        </select>
      </FormField>

      <FormField label="Wochentag" required :error="editForm.errors.day_of_week">
        <select v-model.number="editForm.day_of_week" class="w-full p-2 border rounded">
          <option v-for="(label, val) in DAY_FULL" :key="val" :value="Number(val)">{{ label }}</option>
        </select>
      </FormField>

      <div class="grid grid-cols-2 gap-4">
        <FormField label="Von" required :error="editForm.errors.start_time">
          <input v-model="editForm.start_time" type="time" required class="w-full p-2 border rounded">
        </FormField>
        <FormField label="Bis" required :error="editForm.errors.end_time">
          <input v-model="editForm.end_time" type="time" required class="w-full p-2 border rounded">
        </FormField>
      </div>

      <FormField label="Slot-Intervall">
        <div class="flex gap-4">
          <label class="flex items-center gap-1 cursor-pointer">
            <input type="radio" :value="null" v-model="editForm.slot_interval_minutes" class="accent-slate-800">
            <span class="text-sm">Standard</span>
          </label>
          <label class="flex items-center gap-1 cursor-pointer">
            <input type="radio" :value="20" v-model="editForm.slot_interval_minutes" class="accent-slate-800">
            <span class="text-sm">20 Min.</span>
          </label>
          <label class="flex items-center gap-1 cursor-pointer">
            <input type="radio" :value="30" v-model="editForm.slot_interval_minutes" class="accent-slate-800">
            <span class="text-sm">30 Min.</span>
          </label>
        </div>
        <p class="text-xs text-gray-500 mt-1">Abstand zwischen Terminen, unabhängig von der Leistungsdauer.</p>
      </FormField>

      <PrimaryButton :disabled="editForm.processing">Speichern</PrimaryButton>
    </Card>

    <!-- ─── CREATE MODE ───────────────────────────────────────────────── -->
    <template v-else>
      <Card>
        <!-- Behandler -->
        <FormField label="Behandler" required :error="createErrors.practitioner_id">
          <select v-model.number="createPractitionerId" class="w-full p-2 border rounded">
            <option v-for="p in practitioners" :key="p.id" :value="p.id">
              {{ p.title }} {{ p.first_name }} {{ p.last_name }}
            </option>
          </select>
        </FormField>

        <!-- Day pills -->
        <FormField label="Wochentage" required :error="createErrors.days ?? createErrors['days_hours']">
          <div class="flex gap-2 flex-wrap">
            <button
              v-for="day in ALL_DAYS"
              :key="day"
              type="button"
              @click="toggleDay(day)"
              :class="[
                'px-3 py-1.5 rounded text-sm font-medium border transition-colors',
                selectedDays.includes(day)
                  ? 'bg-slate-800 text-white border-slate-800'
                  : 'bg-white text-slate-700 border-slate-300 hover:border-slate-500'
              ]"
            >
              {{ DAY_LABELS[day] }}
            </button>
          </div>
        </FormField>

        <!-- Hours section -->
        <div class="border border-slate-200 rounded-md p-4 space-y-4">
          <!-- Mode toggle -->
          <div class="flex gap-0 rounded overflow-hidden border border-slate-300 w-fit text-sm">
            <button
              type="button"
              @click="hoursMode = 'same'"
              :class="[
                'px-4 py-1.5 transition-colors',
                hoursMode === 'same' ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'
              ]"
            >
              Gleiche Zeiten
            </button>
            <button
              type="button"
              @click="hoursMode = 'per-day'"
              :class="[
                'px-4 py-1.5 transition-colors border-l border-slate-300',
                hoursMode === 'per-day' ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'
              ]"
            >
              Unterschiedliche Zeiten
            </button>
          </div>

          <!-- Mode A: same hours -->
          <div v-if="hoursMode === 'same'" class="grid grid-cols-2 gap-4">
            <FormField label="Von" required :error="createErrors.start_time">
              <input v-model="sharedStart" type="time" class="w-full p-2 border rounded">
            </FormField>
            <FormField label="Bis" required :error="createErrors.end_time">
              <input v-model="sharedEnd" type="time" class="w-full p-2 border rounded">
            </FormField>
          </div>

          <!-- Mode B: per-day hours -->
          <div v-else class="space-y-3">
            <div v-if="selectedDays.length === 0" class="text-sm text-slate-500 italic">
              Bitte zuerst Tage auswählen.
            </div>
            <div
              v-for="day in sortedSelectedDays"
              :key="day"
              class="grid grid-cols-[5rem_1fr_1fr] items-center gap-3"
            >
              <span class="text-sm font-medium text-slate-700">{{ DAY_FULL[day] }}</span>
              <FormField label="Von" :error="createErrors[`days_hours.${day}.start`]">
                <input v-model="perDayHours[day].start" type="time" class="w-full p-2 border rounded">
              </FormField>
              <FormField label="Bis" :error="createErrors[`days_hours.${day}.end`]">
                <input v-model="perDayHours[day].end" type="time" class="w-full p-2 border rounded">
              </FormField>
            </div>
          </div>
        </div>

        <!-- Period -->
        <div class="grid grid-cols-2 gap-4">
          <FormField label="Gültig ab" required :error="createErrors.valid_from">
            <input v-model="validFrom" type="date" class="w-full p-2 border rounded">
          </FormField>
          <FormField label="Dauer" :error="createErrors.valid_to">
            <select v-model="durationChoice" class="w-full p-2 border rounded">
              <option v-for="opt in durationOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </FormField>
        </div>

        <!-- Custom end date -->
        <FormField v-if="durationChoice === 'custom'" label="Enddatum" required :error="createErrors.valid_to">
          <input v-model="customEnd" type="date" class="w-full p-2 border rounded">
        </FormField>

        <!-- Valid-to hint -->
        <p v-if="validToDisplay" class="text-sm text-slate-500">
          → Gültig bis {{ validToDisplay }}
        </p>

        <!-- Slot interval -->
        <FormField label="Slot-Intervall">
          <div class="flex gap-4">
            <label class="flex items-center gap-1 cursor-pointer">
              <input type="radio" :value="null" v-model="createSlotInterval" class="accent-slate-800">
              <span class="text-sm">Standard</span>
            </label>
            <label class="flex items-center gap-1 cursor-pointer">
              <input type="radio" :value="20" v-model="createSlotInterval" class="accent-slate-800">
              <span class="text-sm">20 Min.</span>
            </label>
            <label class="flex items-center gap-1 cursor-pointer">
              <input type="radio" :value="30" v-model="createSlotInterval" class="accent-slate-800">
              <span class="text-sm">30 Min.</span>
            </label>
          </div>
          <p class="text-xs text-gray-500 mt-1">Abstand zwischen Terminen, unabhängig von der Leistungsdauer.</p>
        </FormField>

        <!-- Submit -->
        <PrimaryButton
          type="button"
          :disabled="selectedDays.length === 0 || isCreating"
          @click="submitCreate"
        >
          {{ submitLabel }}
        </PrimaryButton>
      </Card>
    </template>
  </div>
</template>
