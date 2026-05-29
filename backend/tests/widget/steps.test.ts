import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ServiceStep from '@widget/steps/ServiceStep.vue'
import PractitionerStep from '@widget/steps/PractitionerStep.vue'

describe('ServiceStep', () => {
    it('renders services and emits select on click', async () => {
        const wrapper = mount(ServiceStep, {
            props: { services: [{ id: 1, name: 'Prophylaxe', duration_minutes: 30 }] },
        })
        expect(wrapper.text()).toContain('Prophylaxe')
        await wrapper.get('button').trigger('click')
        expect(wrapper.emitted('select')?.[0][0]).toMatchObject({ id: 1 })
    })
})

describe('PractitionerStep', () => {
    it('renders practitioners and emits select', async () => {
        const wrapper = mount(PractitionerStep, {
            props: { practitioners: [{ id: 2, first_name: 'Anna', last_name: 'Müller', title: 'Dr.' }] },
        })
        expect(wrapper.text()).toContain('Anna')
        await wrapper.get('button').trigger('click')
        expect(wrapper.emitted('select')?.[0][0]).toMatchObject({ id: 2 })
    })
})
