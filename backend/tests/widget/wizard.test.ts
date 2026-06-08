import { describe, it, expect } from 'vitest'
import { useWizard } from '@widget/useWizard'

const slot = {
    starts_at: '2026-09-07T09:00:00+02:00',
    ends_at: '2026-09-07T09:30:00+02:00',
    practitioner: { id: 2, first_name: 'Anna', last_name: 'Müller', color: '#98ACBA' },
}

describe('useWizard', () => {
    it('advances service → termin → form', () => {
        const w = useWizard()
        expect(w.step.value).toBe('service')

        w.chooseService({ id: 1, name: 'Prophylaxe', duration_minutes: 30 })
        expect(w.step.value).toBe('termin')
        expect(w.selection.service?.id).toBe(1)

        w.chooseSlot(slot)
        expect(w.step.value).toBe('form')
        expect(w.selection.slot?.practitioner.id).toBe(2)
    })

    it('goes back one step linearly, retaining the service', () => {
        const w = useWizard()
        w.chooseService({ id: 1, name: 'Prophylaxe', duration_minutes: 30 })
        w.chooseSlot(slot) // termin -> form
        w.back() // form -> termin
        expect(w.step.value).toBe('termin')
        expect(w.selection.service?.id).toBe(1)
    })

    it('moves to success after booking', () => {
        const w = useWizard()
        w.complete()
        expect(w.step.value).toBe('success')
    })
})
