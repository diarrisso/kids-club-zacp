import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import App from '@widget/App.vue'

afterEach(() => { vi.restoreAllMocks(); vi.useRealTimers() })

const today = (() => {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
})()

let fakeApi: any

beforeEach(() => {
    fakeApi = {
        config: vi.fn().mockResolvedValue({ theme: {}, logoUrl: null, datenschutzUrl: null, impressumUrl: null }),
        services: vi.fn().mockResolvedValue([{ id: 1, name: 'Prophylaxe', duration_minutes: 30 }]),
        availabilityDays: vi.fn().mockResolvedValue([today]),
        slots: vi.fn().mockResolvedValue([
            { starts_at: `${today}T09:00:00+02:00`, ends_at: `${today}T09:30:00+02:00`, practitioner: { id: 2, first_name: 'Anna', last_name: 'Müller', color: '#98ACBA' } },
        ]),
        book: vi.fn().mockResolvedValue({ reference: 'KC-0BBAD2', cancellation_token: 'tok-123', starts_at: `${today}T09:00:00+02:00`, ends_at: `${today}T09:30:00+02:00` }),
        cancel: vi.fn().mockResolvedValue({ status: 'cancelled' }),
    }
})

// Drives termin → kind. Returns once the kind step is showing.
async function reachKind(wrapper: ReturnType<typeof mount>) {
    await flushPromises() // services loaded
    await wrapper.get('[data-service-trigger]').trigger('click') // open combobox
    await wrapper.get('[data-service]').trigger('click') // choose the only service (stays on termin)
    await flushPromises() // calendar mounts → availabilityDays
    await wrapper.get(`[data-day="${today}"]`).trigger('click') // pick today
    await flushPromises() // slots
    await wrapper.get('[data-slot]').trigger('click') // select slot — stays on termin
    await wrapper.get('[data-termin-weiter]').trigger('click') // explicit Weiter → kind
}

// Drives termin → kind → form. Returns once the elternteil form step is showing.
async function reachForm(wrapper: ReturnType<typeof mount>) {
    await reachKind(wrapper)
    await fillKindAndAdvance(wrapper)
}

async function fillKindAndAdvance(wrapper: ReturnType<typeof mount>) {
    await wrapper.get('[name="patient_first_name"]').setValue('Lina')
    await wrapper.get('[name="patient_last_name"]').setValue('Müller')
    await wrapper.get('[name="patient_birthdate"]').setValue('2019-04-12')
    await wrapper.get('[data-kind-advance]').trigger('click') // Weiter → form (elternteil)
}

async function fillFormAndAdvance(wrapper: ReturnType<typeof mount>) {
    await wrapper.get('[name="parent_first_name"]').setValue('Anna')
    await wrapper.get('[name="parent_last_name"]').setValue('Müller')
    await wrapper.get('[name="parent_email"]').setValue('anna@example.de')
    await wrapper.get('[data-advance]').trigger('click') // Weiter → confirm
}

async function confirmAndSubmit(wrapper: ReturnType<typeof mount>) {
    await wrapper.get('[data-consent]').setValue(true)
    await wrapper.get('[data-submit]').trigger('click')
    await flushPromises()
}

describe('App', () => {
    it('walks the 5-step flow to success', async () => {
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await reachForm(wrapper)
        expect(fakeApi.availabilityDays).toHaveBeenCalled()
        expect(fakeApi.slots).toHaveBeenCalled()
        await fillFormAndAdvance(wrapper)
        await confirmAndSubmit(wrapper)
        expect(fakeApi.book).toHaveBeenCalled()
        expect(fakeApi.book.mock.calls[0][0].practitioner_id).toBe(2)
        expect(fakeApi.book.mock.calls[0][0].consent).toBe(true)
        expect(wrapper.text()).toContain('KC-0BBAD2')
    })

    it('shows the booking reference, never the cancellation token', async () => {
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await reachKind(wrapper); await fillKindAndAdvance(wrapper); await fillFormAndAdvance(wrapper); await confirmAndSubmit(wrapper)
        expect(wrapper.text()).toContain('KC-0BBAD2')
        expect(wrapper.text()).not.toContain('tok-123')
    })

    it('cancels via the in-widget confirmation (no window.confirm)', async () => {
        const confirmSpy = vi.spyOn(window, 'confirm')
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await reachKind(wrapper); await fillKindAndAdvance(wrapper); await fillFormAndAdvance(wrapper); await confirmAndSubmit(wrapper)
        await wrapper.get('[data-cancel-open]').trigger('click')
        await wrapper.get('[data-cancel-confirm]').trigger('click')
        await flushPromises()
        expect(fakeApi.cancel).toHaveBeenCalledWith('tok-123')
        expect(confirmSpy).not.toHaveBeenCalled()
        expect(wrapper.text()).toContain('Termin storniert')
    })

    it('Neuer Termin resets the wizard back to a clean termin step', async () => {
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await reachKind(wrapper); await fillKindAndAdvance(wrapper); await fillFormAndAdvance(wrapper); await confirmAndSubmit(wrapper)
        await wrapper.get('[data-restart]').trigger('click')
        expect(wrapper.find('[data-service-trigger]').exists()).toBe(true)
        expect(wrapper.find('[data-restart]').exists()).toBe(false)
    })

    it('on slot_taken, returns to the calendar and refreshes the day’s slots', async () => {
        fakeApi.book.mockRejectedValueOnce({ kind: 'slot_taken' })
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await reachForm(wrapper)
        await fillFormAndAdvance(wrapper)
        await confirmAndSubmit(wrapper) // book rejects slot_taken
        expect(wrapper.text()).toContain('Termin nicht mehr verfügbar')
        expect(wrapper.find(`[data-day="${today}"]`).exists()).toBe(true) // back on the calendar
        expect(fakeApi.slots.mock.calls.length).toBeGreaterThanOrEqual(2)
    })

    it('surfaces server validation errors by returning to the form', async () => {
        fakeApi.book.mockRejectedValueOnce({ kind: 'validation', errors: { parent_email: ['Pflichtfeld'] } })
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await reachForm(wrapper)
        await fillFormAndAdvance(wrapper)
        await confirmAndSubmit(wrapper)
        expect(fakeApi.book).toHaveBeenCalled()
        expect(wrapper.find('[name="parent_first_name"]').exists()).toBe(true) // back on the elternteil form
        expect(wrapper.text()).toContain('Pflichtfeld')
    })

    it('clears the selected slot recap when the date changes', async () => {
        // Pin the clock mid-month so day1+day2 always sit in the SAME calendar
        // month (BookingCalendar only renders the current month — a real
        // "today+1" flakes on the last day of each month). Only Date is faked:
        // flushPromises relies on real setTimeout/setImmediate.
        vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-06-15T10:00:00') })
        const day1 = '2026-06-15'
        const day2 = '2026-06-16'
        fakeApi.availabilityDays.mockResolvedValue([day1, day2])
        fakeApi.slots.mockResolvedValue([
            { starts_at: `${day1}T09:00:00+02:00`, ends_at: `${day1}T09:30:00+02:00`, practitioner: { id: 2, first_name: 'Anna', last_name: 'Müller', color: '#98ACBA' } },
        ])
        const wrapper = mount(App, { props: { api: fakeApi as any } })
        await flushPromises()
        await wrapper.get('[data-service-trigger]').trigger('click')
        await wrapper.get('[data-service]').trigger('click')
        await flushPromises()
        await wrapper.get(`[data-day="${day1}"]`).trigger('click')
        await flushPromises()
        await wrapper.get('[data-slot]').trigger('click') // select slot — stays on termin
        expect(wrapper.find('[data-termin-weiter]').exists()).toBe(true)
        // Now pick a different day — stale slot recap must disappear
        await wrapper.get(`[data-day="${day2}"]`).trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-termin-weiter]').exists()).toBe(false)
    })
})
