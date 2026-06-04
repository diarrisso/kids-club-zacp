import { describe, it, expect } from 'vitest'
import { toCalendarEvent, type AppointmentDto } from '@/lib/calendar'

const dto: AppointmentDto = {
    id: 'uuid-1',
    starts_at: '2026-06-01T09:00:00+02:00',
    ends_at: '2026-06-01T09:30:00+02:00',
    status: 'confirmed',
    patient_first_name: 'Lina', patient_last_name: 'Müller', patient_birthdate: '2019-04-12',
    parent_first_name: 'Anna', parent_last_name: 'Müller', parent_email: 'anna@example.de', parent_phone: '+49 170 0',
    notes_internal: null,
    room: null,
    practitioner: { id: 2, name: 'Dr. Anna Berg', color: '#3b82f6' },
    service: { id: 5, name: 'Prophylaxe', duration_minutes: 30 },
}

describe('toCalendarEvent', () => {
    it('maps a DTO to a FullCalendar event with title, color and props', () => {
        const e = toCalendarEvent(dto)
        expect(e.id).toBe('uuid-1')
        expect(e.title).toBe('Lina M. — Prophylaxe')
        expect(e.start).toBe('2026-06-01T09:00:00+02:00')
        expect(e.end).toBe('2026-06-01T09:30:00+02:00')
        // No room → neutral slate-200
        expect(e.backgroundColor).toBe('#E2E8F0')
        expect(e.borderColor).toBe('#3b82f6')
        expect(e.extendedProps).toEqual(dto)
    })
})

const base = (room: string | null): AppointmentDto => ({
    id: 'a1', starts_at: '2026-06-04T09:00:00+02:00', ends_at: '2026-06-04T09:30:00+02:00',
    status: 'confirmed', patient_first_name: 'Max', patient_last_name: 'Muster',
    patient_birthdate: null, parent_first_name: 'Eva', parent_last_name: 'Muster',
    parent_email: null, parent_phone: null, notes_internal: null, room,
    practitioner: { id: 1, name: 'Dr. X', color: '#123456' },
    service: { id: 1, name: 'Kontrolle', duration_minutes: 30 },
})

describe('toCalendarEvent room coloring', () => {
    it('fills with the room color and borders with the practitioner color', () => {
        const e = toCalendarEvent(base('blue'))
        expect(e.backgroundColor).toBe('#98ACBA')
        expect(e.borderColor).toBe('#123456')
        expect(e.textColor).toBe('#1E293B')
    })

    it('falls back to neutral slate when no room is chosen', () => {
        expect(toCalendarEvent(base(null)).backgroundColor).toBe('#E2E8F0')
    })
})
