import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ServiceStep from '@widget/steps/ServiceStep.vue'

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
