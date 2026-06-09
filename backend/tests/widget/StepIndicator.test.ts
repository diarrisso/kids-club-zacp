import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import StepIndicator from '@widget/components/StepIndicator.vue'

describe('StepIndicator', () => {
  it('renders the three step labels', () => {
    const w = mount(StepIndicator, { props: { currentStep: 'termin' } })
    expect(w.text()).toContain('Termin')
    expect(w.text()).toContain('Angaben')
    expect(w.text()).toContain('Bestätigen')
  })
  it('marks the current step active and earlier steps done', () => {
    const w = mount(StepIndicator, { props: { currentStep: 'form' } })
    expect(w.get('[data-step="termin"]').attributes('data-state')).toBe('done')
    expect(w.get('[data-step="form"]').attributes('data-state')).toBe('active')
    expect(w.get('[data-step="confirm"]').attributes('data-state')).toBe('future')
  })
})
