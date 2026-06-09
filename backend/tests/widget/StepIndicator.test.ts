import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import StepIndicator from '@widget/components/StepIndicator.vue'

describe('StepIndicator', () => {
  it('renders the four step labels', () => {
    const w = mount(StepIndicator, { props: { currentStep: 'termin' } })
    expect(w.text()).toContain('Termin')
    expect(w.text()).toContain('Kind')
    expect(w.text()).toContain('Elternteil')
    expect(w.text()).toContain('Bestätigen')
    expect(w.text()).not.toContain('Fertig')
  })
  it('exposes data-step="kind" node', () => {
    const w = mount(StepIndicator, { props: { currentStep: 'kind' } })
    expect(w.get('[data-step="kind"]').attributes('data-state')).toBe('active')
    expect(w.get('[data-step="termin"]').attributes('data-state')).toBe('done')
    expect(w.get('[data-step="form"]').attributes('data-state')).toBe('future')
  })
  it('marks the current step active and earlier steps done', () => {
    const w = mount(StepIndicator, { props: { currentStep: 'form' } })
    expect(w.get('[data-step="termin"]').attributes('data-state')).toBe('done')
    expect(w.get('[data-step="kind"]').attributes('data-state')).toBe('done')
    expect(w.get('[data-step="form"]').attributes('data-state')).toBe('active')
    expect(w.get('[data-step="confirm"]').attributes('data-state')).toBe('future')
  })
  it('marks confirm as last active step on confirm', () => {
    const w = mount(StepIndicator, { props: { currentStep: 'confirm' } })
    expect(w.get('[data-step="confirm"]').attributes('data-state')).toBe('active')
    expect(w.get('[data-step="form"]').attributes('data-state')).toBe('done')
  })
})
