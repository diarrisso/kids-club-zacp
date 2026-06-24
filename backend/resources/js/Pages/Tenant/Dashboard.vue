<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import {
  CalendarDays,
  CalendarRange,
  Clock,
  Stethoscope,
  Plus,
  Calendar,
  QrCode,
  ArrowRight,
  Smile,
} from 'lucide-vue-next'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import StatCard from '@/components/ui/StatCard.vue'
import RoomLegend from '@/components/ui/RoomLegend.vue'
import { NEUTRAL_ROOM_COLOR } from '@/lib/rooms'

defineOptions({ layout: TenantLayout })

interface RoomOption { value: string; color: string; label: string }
interface TodayRow {
  id: string
  time: string
  patient: string
  service: string
  room: string | null
  practitioner: { name: string; color: string }
}

const props = defineProps<{
  role: string
  practitioner: { id: number; name: string; color: string } | null
  stats: {
    todayCount: number
    weekCount: number
    activePractitioners: number
    nextAppointment: { time: string; patient: string; service: string } | null
  }
  todayAppointments: TodayRow[]
  rooms: RoomOption[]
}>()

/** Resolve a stored room value to its option row ({value,color,label}), or null when unset. */
const roomMeta = (value: string | null) =>
  props.rooms.find((r) => r.value === value) ?? null
/** Resolve a stored room value to its hex color, falling back to the neutral fill. */
const roomColor = (value: string | null) => roomMeta(value)?.color ?? NEUTRAL_ROOM_COLOR

// Soft, friendly time-of-day greeting (clinic runs in the morning/afternoon).
const hour = new Date().getHours()
const timeGreeting = computed(() => {
  if (hour < 11) return 'Guten Morgen'
  if (hour < 17) return 'Guten Tag'
  return 'Guten Abend'
})

const firstName = computed(() => props.practitioner?.name.split(' ').slice(-1)[0] ?? '')

const greeting = computed(() =>
  props.practitioner
    ? `${timeGreeting.value}, ${props.practitioner.name}`
    : `${timeGreeting.value} · Empfang`,
)

const today = new Date().toLocaleDateString('de-DE', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  year: 'numeric',
})

const quickActions = [
  { href: '/termine', label: 'Neuer Termin', hint: 'Termin anlegen', icon: Plus, tint: 'bg-kids-blue' },
  { href: '/termine', label: 'Kalender öffnen', hint: 'Wochenübersicht', icon: Calendar, tint: 'bg-kids-green' },
  { href: '/termin-qr-code', label: 'QR-Code', hint: 'Zum Buchen teilen', icon: QrCode, tint: 'bg-kids-purple' },
]
</script>

<template>
  <Head title="Dashboard" />

  <div class="dashboard-stage mx-auto max-w-6xl space-y-7 p-6 sm:p-8">
    <!-- ── Greeting hero ─────────────────────────────────────────── -->
    <header
      class="relative overflow-hidden rounded-[2rem] border border-slate-200/70 bg-white px-6 py-7 sm:px-9 sm:py-9"
      style="animation-delay: 0ms"
    >
      <!-- decorative pastel blobs for warmth & depth -->
      <span aria-hidden="true" class="pointer-events-none absolute -right-10 -top-16 h-48 w-48 rounded-full bg-kids-blue/30 blur-3xl"></span>
      <span aria-hidden="true" class="pointer-events-none absolute right-24 -bottom-20 h-40 w-40 rounded-full bg-kids-peach/50 blur-3xl"></span>
      <span aria-hidden="true" class="pointer-events-none absolute -left-12 top-8 h-32 w-32 rounded-full bg-kids-yellow/30 blur-3xl"></span>

      <div class="relative flex flex-wrap items-start justify-between gap-5">
        <div class="space-y-2">
          <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-kids-blue/25 px-3 py-1 text-xs font-semibold text-slate-700">
              <span
                v-if="practitioner"
                class="h-2 w-2 rounded-full ring-2 ring-white"
                :style="{ background: practitioner.color }"
                aria-hidden="true"
              ></span>
              <Smile v-else class="h-3.5 w-3.5" :stroke-width="2" aria-hidden="true" />
              {{ practitioner ? 'Behandler' : 'Admin' }}
            </span>
            <span class="text-sm capitalize text-slate-400">{{ today }}</span>
          </div>
          <h1 class="text-2xl font-bold tracking-tight text-slate-800 sm:text-3xl">
            {{ greeting }}
          </h1>
          <p class="max-w-md text-sm text-slate-500">
            <template v-if="stats.todayCount">
              {{ firstName ? firstName + ', heute' : 'Heute' }} stehen
              <span class="font-semibold text-slate-700">{{ stats.todayCount }}</span>
              {{ stats.todayCount === 1 ? 'Termin' : 'Termine' }} an. Alles im Blick. 🦷
            </template>
            <template v-else>
              Heute ist es ruhig im Kids&nbsp;Club – Zeit für ein Lächeln. 🦷
            </template>
          </p>
        </div>

        <!-- Next appointment spotlight -->
        <div
          class="relative w-full max-w-xs rounded-3xl border border-slate-200/70 bg-gradient-to-br from-kids-blue/25 to-kids-green/20 p-5 sm:w-auto"
        >
          <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
            <Clock class="h-4 w-4" :stroke-width="2" aria-hidden="true" />
            Nächster Termin
          </div>
          <template v-if="stats.nextAppointment">
            <div class="mt-2 flex items-baseline gap-2">
              <span class="text-3xl font-bold tabular-nums tracking-tight text-slate-800">{{ stats.nextAppointment.time }}</span>
              <span class="text-sm text-slate-500">Uhr</span>
            </div>
            <div class="mt-1 truncate text-sm font-medium text-slate-700">{{ stats.nextAppointment.patient }}</div>
            <div class="truncate text-xs text-slate-500">{{ stats.nextAppointment.service }}</div>
          </template>
          <template v-else>
            <div class="mt-3 text-sm font-medium text-slate-600">Kein weiterer Termin heute</div>
            <div class="text-xs text-slate-500">Schönen Feierabend!</div>
          </template>
        </div>
      </div>
    </header>

    <!-- ── KPI stat cards ────────────────────────────────────────── -->
    <section
      class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
      style="animation-delay: 70ms"
      aria-label="Kennzahlen"
    >
      <StatCard :icon="CalendarDays" :value="`${stats.todayCount} Termine`" label="Heute" color="bg-kids-blue" />
      <StatCard :icon="CalendarRange" :value="`${stats.weekCount} Termine`" label="Diese Woche" color="bg-kids-green" />
      <StatCard :icon="Stethoscope" :value="`${stats.activePractitioners} aktiv`" label="Behandler" color="bg-kids-peach" />
      <StatCard
        :icon="Clock"
        :value="stats.nextAppointment ? `${stats.nextAppointment.time} Uhr` : '—'"
        label="Nächster Termin"
        color="bg-kids-yellow"
      />
    </section>

    <!-- ── Main grid: today list + side rail ─────────────────────── -->
    <section class="grid gap-6 lg:grid-cols-3" style="animation-delay: 140ms">
      <!-- Today timeline -->
      <div class="rounded-[1.75rem] border border-slate-200/70 bg-white p-6 lg:col-span-2">
        <div class="mb-5 flex items-center justify-between">
          <div>
            <h2 class="text-lg font-bold tracking-tight text-slate-800">Termine heute</h2>
            <p class="text-sm text-slate-400">{{ today }}</p>
          </div>
          <Link
            href="/termine"
            class="group inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-sm font-medium text-slate-500
                   transition hover:bg-kids-blue/20 hover:text-slate-700
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-kids-blue focus-visible:ring-offset-2"
          >
            Kalender
            <ArrowRight class="h-4 w-4 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none" :stroke-width="2" />
          </Link>
        </div>

        <ul v-if="todayAppointments.length" class="space-y-2.5">
          <li
            v-for="row in todayAppointments"
            :key="row.id"
            class="group flex items-center gap-4 rounded-2xl border border-slate-100 bg-slate-50/40 p-3
                   transition-colors duration-200 hover:bg-slate-50"
          >
            <!-- room color rail -->
            <span
              class="h-11 w-1.5 shrink-0 rounded-full"
              :style="{ background: roomColor(row.room) }"
              aria-hidden="true"
            ></span>
            <span class="w-12 shrink-0 text-sm font-bold tabular-nums text-slate-700">{{ row.time }}</span>
            <div class="min-w-0 flex-1">
              <div class="truncate font-medium text-slate-800">{{ row.patient }}</div>
              <div class="flex items-center gap-1.5 truncate text-sm text-slate-500">
                <span class="truncate">{{ row.service }}</span>
                <template v-if="roomMeta(row.room)">
                  <span class="text-slate-300" aria-hidden="true">·</span>
                  <span class="truncate">{{ roomMeta(row.room)?.label }}</span>
                </template>
              </div>
            </div>
            <span
              class="flex shrink-0 items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600
                     ring-1 ring-slate-200/80"
            >
              <span class="h-2 w-2 rounded-full" :style="{ background: row.practitioner.color }" aria-hidden="true"></span>
              <span class="hidden truncate sm:inline">{{ row.practitioner.name }}</span>
            </span>
          </li>
        </ul>

        <!-- polished empty state -->
        <div v-else class="flex flex-col items-center justify-center gap-3 py-12 text-center">
          <div class="flex h-16 w-16 items-center justify-center rounded-full bg-kids-peach/60">
            <Smile class="h-8 w-8 text-slate-500" :stroke-width="1.5" aria-hidden="true" />
          </div>
          <div>
            <p class="font-semibold text-slate-700">Heute keine Termine</p>
            <p class="text-sm text-slate-400">Ein ruhiger Tag im Kids&nbsp;Club.</p>
          </div>
          <Link
            href="/termine"
            class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-kids-blue/25 px-4 py-2 text-sm font-medium text-slate-700
                   transition hover:bg-kids-blue/40
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-kids-blue focus-visible:ring-offset-2"
          >
            <Plus class="h-4 w-4" :stroke-width="2" /> Termin anlegen
          </Link>
        </div>
      </div>

      <!-- Side rail: quick actions + room legend -->
      <aside class="space-y-6">
        <div class="rounded-[1.75rem] border border-slate-200/70 bg-white p-6">
          <h2 class="mb-4 text-lg font-bold tracking-tight text-slate-800">Schnellzugriff</h2>
          <div class="space-y-2.5">
            <Link
              v-for="action in quickActions"
              :key="action.label + action.href"
              :href="action.href"
              class="group flex items-center gap-3 rounded-2xl border border-transparent p-2.5
                     transition-all duration-200 hover:border-slate-200/70 hover:bg-slate-50
                     focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-kids-blue focus-visible:ring-offset-2"
            >
              <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition-transform duration-200 group-hover:scale-105
                       motion-reduce:transition-none motion-reduce:group-hover:scale-100"
                :class="action.tint"
              >
                <component :is="action.icon" class="h-5 w-5 text-slate-700" :stroke-width="1.75" aria-hidden="true" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate font-medium text-slate-800">{{ action.label }}</span>
                <span class="block truncate text-xs text-slate-400">{{ action.hint }}</span>
              </span>
              <ArrowRight
                class="h-4 w-4 shrink-0 text-slate-300 transition-all group-hover:translate-x-0.5 group-hover:text-slate-500 motion-reduce:transition-none"
                :stroke-width="2"
                aria-hidden="true"
              />
            </Link>
          </div>
        </div>

        <div class="rounded-[1.75rem] border border-slate-200/70 bg-white p-6">
          <h2 class="mb-1 text-lg font-bold tracking-tight text-slate-800">Zimmer</h2>
          <p class="mb-4 text-xs text-slate-400">Die fünf Farben des Kids&nbsp;Club</p>
          <RoomLegend :rooms="rooms" />
        </div>
      </aside>
    </section>
  </div>
</template>

<style scoped>
@keyframes rise {
  from {
    opacity: 0;
    transform: translateY(12px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
.dashboard-stage > * {
  animation: rise 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
}
@media (prefers-reduced-motion: reduce) {
  .dashboard-stage > * {
    animation: none !important;
  }
}
</style>
