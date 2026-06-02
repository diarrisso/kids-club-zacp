<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreManualAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind 'auth'
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'practitioner_id' => ['required', 'integer', 'exists:practitioners,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'starts_at' => ['required', 'date'],
            'patient_first_name' => ['required', 'string', 'max:255'],
            'patient_last_name' => ['required', 'string', 'max:255'],
            'patient_birthdate' => ['required', 'date'],
            'parent_first_name' => ['required', 'string', 'max:255'],
            'parent_last_name' => ['required', 'string', 'max:255'],
            'parent_phone' => ['required', 'string', 'max:255'],
            'parent_email' => ['nullable', 'email', 'max:255'],
            'notes_internal' => ['nullable', 'string'],
        ];
    }
}
