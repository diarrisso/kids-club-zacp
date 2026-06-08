import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import TerminStep from '@widget/steps/TerminStep.vue'

const slots = [
    { starts_at: '2026-09-07T09:00:00+02:00', ends_at: '2026-09-07T09:30:00+02:00', practitioner: { id: 1, first_name: 'Anna', last_name: 'Berg', color: '#98ACBA' } },
    { starts_at: '2026-09-07T09:30:00+02:00', ends_at: '2026-09-07T10:00:00+02:00', practitioner: { id: 2, first_name: 'Tom', last_name: 'Adler', color: '#F7E29D' } },
]

const base = { availableDates: ['2026-09-07'], loadingSlots: false, selectedDate: '2026-09-07' }

describe('TerminStep', () => {
    it('shows one filter chip per practitioner and filters slots client-side', async () => {
        const wrapper = mount(TerminStep, { props: { ...base, slots } })
        expect(wrapper.findAll('[data-slot]')).toHaveLength(2)
        expect(wrapper.findAll('[data-filter]')).toHaveLength(3) // Alle + 2

        await wrapper.get('[data-filter][data-filter-id="2"]').trigger('click')
        const visible = wrapper.findAll('[data-slot]')
        expect(visible).toHaveLength(1)
        expect(visible[0].text()).toContain('Tom')
    })

    it('hides the filter row when only one practitioner has slots', () => {
        const wrapper = mount(TerminStep, { props: { ...base, slots: [slots[0]] } })
        expect(wrapper.find('[data-filters]').exists()).toBe(false)
    })

    it('emits select with the chosen slot', async () => {
        const wrapper = mount(TerminStep, { props: { ...base, slots } })
        await wrapper.get('[data-slot]').trigger('click')
        expect(wrapper.emitted('select')?.[0]?.[0]).toMatchObject({ practitioner: { id: 1 } })
    })

    it('shows the empty message when no dates are available', () => {
        const wrapper = mount(TerminStep, { props: { availableDates: [], slots: [], loadingSlots: false } })
        expect(wrapper.text()).toContain('Kein freier Termin verfügbar')
    })
})
