import type { Service, Practitioner, Slot, BookingPayload, BookingResult, ApiError } from './types'

export function createApi(base: string, tenant: string) {
    const root = `${base.replace(/\/$/, '')}/api/v1/widget/${tenant}`

    async function request<T>(path: string, init?: RequestInit): Promise<T> {
        let res: Response
        try {
            res = await fetch(`${root}${path}`, {
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                ...init,
            })
        } catch {
            throw { kind: 'network' } satisfies ApiError
        }

        if (res.status === 409) throw { kind: 'slot_taken' } satisfies ApiError
        if (res.status === 429) throw { kind: 'rate_limited' } satisfies ApiError
        if (res.status === 422) {
            const body = await res.json().catch(() => ({}))
            throw { kind: 'validation', errors: body.errors ?? {} } satisfies ApiError
        }
        if (!res.ok) throw { kind: 'network' } satisfies ApiError

        return res.json() as Promise<T>
    }

    return {
        services: () => request<Service[]>('/services'),
        practitioners: (serviceId: number) => request<Practitioner[]>(`/services/${serviceId}/practitioners`),
        slots: (practitionerId: number, serviceId: number, from: string, to: string) =>
            request<Slot[]>(`/slots?practitioner_id=${practitionerId}&service_id=${serviceId}&from=${from}&to=${to}`),
        book: (payload: BookingPayload) =>
            request<BookingResult>('/appointments', { method: 'POST', body: JSON.stringify(payload) }),
    }
}

export type Api = ReturnType<typeof createApi>
