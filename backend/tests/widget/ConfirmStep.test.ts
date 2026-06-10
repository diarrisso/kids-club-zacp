import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ConfirmStep from '@widget/steps/ConfirmStep.vue'
import { WIDGET_CONFIG_KEY } from '@widget/useTheme'

const selection = {
  service: { id: 1, name: 'Erstuntersuchung', duration_minutes: 45 },
  slot: { starts_at: '2026-06-10T10:30:00+02:00', ends_at: '2026-06-10T11:15:00+02:00',
          practitioner: { id: 1, first_name: 'Anna', last_name: 'Müller', title: 'Dr.' } },
}
const formData = { patient_first_name: 'Max', patient_last_name: 'Müller', parent_email: 'hans@example.com' }
const baseProps = { selection, formData, loading: false }

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

  it('links the Datenschutzerklärung when a url is provided', () => {
    const wrapper = mount(ConfirmStep, {
      props: baseProps,
      global: { provide: { [WIDGET_CONFIG_KEY as symbol]: { config: { theme: {}, logoUrl: null, datenschutzUrl: 'https://praxis.test/datenschutz', impressumUrl: null } } } },
    })
    const link = wrapper.get('[data-datenschutz-link]')
    expect(link.attributes('href')).toBe('https://praxis.test/datenschutz')
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toContain('noopener')
  })

  it('falls back to the plain sentence without a url', () => {
    const wrapper = mount(ConfirmStep, { props: baseProps })
    expect(wrapper.find('[data-datenschutz-link]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Ich willige in die Verarbeitung')
  })

  it('shows the Impressum link when provided', () => {
    const wrapper = mount(ConfirmStep, {
      props: baseProps,
      global: { provide: { [WIDGET_CONFIG_KEY as symbol]: { config: { theme: {}, logoUrl: null, datenschutzUrl: null, impressumUrl: 'https://praxis.test/impressum' } } } },
    })
    expect(wrapper.get('[data-impressum-link]').attributes('href')).toBe('https://praxis.test/impressum')
  })
})
