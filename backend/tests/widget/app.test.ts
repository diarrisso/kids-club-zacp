import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import App from '@widget/App.vue'

const fakeApi = {
    services: vi.fn().mockResolvedValue([{ id: 1, name: 'Prophylaxe', duration_minutes: 30 }]),
    practitioners: vi.fn().mockResolvedValue([{ id: 2, first_name: 'Anna', last_name: 'Müller' }]),
    slots: vi.fn().mockResolvedValue([{ starts_at: '2026-09-07T09:00:00+02:00', ends_at: '2026-09-07T09:30:00+02:00' }]),
    book: vi.fn().mockResolvedValue({ cancellation_token: 'tok-123', starts_at: '2026-09-07T09:00:00+02:00', ends_at: '2026-09-07T09:30:00+02:00' }),
    cancel: vi.fn().mockResolvedValue({ status: 'cancelled' }),
}

describe('App', () => {
    it('walks the full flow to success', async () => {
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await flushPromises() // services loaded

        await wrapper.get('button').trigger('click') // choose service
        await flushPromises()
        await wrapper.get('button').trigger('click') // choose practitioner
        await flushPromises()
        await wrapper.get('button[data-slot]').trigger('click') // choose slot

        await wrapper.get('[name="patient_first_name"]').setValue('Lina')
        await wrapper.get('[name="patient_last_name"]').setValue('Müller')
        await wrapper.get('[name="patient_birthdate"]').setValue('2019-04-12')
        await wrapper.get('[name="parent_first_name"]').setValue('Anna')
        await wrapper.get('[name="parent_last_name"]').setValue('Müller')
        await wrapper.get('[name="parent_email"]').setValue('anna@example.de')
        await wrapper.get('[name="consent"]').setValue(true)
        await wrapper.get('form').trigger('submit.prevent')
        await flushPromises()

        expect(fakeApi.book).toHaveBeenCalled()
        expect(wrapper.text()).toContain('tok-123')
    })

    it('cancels the appointment from the success screen', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true)
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await flushPromises()
        await wrapper.get('button').trigger('click') // service
        await flushPromises()
        await wrapper.get('button').trigger('click') // practitioner
        await flushPromises()
        await wrapper.get('button[data-slot]').trigger('click') // slot
        await wrapper.get('[name="patient_first_name"]').setValue('Lina')
        await wrapper.get('[name="patient_last_name"]').setValue('Müller')
        await wrapper.get('[name="patient_birthdate"]').setValue('2019-04-12')
        await wrapper.get('[name="parent_first_name"]').setValue('Anna')
        await wrapper.get('[name="parent_last_name"]').setValue('Müller')
        await wrapper.get('[name="parent_email"]').setValue('anna@example.de')
        await wrapper.get('[name="consent"]').setValue(true)
        await wrapper.get('form').trigger('submit.prevent')
        await flushPromises()

        // success screen → click "Termin stornieren"
        await wrapper.get('button').trigger('click')
        await flushPromises()
        expect(fakeApi.cancel).toHaveBeenCalledWith('tok-123')
        expect(wrapper.text()).toContain('Termin storniert')
    })
})
