<?php

namespace App\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'practitioner_id' => ['required', 'exists:practitioners,id'],
            'service_id' => ['required', 'exists:services,id'],
            'starts_at' => ['required', 'date', 'after:now', 'before:'.now()->addDays(61)->toDateString()],
            'patient_first_name' => ['required', 'string', 'max:255'],
            'patient_last_name' => ['required', 'string', 'max:255'],
            'patient_birthdate' => ['required', 'date', 'before:today'],
            'parent_first_name' => ['required', 'string', 'max:255'],
            'parent_last_name' => ['required', 'string', 'max:255'],
            'parent_email' => ['required', 'email', 'max:255'],
            'parent_phone' => ['nullable', 'string', 'max:50'],
            'notes_parent' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
            'website' => ['nullable', 'string'],
            'room' => ['nullable', 'in:green,yellow,peach,blue,purple'],
        ];
    }
}
