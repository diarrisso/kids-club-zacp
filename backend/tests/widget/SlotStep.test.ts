import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SlotStep from '@widget/steps/SlotStep.vue'

const slots = [
    { starts_at: '2026-09-07T09:00:00+02:00', ends_at: '2026-09-07T09:30:00+02:00' },
    { starts_at: '2026-09-07T09:30:00+02:00', ends_at: '2026-09-07T10:00:00+02:00' },
    { starts_at: '2026-09-08T11:00:00+02:00', ends_at: '2026-09-08T11:30:00+02:00' },
]

describe('SlotStep', () => {
    it('groups slots by date and emits the chosen slot', async () => {
        const wrapper = mount(SlotStep, { props: { slots } })
        expect(wrapper.findAll('[data-date-group]')).toHaveLength(2)
        const first = wrapper.get('button[data-slot]')
        await first.trigger('click')
        expect(wrapper.emitted('select')?.[0][0]).toMatchObject({ starts_at: slots[0].starts_at })
    })

    it('shows an empty message when there are no slots', () => {
        const wrapper = mount(SlotStep, { props: { slots: [] } })
        expect(wrapper.text()).toContain('Keine freien Termine')
    })
})
