import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ServiceSelect from '@widget/components/ServiceSelect.vue'

const services = [
    { id: 1, name: 'Erstuntersuchung Kind', duration_minutes: 45, color: '#EC0A8C' },
    { id: 2, name: 'Notfall', duration_minutes: 60, color: '#C40C78' },
    { id: 3, name: 'Prophylaxe', duration_minutes: 30, color: '#98ACBA' },
]

describe('ServiceSelect', () => {
    it('renders a closed combobox with a placeholder', () => {
        const w = mount(ServiceSelect, { props: { services } })
        const btn = w.get('[data-service-trigger]')
        expect(btn.attributes('aria-expanded')).toBe('false')
        expect(btn.text()).toContain('Leistung wählen')
        expect(w.find('[role="listbox"]').exists()).toBe(false)
    })

    it('opens on click and lists every service with duration', async () => {
        const w = mount(ServiceSelect, { props: { services } })
        await w.get('[data-service-trigger]').trigger('click')
        expect(w.get('[data-service-trigger]').attributes('aria-expanded')).toBe('true')
        const opts = w.findAll('[data-service]')
        expect(opts).toHaveLength(3)
        expect(opts[0].text()).toContain('45 Min.')
    })

    it('emits select and closes when an option is clicked', async () => {
        const w = mount(ServiceSelect, { props: { services } })
        await w.get('[data-service-trigger]').trigger('click')
        await w.get('[data-service][data-service-id="2"]').trigger('click')
        expect(w.emitted('select')?.[0]?.[0]).toMatchObject({ id: 2 })
        expect(w.find('[role="listbox"]').exists()).toBe(false)
    })

    it('shows the chosen service on the trigger', async () => {
        const w = mount(ServiceSelect, { props: { services, modelValue: services[1] } })
        expect(w.get('[data-service-trigger]').text()).toContain('Notfall')
    })

    it('full keyboard: ArrowDown navigates, Enter selects, Escape closes', async () => {
        const w = mount(ServiceSelect, { props: { services } })
        const btn = w.get('[data-service-trigger]')
        await btn.trigger('keydown', { key: 'ArrowDown' }) // opens + highlights first
        expect(w.find('[role="listbox"]').exists()).toBe(true)
        await w.get('[role="listbox"]').trigger('keydown', { key: 'ArrowDown' })
        await w.get('[role="listbox"]').trigger('keydown', { key: 'Enter' })
        expect(w.emitted('select')?.[0]?.[0]).toMatchObject({ id: 2 })
        await btn.trigger('keydown', { key: 'ArrowDown' })
        await w.get('[role="listbox"]').trigger('keydown', { key: 'Escape' })
        expect(w.find('[role="listbox"]').exists()).toBe(false)
    })

    it('returns focus to the trigger after selection and after Escape', async () => {
        const w = mount(ServiceSelect, { props: { services }, attachTo: document.body })
        const btn = w.get('[data-service-trigger]')
        await btn.trigger('keydown', { key: 'ArrowDown' })
        await w.get('[role="listbox"]').trigger('keydown', { key: 'Enter' })
        expect(document.activeElement).toBe(btn.element)
        await btn.trigger('keydown', { key: 'ArrowDown' })
        await w.get('[role="listbox"]').trigger('keydown', { key: 'Escape' })
        expect(document.activeElement).toBe(btn.element)
        w.unmount()
    })

    it('exposes aria-activedescendant tracking the highlighted option', async () => {
        const w = mount(ServiceSelect, { props: { services } })
        await w.get('[data-service-trigger]').trigger('keydown', { key: 'ArrowDown' })
        const list = w.get('[role="listbox"]')
        expect(list.attributes('aria-activedescendant')).toBe('masinga-service-opt-0')
        await list.trigger('keydown', { key: 'ArrowDown' })
        expect(list.attributes('aria-activedescendant')).toBe('masinga-service-opt-1')
        expect(w.get('[data-service-id="2"]').attributes('id')).toBe('masinga-service-opt-1')
    })
})
