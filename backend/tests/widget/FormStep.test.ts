import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FormStep from '@widget/steps/FormStep.vue'

describe('FormStep', () => {
    it('disables submit until required fields + consent are set', async () => {
        const wrapper = mount(FormStep, { props: { serverErrors: {} } })
        const submit = wrapper.get('button[type="submit"]')
        expect((submit.element as HTMLButtonElement).disabled).toBe(true)

        await wrapper.get('[name="patient_first_name"]').setValue('Lina')
        await wrapper.get('[name="patient_last_name"]').setValue('Müller')
        await wrapper.get('[name="patient_birthdate"]').setValue('2019-04-12')
        await wrapper.get('[name="parent_first_name"]').setValue('Anna')
        await wrapper.get('[name="parent_last_name"]').setValue('Müller')
        await wrapper.get('[name="parent_email"]').setValue('anna@example.de')
        await wrapper.get('[name="consent"]').setValue(true)

        expect((submit.element as HTMLButtonElement).disabled).toBe(false)
    })

    it('emits submit payload including an (empty) honeypot field', async () => {
        const wrapper = mount(FormStep, { props: { serverErrors: {} } })
        await wrapper.get('[name="patient_first_name"]').setValue('Lina')
        await wrapper.get('[name="patient_last_name"]').setValue('Müller')
        await wrapper.get('[name="patient_birthdate"]').setValue('2019-04-12')
        await wrapper.get('[name="parent_first_name"]').setValue('Anna')
        await wrapper.get('[name="parent_last_name"]').setValue('Müller')
        await wrapper.get('[name="parent_email"]').setValue('anna@example.de')
        await wrapper.get('[name="consent"]').setValue(true)
        await wrapper.get('form').trigger('submit.prevent')

        const payload = wrapper.emitted('submit')?.[0][0] as any
        expect(payload).toMatchObject({ patient_first_name: 'Lina', consent: true, website: '' })
    })

    it('shows a server field error', () => {
        const wrapper = mount(FormStep, { props: { serverErrors: { parent_email: ['ungültig'] } } })
        expect(wrapper.text()).toContain('ungültig')
    })
})
