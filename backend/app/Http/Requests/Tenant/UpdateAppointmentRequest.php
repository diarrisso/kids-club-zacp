<?php

namespace App\Http\Requests\Tenant;

use App\Support\Attendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'room' => ['sometimes', 'nullable', 'in:green,yellow,peach,blue,purple'],
            'attendance' => ['sometimes', 'nullable', Rule::enum(Attendance::class)],
        ];
    }
}
