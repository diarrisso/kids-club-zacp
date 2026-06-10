import { describe, it, expect } from 'vitest'
import { useWizard } from '@widget/useWizard'

const slot = {
    starts_at: '2026-09-07T09:00:00+02:00',
    ends_at: '2026-09-07T09:30:00+02:00',
    practitioner: { id: 2, first_name: 'Anna', last_name: 'Müller', color: '#98ACBA' },
}

describe('useWizard', () => {
    it('starts on termin and chooseService stays on termin', () => {
        const w = useWizard()
        expect(w.step.value).toBe('termin')
        w.chooseService({ id: 1, name: 'Prophylaxe', duration_minutes: 30 })
        expect(w.step.value).toBe('termin')
        expect(w.selection.service?.id).toBe(1)
    })

    it('chooseSlot records the slot but stays on termin; confirmSlot advances to kind', () => {
        const w = useWizard()
        w.chooseService({ id: 1, name: 'Prophylaxe', duration_minutes: 30 })
        w.chooseSlot(slot)
        expect(w.step.value).toBe('termin')
        expect(w.selection.slot).toEqual(slot)
        w.confirmSlot()
        expect(w.step.value).toBe('kind')
    })

    it('chooseSlot records the slot but stays on termin; advance goes to form, then confirm', () => {
        const w = useWizard()
        w.chooseService({ id: 1, name: 'Prophylaxe', duration_minutes: 30 })
        w.chooseSlot(slot)
        w.confirmSlot()
        expect(w.step.value).toBe('kind')
        w.advance()
        expect(w.step.value).toBe('form')
        w.advance()
        expect(w.step.value).toBe('confirm')
    })

    it('back is linear: confirm → form → kind → termin', () => {
        const w = useWizard()
        w.chooseService({ id: 1, name: 'Prophylaxe', duration_minutes: 30 })
        w.chooseSlot(slot)
        w.confirmSlot() // termin → kind
        w.advance() // kind → form
        w.advance() // form → confirm
        expect(w.step.value).toBe('confirm')
        w.back(); expect(w.step.value).toBe('form')
        w.back(); expect(w.step.value).toBe('kind')
        w.back(); expect(w.step.value).toBe('termin')
    })

    it('backToTermin jumps straight to termin from confirm', () => {
        const w = useWizard()
        w.chooseService({ id: 1, name: 'Prophylaxe', duration_minutes: 30 })
        w.chooseSlot(slot)
        w.confirmSlot() // termin → kind
        w.advance() // kind → form
        w.advance() // form → confirm
        w.backToTermin()
        expect(w.step.value).toBe('termin')
        expect(w.selection.service?.id).toBe(1)
    })

    it('complete moves to success', () => {
        const w = useWizard()
        w.complete()
        expect(w.step.value).toBe('success')
    })
})
