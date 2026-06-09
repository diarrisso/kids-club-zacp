import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import TerminStep from '@widget/steps/TerminStep.vue'

const services = [{ id: 1, name: 'Prophylaxe', duration_minutes: 30 }]
const slots = [
    { starts_at: '2026-09-07T09:00:00+02:00', ends_at: '2026-09-07T09:30:00+02:00', practitioner: { id: 1, first_name: 'Anna', last_name: 'Berg', color: '#98ACBA' } },
    { starts_at: '2026-09-07T09:30:00+02:00', ends_at: '2026-09-07T10:00:00+02:00', practitioner: { id: 2, first_name: 'Tom', last_name: 'Adler', color: '#F7E29D' } },
]
const base = { services, selectedService: services[0], availableDates: ['2026-09-07'], loadingSlots: false, selectedDate: '2026-09-07' }

describe('TerminStep', () => {
    it('renders a service pill per service and emits service-select', async () => {
        const wrapper = mount(TerminStep, { props: { ...base, selectedService: undefined, slots: [] } })
        expect(wrapper.findAll('[data-service]')).toHaveLength(1)
        await wrapper.get('[data-service]').trigger('click')
        expect(wrapper.emitted('service-select')?.[0]?.[0]).toMatchObject({ id: 1 })
    })

    it('hides the calendar until a service is chosen', () => {
        const wrapper = mount(TerminStep, { props: { ...base, selectedService: undefined, slots: [] } })
        expect(wrapper.find('[data-day]').exists()).toBe(false)
    })

    it('shows one filter chip per practitioner and filters slots client-side', async () => {
        const wrapper = mount(TerminStep, { props: { ...base, slots } })
        expect(wrapper.findAll('[data-slot]')).toHaveLength(2)
        expect(wrapper.findAll('[data-filter]')).toHaveLength(3)
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
        const wrapper = mount(TerminStep, { props: { ...base, availableDates: [], slots: [], selectedDate: undefined } })
        expect(wrapper.text()).toContain('Kein freier Termin verfügbar')
    })

    it('resets to all slots when "Alle Behandler" is clicked', async () => {
        const wrapper = mount(TerminStep, { props: { ...base, slots } })
        await wrapper.get('[data-filter][data-filter-id="2"]').trigger('click')
        expect(wrapper.findAll('[data-slot]')).toHaveLength(1)
        await wrapper.get('[data-filter][data-filter-id=""]').trigger('click')
        expect(wrapper.findAll('[data-slot]')).toHaveLength(2)
    })

    it('shows the loading message while slots load', () => {
        const wrapper = mount(TerminStep, { props: { ...base, slots: [], loadingSlots: true } })
        expect(wrapper.text()).toContain('Lädt')
    })

    it('resets the doctor filter when the slots prop changes (new day)', async () => {
        const wrapper = mount(TerminStep, { props: { ...base, slots } })
        await wrapper.get('[data-filter][data-filter-id="2"]').trigger('click')
        expect(wrapper.findAll('[data-slot]')).toHaveLength(1)
        await wrapper.setProps({ slots: [...slots] })
        expect(wrapper.findAll('[data-slot]')).toHaveLength(2)
    })

    it('emits select with the filtered slot when a doctor is selected', async () => {
        const wrapper = mount(TerminStep, { props: { ...base, slots } })
        await wrapper.get('[data-filter][data-filter-id="2"]').trigger('click')
        await wrapper.get('[data-slot]').trigger('click')
        expect(wrapper.emitted('select')?.[0]?.[0]).toMatchObject({ practitioner: { id: 2 } })
    })
})
