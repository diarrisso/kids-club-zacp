import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ConfirmStep from '@widget/steps/ConfirmStep.vue'

const selection = {
  service: { id: 1, name: 'Erstuntersuchung', duration_minutes: 45 },
  slot: { starts_at: '2026-06-10T10:30:00+02:00', ends_at: '2026-06-10T11:15:00+02:00',
          practitioner: { id: 1, first_name: 'Anna', last_name: 'Müller', title: 'Dr.' } },
}
const formData = { patient_first_name: 'Max', patient_last_name: 'Müller', parent_email: 'hans@example.com' }

describe('ConfirmStep', () => {
  it('shows the recap and the clinic-local time (no tz shift)', () => {
    const w = mount(ConfirmStep, { props: { selection, formData, loading: false } })
    expect(w.text()).toContain('Erstuntersuchung')
    expect(w.text()).toContain('45 Min.')
    expect(w.text()).toContain('10:30')          // sliced clinic time, not browser-converted
    expect(w.text()).toContain('Max Müller')
    expect(w.text()).toContain('hans@example.com')
  })
  it('disables submit until consent is checked, then emits submit', async () => {
    const w = mount(ConfirmStep, { props: { selection, formData, loading: false } })
    const btn = () => w.get('[data-submit]').element as HTMLButtonElement
    expect(btn().disabled).toBe(true)
    await w.get('[data-consent]').setValue(true)
    expect(btn().disabled).toBe(false)
    await w.get('[data-submit]').trigger('click')
    expect(w.emitted('submit')).toBeTruthy()
  })
  it('emits back', async () => {
    const w = mount(ConfirmStep, { props: { selection, formData, loading: false } })
    await w.get('[data-back]').trigger('click')
    expect(w.emitted('back')).toBeTruthy()
  })
})
