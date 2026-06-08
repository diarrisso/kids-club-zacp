import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BookingCalendar from '@widget/components/BookingCalendar.vue'

function todayYmd(): string {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

describe('BookingCalendar', () => {
    it('emits month-change on mount with a from/to window', () => {
        const wrapper = mount(BookingCalendar, { props: { availableDates: [] } })
        const ev = wrapper.emitted('month-change')?.[0]?.[0] as { from: string; to: string }
        expect(ev).toBeTruthy()
        expect(ev.from).toMatch(/^\d{4}-\d{2}-\d{2}$/)
        expect(ev.to).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    })

    it('marks an available day clickable and emits select with its date', async () => {
        const today = todayYmd()
        const wrapper = mount(BookingCalendar, { props: { availableDates: [today] } })
        const cell = wrapper.get(`[data-day="${today}"]`)
        expect(cell.attributes('data-available')).toBeDefined()
        await cell.trigger('click')
        expect(wrapper.emitted('select')?.[0]?.[0]).toBe(today)
    })

    it('disables a day without availability', () => {
        const wrapper = mount(BookingCalendar, { props: { availableDates: [] } })
        const cell = wrapper.get(`[data-day="${todayYmd()}"]`)
        expect((cell.element as HTMLButtonElement).disabled).toBe(true)
    })

    it('navigates to the next month and re-emits month-change', async () => {
        const wrapper = mount(BookingCalendar, { props: { availableDates: [] } })
        await wrapper.get('[data-next-month]').trigger('click')
        expect(wrapper.emitted('month-change')?.length).toBe(2)
    })

    it('clamps the emitted from to today in the current month', () => {
        const wrapper = mount(BookingCalendar, { props: { availableDates: [] } })
        const ev = wrapper.emitted('month-change')?.[0]?.[0] as { from: string; to: string }
        expect(ev.from).toBe(todayYmd())
        expect(ev.from <= ev.to).toBe(true)
    })

    it('disables the previous-month button while viewing the current month', () => {
        const wrapper = mount(BookingCalendar, { props: { availableDates: [] } })
        expect((wrapper.get('[data-prev-month]').element as HTMLButtonElement).disabled).toBe(true)
    })
})
