import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import KindStep from '@widget/steps/KindStep.vue'

const selection = {
    service: { id: 1, name: 'Prophylaxe', duration_minutes: 30 },
    slot: { starts_at: '2026-09-07T09:00:00+02:00', ends_at: '2026-09-07T09:30:00+02:00',
            practitioner: { id: 2, first_name: 'Anna', last_name: 'Müller', color: '#98ACBA' } },
}

async function fillKind(wrapper: any) {
    await wrapper.get('[name="patient_first_name"]').setValue('Lina')
    await wrapper.get('[name="patient_last_name"]').setValue('Müller')
    await wrapper.get('[name="patient_birthdate"]').setValue('2019-04-12')
}

describe('KindStep', () => {
    it('renders the three patient fields', () => {
        const wrapper = mount(KindStep, { props: {} })
        expect(wrapper.find('[name="patient_first_name"]').exists()).toBe(true)
        expect(wrapper.find('[name="patient_last_name"]').exists()).toBe(true)
        expect(wrapper.find('[name="patient_birthdate"]').exists()).toBe(true)
    })

    it('shows the recap card with clinic-local time when selection is provided', () => {
        const wrapper = mount(KindStep, { props: { selection } })
        expect(wrapper.text()).toContain('Prophylaxe')
        expect(wrapper.text()).toContain('09:00')
    })

    it('disables the Weiter button until all three fields are filled', async () => {
        const wrapper = mount(KindStep, { props: {} })
        const btn = () => wrapper.get('[data-kind-advance]').element as HTMLButtonElement
        expect(btn().disabled).toBe(true)

        await wrapper.get('[name="patient_first_name"]').setValue('Lina')
        expect(btn().disabled).toBe(true) // still missing last name + date

        await wrapper.get('[name="patient_last_name"]').setValue('Müller')
        expect(btn().disabled).toBe(true) // still missing date

        await wrapper.get('[name="patient_birthdate"]').setValue('2019-04-12')
        expect(btn().disabled).toBe(false)
    })

    it('emits advance with patient_* payload when Weiter is clicked', async () => {
        const wrapper = mount(KindStep, { props: {} })
        await fillKind(wrapper)
        await wrapper.get('[data-kind-advance]').trigger('click')
        const payload = wrapper.emitted('advance')?.[0][0] as any
        expect(payload).toMatchObject({
            patient_first_name: 'Lina',
            patient_last_name: 'Müller',
            patient_birthdate: '2019-04-12',
        })
        expect(payload.consent).toBeUndefined()
        expect(payload.parent_email).toBeUndefined()
    })

    it('Weiter button has type="button" (not submit)', () => {
        const wrapper = mount(KindStep, { props: {} })
        const btn = wrapper.get('[data-kind-advance]').element as HTMLButtonElement
        expect(btn.type).toBe('button')
    })

    it('emits back when Zurück is clicked', async () => {
        const wrapper = mount(KindStep, { props: {} })
        await wrapper.get('[data-kind-back]').trigger('click')
        expect(wrapper.emitted('back')).toBeTruthy()
    })

    it('pre-fills from initialValues', () => {
        const wrapper = mount(KindStep, {
            props: { initialValues: { patient_first_name: 'Max', patient_last_name: 'Muster', patient_birthdate: '2018-01-01' } },
        })
        expect((wrapper.get('[name="patient_first_name"]').element as HTMLInputElement).value).toBe('Max')
        expect((wrapper.get('[name="patient_last_name"]').element as HTMLInputElement).value).toBe('Muster')
        expect((wrapper.get('[name="patient_birthdate"]').element as HTMLInputElement).value).toBe('2018-01-01')
    })

    it('does not contain parent_* or honeypot fields', () => {
        const wrapper = mount(KindStep, { props: {} })
        expect(wrapper.find('[name="parent_first_name"]').exists()).toBe(false)
        expect(wrapper.find('[name="parent_email"]').exists()).toBe(false)
        expect(wrapper.find('[name="website"]').exists()).toBe(false)
    })

    it('caps the birthdate picker at yesterday (no future or today)', () => {
        const wrapper = mount(KindStep, { props: {} })
        const max = wrapper.get('[name="patient_birthdate"]').attributes('max')!
        const yesterday = new Date()
        yesterday.setDate(yesterday.getDate() - 1)
        expect(max).toBe(yesterday.toISOString().slice(0, 10))
    })

    it('keeps Weiter disabled and shows a hint for a future birthdate', async () => {
        const wrapper = mount(KindStep, { props: {} })
        const future = new Date()
        future.setFullYear(future.getFullYear() + 1)
        await wrapper.get('[name="patient_first_name"]').setValue('Lina')
        await wrapper.get('[name="patient_last_name"]').setValue('Müller')
        await wrapper.get('[name="patient_birthdate"]').setValue(future.toISOString().slice(0, 10))

        expect((wrapper.get('[data-kind-advance]').element as HTMLButtonElement).disabled).toBe(true)
        expect(wrapper.text()).toContain('Geburtsdatum in der Vergangenheit')
    })

    it('renders a server-side birthdate error from serverErrors', () => {
        const wrapper = mount(KindStep, {
            props: { serverErrors: { patient_birthdate: ['The patient birthdate field must be a date before today.'] } },
        })
        expect(wrapper.text()).toContain('must be a date before today')
    })
})
