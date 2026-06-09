import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FormStep from '@widget/steps/FormStep.vue'

const selection = {
    service: { id: 1, name: 'Prophylaxe', duration_minutes: 30 },
    slot: { starts_at: '2026-09-07T09:00:00+02:00', ends_at: '2026-09-07T09:30:00+02:00',
            practitioner: { id: 2, first_name: 'Anna', last_name: 'Müller', color: '#98ACBA' } },
}

function fill(wrapper: any) {
    return Promise.all([
        wrapper.get('[name="parent_first_name"]').setValue('Anna'),
        wrapper.get('[name="parent_last_name"]').setValue('Müller'),
        wrapper.get('[name="parent_email"]').setValue('anna@example.de'),
    ])
}

describe('FormStep', () => {
    it('shows the recap (service + clinic-local time)', () => {
        const wrapper = mount(FormStep, { props: { selection, serverErrors: {} } })
        expect(wrapper.text()).toContain('Prophylaxe')
        expect(wrapper.text()).toContain('09:00')
    })

    it('disables Weiter until required fields are set (no consent here)', async () => {
        const wrapper = mount(FormStep, { props: { selection, serverErrors: {} } })
        const btn = () => wrapper.get('[data-advance]').element as HTMLButtonElement
        expect(btn().disabled).toBe(true)
        await fill(wrapper)
        expect(btn().disabled).toBe(false)
    })

    it('emits advance with the form payload (incl honeypot, no consent, no patient fields)', async () => {
        const wrapper = mount(FormStep, { props: { selection, serverErrors: {} } })
        await fill(wrapper)
        await wrapper.get('form').trigger('submit.prevent')
        const payload = wrapper.emitted('advance')?.[0][0] as any
        expect(payload).toMatchObject({ parent_first_name: 'Anna', website: '' })
        expect(payload.consent).toBeUndefined()
        expect(payload.patient_first_name).toBeUndefined()
    })

    it('pre-fills from initialValues (elternteil fields)', () => {
        const wrapper = mount(FormStep, {
            props: { selection, serverErrors: {}, initialValues: { parent_first_name: 'Tom', parent_email: 'p@e.de' } },
        })
        expect((wrapper.get('[name="parent_first_name"]').element as HTMLInputElement).value).toBe('Tom')
        expect((wrapper.get('[name="parent_email"]').element as HTMLInputElement).value).toBe('p@e.de')
    })

    it('does not contain patient_* fields', () => {
        const wrapper = mount(FormStep, { props: { selection, serverErrors: {} } })
        expect(wrapper.find('[name="patient_first_name"]').exists()).toBe(false)
        expect(wrapper.find('[name="patient_last_name"]').exists()).toBe(false)
        expect(wrapper.find('[name="patient_birthdate"]').exists()).toBe(false)
    })

    it('shows a server field error', () => {
        const wrapper = mount(FormStep, { props: { selection, serverErrors: { parent_email: ['ungültig'] } } })
        expect(wrapper.text()).toContain('ungültig')
    })

    it('emits back', async () => {
        const wrapper = mount(FormStep, { props: { selection, serverErrors: {} } })
        await wrapper.get('[data-form-back]').trigger('click')
        expect(wrapper.emitted('back')).toBeTruthy()
    })
})
