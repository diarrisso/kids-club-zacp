import { roomColor } from './rooms'

export interface PractitionerRef { id: number; name: string; color: string }
export interface ServiceRef { id: number; name: string; duration_minutes: number }

export interface AppointmentDto {
    id: string
    starts_at: string
    ends_at: string
    status: string
    patient_first_name: string
    patient_last_name: string
    patient_birthdate: string | null
    parent_first_name: string
    parent_last_name: string
    parent_email: string | null
    parent_phone: string | null
    notes_internal: string | null
    attendance: 'arrived' | 'no_show' | null
    room: string | null
    practitioner: PractitionerRef
    service: ServiceRef
}

export interface CalendarEvent {
    id: string
    title: string
    start: string
    end: string
    backgroundColor: string
    borderColor: string
    textColor: string
    classNames: string[]
    extendedProps: AppointmentDto
}

/** Pure mapping from an appointment DTO to a FullCalendar event input. */
export function toCalendarEvent(a: AppointmentDto): CalendarEvent {
    const lastInitial = a.patient_last_name ? `${a.patient_last_name[0]}.` : ''
    return {
        id: a.id,
        title: `${a.patient_first_name} ${lastInitial} — ${a.service.name}`.replace(/\s+—/, ' —'),
        start: a.starts_at,
        end: a.ends_at,
        backgroundColor: roomColor(a.room),
        borderColor: a.practitioner.color,
        textColor: '#1E293B',
        classNames: a.attendance ? [`att-${a.attendance}`] : [],
        extendedProps: a,
    }
}

export const ATTENDANCE_LABELS: Record<'arrived' | 'no_show', string> = {
    arrived: 'Erschienen',
    no_show: 'Nicht erschienen',
}
