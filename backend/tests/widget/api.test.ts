import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createApi } from '@widget/api'

const api = createApi('https://app.test')

beforeEach(() => { vi.restoreAllMocks() })

function mockFetch(status: number, body: unknown) {
    return vi.spyOn(globalThis, 'fetch').mockResolvedValue(
        new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
    )
}

describe('api client', () => {
    it('builds the services URL', async () => {
        const spy = mockFetch(200, [{ id: 1, name: 'Prophylaxe', duration_minutes: 30 }])
        const services = await api.services()
        expect(spy).toHaveBeenCalledWith('https://app.test/api/v1/widget/services', expect.anything())
        expect(services[0].name).toBe('Prophylaxe')
    })

    it('maps a 409 conflict to a SlotTaken error', async () => {
        mockFetch(409, { message: 'taken' })
        await expect(api.book({} as any)).rejects.toMatchObject({ kind: 'slot_taken' })
    })

    it('maps a 422 to a validation error carrying field errors', async () => {
        mockFetch(422, { errors: { consent: ['required'] } })
        await expect(api.book({} as any)).rejects.toMatchObject({ kind: 'validation', errors: { consent: ['required'] } })
    })

    it('maps a 429 to a rate_limited error', async () => {
        mockFetch(429, {})
        await expect(api.book({} as any)).rejects.toMatchObject({ kind: 'rate_limited' })
    })

    it('builds the availability/days URL with service and window', async () => {
        const spy = mockFetch(200, ['2026-09-07'])
        const days = await api.availabilityDays(1, '2026-09-01', '2026-09-30')
        expect(spy).toHaveBeenCalledWith(
            'https://app.test/api/v1/widget/availability/days?service_id=1&from=2026-09-01&to=2026-09-30',
            expect.anything(),
        )
        expect(days).toEqual(['2026-09-07'])
    })

    it('omits practitioner_id from the slots URL when not provided', async () => {
        const spy = mockFetch(200, [])
        await api.slots(1, '2026-09-07', '2026-09-07')
        expect(spy).toHaveBeenCalledWith(
            'https://app.test/api/v1/widget/slots?service_id=1&from=2026-09-07&to=2026-09-07',
            expect.anything(),
        )
    })

    it('includes practitioner_id in the slots URL when provided', async () => {
        const spy = mockFetch(200, [])
        await api.slots(1, '2026-09-07', '2026-09-07', 42)
        expect(spy).toHaveBeenCalledWith(
            'https://app.test/api/v1/widget/slots?service_id=1&from=2026-09-07&to=2026-09-07&practitioner_id=42',
            expect.anything(),
        )
    })
})
