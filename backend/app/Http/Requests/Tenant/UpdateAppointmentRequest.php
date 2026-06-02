<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'practitioner_id' => ['sometimes', 'integer', 'exists:practitioners,id'],
            'service_id' => ['sometimes', 'integer', 'exists:services,id'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
            'patient_first_name' => ['sometimes', 'string', 'max:255'],
            'patient_last_name' => ['sometimes', 'string', 'max:255'],
            'patient_birthdate' => ['sometimes', 'date'],
            'parent_first_name' => ['sometimes', 'string', 'max:255'],
            'parent_last_name' => ['sometimes', 'string', 'max:255'],
            'parent_phone' => ['sometimes', 'string', 'max:255'],
            'parent_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'notes_internal' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
