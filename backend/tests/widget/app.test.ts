import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import App from '@widget/App.vue'

afterEach(() => { vi.restoreAllMocks() })

const today = (() => {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
})()

let fakeApi: any

beforeEach(() => {
    fakeApi = {
        services: vi.fn().mockResolvedValue([{ id: 1, name: 'Prophylaxe', duration_minutes: 30 }]),
        availabilityDays: vi.fn().mockResolvedValue([today]),
        slots: vi.fn().mockResolvedValue([
            { starts_at: `${today}T09:00:00+02:00`, ends_at: `${today}T09:30:00+02:00`, practitioner: { id: 2, first_name: 'Anna', last_name: 'Müller', color: '#98ACBA' } },
        ]),
        book: vi.fn().mockResolvedValue({ cancellation_token: 'tok-123', starts_at: `${today}T09:00:00+02:00`, ends_at: `${today}T09:30:00+02:00` }),
        cancel: vi.fn().mockResolvedValue({ status: 'cancelled' }),
    }
})

async function fillAndSubmit(wrapper: ReturnType<typeof mount>) {
    await wrapper.get('[name="patient_first_name"]').setValue('Lina')
    await wrapper.get('[name="patient_last_name"]').setValue('Müller')
    await wrapper.get('[name="patient_birthdate"]').setValue('2019-04-12')
    await wrapper.get('[name="parent_first_name"]').setValue('Anna')
    await wrapper.get('[name="parent_last_name"]').setValue('Müller')
    await wrapper.get('[name="parent_email"]').setValue('anna@example.de')
    await wrapper.get('[name="consent"]').setValue(true)
    await wrapper.get('form').trigger('submit.prevent')
    await flushPromises()
}

describe('App', () => {
    it('walks the full date-first flow to success', async () => {
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await flushPromises() // services loaded

        await wrapper.get('button').trigger('click') // choose the only service
        await flushPromises() // calendar mounts → availabilityDays loaded
        expect(fakeApi.availabilityDays).toHaveBeenCalled()

        await wrapper.get(`[data-day="${today}"]`).trigger('click') // pick today
        await flushPromises() // slots loaded
        expect(fakeApi.slots).toHaveBeenCalled()

        await wrapper.get('[data-slot]').trigger('click') // choose the slot
        await fillAndSubmit(wrapper)

        expect(fakeApi.book).toHaveBeenCalled()
        expect(fakeApi.book.mock.calls[0][0].practitioner_id).toBe(2)
        expect(wrapper.text()).toContain('tok-123')
    })

    it('cancels the appointment from the success screen', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true)
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await flushPromises()
        await wrapper.get('button').trigger('click')
        await flushPromises()
        await wrapper.get(`[data-day="${today}"]`).trigger('click')
        await flushPromises()
        await wrapper.get('[data-slot]').trigger('click')
        await fillAndSubmit(wrapper)

        await wrapper.get('button').trigger('click') // success screen → "Termin stornieren"
        await flushPromises()
        expect(fakeApi.cancel).toHaveBeenCalledWith('tok-123')
        expect(wrapper.text()).toContain('Termin storniert')
    })

    it('on slot_taken, returns to the calendar and refreshes the day’s slots', async () => {
        fakeApi.book.mockRejectedValueOnce({ kind: 'slot_taken' })
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await flushPromises()
        await wrapper.get('button').trigger('click')        // service
        await flushPromises()
        await wrapper.get(`[data-day="${today}"]`).trigger('click') // pick day → slots call #1
        await flushPromises()
        await wrapper.get('[data-slot]').trigger('click')   // choose slot
        await fillAndSubmit(wrapper)                          // book rejects slot_taken
        expect(wrapper.text()).toContain('Termin nicht mehr verfügbar')
        expect(wrapper.find(`[data-day="${today}"]`).exists()).toBe(true) // back on the calendar
        expect(fakeApi.slots.mock.calls.length).toBeGreaterThanOrEqual(2) // refetched after slot_taken
    })

    it('surfaces server validation errors without leaving the form', async () => {
        fakeApi.book.mockRejectedValueOnce({ kind: 'validation', errors: { patient_first_name: ['Pflichtfeld'] } })
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await flushPromises()
        await wrapper.get('button').trigger('click')
        await flushPromises()
        await wrapper.get(`[data-day="${today}"]`).trigger('click')
        await flushPromises()
        await wrapper.get('[data-slot]').trigger('click')
        await fillAndSubmit(wrapper)
        expect(fakeApi.book).toHaveBeenCalled()
        expect(wrapper.find('[name="patient_first_name"]').exists()).toBe(true) // still on the form, not success
    })
})
