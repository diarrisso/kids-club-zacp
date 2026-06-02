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
import AppointmentForm from './AppointmentForm.vue'

defineOptions({ layout: TenantLayout })

const props = defineProps<{
    practitioners: Array<{ id: number; name: string; color: string }>
    services: Array<{ id: number; name: string; duration_minutes: number }>
}>()

const calendarRef = ref()
const activePractitioners = ref<number[]>(props.practitioners.map((p) => p.id))

// modal state
const formOpen = ref(false)
const editing = ref<AppointmentDto | null>(null)
const prefill = ref<{ starts_at?: string; practitioner_id?: number } | null>(null)

const refetch = () => calendarRef.value?.getApi().refetchEvents()

const togglePractitioner = (id: number) => {
    const i = activePractitioners.value.indexOf(id)
    if (i === -1) activePractitioners.value.push(id)
    else activePractitioners.value.splice(i, 1)
    refetch()
}

const fetchEvents = async (
    info: { startStr: string; endStr: string },
    success: (e: any[]) => void,
    failure: (e: any) => void,
) => {
    // No practitioner selected = show nothing (an empty practitioner_ids would
    // otherwise be dropped server-side and return everyone).
    if (activePractitioners.value.length === 0) {
        success([])
        return
    }
    try {
        const { data } = await window.axios.get('/termine/events', {
            params: { start: info.startStr, end: info.endStr, practitioner_ids: activePractitioners.value },
        })
        success((data as AppointmentDto[]).map(toCalendarEvent))
    } catch (e) {
        failure(e)
    }
}

const openCreate = (startStr: string) => {
    editing.value = null
    prefill.value = {
        starts_at: startStr,
        practitioner_id: activePractitioners.value.length === 1 ? activePractitioners.value[0] : undefined,
    }
    formOpen.value = true
}

const openEdit = (dto: AppointmentDto) => {
    editing.value = dto
    prefill.value = null
    formOpen.value = true
}

const onDrop = async (info: any) => {
    try {
        await window.axios.patch(`/termine/${info.event.id}`, {
            starts_at: info.event.startStr,
            ends_at: info.event.endStr,
        })
    } catch (e) {
        info.revert()
    }
}

const onSaved = () => {
    formOpen.value = false
    refetch()
}

const calendarOptions = computed(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
    initialView: 'timeGridWeek',
    locale: deLocale,
    timeZone: 'Europe/Berlin',
    firstDay: 1,
    nowIndicator: true,
    selectable: true,
    editable: true,
    eventDurationEditable: true,
    slotMinTime: '07:00:00',
    slotMaxTime: '20:00:00',
    allDaySlot: false,
    height: 'auto',
    headerToolbar: { left: 'prev,next today', center: 'title', right: 'timeGridDay,timeGridWeek,dayGridMonth' },
    events: fetchEvents,
    dateClick: (arg: any) => openCreate(arg.dateStr),
    eventClick: (arg: any) => openEdit(arg.event.extendedProps as AppointmentDto),
    eventDrop: onDrop,
    eventResize: onDrop,
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

        <AppointmentForm
            :open="formOpen"
            :practitioners="props.practitioners"
            :services="props.services"
            :appointment="editing"
            :prefill="prefill"
            @close="formOpen = false"
            @saved="onSaved"
        />
    </div>
</template>
