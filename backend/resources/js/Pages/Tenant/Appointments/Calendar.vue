<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import interactionPlugin from '@fullcalendar/interaction'
import deLocale from '@fullcalendar/core/locales/de'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import { toCalendarEvent, type AppointmentDto } from '@/lib/calendar'

defineOptions({ layout: TenantLayout })

const props = defineProps<{
    practitioners: Array<{ id: number; name: string; color: string }>
    services: Array<{ id: number; name: string; duration_minutes: number }>
}>()

const calendarRef = ref()
const activePractitioners = ref<number[]>(props.practitioners.map((p) => p.id))

const togglePractitioner = (id: number) => {
    const i = activePractitioners.value.indexOf(id)
    if (i === -1) activePractitioners.value.push(id)
    else activePractitioners.value.splice(i, 1)
    calendarRef.value?.getApi().refetchEvents()
}

const fetchEvents = async (
    info: { startStr: string; endStr: string },
    success: (e: any[]) => void,
    failure: (e: any) => void,
) => {
    try {
        const { data } = await window.axios.get('/termine/events', {
            params: { start: info.startStr, end: info.endStr, practitioner_ids: activePractitioners.value },
        })
        success((data as AppointmentDto[]).map(toCalendarEvent))
    } catch (e) {
        failure(e)
    }
}

const calendarOptions = computed(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
    initialView: 'timeGridWeek',
    locale: deLocale,
    timeZone: 'Europe/Berlin',
    firstDay: 1,
    nowIndicator: true,
    slotMinTime: '07:00:00',
    slotMaxTime: '20:00:00',
    allDaySlot: false,
    height: 'auto',
    headerToolbar: { left: 'prev,next today', center: 'title', right: 'timeGridDay,timeGridWeek,dayGridMonth' },
    events: fetchEvents,
}))
</script>

<template>
    <Head title="Termine" />
    <div class="p-8">
        <h1 class="text-3xl font-bold mb-6">Termine</h1>

        <div class="flex flex-wrap gap-4 mb-4">
            <label v-for="p in props.practitioners" :key="p.id" class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="activePractitioners.includes(p.id)" @change="togglePractitioner(p.id)" />
                <span class="inline-block w-3 h-3 rounded-full" :style="{ background: p.color }"></span>
                {{ p.name }}
            </label>
        </div>

        <div class="bg-white rounded shadow p-4">
            <FullCalendar ref="calendarRef" :options="calendarOptions" />
        </div>
    </div>
</template>
