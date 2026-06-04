export interface Service { id: number; name: string; duration_minutes: number; color?: string; description?: string }
export interface Practitioner { id: number; first_name: string; last_name: string; title?: string; color?: string }
export interface Slot { starts_at: string; ends_at: string }

export interface BookingPayload {
    practitioner_id: number
    service_id: number
    starts_at: string
    patient_first_name: string
    patient_last_name: string
    patient_birthdate: string
    parent_first_name: string
    parent_last_name: string
    parent_email: string
    parent_phone?: string
    notes_parent?: string
    consent: boolean
    room?: string | null
    website?: string // honeypot
}

export interface BookingResult { cancellation_token: string; starts_at: string; ends_at: string }

export type ApiError =
    | { kind: 'slot_taken' }
    | { kind: 'rate_limited' }
    | { kind: 'validation'; errors: Record<string, string[]> }
    | { kind: 'network' }
