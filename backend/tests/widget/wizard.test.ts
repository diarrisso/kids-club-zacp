import { describe, it, expect } from 'vitest'
import { useWizard } from '@widget/useWizard'

describe('useWizard', () => {
    it('starts on the service step and advances with selections', () => {
        const w = useWizard()
        expect(w.step.value).toBe('service')

        w.chooseService({ id: 1, name: 'Prophylaxe', duration_minutes: 30 })
        expect(w.step.value).toBe('practitioner')
        expect(w.selection.service?.id).toBe(1)

        w.choosePractitioner({ id: 2, first_name: 'Anna', last_name: 'M' })
        expect(w.step.value).toBe('slot')

        w.chooseSlot({ starts_at: '2026-09-07T09:00:00+02:00', ends_at: '2026-09-07T09:30:00+02:00' })
        expect(w.step.value).toBe('form')
    })

    it('goes back one step (linear) without losing earlier selections', () => {
        const w = useWizard()
        w.chooseService({ id: 1, name: 'Prophylaxe', duration_minutes: 30 })
        w.choosePractitioner({ id: 2, first_name: 'Anna', last_name: 'M' }) // step = slot
        w.back() // slot -> practitioner
        expect(w.step.value).toBe('practitioner')
        expect(w.selection.service?.id).toBe(1) // retained
        expect(w.selection.practitioner?.id).toBe(2) // retained
    })

    it('moves to success after booking', () => {
        const w = useWizard()
        w.complete()
        expect(w.step.value).toBe('success')
    })
})
